<?php
/**
 * Glossary Manager — admin tooling for the 5,000+ entry IT glossary.
 *
 * Schema note: `ec_it_glossary` is theme-owned and its PRIMARY KEY is the
 * `term` column itself — there is no surrogate `id`. All helpers below
 * use `term` as the canonical row identifier and pass it through AJAX as
 * a POST string (POST is fine for the long-ish strings — no URL length
 * concern). We also store a `slug` derived via sanitize_title() so the
 * crosswalk and future per-term permalinks have a clean URL key.
 *
 * Two pipelines, both batched and AI-driven, both designed to be run
 * incrementally over many days without timing out a single page load:
 *
 *   1. DEFINITION EXPANSION
 *      Replaces the one-sentence definition on a glossary row with a
 *      structured 300-500 word piece of content (definition lead → how
 *      it works → use cases → why it matters). The original text is
 *      preserved in `definition_original` so any expansion can be
 *      reverted in one click. Idempotent: rows that already have a
 *      stored `definition_original` are considered "expanded" and
 *      skipped unless the caller explicitly re-runs them.
 *
 *   2. CROSSWALK SCAN
 *      For each glossary term, scans all published posts and upserts
 *      one row in `wp_seom_glossary_crosswalk` per (term, post) pair
 *      that matched. Scoring weights title hits highest (10), heading
 *      hits mid (3), and body mentions low (1, capped at 5).
 *
 * Phase A (current): both pipelines exposed as on-demand admin runners.
 * Phase B (later)  : nightly cron handles incremental scans for posts
 *                    modified since the last scan time.
 * Phase C (later)  : per-term permalinks (/it-glossary/{slug}/) that
 *                    read from the crosswalk and render the top-K
 *                    related blogs.
 */

if (!defined('ABSPATH')) exit;

/**
 * Get a single glossary row by its term (the table's PK). Returns the raw
 * stdClass or null.
 */
function seom_glossary_get_row($term) {
    global $wpdb;
    $term = trim((string) $term);
    if ($term === '') return null;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM ec_it_glossary WHERE term = %s LIMIT 1",
        $term
    ));
    return $row ?: null;
}

/**
 * Word count of a glossary definition (the raw stored value — may include
 * HTML from a prior expansion run).
 */
function seom_glossary_word_count($html) {
    $text = trim(wp_strip_all_tags((string) $html));
    if ($text === '') return 0;
    return str_word_count($text);
}

/**
 * Ensure `slug` is populated for every row. Called lazily before any
 * list/crosswalk operation so we always have a stable join key.
 * Returns the number of rows that were updated. The query is small
 * enough (one SELECT + N UPDATEs) to handle the full 6K table in one
 * call without timing out.
 */
function seom_glossary_backfill_slugs($limit = 10000) {
    global $wpdb;
    $limit = max(1, min(50000, (int) $limit));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT term FROM ec_it_glossary
         WHERE (slug IS NULL OR slug = '') AND term IS NOT NULL AND term != ''
         LIMIT %d",
        $limit
    ));
    $updated = 0;
    foreach ($rows as $r) {
        $slug = sanitize_title($r->term);
        if ($slug === '') continue;
        $wpdb->update('ec_it_glossary', ['slug' => $slug], ['term' => $r->term]);
        $updated++;
    }
    return $updated;
}

/**
 * Status counts for the dashboard header. Returns:
 *   ['total' => N, 'expanded' => N, 'pending_expand' => N,
 *    'scanned' => N, 'pending_scan' => N, 'crosswalk_rows' => N]
 */
function seom_glossary_status_counts() {
    global $wpdb;
    $cw_table = $wpdb->prefix . 'seom_glossary_crosswalk';
    return [
        'total'           => (int) $wpdb->get_var("SELECT COUNT(*) FROM ec_it_glossary"),
        'expanded'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM ec_it_glossary WHERE definition_expanded IS NOT NULL AND definition_expanded != ''"),
        'pending_expand'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM ec_it_glossary WHERE definition_expanded IS NULL OR definition_expanded = ''"),
        'scanned'         => (int) $wpdb->get_var("SELECT COUNT(*) FROM ec_it_glossary WHERE crosswalk_scanned_at IS NOT NULL"),
        'pending_scan'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM ec_it_glossary WHERE crosswalk_scanned_at IS NULL"),
        'crosswalk_rows'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cw_table}"),
    ];
}

/**
 * Paginated, filterable term list for the admin table view. Returns:
 *   ['rows' => [...], 'total' => N, 'pages' => N]
 *
 * Each row includes the columns the table renders + a derived
 * `crosswalk_count` so the UI can show "X blogs reference this term"
 * without an N+1 query.
 *
 * Filter values:
 *   filter      — 'all' | 'expanded' | 'pending_expand' | 'scanned' | 'pending_scan'
 *   search      — substring match against term
 *   sort        — 'term' | 'expanded_at' | 'scanned_at' | 'crosswalk_count'
 *   order       — 'asc' | 'desc'
 */
function seom_glossary_list_terms($args = []) {
    global $wpdb;
    $defaults = [
        'filter'   => 'all',
        'search'   => '',
        'sort'     => 'term',
        'order'    => 'asc',
        'page'     => 1,
        'per_page' => 50,
    ];
    $a = array_merge($defaults, $args);

    $per_page = max(10, min(200, (int) $a['per_page']));
    $page     = max(1, (int) $a['page']);
    $offset   = ($page - 1) * $per_page;

    $where  = ["g.term IS NOT NULL", "g.term != ''"];
    $params = [];

    switch ($a['filter']) {
        case 'expanded':       $where[] = "g.definition_expanded IS NOT NULL AND g.definition_expanded != ''"; break;
        case 'pending_expand': $where[] = "(g.definition_expanded IS NULL OR g.definition_expanded = '')"; break;
        case 'scanned':        $where[] = "g.crosswalk_scanned_at IS NOT NULL"; break;
        case 'pending_scan':   $where[] = "g.crosswalk_scanned_at IS NULL"; break;
    }
    if (!empty($a['search'])) {
        $where[]  = "g.term LIKE %s";
        $params[] = '%' . $wpdb->esc_like(trim($a['search'])) . '%';
    }

    $sort_map = [
        'term'            => 'g.term',
        'expanded_at'     => 'g.definition_expanded_at',
        'scanned_at'      => 'g.crosswalk_scanned_at',
        'crosswalk_count' => 'crosswalk_count',
    ];
    $sort  = $sort_map[$a['sort']] ?? 'g.term';
    $order = strtoupper($a['order']) === 'DESC' ? 'DESC' : 'ASC';

    $cw_table  = $wpdb->prefix . 'seom_glossary_crosswalk';
    $where_sql = implode(' AND ', $where);

    // Total — same WHERE, no joins.
    $count_sql = "SELECT COUNT(*) FROM ec_it_glossary g WHERE {$where_sql}";
    $total = (int) ($params
        ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params))
        : $wpdb->get_var($count_sql));

    // Page — left join the count subquery so we can sort by it. Tie-break
    // on g.term (the PK) for stable ordering across pages.
    $sql = "SELECT g.*,
                   COALESCE(c.cnt, 0) AS crosswalk_count
            FROM ec_it_glossary g
            LEFT JOIN (
                SELECT term_slug, COUNT(*) AS cnt
                FROM {$cw_table}
                GROUP BY term_slug
            ) c ON c.term_slug = g.slug
            WHERE {$where_sql}
            ORDER BY {$sort} {$order}, g.term ASC
            LIMIT %d OFFSET %d";

    $params_with_paging = array_merge($params, [$per_page, $offset]);
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_with_paging));

    return [
        'rows'  => $rows ?: [],
        'total' => $total,
        'pages' => (int) ceil($total / $per_page),
        'page'  => $page,
    ];
}

