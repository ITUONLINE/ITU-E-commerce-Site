<?php
/**
 * Surgical Insertion — phase 1a backfill for existing posts.
 *
 * Generates and prepends the TL;DR ("Quick Answer") block to existing
 * published posts WITHOUT doing a full content rewrite. Roughly 10×
 * cheaper than a full refresh and zero risk of regressing existing body
 * copy. The .itu-tldr block also activates the speakable schema (the
 * authority schema layer scans the body for the class name at render
 * time), so a post that gains this block also gains speakable schema
 * the moment it's saved.
 *
 * Three responsibilities:
 *
 *   1. seom_surgical_has_tldr($post_id) — content scan
 *   2. seom_surgical_insert_tldr($post_id) — AI call + prepend + meta flag
 *   3. AJAX endpoints for the admin list view and per-row run
 */

if (!defined('ABSPATH')) exit;

/**
 * True if the post already has a .itu-tldr block in its content.
 */
function seom_surgical_has_tldr($post_id) {
    $content = (string) get_post_field('post_content', (int) $post_id);
    if ($content === '') return false;
    return stripos($content, 'itu-tldr') !== false;
}

/**
 * Thin OpenAI wrapper. Reuses the shared ITU API-key + model accessor
 * pattern the rest of the plugin uses. Returns trimmed text or WP_Error.
 */
function seom_surgical_call_openai($system_prompt, $user_prompt) {
    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) return new WP_Error('no_key', 'Blog Writer API key not configured.');

    $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user',   'content' => $user_prompt],
            ],
            'temperature' => 0.5,
        ]),
        'timeout' => 60,
    ]);
    if (is_wp_error($response)) return $response;

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return new WP_Error('openai_http_' . $code, 'OpenAI returned HTTP ' . $code);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $text = $body['choices'][0]['message']['content'] ?? '';
    $text = trim((string) $text);
    if ($text === '') return new WP_Error('empty_response', 'OpenAI returned empty content.');
    return $text;
}

/**
 * Generate and prepend a TL;DR block to an existing post.
 *
 * Idempotent: returns 'skipped' if the post already has .itu-tldr.
 *
 * Returns:
 *   ['status' => 'inserted', 'post_id' => N, 'words' => N, 'tldr' => '...']
 *   ['status' => 'skipped',  'post_id' => N, 'reason' => 'already_has_tldr']
 *   WP_Error on failure (no key, AI error, length validation fail, etc.)
 *
 * Side effects:
 *   - Updates wp_posts.post_content with the prepended block
 *   - Does NOT bump post_modified (uses direct $wpdb->update with no
 *     date columns, so historical refresh signal stays clean)
 *   - Sets post meta _seom_surgical_tldr_at = mysql datetime
 *   - Clears the post cache
 */
function seom_surgical_insert_tldr($post_id) {
    $post_id = (int) $post_id;
    $post = get_post($post_id);
    if (!$post)                                  return new WP_Error('not_found', 'Post not found.');
    if ($post->post_type !== 'post')             return new WP_Error('wrong_type', 'Only blog posts are supported.');
    if ($post->post_status !== 'publish')        return new WP_Error('not_published', 'Post is not published.');
    if (seom_surgical_has_tldr($post_id)) {
        return ['status' => 'skipped', 'post_id' => $post_id, 'reason' => 'already_has_tldr'];
    }

    // Build a content excerpt the model can summarize from. Strip HTML,
    // collapse whitespace, cap at ~2KB so we don't blow the context budget
    // on long posts. 2KB ≈ 350 words which is plenty for a TL;DR pass.
    $excerpt = wp_strip_all_tags($post->post_content);
    $excerpt = preg_replace('/\s+/u', ' ', (string) $excerpt);
    $excerpt = trim((string) $excerpt);
    $excerpt = mb_substr($excerpt, 0, 2200);

    $title = trim((string) $post->post_title);

    $system_prompt = "You write the \"Quick Answer\" block that opens technical blog posts on an IT training and certification site. This single paragraph is what AI search engines (Google AI Overview, Perplexity, ChatGPT, Claude) cite verbatim, so it must be self-contained, specific, and direct.\n\n"
        . "REQUIREMENTS:\n"
        . "- Exactly one paragraph, 40-80 words\n"
        . "- Plain text — no markdown, no HTML, no labels, no quotes around it\n"
        . "- Directly answers what the title asks\n"
        . "- Self-contained: readable out of context (no \"in this article\", \"this post will explain\", \"as we discussed\")\n"
        . "- Includes at least one specific element: a number, a named entity (CompTIA, AWS, ITIL, etc.), a concrete example, or a date range\n"
        . "- Matches the tone of the existing post excerpt\n"
        . "- NO YEARS. Never write \"2024\", \"2025\", \"2026\", or any 4-digit year, anywhere in the paragraph\n"
        . "- No \"Here's what you need to know\" or similar filler openings\n"
        . "- No \"Quick Answer:\" label — that gets added separately";

    $user_prompt = "BLOG TITLE: {$title}\n\nEXISTING POST EXCERPT:\n{$excerpt}\n\nReturn ONLY the Quick Answer paragraph.";

    $raw = seom_surgical_call_openai($system_prompt, $user_prompt);
    if (is_wp_error($raw)) return $raw;

    // Sanitize the model output. Strip wrapping quotes, any markdown HR
    // marks, leading "Quick Answer:" labels, and stray HTML tags.
    $text = $raw;
    $text = preg_replace('/^"+|"+$/u', '', $text);
    $text = preg_replace("/^'+|'+$/u", '', $text);
    $text = preg_replace('/^(Quick Answer|TL;DR|Summary)\s*[::\-]\s*/iu', '', $text);
    $text = wp_strip_all_tags($text);
    // Defensive year strip (matches the title-strip rule from blog-queue).
    $text = preg_replace('/\b20\d{2}\b/u', '', $text);
    $text = preg_replace('/\s{2,}/u', ' ', $text);
    $text = trim((string) $text);

    $word_count = str_word_count($text);
    if ($word_count < 25 || $word_count > 120) {
        return new WP_Error(
            'bad_length',
            "TL;DR generation produced a {$word_count}-word output (expected 40-80). Try again."
        );
    }

    $tldr_html = "<div class=\"itu-tldr\" data-speakable=\"true\">\n"
        . "  <p><strong>Quick Answer</strong></p>\n"
        . "  <p>" . esc_html($text) . "</p>\n"
        . "</div>\n\n";

    // Prepend to existing content without bumping post_modified. Direct
    // wp_update_post() touches the modified column and triggers any
    // dateModified-keyed cache invalidations / sitemap pings — overkill
    // for a structural insertion that doesn't change the post's meaning.
    global $wpdb;
    $new_content = $tldr_html . $post->post_content;
    $updated = $wpdb->update(
        $wpdb->posts,
        ['post_content' => $new_content],
        ['ID' => $post_id]
    );
    if ($updated === false) {
        return new WP_Error('db_error', 'Failed to update post content: ' . $wpdb->last_error);
    }
    clean_post_cache($post_id);
    update_post_meta($post_id, '_seom_surgical_tldr_at', current_time('mysql'));

    return [
        'status'  => 'inserted',
        'post_id' => $post_id,
        'words'   => $word_count,
        'tldr'    => $text,
    ];
}

