<?php
/**
 * Authority / AI-visibility layer for SEO AI AutoPilot — phase 1a.
 *
 * Three responsibilities:
 *
 *  1. Resolving the sub-team byline for a post (via gap_category, fall back
 *     to the configured editorial team).
 *  2. Emitting an additive JSON-LD @graph on single-post views containing:
 *       - Organization (with `knowsAbout`, `foundingDate`, `sameAs`) — uses a
 *         distinct @id so it never collides with RankMath's publisher block.
 *       - WebPage with `speakable` selectors pointing at .itu-tldr and
 *         .itu-at-a-glance (the citation-friendly blocks the writer prompt
 *         now requires).
 *       - HowTo when the post body contains a procedural <ol> with 5+ steps.
 *       - Course referenced via Article `about` when a featured product is
 *         linked. The Course entity's `url` points to the actual product page
 *         so the blog never claims to *be* the course.
 *  3. robots.txt explicit allow-list for AI training and AI-search crawlers,
 *     plus a programmatic /llms.txt endpoint pointing AI engines at the
 *     canonical hubs (homepage, glossary, course catalog).
 *
 * Designed to layer on top of RankMath without duplicating its Article /
 * BreadcrumbList / publisher emissions.
 */

if (!defined('ABSPATH')) exit;

// ─── Sub-team byline ────────────────────────────────────────────────────────

/**
 * Resolve a sub-team byline name for a given post.
 *
 * Lookup order:
 *  1. Post meta `_seom_byline_team` (manual override).
 *  2. Match the post's gap_category (Blog Queue tag) against the configured
 *     byline map. Match is case-insensitive substring, so a single team tag
 *     like "CompTIA" matches gap_categories like "CompTIA Security+",
 *     "CompTIA A+", etc.
 *  3. Fall back to the editorial team configured in settings.
 */
function seom_resolve_byline_team($post_id) {
    $override = get_post_meta($post_id, '_seom_byline_team', true);
    if (is_string($override) && $override !== '') return $override;

    $settings = seom_get_settings();
    $map_raw = $settings['authority_byline_map'] ?? '';
    $map = is_array($map_raw) ? $map_raw : json_decode((string) $map_raw, true);
    if (!is_array($map)) $map = [];

    // The Blog Queue plugin stores the topic tag on its own queue row but
    // doesn't write it to the post directly. The post's `gap_category` (or
    // the matched gap-table row) is the best signal. Use post's first
    // category name as a soft fallback.
    $signal = seom_get_post_topic_signal($post_id);
    if ($signal !== '') {
        foreach ($map as $team => $needles) {
            if (!is_array($needles)) continue;
            foreach ($needles as $needle) {
                $needle = (string) $needle;
                if ($needle === '') continue;
                if (stripos($signal, $needle) !== false) return $team;
            }
        }
    }

    // Fallback: pick the team whose needle list is empty (the editorial
    // catch-all), or the first team if none is marked.
    foreach ($map as $team => $needles) {
        if (empty($needles)) return $team;
    }
    return $settings['authority_org_name'] . ' Editorial Team';
}

/**
 * Try to derive a topic signal for byline + schema lookups. Looks at:
 *   - bq_history.gap_category for this post_id (Blog Queue path)
 *   - post tags / categories as a fallback
 */
function seom_get_post_topic_signal($post_id) {
    global $wpdb;
    $cache_key = 'seom_topic_signal_' . (int) $post_id;
    $cached = wp_cache_get($cache_key, 'seom');
    if (is_string($cached)) return $cached;

    $signal = '';
    $bq_table = $wpdb->prefix . 'bq_queue';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$bq_table}'") === $bq_table) {
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT gap_category FROM {$bq_table} WHERE post_id = %d AND gap_category IS NOT NULL AND gap_category != '' ORDER BY processed_at DESC LIMIT 1",
            $post_id
        ));
        if (is_string($row) && $row !== '') $signal = $row;
    }
    if ($signal === '') {
        $tags = get_the_tags($post_id);
        if (!empty($tags) && !is_wp_error($tags)) {
            $signal = $tags[0]->name;
        }
    }
    if ($signal === '') {
        $cats = get_the_category($post_id);
        if (!empty($cats)) $signal = $cats[0]->name;
    }
    wp_cache_set($cache_key, $signal, 'seom', 10 * MINUTE_IN_SECONDS);
    return $signal;
}

// ─── Visible byline injection ──────────────────────────────────────────────

/**
 * Inject the sub-team byline + publisher line above post content on single
 * post views. The byline is also picked up by the schema layer below so the
 * human-visible attribution and the JSON-LD author match.
 */
