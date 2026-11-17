<?php
/**
 * Glossary integration for SEO AI AutoPilot — phase 3a.
 *
 * The glossary lives in a custom table `ec_it_glossary` (managed by the
 * theme's [it_glossary] shortcode) and is rendered as accordions on a
 * single page at /it-glossary/. Phase 1a's authority layer already emits
 * speakable + Organization schema on every post; phase 3a wires the
 * glossary into the new-blog and refresh paths so the writer naturally
 * links to canonical term definitions, building hub-and-spoke topical
 * authority across 5,000+ entity references.
 *
 * Three responsibilities:
 *
 *  1. seom_get_glossary_index() — load and cache the full term list. The
 *     index is a transient with 24-hour TTL since the table changes rarely.
 *     Each entry contains the raw term, a sortable normalized form, and the
 *     anchor URL on the public glossary page.
 *
 *  2. seom_find_glossary_terms_in_text() — given a chunk of text (outline,
 *     existing post body, etc.) return up to N matching terms ranked by
 *     specificity (longest match wins over shorter substring matches). This
 *     is what feeds the writer-prompt linking instruction.
 *
 *  3. Settings + helpers exposed via the seom_get_settings() defaults.
 */

if (!defined('ABSPATH')) exit;

/**
 * Resolve the glossary index page URL. Defaults to /it-glossary/ but reads
 * a setting so admins can move the page without code changes.
 */
function seom_glossary_index_url() {
    $settings = seom_get_settings();
    $url = isset($settings['glossary_url']) ? trim((string) $settings['glossary_url']) : '';
    if ($url === '') $url = home_url('/it-glossary/');
    return trailingslashit($url);
}

/**
 * Load the full glossary index — array of:
 *   [
 *     'term'   => string (canonical name as stored),
 *     'lower'  => string (lowercased for matching),
 *     'slug'   => string (sanitize_title result, matches the anchor id),
 *     'url'    => string (deep-link including ?letter=X&pagenum=Y#term-slug),
 *   ]
 *
 * The URL has to point at the SAME paginated page the public shortcode
 * would render the term on — otherwise the anchor exists on a page the
 * user never lands on. Page size matches the theme's get_terms_by_letter()
 * value of 50 (see render path in functions.php). We compute pagenum by
 * counting each term's position within its letter group, using the same
 * `ORDER BY term ASC` the public page uses.
 *
 * Cached as a transient for 24 hours. To force rebuild call with $fresh=true
 * or delete the transient externally via seom_glossary_invalidate_index().
 */
function seom_get_glossary_index($fresh = false) {
    $cache_key = 'seom_glossary_index_v2'; // v2 = paginated URLs
    if (!$fresh) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;
    }
    global $wpdb;
    $table = 'ec_it_glossary'; // unprefixed — matches the theme code
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        set_transient($cache_key, [], 5 * MINUTE_IN_SECONDS);
        return [];
    }

    // ORDER BY term ASC — must mirror get_terms_by_letter() exactly so the
    // letter-page positions we compute below align with what the user
    // actually sees on /it-glossary/?letter=X&pagenum=Y.
    $rows = $wpdb->get_results("SELECT term FROM {$table} WHERE term IS NOT NULL AND term != '' ORDER BY term ASC");
    $base = seom_glossary_index_url();
    $items_per_page = 50; // matches theme

    $letter_position = []; // 'A' => N seen so far
    $index = [];
    foreach ($rows as $r) {
        $term = trim((string) $r->term);
        if ($term === '') continue;
        $slug = sanitize_title($term);
        if ($slug === '') continue;

        $first = strtoupper(mb_substr($term, 0, 1));
        if (preg_match('/^[A-Z]$/', $first)) {
            $position = $letter_position[$first] ?? 0;
            $pagenum  = (int) floor($position / $items_per_page) + 1;
            $letter_position[$first] = $position + 1;
            $url = $base . '?letter=' . $first . '&pagenum=' . $pagenum . '#term-' . $slug;
        } else {
            // Numeric / symbol terms — the public page's A-Z nav only handles
            // A-Z, so we link without letter params and let the user use the
            // search box (or future enhancement: a "0-9" tab).
            $url = $base . '#term-' . $slug;
        }

        $index[] = [
            'term'  => $term,
            'lower' => mb_strtolower($term),
            'slug'  => $slug,
            'url'   => $url,
        ];
    }

    // Sort by descending length so the matcher prefers the most specific
    // term first ("Access Control List (ACL)" before "Access Control").
    usort($index, function ($a, $b) {
        $la = mb_strlen($a['lower']);
        $lb = mb_strlen($b['lower']);
        if ($la === $lb) return strcmp($a['lower'], $b['lower']);
        return $lb - $la;
    });

    set_transient($cache_key, $index, DAY_IN_SECONDS);
    return $index;
}