// ─── DEFINITION EXPANSION ───────────────────────────────────────────────────

/**
 * System prompt for the expander. The model returns ONE JSON object
 * carrying the expanded HTML plus SEO metadata fields. We do this in a
 * single call (instead of HTML now + SEO meta later) because both
 * outputs need the same context — splitting them would double our
 * token spend.
 *
 * The HTML body content is stored in `definition_expanded`, SEO title
 * in `seo_title`, meta description in `seo_description`, TL;DR speakable
 * answer in `tldr`, and the FAQ list in `faq_json` (drives both visible
 * accordion + FAQPage schema).
 */
function seom_glossary_expand_system_prompt() {
    return <<<TXT
You expand short IT glossary definitions into structured, authoritative
educational content for ITU Online, an IT training and certification site,
AND produce search-engine-optimized metadata for the resulting page.

Return a SINGLE JSON object with EXACTLY these keys (no other keys, no
prose around it):

{
  "html": "<p>Lead paragraph (2-3 sentences)…</p><h3>How It Works</h3>…",
  "tldr": "30-50 word answer suitable for a featured snippet / AI assistant. Plain text only.",
  "seo_title": "50-60 char SERP title including the term and a hook word",
  "seo_description": "145-160 char meta description that reads like a search-result preview",
  "faqs": [
    { "question": "Specific question someone would type into Google.", "answer": "60-100 word concise answer." },
    { "question": "…", "answer": "…" },
    { "question": "…", "answer": "…" }
  ]
}

HTML body content (inside the "html" field):
  <p>Lead paragraph — tight 2-3 sentence definition.</p>
  <h3>How It Works</h3>
  <p>1-2 paragraphs explaining the mechanics, components, or process.</p>
  <h3>Common Use Cases</h3>
  <ul>
    <li>3-5 specific, concrete use cases (each ~10-25 words).</li>
  </ul>
  <h3>Why It Matters</h3>
  <p>1-2 paragraphs on relevance to IT professionals, certification
     candidates, or the certifications/job roles where this term appears.</p>

RULES:
  - HTML body total length: 300-500 words.
  - tldr: 30-50 words, complete sentences, no markup. Reads as a direct
    answer to "What is {term}?".
  - seo_title: 50-60 chars. Include the term verbatim. End with " | ITU Online".
    Example: "Kerberos Explained: How Authentication Works | ITU Online"
  - seo_description: 145-160 chars. Treat as ad copy — promise the value
    of clicking. No truncated definitions.
  - faqs: exactly 3 entries. Pick questions that match real "People Also
    Ask" patterns (how, what, vs, examples, requirements, etc).
  - Plain English, technical but accessible. Avoid marketing fluff.
  - Do NOT invent product names, version numbers, statistics, or dates.
  - Do NOT recommend specific courses — the page template handles that.
  - No emoji, no curly quotes, no em-dashes used as separators.
  - All string values must be valid JSON (escape internal quotes / newlines).
  - Return ONLY the JSON object. No code fences. No surrounding text.
TXT;
}

/**
 * Parse the JSON envelope returned by the expansion prompt. Returns:
 *   ['html' => string, 'tldr' => string, 'seo_title' => string,
 *    'seo_description' => string, 'faqs' => [['question', 'answer'], …]]
 *
 * On parse failure returns WP_Error. On partial responses (missing
 * fields), missing fields default to empty so the caller can decide
 * whether to fall back or fail.
 *
 * Tolerates models that wrap the JSON in ```json fences or prepend a
 * short prose preamble.
 */
function seom_glossary_parse_expansion_envelope($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') return new WP_Error('empty', 'Empty expansion response.');

    // Strip code fences if present.
    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw);

    // If the model prefixed prose, try to recover by trimming to the
    // outermost {...} block.
    $first = strpos($clean, '{');
    $last  = strrpos($clean, '}');
    if ($first === false || $last === false || $last <= $first) {
        return new WP_Error('not_json', 'Response is not JSON.');
    }
    $json = substr($clean, $first, $last - $first + 1);

    $obj = json_decode($json, true);
    if (!is_array($obj)) {
        return new WP_Error('parse_failed', 'JSON decode failed.');
    }

    $faqs = [];
    if (isset($obj['faqs']) && is_array($obj['faqs'])) {
        foreach ($obj['faqs'] as $faq) {
            if (!is_array($faq)) continue;
            $q = trim((string) ($faq['question'] ?? ''));
            $a = trim((string) ($faq['answer']   ?? ''));
            if ($q === '' || $a === '') continue;
            $faqs[] = ['question' => $q, 'answer' => $a];
        }
    }

    return [
        'html'            => trim((string) ($obj['html']            ?? '')),
        'tldr'            => trim((string) ($obj['tldr']            ?? '')),
        'seo_title'       => trim((string) ($obj['seo_title']       ?? '')),
        'seo_description' => trim((string) ($obj['seo_description'] ?? '')),
        'faqs'            => $faqs,
    ];
}

/**
 * Light wp_kses pass for the HTML body. Same allowlist used by both
 * the realtime expansion and batch apply paths so output is consistent
 * regardless of which path stored the row.
 */
function seom_glossary_clean_body_html($html) {
    $allowed = [
        'p'      => [],
        'h3'     => [],
        'ul'     => [],
        'ol'     => [],
        'li'     => [],
        'strong' => [],
        'em'     => [],
        'code'   => [],
        'br'     => [],
    ];
    return wp_kses((string) $html, $allowed);
}

/**
 * Call the OpenAI chat completion endpoint with the expansion prompt.
 * Returns trimmed text or WP_Error. Mirrors the helper in surgical-insert.php
 * but uses a higher max_tokens so the long form doesn't truncate.
 */