add_filter('the_content', function ($content) {
    if (!is_singular('post')) return $content;
    if (is_admin()) return $content;
    if (!in_the_loop() || !is_main_query()) return $content;

    $post_id = get_the_ID();
    if (!$post_id) return $content;

    $settings = seom_get_settings();
    $team    = esc_html(seom_resolve_byline_team($post_id));
    $org     = esc_html($settings['authority_org_name'] ?? 'ITU Online');
    $blurb   = esc_html($settings['authority_about_blurb'] ?? '');
    $publish = get_the_date('F j, Y', $post_id);
    $updated = get_the_modified_date('F j, Y', $post_id);
    $stale   = $publish !== $updated;

    $html  = '<div class="itu-byline" style="margin:0 0 18px;padding:10px 14px;border-left:3px solid #2563eb;background:#f8fafc;font-size:13px;color:#1e293b;">';
    $html .= '<div><strong>By ' . $team . '</strong></div>';
    if ($blurb !== '') {
        $html .= '<div style="font-size:12px;color:#475569;margin-top:2px;">' . $blurb . '</div>';
    }
    $html .= '<div style="font-size:11px;color:#64748b;margin-top:4px;">Published ' . esc_html($publish);
    if ($stale) {
        $html .= ' · <span style="color:#0891b2;">Last updated ' . esc_html($updated) . '</span>';
    }
    $html .= '</div></div>';

    return $html . $content;
}, 8); // priority 8 = before most theme/RankMath filters

// ─── Cluster member lookup (phase 2) ───────────────────────────────────────

/**
 * Return summary rows (title + url) for every completed spoke post in a
 * cluster — i.e., every bq_queue row with gap_category = $tag that produced
 * a published post, excluding the pillar itself.
 *
 * Used by the CollectionPage schema emitter on pillar posts. Returns [] when
 * the blog-queue plugin isn't active or the cluster has no spokes yet.
 */
function seom_get_cluster_member_summaries($pillar_post_id, $tag, $limit = 50) {
    global $wpdb;
    $tag = trim((string) $tag);
    if ($tag === '') return [];
    $queue = $wpdb->prefix . 'bq_queue';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$queue}'") !== $queue) return [];

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$queue}
         WHERE gap_category = %s
           AND post_id IS NOT NULL AND post_id != %d
           AND status = 'completed'
         LIMIT %d",
        $tag, (int) $pillar_post_id, (int) $limit
    ));
    $out = [];
    foreach ($rows as $r) {
        $pid = (int) $r->post_id;
        $post = get_post($pid);
        if (!$post || $post->post_status !== 'publish') continue;
        $out[] = [
            'title' => $post->post_title,
            'url'   => get_permalink($pid),
        ];
    }
    return $out;
}

// ─── HowTo detection ────────────────────────────────────────────────────────

/**
 * Scan post content for a procedural <ol>. Returns an array of step strings
 * or an empty array.
 *
 * Threshold logic:
 *   - $min_steps = 3 — used when the post is explicitly classified as howto
 *     (via _seom_content_type meta). The Quick Procedure callout the writer
 *     prompt now emits is exactly this shape (3-7 steps).
 *   - $min_steps = 5 — the default for unclassified / legacy posts, where we
 *     stay conservative to avoid mistaking a generic numbered list for a
 *     procedure.
 *
 * When multiple <ol> blocks qualify, the one with the most steps wins.
 */
function seom_detect_howto_steps($content, $min_steps = 5) {
    if (!is_string($content) || $content === '') return [];
    if (stripos($content, '<ol') === false) return [];
    if (!preg_match_all('/<ol\b[^>]*>(.*?)<\/ol>/is', $content, $blocks)) return [];

    $best_steps = [];
    foreach ($blocks[1] as $inner) {
        if (!preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $inner, $items)) continue;
        if (count($items[1]) < $min_steps) continue;

        $steps = [];
        foreach ($items[1] as $li_html) {
            $text = trim(wp_strip_all_tags($li_html));
            $text = preg_replace('/\s+/', ' ', $text);
            if ($text === '') continue;
            if (mb_strlen($text) > 600) $text = mb_substr($text, 0, 597) . '...';
            $steps[] = $text;
        }
        if (count($steps) >= $min_steps && count($steps) > count($best_steps)) {
            $best_steps = $steps;
        }
    }
    return $best_steps;
}

// ─── Schema graph emitter ──────────────────────────────────────────────────

