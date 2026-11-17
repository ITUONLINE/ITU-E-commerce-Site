<?php
/**
 * Glossary Term Page — per-term permalinks at /it-glossary/{slug}/
 *
 * Renders a long-form definition page for each glossary term that has
 * had AI-expanded content generated. The page structure intentionally
 * mirrors the blog post template (single.html) so it feels native to
 * the site: same header / breadcrumbs / hero / plans bar / footer
 * shell, just with glossary-specific body content.
 *
 * Flow:
 *   1. Rewrite rule on init: /it-glossary/{slug}/ → query var
 *      seom_glossary_term_slug.
 *   2. template_include filter swaps in our PHP template when the
 *      query var is set.
 *   3. Template loads the row, renders the page, exits.
 *
 * Auto-flush: on every admin_init we check if our rule is present in
 * the rewrite_rules option; if not, we flush. Costs one flush per
 * deploy. No activation hook required.
 *
 * URL gating: the page 404s when:
 *   - the slug doesn't resolve to a glossary row, OR
 *   - the row has no `definition_expanded` (we don't show stub pages
 *     for terms that only have the one-sentence form).
 *
 * Related Blogs: pulled from wp_seom_glossary_crosswalk. Top 5 by
 * relevance_score; if fewer than 2 results exist the section is
 * suppressed entirely (matches the user's "no section if no blogs"
 * requirement). When more than 5 candidates exist we take top-3
 * deterministically and round out with 2 random picks from the tail
 * so frequent visitors get freshness without losing the most relevant
 * matches.
 */

if (!defined('ABSPATH')) exit;

const SEOM_GLOSSARY_QUERY_VAR = 'seom_glossary_term_slug';

/**
 * Register the rewrite rule + query var. Inserted at 'top' so it
 * matches before any catch-all page rule.
 */
add_action('init', function () {
    add_rewrite_rule(
        '^it-glossary/([a-z0-9\-]+)/?$',
        'index.php?' . SEOM_GLOSSARY_QUERY_VAR . '=$matches[1]',
        'top'
    );
});

add_filter('query_vars', function ($vars) {
    $vars[] = SEOM_GLOSSARY_QUERY_VAR;
    return $vars;
});

/**
 * Strip RankMath's (and any other plugin's) FAQPage entries from the
 * json_ld output on glossary term pages — ours is the canonical
 * FAQPage and Google flags duplicates as a critical error. Registered
 * globally so it's in place before any wp_head firing order question.
 * The filter is a no-op except on glossary term pages.
 */
add_filter('rank_math/json_ld', function ($data) {
    global $wp_query;
    if (empty($wp_query) || empty($wp_query->seom_glossary_row)) return $data;
    if (!is_array($data)) return $data;
    foreach ($data as $key => $schema) {
        if (is_array($schema) && isset($schema['@type'])) {
            $type = $schema['@type'];
            if ($type === 'FAQPage' || (is_array($type) && in_array('FAQPage', $type, true))) {
                unset($data[$key]);
            }
        }
    }
    return $data;
}, 999, 1);

/**
 * Auto-flush rewrites once if our rule isn't installed yet. Runs on
 * any admin page load; once flushed the option stays correct until
 * the rule is removed. Cheap option read, no flush on hot path.
 */
add_action('admin_init', function () {
    $rules = get_option('rewrite_rules');
    if (!is_array($rules)) return;
    foreach (array_keys($rules) as $pattern) {
        if (strpos($pattern, '^it-glossary/(') === 0) return;
    }
    flush_rewrite_rules(false);
});

/**
 * Look up the glossary row matching the slug from the request. If the
 * row exists but has no expanded content, return null so the handler
 * 404s (we don't want stub pages indexed for unexpanded terms).
 *
 * Slug matching prefers the stored `slug` column (lowercase, kebab),
 * with a fall-back to sanitize_title(term) for rows whose slug was
 * never backfilled. The fall-back uses a small in-memory scan keyed
 * on the first letter of the requested slug to keep the cost bounded.
 */
function seom_glossary_resolve_slug($slug) {
    global $wpdb;
    $slug = strtolower(trim((string) $slug));
    if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) return null;

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM ec_it_glossary
         WHERE slug = %s
           AND definition_expanded IS NOT NULL
           AND definition_expanded != ''
         LIMIT 1",
        $slug
    ));
    if ($row) return $row;

    // Fall-back: brute-force compute slugs for unbackfilled rows. This
    // only fires when slug-column lookups miss, which after the
    // gap-finder's backfill should be never.
    $first = substr($slug, 0, 1);
    if (!ctype_alnum($first)) return null;
    $candidates = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM ec_it_glossary
         WHERE (slug IS NULL OR slug = '')
           AND LOWER(SUBSTRING(term, 1, 1)) = %s
           AND definition_expanded IS NOT NULL
           AND definition_expanded != ''",
        $first
    ));
    foreach ($candidates as $c) {
        if (sanitize_title($c->term) === $slug) return $c;
    }
    return null;
}