function seom_glossary_call_openai($system_prompt, $user_prompt) {
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
            'max_tokens'  => 1500,
        ]),
        'timeout' => 90,
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
 * Expand a single glossary term. The short form in `definition` is never
 * touched — the AI-generated long form is written to `definition_expanded`.
 * Returns:
 *   ['status' => 'expanded', 'term' => '...', 'words' => N]
 *   ['status' => 'skipped',  'term' => '...', 'reason' => 'already_expanded']
 *   WP_Error on any failure
 *
 * Side effects on success:
 *   - writes the new HTML into `definition_expanded`
 *   - snapshots the short form into `definition_original` on first run
 *     (defensive copy in case `definition` ever gets edited later)
 *   - stamps `definition_expanded_at`
 *   - invalidates the glossary index cache
 *
 * Pass force=true to regenerate a term that already has expanded content.
 */
function seom_glossary_expand_term($term_str, $force = false) {
    $row = seom_glossary_get_row($term_str);
    if (!$row) return new WP_Error('not_found', 'Glossary term not found.');

    $already_expanded = !empty($row->definition_expanded);
    if ($already_expanded && !$force) {
        return ['status' => 'skipped', 'term' => (string) $row->term, 'reason' => 'already_expanded'];
    }

    $term = trim((string) $row->term);
    $orig = trim((string) $row->definition);
    if ($term === '' || $orig === '') {
        return new WP_Error('empty_input', 'Term or definition is empty.');
    }

    $user_prompt = "Term: {$term}\n\n"
                 . "Existing one-sentence definition (preserve the meaning, "
                 . "expand to the full structured format):\n{$orig}";

    $raw = seom_glossary_call_openai(seom_glossary_expand_system_prompt(), $user_prompt);
    if (is_wp_error($raw)) return $raw;

    $envelope = seom_glossary_parse_expansion_envelope($raw);
    if (is_wp_error($envelope)) {
        // Legacy fallback: if the model returned raw HTML (e.g. older
        // prompt version cached server-side), treat the whole thing as
        // the body so we at least store useful content.
        $envelope = [
            'html'            => $raw,
            'tldr'            => '',
            'seo_title'       => '',
            'seo_description' => '',
            'faqs'            => [],
        ];
    }

    $clean = seom_glossary_clean_body_html($envelope['html']);
    $words = seom_glossary_word_count($clean);
    if ($words < 200) {
        return new WP_Error('too_short', "Generated content too short ({$words} words; need 200+).");
    }
    if ($words > 700) {
        return new WP_Error('too_long', "Generated content too long ({$words} words; max 700).");
    }

    global $wpdb;
    $now = current_time('mysql');
    $update_data = [
        'definition_expanded'    => $clean,
        'definition_expanded_at' => $now,
    ];
    // Defensive snapshot of the short form on first expansion. Lets us
    // recover if `definition` ever gets edited or corrupted; never
    // overwritten by re-runs.
    if (empty($row->definition_original)) {
        $update_data['definition_original'] = $orig;
    }
    if (empty($row->slug)) {
        $update_data['slug'] = sanitize_title($term);
    }
    // SEO metadata — only write when present so a legacy-format
    // response doesn't clobber existing fields with empty strings.
    if ($envelope['tldr']            !== '') $update_data['tldr']            = mb_substr($envelope['tldr'], 0, 1500);
    if ($envelope['seo_title']       !== '') $update_data['seo_title']       = mb_substr($envelope['seo_title'], 0, 190);
    if ($envelope['seo_description'] !== '') $update_data['seo_description'] = mb_substr($envelope['seo_description'], 0, 255);
    if (!empty($envelope['faqs']))           $update_data['faq_json']        = wp_json_encode($envelope['faqs'], JSON_UNESCAPED_UNICODE);
    if ($envelope['tldr'] !== '' || $envelope['seo_title'] !== '' || !empty($envelope['faqs'])) {
        $update_data['seo_meta_at'] = $now;
    }

    $wpdb->update('ec_it_glossary', $update_data, ['term' => $row->term]);

    if (function_exists('seom_glossary_invalidate_index')) {
        seom_glossary_invalidate_index();
    }
    do_action('seom_glossary_changed');

    return [
        'status'  => 'expanded',
        'term'    => $term,
        'words'   => $words,
        'has_seo' => !empty($update_data['seo_meta_at']),
    ];
}

/**
 * Clear the AI-expanded content for a term. The short form (`definition`)
 * is never touched — it's always preserved. Returns:
 *   ['status' => 'reverted', 'term' => '...']
 *   ['status' => 'skipped',  'term' => '...', 'reason' => 'no_expansion']
 */
function seom_glossary_revert_term($term_str) {
    $row = seom_glossary_get_row($term_str);
    if (!$row) return new WP_Error('not_found', 'Glossary term not found.');
    if (empty($row->definition_expanded)) {
        return ['status' => 'skipped', 'term' => (string) $row->term, 'reason' => 'no_expansion'];
    }
    global $wpdb;
    $wpdb->update(
        'ec_it_glossary',
        [
            'definition_expanded'    => null,
            'definition_expanded_at' => null,
        ],
        ['term' => $row->term]
    );
    if (function_exists('seom_glossary_invalidate_index')) {
        seom_glossary_invalidate_index();
    }
    do_action('seom_glossary_changed');
    return ['status' => 'reverted', 'term' => (string) $row->term];
}

// ─── SEO META ENRICHMENT (TL;DR + SEO TITLE/DESC + FAQS) ───────────────────
//
// Standalone path that enriches an already-expanded term with the SEO
// metadata fields. Cheaper than re-running full expansion because the
// model gets the existing content as input and only writes the four
// summary fields. Used for:
//   - per-row "Generate SEO meta" button on already-expanded terms
//   - Batch API submission to backfill the entire library at once

function seom_glossary_seo_meta_system_prompt() {
    return <<<TXT
You generate SEO metadata for an existing IT glossary page on ITU
Online (an IT training and certification site). The page already has
a long-form HTML body; you write the metadata layer that wraps it.

Return a SINGLE JSON object with EXACTLY these keys:

{
  "tldr": "30-50 word answer suitable for a featured snippet / AI assistant. Plain text only.",
  "seo_title": "50-60 char SERP title including the term and a hook word",
  "seo_description": "145-160 char meta description that reads like a search-result preview",
  "faqs": [
    { "question": "Specific question someone would type into Google.", "answer": "60-100 word concise answer." },
    { "question": "…", "answer": "…" },
    { "question": "…", "answer": "…" }
  ]
}

RULES:
  - tldr: 30-50 words, complete sentences, no markup. Reads as a direct
    answer to "What is {term}?".
  - seo_title: 50-60 chars. Include the term verbatim. End with " | ITU Online".
  - seo_description: 145-160 chars. Treat as ad copy — promise the value
    of clicking. No truncated definitions.
  - faqs: exactly 3 entries. Pick questions that match real "People Also
    Ask" patterns (how, what, vs, examples, requirements, etc).
  - Use the body content as your source of truth. Do not invent facts
    that aren't grounded in it.
  - No emoji, no curly quotes, no em-dashes used as separators.
  - Return ONLY the JSON object. No code fences. No surrounding text.
TXT;
}

