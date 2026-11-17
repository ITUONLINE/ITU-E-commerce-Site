<?php
/**
 * Glossary Batch Expansion — OpenAI Batch API integration.
 *
 * The realtime per-row runner in glossary-manager.php is great for
 * small batches (5-100 terms) but is expensive at scale: every request
 * goes through the standard rate-limited endpoint and pays full price.
 * For the full 5,700-term backlog we route through OpenAI's Batch API:
 *   - 50% cost discount on input + output tokens
 *   - No rate-limit concerns
 *   - Async — completes within 24h (usually 1-4h)
 *
 * Lifecycle (one batch at a time):
 *
 *   IDLE → SUBMITTING → IN_PROGRESS → COMPLETED → APPLIED → IDLE
 *                              ↓
 *                          FAILED / CANCELLED → IDLE
 *
 * State lives in two places:
 *   - WP option `seom_glossary_active_batch` carries the OpenAI batch
 *     metadata (id, file ids, status, counts, timestamps).
 *   - Per-row tracking on `ec_it_glossary` (batch_id, batch_custom_id,
 *     batch_status) so we can join responses back even if multiple
 *     batches happen over time.
 *
 * A cron event polls the OpenAI batch every 15 minutes. When the batch
 * completes, the cron downloads the output file, parses each line, and
 * writes the expanded content into each glossary row keyed on custom_id.
 *
 * Concurrency: only one active batch at a time. New submits while a
 * batch is in progress return an error. This keeps the per-row
 * tracking unambiguous and keeps the UI simple.
 */

if (!defined('ABSPATH')) exit;

const SEOM_GLOSSARY_BATCH_OPTION = 'seom_glossary_active_batch';
const SEOM_GLOSSARY_BATCH_CRON   = 'seom_glossary_batch_poll';

/**
 * OpenAI base URL + headers. Reuses the same key as the rest of the
 * plugin so admins don't manage a second credential.
 */
function seom_glossary_batch_openai_base() {
    return 'https://api.openai.com/v1';
}

function seom_glossary_batch_auth_headers($extra = []) {
    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) return new WP_Error('no_key', 'Blog Writer API key not configured.');
    return array_merge([
        'Authorization' => 'Bearer ' . $api_key,
    ], $extra);
}

/**
 * Return the active batch state from options, or null when idle.
 *
 * Shape:
 *   [
 *     'batch_id'        => 'batch_...',
 *     'input_file_id'   => 'file_...',
 *     'output_file_id'  => 'file_...' | null,
 *     'error_file_id'   => 'file_...' | null,
 *     'status'          => 'validating'|'in_progress'|'finalizing'|'completed'|'failed'|'expired'|'cancelled',
 *     'submitted_at'    => 'YYYY-mm-dd H:i:s',
 *     'last_polled_at'  => 'YYYY-mm-dd H:i:s' | null,
 *     'total_count'     => N,
 *     'completed_count' => N (applied to glossary),
 *     'failed_count'    => N,
 *     'applied'         => bool,
 *     'model'           => 'gpt-4.1-nano',
 *   ]
 */
function seom_glossary_batch_get_active() {
    $state = get_option(SEOM_GLOSSARY_BATCH_OPTION, null);
    if (!is_array($state) || empty($state['batch_id'])) return null;
    return $state;
}

function seom_glossary_batch_set_active($state) {
    update_option(SEOM_GLOSSARY_BATCH_OPTION, $state, false);
}

function seom_glossary_batch_clear_active() {
    delete_option(SEOM_GLOSSARY_BATCH_OPTION);
}

/**
 * Build a JSONL string for a list of terms. $job_type controls which
 * prompt + which input fields are used:
 *
 *   'expand'  — full expansion. Uses seom_glossary_expand_system_prompt.
 *               Input: term + short definition. Output: html + tldr +
 *               seo_title + seo_description + faqs.
 *
 *   'seo_meta' — SEO metadata only for already-expanded terms. Uses
 *               seom_glossary_seo_meta_system_prompt. Input: term +
 *               short def + EXISTING expanded HTML. Output: tldr +
 *               seo_title + seo_description + faqs (no html).
 *
 * custom_id is the term slug truncated to OpenAI's 1-64 char window,
 * suffixed with a hash on collision. Maps back via $custom_id_map.
 *
 * Returns [string $jsonl, array $custom_id_map => term].
 */