/**
 * Build the additive JSON-LD @graph for a single post. Returns null when the
 * post isn't a candidate (e.g., excluded post types).
 *
 * The @id values are deliberately distinct from RankMath's defaults so the
 * two layers coexist additively:
 *   - Our publisher Organization uses `…/#org-authority` (RankMath uses
 *     `…/#organization`). Both describe the same business but ours carries
 *     `knowsAbout` + `foundingDate` + extended `sameAs` data RankMath omits.
 *   - Our WebPage entity uses `…/#webpage-authority` and only contributes
 *     `speakable`, `mentions`, and `about` — properties RankMath doesn't set.
 */
function seom_build_authority_schema_graph($post_id) {
    if (!$post_id) return null;
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return null;
    if (!in_array($post->post_type, ['post'], true)) return null;

    $settings = seom_get_settings();
    $site_url = home_url('/');
    $perma    = get_permalink($post_id);
    if (!$perma) return null;

    $graph = [];

    // ── Organization (publisher, with knowsAbout / sameAs / foundingDate) ──
    $sameas = array_values(array_filter(array_map('trim', explode("\n", $settings['authority_same_as'] ?? ''))));
    $knows  = array_values(array_filter(array_map('trim', explode("\n", $settings['authority_knows_about'] ?? ''))));
    $org_id = $site_url . '#org-authority';
    $org = [
        '@type' => 'Organization',
        '@id'   => $org_id,
        'name'  => $settings['authority_org_name'] ?? 'ITU Online',
        'url'   => $settings['authority_org_url'] ?? $site_url,
    ];
    if (!empty($settings['authority_founding_date'])) {
        $org['foundingDate'] = $settings['authority_founding_date'];
    }
    if (!empty($settings['authority_about_blurb'])) {
        $org['description'] = $settings['authority_about_blurb'];
    }
    if (!empty($sameas)) $org['sameAs'] = $sameas;
    if (!empty($knows))  $org['knowsAbout'] = $knows;
    $graph[] = $org;

    // ── Sub-team byline as a sub-organization, referenced as the post's author ──
    $team = seom_resolve_byline_team($post_id);
    $team_id = $site_url . '#team-' . sanitize_title($team);
    $graph[] = [
        '@type' => 'Organization',
        '@id'   => $team_id,
        'name'  => $team,
        'parentOrganization' => ['@id' => $org_id],
    ];

    // ── HowTo (procedural posts) ──
    // When the post is explicitly classified as howto we lower the floor to 3
    // steps (matches the Quick Procedure callout the writer prompt emits).
    // Legacy / non-howto posts stay at the conservative 5-step floor.
    $known_type = get_post_meta($post_id, '_seom_content_type', true);
    $min_steps  = ($known_type === 'howto') ? 3 : 5;
    $steps = seom_detect_howto_steps($post->post_content, $min_steps);
    $howto_id = null;
    if (!empty($steps)) {
        $howto_id = $perma . '#howto';
        $step_items = [];
        $i = 1;
        foreach ($steps as $s) {
            $step_items[] = [
                '@type'    => 'HowToStep',
                'position' => $i++,
                'name'     => 'Step ' . ($i - 1),
                'text'     => $s,
            ];
        }
        $graph[] = [
            '@type' => 'HowTo',
            '@id'   => $howto_id,
            'name'  => get_the_title($post_id),
            'step'  => $step_items,
        ];
    }

    // ── Course (only when a featured product is attached) ──
    $course_id = null;
    $fp_meta_key = defined('SEOM_FEATURED_PRODUCT_META_KEY') ? SEOM_FEATURED_PRODUCT_META_KEY : '_seom_featured_product_id';
    $fp_id = (int) get_post_meta($post_id, $fp_meta_key, true);
    if ($fp_id && get_post_type($fp_id) === 'product') {
        $fp = get_post($fp_id);
        if ($fp && $fp->post_status === 'publish') {
            $fp_url = get_permalink($fp_id);
            $course_id = $fp_url . '#course';
            $course_desc = wp_strip_all_tags($fp->post_excerpt ?: wp_trim_words($fp->post_content, 40, '...'));
            $course = [
                '@type'    => 'Course',
                '@id'      => $course_id,
                'name'     => $fp->post_title,
                'url'      => $fp_url,
                'provider' => ['@id' => $org_id],
            ];
            if ($course_desc !== '') $course['description'] = trim($course_desc);
            $graph[] = $course;
        }
    }

    // ── WebPage with speakable + about + mentions ──
    // We don't emit a competing Article — RankMath owns that. We add a
    // separate WebPage entity that carries the speakable + relationships.
    //
    // NOTE: isPartOf intentionally omitted. Schema.org WebPage.isPartOf
    // expects a CreativeWork (typically a WebSite), not an Organization —
    // pointing it at the publisher Organization is a validation error. The
    // publisher relationship is already expressed via the `publisher` field
    // below, so we don't lose anything by dropping isPartOf.
    $webpage = [
        '@type'   => 'WebPage',
        '@id'     => $perma . '#webpage-authority',
        'url'     => $perma,
        'author'  => ['@id' => $team_id],
        'publisher' => ['@id' => $org_id],
        'datePublished' => get_the_date('c', $post_id),
        'dateModified'  => get_the_modified_date('c', $post_id),
    ];

    // Only declare speakable for selectors that ACTUALLY appear in the
    // rendered body — otherwise Schema.org validators (correctly) error
    // because the speakable region references markup that isn't on the page.
    // Legacy posts that haven't been refreshed under the new writer prompt
    // simply get no speakable block until they have one.
    // Speakable selectors are added only when the class actually appears in
    // the rendered body — otherwise the schema.org validator (correctly)
    // errors because the speakable region references markup that isn't there.
    // The set includes the base AI-extraction blocks plus the four type-
    // specific blocks the writer prompt now emits.
    $speakable_candidates = [
        '.itu-tldr',
        '.itu-at-a-glance',
        '.itu-quick-procedure',  // howto
        '.itu-comparison',       // comparison (the table itself)
        '.itu-definition',       // concept
        '.itu-career-outlook',   // career
    ];
    $speakable_selectors = [];
    $body = (string) $post->post_content;
    foreach ($speakable_candidates as $sel) {
        // Strip leading dot for substring match against class= attributes.
        $needle = ltrim($sel, '.');
        if ($needle !== '' && stripos($body, $needle) !== false) {
            $speakable_selectors[] = $sel;
        }
    }
    if (!empty($speakable_selectors)) {
        $webpage['speakable'] = [
            '@type'       => 'SpeakableSpecification',
            'cssSelector' => $speakable_selectors,
        ];
    }

    $mentions = [];
    if ($howto_id)  $mentions[] = ['@id' => $howto_id];
    if ($course_id) {
        $webpage['about'] = ['@id' => $course_id];
        $mentions[] = ['@id' => $course_id];
    }

    // ── Phase 2: CollectionPage for pillar posts ──
    // When this post is the pillar for a cluster, we add a CollectionPage
    // entity whose mainEntity is an ItemList of every spoke post. Tells AI
    // engines explicitly: "this page is a topical hub, and these are the
    // member pages." Linked from the WebPage via `hasPart` so the
    // relationship is bidirectional in the graph.
    $pillar_tag = get_post_meta($post_id, '_seom_pillar_for', true);
    if (is_string($pillar_tag) && $pillar_tag !== '') {
        $members = seom_get_cluster_member_summaries($post_id, $pillar_tag);
        if (!empty($members)) {
            $collection_id = $perma . '#cluster-hub';
            $item_list_elements = [];
            $i = 1;
            foreach ($members as $m) {
                $item_list_elements[] = [
                    '@type'    => 'ListItem',
                    'position' => $i++,
                    'url'      => $m['url'],
                    'name'     => $m['title'],
                ];
            }
            $graph[] = [
                '@type'      => 'CollectionPage',
                '@id'        => $collection_id,
                'name'       => $pillar_tag . ' — Topic Cluster',
                'description'=> 'Pillar post and member articles in the ' . $pillar_tag . ' topical cluster.',
                'isPartOf'   => ['@id' => $perma . '#webpage-authority'],
                'mainEntity' => [
                    '@type'           => 'ItemList',
                    'itemListElement' => $item_list_elements,
                    'numberOfItems'   => count($item_list_elements),
                ],
            ];
            $webpage['hasPart'] = ['@id' => $collection_id];
        }
    }

    if (!empty($mentions)) $webpage['mentions'] = $mentions;
    $graph[] = $webpage;

    return ['@context' => 'https://schema.org', '@graph' => $graph];
}