// ─── AJAX: paginated list with traffic-ranked sort ──────────────────────────
//
// The list pulls posts + their most recent metrics row from
// seom_page_metrics. When a post has no metrics row (newly published, or
// outside the GSC reporting window), its impressions/clicks/position are
// treated as 0 so the user can still see it — they just won't sort high.
add_action('wp_ajax_seom_surgical_list', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $page     = max(1, intval($_POST['page'] ?? 1));
    $per_page = 25;
    $offset   = max(0, ($page - 1) * $per_page);
    $filter   = sanitize_text_field($_POST['filter'] ?? 'missing_tldr');
    $sort     = sanitize_text_field($_POST['sort'] ?? 'impressions_desc');

    $valid_sorts = [
        'impressions_desc' => 'impressions DESC, p.ID DESC',
        'clicks_desc'      => 'clicks DESC, p.ID DESC',
        'position_asc'     => 'CASE WHEN avg_position > 0 THEN avg_position ELSE 999 END ASC, impressions DESC',
        'modified_desc'    => 'p.post_modified DESC',
        'modified_asc'     => 'p.post_modified ASC',
    ];
    $order_by = $valid_sorts[$sort] ?? $valid_sorts['impressions_desc'];

    $metrics_table = $wpdb->prefix . 'seom_page_metrics';
    $latest_date_clause = "(SELECT MAX(date_collected) FROM {$metrics_table})";

    $where = "p.post_type = 'post' AND p.post_status = 'publish'";
    if ($filter === 'missing_tldr') {
        $where .= " AND p.post_content NOT LIKE '%itu-tldr%'";
    } elseif ($filter === 'has_tldr') {
        $where .= " AND p.post_content LIKE '%itu-tldr%'";
    }

    $sql_count = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}";
    $total = (int) $wpdb->get_var($sql_count);

    // Intentionally NOT wrapped in $wpdb->prepare(). The query contains
    // LIKE '%itu-tldr%' literals; prepare() runs the SQL through a
    // sprintf-style processor that interprets every '%' as a placeholder,
    // which mangles the LIKE patterns and returns zero rows. $per_page and
    // $offset are integers we set ourselves (cast above), so direct
    // interpolation is safe — there's no user-supplied value in the SQL.
    $per_page_sql = (int) $per_page;
    $offset_sql   = (int) $offset;
    $sql = "
        SELECT
            p.ID                                AS post_id,
            p.post_title                        AS title,
            p.post_modified                     AS modified,
            COALESCE(m.impressions, 0)          AS impressions,
            COALESCE(m.clicks, 0)               AS clicks,
            COALESCE(m.avg_position, 0)         AS avg_position,
            CASE WHEN p.post_content LIKE '%itu-tldr%' THEN 1 ELSE 0 END AS has_tldr
        FROM {$wpdb->posts} p
        LEFT JOIN {$metrics_table} m
          ON p.ID = m.post_id AND m.date_collected = {$latest_date_clause}
        WHERE {$where}
        ORDER BY {$order_by}
        LIMIT {$per_page_sql} OFFSET {$offset_sql}
    ";
    $rows = $wpdb->get_results($sql);

    // Enrich with URL + edit URL — small per-row work since the list is paginated.
    foreach ($rows as $r) {
        $r->url      = get_permalink((int) $r->post_id);
        $r->edit_url = get_edit_post_link((int) $r->post_id, 'raw');
        $r->surgical_at = get_post_meta((int) $r->post_id, '_seom_surgical_tldr_at', true) ?: null;
    }

    wp_send_json_success([
        'rows'      => $rows,
        'page'      => $page,
        'per_page'  => $per_page,
        'total'     => $total,
        'pages'     => max(1, (int) ceil($total / $per_page)),
        'filter'    => $filter,
        'sort'      => $sort,
    ]);
});

add_action('wp_ajax_seom_surgical_insert_one', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(90);

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Missing post_id.');

    $result = seom_surgical_insert_tldr($post_id);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success($result);
});