function seom_glossary_batch_build_jsonl($term_rows, $model, $job_type = 'expand') {
    $system = $job_type === 'seo_meta'
        ? seom_glossary_seo_meta_system_prompt()
        : seom_glossary_expand_system_prompt();

    $lines = [];
    $custom_id_map = [];
    foreach ($term_rows as $row) {
        $term = trim((string) $row->term);
        $def  = trim((string) $row->definition);
        if ($term === '' || $def === '') continue;
        // seo_meta jobs require existing expanded content.
        if ($job_type === 'seo_meta' && empty($row->definition_expanded)) continue;

        $slug = !empty($row->slug) ? $row->slug : sanitize_title($term);
        $cid = $slug;
        if (strlen($cid) > 60) {
            $cid = substr($cid, 0, 50) . '-' . substr(md5($slug), 0, 8);
        }
        if (isset($custom_id_map[$cid])) {
            $cid = substr($cid, 0, 50) . '-' . substr(md5($term . microtime(true)), 0, 8);
        }
        $custom_id_map[$cid] = $term;

        if ($job_type === 'seo_meta') {
            $user_prompt = "Term: {$term}\n\n"
                         . "Short definition: " . wp_strip_all_tags($def) . "\n\n"
                         . "Long-form body content (HTML):\n" . (string) $row->definition_expanded;
            $max_tokens = 1000;
        } else {
            $user_prompt = "Term: {$term}\n\n"
                         . "Existing one-sentence definition (preserve the meaning, "
                         . "expand to the full structured format):\n{$def}";
            $max_tokens = 2000;
        }

        $line = [
            'custom_id' => $cid,
            'method'    => 'POST',
            'url'       => '/v1/chat/completions',
            'body'      => [
                'model'       => $model,
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user_prompt],
                ],
                'temperature' => 0.5,
                'max_tokens'  => $max_tokens,
            ],
        ];
        $lines[] = wp_json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return [implode("\n", $lines) . "\n", $custom_id_map];
}

/**
 * Upload a string as a file to /v1/files with purpose=batch. Returns
 * the file ID or WP_Error.
 *
 * wp_remote_post can't do multipart cleanly so we build the body by
 * hand. The file content type is application/json (technically JSONL
 * but OpenAI accepts application/json fine).
 */
function seom_glossary_batch_upload_file($content) {
    $headers = seom_glossary_batch_auth_headers();
    if (is_wp_error($headers)) return $headers;

    $boundary = 'WPBatch' . md5(microtime(true));
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"purpose\"\r\n\r\n";
    $body .= "batch\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"batch_input.jsonl\"\r\n";
    $body .= "Content-Type: application/json\r\n\r\n";
    $body .= $content . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

    $response = wp_remote_post(seom_glossary_batch_openai_base() . '/files', [
        'headers' => $headers,
        'body'    => $body,
        'timeout' => 90,
    ]);
    if (is_wp_error($response)) return $response;

    $code = wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);
    $json = json_decode($raw, true);
    if ($code !== 200 || !isset($json['id'])) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code . ': ' . substr($raw, 0, 200));
        return new WP_Error('openai_file_upload', 'File upload failed: ' . $msg);
    }
    return (string) $json['id'];
}

/**
 * Create a batch job for an uploaded file. Returns batch metadata or
 * WP_Error.
 */
function seom_glossary_batch_create($input_file_id) {
    $headers = seom_glossary_batch_auth_headers(['Content-Type' => 'application/json']);
    if (is_wp_error($headers)) return $headers;

    $response = wp_remote_post(seom_glossary_batch_openai_base() . '/batches', [
        'headers' => $headers,
        'body'    => wp_json_encode([
            'input_file_id'     => $input_file_id,
            'endpoint'          => '/v1/chat/completions',
            'completion_window' => '24h',
            'metadata'          => [
                'source' => 'seo-ai-autopilot.glossary-batch',
                'site'   => home_url(),
            ],
        ]),
        'timeout' => 60,
    ]);
    if (is_wp_error($response)) return $response;

    $code = wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);
    $json = json_decode($raw, true);
    if ($code !== 200 || !isset($json['id'])) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code . ': ' . substr($raw, 0, 200));
        return new WP_Error('openai_batch_create', 'Batch creation failed: ' . $msg);
    }
    return $json;
}