/**
 * Parse a SEO-only AI response. Same envelope shape as the full
 * expansion but with no `html` key.
 */
function seom_glossary_parse_seo_envelope($raw) {
    $env = seom_glossary_parse_expansion_envelope($raw);
    if (is_wp_error($env)) return $env;
    // Drop html field; rest is the same.
    unset($env['html']);
    return $env;
}

/**
 * Generate SEO meta for a single already-expanded term using the
 * realtime endpoint. Cheap (~1.5K tokens input + 500 tokens output =
 * ~$0.0003 per term on gpt-4.1-nano). Returns:
 *   ['status' => 'ok', 'term' => '...', 'fields' => [...]]
 *   ['status' => 'skipped', 'term' => '...', 'reason' => '...']
 */
function seom_glossary_generate_seo_meta($term_str, $force = false) {
    $row = seom_glossary_get_row($term_str);
    if (!$row) return new WP_Error('not_found', 'Glossary term not found.');
    if (empty($row->definition_expanded)) {
        return new WP_Error('not_expanded', 'Term has no expanded content yet — run expansion first.');
    }
    if (!$force && !empty($row->seo_meta_at)) {
        return ['status' => 'skipped', 'term' => (string) $row->term, 'reason' => 'already_has_seo_meta'];
    }

    $user_prompt = "Term: {$row->term}\n\n"
                 . "Short definition: " . wp_strip_all_tags((string) $row->definition) . "\n\n"
                 . "Long-form body content (HTML):\n" . (string) $row->definition_expanded;

    $raw = seom_glossary_call_openai(seom_glossary_seo_meta_system_prompt(), $user_prompt);
    if (is_wp_error($raw)) return $raw;

    $env = seom_glossary_parse_seo_envelope($raw);
    if (is_wp_error($env)) return $env;

    global $wpdb;
    $now = current_time('mysql');
    $update_data = ['seo_meta_at' => $now];
    if ($env['tldr']            !== '') $update_data['tldr']            = mb_substr($env['tldr'], 0, 1500);
    if ($env['seo_title']       !== '') $update_data['seo_title']       = mb_substr($env['seo_title'], 0, 190);
    if ($env['seo_description'] !== '') $update_data['seo_description'] = mb_substr($env['seo_description'], 0, 255);
    if (!empty($env['faqs']))           $update_data['faq_json']        = wp_json_encode($env['faqs'], JSON_UNESCAPED_UNICODE);

    if (count($update_data) <= 1) {
        return new WP_Error('empty_meta', 'AI returned no usable fields.');
    }
    $wpdb->update('ec_it_glossary', $update_data, ['term' => $row->term]);
    do_action('seom_glossary_changed');

    return [
        'status' => 'ok',
        'term'   => (string) $row->term,
        'fields' => array_keys(array_filter($update_data, function ($v, $k) {
            return $k !== 'seo_meta_at' && $v !== null && $v !== '';
        }, ARRAY_FILTER_USE_BOTH)),
    ];
}

// AJAX — per-row "Generate SEO meta" trigger.
add_action('wp_ajax_seom_glossary_seo_meta', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(60);

    $term  = isset($_POST['term']) ? wp_unslash((string) $_POST['term']) : '';
    $force = !empty($_POST['force']);
    $r = seom_glossary_generate_seo_meta($term, $force);
    if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    wp_send_json_success($r);
});

// ─── CROSSWALK SCAN ─────────────────────────────────────────────────────────

/**
 * Compute the relevance score for a single term within a single post.
 *
 * Weights:
 *   - Title match           +10
 *   - H2/H3/H4 match        +3 per heading
 *   - Body mention          +1 per occurrence, capped at 5
 *
 * Returns:
 *   ['score' => int, 'match_type' => 'title'|'heading'|'body'|null]
 *
 * Returns score=0 / match_type=null when the term doesn't appear at all.
 * Matching is case-insensitive and word-boundary aware (same regex shape
 * as the glossary writer-link helper).
 */
function seom_glossary_score_term_in_post($term, $title, $content) {
    $needle = mb_strtolower(trim((string) $term));
    if ($needle === '' || strlen($needle) < 3) return ['score' => 0, 'match_type' => null];

    $pattern = '/(?<![a-z0-9])' . preg_quote($needle, '/') . '(?![a-z0-9])/iu';

    $score      = 0;
    $match_type = null;

    if (preg_match($pattern, (string) $title)) {
        $score += 10;
        $match_type = 'title';
    }

    if (preg_match_all('/<h[2-4][^>]*>(.*?)<\/h[2-4]>/is', (string) $content, $m)) {
        foreach ($m[1] as $heading_html) {
            $heading_text = wp_strip_all_tags($heading_html);
            if (preg_match($pattern, $heading_text)) {
                $score += 3;
                if (!$match_type) $match_type = 'heading';
            }
        }
    }

    $plain = wp_strip_all_tags((string) $content);
    $body_hits = preg_match_all($pattern, $plain, $unused);
    if ($body_hits > 0) {
        $score += min(5, (int) $body_hits);
        if (!$match_type) $match_type = 'body';
    }

    return ['score' => $score, 'match_type' => $match_type];
}

/**
 * Scan one term against all published posts and upsert crosswalk rows.
 * Returns:
 *   ['status' => 'scanned', 'term' => '...', 'matches' => N,
 *    'inserted' => N, 'updated' => N, 'removed' => N]
 *
 * Operation:
 *   - Compute score for every published post/page
 *   - For each post with score > 0: insert or update a crosswalk row
 *   - For posts that were in the crosswalk but now score 0, delete them
 *   - Stamp `crosswalk_scanned_at` on the glossary row
 */