/**
 * Emit the schema graph in <head> after RankMath / other SEO plugins have
 * written theirs (priority 99). We deliberately don't replace anything —
 * we add an additive layer with non-colliding @id values.
 */
add_action('wp_head', function () {
    // Virtual glossary term pages set this flag — they emit their own
    // (different) schema graph from glossary-term-page.php and should
    // never receive the blog-post authority graph (Article + Course +
    // HowTo + WebPage). Bail before any further checks.
    global $wp_query;
    if (!empty($wp_query->seom_is_glossary_term_page)) return;

    if (!is_singular('post')) return;
    $post_id = get_queried_object_id();
    if (!$post_id) return;
    $graph = seom_build_authority_schema_graph($post_id);
    if (empty($graph)) return;

    echo "\n<script type=\"application/ld+json\" data-source=\"seom-authority\">"
        . wp_json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "</script>\n";
}, 99);

// ─── robots.txt: explicit allows for AI bots ───────────────────────────────

add_filter('robots_txt', function ($output, $public) {
    $settings = seom_get_settings();
    if (empty($settings['ai_bots_allow'])) return $output;
    if (!$public) return $output;

    // Explicit allow blocks for major AI training + AI-search crawlers.
    // Order matters less than the explicit Allow: directive — but we add a
    // visible comment so anyone reading robots.txt knows why these are here.
    $bots = [
        'GPTBot',            // OpenAI training
        'OAI-SearchBot',     // ChatGPT real-time search
        'ChatGPT-User',      // user-initiated ChatGPT fetches
        'ClaudeBot',         // Anthropic training
        'Claude-Web',        // Anthropic real-time
        'anthropic-ai',      // legacy Anthropic UA
        'PerplexityBot',     // Perplexity training + search
        'Perplexity-User',   // Perplexity user-initiated fetches
        'Google-Extended',   // Bard / Gemini training opt-in
        'Applebot-Extended', // Apple Intelligence training
        'CCBot',             // Common Crawl (feeds most LLM training)
        'Bytespider',        // ByteDance / Doubao
        'cohere-ai',         // Cohere training
        'YouBot',            // You.com search/answer engine
        'Meta-ExternalAgent',// Meta AI
        'FacebookBot',       // Meta legacy
        'DuckAssistBot',     // DuckDuckGo AI
    ];
    $lines = ["\n# ── AI crawlers (training + AI search) — managed by SEO AI AutoPilot ──"];
    foreach ($bots as $bot) {
        $lines[] = "User-agent: {$bot}";
        $lines[] = "Allow: /";
        $lines[] = '';
    }
    return $output . implode("\n", $lines);
}, 10, 2);