/**
 * Submit pending glossary terms as a new batch. Optional $limit caps
 * the number of terms included. $job_type selects which work the batch
 * performs:
 *
 *   'expand'   — pending = rows with NO definition_expanded. Full
 *                expansion + SEO metadata in one call.
 *   'seo_meta' — pending = rows with definition_expanded but NO
 *                seo_meta_at. Cheap metadata-only enrichment using
 *                the existing expanded HTML as input.
 *
 * Returns the active batch state or WP_Error.
 *
 * Refuses to submit if there's already an active batch.
 */
function seom_glossary_batch_submit_pending($limit = 0, $job_type = 'expand') {
    if (seom_glossary_batch_get_active()) {
        return new WP_Error('batch_active', 'Another batch is already in flight. Cancel or wait for completion before submitting a new one.');
    }
    if (!in_array($job_type, ['expand', 'seo_meta'], true)) {
        return new WP_Error('bad_job_type', 'Invalid job_type: ' . $job_type);
    }
    global $wpdb;
    seom_glossary_backfill_slugs(10000);

    if ($job_type === 'seo_meta') {
        $sql = "SELECT term, slug, definition, definition_expanded FROM ec_it_glossary
                WHERE definition_expanded IS NOT NULL AND definition_expanded != ''
                  AND seo_meta_at IS NULL
                  AND term IS NOT NULL AND term != ''";
        $no_pending_msg = 'No terms eligible for SEO meta enrichment (every expanded term already has SEO metadata).';
    } else {
        $sql = "SELECT term, slug, definition FROM ec_it_glossary
                WHERE (definition_expanded IS NULL OR definition_expanded = '')
                  AND definition IS NOT NULL AND definition != ''";
        $no_pending_msg = 'No pending terms to expand.';
    }
    if ($limit > 0) {
        $sql .= $wpdb->prepare(' LIMIT %d', max(1, min(50000, (int) $limit)));
    }
    $rows = $wpdb->get_results($sql);
    if (!$rows) return new WP_Error('no_pending', $no_pending_msg);

    $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';
    list($jsonl, $custom_id_map) = seom_glossary_batch_build_jsonl($rows, $model, $job_type);
    if ($jsonl === "\n" || empty($custom_id_map)) {
        return new WP_Error('build_failed', 'Could not build any valid request lines.');
    }

    $file_id = seom_glossary_batch_upload_file($jsonl);
    if (is_wp_error($file_id)) return $file_id;

    $batch = seom_glossary_batch_create($file_id);
    if (is_wp_error($batch)) return $batch;

    $now = current_time('mysql');
    // Tag each row with the batch metadata so we can match output lines
    // back even if the WP option somehow gets clobbered.
    foreach ($custom_id_map as $cid => $term) {
        $wpdb->update(
            'ec_it_glossary',
            [
                'batch_id'           => $batch['id'],
                'batch_custom_id'    => $cid,
                'batch_status'       => 'pending',
                'batch_submitted_at' => $now,
            ],
            ['term' => $term]
        );
    }

    $state = [
        'batch_id'        => $batch['id'],
        'input_file_id'   => $file_id,
        'output_file_id'  => $batch['output_file_id'] ?? null,
        'error_file_id'   => $batch['error_file_id']  ?? null,
        'status'          => $batch['status'] ?? 'validating',
        'submitted_at'    => $now,
        'last_polled_at'  => null,
        'total_count'     => count($custom_id_map),
        'completed_count' => 0,
        'failed_count'    => 0,
        'applied'         => false,
        'model'           => $model,
        'job_type'        => $job_type,
    ];
    seom_glossary_batch_set_active($state);

    // Make sure the polling cron is scheduled.
    if (!wp_next_scheduled(SEOM_GLOSSARY_BATCH_CRON)) {
        wp_schedule_event(time() + 60, 'seom_quarter_hour', SEOM_GLOSSARY_BATCH_CRON);
    }
    return $state;
}