function seom_glossary_scan_term($term_str) {
    global $wpdb;
    $row = seom_glossary_get_row($term_str);
    if (!$row) return new WP_Error('not_found', 'Glossary term not found.');
    $term = trim((string) $row->term);
    if ($term === '') return new WP_Error('empty_term', 'Term is empty.');

    $slug = !empty($row->slug) ? $row->slug : sanitize_title($term);
    if ($slug === '') return new WP_Error('no_slug', 'Could not derive slug from term.');
    if (empty($row->slug)) {
        $wpdb->update('ec_it_glossary', ['slug' => $slug], ['term' => $row->term]);
    }

    $cw_table = $wpdb->prefix . 'seom_glossary_crosswalk';
    $now      = current_time('mysql');

    $existing = $wpdb->get_results($wpdb->prepare(
        "SELECT id, post_id FROM {$cw_table} WHERE term_slug = %s",
        $slug
    ));
    $existing_by_post = [];
    foreach ($existing as $e) {
        $existing_by_post[(int) $e->post_id] = (int) $e->id;
    }

    $posts = $wpdb->get_results(
        "SELECT ID, post_title, post_content
         FROM {$wpdb->posts}
         WHERE post_status = 'publish'
           AND post_type IN ('post', 'page')"
    );

    $inserted = 0;
    $updated  = 0;
    $seen     = [];

    foreach ($posts as $post) {
        $result = seom_glossary_score_term_in_post($term, $post->post_title, $post->post_content);
        if ($result['score'] <= 0) continue;

        $post_id = (int) $post->ID;
        $seen[$post_id] = true;

        if (isset($existing_by_post[$post_id])) {
            $wpdb->update(
                $cw_table,
                [
                    'match_type'       => $result['match_type'],
                    'relevance_score'  => $result['score'],
                    'last_verified_at' => $now,
                ],
                ['id' => $existing_by_post[$post_id]]
            );
            $updated++;
        } else {
            $wpdb->insert($cw_table, [
                'term_slug'        => $slug,
                'post_id'          => $post_id,
                'match_type'       => $result['match_type'],
                'relevance_score'  => $result['score'],
                'discovered_at'    => $now,
                'last_verified_at' => $now,
            ]);
            $inserted++;
        }
    }

    $removed = 0;
    foreach ($existing_by_post as $post_id => $row_id) {
        if (!isset($seen[$post_id])) {
            $wpdb->delete($cw_table, ['id' => $row_id], ['%d']);
            $removed++;
        }
    }

    $wpdb->update(
        'ec_it_glossary',
        ['crosswalk_scanned_at' => $now],
        ['term' => $row->term]
    );

    return [
        'status'   => 'scanned',
        'term'     => $term,
        'matches'  => count($seen),
        'inserted' => $inserted,
        'updated'  => $updated,
        'removed'  => $removed,
    ];
}

// ─── AJAX ENDPOINTS ─────────────────────────────────────────────────────────

add_action('wp_ajax_seom_glossary_list', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    // Full backfill on first call so the LEFT JOIN against the crosswalk
    // (keyed on slug) actually matches. After this completes once, it's a
    // cheap no-op SELECT.
    seom_glossary_backfill_slugs(10000);

    $payload = seom_glossary_list_terms([
        'filter'   => isset($_POST['filter'])   ? sanitize_key($_POST['filter']) : 'all',
        'search'   => isset($_POST['search'])   ? sanitize_text_field($_POST['search']) : '',
        'sort'     => isset($_POST['sort'])     ? sanitize_key($_POST['sort']) : 'term',
        'order'    => isset($_POST['order'])    ? sanitize_key($_POST['order']) : 'asc',
        'page'     => isset($_POST['page'])     ? intval($_POST['page']) : 1,
        'per_page' => isset($_POST['per_page']) ? intval($_POST['per_page']) : 50,
    ]);
    $payload['counts'] = seom_glossary_status_counts();
    wp_send_json_success($payload);
});

add_action('wp_ajax_seom_glossary_expand', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(120);

    $term  = isset($_POST['term']) ? wp_unslash((string) $_POST['term']) : '';
    $force = !empty($_POST['force']);
    $r = seom_glossary_expand_term($term, $force);
    if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    wp_send_json_success($r);
});

add_action('wp_ajax_seom_glossary_revert', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $term = isset($_POST['term']) ? wp_unslash((string) $_POST['term']) : '';
    $r = seom_glossary_revert_term($term);
    if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    wp_send_json_success($r);
});

add_action('wp_ajax_seom_glossary_scan', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(180);

    $term = isset($_POST['term']) ? wp_unslash((string) $_POST['term']) : '';
    $r = seom_glossary_scan_term($term);
    if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    wp_send_json_success($r);
});

// ─── CONTENT GAP TOPIC FINDER ───────────────────────────────────────────────
//
// Identifies glossary terms with zero or thin blog coverage and proposes
// them as future blog topics. Two tiers:
//
//   Tier 1 — glossary term that matches a row in wp_seom_keyword_gaps
//            (proven search demand). The matched keyword + volume is
//            attached to the candidate so the writer can target it.
//   Tier 2 — glossary term with no keyword-gap match. The term itself
//            becomes the seed keyword.
//
// Both tiers are returned. The UI surfaces tier 1 first; tier 2 is
// available as fallback when tier 1 has been exhausted for a given
// primary_area / featured product.

/**
 * Return candidate glossary-gap rows. The query enriches each row with:
 *   - crosswalk_count : how many blogs already reference the term
 *   - kw_match        : best matching keyword from keyword_gaps (or NULL)
 *   - kw_volume       : that keyword's search volume (or NULL)
 *   - kw_difficulty   : that keyword's difficulty (or NULL)
 *   - tier            : 1 (has kw_match) or 2 (glossary-only)
 *
 * Args:
 *   coverage_max   — max crosswalk_count to consider a "gap" (default 2)
 *   primary_area   — optional filter (e.g. "Cybersecurity")
 *   tier           — 0 (both), 1 (gap-matched only), 2 (glossary-only)
 *   exclude_queued — when true, hides rows with topic_queued_at within 180d
 *   per_page / page
 *
 * Performance: the keyword-gap match uses two correlated subqueries
 * (best-matching keyword + its volume). ec_it_glossary is ~6K rows so
 * this runs in a few hundred ms even on a cold cache. If it becomes a
 * problem we'll materialize the matches into a join table.
 */