/**
 * Drop the cached index — call when the underlying table changes (CSV
 * import, manual edit, etc.). Exposed as a public hook so the theme
 * shortcode or an admin tool can poke it.
 */
function seom_glossary_invalidate_index() {
    // Clear both old and current cache keys so a one-off cleanup also
    // drops any legacy v1 cache that's still floating around.
    delete_transient('seom_glossary_index_v1');
    delete_transient('seom_glossary_index_v2');
}
add_action('seom_glossary_changed', 'seom_glossary_invalidate_index');

/**
 * Find glossary terms that appear in a chunk of text. Used to assemble
 * "glossary terms available to link" context for the writer prompt.
 *
 * Matching rules:
 *   - case-insensitive, word-boundary aware (so "Access" doesn't match
 *     "Accessory")
 *   - longest match wins — a hit on "Access Control List (ACL)" suppresses
 *     a hit on "Access Control" within the same span
 *   - returns up to $limit unique terms in the order they first appear in
 *     the text (so the writer sees them in the same order they'd be
 *     introduced in the post)
 *
 * Performance: O(N × T) where N = text length, T = index size. For 5K
 * terms + a 5KB outline this is < 50ms in practice. Returns an empty
 * array when no terms match (or the index is empty).
 */
function seom_find_glossary_terms_in_text($text, $limit = 10) {
    $text = (string) $text;
    if ($text === '' || $limit < 1) return [];
    $index = seom_get_glossary_index();
    if (empty($index)) return [];

    $haystack = mb_strtolower($text);
    $matches  = []; // slug => ['term' => ..., 'url' => ..., 'pos' => first byte offset]

    foreach ($index as $entry) {
        if (isset($matches[$entry['slug']])) continue;
        if (strlen($entry['lower']) < 3) continue; // skip 1-2 char terms
        // word-boundary check via simple regex on the lowercase haystack
        $needle = $entry['lower'];
        $pattern = '/(?<![a-z0-9])' . preg_quote($needle, '/') . '(?![a-z0-9])/u';
        if (preg_match($pattern, $haystack, $m, PREG_OFFSET_CAPTURE)) {
            $matches[$entry['slug']] = [
                'term' => $entry['term'],
                'url'  => $entry['url'],
                'pos'  => (int) $m[0][1],
            ];
            if (count($matches) >= $limit * 2) break; // collect a buffer; ranked + trimmed below
        }
    }
    if (empty($matches)) return [];

    // Sort by first-appearance position so the writer sees terms in reading order.
    uasort($matches, function ($a, $b) { return $a['pos'] - $b['pos']; });

    // Strip down to {term, url} and cap at $limit.
    $out = [];
    foreach ($matches as $m) {
        $out[] = ['term' => $m['term'], 'url' => $m['url']];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * Public helper for callers (Blog Queue, refresh path) that just want the
 * raw "X glossary terms; link the first mention of each" block to drop
 * into writer opts. Returns an empty array when the integration is off,
 * the index is empty, or no candidate text was provided.
 */
function seom_resolve_glossary_links_for_writer($candidate_text) {
    $settings = seom_get_settings();
    if (empty($settings['glossary_links_enabled'])) return [];
    $max = max(1, min(15, intval($settings['glossary_max_links_per_post'] ?? 5)));
    return seom_find_glossary_terms_in_text((string) $candidate_text, $max);
}

// Admin endpoint — clears the cached index and returns the new size.
add_action('wp_ajax_seom_glossary_rebuild', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    seom_glossary_invalidate_index();
    $index = seom_get_glossary_index(true);
    wp_send_json_success(['count' => count($index)]);
});

/**
 * One-shot migration that rewrites existing post HTML so historical
 * glossary links (bare-anchor URLs from the original phase 3a release,
 * or any link pointing at the wrong letter/pagenum) get re-resolved to
 * the current paginated URL for each term.
 *
 * Called in batches by the admin button below. Returns a status payload
 * the JS uses to drive a progress indicator.
 *
 *   $batch_size — posts processed per AJAX call (default 50, max 100)
 *   $offset     — paging offset into the candidate post list
 *   $dry_run    — when true, counts what would change without writing
 */
function seom_glossary_migrate_post_links($batch_size = 50, $offset = 0, $dry_run = false) {
    global $wpdb;
    $batch_size = max(1, min(100, (int) $batch_size));
    $offset     = max(0, (int) $offset);

    // Build slug → new URL map from the current (paginated) index.
    $index = seom_get_glossary_index();
    if (empty($index)) {
        return [
            'scanned'       => 0,
            'posts_updated' => 0,
            'links_updated' => 0,
            'has_more'      => false,
            'next_offset'   => $offset,
            'dry_run'       => (bool) $dry_run,
            'note'          => 'Glossary index is empty.',
        ];
    }
    $by_slug = [];
    foreach ($index as $entry) {
        $by_slug[$entry['slug']] = $entry['url'];
    }

    // Pull only posts that contain a glossary-anchor link. The LIKE narrows
    // the candidate set so we don't scan every post on the site.
    $needle = '%' . $wpdb->esc_like('/it-glossary/') . '%#term-%';
    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_content FROM {$wpdb->posts}
         WHERE post_status IN ('publish', 'private')
           AND post_type IN ('post', 'page')
           AND post_content LIKE %s
         ORDER BY ID ASC
         LIMIT %d OFFSET %d",
        $needle, $batch_size, $offset
    ));

    $posts_updated = 0;
    $links_updated = 0;
    foreach ($posts as $post) {
        $original = $post->post_content;
        $per_post_count = 0;
        $new_content = preg_replace_callback(
            // Match any href that points at /it-glossary/ with a #term-slug
            // anchor — captures the slug separately so we can swap the whole
            // attribute value with the new canonical URL for that slug.
            '/href="([^"]*\/it-glossary\/[^"#]*#term-([a-z0-9\-]+))"/i',
            function ($m) use ($by_slug, &$per_post_count) {
                $slug = strtolower($m[2]);
                if (!isset($by_slug[$slug])) return $m[0]; // unknown slug — leave alone
                $new_full = 'href="' . $by_slug[$slug] . '"';
                $old_full = 'href="' . $m[1] . '"';
                if ($old_full === $new_full) return $m[0]; // already correct
                $per_post_count++;
                return $new_full;
            },
            $original
        );
        if ($per_post_count === 0) continue;

        $links_updated += $per_post_count;
        if (!$dry_run) {
            $wpdb->update(
                $wpdb->posts,
                ['post_content' => $new_content],
                ['ID' => $post->ID]
            );
            clean_post_cache($post->ID);
        }
        $posts_updated++;
    }

    return [
        'scanned'       => count($posts),
        'posts_updated' => $posts_updated,
        'links_updated' => $links_updated,
        'has_more'      => count($posts) === $batch_size,
        'next_offset'   => $offset + count($posts),
        'dry_run'       => (bool) $dry_run,
    ];
}

add_action('wp_ajax_seom_glossary_migrate_links', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(120);
    $offset  = max(0, intval($_POST['offset'] ?? 0));
    $dry     = !empty($_POST['dry_run']);
    wp_send_json_success(seom_glossary_migrate_post_links(50, $offset, $dry));
});