/**
 * GET /v1/batches/{id} — fetch current status. Returns parsed JSON or
 * WP_Error.
 */
function seom_glossary_batch_fetch($batch_id) {
    $headers = seom_glossary_batch_auth_headers();
    if (is_wp_error($headers)) return $headers;

    $response = wp_remote_get(seom_glossary_batch_openai_base() . '/batches/' . $batch_id, [
        'headers' => $headers,
        'timeout' => 30,
    ]);
    if (is_wp_error($response)) return $response;
    $code = wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);
    $json = json_decode($raw, true);
    if ($code !== 200) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code);
        return new WP_Error('openai_batch_fetch', 'Batch fetch failed: ' . $msg);
    }
    return $json;
}

/**
 * Poll the active batch once. Updates the option with the latest
 * status from OpenAI. If the batch is in a terminal state, triggers
 * apply (when completed) or marks failed/cancelled. Returns the new
 * active state or WP_Error.
 */
function seom_glossary_batch_poll() {
    $state = seom_glossary_batch_get_active();
    if (!$state) return new WP_Error('no_batch', 'No active batch.');

    $batch = seom_glossary_batch_fetch($state['batch_id']);
    if (is_wp_error($batch)) return $batch;

    $state['status']         = $batch['status']         ?? $state['status'];
    $state['output_file_id'] = $batch['output_file_id'] ?? $state['output_file_id'];
    $state['error_file_id']  = $batch['error_file_id']  ?? $state['error_file_id'];
    $state['last_polled_at'] = current_time('mysql');

    // Mirror OpenAI's request_counts onto our state for the UI.
    if (isset($batch['request_counts']) && is_array($batch['request_counts'])) {
        $rc = $batch['request_counts'];
        $state['provider_completed'] = (int) ($rc['completed'] ?? 0);
        $state['provider_failed']    = (int) ($rc['failed']    ?? 0);
        $state['provider_total']     = (int) ($rc['total']     ?? 0);
    }

    seom_glossary_batch_set_active($state);

    if ($state['status'] === 'completed' && !$state['applied']) {
        $applied = seom_glossary_batch_apply_output();
        if (is_wp_error($applied)) return $applied;
        return seom_glossary_batch_get_active();
    }
    if (in_array($state['status'], ['failed', 'expired', 'cancelled'], true)) {
        // Mark all in-flight rows as failed so they're eligible for
        // re-submission on a future batch.
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE ec_it_glossary SET batch_status = 'failed'
             WHERE batch_id = %s AND batch_status = 'pending'",
            $state['batch_id']
        ));
    }
    return $state;
}

/**
 * Download a file's content from /v1/files/{id}/content. Returns the
 * raw string body or WP_Error.
 */
function seom_glossary_batch_download_file($file_id) {
    $headers = seom_glossary_batch_auth_headers();
    if (is_wp_error($headers)) return $headers;

    $response = wp_remote_get(seom_glossary_batch_openai_base() . '/files/' . $file_id . '/content', [
        'headers' => $headers,
        'timeout' => 120,
    ]);
    if (is_wp_error($response)) return $response;
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($code !== 200) {
        return new WP_Error('openai_file_download', 'File download failed: HTTP ' . $code);
    }
    return (string) $body;
}

/**
 * Download the output file for the active batch, parse each line,
 * validate + sanitize the content, and write it into the matching
 * glossary row. Sets state['applied'] = true on success.
 */