function seom_glossary_get_gap_candidates($args = []) {
    global $wpdb;
    $defaults = [
        'coverage_max'   => 2,
        'primary_area'   => '',
        'tier'           => 0,
        'exclude_queued' => true,
        'page'           => 1,
        'per_page'       => 50,
    ];
    $a = array_merge($defaults, $args);

    $coverage_max = max(0, min(20, (int) $a['coverage_max']));
    $per_page     = max(5, min(500, (int) $a['per_page']));
    $page         = max(1, (int) $a['page']);
    $offset       = ($page - 1) * $per_page;

    $cw_table   = $wpdb->prefix . 'seom_glossary_crosswalk';
    $kgap_table = $wpdb->prefix . 'seom_keyword_gaps';

    $where = ["g.term IS NOT NULL", "g.term != ''"];
    $params = [];

    if (!empty($a['primary_area'])) {
        $where[]  = "g.primary_area = %s";
        $params[] = (string) $a['primary_area'];
    }
    if (!empty($a['exclude_queued'])) {
        $where[] = "(g.topic_queued_at IS NULL OR g.topic_queued_at < DATE_SUB(NOW(), INTERVAL 180 DAY))";
    }

    $where_sql = implode(' AND ', $where);

    // Keep the SQL simple: glossary rows + coverage count, no LIKE join
    // (66K keyword_gaps × 6K glossary terms × LIKE substring = ~400M ops
    // which MariaDB 10.5 cannot deliver in interactive time). Keyword-gap
    // enrichment is layered on in PHP from a cached inverted index built
    // by seom_glossary_get_kw_match_index() below.
    $sql = "
        SELECT
            g.term,
            g.slug,
            g.primary_area,
            g.definition,
            g.definition_original,
            g.definition_expanded_at,
            g.crosswalk_scanned_at,
            g.topic_queued_at,
            COALESCE(cw.cnt, 0) AS crosswalk_count
        FROM ec_it_glossary g
        LEFT JOIN (
            SELECT term_slug, COUNT(*) AS cnt
            FROM {$cw_table}
            GROUP BY term_slug
        ) cw ON cw.term_slug = g.slug
        WHERE {$where_sql}
        HAVING crosswalk_count <= %d
    ";

    // Pull ALL eligible glossary rows (no pagination yet — we need to
    // tier-sort AFTER enrichment). The filtered set is at most the
    // ~5,727 unscanned + low-coverage rows, so this fits comfortably
    // in memory.
    $bind = array_merge($params, [$coverage_max]);
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$bind));
    if (!$rows) return [];

    // Enrich with kw_match data from the cached index.
    $kw_index = seom_glossary_get_kw_match_index();
    foreach ($rows as &$r) {
        $key = mb_strtolower((string) $r->term);
        $match = $kw_index[$key] ?? null;
        $r->kw_match      = $match['keyword']     ?? null;
        $r->kw_volume     = $match['volume']      ?? null;
        $r->kw_difficulty = $match['difficulty']  ?? null;
        $r->kw_tag        = $match['tag']         ?? null;
        $r->tier          = $match ? 1 : 2;
    }
    unset($r);

    // Tier filter.
    if ((int) $a['tier'] === 1) {
        $rows = array_values(array_filter($rows, function ($r) { return $r->tier === 1; }));
    } elseif ((int) $a['tier'] === 2) {
        $rows = array_values(array_filter($rows, function ($r) { return $r->tier === 2; }));
    }

    // Sort: tier 1 first, then by volume DESC, then by coverage ASC,
    // then by term ASC for stable ordering.
    usort($rows, function ($a, $b) {
        if ($a->tier !== $b->tier) return $a->tier - $b->tier; // 1 before 2
        $va = (int) ($a->kw_volume ?? 0);
        $vb = (int) ($b->kw_volume ?? 0);
        if ($va !== $vb) return $vb - $va;
        $ca = (int) ($a->crosswalk_count ?? 0);
        $cb = (int) ($b->crosswalk_count ?? 0);
        if ($ca !== $cb) return $ca - $cb;
        return strcasecmp($a->term, $b->term);
    });

    // Paginate.
    return array_slice($rows, $offset, $per_page);
}

/**
 * Build (and cache) the term-to-best-keyword-gap index. Returns a map:
 *   [ lowercase_term => ['keyword' => '...', 'volume' => N,
 *                        'difficulty' => '...', 'tag' => '...'] ]
 *
 * The "best" match for a term is the keyword-gap row whose `keyword`
 * string contains the term as a substring (case-insensitive) and has the
 * highest search volume. Only your_position=0 rows count — gaps we don't
 * yet rank for.
 *
 * The build does N × M substring checks (~6K glossary terms × ~66K gap
 * rows = ~400M comparisons) so we cache the result aggressively. The
 * transient is invalidated externally when:
 *   - keyword gaps are imported (hook into the importer if needed)
 *   - glossary rows change (we already invalidate the glossary index)
 *   - the rebuild button is clicked
 *
 * Build time: 5-15 seconds on the live data set. Cached for 12 hours.
 */
function seom_glossary_get_kw_match_index($fresh = false) {
    $cache_key = 'seom_glossary_kw_match_index_v1';
    if (!$fresh) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;
    }
    global $wpdb;
    $kgap_table = $wpdb->prefix . 'seom_keyword_gaps';

    // Pull glossary terms (lowercase + raw) and keyword gaps (lowercase +
    // metadata). Sorting keyword gaps by volume DESC means the first hit
    // we find for any term IS the best match, so we can early-exit.
    $glossary = $wpdb->get_results(
        "SELECT term FROM ec_it_glossary
         WHERE term IS NOT NULL AND term != ''"
    );
    $gaps = $wpdb->get_results(
        "SELECT keyword, search_volume, keyword_difficulty, tag
         FROM {$kgap_table}
         WHERE your_position = 0
         ORDER BY search_volume DESC"
    );

    if (!$glossary || !$gaps) {
        set_transient($cache_key, [], 5 * MINUTE_IN_SECONDS);
        return [];
    }

    // Precompute lowercase forms once.
    $lc_terms = [];
    foreach ($glossary as $g) {
        $term = trim((string) $g->term);
        if ($term === '') continue;
        $lc_terms[mb_strtolower($term)] = $term;
    }

    $lc_gaps = [];
    foreach ($gaps as $gap) {
        $kw = trim((string) $gap->keyword);
        if ($kw === '') continue;
        $lc_gaps[] = [
            'lc'         => mb_strtolower($kw),
            'kw'         => $kw,
            'volume'     => (int) $gap->search_volume,
            'difficulty' => (string) $gap->keyword_difficulty,
            'tag'        => (string) $gap->tag,
        ];
    }

    // For each term, find the FIRST gap whose lowercase keyword contains
    // the lowercase term as a WHOLE-WORD match. Cheap strpos pre-filter
    // weeds out 99% of non-matches; the regex word-boundary check only
    // runs on the ~hundreds of candidates that survive — keeps the build
    // fast while preventing false positives like "java" → "javascript".
    // Gaps are sorted by volume DESC so the first surviving hit is the
    // best match and we can break early.
    $index = [];
    foreach ($lc_terms as $lc_term => $orig_term) {
        if (strlen($lc_term) < 3) continue; // skip 1-2 char terms (noise)
        $pattern = '/(?<![a-z0-9])' . preg_quote($lc_term, '/') . '(?![a-z0-9])/u';
        foreach ($lc_gaps as $gap) {
            if (strpos($gap['lc'], $lc_term) === false) continue;
            if (!preg_match($pattern, $gap['lc'])) continue;
            $index[$lc_term] = [
                'keyword'    => $gap['kw'],
                'volume'     => $gap['volume'],
                'difficulty' => $gap['difficulty'],
                'tag'        => $gap['tag'],
            ];
            break;
        }
    }

    set_transient($cache_key, $index, 12 * HOUR_IN_SECONDS);
    return $index;
}

/**
 * Invalidate the kw-match index. Call after importing new keyword gaps
 * or via a manual admin trigger.
 */
function seom_glossary_invalidate_kw_match_index() {
    delete_transient('seom_glossary_kw_match_index_v1');
}

/**
 * Tier breakdown across the eligible candidate pool, ignoring pagination.
 * Returns: ['tier1' => N, 'tier2' => N, 'total' => N].
 *
 * The same WHERE / HAVING shape as get_gap_candidates so the numbers in
 * the UI's filter dropdowns match what the paginated list will return.
 */