// ─── /llms.txt endpoint ────────────────────────────────────────────────────
//
// Emerging convention (llmstxt.org) — a markdown-flavored manifest at the
// site root pointing AI crawlers at canonical hubs. Served by intercepting
// the request before WordPress hits its 404 path.

add_action('init', function () {
    if (!isset($_SERVER['REQUEST_URI'])) return;
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/llms.txt') return;

    $settings = seom_get_settings();
    if (empty($settings['llms_txt_enabled'])) return;

    $site_name = wp_strip_all_tags(get_bloginfo('name'));
    $site_url  = trailingslashit(home_url('/'));
    $blurb     = $settings['authority_about_blurb'] ?? '';
    $extra     = trim((string) ($settings['llms_txt_extra_lines'] ?? ''));

    $lines = [];
    $lines[] = '# ' . $site_name;
    $lines[] = '';
    if ($blurb !== '') {
        $lines[] = '> ' . $blurb;
        $lines[] = '';
    }
    $lines[] = '## Canonical hubs';
    $lines[] = '';
    $lines[] = '- [Home](' . $site_url . ')';
    $lines[] = '- [IT Glossary (5,000+ definitions)](' . $site_url . 'it-glossary/)';

    // Blog index if it's a distinct page from home.
    $blog_id = (int) get_option('page_for_posts');
    if ($blog_id) {
        $lines[] = '- [Blog](' . get_permalink($blog_id) . ')';
    } elseif ((int) get_option('show_on_front') === 0 || get_option('show_on_front') === 'posts') {
        // posts-on-front; nothing extra
    }

    // WooCommerce shop / course catalog if present.
    if (function_exists('wc_get_page_id')) {
        $shop_id = wc_get_page_id('shop');
        if ($shop_id && $shop_id > 0) {
            $lines[] = '- [Course Catalog](' . get_permalink($shop_id) . ')';
        }
    }

    $lines[] = '';
    $lines[] = '## About the publisher';
    $lines[] = '';
    $lines[] = ($settings['authority_org_name'] ?? 'ITU Online') . ' — ' . ($blurb ?: 'IT training and certification content.');
    $founded = $settings['authority_founding_date'] ?? '';
    if ($founded !== '') {
        $year = substr($founded, 0, 4);
        $lines[] = 'Founded: ' . $year;
    }
    $sameas = array_values(array_filter(array_map('trim', explode("\n", $settings['authority_same_as'] ?? ''))));
    if (!empty($sameas)) {
        $lines[] = 'Profiles: ' . implode(', ', $sameas);
    }

    if ($extra !== '') {
        $lines[] = '';
        $lines[] = $extra;
    }

    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", $lines) . "\n";
    exit;
});