function seom_glossary_batch_apply_output() {
    global $wpdb;
    $state = seom_glossary_batch_get_active();
    if (!$state) return new WP_Error('no_batch', 'No active batch.');
    if (empty($state['output_file_id'])) return new WP_Error('no_output', 'Batch has no output file yet.');

    $raw = seom_glossary_batch_download_file($state['output_file_id']);
    if (is_wp_error($raw)) return $raw;

    $lines     = preg_split('/\r?\n/', $raw);
    $job_type  = $state['job_type'] ?? 'expand';
    $now       = current_time('mysql');
    $completed = 0;
    $failed    = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $obj = json_decode($line, true);
        if (!is_array($obj)) continue;
        $cid = (string) ($obj['custom_id'] ?? '');
        if ($cid === '') continue;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT term, slug, definition, definition_expanded, definition_original
             FROM ec_it_glossary
             WHERE batch_id = %s AND batch_custom_id = %s LIMIT 1",
            $state['batch_id'], $cid
        ));
        if (!$row) continue;

        $body    = $obj['response']['body'] ?? null;
        $content = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
        if ($content === '' || !empty($obj['error'])) {
            $wpdb->update('ec_it_glossary', ['batch_status' => 'failed'], ['term' => $row->term]);
            $failed++;
            continue;
        }

        $env = seom_glossary_parse_expansion_envelope($content);
        if (is_wp_error($env)) {
            // expand jobs: tolerate legacy raw HTML; seo_meta jobs:
            // can't recover, mark failed.
            if ($job_type === 'expand') {
                $env = [
                    'html'            => $content,
                    'tldr'            => '',
                    'seo_title'       => '',
                    'seo_description' => '',
                    'faqs'            => [],
                ];
            } else {
                $wpdb->update('ec_it_glossary', ['batch_status' => 'failed'], ['term' => $row->term]);
                $failed++;
                continue;
            }
        }

        $update = ['batch_status' => 'completed'];

        if ($job_type === 'expand') {
            $clean = seom_glossary_clean_body_html($env['html']);
            $words = seom_glossary_word_count($clean);
            if ($words < 150 || $words > 800) {
                $wpdb->update('ec_it_glossary', ['batch_status' => 'failed'], ['term' => $row->term]);
                $failed++;
                continue;
            }
            $update['definition_expanded']    = $clean;
            $update['definition_expanded_at'] = $now;
            if (empty($row->definition_original) && !empty($row->definition)) {
                $update['definition_original'] = $row->definition;
            }
        }

        // SEO metadata — written for both job types when present.
        if ($env['tldr']            !== '') $update['tldr']            = mb_substr($env['tldr'], 0, 1500);
        if ($env['seo_title']       !== '') $update['seo_title']       = mb_substr($env['seo_title'], 0, 190);
        if ($env['seo_description'] !== '') $update['seo_description'] = mb_substr($env['seo_description'], 0, 255);
        if (!empty($env['faqs']))           $update['faq_json']        = wp_json_encode($env['faqs'], JSON_UNESCAPED_UNICODE);
        if ($env['tldr'] !== '' || $env['seo_title'] !== '' || !empty($env['faqs'])) {
            $update['seo_meta_at'] = $now;
        }

        $wpdb->update('ec_it_glossary', $update, ['term' => $row->term]);
        $completed++;
    }

    // Also pull error file (if any) and bump failure count.
    if (!empty($state['error_file_id'])) {
        $err_raw = seom_glossary_batch_download_file($state['error_file_id']);
        if (!is_wp_error($err_raw)) {
            $err_lines = preg_split('/\r?\n/', $err_raw);
            foreach ($err_lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $obj = json_decode($line, true);
                if (!is_array($obj)) continue;
                $cid = (string) ($obj['custom_id'] ?? '');
                if ($cid === '') continue;
                $wpdb->update(
                    'ec_it_glossary',
                    ['batch_status' => 'failed'],
                    ['batch_id' => $state['batch_id'], 'batch_custom_id' => $cid]
                );
                $failed++;
            }
        }
    }

    $state['completed_count'] = $completed;
    $state['failed_count']    = $failed;
    $state['applied']         = true;
    $state['applied_at']      = $now;
    seom_glossary_batch_set_active($state);

    if (function_exists('seom_glossary_invalidate_index')) {
        seom_glossary_invalidate_index();
    }
    do_action('seom_glossary_changed');
    return $state;
}

/**
 * Cancel the active batch via OpenAI. Best-effort — if the batch is
 * already in a terminal state, just clears local state.
 */