function seom_glossary_gap_counts($coverage_max = 2, $primary_area = '', $exclude_queued = true) {
    global $wpdb;
    $coverage_max = max(0, min(20, (int) $coverage_max));
    $cw_table     = $wpdb->prefix . 'seom_glossary_crosswalk';

    $where  = ["g.term IS NOT NULL", "g.term != ''"];
    $params = [];
    if ($primary_area !== '') { $where[] = "g.primary_area = %s"; $params[] = $primary_area; }
    if ($exclude_queued)      { $where[] = "(g.topic_queued_at IS NULL OR g.topic_queued_at < DATE_SUB(NOW(), INTERVAL 180 DAY))"; }
    $where_sql = implode(' AND ', $where);

    // Pull eligible rows (no LIKE join — that's done in the cached kw
    // index). Tier breakdown is derived in PHP.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT g.term, COALESCE(cw.cnt, 0) AS crosswalk_count
         FROM ec_it_glossary g
         LEFT JOIN (
             SELECT term_slug, COUNT(*) AS cnt
             FROM {$cw_table}
             GROUP BY term_slug
         ) cw ON cw.term_slug = g.slug
         WHERE {$where_sql}
         HAVING crosswalk_count <= %d",
        ...array_merge($params, [$coverage_max])
    ));
    if (!$rows) return ['tier1' => 0, 'tier2' => 0, 'total' => 0];

    $kw_index = seom_glossary_get_kw_match_index();
    $tier1 = 0; $tier2 = 0;
    foreach ($rows as $r) {
        $key = mb_strtolower((string) $r->term);
        if (isset($kw_index[$key])) $tier1++;
        else $tier2++;
    }
    return ['tier1' => $tier1, 'tier2' => $tier2, 'total' => count($rows)];
}

/**
 * Available primary_area buckets (with row counts) for the gap-finder UI.
 * Used to populate the "Limit to area" dropdown so the user can scope a
 * batch to "Cybersecurity" / "Networking" / etc.
 */
function seom_glossary_primary_areas() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT primary_area, COUNT(*) AS cnt
         FROM ec_it_glossary
         WHERE primary_area IS NOT NULL AND primary_area != ''
         GROUP BY primary_area
         ORDER BY primary_area ASC"
    );
    return $rows ?: [];
}

/**
 * Convert a batch of gap candidates into blog topic angles via one
 * OpenAI call. Input is an array of objects (from get_gap_candidates).
 * Output is an array of:
 *   [
 *     'term'             => '...',
 *     'slug'             => '...',
 *     'suggested_title'  => '...',
 *     'primary_keyword'  => '...',
 *     'content_type'     => 'howto'|'comparison'|'concept'|'career',
 *     'tier'             => 1|2,
 *     'kw_tag'           => '...'   // from keyword_gaps if tier 1
 *   ]
 *
 * Batches are capped at 20 candidates per call to keep the response
 * inside model token limits and to keep retry blast-radius small.
 */
function seom_glossary_generate_angles($candidates) {
    if (!is_array($candidates) || !$candidates) return [];
    if (count($candidates) > 20) {
        $candidates = array_slice($candidates, 0, 20);
    }

    // Build the input block — give the model term + 1-sentence definition
    // hint + matched keyword (when tier 1) so it can frame the angle as a
    // real search query rather than a Wikipedia-style "What is X?" by
    // default.
    $items = [];
    foreach ($candidates as $i => $c) {
        $def_first_sentence = preg_split('/(?<=[.!?])\s+/', wp_strip_all_tags((string) $c->definition), 2);
        $hint = isset($def_first_sentence[0]) ? trim($def_first_sentence[0]) : '';
        if (mb_strlen($hint) > 220) $hint = mb_substr($hint, 0, 220) . '…';

        $items[] = sprintf(
            "%d. Term: %s\n   Area: %s\n   Definition: %s%s",
            $i + 1,
            $c->term,
            $c->primary_area ?: '(none)',
            $hint,
            !empty($c->kw_match)
                ? "\n   Search demand: \"{$c->kw_match}\" (volume ~" . (int) $c->kw_volume . ")"
                : ''
        );
    }
    $item_block = implode("\n\n", $items);

    $system = <<<TXT
You convert IT glossary terms into blog topic angles for an IT
training and certification site (ITU Online). For each input term,
output ONE blog topic that reads like a real search query someone
would type — not a Wikipedia-style "What is X?" unless the term is
genuinely best served as a definition piece.

Rules:
  - The suggested_title MUST be 50-75 characters, title-case, no trailing year.
  - The primary_keyword MUST be a realistic search phrase (4-9 words).
  - When a "Search demand" hint is provided, use that keyword as the
    primary_keyword (or a tight variant of it).
  - Pick content_type from: howto, comparison, concept, career.
    Use comparison when the term is one half of a well-known pair
    (e.g. NAT vs PAT), career when the term is a role/cert, concept
    when it's an architecture/standard, howto otherwise.
  - No emoji, no Q&A format, no "Ultimate Guide" or "Complete Guide" cliches.

Return ONLY a JSON array (no prose), one object per input in input order:
[{"term":"...","suggested_title":"...","primary_keyword":"...","content_type":"..."}]
TXT;

    $user = "Generate angles for these glossary terms:\n\n" . $item_block;

    $raw = seom_glossary_call_openai($system, $user);
    if (is_wp_error($raw)) return $raw;

    // Strip code fences the model sometimes wraps JSON in.
    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
    $decoded = json_decode($clean, true);
    if (!is_array($decoded)) {
        return new WP_Error('parse_failed', 'AI returned unparseable JSON. Raw: ' . substr($raw, 0, 400));
    }

    // Re-attach the source candidate metadata so the caller gets a
    // complete row per angle (term/slug match by position, not name —
    // the model occasionally normalizes capitalization).
    $angles = [];
    foreach ($decoded as $i => $obj) {
        if (!isset($candidates[$i])) continue;
        $src = $candidates[$i];
        $title = trim((string) ($obj['suggested_title'] ?? ''));
        $kw    = trim((string) ($obj['primary_keyword'] ?? ''));
        $ct    = strtolower(trim((string) ($obj['content_type'] ?? '')));
        if (!in_array($ct, ['howto', 'comparison', 'concept', 'career'], true)) $ct = 'concept';
        if ($title === '' || $kw === '') continue;
        // Hard-strip trailing years just in case.
        if (function_exists('bq_strip_year_from_title')) {
            $title = bq_strip_year_from_title($title);
        }
        $angles[] = [
            'term'            => (string) $src->term,
            'slug'            => (string) $src->slug,
            'primary_area'    => (string) $src->primary_area,
            'suggested_title' => $title,
            'primary_keyword' => $kw,
            'content_type'    => $ct,
            'tier'            => (int) $src->tier,
            'kw_volume'       => (int) ($src->kw_volume ?? 0),
            'kw_tag'          => (string) ($src->kw_tag ?? ''),
        ];
    }
    return $angles;
}