/**
 * Build the related-blogs list for a term. Returns up to $limit posts
 * sorted by relevance, with a deterministic head + random tail so the
 * page feels fresh on repeat visits without losing the strongest
 * matches.
 *
 * Returns an array of WP_Post objects (or stdClass with ID, post_title,
 * post_excerpt, permalink fields) — empty array when there's no
 * crosswalk data for the slug.
 */
function seom_glossary_get_related_posts($slug, $limit = 5) {
    global $wpdb;
    $cw_table = $wpdb->prefix . 'seom_glossary_crosswalk';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT cw.post_id, cw.relevance_score, cw.match_type
         FROM {$cw_table} cw
         INNER JOIN {$wpdb->posts} p ON p.ID = cw.post_id
         WHERE cw.term_slug = %s
           AND p.post_status = 'publish'
         ORDER BY cw.relevance_score DESC
         LIMIT 30",
        $slug
    ));
    if (!$rows) return [];

    // Deterministic head + random tail: top 3 by score, then up to 2
    // random picks from positions 4-30. Falls through gracefully when
    // fewer than 3 candidates exist.
    $head = array_slice($rows, 0, min(3, count($rows)));
    $tail_pool = array_slice($rows, 3);
    $tail = [];
    if ($tail_pool && $limit > count($head)) {
        shuffle($tail_pool);
        $tail = array_slice($tail_pool, 0, $limit - count($head));
    }
    $picked = array_merge($head, $tail);

    $post_ids = array_map(function ($r) { return (int) $r->post_id; }, $picked);
    if (!$post_ids) return [];

    // Pull post data in one query, preserve the order from $picked.
    $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_excerpt, post_content, post_date
         FROM {$wpdb->posts}
         WHERE ID IN ({$placeholders}) AND post_status = 'publish'",
        ...$post_ids
    ));
    $by_id = [];
    foreach ($posts as $p) $by_id[(int) $p->ID] = $p;

    $ordered = [];
    foreach ($post_ids as $pid) {
        if (!isset($by_id[$pid])) continue;
        $post = $by_id[$pid];
        // Derive a short excerpt if post_excerpt is empty (most ITU
        // blogs don't set it explicitly).
        if (empty($post->post_excerpt)) {
            $stripped = wp_strip_all_tags($post->post_content);
            $post->post_excerpt = wp_trim_words($stripped, 28, '…');
        }
        $post->permalink = get_permalink($pid);
        $ordered[] = $post;
    }
    return $ordered;
}

/**
 * template_include hook — when the query var is set, swap in our
 * template and stop default WordPress page resolution. Sends 404 if
 * the slug doesn't resolve to a valid (expanded) term.
 */
add_filter('template_include', function ($template) {
    $slug = get_query_var(SEOM_GLOSSARY_QUERY_VAR);
    if (empty($slug)) return $template;

    $row = seom_glossary_resolve_slug($slug);
    if (!$row) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        // Fall through to the theme's 404 template.
        return $template;
    }

    // Stash the row on the WP_Query object so the template can pick it
    // up without re-querying. We set is_singular() so theme code that
    // assumes a singular context (breadcrumb shortcode, etc.) plays
    // nicely.
    global $wp_query, $post;
    $wp_query->seom_glossary_row  = $row;
    $wp_query->is_singular        = true;
    $wp_query->is_404             = false;
    $wp_query->queried_object     = null;
    $wp_query->queried_object_id  = 0;
    // Null out $post so theme functions like inject_faq_jsonld_script
    // (which reads ACF from $post->ID and would otherwise leak FAQ
    // schema from an unrelated post into our page) bail out cleanly.
    $post = null;

    // Remove the theme's ACF-based FAQ injector. It was authored for
    // blog posts where FAQ data lives in an ACF field; on our virtual
    // glossary pages it has nothing to read, but if $post somehow
    // leaks through it would emit a duplicate FAQPage with wrong
    // content. Belt-and-suspenders.
    remove_action('wp_head', 'inject_faq_jsonld_script');

    // Flag so authority.php (and any other module) knows we're rendering
    // a virtual glossary term page and must not inject blog-post-shaped
    // schema (Article+Course+HowTo etc.) for what isn't a real post.
    // The flag is read by an early-bail check in authority.php.
    $wp_query->seom_is_glossary_term_page = true;

    status_header(200);

    return __DIR__ . '/glossary-term-page-template.php';
});