function seom_glossary_batch_cancel() {
    $state = seom_glossary_batch_get_active();
    if (!$state) return new WP_Error('no_batch', 'No active batch.');

    $headers = seom_glossary_batch_auth_headers();
    if (is_wp_error($headers)) return $headers;

    $response = wp_remote_post(
        seom_glossary_batch_openai_base() . '/batches/' . $state['batch_id'] . '/cancel',
        ['headers' => $headers, 'timeout' => 30]
    );
    // Don't error out on a 409 (already terminal) — just mark locally.
    if (!is_wp_error($response)) {
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 200 && isset($body['status'])) {
            $state['status'] = $body['status'];
        }
    }
    // Mark in-flight rows as failed so they're re-eligible.
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "UPDATE ec_it_glossary SET batch_status = 'failed'
         WHERE batch_id = %s AND batch_status = 'pending'",
        $state['batch_id']
    ));
    $state['status']  = $state['status'] ?? 'cancelled';
    $state['applied'] = true; // nothing more to apply
    seom_glossary_batch_set_active($state);
    return $state;
}

/**
 * Reset state to idle — used after a finished batch is acknowledged so
 * the user can submit a new one. Does NOT touch glossary rows; their
 * batch_id/batch_status remain for audit.
 */
function seom_glossary_batch_dismiss() {
    seom_glossary_batch_clear_active();
    $next = wp_next_scheduled(SEOM_GLOSSARY_BATCH_CRON);
    if ($next) wp_unschedule_event($next, SEOM_GLOSSARY_BATCH_CRON);
    return true;
}

// ─── Cron schedule (15 min) ─────────────────────────────────────────────────

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['seom_quarter_hour'])) {
        $schedules['seom_quarter_hour'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'Every 15 Minutes (SEOM glossary batch poll)',
        ];
    }
    return $schedules;
});

add_action(SEOM_GLOSSARY_BATCH_CRON, function () {
    $state = seom_glossary_batch_get_active();
    if (!$state) return; // nothing to poll
    // Don't keep polling after we've applied a completed batch — wait
    // for the admin to dismiss before unscheduling (in cancel/dismiss).
    if (in_array($state['status'], ['completed', 'failed', 'expired', 'cancelled'], true) && !empty($state['applied'])) {
        return;
    }
    seom_glossary_batch_poll();
});

// ─── AJAX endpoints ─────────────────────────────────────────────────────────

add_action('wp_ajax_seom_glossary_batch_submit', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(180);

    $limit    = isset($_POST['limit'])    ? intval($_POST['limit']) : 0;
    $job_type = isset($_POST['job_type']) ? sanitize_key($_POST['job_type']) : 'expand';
    $r = seom_glossary_batch_submit_pending($limit, $job_type);
    if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    wp_send_json_success($r);
});

add_action('wp_ajax_seom_glossary_batch_poll', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(180);
    $r = seom_glossary_batch_poll();
    if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    wp_send_json_success($r);
});

add_action('wp_ajax_seom_glossary_batch_status', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    wp_send_json_success(seom_glossary_batch_get_active());
});

add_action('wp_ajax_seom_glossary_batch_cancel', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    $r = seom_glossary_batch_cancel();
    if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    wp_send_json_success($r);
});

add_action('wp_ajax_seom_glossary_batch_dismiss', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    seom_glossary_batch_dismiss();
    wp_send_json_success();
});

/**
 * Count terms eligible for a fresh batch submit — i.e. rows without an
 * expansion yet and not already pending in another (non-existent)
 * batch. Used by the UI to show "Submit N pending terms".
 */
function seom_glossary_batch_count_pending($job_type = 'expand') {
    global $wpdb;
    if ($job_type === 'seo_meta') {
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM ec_it_glossary
             WHERE definition_expanded IS NOT NULL AND definition_expanded != ''
               AND seo_meta_at IS NULL
               AND term IS NOT NULL AND term != ''"
        );
    }
    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM ec_it_glossary
         WHERE (definition_expanded IS NULL OR definition_expanded = '')
           AND definition IS NOT NULL AND definition != ''"
    );
}