/**
 * Insert generated angles into the blog queue (`wp_bq_queue`). Returns:
 *   ['queued' => N, 'skipped' => N, 'queue_ids' => [...]]
 *
 * Side effects: stamps `topic_queued_at` on the glossary row so the
 * gap finder won't re-suggest the same term for 180 days.
 *
 * gap_category in the queue row is populated from the matched keyword
 * gap's tag when present (tier 1), else falls back to primary_area.
 * That keeps cluster-pillar lookup downstream working for tier 1 and
 * still gives the writer prompt a useful category context for tier 2.
 */
function seom_glossary_queue_angles($angles, $featured_product_id = 0) {
    global $wpdb;
    if (!is_array($angles) || !$angles) return ['queued' => 0, 'skipped' => 0, 'queue_ids' => []];

    $queue_table = $wpdb->prefix . 'bq_queue';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$queue_table}'") !== $queue_table) {
        return new WP_Error('no_queue', 'Blog queue table not found. Is the blog-queue plugin active?');
    }

    $now = current_time('mysql');
    $queued = 0;
    $skipped = 0;
    $ids = [];

    foreach ($angles as $a) {
        $title = trim((string) ($a['suggested_title'] ?? ''));
        $kw    = trim((string) ($a['primary_keyword'] ?? ''));
        if ($title === '' || $kw === '') { $skipped++; continue; }

        // Duplicate guard — same exact title already in queue or in last
        // 30 days of history.
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$queue_table} WHERE title = %s LIMIT 1",
            $title
        ));
        if ($exists) { $skipped++; continue; }

        $gap_category = !empty($a['kw_tag']) ? $a['kw_tag'] : (string) ($a['primary_area'] ?? '');
        $inserted = $wpdb->insert($queue_table, [
            'title'               => $title,
            'gap_category'        => $gap_category,
            'target_keywords'     => $kw,
            'featured_product_id' => (int) $featured_product_id,
            'content_type'        => (string) ($a['content_type'] ?? 'concept'),
            'status'              => 'pending',
            'added_at'            => $now,
        ]);
        if (!$inserted) { $skipped++; continue; }

        $qid = (int) $wpdb->insert_id;
        $ids[] = $qid;
        $queued++;

        // Stamp the glossary row.
        if (!empty($a['term'])) {
            $wpdb->update(
                'ec_it_glossary',
                ['topic_queued_at' => $now],
                ['term' => (string) $a['term']]
            );
        }
    }

    return ['queued' => $queued, 'skipped' => $skipped, 'queue_ids' => $ids];
}

// AJAX — list candidate gaps for the UI table.
add_action('wp_ajax_seom_glossary_gap_list', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    seom_glossary_backfill_slugs(10000);

    $args = [
        'coverage_max'   => isset($_POST['coverage_max'])   ? intval($_POST['coverage_max']) : 2,
        'primary_area'   => isset($_POST['primary_area'])   ? sanitize_text_field($_POST['primary_area']) : '',
        'tier'           => isset($_POST['tier'])           ? intval($_POST['tier']) : 0,
        'exclude_queued' => !empty($_POST['exclude_queued']),
        'page'           => isset($_POST['page'])           ? intval($_POST['page']) : 1,
        'per_page'       => isset($_POST['per_page'])       ? intval($_POST['per_page']) : 50,
    ];

    $rows   = seom_glossary_get_gap_candidates($args);
    $counts = seom_glossary_gap_counts(
        $args['coverage_max'],
        $args['primary_area'],
        $args['exclude_queued']
    );

    wp_send_json_success([
        'rows'   => $rows,
        'counts' => $counts,
        'areas'  => seom_glossary_primary_areas(),
        'page'   => $args['page'],
    ]);
});

// AJAX — generate angles for a chosen subset (up to 20 at a time).
add_action('wp_ajax_seom_glossary_gap_angles', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(120);

    $terms_raw = isset($_POST['terms']) ? wp_unslash((string) $_POST['terms']) : '';
    $terms = json_decode($terms_raw, true);
    if (!is_array($terms) || !$terms) wp_send_json_error('No terms provided.');

    // Pull the source rows so we have definitions + match metadata. We
    // re-fetch instead of trusting client-supplied fields to avoid
    // spoofing of kw_volume etc.
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($terms), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT term, slug, primary_area, definition FROM ec_it_glossary
         WHERE term IN ({$placeholders})",
        ...array_map('strval', $terms)
    ));
    if (!$rows) wp_send_json_error('Terms not found in glossary.');

    // Re-attach the keyword-gap match for each row from the cached index.
    $kw_index = seom_glossary_get_kw_match_index();
    foreach ($rows as &$r) {
        $key = mb_strtolower((string) $r->term);
        $match = $kw_index[$key] ?? null;
        $r->kw_match      = $match['keyword']     ?? null;
        $r->kw_volume     = $match['volume']      ?? null;
        $r->kw_difficulty = $match['difficulty']  ?? null;
        $r->kw_tag        = $match['tag']         ?? null;
        $r->tier          = $match ? 1 : 2;
    }
    unset($r);

    $angles = seom_glossary_generate_angles($rows);
    if (is_wp_error($angles)) wp_send_json_error($angles->get_error_message());

    wp_send_json_success(['angles' => $angles]);
});

// AJAX — queue a set of angles as topics. Receives the angle objects
// directly from the UI (they're already validated by generate_angles).
add_action('wp_ajax_seom_glossary_gap_queue', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $angles_raw = isset($_POST['angles']) ? wp_unslash((string) $_POST['angles']) : '';
    $angles = json_decode($angles_raw, true);
    if (!is_array($angles) || !$angles) wp_send_json_error('No angles provided.');

    $featured_product_id = isset($_POST['featured_product_id']) ? intval($_POST['featured_product_id']) : 0;
    $r = seom_glossary_queue_angles($angles, $featured_product_id);
    if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    wp_send_json_success($r);
});

/**
 * Fetch the current definition for a term so the admin can preview the
 * expansion (or compare it against the stored original).
 */
add_action('wp_ajax_seom_glossary_preview', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $term = isset($_POST['term']) ? wp_unslash((string) $_POST['term']) : '';
    $row  = seom_glossary_get_row($term);
    if (!$row) wp_send_json_error('Not found.');

    wp_send_json_success([
        'term'        => (string) $row->term,
        'slug'        => (string) $row->slug,
        'definition'  => (string) $row->definition,
        'expanded'    => (string) $row->definition_expanded,
        'original'    => (string) $row->definition_original,
        'expanded_at' => (string) $row->definition_expanded_at,
        'scanned_at'  => (string) $row->crosswalk_scanned_at,
        'words'       => seom_glossary_word_count($row->definition_expanded ?: $row->definition),
    ]);
});