/**
 * Resolve the best title / description for the page. Returns:
 *   ['title' => string, 'description' => string]
 *
 * Uses AI-generated SEO fields when present, falls back to deterministic
 * defaults otherwise. Centralized so head meta and OG / Twitter cards
 * all agree.
 */
function seom_glossary_term_page_meta($row) {
    $term = (string) $row->term;
    $title = !empty($row->seo_title)
        ? (string) $row->seo_title
        : $term . ' Explained: Definition & Use Cases | ' . get_bloginfo('name');

    $description = '';
    if (!empty($row->seo_description)) {
        $description = (string) $row->seo_description;
    } else {
        $description = wp_strip_all_tags((string) $row->definition);
        $description = trim(preg_replace('/\s+/', ' ', $description));
        if (mb_strlen($description) > 160) $description = mb_substr($description, 0, 157) . '…';
    }
    return ['title' => $title, 'description' => $description];
}

/**
 * Document title — overrides whatever the default WP_Query path would
 * produce. Uses the AI-generated seo_title when present.
 */
add_filter('pre_get_document_title', function ($title) {
    global $wp_query;
    if (empty($wp_query->seom_glossary_row)) return $title;
    $meta = seom_glossary_term_page_meta($wp_query->seom_glossary_row);
    return $meta['title'];
}, 20);

/**
 * RankMath also computes its own title via the wp_title filter — make
 * sure it sees our title too (otherwise the OG / Twitter tags it emits
 * via its own pipeline would carry the wrong value).
 */
add_filter('wp_title', function ($title) {
    global $wp_query;
    if (empty($wp_query->seom_glossary_row)) return $title;
    $meta = seom_glossary_term_page_meta($wp_query->seom_glossary_row);
    return $meta['title'];
}, 20);

/**
 * Canonical + meta description in <head>. Emitted early (priority 1)
 * so it lands before any plugin trying to inject its own.
 */
add_action('wp_head', function () {
    global $wp_query;
    if (empty($wp_query->seom_glossary_row)) return;
    $row = $wp_query->seom_glossary_row;
    $slug = !empty($row->slug) ? $row->slug : sanitize_title($row->term);
    $url  = home_url('/it-glossary/' . $slug . '/');
    $meta = seom_glossary_term_page_meta($row);

    echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
    if ($meta['description'] !== '') {
        echo '<meta name="description" content="' . esc_attr($meta['description']) . '">' . "\n";
    }
}, 1);

/**
 * Open Graph + Twitter Card tags. Drives social-share previews on
 * LinkedIn / Twitter / Slack / etc. The og:image is a site-wide
 * default (the glossary section logo) until we have per-term images.
 */
add_action('wp_head', function () {
    global $wp_query;
    if (empty($wp_query->seom_glossary_row)) return;
    $row = $wp_query->seom_glossary_row;
    $slug = !empty($row->slug) ? $row->slug : sanitize_title($row->term);
    $url  = home_url('/it-glossary/' . $slug . '/');
    $meta = seom_glossary_term_page_meta($row);

    // Default share image — uses the site icon if no global OG image is
    // configured. Replace with a custom value via filter if needed.
    $default_image = apply_filters(
        'seom_glossary_default_og_image',
        get_site_icon_url(512) ?: home_url('/wp-content/uploads/2024/01/itu-online-logo.png')
    );

    $og = [
        'og:type'        => 'article',
        'og:title'       => $meta['title'],
        'og:description' => $meta['description'],
        'og:url'         => $url,
        'og:site_name'   => get_bloginfo('name'),
        'og:image'       => $default_image,
        'article:section'         => 'IT Glossary',
        'article:modified_time'   => !empty($row->definition_expanded_at)
                                     ? mysql2date('c', $row->definition_expanded_at, false)
                                     : '',
        'twitter:card'        => 'summary_large_image',
        'twitter:title'       => $meta['title'],
        'twitter:description' => $meta['description'],
        'twitter:image'       => $default_image,
    ];
    foreach ($og as $property => $content) {
        if ($content === '') continue;
        $attr = strpos($property, 'twitter:') === 0 ? 'name' : 'property';
        echo '<meta ' . $attr . '="' . esc_attr($property) . '" content="' . esc_attr($content) . '">' . "\n";
    }
}, 1);

/**
 * JSON-LD schema — DefinedTerm + Article + BreadcrumbList + (optional)
 * FAQPage. Article alongside DefinedTerm because Google indexes Article
 * for ranking signals (datePublished, dateModified, author, publisher);
 * DefinedTerm serves the glossary-specific semantic context.
 */
add_action('wp_head', function () {
    global $wp_query;
    if (empty($wp_query->seom_glossary_row)) return;
    $row = $wp_query->seom_glossary_row;
    $slug = !empty($row->slug) ? $row->slug : sanitize_title($row->term);
    $term_url = home_url('/it-glossary/' . $slug . '/');
    $set_url  = home_url('/it-glossary/');
    $meta = seom_glossary_term_page_meta($row);

    // DefinedTerm
    $defined = [
        '@context'         => 'https://schema.org',
        '@type'            => 'DefinedTerm',
        '@id'              => $term_url . '#defined-term',
        'name'             => (string) $row->term,
        'url'              => $term_url,
        'description'      => wp_strip_all_tags((string) $row->definition),
        'inDefinedTermSet' => [
            '@type' => 'DefinedTermSet',
            '@id'   => $set_url . '#defined-term-set',
            'name'  => 'IT Glossary',
            'url'   => $set_url,
        ],
    ];
    if (!empty($row->primary_area)) {
        $defined['termCode'] = wp_strip_all_tags((string) $row->primary_area);
    }
    echo '<script type="application/ld+json">' . wp_json_encode($defined, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";

    // Article — gives Google the ranking signals it expects.
    // mainEntityOfPage links the Article to the same page URL.
    $modified = !empty($row->definition_expanded_at)
        ? mysql2date('c', $row->definition_expanded_at, false)
        : '';
    $article = [
        '@context'         => 'https://schema.org',
        '@type'            => 'Article',
        '@id'              => $term_url . '#article',
        'headline'         => (string) $row->term,
        'description'      => $meta['description'],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $term_url],
        'url'              => $term_url,
        'author' => [
            '@type' => 'Organization',
            'name'  => get_bloginfo('name'),
            'url'   => home_url('/'),
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name'  => get_bloginfo('name'),
            'url'   => home_url('/'),
        ],
        'isPartOf' => [
            '@type' => 'CreativeWork',
            '@id'   => $set_url . '#defined-term-set',
            'name'  => 'IT Glossary',
            'url'   => $set_url,
        ],
        'about' => ['@id' => $term_url . '#defined-term'],
    ];
    if ($modified !== '') {
        $article['datePublished'] = $modified;
        $article['dateModified']  = $modified;
    }
    // Speakable scope — answer-engine hint pointing at the TL;DR block
    // if we have one.
    if (!empty($row->tldr)) {
        $article['speakable'] = [
            '@type'    => 'SpeakableSpecification',
            'cssSelector' => ['.itu-glossary-tldr'],
        ];
    }
    echo '<script type="application/ld+json">' . wp_json_encode($article, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";

    // BreadcrumbList
    $crumbs = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',        'item' => home_url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'IT Glossary', 'item' => $set_url],
            ['@type' => 'ListItem', 'position' => 3, 'name' => (string) $row->term, 'item' => $term_url],
        ],
    ];
    echo '<script type="application/ld+json">' . wp_json_encode($crumbs, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";

    // FAQPage — only when we have parsed FAQs from the SEO meta column.
    // We also strip RankMath's auto-detected FAQPage below to avoid a
    // duplicate (Google Rich Results flags two FAQPage entries on a page
    // as a critical error).
    if (!empty($row->faq_json)) {
        $faqs = json_decode((string) $row->faq_json, true);
        if (is_array($faqs) && !empty($faqs)) {
            $main_entity = [];
            foreach ($faqs as $faq) {
                if (!is_array($faq)) continue;
                $q = trim((string) ($faq['question'] ?? ''));
                $a = trim((string) ($faq['answer']   ?? ''));
                if ($q === '' || $a === '') continue;
                $main_entity[] = [
                    '@type'           => 'Question',
                    'name'            => $q,
                    'acceptedAnswer'  => ['@type' => 'Answer', 'text' => $a],
                ];
            }
            if (!empty($main_entity)) {
                $faq_schema = [
                    '@context'    => 'https://schema.org',
                    '@type'       => 'FAQPage',
                    '@id'         => $term_url . '#faqs',
                    'mainEntity'  => $main_entity,
                ];
                echo '<script type="application/ld+json">' . wp_json_encode($faq_schema, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
            }
        }
    }
}, 2);
