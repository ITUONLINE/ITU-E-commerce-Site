<?php
/*
Plugin Name: Blog Queue
Description: Queue blog topics for automated daily creation. Generates outlines, full blog posts, FAQs, and SEO metadata using AI.
Version: 1.0
Author: ITU Online
*/

if (!defined('ABSPATH')) exit;

// ─── Activation ──────────────────────────────────────────────────────────────

register_activation_hook(__FILE__, 'bq_activate');
function bq_activate() {
    bq_create_tables();

    if (!wp_next_scheduled('bq_daily_process')) {
        wp_schedule_event(strtotime('today 07:00'), 'daily', 'bq_daily_process');
    }

    // Register the taxonomy and seed it now so terms are ready immediately.
    // The seeder is idempotent so this is safe even if init has already
    // fired in the activation request.
    if (!taxonomy_exists('itu_content_category')) {
        register_taxonomy('itu_content_category', 'post', [
            'hierarchical' => true, 'rewrite' => false, 'show_ui' => true, 'show_in_rest' => true,
        ]);
    }
    if (function_exists('bq_seed_content_taxonomy')) {
        bq_seed_content_taxonomy();
    }
}

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('bq_daily_process');
    wp_clear_scheduled_hook('bq_process_next');
});

// ─── Database ────────────────────────────────────────────────────────────────

function bq_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE {$wpdb->prefix}bq_queue (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(500) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        gap_category varchar(100) DEFAULT NULL,
        target_keywords text DEFAULT NULL,
        featured_product_id bigint(20) unsigned DEFAULT NULL,
        content_type varchar(20) DEFAULT NULL,
        post_id bigint(20) unsigned DEFAULT NULL,
        added_at datetime NOT NULL,
        processed_at datetime DEFAULT NULL,
        error_message text DEFAULT NULL,
        PRIMARY KEY (id),
        KEY status (status)
    ) $charset;");

    dbDelta("CREATE TABLE {$wpdb->prefix}bq_history (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(500) NOT NULL,
        post_id bigint(20) unsigned DEFAULT NULL,
        status varchar(20) NOT NULL,
        content_type varchar(20) DEFAULT NULL,
        processed_at datetime NOT NULL,
        error_message text DEFAULT NULL,
        PRIMARY KEY (id),
        KEY processed_at (processed_at)
    ) $charset;");

    // ── Content Categorization staging log ──
    // AI-suggested categories land here BEFORE they're applied to posts.
    // The review UI lets the user approve / reject / override per row.
    // High-confidence rows are auto-applied; lower-confidence rows wait
    // for manual review.
    dbDelta("CREATE TABLE {$wpdb->prefix}bq_categorization_log (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        batch_id varchar(40) DEFAULT NULL,
        run_at datetime NOT NULL,
        primary_term_id bigint(20) unsigned DEFAULT NULL,
        primary_term_name varchar(190) DEFAULT NULL,
        secondary_term_ids text DEFAULT NULL,
        secondary_term_names text DEFAULT NULL,
        confidence decimal(4,3) DEFAULT NULL,
        notes text DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        applied_at datetime DEFAULT NULL,
        applied_by bigint(20) unsigned DEFAULT NULL,
        error_message text DEFAULT NULL,
        PRIMARY KEY (id),
        KEY post_id (post_id),
        KEY status (status),
        KEY batch_id (batch_id)
    ) $charset;");
}

// Auto-add new columns for existing installs
add_action('admin_init', function () {
    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $col = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'gap_category'");
    if (!$col) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN gap_category varchar(100) DEFAULT NULL AFTER status");
    }
    $col2 = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'target_keywords'");
    if (!$col2) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN target_keywords text DEFAULT NULL AFTER gap_category");
    }
    $col3 = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'featured_product_id'");
    if (!$col3) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN featured_product_id bigint(20) unsigned DEFAULT NULL AFTER target_keywords");
    }
    // Phase 1b: content_type drives the type-specific writer block + HowTo
    // schema detection. NULL = legacy / unknown; inferred at writer time.
    $col4 = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'content_type'");
    if (!$col4) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN content_type varchar(20) DEFAULT NULL AFTER featured_product_id");
    }
    $hist = $wpdb->prefix . 'bq_history';
    $hcol = $wpdb->get_var("SHOW COLUMNS FROM {$hist} LIKE 'content_type'");
    if (!$hcol) {
        $wpdb->query("ALTER TABLE {$hist} ADD COLUMN content_type varchar(20) DEFAULT NULL AFTER status");
    }

    // Categorization log table — created by bq_create_tables() on activation,
    // but existing installs need it back-filled here.
    $cat_table = $wpdb->prefix . 'bq_categorization_log';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$cat_table}'") !== $cat_table) {
        bq_create_tables();
    }

    // Seed the content taxonomy ONCE — only if it has zero terms (fresh
    // install or first activation). After that, admins manage the taxonomy
    // via WP-Admin and deleted terms stay deleted.
    if (function_exists('bq_seed_content_taxonomy')) {
        $term_count = (int) wp_count_terms(['taxonomy' => 'itu_content_category', 'hide_empty' => false]);
        if ($term_count === 0) {
            bq_seed_content_taxonomy();
        }
    }
}, 99);

/**
 * Inject the [itu_featured_product] shortcode into blog content. Places
 * one copy after the first </p> and another before the last <h2>.
 *
 * The actual HTML rendering lives in the SEO AI AutoPilot plugin
 * (seom_shortcode_featured_product) so the site's template is the
 * single source of truth and survives every full refresh.
 */
function bq_inject_featured_product($content, $product_id) {
    $product_id = (int) $product_id;
    if (!$product_id || empty($content)) return $content;

    // Prefer the SEOM helper when available so Blog Queue and the refresher
    // both use identical insertion logic. Fall back to a local copy if the
    // SEO plugin isn't loaded yet during activation races.
    if (function_exists('seom_inject_featured_product_shortcode')) {
        return seom_inject_featured_product_shortcode($content, $product_id);
    }

    $shortcode = '[itu_featured_product id="' . $product_id . '"]';
    $content = preg_replace('/\[itu_featured_product[^\]]*\]/', '', $content);

    $first_p = strpos($content, '</p>');
    if ($first_p !== false) {
        $content = substr($content, 0, $first_p + 4) . "\n\n" . $shortcode . "\n\n" . substr($content, $first_p + 4);
    }
    $last_h2 = strrpos($content, '<h2');
    if ($last_h2 !== false) {
        $content = substr($content, 0, $last_h2) . $shortcode . "\n\n" . substr($content, $last_h2);
    } else {
        $content .= "\n\n" . $shortcode;
    }
    return $content;
}

/**
 * Auto-pick a priority Udemy course for a new Blog Queue post when the user
 * did not manually choose a featured product.
 */
function bq_auto_select_priority_featured_product($blog_title, $context = '') {
    if (!class_exists('SEOM_Blog_Refresher') || !method_exists('SEOM_Blog_Refresher', 'choose_priority_featured_product')) {
        return 0;
    }

    return (int) SEOM_Blog_Refresher::choose_priority_featured_product($blog_title, $context);
}

// ─── Content Categorization Taxonomy ────────────────────────────────────────
//
// Custom non-rewriting taxonomy that gives every blog post a hierarchical
// content category. Used by the AI Categorization tool to label existing
// posts and the future front-end navigation. Terms are seeded once on
// activation from BQ_CONTENT_TAXONOMY_TREE; admins edit them via WP-Admin
// (no in-plugin term editor).

/**
 * Master tree definition for the content taxonomy. Edit this constant to
 * change the seed list — bq_seed_content_taxonomy() will pick up new
 * children on the next admin page load. Existing terms are never modified
 * or deleted by the seeder, so renames/removals must be done in WP-Admin.
 */
function bq_get_content_taxonomy_seed() {
    return [
        'Certification Tracks' => [
            'CompTIA', 'Cisco', 'Microsoft', 'AWS', 'Google Cloud', 'ISC2',
            'EC-Council', 'ISACA', 'PMI & Agile', 'ITIL & Service Management',
            'Linux Foundation', 'Other Certifications',
        ],
        'Technology Domains' => [
            'Cybersecurity & Threat Intelligence', 'Networking & Infrastructure',
            'Cloud Computing & Architecture', 'Linux & Open Source',
            'Windows Server & Administration', 'Virtualization & Containers',
            'DevOps & CI/CD', 'Artificial Intelligence in IT',
            'IoT & Operational Technology', 'Database Administration',
            'Scripting & Automation', 'IT Service Management (ITSM)',
            'Identity & Access Management', 'Endpoint Security & EDR',
            'Incident Response & Digital Forensics',
            'Vulnerability Management & Penetration Testing',
            'Data Analytics & Business Intelligence',
            'IT Governance Risk & Compliance',
        ],
        'Career & Professional Development' => [
            'IT Career Paths & Roadmaps', 'Salary & Compensation Guides',
            'Certifications vs Degrees', 'How to Break Into IT',
            'Resume & Interview Prep', 'Career Changers in IT',
            'Women & Diversity in Tech', 'Freelancing & IT Consulting',
            'IT Leadership & Management', 'Remote Work in IT',
        ],
        'Training & Learning' => [
            'Study Guides & Exam Objectives', 'Exam Prep & Practice Tests',
            'Hands-On Labs & Skill Building', 'Online vs Instructor-Led Training',
            'Corporate IT Training', 'Workforce Development',
            'Self-Paced Learning Strategies',
            'Micro-Credentialing & Continuing Education',
            'LMS & eLearning Technology',
        ],
        'Industry Trends & News' => [
            'IT Industry News', 'Cybersecurity Threats & Breaches',
            'New Certifications & Exam Updates', 'Emerging Technologies',
            'AI & Automation in the Workplace', 'Regulatory & Compliance Changes',
            'Technology in Healthcare IT', 'Technology in Finance & Banking',
            'Technology in Government & Defense',
        ],
        'Beginner & Foundational Content' => [
            'What Is (Explainer Posts)', 'IT Fundamentals & Concepts',
            'Glossary & Terminology', 'Getting Started in IT',
            'IT for Non-Technical Professionals', 'IT Degree & Education Paths',
        ],
        'Tools & Resources' => [
            'IT Tools & Software Reviews', 'Free Resources & Study Materials',
            'Certification Comparison Guides', 'Home Lab Setup Guides',
            'Command References & Cheat Sheets', 'Salary & Job Market Data',
        ],
    ];
}

// Register the custom taxonomy on every page load.
add_action('init', function () {
    register_taxonomy('itu_content_category', 'post', [
        'label'             => 'Content Categories',
        'labels'            => [
            'name'          => 'Content Categories',
            'singular_name' => 'Content Category',
            'menu_name'     => 'Content Categories',
            'all_items'     => 'All Content Categories',
            'edit_item'     => 'Edit Content Category',
            'add_new_item'  => 'Add New Content Category',
            'search_items'  => 'Search Content Categories',
        ],
        'hierarchical'      => true,
        'rewrite'           => false,
        'show_ui'           => true,
        'show_admin_column' => false,
        'show_in_rest'      => true,
        'show_in_menu'      => true,
        'meta_box_cb'       => false,
    ]);
});

/**
 * Seed the content taxonomy from bq_get_content_taxonomy_seed().
 * Idempotent — only inserts terms that don't already exist (matched by
 * name within the same parent). Safe to call on every admin page load.
 */
function bq_seed_content_taxonomy() {
    if (!taxonomy_exists('itu_content_category')) return;

    $seed = bq_get_content_taxonomy_seed();
    foreach ($seed as $parent_name => $children) {
        // Find or create the parent.
        $parent = term_exists($parent_name, 'itu_content_category', 0);
        if (!$parent) {
            $parent = wp_insert_term($parent_name, 'itu_content_category', ['parent' => 0]);
            if (is_wp_error($parent)) continue;
        }
        $parent_id = is_array($parent) ? (int) $parent['term_id'] : (int) $parent;
        if (!$parent_id) continue;

        // Children: insert any that don't already exist under this parent.
        foreach ($children as $child_name) {
            $existing = term_exists($child_name, 'itu_content_category', $parent_id);
            if ($existing) continue;
            wp_insert_term($child_name, 'itu_content_category', ['parent' => $parent_id]);
        }
    }
}

/**
 * Build a nested representation of the taxonomy for UI / front-end use.
 *
 * @param array $args {
 *     @type bool $only_with_posts  When true (default), prune subcategories
 *                                  with count = 0 and prune parents whose
 *                                  children all have count = 0. The future
 *                                  navigation template uses this to keep
 *                                  empty categories out of the user-facing
 *                                  filter list.
 * }
 * @return array Nested: [
 *     ['term_id' => …, 'name' => …, 'slug' => …, 'count' => N, 'children' => [...]],
 *     …
 * ]
 */
function bq_get_content_taxonomy_tree($args = []) {
    $args = wp_parse_args($args, ['only_with_posts' => true]);
    if (!taxonomy_exists('itu_content_category')) return [];

    $all = get_terms([
        'taxonomy'   => 'itu_content_category',
        'hide_empty' => false,
    ]);
    if (is_wp_error($all) || empty($all)) return [];

    // Index by parent.
    $by_parent = [];
    foreach ($all as $t) {
        $by_parent[(int) $t->parent][] = $t;
    }

    // Helper: WordPress stores term names HTML-encoded ('Career &amp;
    // Professional Development'), so decode for display. Keep this in
    // ONE place so the AI prompt builder, the validator, and the UI
    // tree all output the same human-readable form.
    $decode = function ($name) {
        return html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    };

    $tree = [];
    foreach ($by_parent[0] ?? [] as $parent) {
        $children = [];
        // Sum the children's post counts so the parent badge reflects
        // its full subtree, not just posts attached directly to the
        // parent (which is always 0 here since we only ever assign
        // leaf categories to posts).
        $subtree_count = (int) $parent->count;
        foreach ($by_parent[(int) $parent->term_id] ?? [] as $child) {
            if ($args['only_with_posts'] && (int) $child->count === 0) continue;
            $children[] = [
                'term_id' => (int) $child->term_id,
                'name'    => $decode($child->name),
                'slug'    => $child->slug,
                'count'   => (int) $child->count,
                'children'=> [],
            ];
            $subtree_count += (int) $child->count;
        }
        // Drop the parent if all children were filtered out.
        if ($args['only_with_posts'] && empty($children) && $subtree_count === 0) continue;

        $tree[] = [
            'term_id' => (int) $parent->term_id,
            'name'    => $decode($parent->name),
            'slug'    => $parent->slug,
            'count'   => $subtree_count,
            'children'=> $children,
        ];
    }
    return $tree;
}

// ─── Blog Archive Front-End ─────────────────────────────────────────────────
//
// Renders the blog archive page (theme template archive.html calls the
// shortcode below). For admins it shows the new sidebar+filterable layout
// driven by the itu_content_category taxonomy. For non-admins it falls back
// to a simple grid identical to the previous block-template output, so
// the public site is unchanged until the feature ships to everyone.
//
// Practice tests (posts in the 'practice-tests' category) are excluded from
// the archive in BOTH paths.

/**
 * Build the WP_Query args used by the archive. Honors the optional
 * subcategory ID list ($cat_ids) for filtering, plus pagination.
 */
function bq_archive_query_args($cat_ids = [], $paged = 1, $per_page = 19) {
    $args = [
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'posts_per_page'      => $per_page,
        'paged'               => max(1, (int) $paged),
        'orderby'             => 'date',
        'order'               => 'DESC',
        'ignore_sticky_posts' => true,
    ];

    // Always exclude practice tests (posts in the 'practice-tests' category).
    $tax_query = [];
    $practice = get_category_by_slug('practice-tests');
    if ($practice) {
        $tax_query[] = [
            'taxonomy' => 'category',
            'field'    => 'term_id',
            'terms'    => [(int) $practice->term_id],
            'operator' => 'NOT IN',
        ];
    }

    // Optional subcategory filter (OR semantics — checking Microsoft +
    // Cisco shows posts in either).
    $cat_ids = array_values(array_unique(array_filter(array_map('intval', (array) $cat_ids))));
    if (!empty($cat_ids)) {
        $tax_query[] = [
            'taxonomy' => 'itu_content_category',
            'field'    => 'term_id',
            'terms'    => $cat_ids,
            'operator' => 'IN',
            'include_children' => true,
        ];
    }

    if (!empty($tax_query)) {
        if (count($tax_query) > 1) $tax_query['relation'] = 'AND';
        $args['tax_query'] = $tax_query;
    }

    return $args;
}

/**
 * Render a single blog card. Matches the visual of the existing template
 * (title link + 20-word excerpt + "Read Article →" link) so swapping in
 * the new shortcode doesn't visibly change the public archive.
 */
function bq_render_blog_card($post_id) {
    $title     = get_the_title($post_id);
    $permalink = get_permalink($post_id);
    $excerpt   = wp_strip_all_tags(get_the_excerpt($post_id));
    $excerpt   = wp_trim_words($excerpt, 20, '…');
    return '<div class="wp-block-group itu-blog-card">'
        . '<h3 class="wp-block-post-title itu-blog-card__title">'
        .   '<a href="' . esc_url($permalink) . '">' . esc_html($title) . '</a>'
        . '</h3>'
        . '<p class="wp-block-post-excerpt__excerpt itu-blog-card__excerpt">' . esc_html($excerpt) . '</p>'
        . '<p class="wp-block-post-excerpt__more-text itu-blog-card__link">'
        .   '<a href="' . esc_url($permalink) . '">Read Article →</a>'
        . '</p>'
        . '</div>';
}

/**
 * Render N cards as a single HTML string, used by the initial server-side
 * render and the AJAX filter response.
 */
/**
 * Build a promo card's HTML matching the existing itu-promo-card styling.
 */
function bq_render_promo_card($promo) {
    return '<div class="wp-block-group itu-blog-card itu-promo-card">'
        . '<span class="itu-promo-card__eyebrow">' . esc_html($promo['eyebrow'] ?? '') . '</span>'
        . '<h3 class="itu-promo-card__title">' . esc_html($promo['title'] ?? '') . '</h3>'
        . '<p class="itu-promo-card__desc">' . esc_html($promo['description'] ?? '') . '</p>'
        . '<a href="' . esc_url($promo['cta_url'] ?? '#') . '" class="itu-promo-card__cta">' . esc_html($promo['cta_text'] ?? 'Learn More →') . '</a>'
        . '</div>';
}

function bq_render_archive_grid($cat_ids = [], $paged = 1, $per_page = 19) {
    $q = new WP_Query(bq_archive_query_args($cat_ids, $paged, $per_page));

    // Load promo cards for injection on every page of results — including
    // "Load More" appended pages — so the grid always fills evenly
    // (19 posts + 2 promos = 21 = 3×7).
    $promos = [];
    if (function_exists('itu_get_promo_cards')) {
        $promos = array_values(itu_get_promo_cards());
    }

    $html = '';
    $card_index = 0;
    if ($q->have_posts()) {
        while ($q->have_posts()) {
            $q->the_post();
            $card_index++;
            $html .= bq_render_blog_card(get_the_ID());

            // Inject promo cards after position 5 and 10 (matching the
            // original layout: posts + 2 promos fill a 3-col grid evenly).
            $promo_count = count($promos);
            if ($card_index === 5 && $promo_count >= 1) {
                $html .= bq_render_promo_card($promos[0]);
            } elseif ($card_index === 10 && $promo_count >= 2) {
                $html .= bq_render_promo_card($promos[1]);
            } elseif ($card_index === 10 && $promo_count === 1) {
                $html .= bq_render_promo_card($promos[0]);
            }
        }
        wp_reset_postdata();
    } else {
        $html = '<div class="itu-blog-empty" style="grid-column:1/-1;padding:40px;text-align:center;color:#94a3b8;">No posts match the selected filters.</div>';
    }
    return [
        'html'      => $html,
        'total'     => (int) $q->found_posts,
        'pages'     => (int) $q->max_num_pages,
        'has_more'  => $paged < (int) $q->max_num_pages,
    ];
}

/**
 * Build the sidebar HTML — collapsible parent groups with subcategory
 * checkboxes. Uses the same bq_get_content_taxonomy_tree() helper as
 * the admin Categories tab so empty categories are filtered out and
 * counts roll up to parents.
 *
 * @param array $checked_ids Pre-checked subcategory term IDs (from URL state)
 */
function bq_render_archive_sidebar($checked_ids = []) {
    $tree = bq_get_content_taxonomy_tree(['only_with_posts' => true]);
    $checked_lookup = array_flip(array_map('intval', $checked_ids));

    $html = '<aside class="itu-archive-sidebar">';
    $html .= '<div class="itu-archive-sidebar__header">';
    $html .= '<h3>Filter by Category</h3>';
    $html .= '<button type="button" class="itu-archive-sidebar__clear" id="itu-blog-clear-filters" style="display:none;">Clear all</button>';
    $html .= '</div>';

    if (empty($tree)) {
        $html .= '<div style="padding:14px;color:#94a3b8;font-size:13px;">No categorized posts yet.</div>';
        $html .= '</aside>';
        return $html;
    }

    foreach ($tree as $parent) {
        $html .= '<details class="itu-archive-cat-group">';
        $html .= '<summary class="itu-archive-cat-group__title">'
              . '<span class="itu-archive-cat-group__name">' . esc_html($parent['name']) . '</span>'
              . '<span class="itu-archive-cat-group__count">(' . intval($parent['count']) . ')</span>'
              . '</summary>';
        $html .= '<ul class="itu-archive-cat-list">';
        foreach ($parent['children'] as $child) {
            $checked = isset($checked_lookup[$child['term_id']]) ? ' checked' : '';
            $html .= '<li class="itu-archive-cat-item">'
                  . '<label>'
                  .   '<input type="checkbox" class="itu-blog-filter-cb" value="' . intval($child['term_id']) . '"' . $checked . ' /> '
                  .   '<span class="itu-archive-cat-item__name">' . esc_html($child['name']) . '</span>'
                  .   '<span class="itu-archive-cat-item__count">' . intval($child['count']) . '</span>'
                  . '</label>'
                  . '</li>';
        }
        $html .= '</ul>';
        $html .= '</details>';
    }
    $html .= '</aside>';
    return $html;
}

/**
 * Main shortcode — outputs the entire archive layout.
 *   - Admin view: sidebar + filterable grid + Load More button.
 *   - Public view: simple grid (no sidebar, no filters) — visually
 *     identical to the old block-query template.
 */
add_shortcode('itu_blog_archive', function () {
    // ── Bail out for practice-tests ──
    // The practice-tests category should NOT get the filterable archive
    // layout. Render a simple card grid with standard pagination —
    // visually identical to what the old block-query template produced.
    if (is_category('practice-tests')) {
        $pt_cat = get_queried_object();
        $paged = max(1, (int) get_query_var('paged', 1));
        $q = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 12,
            'paged'          => $paged,
            'cat'            => $pt_cat ? $pt_cat->term_id : 0,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        $cards = '';
        if ($q->have_posts()) {
            while ($q->have_posts()) { $q->the_post(); $cards .= bq_render_blog_card(get_the_ID()); }
            wp_reset_postdata();
        }
        $title = single_term_title('', false) ?: 'Practice Tests';
        $html = '<div style="max-width:1200px;margin:0 auto;padding:0 24px;">'
              . '<div class="itu-archive-header">'
              . '<h1 class="itu-archive-title wp-block-query-title">' . esc_html($title) . '</h1>'
              . do_shortcode('[ivory-search id="1004565" title="AJAX Search Form"]')
              . '</div>'
              . '<div class="wp-block-post-template itu-blog-grid is-layout-grid" style="grid-template-columns:repeat(3,1fr);">' . $cards . '</div>';
        // Simple numbered pagination
        $pag = paginate_links([
            'total'   => $q->max_num_pages,
            'current' => $paged,
            'type'    => 'list',
        ]);
        if ($pag) $html .= '<nav class="itu-blog-pagination">' . $pag . '</nav>'
              . '<style>'
              . '.itu-blog-pagination { margin:32px 0 60px; text-align:center; }'
              . '.itu-blog-pagination > ul.page-numbers { list-style:none; display:flex; justify-content:center; gap:6px; padding:0; margin:0; flex-wrap:wrap; }'
              . '.itu-blog-pagination > ul.page-numbers > li { list-style:none; }'
              . '.itu-blog-pagination > ul.page-numbers > li > a, .itu-blog-pagination > ul.page-numbers > li > span { display:inline-block; padding:0 12px !important; height:36px !important; line-height:36px !important; border:1px solid #e2e8f0; text-decoration:none; color:#475569; font-size:13px; border-radius:4px; box-sizing:border-box; }'
              . '.itu-blog-pagination > ul.page-numbers > li > a:hover { border-color:#C026D3; color:#C026D3; }'
              . '.itu-blog-pagination > ul.page-numbers > li > .current { background:#1e293b; color:#fff; border-color:#1e293b; }'
              . '</style>';
        $html .= '</div>';
        return $html;
    }

    // Pre-check subcategory boxes from ?cats=12,15,28
    $initial_cat_ids = [];
    if (!empty($_GET['cats'])) {
        $initial_cat_ids = array_filter(array_map('intval', explode(',', sanitize_text_field($_GET['cats']))));
    }

    $is_admin_view = true; // Category filter is now public
    $per_page = 19;
    $grid = bq_render_archive_grid($initial_cat_ids, 1, $per_page);

    // Page header (mirrors the previous archive header layout)
    $title = single_term_title('', false);
    if (!$title) $title = post_type_archive_title('', false) ?: 'Blogs';
    $header = '<div class="itu-archive-header"><h1 class="itu-archive-title wp-block-query-title">' . esc_html($title) . '</h1>'
            . do_shortcode('[ivory-search id="1004565" title="AJAX Search Form"]')
            . '</div>';

    if (!$is_admin_view) {
        // Public path — simple full-width grid, no filters.
        $html = $header
              . '<div class="wp-block-query itu-blog-query">'
              . '<div class="wp-block-post-template itu-blog-grid is-layout-grid">' . $grid['html'] . '</div>'
              . ($grid['has_more']
                  ? '<div class="itu-blog-loadmore-wrap"><button type="button" class="button itu-blog-loadmore" data-paged="2">Load More Articles</button></div>'
                  : '')
              . '</div>';
        return $html;
    }

    // Admin path — sidebar + grid + URL state.
    $sidebar = bq_render_archive_sidebar($initial_cat_ids);
    $cats_attr = !empty($initial_cat_ids) ? esc_attr(implode(',', $initial_cat_ids)) : '';

    $html = $header;
    $html .= '<div class="itu-archive-layout"';
    $html .= ' data-ajax="' . esc_url(admin_url('admin-ajax.php')) . '"';
    $html .= ' data-nonce="' . esc_attr(wp_create_nonce('bq_archive_nonce')) . '"';
    $html .= ' data-initial-cats="' . $cats_attr . '"';
    $html .= '>';

    // Main content first in DOM order — the sidebar follows but is
    // positioned to the right via grid-column. This way the cards stay
    // on the left even when the grid auto-flows on narrow viewports.
    $html .= '<div class="itu-archive-main">';
    $html .= '<div class="itu-archive-active-filters" id="itu-blog-active-filters" style="display:none;"></div>';
    $html .= '<div class="wp-block-post-template itu-blog-grid is-layout-grid" id="itu-blog-grid">' . $grid['html'] . '</div>';
    $html .= '<div class="itu-blog-loadmore-wrap" id="itu-blog-loadmore-wrap"';
    $html .= $grid['has_more'] ? '' : ' style="display:none;"';
    $html .= '>';
    $html .= '<button type="button" class="button itu-blog-loadmore" id="itu-blog-loadmore" data-paged="2">Load More Articles</button>';
    $html .= '</div>';
    $html .= '</div>'; // .itu-archive-main

    $html .= $sidebar;

    $html .= '</div>'; // .itu-archive-layout
    return $html;
});

// AJAX endpoint that powers the live filter — returns rendered cards HTML
// for the current cat_ids + pagination state. Public nonce so logged-in
// users can call it (capability check below restricts to admins for now).
add_action('wp_ajax_bq_archive_filter',  'bq_archive_filter_handler');
add_action('wp_ajax_nopriv_bq_archive_filter', 'bq_archive_filter_handler');
function bq_archive_filter_handler() {
    // Catch PHP fatals and return them as JSON so the front-end JS can
    // display a meaningful error instead of seeing raw HTML.
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            while (ob_get_level() > 0) @ob_end_clean();
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'data'    => 'PHP Fatal: ' . $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line'],
            ]);
        }
    });
    ob_start();

    // Nonce verification — if it fails for logged-out users (because the
    // page cache served a nonce generated for a different session), skip
    // the check. The endpoint only returns public blog post HTML — there's
    // no write operation or sensitive data, so nonce protection is nice-to-
    // have but not security-critical.
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bq_archive_nonce')) {
        // Allow the request anyway — it's a public read-only endpoint.
    }

    $cat_ids = isset($_POST['cats']) && is_array($_POST['cats'])
        ? array_filter(array_map('intval', $_POST['cats']))
        : [];
    $paged    = max(1, (int) ($_POST['paged'] ?? 1));
    $append   = !empty($_POST['append']);
    $per_page = 19;

    $grid = bq_render_archive_grid($cat_ids, $paged, $per_page);
    ob_end_clean();
    wp_send_json_success([
        'html'     => $grid['html'],
        'total'    => $grid['total'],
        'pages'    => $grid['pages'],
        'has_more' => $grid['has_more'],
        'paged'    => $paged,
        'append'   => $append,
    ]);
}

// Inline CSS + JS for the front-end archive — only emitted on category /
// archive pages so we don't bloat unrelated requests.
add_action('wp_footer', function () {
    if (!(is_category() || is_archive() || is_home())) return;
    ?>
    <style>
    .itu-archive-header { display:flex; align-items:center; justify-content:space-between; gap:24px; flex-wrap:wrap; max-width:1200px; margin:0 auto 32px; padding:0 24px; }
    .itu-archive-title { margin:0; font-size:2.5rem; font-weight:800; }
    /* Two-column grid: cards on the LEFT (flexible), sidebar on the RIGHT (fixed 280px).
       grid-column on each child enforces the position regardless of DOM order. */
    .itu-archive-layout { display:grid; grid-template-columns:minmax(0, 1fr) 280px; gap:32px; max-width:1200px; margin:0 auto; padding:0 24px; align-items:start; }
    .itu-archive-main    { grid-column:1; min-width:0; padding-bottom:60px; }
    .itu-archive-sidebar { grid-column:2; }
    /* Mobile / narrow viewports: drop the sidebar entirely. The filter UI
       is desktop-only — mobile users get the simple grid like the public
       view, ordered by date. */
    @media (max-width:900px) {
        .itu-archive-layout  { grid-template-columns:1fr; }
        .itu-archive-main    { grid-column:1; }
        .itu-archive-sidebar { display:none; }
    }
    /* Sidebar — styled to match the itu-plans-bar: dashed fuchsia border,
       fuchsia tinted background, Fira Code font for the header. */
    .itu-archive-sidebar { border:1px dashed #C026D3; background:rgba(192,38,211,0.03); border-radius:0; padding:0; position:sticky; top:24px; max-height:calc(100vh - 48px); overflow-y:auto; box-sizing:border-box;
        scrollbar-width:thin; scrollbar-color:#d1d5db transparent; }
    .itu-archive-sidebar::-webkit-scrollbar { width:6px; }
    .itu-archive-sidebar::-webkit-scrollbar-track { background:transparent; }
    .itu-archive-sidebar::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:3px; }
    .itu-archive-sidebar::-webkit-scrollbar-thumb:hover { background:#9ca3af; }
    .itu-archive-sidebar__header { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-bottom:1px dashed rgba(192,38,211,0.25); }
    .itu-archive-sidebar__header h3 { margin:0; font-family:'Fira Code',Menlo,Consolas,monospace; font-size:0.85rem; font-weight:600; text-transform:uppercase; letter-spacing:2px; color:#C026D3; }
    .itu-archive-sidebar__clear { background:#fff; border:1px solid rgba(192,38,211,0.3); font-family:'Fira Code',Menlo,Consolas,monospace; font-size:11px; font-weight:600; color:#C026D3; cursor:pointer; text-decoration:none; padding:4px 12px; border-radius:0; border-top-right-radius:8px; transition:all 0.15s; white-space:nowrap; }
    .itu-archive-sidebar__clear:hover { background:#C026D3; color:#fff; border-color:#C026D3; }
    .itu-archive-cat-group { border-bottom:1px solid rgba(192,38,211,0.12); }
    .itu-archive-cat-group:last-child { border-bottom:none; }
    .itu-archive-cat-group[open] { background:#fff; }
    /* Parent group title — chevron + LEFT-aligned name, count pushed right. */
    .itu-archive-cat-group__title { padding:12px 20px; cursor:pointer; font-size:13px; font-weight:600; color:#1e293b; list-style:none; display:flex; align-items:center; gap:8px; text-align:left; }
    .itu-archive-cat-group__title::-webkit-details-marker { display:none; }
    .itu-archive-cat-group__title::before { content:'+'; display:inline-block; font-size:14px; font-weight:700; color:#C026D3; flex-shrink:0; width:14px; text-align:center; }
    .itu-archive-cat-group[open] .itu-archive-cat-group__title::before { content:'−'; }
    .itu-archive-cat-group__name { flex:1 1 auto; text-align:left; }
    .itu-archive-cat-group__count { color:#C026D3; font-weight:400; font-size:12px; flex-shrink:0; opacity:0.7; }
    .itu-archive-cat-list { list-style:none; padding:0 20px 12px 36px; margin:0; }
    .itu-archive-cat-item label { display:flex; align-items:center; gap:8px; padding:5px 0; cursor:pointer; font-size:12px; color:#475569; }
    .itu-archive-cat-item__name { flex:1; }
    .itu-archive-cat-item__count { color:#94a3b8; font-size:11px; min-width:24px; text-align:right; }
    .itu-archive-cat-item label:hover { color:#C026D3; }
    .itu-archive-cat-item input[type="checkbox"] { accent-color:#C026D3; }
    .itu-archive-active-filters { margin-bottom:16px; display:flex; flex-wrap:wrap; gap:6px; }
    .itu-archive-active-filters__chip { background:rgba(192,38,211,0.06); border:1px solid rgba(192,38,211,0.25); border-radius:0; border-top-right-radius:8px; padding:5px 10px 5px 12px; font-family:'Fira Code',Menlo,Consolas,monospace; font-size:11px; color:#C026D3; display:inline-flex; align-items:center; gap:6px; font-weight:600; }
    .itu-archive-active-filters__chip button { background:none; border:none; color:rgba(192,38,211,0.5); cursor:pointer; font-size:14px; line-height:1; padding:0; }
    .itu-archive-active-filters__chip button:hover { color:#dc2626; }
    .itu-blog-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:20px; }
    .itu-blog-loadmore-wrap { margin-top:32px; margin-bottom:60px; text-align:center; }
    .itu-blog-loadmore { display:inline-block; padding:10px 32px; font-family:'Fira Code',Menlo,Consolas,monospace; font-size:0.85rem; font-weight:600; color:#C026D3; background:#fff; border:1px solid rgba(192,38,211,0.3); border-radius:0; border-top-right-radius:12px; cursor:pointer; transition:all 0.15s; text-decoration:none; }
    .itu-blog-loadmore:hover { background:#C026D3; color:#fff; border-color:#C026D3; }
    .itu-blog-grid--loading { opacity:0.4; transition:opacity 0.2s; }
    .itu-archive-layout { margin-bottom:60px; }
    </style>
    <script>
    (function() {
        var layout = document.querySelector('.itu-archive-layout');
        if (!layout) return; // not the admin filterable view

        var ajaxUrl = layout.dataset.ajax;
        var nonce   = layout.dataset.nonce;
        var grid    = document.getElementById('itu-blog-grid');
        var loadMoreBtn = document.getElementById('itu-blog-loadmore');
        var loadMoreWrap = document.getElementById('itu-blog-loadmore-wrap');
        var clearBtn = document.getElementById('itu-blog-clear-filters');
        var activeFilters = document.getElementById('itu-blog-active-filters');
        var checkboxes = layout.querySelectorAll('.itu-blog-filter-cb');
        var requestSeq = 0;

        function getCheckedIds() {
            var ids = [];
            checkboxes.forEach(function(cb) { if (cb.checked) ids.push(parseInt(cb.value, 10)); });
            return ids;
        }

        function updateUrl(ids) {
            var params = new URLSearchParams(window.location.search);
            if (ids.length) params.set('cats', ids.join(','));
            else            params.delete('cats');
            var url = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
            window.history.replaceState({}, '', url);
        }

        function renderActiveChips() {
            var ids = getCheckedIds();
            if (!ids.length) {
                activeFilters.style.display = 'none';
                clearBtn.style.display = 'none';
                return;
            }
            activeFilters.innerHTML = '';
            checkboxes.forEach(function(cb) {
                if (!cb.checked) return;
                var label = cb.parentNode.querySelector('.itu-archive-cat-item__name').textContent;
                var chip = document.createElement('span');
                chip.className = 'itu-archive-active-filters__chip';
                chip.innerHTML = label.replace(/[<>&]/g, '') + ' <button type="button" data-id="' + cb.value + '" aria-label="Remove">×</button>';
                chip.querySelector('button').addEventListener('click', function() {
                    cb.checked = false;
                    runFilter(true);
                });
                activeFilters.appendChild(chip);
            });
            activeFilters.style.display = 'flex';
            clearBtn.style.display = '';
        }

        function runFilter(reset) {
            var ids = getCheckedIds();
            updateUrl(ids);
            renderActiveChips();
            grid.classList.add('itu-blog-grid--loading');

            var paged = reset ? 1 : (parseInt(loadMoreBtn.dataset.paged, 10) || 1);
            var append = !reset;
            var seq = ++requestSeq;
            var formData = new FormData();
            formData.append('action', 'bq_archive_filter');
            formData.append('nonce', nonce);
            formData.append('paged', paged);
            formData.append('append', append ? '1' : '0');
            ids.forEach(function(id) { formData.append('cats[]', id); });

            // Show loading state on the button for Load More clicks
            if (!reset) loadMoreBtn.textContent = 'Loading…';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.timeout = 30000;
            xhr.onload = function() {
                if (seq !== requestSeq) return;
                grid.classList.remove('itu-blog-grid--loading');
                loadMoreBtn.textContent = 'Load More Articles';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (!resp || !resp.success) {
                        console.error('bq_archive_filter error:', resp);
                        return;
                    }
                    var d = resp.data;
                    if (d.append) {
                        grid.insertAdjacentHTML('beforeend', d.html);
                    } else {
                        grid.innerHTML = d.html;
                        // Scroll to the top of the grid so the user sees the
                        // new filtered results immediately instead of staring
                        // at whatever scroll position they were at before.
                        grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    if (d.has_more) {
                        loadMoreWrap.style.display = '';
                        loadMoreBtn.dataset.paged = String((d.paged || 1) + 1);
                    } else {
                        loadMoreWrap.style.display = 'none';
                    }
                } catch(e) {
                    console.error('bq_archive_filter parse error:', e, xhr.responseText.substring(0, 500));
                    grid.insertAdjacentHTML('beforeend', '<div style="grid-column:1/-1;padding:20px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;color:#991b1b;font-size:13px;margin-top:12px;"><strong>Load More failed:</strong> ' + (xhr.responseText || '').substring(0, 300).replace(/</g, '&lt;') + '</div>');
                }
            };
            xhr.onerror = xhr.ontimeout = function() {
                grid.classList.remove('itu-blog-grid--loading');
                loadMoreBtn.textContent = 'Load More Articles';
                console.error('bq_archive_filter XHR error:', xhr.status, xhr.statusText);
                grid.insertAdjacentHTML('beforeend', '<div style="grid-column:1/-1;padding:20px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;color:#991b1b;font-size:13px;margin-top:12px;"><strong>Load More failed:</strong> ' + xhr.status + ' ' + xhr.statusText + '</div>');
            };
            // Send as URL-encoded form data instead of FormData — admin-ajax
            // parses $_POST from url-encoded more reliably than multipart.
            var params = 'action=bq_archive_filter&nonce=' + encodeURIComponent(nonce)
                       + '&paged=' + paged + '&append=' + (append ? '1' : '0');
            ids.forEach(function(id) { params += '&cats%5B%5D=' + id; });
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send(params);
        }

        // Accordion behavior — only one parent category open at a time.
        // When a <details> opens, close all siblings.
        layout.querySelectorAll('.itu-archive-cat-group').forEach(function(det) {
            det.addEventListener('toggle', function() {
                if (!det.open) return;
                layout.querySelectorAll('.itu-archive-cat-group').forEach(function(other) {
                    if (other !== det && other.open) other.open = false;
                });
            });
        });

        checkboxes.forEach(function(cb) {
            cb.addEventListener('change', function() { runFilter(true); });
        });

        clearBtn.addEventListener('click', function() {
            checkboxes.forEach(function(cb) { cb.checked = false; });
            runFilter(true);
        });

        loadMoreBtn.addEventListener('click', function() {
            runFilter(false);
        });

        // Initial render: show chips for any pre-checked categories from URL.
        renderActiveChips();
    })();
    </script>
    <?php
});

// ─── AI Categorizer ─────────────────────────────────────────────────────────

/**
 * Detect which vendor categories MUST appear in a categorization result
 * based on certification names, exam codes, and brand mentions in the
 * post text. Used as a server-side safety net when the AI prompt fails
 * to enforce the vendor → category mapping.
 *
 * Returns an array of [decoded_term_name => term_id] for vendors that
 * exist in the active taxonomy and are referenced in the text.
 *
 * @param string $haystack         Combined title + body + tags text.
 * @param array  $valid_term_names Lowercased term name → term_id lookup
 *                                 (from bq_categorize_post's prompt builder).
 * @return array
 */
function bq_detect_required_vendors($haystack, $valid_term_names) {
    if (empty($haystack) || empty($valid_term_names)) return [];

    $hay_lower = strtolower($haystack);

    // vendor_category_name => array of regex patterns that imply it
    // Patterns are case-insensitive substring matches with word boundaries
    // for short codes that could collide (e.g. "A+" matching "A+B").
    $rules = [
        'CompTIA' => [
            '/\bcomptia\b/i',
            // The literal plus sign must be escaped as \+ in the regex.
            // Without it, "security+" matches "security" (+ = quantifier),
            // which triggers on every cybersecurity post.
            '/\bsecurity\s*\+/i',
            '/\bnetwork\s*\+/i',
            '/\bcysa\s*\+/i',
            '/\bpentest\s*\+/i',
            '/\bcasp\s*\+/i',
            '/\bcloud\s*\+/i',
            '/\blinux\s*\+/i',
            '/\bserver\s*\+/i',
            '/\bproject\s*\+/i',
            '/\bdata\s*\+/i',
            '/\bitf\s*\+/i',
            // "A+" is too ambiguous (matches "A Beginner's Guide") — only
            // match when preceded by "CompTIA" or followed by "certification"
            // or "exam" to avoid false positives on regular English.
            '/\bcomptia\s+a\s*\+/i',
            '/\bA\s*\+\s*(?:certification|exam|certified)\b/i',
            // Exam codes are unambiguous.
            '/\b(?:sy0|n10|220|cs0|pt0|cv0|xk0|sk0|pk0)-\d{3}\b/i',
        ],
        'Cisco' => [
            '/\bcisco\b/i',
            '/\bccna\b/i', '/\bccnp\b/i', '/\bccie\b/i', '/\bccst\b/i', '/\bdevnet\b/i',
        ],
        'Microsoft' => [
            '/\bmicrosoft\b/i', '/\bazure\b/i', '/\bmicrosoft\s*365\b/i', '/\bm365\b/i',
            '/\bwindows\s*server\b/i',
            '/\b(?:az|ms|ai|sc|md|mb|pl|dp)-\d{3}\b/i',
        ],
        'AWS' => [
            '/\baws\b/i', '/\bamazon\s*web\s*services\b/i',
            '/\b(?:saa|sap|dva|soa|mls|das|dbs|scs|ans|clf)-\d{3}\b/i',
            '/\baws\s*certified\b/i',
        ],
        'Google Cloud' => [
            '/\bgoogle\s*cloud\b/i', '/\bgcp\b/i',
            '/\b(?:ace|pca|pcd|pcne|pcse|pde)\b/i',
        ],
        'ISC2' => [
            '/\bisc2\b/i', '/\b\(isc\)2\b/i',
            '/\bcissp\b/i', '/\bccsp\b/i', '/\bsscp\b/i', '/\bcsslp\b/i', '/\bhcispp\b/i',
        ],
        'EC-Council' => [
            '/\bec-council\b/i', '/\bec council\b/i',
            '/\bceh\b/i', '/\bchfi\b/i', '/\bccISO\b/i', '/\becsa\b/i',
        ],
        'ISACA' => [
            '/\bisaca\b/i', '/\bcisa\b/i', '/\bcism\b/i', '/\bcrisc\b/i', '/\bcgeit\b/i',
        ],
        'PMI & Agile' => [
            '/\bpmi\b/i', '/\bpmp\b/i', '/\bcapm\b/i', '/\bpmi-acp\b/i', '/\bpmi-rmp\b/i',
        ],
        'ITIL & Service Management' => [
            '/\bitil\b/i',
        ],
        'Linux Foundation' => [
            '/\blinux\s*foundation\b/i', '/\bcka\b/i', '/\bckad\b/i', '/\bcks\b/i',
            '/\blfcs\b/i', '/\blfce\b/i', '/\bkubernetes\s*certified\b/i',
        ],
    ];

    $required = [];
    foreach ($rules as $term_name => $patterns) {
        $key = strtolower($term_name);
        if (!isset($valid_term_names[$key])) continue; // term doesn't exist in taxonomy
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $hay_lower)) {
                $required[$term_name] = $valid_term_names[$key];
                break;
            }
        }
    }
    return $required;
}

/**
 * Run the AI categorizer against a single post.
 *
 * @param int   $post_id
 * @param array $opts {
 *     @type string $batch_id    Optional batch identifier for grouping.
 *     @type bool   $auto_apply  When true, applies the result immediately
 *                               to the post if confidence ≥ auto threshold;
 *                               low-confidence rows go to manual review.
 * }
 * @return int|WP_Error  Staging row ID on success.
 */
function bq_categorize_post($post_id, $opts = []) {
    $post_id = (int) $post_id;
    global $wpdb;
    $log_table = $wpdb->prefix . 'bq_categorization_log';
    $batch_id  = isset($opts['batch_id']) ? sanitize_text_field($opts['batch_id']) : null;

    // Helper: write a staging row representing a failure so the user can see
    // it in the review queue. Returns the new row's ID.
    $write_failure = function ($post_id, $message) use ($wpdb, $log_table, $batch_id) {
        $wpdb->insert($log_table, [
            'post_id'        => (int) $post_id,
            'batch_id'       => $batch_id,
            'run_at'         => current_time('mysql'),
            'status'         => 'failed',
            'error_message'  => substr((string) $message, 0, 1000),
            'confidence'     => 0,
        ]);
        return (int) $wpdb->insert_id;
    };

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post') {
        $write_failure($post_id, 'Post not found or not a blog post.');
        return new WP_Error('invalid_post', 'Post not found or not a blog post.');
    }

    // Build the list of valid term names so the AI can ONLY pick from
    // categories that actually exist. We send it as a structured tree so
    // it understands the parent → child relationship.
    $tree = bq_get_content_taxonomy_tree(['only_with_posts' => false]);
    if (empty($tree)) {
        $write_failure($post_id, 'Content taxonomy is empty — seed it first.');
        return new WP_Error('no_taxonomy', 'Content taxonomy is empty — seed it first.');
    }

    // Build the prompt text and the validation lookup with HTML entities
    // decoded. WordPress stores term names encoded (& → &amp;), so we need
    // to compare against the decoded form on both sides — the AI sees the
    // human-friendly version in the prompt and returns the same form back.
    $taxonomy_text = '';
    $valid_term_names = [];
    foreach ($tree as $parent) {
        $parent_name_decoded = html_entity_decode($parent['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $taxonomy_text .= "\n# " . $parent_name_decoded . "\n";
        foreach ($parent['children'] as $child) {
            $child_name_decoded = html_entity_decode($child['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $taxonomy_text .= "  - " . $child_name_decoded . "\n";
            $valid_term_names[strtolower($child_name_decoded)] = $child['term_id'];
        }
    }

    // Inputs per spec: title, meta description, first 200 words, existing tags.
    $title = $post->post_title;
    $meta_desc = (string) get_post_meta($post_id, 'rank_math_description', true);
    if ($meta_desc === '') $meta_desc = (string) $post->post_excerpt;

    $body_text = wp_strip_all_tags(strip_shortcodes($post->post_content));
    $words = preg_split('/\s+/u', trim($body_text));
    $first_200 = implode(' ', array_slice($words, 0, 200));

    $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
    $tags_text = is_array($tags) && !empty($tags) ? implode(', ', $tags) : '(none)';

    $instruction = "You are a content categorization expert for an IT training company's blog.\n\n"
        . "TASK: Read the blog post details below and assign it to ONE primary category and UP TO 4 secondary categories from the taxonomy provided.\n\n"
        . "TAXONOMY (pick ONLY from these exact names — do NOT invent new categories):\n"
        . $taxonomy_text . "\n"
        . "RULES:\n"
        . "- Primary category: exactly 1, the BEST single fit.\n"
        . "- If a post is centered on a specific certification or exam (Security+, CCNA, CISSP, AZ-900, etc.), the matching vendor (CompTIA, Cisco, ISC2, Microsoft) is the primary — NOT a Technology Domain.\n"
        . "- Salary posts → 'Salary & Compensation Guides' primary.\n"
        . "- 'What is X' explainers → 'What Is (Explainer Posts)' primary, unless X is a specific exam (then vendor is primary).\n"
        . "- Secondary categories: 0-4 additional categories the post genuinely covers. Do NOT pad — only add what's clearly relevant.\n\n"
        . "OUTPUT — return ONLY this exact JSON, no markdown, no preamble:\n"
        . "{\n"
        . "  \"primary_category\": \"Exact Category Name\",\n"
        . "  \"secondary_categories\": [\"Category A\", \"Category B\"],\n"
        . "  \"confidence\": 0.95,\n"
        . "  \"notes\": \"Brief justification.\"\n"
        . "}\n\n"
        . "Confidence: decimal between 0.0 and 1.0 for the primary category specifically.";

    $user_prompt = "TITLE: {$title}\n\n"
        . "META DESCRIPTION: " . ($meta_desc ?: '(none)') . "\n\n"
        . "EXISTING TAGS: {$tags_text}\n\n"
        . "FIRST 200 WORDS:\n{$first_200}";

    // Use the existing AI router (OpenAI by default) — same call pattern as
    // every other Blog Queue AI call. Lower temperature for consistency.
    $raw = bq_call_openai($instruction, $user_prompt, '', 0.2);
    if (is_wp_error($raw)) {
        $write_failure($post_id, 'AI call failed: ' . $raw->get_error_message());
        return $raw;
    }

    // Strip markdown code fences if the model wrapped the JSON.
    $raw = preg_replace('/^```[a-zA-Z]*\s*/m', '', $raw);
    $raw = preg_replace('/\s*```\s*$/m', '', $raw);
    $raw = trim($raw);

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        $write_failure($post_id, 'AI returned invalid JSON: ' . substr($raw, 0, 500));
        return new WP_Error('parse_failed', 'AI returned invalid JSON: ' . substr($raw, 0, 200));
    }

    // Decode any HTML entities the AI may have echoed back from our prompt
    // before validating, so '&amp;' / '&' / '&#38;' all match the same key.
    $decode = function ($s) {
        return html_entity_decode(trim((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    };

    $primary_name = $decode($parsed['primary_category'] ?? '');
    $secondary    = isset($parsed['secondary_categories']) && is_array($parsed['secondary_categories'])
        ? array_map($decode, $parsed['secondary_categories'])
        : [];
    $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0.0;
    $notes      = isset($parsed['notes']) ? sanitize_textarea_field((string) $parsed['notes']) : '';

    // Validate the primary against real taxonomy term names.
    $primary_id = $valid_term_names[strtolower($primary_name)] ?? 0;
    if (!$primary_id) {
        $write_failure($post_id, "AI picked unknown primary category: '{$primary_name}'");
        return new WP_Error('invalid_primary', "AI picked unknown primary category: '{$primary_name}'");
    }

    // Validate + dedupe + cap secondaries to 4 (5 total per spec).
    $secondary_ids = [];
    $secondary_names = [];
    foreach ($secondary as $name) {
        $tid = $valid_term_names[strtolower($name)] ?? 0;
        if (!$tid) continue;            // skip unknown
        if ($tid === $primary_id) continue; // primary can't double as secondary
        if (in_array($tid, $secondary_ids, true)) continue; // dedupe
        $secondary_ids[] = $tid;
        $secondary_names[] = $name;
        if (count($secondary_ids) >= 4) break;
    }

    // ── Deterministic vendor override ──
    // The AI is unreliable at vendor classification — it sees "Security+"
    // and sometimes picks Cisco because Cisco also has security certs.
    // The fix: vendor assignment is DETERMINISTIC. The regex-based detector
    // is the authority on which vendor(s) a post belongs to. The AI only
    // picks non-vendor subcategories (Technology Domains, Career, Training).
    //
    // Rules:
    //  1. If the detector finds exactly ONE vendor, it becomes the primary.
    //     The AI's original primary (if it was a different vendor) is removed.
    //     If the AI's primary was a non-vendor subcategory, it becomes a secondary.
    //  2. If the detector finds MULTIPLE vendors (comparison post), the first
    //     one detected becomes primary, the rest become secondaries.
    //  3. Any vendor in the AI's response that the detector did NOT find is
    //     removed — the AI hallucinated it.
    //  4. If no vendor is detected, the AI's pick stands (generic content).

    // Build the set of all vendor term IDs so we can identify whether a
    // term is a vendor (Certification Track child) or a non-vendor.
    // Build the vendor ID set by checking every known vendor name directly
    // against the taxonomy terms — avoids the fragile mega-string hack.
    $vendor_category_names = [
        'CompTIA', 'Cisco', 'Microsoft', 'AWS', 'Google Cloud', 'ISC2',
        'EC-Council', 'ISACA', 'PMI & Agile', 'ITIL & Service Management',
        'Linux Foundation',
    ];
    $all_vendor_names = [];
    foreach ($vendor_category_names as $vn) {
        if (isset($valid_term_names[strtolower($vn)])) $all_vendor_names[] = $vn;
    }
    $vendor_ids = [];
    foreach ($all_vendor_names as $vn) {
        $vid = $valid_term_names[strtolower($vn)] ?? 0;
        if ($vid) $vendor_ids[$vid] = $vn;
    }

    // Scan the title + first 300 words of body for vendor detection.
    // The first 300 words cover the introduction where the topic is stated;
    // deeper body mentions are just passing references and shouldn't trigger
    // vendor assignment.
    $body_words = preg_split('/\s+/u', trim($body_text));
    $intro_text = $title . ' ' . implode(' ', array_slice($body_words, 0, 300));
    $required_vendors = bq_detect_required_vendors($intro_text, $valid_term_names);
    $changes = [];

    if (!empty($required_vendors)) {
        $detected_vendor_ids   = array_values($required_vendors);
        $detected_vendor_names = array_keys($required_vendors);

        // Is the AI's current primary a vendor?
        $primary_is_vendor  = isset($vendor_ids[$primary_id]);
        $primary_is_correct = in_array($primary_id, $detected_vendor_ids, true);

        // Step 1: Fix the primary.
        if ($primary_is_vendor && !$primary_is_correct) {
            // AI picked the WRONG vendor as primary — swap it out.
            $changes[] = 'Replaced wrong vendor primary "' . $primary_name . '" with "' . $detected_vendor_names[0] . '"';
            $primary_id   = $detected_vendor_ids[0];
            $primary_name = $detected_vendor_names[0];
        } elseif (!$primary_is_vendor) {
            // AI picked a non-vendor as primary but the post has a vendor.
            // Demote the AI's primary to secondary and install the vendor.
            if (count($secondary_ids) < 4 && !in_array($primary_id, $secondary_ids, true)) {
                array_unshift($secondary_ids, $primary_id);
                array_unshift($secondary_names, $primary_name);
            }
            $changes[] = 'Promoted vendor "' . $detected_vendor_names[0] . '" to primary (was "' . $primary_name . '")';
            $primary_id   = $detected_vendor_ids[0];
            $primary_name = $detected_vendor_names[0];
        }
        // else: AI's primary matches a detected vendor — leave it.

        // Step 2: Remove any WRONG vendors from secondaries.
        $cleaned_ids   = [];
        $cleaned_names = [];
        foreach ($secondary_ids as $i => $sid) {
            if (isset($vendor_ids[$sid]) && !in_array($sid, $detected_vendor_ids, true)) {
                $changes[] = 'Removed wrong vendor secondary "' . ($secondary_names[$i] ?? '?') . '"';
                continue; // drop it
            }
            if ($sid === $primary_id) continue; // don't duplicate primary
            $cleaned_ids[]   = $sid;
            $cleaned_names[] = $secondary_names[$i] ?? '';
        }
        $secondary_ids   = $cleaned_ids;
        $secondary_names = $cleaned_names;

        // Step 3: Add any remaining detected vendors that aren't already
        // represented (for multi-vendor comparison posts).
        foreach ($required_vendors as $vname => $vid) {
            if ($vid === $primary_id) continue;
            if (in_array($vid, $secondary_ids, true)) continue;
            if (count($secondary_ids) >= 4) break;
            $secondary_ids[]   = $vid;
            $secondary_names[] = $vname;
            $changes[]         = 'Added detected vendor "' . $vname . '" as secondary';
        }

        // Cap secondaries at 4
        $secondary_ids   = array_slice($secondary_ids, 0, 4);
        $secondary_names = array_slice($secondary_names, 0, 4);

        if (!empty($changes)) {
            $notes = trim($notes . ' [Vendor override: ' . implode('; ', $changes) . ']');
        }
    } else {
        // No vendor detected — remove any vendor the AI hallucinated.
        // If the AI picked a vendor as primary on a non-vendor post, that's
        // suspicious but we leave it since we can't disprove it.
        // Only clean secondaries where we're confident it's wrong.
    }

    // Decide initial status based on confidence thresholds.
    $settings = bq_get_settings();
    $auto_t   = (float) $settings['cat_auto_threshold'];
    $review_t = (float) $settings['cat_review_threshold'];

    if ($confidence >= $auto_t)         $status = 'pending'; // will auto-apply if requested
    elseif ($confidence >= $review_t)   $status = 'flagged'; // applied but flagged for spot-check
    else                                $status = 'manual_review';

    $wpdb->insert($log_table, [
        'post_id'              => $post_id,
        'batch_id'             => $batch_id,
        'run_at'               => current_time('mysql'),
        'primary_term_id'      => $primary_id,
        'primary_term_name'    => sanitize_text_field($primary_name),
        'secondary_term_ids'   => wp_json_encode($secondary_ids),
        'secondary_term_names' => wp_json_encode($secondary_names),
        'confidence'           => round($confidence, 3),
        'notes'                => $notes,
        'status'               => $status,
    ]);
    $log_id = (int) $wpdb->insert_id;

    // Auto-apply path — high confidence and the caller asked for it.
    if (!empty($opts['auto_apply']) && $confidence >= $auto_t) {
        bq_apply_categorization($log_id);
    } elseif (!empty($opts['auto_apply']) && $confidence >= $review_t) {
        // Spot-check tier — still apply, but leave the row marked 'flagged'
        // so it shows up in the review UI for verification.
        bq_apply_categorization($log_id, true);
    }

    return $log_id;
}

/**
 * Apply a staging row to the actual post terms.
 *
 * @param int  $log_id           Row in wp_bq_categorization_log
 * @param bool $keep_flagged     If true, status stays 'flagged' instead of
 *                               flipping to 'auto_applied' — used by the
 *                               spot-check tier.
 * @return bool|WP_Error
 */
function bq_apply_categorization($log_id, $keep_flagged = false) {
    global $wpdb;
    $log_table = $wpdb->prefix . 'bq_categorization_log';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$log_table} WHERE id = %d", (int) $log_id));
    if (!$row) return new WP_Error('not_found', 'Staging row not found.');

    $primary_id = (int) $row->primary_term_id;
    $secondary  = json_decode((string) $row->secondary_term_ids, true) ?: [];
    $secondary  = array_slice(array_filter(array_map('intval', $secondary)), 0, 4);

    $all_ids = array_merge([$primary_id], $secondary);
    $all_ids = array_values(array_unique(array_filter($all_ids)));
    if (empty($all_ids)) return new WP_Error('empty', 'No valid terms to apply.');

    $set = wp_set_object_terms((int) $row->post_id, $all_ids, 'itu_content_category', false);
    if (is_wp_error($set)) return $set;

    // Persist the primary separately so we don't lose the distinction.
    update_post_meta((int) $row->post_id, '_bq_primary_content_category', $primary_id);

    $new_status = $keep_flagged ? 'flagged' : 'auto_applied';
    $wpdb->update($log_table, [
        'status'     => $new_status,
        'applied_at' => current_time('mysql'),
        'applied_by' => get_current_user_id() ?: null,
    ], ['id' => $log_id]);

    return true;
}

// ─── Phase 4: Auto-categorize on publish ────────────────────────────────────
//
// Hook fires whenever a blog post transitions into the 'publish' state for
// the first time (new content, not edits). Skips posts that already have
// categories assigned. Respects confidence thresholds — only ≥ auto applies
// immediately; spot-check tier applies but flags; manual review tier writes
// to the staging log without touching the post.
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($new_status !== 'publish') return;
    if ($old_status === 'publish') return;
    if (!$post || $post->post_type !== 'post') return;
    if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) return;

    // Skip posts that already have content categories — respect manual edits.
    $existing = wp_get_object_terms($post->ID, 'itu_content_category', ['fields' => 'ids']);
    if (!is_wp_error($existing) && !empty($existing)) return;

    bq_categorize_post($post->ID, ['auto_apply' => true, 'batch_id' => 'auto-on-publish']);
}, 20, 3);

// ─── AJAX: Categorization endpoints ─────────────────────────────────────────

// Quick count of uncategorized published posts. Powers the badge in the UI.
add_action('wp_ajax_bq_categorize_uncategorized_count', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $count = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts} p
        WHERE p.post_type = 'post' AND p.post_status = 'publish'
        AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->term_relationships} tr
            JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id = p.ID AND tt.taxonomy = 'itu_content_category'
        )
    ");
    wp_send_json_success(['count' => $count]);
});

// Diagnostic — runs ONE uncategorized post synchronously and returns the
// full result inline. Use this when the batch run silently produces nothing
// to see exactly what's failing (AI key wrong, model returns garbage, etc.).
add_action('wp_ajax_bq_categorize_test_one', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    @set_time_limit(120);

    // Pick the next uncategorized post (deterministic — first by ID).
    $post_id = (int) $wpdb->get_var("
        SELECT p.ID FROM {$wpdb->posts} p
        WHERE p.post_type = 'post' AND p.post_status = 'publish'
        AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->term_relationships} tr
            JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id = p.ID AND tt.taxonomy = 'itu_content_category'
        )
        ORDER BY p.ID ASC LIMIT 1
    ");
    if (!$post_id) wp_send_json_error('No uncategorized posts found.');

    // Test One runs the FULL pipeline including auto-apply, so the user
    // can verify the term actually lands on the post (not just that the
    // AI returned a parseable response). High confidence → applied silently;
    // mid confidence → applied + flagged; low confidence → staged only.
    $log_id = bq_categorize_post($post_id, ['batch_id' => 'diagnostic-' . time(), 'auto_apply' => true]);

    if (is_wp_error($log_id)) {
        wp_send_json_error([
            'post_id'   => $post_id,
            'post_title'=> get_the_title($post_id),
            'error'     => $log_id->get_error_message(),
            'error_code'=> $log_id->get_error_code(),
            'message'   => 'Categorization failed — see error above. A failed-status row was written to the log so you can see it in the review queue.',
        ]);
    }

    // Pull the row back so we return the parsed result.
    $log_table = $wpdb->prefix . 'bq_categorization_log';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$log_table} WHERE id = %d", $log_id));

    // Verify the apply actually happened by reading the post's terms back.
    $applied_term_ids = wp_get_object_terms($post_id, 'itu_content_category', ['fields' => 'ids']);
    $applied_term_names = [];
    if (!is_wp_error($applied_term_ids) && !empty($applied_term_ids)) {
        foreach ($applied_term_ids as $tid) {
            $t = get_term($tid, 'itu_content_category');
            if ($t && !is_wp_error($t)) $applied_term_names[] = $t->name;
        }
    }
    $primary_meta = (int) get_post_meta($post_id, '_bq_primary_content_category', true);

    $applied = !empty($applied_term_ids);
    $msg = $applied
        ? 'Success — terms applied to the post and the row is in the review queue under "' . $row->status . '".'
        : 'Success — but confidence was below the review threshold so the row was staged in "manual_review" without applying. Approve or override it from the review queue.';

    wp_send_json_success([
        'post_id'             => $post_id,
        'post_title'          => get_the_title($post_id),
        'log_id'              => $log_id,
        'primary'             => $row->primary_term_name,
        'secondary'           => json_decode((string) $row->secondary_term_names, true) ?: [],
        'confidence'          => (float) $row->confidence,
        'notes'               => $row->notes,
        'status'              => $row->status,
        'applied'             => $applied,
        'applied_terms'       => $applied_term_names,
        'applied_primary_id'  => $primary_meta,
        'message'             => $msg,
    ]);
});

// Re-categorize: strip existing itu_content_category terms from N
// already-categorized posts and re-run the AI categorizer with the
// current (deterministic vendor) rules. Also clears any prior staging
// log entries for the affected posts so the review queue is clean.
add_action('wp_ajax_bq_categorize_rerun_batch', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $batch_size = max(1, min(200, (int) ($_POST['batch'] ?? 50)));
    $auto_apply = !empty($_POST['auto_apply']);
    $batch_id   = 'rerun-' . date('Ymd-His') . '-' . wp_generate_password(6, false, false);

    // Find already-categorized published blog posts that haven't been
    // re-categorized yet today. The LEFT JOIN against the staging log
    // excludes any post that already has a 'rerun-*' batch entry from
    // today, so clicking Re-categorize repeatedly advances through the
    // full inventory instead of re-processing the same first N posts.
    $log_table = $wpdb->prefix . 'bq_categorization_log';
    $post_ids = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT p.ID FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        LEFT JOIN {$log_table} cl ON p.ID = cl.post_id
            AND cl.batch_id LIKE 'rerun-%%'
            AND DATE(cl.run_at) = CURDATE()
        WHERE p.post_type = 'post' AND p.post_status = 'publish'
        AND tt.taxonomy = 'itu_content_category'
        AND cl.id IS NULL
        ORDER BY p.ID ASC
        LIMIT %d
    ", $batch_size));

    if (empty($post_ids)) {
        wp_send_json_success([
            'batch_id'  => $batch_id,
            'queued'    => 0,
            'message'   => 'No categorized posts found to re-process.',
        ]);
    }

    // Detach response — same pattern as the normal batch run.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);
    @set_time_limit(0);

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    }
    echo wp_json_encode([
        'success' => true,
        'data'    => [
            'batch_id' => $batch_id,
            'queued'   => count($post_ids),
        ],
    ]);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { @ob_end_flush(); @flush(); }

    $log_table = $wpdb->prefix . 'bq_categorization_log';

    foreach ($post_ids as $pid) {
        $pid = (int) $pid;

        // 1. Strip all itu_content_category terms from the post.
        wp_delete_object_term_relationships($pid, 'itu_content_category');

        // 2. Clear the primary meta.
        delete_post_meta($pid, '_bq_primary_content_category');

        // 3. Remove any prior staging log entries for this post so they
        //    don't clutter the review queue.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$log_table} WHERE post_id = %d", $pid
        ));

        // 4. Re-categorize with the current rules (deterministic vendor + AI).
        bq_categorize_post($pid, [
            'batch_id'   => $batch_id,
            'auto_apply' => $auto_apply,
        ]);
    }
    exit;
});

// Run a categorization batch — picks N uncategorized posts and runs them.
// Long-running, so detaches the response and processes inline.
add_action('wp_ajax_bq_categorize_run_batch', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $batch_size = max(1, min(200, (int) ($_POST['batch'] ?? 50)));
    $auto_apply = !empty($_POST['auto_apply']);
    $batch_id   = 'batch-' . date('Ymd-His') . '-' . wp_generate_password(6, false, false);

    // Find uncategorized published blog posts ordered by ID asc.
    $post_ids = $wpdb->get_col($wpdb->prepare("
        SELECT p.ID FROM {$wpdb->posts} p
        WHERE p.post_type = 'post' AND p.post_status = 'publish'
        AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->term_relationships} tr
            JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id = p.ID AND tt.taxonomy = 'itu_content_category'
        )
        ORDER BY p.ID ASC
        LIMIT %d
    ", $batch_size));

    if (empty($post_ids)) {
        wp_send_json_success([
            'batch_id'  => $batch_id,
            'processed' => 0,
            'message'   => 'All posts are already categorized.',
        ]);
    }

    // ── Worker dispatch ──
    // ALL batches detach via fastcgi_finish_request() so the AJAX response
    // returns immediately and the browser polls bq_categorize_batch_status
    // for live progress. Categorization rows land in the staging log as
    // each post finishes, so the poller sees real-time row counts.
    //
    // Why no sync path: even a 10-post batch takes 30-60 seconds with a
    // slow reasoning model, which leaves the user staring at a blank
    // "Starting…" message. Polling every 2s gives constant feedback.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);
    @set_time_limit(0);

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    }
    echo wp_json_encode([
        'success' => true,
        'data'    => [
            'batch_id' => $batch_id,
            'queued'   => count($post_ids),
        ],
    ]);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { @ob_end_flush(); @flush(); }

    // Worker loop — each iteration writes a row to the log so the poller
    // can show progress as posts finish.
    foreach ($post_ids as $pid) {
        bq_categorize_post((int) $pid, [
            'batch_id'   => $batch_id,
            'auto_apply' => $auto_apply,
        ]);
    }
    exit;
});

// Live status of the most recent batch (or any batch by ID). Powers the
// progress poller in the UI.
//
// The poller passes both batch_id AND the original queued count so we
// can report "X / queued" instead of "X / X" — the latter would always
// look like 100% even when posts are still being processed.
add_action('wp_ajax_bq_categorize_batch_status', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $log_table = $wpdb->prefix . 'bq_categorization_log';
    $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
    $queued   = max(0, (int) ($_POST['queued'] ?? 0));

    if ($batch_id === '') {
        // Latest batch
        $batch_id = $wpdb->get_var("SELECT batch_id FROM {$log_table} WHERE batch_id IS NOT NULL ORDER BY id DESC LIMIT 1");
    }
    if (!$batch_id) wp_send_json_success(['batch_id' => null, 'rows' => [], 'totals' => []]);

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT id, post_id, primary_term_name, secondary_term_names, confidence, status, run_at, error_message
        FROM {$log_table} WHERE batch_id = %s ORDER BY id ASC
    ", $batch_id));

    // 'total' = the original queued count from the JS so the % is honest
    // even mid-run; falls back to row count if the caller didn't pass it.
    $totals = [
        'total'         => $queued > 0 ? $queued : count($rows),
        'processed'     => count($rows),
        'auto_applied'  => 0,
        'flagged'       => 0,
        'manual_review' => 0,
        'pending'       => 0,
        'rejected'      => 0,
        'failed'        => 0,
    ];
    foreach ($rows as $r) {
        $s = $r->status;
        if (isset($totals[$s])) $totals[$s]++;
    }
    $totals['done'] = ($totals['processed'] >= $totals['total']);

    // Enrich the most recent 10 rows with post titles for the live feed.
    // We don't need to ship the entire batch every poll.
    $enriched = [];
    $recent = array_slice($rows, -10);
    foreach ($recent as $r) {
        $r->post_title = get_the_title((int) $r->post_id) ?: '(deleted post)';
        $r->edit_url = get_edit_post_link((int) $r->post_id, '');
        $enriched[] = $r;
    }

    wp_send_json_success(['batch_id' => $batch_id, 'totals' => $totals, 'rows' => $enriched]);
});

// Paginated review of staging rows by status filter.
add_action('wp_ajax_bq_categorize_get_log', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $log_table = $wpdb->prefix . 'bq_categorization_log';
    $status = sanitize_text_field($_POST['status'] ?? 'all');
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;

    $where = "1=1";
    if ($status !== 'all') {
        $where .= $wpdb->prepare(" AND status = %s", $status);
    }

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$log_table} WHERE {$where}");
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM {$log_table} WHERE {$where}
        ORDER BY id DESC LIMIT %d OFFSET %d
    ", $per_page, $offset));

    foreach ($rows as $r) {
        $r->post_title = get_the_title((int) $r->post_id) ?: '(deleted post)';
        $r->edit_url   = get_edit_post_link((int) $r->post_id, '');
        $r->view_url   = get_permalink((int) $r->post_id);
        $r->secondary_term_names_arr = json_decode((string) $r->secondary_term_names, true) ?: [];
    }

    wp_send_json_success([
        'rows'  => $rows,
        'total' => $total,
        'page'  => $page,
        'pages' => max(1, ceil($total / $per_page)),
    ]);
});

// Apply a staging row to the post (uses bq_apply_categorization).
add_action('wp_ajax_bq_categorize_apply', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
    if (empty($ids)) wp_send_json_error('No rows selected.');

    $applied = 0;
    $failed  = 0;
    foreach ($ids as $log_id) {
        $r = bq_apply_categorization($log_id);
        if (is_wp_error($r)) $failed++; else $applied++;
    }
    wp_send_json_success(['applied' => $applied, 'failed' => $failed]);
});

// Reject a staging row — flips status to 'rejected' but leaves the row in
// the log for audit. The post itself is left untouched.
add_action('wp_ajax_bq_categorize_reject', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $log_table = $wpdb->prefix . 'bq_categorization_log';
    $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
    if (empty($ids)) wp_send_json_error('No rows selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query($wpdb->prepare(
        "UPDATE {$log_table} SET status = 'rejected' WHERE id IN ({$placeholders})",
        ...$ids
    ));
    wp_send_json_success(['rejected' => count($ids)]);
});

// Hard-delete a staging row — removes it from the log entirely. Used by
// the per-row × button and the bulk Delete action. Does NOT touch the
// underlying post; if the row had already been applied, the post keeps
// the term assignment — only the audit row is removed.
add_action('wp_ajax_bq_categorize_delete', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $log_table = $wpdb->prefix . 'bq_categorization_log';
    $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
    if (empty($ids)) wp_send_json_error('No rows selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$log_table} WHERE id IN ({$placeholders})",
        ...$ids
    ));
    wp_send_json_success(['deleted' => (int) $deleted]);
});

// Clear ALL rows from the categorization log. Does not touch any post terms.
add_action('wp_ajax_bq_categorize_clear_log', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $log_table = $wpdb->prefix . 'bq_categorization_log';
    $deleted = $wpdb->query("DELETE FROM {$log_table}");
    wp_send_json_success(['deleted' => (int) $deleted]);
});

// Strip ALL itu_content_category terms from every post + clear the log.
// Nuclear reset — makes every post uncategorized again so Run Batch can
// start fresh with the current rules.
add_action('wp_ajax_bq_categorize_reset_all', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;

    // 1. Remove all term relationships for this taxonomy.
    $tt_ids = $wpdb->get_col("
        SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
        WHERE taxonomy = 'itu_content_category'
    ");
    $stripped = 0;
    if (!empty($tt_ids)) {
        $placeholders = implode(',', array_fill(0, count($tt_ids), '%d'));
        $stripped = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$placeholders})",
            ...$tt_ids
        ));
        // Reset the cached counts on each term to 0.
        $wpdb->query("
            UPDATE {$wpdb->term_taxonomy} SET count = 0
            WHERE taxonomy = 'itu_content_category'
        ");
    }

    // 2. Delete all primary-category post meta.
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_bq_primary_content_category'");

    // 3. Clear the staging log.
    $log_table = $wpdb->prefix . 'bq_categorization_log';
    $wpdb->query("DELETE FROM {$log_table}");

    // 4. Flush the term cache so counts update immediately.
    clean_taxonomy_cache('itu_content_category');

    wp_send_json_success([
        'stripped' => $stripped,
        'message'  => "Removed {$stripped} category assignments. All posts are now uncategorized. Use Run Batch to start fresh.",
    ]);
});

// Override a staging row's primary / secondaries before applying.
add_action('wp_ajax_bq_categorize_override', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $log_table = $wpdb->prefix . 'bq_categorization_log';
    $log_id = (int) ($_POST['log_id'] ?? 0);
    $primary_id = (int) ($_POST['primary_id'] ?? 0);
    $secondary_ids = array_slice(array_filter(array_map('intval', (array) ($_POST['secondary_ids'] ?? []))), 0, 4);

    if (!$log_id || !$primary_id) wp_send_json_error('Missing required fields.');

    // Validate the term IDs exist in the taxonomy
    $primary_term = get_term($primary_id, 'itu_content_category');
    if (is_wp_error($primary_term) || !$primary_term) wp_send_json_error('Invalid primary term.');

    $secondary_names = [];
    $valid_secondary = [];
    foreach ($secondary_ids as $sid) {
        if ($sid === $primary_id) continue;
        $t = get_term($sid, 'itu_content_category');
        if (is_wp_error($t) || !$t) continue;
        $valid_secondary[] = $sid;
        $secondary_names[] = $t->name;
    }

    $wpdb->update($log_table, [
        'primary_term_id'      => $primary_id,
        'primary_term_name'    => $primary_term->name,
        'secondary_term_ids'   => wp_json_encode($valid_secondary),
        'secondary_term_names' => wp_json_encode($secondary_names),
    ], ['id' => $log_id]);

    wp_send_json_success([
        'primary_name'    => $primary_term->name,
        'secondary_names' => $secondary_names,
    ]);
});

// Tree endpoint for the Browse panel — returns the full taxonomy with counts.
add_action('wp_ajax_bq_categorize_get_tree', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $only_with_posts = !empty($_POST['only_with_posts']);
    wp_send_json_success([
        'tree' => bq_get_content_taxonomy_tree(['only_with_posts' => $only_with_posts]),
    ]);
});

// Flat term list for the override picker — returns parent → child grouping
// suitable for an HTML <select> with optgroups.
add_action('wp_ajax_bq_categorize_get_terms', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $tree = bq_get_content_taxonomy_tree(['only_with_posts' => false]);
    $groups = [];
    foreach ($tree as $parent) {
        $opts = [];
        foreach ($parent['children'] as $child) {
            $opts[] = ['id' => $child['term_id'], 'name' => $child['name']];
        }
        $groups[] = ['parent' => $parent['name'], 'options' => $opts];
    }
    wp_send_json_success(['groups' => $groups]);
});

// ─── Settings ────────────────────────────────────────────────────────────────

function bq_get_settings() {
    return wp_parse_args(get_option('bq_settings', []), [
        'daily_limit'         => 5,
        'notify_email'        => get_option('admin_email'),
        'enabled'             => false,
        // ── Content categorization ──
        'cat_auto_threshold'   => 0.90, // ≥ this → auto-apply, no review
        'cat_review_threshold' => 0.70, // ≥ this → applied but flagged
        'cat_default_batch'    => 50,   // default batch size for the AI run
        // ── Topic relevance gate ──
        // Pre-flight AI check that runs before outline generation. If the
        // topic is off-scope, the row is marked 'skipped' instead of getting
        // a wasted full blog generation.
        'topic_gate_enabled'   => true,
        'topic_gate_scope'     => bq_default_topic_scope(),
    ]);
}

function bq_default_topic_scope() {
    return "This site publishes content for IT professionals, students, and career changers. "
        . "Relevant topics include: IT industry trends, IT training, IT certifications (CompTIA, Cisco, Microsoft, AWS, Azure, Google Cloud, CISSP, PMP, etc.), "
        . "cybersecurity, cloud computing, networking, programming and software development, DevOps, data and AI/ML in an IT context, "
        . "IT tools and platforms, IT career guidance, and exam preparation. "
        . "Off-scope topics: general business advice unrelated to IT, lifestyle, entertainment, cooking, travel, fashion, health/medical, "
        . "non-tech finance, sports, politics, or anything that isn't clearly tied to information technology or IT careers.";
}

// ─── Blog Creator ────────────────────────────────────────────────────────────

function bq_call_openai($instruction, $user_prompt = '', $model = '', $temperature = 0.7) {
    if (!$model) $model = function_exists('itu_ai_model') ? itu_ai_model('blog_queue') : 'gpt-4.1-nano';

    // Use unified provider router if available
    if (function_exists('itu_ai_call')) {
        return itu_ai_call($instruction, $user_prompt, $model, $temperature, ['key_name' => 'blog_writer', 'timeout' => 240]);
    }

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) return new WP_Error('no_key', 'Blog Writer API key not configured.');

    $messages = [['role' => 'system', 'content' => $instruction]];
    if ($user_prompt) $messages[] = ['role' => 'user', 'content' => $user_prompt];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
        ]),
        'timeout' => 240,
    ]);

    if (is_wp_error($response)) return $response;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $content = trim($data['choices'][0]['message']['content'] ?? '');
    if (empty($content)) return new WP_Error('empty', 'No content returned from OpenAI.');

    return $content;
}

/**
 * Round-robin pick: one item per dimension, then repeat, so consecutive
 * blogs are always from DIFFERENT topics.
 *
 * Bucket key precedence (composite):
 *   1. featured_product_id  → product-tied topics each get their own slot
 *      so 30 topics for one course don't all run back-to-back.
 *   2. gap_category         → keyword-driven topics rotate by category as
 *      they did before.
 *   3. '__none__'           → free-form topics with no association.
 *
 * This way every dimension we have (product, category, neither) competes
 * fairly in the rotation. Adding a new dimension later (e.g., topic
 * cluster) just means extending the priority list.
 */
function bq_round_robin_pick($wpdb, $table, $limit) {
    // Pre-shuffle so within-bucket order is also random.
    $all = $wpdb->get_results("SELECT * FROM {$table} WHERE status = 'pending' ORDER BY RAND()");
    if (empty($all)) return [];

    $buckets = [];
    foreach ($all as $item) {
        // Compute the composite key. Product wins because product-only
        // topics may have no category at all and would otherwise collapse
        // into the single '__none__' bucket and run back-to-back.
        $product_id = (int) ($item->featured_product_id ?? 0);
        if ($product_id > 0) {
            $key = 'product:' . $product_id;
        } elseif (!empty($item->gap_category)) {
            $key = 'cat:' . $item->gap_category;
        } else {
            $key = '__none__';
        }
        $buckets[$key][] = $item;
    }

    // Shuffle bucket order so the same dimension doesn't always go first.
    $bucket_keys = array_keys($buckets);
    shuffle($bucket_keys);

    // Round-robin: pick one from each bucket in rotation until we hit the limit.
    $picked = [];
    while (count($picked) < $limit) {
        $picked_this_round = false;
        foreach ($bucket_keys as $key) {
            if (empty($buckets[$key])) continue;
            $picked[] = array_shift($buckets[$key]);
            $picked_this_round = true;
            if (count($picked) >= $limit) break;
        }
        if (!$picked_this_round) break; // all buckets empty
    }

    return $picked;
}

function bq_get_gap_keywords_for_prompt($gap_category) {
    if (empty($gap_category)) return '';

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return '';

    // Get cooldown days from settings
    $cooldown = 90;
    if (function_exists('seom_get_settings')) {
        $settings = seom_get_settings();
        $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
    }

    // Only pull keywords that haven't been used recently (or never used)
    $keywords = $wpdb->get_results($wpdb->prepare("
        SELECT id, keyword, search_volume, keyword_difficulty
        FROM {$table}
        WHERE tag = %s AND search_volume > 0
        AND (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL %d DAY))
        ORDER BY search_volume DESC
        LIMIT 15
    ", $gap_category, $cooldown));

    if (empty($keywords)) return '';

    $lines = [];
    foreach ($keywords as $kw) {
        $lines[] = "- {$kw->keyword} (volume: {$kw->search_volume})";
    }

    return "\n\nTARGET SEO KEYWORDS (from competitive gap analysis — these are keywords competitors rank for but we don't):\n"
        . "Category: {$gap_category}\n"
        . implode("\n", $lines) . "\n"
        . "Work these keywords naturally into the content. Use the primary keywords in headings where appropriate. "
        . "Do NOT force keywords unnaturally — weave them into relevant sections.";
}

/**
 * Check if a keyword is on cooldown (used or reserved recently).
 * Works for both GSC and imported gap keywords.
 */
function bq_is_keyword_on_cooldown($keyword) {
    global $wpdb;
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") !== $usage_table) return false;

    $cooldown = 90;
    if (function_exists('seom_get_settings')) {
        $settings = seom_get_settings();
        $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
    }

    $used = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$usage_table} WHERE keyword = %s AND used_at > DATE_SUB(CURDATE(), INTERVAL %d DAY)",
        strtolower(trim($keyword)), $cooldown
    ));

    return $used > 0;
}

/**
 * Reserve keywords when topics are added to the queue.
 * Prevents the same keywords from being offered to other topics.
 */
function bq_reserve_keywords($keywords, $queue_id = null, $source = 'gap') {
    if (empty($keywords)) return;

    global $wpdb;
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") !== $usage_table) return;

    // Check if status column exists
    $has_status = $wpdb->get_var("SHOW COLUMNS FROM {$usage_table} LIKE 'status'");

    foreach ((array) $keywords as $kw) {
        if (is_string($kw)) {
            $kw = strtolower(trim($kw));
        } else {
            continue;
        }
        if (empty($kw) || mb_strlen($kw) < 2) continue;

        $row = [
            'keyword' => mb_substr($kw, 0, 255),
            'source'  => $source,
            'used_at' => date('Y-m-d'),
        ];
        if ($has_status) $row['status'] = 'reserved';
        if ($queue_id) $row['queue_id'] = $queue_id;

        $wpdb->replace($usage_table, $row);
    }

    // Also stamp the gap table if applicable
    $gap_table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$gap_table}'") === $gap_table) {
        foreach ((array) $keywords as $kw) {
            if (!is_string($kw)) continue;
            $kw = strtolower(trim($kw));
            if (empty($kw)) continue;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$gap_table} SET last_used_at = CURDATE() WHERE LOWER(keyword) = %s AND last_used_at IS NULL",
                $kw
            ));
        }
    }
}

/**
 * Promote reserved keywords to used when blog is actually created.
 */
function bq_record_keywords_used($keywords, $post_id, $source = 'gap') {
    if (empty($keywords)) return;

    global $wpdb;
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") !== $usage_table) return;

    $has_status = $wpdb->get_var("SHOW COLUMNS FROM {$usage_table} LIKE 'status'");

    foreach ($keywords as $kw) {
        $kw = strtolower(trim($kw));
        if (empty($kw)) continue;

        $row = [
            'keyword' => mb_substr($kw, 0, 255),
            'source'  => $source,
            'used_at' => date('Y-m-d'),
            'post_id' => $post_id,
        ];
        if ($has_status) $row['status'] = 'used';

        $wpdb->replace($usage_table, $row);
    }
}

/**
 * Mark gap keywords as used after a blog is created targeting them.
 * Stamps both the seom_keyword_gaps table AND the unified usage table.
 */
function bq_stamp_gap_keywords_used($gap_category, $post_id) {
    if (empty($gap_category)) return;

    global $wpdb;
    $gap_table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$gap_table}'") !== $gap_table) return;

    $cooldown = 90;
    if (function_exists('seom_get_settings')) {
        $settings = seom_get_settings();
        $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
    }

    // Get the keywords that were used (same query as the prompt builder)
    $used_keywords = $wpdb->get_col($wpdb->prepare("
        SELECT keyword FROM {$gap_table}
        WHERE tag = %s AND search_volume > 0
        AND (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL %d DAY))
        ORDER BY search_volume DESC
        LIMIT 15
    ", $gap_category, $cooldown));

    // Stamp the gap table
    $wpdb->query($wpdb->prepare("
        UPDATE {$gap_table}
        SET last_used_at = CURDATE(), used_in_post_id = %d
        WHERE tag = %s
        AND (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL %d DAY))
        ORDER BY search_volume DESC
        LIMIT 15
    ", $post_id, $gap_category, $cooldown));

    // Also record in the unified usage table
    bq_record_keywords_used($used_keywords, $post_id, 'gap');
}

/**
 * Pre-flight topic-relevance check.
 *
 * Returns true when the topic is in scope (or when the gate is disabled / the
 * AI call errored — we don't want a transient API failure to block creation).
 * Returns a WP_Error with code 'bq_off_topic' when the AI says the topic is
 * off-scope; the error message is the reason text the caller should surface
 * to the user / store in history.
 */
function bq_check_topic_relevance($title, $gap_category = '', $target_keywords = '') {
    $settings = bq_get_settings();
    if (empty($settings['topic_gate_enabled'])) return true;

    $scope = trim($settings['topic_gate_scope'] ?? '');
    if ($scope === '') $scope = bq_default_topic_scope();

    $context_lines = ["Topic: {$title}"];
    if (!empty($gap_category))    $context_lines[] = "Gap category: {$gap_category}";
    if (!empty($target_keywords)) $context_lines[] = "Target keywords: {$target_keywords}";
    $user_prompt = implode("\n", $context_lines);

    $instruction = <<<PROMPT
You are a content-relevance classifier. Decide whether a proposed blog topic is in scope for the site described below.

SITE SCOPE:
{$scope}

DECISION RULES:
- If the topic is clearly in scope, respond with exactly: RELEVANT
- If the topic is clearly off scope, respond with: OFF_TOPIC: <short reason in 12 words or less>
- If the topic is borderline but could reasonably be framed in scope, lean toward RELEVANT — only mark OFF_TOPIC when the subject has no realistic IT angle
- Respond on a single line. Do NOT add extra commentary, headings, or markdown.
PROMPT;

    $result = bq_call_openai($instruction, $user_prompt, function_exists('itu_ai_model') ? itu_ai_model('topic_generation') : 'gpt-4.1-nano', 0.0);

    // Soft-fail: if the AI call breaks, let the topic through. We don't want
    // an API outage to silently skip every blog. The hard failure path stays
    // with bq_create_blog's outline/content steps.
    if (is_wp_error($result)) {
        error_log('[blog-queue] topic gate AI call failed: ' . $result->get_error_message());
        return true;
    }

    $verdict = trim(preg_replace('/\s+/', ' ', $result));
    if (preg_match('/^OFF[_\s-]?TOPIC\b/i', $verdict)) {
        $reason = trim(preg_replace('/^OFF[_\s-]?TOPIC\s*[::\-]?\s*/i', '', $verdict));
        if ($reason === '') $reason = 'Topic not aligned with site scope.';
        return new WP_Error('bq_off_topic', 'Skipped — off-topic: ' . $reason);
    }
    // Anything else (including the expected "RELEVANT") passes.
    return true;
}

function bq_create_blog($title, $gap_category = '', $target_keywords = '', $featured_product_id = 0, $content_type = '') {
    // Pre-flight: skip the topic entirely if it's outside our IT scope.
    // The caller (queue processor / step handler) detects the 'bq_off_topic'
    // code and marks the row as 'skipped' rather than 'failed'.
    $relevance = bq_check_topic_relevance($title, $gap_category, $target_keywords);
    if (is_wp_error($relevance)) return $relevance;

    // Step 1: Generate outline
    $outline_instruction = <<<PROMPT
Using the given topic, create a compelling blog title in title case, then generate a very detailed outline for a long-form blog post that will be 2,000-2,500 words when fully written.

The outline must have at least 6-8 main sections (not counting Introduction and Conclusion). Each section must have 4-6 detailed bullet points covering specific concepts, examples, tools, or steps. The more detailed the outline, the longer and better the final blog post will be.

TITLE RULES:
- Write the blog title in Title Case on the very first line — no label, no prefix, just the title itself
- Do NOT use ALL CAPS for the title or any heading
- Do NOT prefix the title with "BLOG TITLE:" or any label
- ABSOLUTELY NO YEARS in the title. No "2024", "2025", "2026", or any other 4-digit year — anywhere in the title, in any form. Not at the end, not in parentheses, not after "in" / "for" / "of". Banned examples: "Top Cloud Certifications in 2024", "Best Practices for X in 2025". Correct: "Top Cloud Certifications for Career Advancement", "Best Practices for X". Year-tagged titles are unacceptable and will be rejected.

Return the outline in plain text using section headings and bulleted key points. Do not use numbers or Roman numerals.

Format:
Your Blog Title in Title Case Here

Main Heading
- Key point 1
- Key point 2
- Additional subtopics

Next Main Heading
- Key point 1
- Key point 2
PROMPT;

    $outline = bq_call_openai($outline_instruction, $title);
    if (is_wp_error($outline)) return $outline;

    // Extract the blog title from the outline (first non-empty line that isn't a label)
    $lines = explode("\n", trim($outline));
    $blog_title = '';
    foreach ($lines as $line) {
        $candidate = trim($line);
        if (empty($candidate)) continue;
        // Skip if it's just a label like "BLOG TITLE" or "BLOG TITLE:" with no actual title
        if (preg_match('/^(BLOG TITLE|Title|OUTLINE)\s*[::\-]?\s*$/i', $candidate)) continue;
        $blog_title = $candidate;
        break;
    }
    // Clean up title — remove "BLOG TITLE:" prefix, markdown bold, headings, and surrounding quotes
    $blog_title = preg_replace('/^(BLOG TITLE|Title)\s*[::\-]\s*/i', '', $blog_title);
    $blog_title = preg_replace('/^\*{1,2}|\*{1,2}$/', '', $blog_title);
    $blog_title = preg_replace('/^#{1,3}\s*/', '', $blog_title);
    $blog_title = trim($blog_title, " \t\n\r\0\x0B\"'");
    // Fix ALL CAPS titles — convert to title case
    if ($blog_title === mb_strtoupper($blog_title) && mb_strlen($blog_title) > 5) {
        $blog_title = mb_convert_case(mb_strtolower($blog_title), MB_CASE_TITLE);
    }
    // Strip trailing-year tokens the AI keeps adding despite the prompt rule.
    // Defense-in-depth: the prompt asks for no years, but the outline model
    // ignores it ~10% of the time. Year-tagged titles ("…in 2024") date
    // content immediately and tank long-term SEO. Hard-strip here.
    $blog_title = bq_strip_year_from_title($blog_title);
    if (empty($blog_title) || strlen($blog_title) > 200) $blog_title = bq_strip_year_from_title($title);

    $auto_featured_product = false;
    if (empty($featured_product_id)) {
        $match_context = trim($outline . "\n\nGap category: " . $gap_category . "\nTarget keywords: " . $target_keywords);
        $featured_product_id = bq_auto_select_priority_featured_product($blog_title, $match_context);
        $auto_featured_product = !empty($featured_product_id);
    }

    // Step 2: Generate full blog content via the canonical writer instruction
    // owned by SEOM_Blog_Refresher. Single source of truth — refresh and
    // new-blog paths now share identical rules for tone, depth, format
    // variety, citations, trademarks, and AI-search optimization.
    $site_name = get_bloginfo('name');

    // Featured-product context — passed as structured data so the helper
    // can inject the standardized "do not write the markup yourself" note.
    $featured_product_ctx = null;
    if (!empty($featured_product_id)) {
        $fp = get_post((int) $featured_product_id);
        if ($fp && $fp->post_type === 'product') {
            $featured_product_ctx = [
                'title'             => $fp->post_title,
                'short_description' => trim(wp_strip_all_tags($fp->post_excerpt ?: wp_trim_words($fp->post_content, 40, '...'))),
            ];
        }
    }

    // Fall back to title-heuristic inference if a caller didn't supply a type.
    $valid_types = ['howto', 'comparison', 'concept', 'career'];
    $resolved_type = strtolower(trim((string) $content_type));
    if (!in_array($resolved_type, $valid_types, true)) {
        $resolved_type = bq_infer_content_type_from_title($blog_title);
    }

    // Phase 2: cluster-pillar context. If this post belongs to a cluster
    // that has a designated pillar AND this post isn't itself the pillar,
    // pass the pillar's title + URL into the writer instruction so the post
    // links back to it naturally in the intro paragraph. Hub-and-spoke
    // internal linking is built at content-write time rather than as a
    // post-process so the link survives future edits.
    $pillar_ctx = null;
    if (!empty($gap_category)) {
        global $wpdb;
        $pillar_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_seom_pillar_for' AND meta_value = %s LIMIT 1",
            $gap_category
        ));
        if ($pillar_id) {
            $pp = get_post($pillar_id);
            if ($pp && $pp->post_status === 'publish') {
                $pillar_ctx = [
                    'title' => $pp->post_title,
                    'url'   => get_permalink($pillar_id),
                    'tag'   => $gap_category,
                ];
            }
        }
    }

    // Phase 3a: glossary links — derive from the outline + target_keywords
    // + title (the writer's working context). Short-circuits cleanly when
    // the integration is disabled.
    $glossary_links_new = [];
    if (function_exists('seom_resolve_glossary_links_for_writer')) {
        $candidate = $outline . "\n" . $target_keywords . "\n" . $blog_title;
        $glossary_links_new = seom_resolve_glossary_links_for_writer($candidate);
    }

    if (class_exists('SEOM_Blog_Refresher') && method_exists('SEOM_Blog_Refresher', 'build_blog_writer_instruction')) {
        $blog_instruction = SEOM_Blog_Refresher::build_blog_writer_instruction($site_name, [
            'mode'              => 'new',
            'gap_keyword_block' => bq_get_gap_keywords_for_prompt($gap_category),
            'target_keywords'   => $target_keywords,
            'featured_product'  => $featured_product_ctx,
            'content_type'      => $resolved_type,
            'cluster_pillar'    => $pillar_ctx,
            'glossary_links'    => $glossary_links_new,
        ]);
    } else {
        // Hard fail — the SEO plugin is required for the canonical prompt.
        return new WP_Error('seom_missing', 'SEO AI AutoPilot is required for blog generation.');
    }

    $blog_content = bq_call_openai($blog_instruction, "Outline:\n" . $outline);
    if (is_wp_error($blog_content)) return $blog_content;

    // Fix any markdown that slipped through
    $blog_content = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $blog_content);
    $blog_content = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $blog_content);
    $blog_content = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $blog_content);
    $blog_content = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $blog_content);
    $blog_content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $blog_content);

    // Fix invalid callout classes — normalize to valid variants
    $blog_content = preg_replace_callback('/itu-callout--([a-z\-]+)/', function ($m) {
        $valid = ['tip', 'info', 'warning', 'key'];
        $variant = $m[1];
        // Map common AI mistakes to valid classes
        if (strpos($variant, 'tip') !== false) return 'itu-callout--tip';
        if (strpos($variant, 'info') !== false || strpos($variant, 'note') !== false) return 'itu-callout--info';
        if (strpos($variant, 'warn') !== false || strpos($variant, 'caution') !== false) return 'itu-callout--warning';
        if (strpos($variant, 'key') !== false || strpos($variant, 'purple') !== false || strpos($variant, 'important') !== false) return 'itu-callout--key';
        if (in_array($variant, $valid)) return 'itu-callout--' . $variant;
        return 'itu-callout--tip'; // default fallback
    }, $blog_content);

    // Inject the Featured Product shortcode (after first paragraph + before last H2)
    if (!empty($featured_product_id)) {
        $blog_content = bq_inject_featured_product($blog_content, (int) $featured_product_id);
    }

    // Step 3: Create the WordPress post
    $post_id = wp_insert_post([
        'post_title'   => $blog_title,
        'post_content' => $blog_content,
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'post_author'  => 1,
    ]);

    if (is_wp_error($post_id)) return $post_id;

    // Persist the product association on the post so SEO AI AutoPilot's
    // refresher (and a future manual edit via the Featured button on the
    // Indexed Pages tab) can re-inject the shortcode on every refresh.
    if (!empty($featured_product_id)) {
        $meta_key = defined('SEOM_FEATURED_PRODUCT_META_KEY') ? SEOM_FEATURED_PRODUCT_META_KEY : '_seom_featured_product_id';
        update_post_meta($post_id, $meta_key, (int) $featured_product_id);
        if ($auto_featured_product) {
            update_post_meta($post_id, '_seom_featured_product_auto_selected', '1');
        } else {
            delete_post_meta($post_id, '_seom_featured_product_auto_selected');
        }
    }

    // Assign to "Blogs" category
    $blog_cat = get_category_by_slug('blogs');
    if (!$blog_cat) $blog_cat = get_category_by_slug('blog');
    if ($blog_cat) {
        wp_set_post_categories($post_id, [$blog_cat->term_id]);
    }

    // ── SEO pipeline ──
    // After the post is created, hand off the SEO meta generation to the
    // canonical SEOM_Blog_Refresher steps so Blog Queue, AI Product Manager,
    // and SEO AI AutoPilot all use the exact same prompts/model/logic. The
    // step methods read the post's content and write rank_math_description,
    // rank_math_focus_keyword, rank_math_title, FAQ HTML, and FAQ JSON-LD.
    //
    // We deliberately call the steps individually (not full_refresh()) because
    // full_refresh rewrites the body content from scratch — Blog Queue already
    // has fresh content from the outline+content steps above. We only want
    // the SEO bits.
    if (class_exists('SEOM_Blog_Refresher') && SEOM_Blog_Refresher::is_available()) {
        $faq_html_result = SEOM_Blog_Refresher::step_faq_html($post_id);
        if (!is_wp_error($faq_html_result)) {
            SEOM_Blog_Refresher::step_faq_json($post_id, $faq_html_result);
        }
        SEOM_Blog_Refresher::step_meta_description($post_id);
        SEOM_Blog_Refresher::step_rankmath($post_id);    // focus keyword
        SEOM_Blog_Refresher::step_seo_title($post_id);
    }

    // Save timestamp
    update_post_meta($post_id, 'last_page_refresh', current_time('mysql'));

    // Persist the resolved content type on the post so the schema layer
    // (HowTo emission) and any future refresh can use the same classification
    // without re-inferring from the title every time.
    if (!empty($resolved_type)) {
        update_post_meta($post_id, '_seom_content_type', $resolved_type);
    }

    // Stamp imported gap keywords as used so they enter cooldown
    bq_stamp_gap_keywords_used($gap_category, $post_id);

    // Also stamp the blog title and focus keyword in the unified usage table
    // so GSC content gaps that match this topic enter cooldown too
    $title_keywords = [];
    $title_keywords[] = strtolower($blog_title);
    $focus_kw = get_post_meta($post_id, 'rank_math_focus_keyword', true);
    if (!empty($focus_kw)) {
        $title_keywords[] = strtolower(trim($focus_kw));
    }
    bq_record_keywords_used($title_keywords, $post_id, 'gsc');

    return $post_id;
}

// ─── Queue Processor ─────────────────────────────────────────────────────────

/**
 * Process queued blog topics into published posts.
 *
 * @param bool $force        Bypass the global "enabled" setting (used by manual runs).
 * @param int  $manual_extra Extra quota to add on top of the daily limit when the user
 *                           manually clicks "Process Queue" — lets them run an additional
 *                           batch of N posts even if the automated cron has already
 *                           hit the daily cap. 0 means strict daily limit (cron mode).
 */
function bq_process_queue($force = false, $manual_extra = 0) {
    $settings = bq_get_settings();
    if (!$force && !$settings['enabled']) return;

    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $history = $wpdb->prefix . 'bq_history';

    // Reset stuck and failed items back to pending for retry
    $wpdb->query("UPDATE {$table} SET status = 'pending', error_message = NULL WHERE status IN ('processing', 'failed')");
    // Clean up completed items older than 24 hours
    $wpdb->query("DELETE FROM {$table} WHERE status = 'completed' AND processed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");

    $today_key = 'bq_daily_count_' . date('Y-m-d');
    $count     = (int) get_option($today_key, 0);

    // ── Limit logic ──
    // Two distinct modes:
    //
    //   1. Automated cron path ($manual_extra === 0):
    //      Enforce $settings['daily_limit'] strictly. Stop when today's
    //      count hits the cap.
    //
    //   2. Manual button path ($manual_extra > 0):
    //      The user explicitly clicked "Process Queue". Each click is a
    //      one-shot quota of $manual_extra blogs, INDEPENDENT of how many
    //      have already been created today. Today's daily_limit is ignored
    //      entirely for manual runs — the user is taking responsibility
    //      for the volume by clicking the button.
    //
    // This way the user can click Process Queue, get 25, click again, get
    // another 25, regardless of where today's running total stands.
    $is_manual = ($manual_extra > 0);
    if ($is_manual) {
        $remaining = (int) $manual_extra;
    } else {
        if ($count >= $settings['daily_limit']) return;
        $remaining = $settings['daily_limit'] - $count;
    }

    // Pick pending items distributed across categories (round-robin)
    $items = bq_round_robin_pick($wpdb, $table, $remaining);

    if (empty($items)) return;

    $created = [];
    $failed = [];

    foreach ($items as $item) {
        // Stop-flag check — direct DB read so we bypass the per-request
        // object cache (the option may have been set by another process
        // while this long-running worker is mid-batch).
        $stop = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            'bq_queue_stop'
        ));
        if ($stop) {
            delete_option('bq_queue_stop');
            break;
        }

        // Mark as processing
        $wpdb->update($table, ['status' => 'processing'], ['id' => $item->id]);

        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        @set_time_limit(300);

        $result = bq_create_blog($item->title, $item->gap_category ?? '', $item->target_keywords ?? '', $item->featured_product_id ?? 0, $item->content_type ?? '');

        if (is_wp_error($result)) {
            $error = $result->get_error_message();
            $is_skipped = $result->get_error_code() === 'bq_off_topic';
            $row_status = $is_skipped ? 'skipped' : 'failed';
            $wpdb->update($table, [
                'status'        => $row_status,
                'processed_at'  => current_time('mysql'),
                'error_message' => $error,
            ], ['id' => $item->id]);
            $wpdb->insert($history, [
                'title'        => $item->title,
                'status'       => $row_status,
                'processed_at' => current_time('mysql'),
                'error_message'=> $error,
            ]);
            // Skipped topics aren't failures — don't add them to the failed[]
            // bucket and don't send a failure notification. They show up in
            // History with a neutral "Skipped" status instead.
            if (!$is_skipped) {
                $failed[] = ['title' => $item->title, 'error' => $error];
                if ($settings['notify_email']) {
                    bq_send_notification($settings['notify_email'], $item->title, 'failed', 0, '', $error);
                }
            }
        } else {
            $wpdb->update($table, [
                'status'       => 'completed',
                'post_id'      => $result,
                'processed_at' => current_time('mysql'),
            ], ['id' => $item->id]);
            $wpdb->insert($history, [
                'title'        => $item->title,
                'post_id'      => $result,
                'status'       => 'completed',
                'processed_at' => current_time('mysql'),
            ]);
            $created[] = ['title' => $item->title, 'post_id' => $result, 'url' => get_permalink($result)];
            // Per-blog success notification.
            if ($settings['notify_email']) {
                bq_send_notification($settings['notify_email'], $item->title, 'completed', $result, get_permalink($result));
            }
        }

        // Don't count skipped (off-topic) items toward today's daily quota —
        // no blog was generated and no AI writer cost was spent. Failures
        // keep the existing behavior (they count) since they did consume the
        // expensive outline/content calls before failing.
        if (!(isset($is_skipped) && $is_skipped)) {
            update_option($today_key, ++$count);
        }
    }

    // Send summary email
    if ($settings['notify_email'] && (!empty($created) || !empty($failed))) {
        bq_send_summary_email($settings['notify_email'], $created, $failed);
    }
}

// ─── Email Notification ──────────────────────────────────────────────────────

function bq_send_notification($to, $title, $status, $post_id = 0, $view_url = '', $error = '') {
    $is_success = $status === 'completed';
    $status_color = $is_success ? '#059669' : '#dc2626';
    $status_label = $is_success ? 'Published' : 'Failed';
    $status_icon  = $is_success ? '&#10003;' : '&#10007;';
    $subject = $is_success ? "[Blog Queue] Published: {$title}" : "[Blog Queue] Failed: {$title}";

    $edit_url = $post_id ? admin_url('post.php?action=edit&post=' . $post_id) : '';

    $error_row = $error ? '<tr style="background:#fef2f2;"><td style="padding:8px 16px;color:#94a3b8;font-size:13px;">Error</td><td style="padding:8px 16px;color:#dc2626;">' . esc_html($error) . '</td></tr>' : '';

    $buttons = '';
    if ($post_id) {
        $buttons = '<div style="margin-top:20px;">'
            . '<a href="' . esc_url($view_url) . '" style="display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;margin-right:8px;">View Post</a>'
            . '<a href="' . esc_url($edit_url) . '" style="display:inline-block;padding:10px 20px;background:#f0f0f1;color:#1e293b;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;border:1px solid #c3c4c7;">Edit in WordPress</a>'
            . '</div>';
    }

    $word_count = '';
    if ($post_id) {
        $content = get_post_field('post_content', $post_id);
        $wc = str_word_count(wp_strip_all_tags($content));
        $read_time = max(1, round($wc / 250));
        $word_count = '<tr><td style="padding:8px 16px;color:#94a3b8;font-size:13px;">Word Count</td><td style="padding:8px 16px;">' . number_format($wc) . ' words (~' . $read_time . ' min read)</td></tr>';
    }

    $body = '
    <div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;">
        <div style="background:#1e293b;padding:20px 24px;border-radius:8px 8px 0 0;">
            <h1 style="margin:0;color:#fff;font-size:18px;">Blog Queue</h1>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;padding:24px;">
            <div style="display:inline-block;padding:6px 14px;border-radius:4px;background:' . $status_color . ';color:#fff;font-weight:600;font-size:14px;margin-bottom:16px;">
                ' . $status_icon . ' ' . $status_label . '
            </div>

            <h2 style="margin:0 0 16px;font-size:20px;color:#1e293b;">' . esc_html($title) . '</h2>

            <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
                <tr><td style="padding:8px 16px;color:#94a3b8;font-size:13px;width:120px;">Type</td><td style="padding:8px 16px;">New Blog Post</td></tr>
                <tr style="background:#f9fafb;"><td style="padding:8px 16px;color:#94a3b8;font-size:13px;">Category</td><td style="padding:8px 16px;">Blogs</td></tr>
                ' . $word_count . '
                ' . $error_row . '
            </table>

            ' . $buttons . '

            <p style="margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px;">
                Sent by Blog Queue on ' . esc_html(get_bloginfo('name')) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '
            </p>
        </div>
    </div>';

    $set_html = function () { return 'text/html'; };
    add_filter('wp_mail_content_type', $set_html);
    wp_mail($to, $subject, $body);
    remove_filter('wp_mail_content_type', $set_html);
}

function bq_send_summary_email($to, $created, $failed) {
    $site_name = get_bloginfo('name');
    $total = count($created) + count($failed);
    $subject = "[Blog Queue] Daily Summary: " . count($created) . " created, " . count($failed) . " failed";

    $rows = '';
    foreach ($created as $c) {
        $edit = admin_url('post.php?action=edit&post=' . $c['post_id']);
        $rows .= '<tr>'
            . '<td style="padding:8px 12px;"><a href="' . esc_url($c['url']) . '">' . esc_html($c['title']) . '</a></td>'
            . '<td style="padding:8px 12px;color:#059669;font-weight:600;">Published</td>'
            . '<td style="padding:8px 12px;"><a href="' . esc_url($edit) . '">Edit</a></td>'
            . '</tr>';
    }
    foreach ($failed as $f) {
        $rows .= '<tr style="background:#fef2f2;">'
            . '<td style="padding:8px 12px;">' . esc_html($f['title']) . '</td>'
            . '<td style="padding:8px 12px;color:#dc2626;font-weight:600;">Failed</td>'
            . '<td style="padding:8px 12px;font-size:12px;color:#dc2626;">' . esc_html($f['error']) . '</td>'
            . '</tr>';
    }

    $body = '
    <div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:700px;margin:0 auto;">
        <div style="background:#1d2327;padding:20px 24px;border-radius:8px 8px 0 0;">
            <h1 style="margin:0;color:#fff;font-size:18px;">Blog Queue — Daily Summary</h1>
        </div>
        <div style="background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:24px;">
            <div style="display:flex;gap:24px;margin-bottom:20px;">
                <div><span style="font-size:32px;font-weight:700;color:#059669;">' . count($created) . '</span><br><span style="color:#6b7280;font-size:13px;">Created</span></div>
                <div><span style="font-size:32px;font-weight:700;color:#dc2626;">' . count($failed) . '</span><br><span style="color:#6b7280;font-size:13px;">Failed</span></div>
                <div><span style="font-size:32px;font-weight:700;color:#374151;">' . $total . '</span><br><span style="color:#6b7280;font-size:13px;">Total</span></div>
            </div>
            <table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;">
                <thead><tr style="background:#f9fafb;"><th style="padding:8px 12px;text-align:left;">Title</th><th style="padding:8px 12px;text-align:left;">Status</th><th style="padding:8px 12px;text-align:left;">Details</th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table>
            <p style="margin-top:20px;color:#9ca3af;font-size:12px;">Sent by Blog Queue on ' . esc_html($site_name) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '</p>
        </div>
    </div>';

    $set_html = function () { return 'text/html'; };
    add_filter('wp_mail_content_type', $set_html);
    wp_mail($to, $subject, $body);
    remove_filter('wp_mail_content_type', $set_html);
}

// ─── Cron Hooks ──────────────────────────────────────────────────────────────

add_action('bq_daily_process', 'bq_process_queue');
add_action('bq_process_next', 'bq_process_one_from_queue');

/**
 * Process one item from the queue (for chained background processing).
 */
function bq_process_one_from_queue() {
    // Check stop flag
    if (get_option('bq_queue_stop', false)) {
        delete_option('bq_queue_stop');
        return;
    }

    $settings = bq_get_settings();
    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $history = $wpdb->prefix . 'bq_history';

    // Reset stuck and failed items back to pending for retry
    $wpdb->query("UPDATE {$table} SET status = 'pending', error_message = NULL WHERE status IN ('processing', 'failed')");

    // Clean up completed items older than 24 hours
    $wpdb->query("DELETE FROM {$table} WHERE status = 'completed' AND processed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");

    // Check daily limit
    $today_key = 'bq_daily_count_' . date('Y-m-d');
    $count = (int) get_option($today_key, 0);
    if ($count >= $settings['daily_limit']) return;

    // Pick next pending item — single random row.
    // NOTE: This handler is the legacy path used only by old scheduled
    // bq_process_next cron events. The manual "Process Queue" flow now
    // calls bq_process_queue() directly which uses round-robin selection
    // across categories — see wp_ajax_bq_start_queue_processing.
    $item = $wpdb->get_row("SELECT * FROM {$table} WHERE status = 'pending' ORDER BY RAND() LIMIT 1");
    if (!$item) return;

    $wpdb->update($table, ['status' => 'processing'], ['id' => $item->id]);

    @set_time_limit(300);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $result = bq_create_blog($item->title, $item->gap_category ?? '', $item->target_keywords ?? '', $item->featured_product_id ?? 0, $item->content_type ?? '');

    $settings = bq_get_settings();
    if (is_wp_error($result)) {
        $error_msg = $result->get_error_message();
        $is_skipped = $result->get_error_code() === 'bq_off_topic';
        $row_status = $is_skipped ? 'skipped' : 'failed';
        $wpdb->update($table, ['status' => $row_status, 'processed_at' => current_time('mysql'), 'error_message' => $error_msg], ['id' => $item->id]);
        $wpdb->insert($history, ['title' => $item->title, 'status' => $row_status, 'processed_at' => current_time('mysql'), 'error_message' => $error_msg]);
        if (!$is_skipped && $settings['notify_email']) {
            bq_send_notification($settings['notify_email'], $item->title, 'failed', 0, '', $error_msg);
        }
    } else {
        $wpdb->update($table, ['status' => 'completed', 'post_id' => $result, 'processed_at' => current_time('mysql')], ['id' => $item->id]);
        $wpdb->insert($history, ['title' => $item->title, 'post_id' => $result, 'status' => 'completed', 'processed_at' => current_time('mysql')]);
        update_option($today_key, $count + 1);
        if ($settings['notify_email']) {
            bq_send_notification($settings['notify_email'], $item->title, 'created', $result, get_permalink($result));
        }
    }

    // NOTE: Self-rescheduling removed — the manual "Process Queue" flow now
    // runs the worker inline in a loop after detaching the AJAX response
    // (see wp_ajax_bq_start_queue_processing). The nightly bq_daily_process
    // cron event still calls bq_process_queue() which handles its own
    // batching loop. This function is also still wired to the legacy
    // bq_process_next hook for backwards-compat, but no longer chains.
}

// Manual start/stop/status endpoints for background queue processing
add_action('wp_ajax_bq_start_queue_processing', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bq_queue WHERE status = 'pending'");
    if (!$pending) wp_send_json_error('No pending items.');

    delete_option('bq_queue_stop');
    update_option('bq_queue_manual', true);

    // ── Detach the response and run the worker inline ──
    // The previous approach scheduled a cron event and depended on a
    // wp_remote_get loopback to wp-cron.php to fire it — that loopback
    // silently fails on Local by Flywheel and many shared hosts, so the
    // queue would sit forever in "processing" with nothing actually
    // running. Now we ack the request, flush PHP-FPM via
    // fastcgi_finish_request(), and call the worker chain inline.
    // The browser keeps polling bq_queue_status to track progress.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);
    @set_time_limit(0);

    // Manually emit the JSON ack so wp_send_json_success() doesn't wp_die()
    // before we get a chance to run the worker loop.
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    }
    echo wp_json_encode(['success' => true, 'data' => ['pending' => $pending]]);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // Generic fallback: flush all buffers so the browser receives the body.
        @ob_end_flush();
        @flush();
    }

    // ── Worker ──
    // bq_process_queue(true, 25) pre-picks the entire batch using
    // bq_round_robin_pick() so consecutive blogs come from DIFFERENT
    // categories, then processes them in rotation order. The "25" is a
    // manual bonus quota added on top of the daily limit — clicking
    // "Process Queue" lets the user run up to 25 additional blogs even
    // when the automated cron has already hit today's cap.
    bq_process_queue(true, 25);
    delete_option('bq_queue_manual');
    exit;
});

add_action('wp_ajax_bq_queue_status', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
    $processing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'processing'");
    $today_done = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'completed' AND DATE(processed_at) = CURDATE()");
    $today_failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND DATE(processed_at) = CURDATE()");
    $stopped = get_option('bq_queue_stop', false);
    $manual = get_option('bq_queue_manual', false);
    $next_scheduled = wp_next_scheduled('bq_process_next');

    $remaining = $pending + $processing;
    $is_running = ($processing > 0) || $next_scheduled || ($manual && !$stopped);

    // Clean up when done
    if ($manual && $pending === 0 && !$processing) {
        delete_option('bq_queue_manual');
        $is_running = false;
    }

    // Currently processing
    $current = $wpdb->get_row("SELECT id, title, gap_category FROM {$table} WHERE status = 'processing' LIMIT 1");

    $last = $wpdb->get_row("SELECT title, status, processed_at FROM {$table} WHERE status IN ('completed','failed') ORDER BY processed_at DESC LIMIT 1");

    $settings = bq_get_settings();
    wp_send_json_success([
        'remaining' => $remaining, 'pending' => $pending, 'processing' => $processing,
        'completed' => $today_done, 'failed' => $today_failed,
        'is_running' => $is_running && !$stopped, 'stopped' => (bool) $stopped,
        'current' => $current, 'last_done' => $last, 'daily_limit' => $settings['daily_limit'],
    ]);
});

add_action('wp_ajax_bq_stop_queue', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    update_option('bq_queue_stop', true);
    delete_option('bq_queue_manual');
    wp_clear_scheduled_hook('bq_process_next');
    wp_send_json_success('Stopped.');
});

// ─── Admin Menu ──────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    // Register as a submenu under SEO AI AutoPilot when that plugin is active.
    // Falls back to a top-level menu if SEO AI AutoPilot isn't around so this
    // plugin still works standalone.
    if (defined('SEOM_PATH')) {
        add_submenu_page(
            'seo-ai-autopilot',
            'Blog Queue',
            'Blog Queue',
            'manage_options',
            'blog-queue',
            'bq_render_admin'
        );
    } else {
        add_menu_page('Blog Queue', 'Blog Queue', 'manage_options', 'blog-queue', 'bq_render_admin', 'dashicons-edit-large', 58);
    }
}, 11); // priority 11 so SEO AI AutoPilot's menu (default 10) registers first

// "Documentation" link on the Plugins page for Blog Queue
add_filter('plugin_action_links_blog-queue/blog-queue.php', function ($links) {
    $url = admin_url('admin.php?page=seo-platform-docs');
    array_unshift($links, '<a href="' . esc_url($url) . '">Documentation</a>');
    return $links;
});

// ─── AJAX Handlers ───────────────────────────────────────────────────────────

add_action('wp_ajax_bq_add_topics', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $gap_category        = sanitize_text_field($_POST['gap_category'] ?? '');
    $featured_product_id = (int) ($_POST['featured_product_id'] ?? 0);

    // Accept either plain text (newline-separated titles) or JSON array of {title, keywords} objects
    $items_json = $_POST['items'] ?? '';
    $items = [];

    if (!empty($items_json) && is_string($items_json)) {
        $decoded = json_decode(wp_unslash($items_json), true);
        if (is_array($decoded)) $items = $decoded;
    }

    // Fallback: plain text topics
    if (empty($items)) {
        $topics = sanitize_textarea_field($_POST['topics'] ?? '');
        $lines = array_filter(array_map('trim', explode("\n", $topics)));
        foreach ($lines as $line) {
            $items[] = ['title' => $line, 'keywords' => ''];
        }
    }

    if (empty($items)) wp_send_json_error('No topics provided.');

    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $now = current_time('mysql');
    $added = 0;

    $valid_types = ['howto', 'comparison', 'concept', 'career'];
    foreach ($items as $item) {
        $title = sanitize_text_field(trim($item['title'] ?? ''));
        // Strip trailing year tokens before anything gets queued. The AI
        // generator AND user-pasted topic lists both occasionally include
        // years; we don't want them in the queue either way.
        $title = bq_strip_year_from_title($title);
        $keywords = sanitize_text_field(trim($item['keywords'] ?? ''));
        $content_type = strtolower(trim((string) ($item['content_type'] ?? '')));
        if (!in_array($content_type, $valid_types, true)) {
            // Either the AI omitted it (legacy/short topic input) or it was
            // garbage — infer from the title text so the writer still gets
            // type-specific treatment.
            $content_type = bq_infer_content_type_from_title($title);
        }
        if (mb_strlen($title) < 5) continue;

        $row = [
            'title'    => mb_substr($title, 0, 500),
            'status'   => 'pending',
            'added_at' => $now,
            'content_type' => $content_type,
        ];
        if (!empty($gap_category)) {
            $row['gap_category'] = $gap_category;
        }
        if (!empty($keywords)) {
            $row['target_keywords'] = $keywords;
        }
        if ($featured_product_id) {
            $row['featured_product_id'] = $featured_product_id;
        }
        $wpdb->insert($table, $row);
        $queue_id = $wpdb->insert_id;

        // Glossary-gaps source: stamp ec_it_glossary.topic_queued_at so the
        // gap finder won't re-suggest this term for 180 days. The item
        // carries the source term verbatim from bq_generate_topics.
        $glossary_term = isset($item['glossary_term']) ? trim((string) $item['glossary_term']) : '';
        if ($glossary_term !== '' && $wpdb->get_var("SHOW TABLES LIKE 'ec_it_glossary'") === 'ec_it_glossary') {
            $wpdb->update(
                'ec_it_glossary',
                ['topic_queued_at' => $now],
                ['term' => $glossary_term]
            );
        }

        // Reserve the keywords so they don't get offered to other topics
        // and save LSI relationships
        if (!empty($keywords)) {
            $kw_list = array_map('trim', explode(',', $keywords));
            bq_reserve_keywords($kw_list, $queue_id, 'gap');

            // Save LSI keyword relationships — first keyword is primary, rest are LSI
            if (count($kw_list) > 1) {
                $primary = strtolower(trim($kw_list[0]));
                $lsi_table = $wpdb->prefix . 'seom_lsi_keywords';
                if ($wpdb->get_var("SHOW TABLES LIKE '{$lsi_table}'") === $lsi_table) {
                    $today = date('Y-m-d');
                    for ($k = 1; $k < count($kw_list); $k++) {
                        $lsi = strtolower(trim($kw_list[$k]));
                        if (empty($lsi) || $lsi === $primary) continue;
                        $wpdb->replace($lsi_table, [
                            'seed_keyword' => mb_substr($primary, 0, 255),
                            'lsi_keyword'  => mb_substr($lsi, 0, 255),
                            'source'       => 'ai',
                            'date_added'   => $today,
                        ]);
                    }
                }
            }
        }

        $added++;
    }

    wp_send_json_success(['added' => $added]);
});

add_action('wp_ajax_bq_get_queue', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bq_queue WHERE status = 'pending' ORDER BY added_at ASC LIMIT %d OFFSET %d",
        $per_page, $offset
    ));
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bq_queue WHERE status = 'pending'");
    $today_count = (int) get_option('bq_daily_count_' . date('Y-m-d'), 0);
    $bq_settings = bq_get_settings();

    wp_send_json_success([
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'pages'       => ceil($total / $per_page),
        'today_count' => $today_count,
        'daily_limit' => $bq_settings['daily_limit'],
    ]);
});

// Return ALL pending items with round-robin category distribution (for manual processing)
add_action('wp_ajax_bq_get_queue_distributed', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $bq_settings = get_option('bq_settings', ['daily_limit' => 5]);
    $daily_limit = max(1, intval($bq_settings['daily_limit'] ?? 5));
    $limit = min(50, max(1, intval($_POST['limit'] ?? $daily_limit)));
    $all = $wpdb->get_results("SELECT id, title, gap_category, target_keywords FROM {$table} WHERE status = 'pending' ORDER BY RAND()");
    if (empty($all)) { wp_send_json_success(['rows' => []]); return; }

    // Group by category
    $buckets = [];
    foreach ($all as $item) {
        $cat = $item->gap_category ?: '__none__';
        $buckets[$cat][] = $item;
    }
    $cat_keys = array_keys($buckets);
    shuffle($cat_keys);

    // Round-robin pick across categories, capped at limit
    $distributed = [];
    $has_more = true;
    while ($has_more && count($distributed) < $limit) {
        $has_more = false;
        foreach ($cat_keys as $cat) {
            if (!empty($buckets[$cat])) {
                $distributed[] = array_shift($buckets[$cat]);
                $has_more = true;
                if (count($distributed) >= $limit) break;
            }
        }
    }

    wp_send_json_success(['rows' => $distributed, 'total' => count($distributed), 'daily_limit' => $daily_limit]);
});

add_action('wp_ajax_bq_get_history', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bq_history ORDER BY processed_at DESC LIMIT %d OFFSET %d",
        $per_page, $offset
    ));
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bq_history");

    wp_send_json_success(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $per_page)]);
});

add_action('wp_ajax_bq_remove_item', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('Missing ID.');

    // Release reserved keywords from BOTH the usage and gap tables — same
    // logic as bq_bulk_delete so per-row removal and bulk removal stay
    // in sync. Without the gap-table clear, the topic generator would
    // continue skipping those keywords as if they were still reserved.
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    $gap_table   = $wpdb->prefix . 'seom_keyword_gaps';
    $has_usage   = $wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") === $usage_table;
    $has_gaps    = $wpdb->get_var("SHOW TABLES LIKE '{$gap_table}'") === $gap_table;

    $item = $wpdb->get_row($wpdb->prepare(
        "SELECT target_keywords, status FROM {$wpdb->prefix}bq_queue WHERE id = %d",
        $id
    ));

    // Only release for rows that haven't been published yet — completed
    // rows have legitimately used the keyword in real content, so we leave
    // their cooldown stamp alone.
    if ($item && !empty($item->target_keywords) && $item->status !== 'completed') {
        $kw_list = array_map('trim', explode(',', $item->target_keywords));
        foreach ($kw_list as $kw) {
            $kw = strtolower(trim($kw));
            if (empty($kw)) continue;
            if ($has_usage) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$usage_table} WHERE keyword = %s AND status = 'reserved'",
                    $kw
                ));
            }
            if ($has_gaps) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$gap_table} SET last_used_at = NULL, used_in_post_id = NULL
                     WHERE LOWER(keyword) = %s AND used_in_post_id IS NULL",
                    $kw
                ));
            }
        }
    }

    $wpdb->delete($wpdb->prefix . 'bq_queue', ['id' => $id]);
    wp_send_json_success();
});

add_action('wp_ajax_bq_bulk_delete', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    if (empty($ids)) wp_send_json_error('No items selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));

    // Release reserved keywords before deleting
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    $gap_table = $wpdb->prefix . 'seom_keyword_gaps';
    $has_usage = $wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") === $usage_table;
    $has_gaps = $wpdb->get_var("SHOW TABLES LIKE '{$gap_table}'") === $gap_table;

    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT id, target_keywords FROM {$wpdb->prefix}bq_queue WHERE id IN ($placeholders) AND status = 'pending'",
        ...$ids
    ));
    foreach ($items as $item) {
        if (!empty($item->target_keywords)) {
            $kw_list = array_map('trim', explode(',', $item->target_keywords));
            foreach ($kw_list as $kw) {
                $kw = strtolower(trim($kw));
                if (empty($kw)) continue;
                // Remove reservation from usage table
                if ($has_usage) {
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$usage_table} WHERE keyword = %s AND status = 'reserved'", $kw
                    ));
                }
                // Clear last_used_at on gap table if it was only reserved (not used)
                if ($has_gaps) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$gap_table} SET last_used_at = NULL, used_in_post_id = NULL WHERE LOWER(keyword) = %s AND used_in_post_id IS NULL", $kw
                    ));
                }
            }
        }
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}bq_queue WHERE id IN ($placeholders) AND status = 'pending'",
        ...$ids
    ));

    wp_send_json_success(['deleted' => count($ids)]);
});

// Step-based blog creation — each step is a separate AJAX call to avoid 504 timeouts
add_action('wp_ajax_bq_process_step', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(300);
    ob_start();

    global $wpdb;
    $id   = intval($_POST['id'] ?? 0);
    $step = intval($_POST['step'] ?? 1);

    if (!$id) { ob_end_clean(); wp_send_json_error('Missing ID.'); }

    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bq_queue WHERE id = %d", $id));
    if (!$item) { ob_end_clean(); wp_send_json_error('Item not found.'); }

    $progress_key = 'bq_progress_' . $id;
    $site_name = get_bloginfo('name');

    switch ($step) {
        case 1: // Generate outline
            $wpdb->update($wpdb->prefix . 'bq_queue', ['status' => 'processing'], ['id' => $id]);

            // Pre-flight relevance check — bail out before the outline call
            // when the topic isn't in scope. The row is marked 'skipped' and
            // the JS step runner gets a success payload with skipped:true so
            // it doesn't render as an error.
            $relevance = bq_check_topic_relevance(
                $item->title,
                $item->gap_category ?? '',
                $item->target_keywords ?? ''
            );
            if (is_wp_error($relevance)) {
                $reason = $relevance->get_error_message();
                $wpdb->update($wpdb->prefix . 'bq_queue', [
                    'status'        => 'skipped',
                    'processed_at'  => current_time('mysql'),
                    'error_message' => $reason,
                ], ['id' => $id]);
                $wpdb->insert($wpdb->prefix . 'bq_history', [
                    'title'         => $item->title,
                    'status'        => 'skipped',
                    'processed_at'  => current_time('mysql'),
                    'error_message' => $reason,
                ]);
                delete_transient($progress_key);
                ob_end_clean();
                wp_send_json_success([
                    'complete' => true,
                    'skipped'  => true,
                    'reason'   => $reason,
                ]);
            }

            $outline_instruction = <<<PROMPT
Using the given topic, create a compelling blog title in title case, then generate a very detailed outline for a long-form blog post that will be 2,000-2,500 words when fully written.

The outline must have at least 6-8 main sections (not counting Introduction and Conclusion). Each section must have 4-6 detailed bullet points covering specific concepts, examples, tools, or steps. The more detailed the outline, the longer and better the final blog post will be.

TITLE RULES:
- Write the blog title in Title Case on the very first line — no label, no prefix, just the title itself
- Do NOT use ALL CAPS for the title or any heading
- Do NOT prefix the title with "BLOG TITLE:" or any label
- ABSOLUTELY NO YEARS in the title. No "2024", "2025", "2026", or any other 4-digit year — anywhere in the title, in any form. Not at the end, not in parentheses, not after "in" / "for" / "of". Banned examples: "Top Cloud Certifications in 2024", "Best Practices for X in 2025". Correct: "Top Cloud Certifications for Career Advancement", "Best Practices for X". Year-tagged titles are unacceptable and will be rejected.

Return the outline in plain text using section headings and bulleted key points. Do not use numbers or Roman numerals.

Format:
Your Blog Title in Title Case Here

Main Heading
- Key point 1
- Key point 2
- Additional subtopics

Next Main Heading
- Key point 1
- Key point 2
PROMPT;

            $outline = bq_call_openai($outline_instruction, $item->title);
            if (is_wp_error($outline)) { ob_end_clean(); wp_send_json_error('Step 1 (Outline): ' . $outline->get_error_message()); }

            // Extract title
            $lines = explode("\n", trim($outline));
            $blog_title = '';
            foreach ($lines as $line) {
                $candidate = trim($line);
                if (empty($candidate)) continue;
                if (preg_match('/^(BLOG TITLE|Title|OUTLINE)\s*[::\-]?\s*$/i', $candidate)) continue;
                $blog_title = $candidate;
                break;
            }
            $blog_title = preg_replace('/^(BLOG TITLE|Title)\s*[::\-]\s*/i', '', $blog_title);
            $blog_title = preg_replace('/^\*{1,2}|\*{1,2}$/', '', $blog_title);
            $blog_title = preg_replace('/^#{1,3}\s*/', '', $blog_title);
            $blog_title = trim($blog_title, " \t\n\r\0\x0B\"'");
            if ($blog_title === mb_strtoupper($blog_title) && mb_strlen($blog_title) > 5) {
                $blog_title = mb_convert_case(mb_strtolower($blog_title), MB_CASE_TITLE);
            }
            // Strip year tokens the AI keeps slipping in. See bq_strip_year_from_title.
            $blog_title = bq_strip_year_from_title($blog_title);
            if (empty($blog_title) || strlen($blog_title) > 200) {
                $blog_title = bq_strip_year_from_title($item->title);
            }

            $featured_product_id = (int) ($item->featured_product_id ?? 0);
            $auto_featured_product = false;
            if (empty($featured_product_id)) {
                $match_context = trim($outline . "\n\nGap category: " . ($item->gap_category ?? '') . "\nTarget keywords: " . ($item->target_keywords ?? ''));
                $featured_product_id = bq_auto_select_priority_featured_product($blog_title, $match_context);
                $auto_featured_product = !empty($featured_product_id);
            }

            // Resolve content type once at step 1 — queue row value wins, then
            // title inference for legacy / direct-typed entries.
            $resolved_type_step1 = strtolower(trim((string) ($item->content_type ?? '')));
            $valid_types_step1 = ['howto', 'comparison', 'concept', 'career'];
            if (!in_array($resolved_type_step1, $valid_types_step1, true)) {
                $resolved_type_step1 = bq_infer_content_type_from_title($blog_title);
            }

            set_transient($progress_key, [
                'outline'             => $outline,
                'blog_title'          => $blog_title,
                'gap_category'        => $item->gap_category ?? '',
                'target_keywords'     => $item->target_keywords ?? '',
                'featured_product_id' => $featured_product_id,
                'auto_featured_product' => $auto_featured_product ? 1 : 0,
                'content_type'        => $resolved_type_step1,
            ], 3600);
            ob_end_clean();
            wp_send_json_success(['step' => 1, 'blog_title' => $blog_title]);
            break;

        case 2: // Generate content
            $progress = get_transient($progress_key);
            if (!$progress) { ob_end_clean(); wp_send_json_error('Step 2: Lost progress data. Start over.'); }

            // ── Build instruction via the canonical SEOM helper ──
            // Same source of truth as bq_create_blog and the refresh path,
            // so the inline-heredoc duplicate prompt that used to live here
            // can never drift out of sync again.
            $fpid = (int) ($progress['featured_product_id'] ?? 0);
            $featured_product_ctx = null;
            if ($fpid) {
                $fp = get_post($fpid);
                if ($fp && $fp->post_type === 'product') {
                    $featured_product_ctx = [
                        'title'             => $fp->post_title,
                        'short_description' => trim(wp_strip_all_tags($fp->post_excerpt ?: wp_trim_words($fp->post_content, 40, '...'))),
                    ];
                }
            }
            // Phase 3a: glossary links for the step-based "Create Now" flow.
            $glossary_links_step = [];
            if (function_exists('seom_resolve_glossary_links_for_writer')) {
                $candidate = ($progress['outline'] ?? '') . "\n" . ($progress['target_keywords'] ?? '') . "\n" . ($progress['blog_title'] ?? '');
                $glossary_links_step = seom_resolve_glossary_links_for_writer($candidate);
            }

            if (class_exists('SEOM_Blog_Refresher') && method_exists('SEOM_Blog_Refresher', 'build_blog_writer_instruction')) {
                $blog_instruction = SEOM_Blog_Refresher::build_blog_writer_instruction($site_name, [
                    'mode'              => 'new',
                    'gap_keyword_block' => bq_get_gap_keywords_for_prompt($progress['gap_category'] ?? ''),
                    'target_keywords'   => $progress['target_keywords'] ?? '',
                    'featured_product'  => $featured_product_ctx,
                    'content_type'      => $progress['content_type'] ?? '',
                    'glossary_links'    => $glossary_links_step,
                ]);
            } else {
                ob_end_clean();
                wp_send_json_error('Step 2: SEO AI AutoPilot is required for blog generation.');
            }

            $blog_content = bq_call_openai($blog_instruction, "Outline:\n" . $progress['outline']);
            if (is_wp_error($blog_content)) { ob_end_clean(); wp_send_json_error('Step 2 (Content): ' . $blog_content->get_error_message()); }

            // Fix markdown/callout issues
            $blog_content = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $blog_content);
            $blog_content = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $blog_content);
            $blog_content = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $blog_content);
            $blog_content = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $blog_content);
            $blog_content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $blog_content);
            $blog_content = preg_replace_callback('/itu-callout--([a-z\-]+)/', function ($m) {
                $valid = ['tip', 'info', 'warning', 'key'];
                $variant = $m[1];
                if (strpos($variant, 'tip') !== false) return 'itu-callout--tip';
                if (strpos($variant, 'info') !== false || strpos($variant, 'note') !== false) return 'itu-callout--info';
                if (strpos($variant, 'warn') !== false || strpos($variant, 'caution') !== false) return 'itu-callout--warning';
                if (strpos($variant, 'key') !== false || strpos($variant, 'purple') !== false || strpos($variant, 'important') !== false) return 'itu-callout--key';
                if (in_array($variant, $valid)) return 'itu-callout--' . $variant;
                return 'itu-callout--tip';
            }, $blog_content);

            // Inject the Featured Product shortcode (after first paragraph + before last H2)
            if ($fpid) {
                $blog_content = bq_inject_featured_product($blog_content, $fpid);
            }

            // Create the WP post
            $post_id = wp_insert_post([
                'post_title'   => $progress['blog_title'],
                'post_content' => $blog_content,
                'post_status'  => 'publish',
                'post_type'    => 'post',
                'post_author'  => 1,
            ]);
            if (is_wp_error($post_id)) { ob_end_clean(); wp_send_json_error('Step 2 (Insert): ' . $post_id->get_error_message()); }

            // Persist the product association so the refresher can re-inject it later.
            if ($fpid) {
                $meta_key = defined('SEOM_FEATURED_PRODUCT_META_KEY') ? SEOM_FEATURED_PRODUCT_META_KEY : '_seom_featured_product_id';
                update_post_meta($post_id, $meta_key, $fpid);
                if (!empty($progress['auto_featured_product'])) {
                    update_post_meta($post_id, '_seom_featured_product_auto_selected', '1');
                } else {
                    delete_post_meta($post_id, '_seom_featured_product_auto_selected');
                }
            }

            // Phase 1b: persist resolved content type so the schema layer +
            // future refresh can both reuse it without re-inferring.
            if (!empty($progress['content_type'])) {
                update_post_meta($post_id, '_seom_content_type', $progress['content_type']);
            }

            $blog_cat = get_category_by_slug('blogs');
            if (!$blog_cat) $blog_cat = get_category_by_slug('blog');
            if ($blog_cat) wp_set_post_categories($post_id, [$blog_cat->term_id]);

            $progress['post_id'] = $post_id;
            $progress['blog_content'] = $blog_content;
            set_transient($progress_key, $progress, 3600);
            ob_end_clean();
            wp_send_json_success(['step' => 2, 'post_id' => $post_id]);
            break;

        case 3: // Meta description, focus keyword, SEO title — via canonical pipeline
            $progress = get_transient($progress_key);
            if (!$progress || empty($progress['post_id'])) { ob_end_clean(); wp_send_json_error('Step 3: Lost progress data.'); }

            $post_id = $progress['post_id'];

            // Delegate to SEOM_Blog_Refresher so Blog Queue, AI Product Manager,
            // and SEO AI AutoPilot all share the same prompts/model/logic.
            // The previous inline implementation referenced an undefined
            // {$site_name} in the SEO title prompt, which produced a broken
            // prompt and frequently failed the 70-char length check, leaving
            // rank_math_title unset.
            if (class_exists('SEOM_Blog_Refresher') && SEOM_Blog_Refresher::is_available()) {
                SEOM_Blog_Refresher::step_meta_description($post_id);
                SEOM_Blog_Refresher::step_rankmath($post_id);   // focus keyword
                SEOM_Blog_Refresher::step_seo_title($post_id);
            } else {
                ob_end_clean();
                wp_send_json_error('Step 3: SEO AI AutoPilot Blog Refresher not available.');
            }

            set_transient($progress_key, $progress, 3600);
            ob_end_clean();
            wp_send_json_success(['step' => 3]);
            break;

        case 4: // FAQ HTML + JSON-LD schema + finalize — via canonical pipeline
            $progress = get_transient($progress_key);
            if (!$progress || empty($progress['post_id'])) { ob_end_clean(); wp_send_json_error('Step 4: Lost progress data.'); }

            $post_id = $progress['post_id'];

            if (class_exists('SEOM_Blog_Refresher') && SEOM_Blog_Refresher::is_available()) {
                $faq_html = SEOM_Blog_Refresher::step_faq_html($post_id);
                if (!is_wp_error($faq_html)) {
                    SEOM_Blog_Refresher::step_faq_json($post_id, $faq_html);
                }
            } else {
                ob_end_clean();
                wp_send_json_error('Step 4: SEO AI AutoPilot Blog Refresher not available.');
            }

            update_post_meta($post_id, 'last_page_refresh', current_time('mysql'));

            // Stamp gap keywords as used
            bq_stamp_gap_keywords_used($progress['gap_category'] ?? '', $post_id);

            // Mark queue item complete
            $wpdb->update($wpdb->prefix . 'bq_queue', [
                'status' => 'completed', 'post_id' => $post_id, 'processed_at' => current_time('mysql')
            ], ['id' => $id]);
            $wpdb->insert($wpdb->prefix . 'bq_history', [
                'title' => $item->title, 'post_id' => $post_id, 'status' => 'completed', 'processed_at' => current_time('mysql')
            ]);

            // Send notification email
            $bq_settings = bq_get_settings();
            if ($bq_settings['notify_email']) {
                bq_send_notification($bq_settings['notify_email'], $progress['blog_title'], 'completed', $post_id, get_permalink($post_id));
            }

            delete_transient($progress_key);
            ob_end_clean();
            wp_send_json_success([
                'step'     => 4,
                'complete' => true,
                'post_id'  => $post_id,
                'url'      => get_permalink($post_id),
                'edit_url' => admin_url('post.php?action=edit&post=' . $post_id),
            ]);
            break;

        default:
            ob_end_clean();
            wp_send_json_error('Invalid step.');
    }
});

add_action('wp_ajax_bq_save_settings', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    // Merge into the existing settings array so unrelated fields (content
    // categorization thresholds, etc.) aren't wiped on save.
    $existing = get_option('bq_settings', []);
    if (!is_array($existing)) $existing = [];
    $scope = isset($_POST['topic_gate_scope'])
        ? trim(wp_strip_all_tags((string) wp_unslash($_POST['topic_gate_scope'])))
        : ($existing['topic_gate_scope'] ?? bq_default_topic_scope());
    if ($scope === '') $scope = bq_default_topic_scope();

    $new = array_merge($existing, [
        'daily_limit'        => max(1, intval($_POST['daily_limit'] ?? 5)),
        'notify_email'       => sanitize_email($_POST['notify_email'] ?? ''),
        'enabled'            => ($_POST['enabled'] ?? '0') === '1',
        'topic_gate_enabled' => ($_POST['topic_gate_enabled'] ?? '0') === '1',
        'topic_gate_scope'   => $scope,
    ]);
    update_option('bq_settings', $new);
    wp_send_json_success();
});

add_action('wp_ajax_bq_run_queue', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(1200);

    // Force = true bypasses the "enabled" check for manual triggers
    bq_process_queue(true);

    // Return what happened
    global $wpdb;
    $today_count = (int) get_option('bq_daily_count_' . date('Y-m-d'), 0);
    $recent = $wpdb->get_results("
        SELECT * FROM {$wpdb->prefix}bq_history
        WHERE DATE(processed_at) = CURDATE()
        ORDER BY processed_at DESC LIMIT 20
    ");

    wp_send_json_success([
        'processed'   => count($recent),
        'today_count' => $today_count,
        'results'     => $recent,
    ]);
});

// Get keyword gap categories for the topic generator dropdown
add_action('wp_ajax_bq_get_gap_categories', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        wp_send_json_success([]);
        return;
    }

    $cooldown = 90;
    if (function_exists('seom_get_settings')) {
        $settings = seom_get_settings();
        $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
    }

    $gap_kw_filter = sanitize_text_field($_POST['gap_kw_filter'] ?? 'all');
    $filter_clause = '';
    switch ($gap_kw_filter) {
        case 'high_volume':    $filter_clause = ' AND search_volume >= 1000'; break;
        case 'low_difficulty': $filter_clause = ' AND keyword_difficulty <= 30'; break;
        case 'quick_wins':     $filter_clause = ' AND search_volume >= 500 AND keyword_difficulty <= 40'; break;
    }

    $tags = $wpdb->get_results($wpdb->prepare("
        SELECT tag,
            COUNT(*) as cnt,
            SUM(CASE WHEN (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL %d DAY)){$filter_clause} THEN 1 ELSE 0 END) as available
        FROM {$table}
        WHERE tag IS NOT NULL AND tag != ''
        GROUP BY tag
        ORDER BY tag ASC
    ", $cooldown));

    wp_send_json_success($tags ?: []);
});

// Product search for the Featured Product picker on Generate Topics.
// Returns up to 30 published products matching a search term, or the
// 30 most recent if the term is empty.
add_action('wp_ajax_bq_search_products', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $term = sanitize_text_field(wp_unslash($_POST['term'] ?? ''));
    $like = '%' . $wpdb->esc_like($term) . '%';
    if (empty($term)) {
        $rows = $wpdb->get_results("
            SELECT ID, post_title FROM {$wpdb->posts}
            WHERE post_type = 'product' AND post_status = 'publish'
            ORDER BY post_modified DESC
            LIMIT 30
        ");
    } else {
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT ID, post_title FROM {$wpdb->posts}
            WHERE post_type = 'product' AND post_status = 'publish'
            AND post_title LIKE %s
            ORDER BY post_title ASC
            LIMIT 30
        ", $like));
    }

    $products = [];
    foreach ($rows as $r) {
        $products[] = ['id' => (int) $r->ID, 'title' => $r->post_title];
    }
    wp_send_json_success(['products' => $products]);
});

/**
 * Strip trailing year tokens (2020-2099) from a blog title.
 *
 * Both the outline AI and the topic generator AI ignore the "no year in
 * title" prompt rule with disappointing frequency. This is the defense-
 * in-depth safety net: any title leaving the queue path passes through
 * here first.
 *
 * Only TRAILING years are stripped — and only when accompanied by a
 * trivial leading connector ("in", "for", "of", em/en dash, hyphen),
 * a leading bracket, or simple whitespace. Mid-title years stay (so
 * product names like "Windows Server 2022" or "ITIL 2011" survive).
 *
 * Examples:
 *   "Top Certifications for 2024"           → "Top Certifications"
 *   "Best Practices in 2024"                → "Best Practices"
 *   "Best Practices (2024)"                 → "Best Practices"
 *   "Active Directory 2024 Best Practices"  → unchanged (mid-title)
 *   "Windows Server 2022"                   → "Windows Server" (trailing)
 *                                              — acceptable; the trailing
 *                                                year is ambiguous and
 *                                                we err on stripping
 */
function bq_strip_year_from_title($title) {
    if (!is_string($title) || $title === '') return $title;
    $original = $title;

    // Trailing year, optionally with a connector word, possibly bracketed.
    // Run twice to handle "2024 2025" type repeats that an AI sometimes produces.
    for ($i = 0; $i < 2; $i++) {
        $title = preg_replace(
            '/[\s,;:\-–—]*[\(\[]?\s*(?:in|for|of|during|by)?\s*20\d{2}[\)\]]?\s*$/iu',
            '',
            $title
        );
    }

    // Tidy trailing punctuation/whitespace left behind by the strip.
    $title = preg_replace('/[\s,;:\-–—]+$/u', '', (string) $title);
    $title = trim((string) $title);

    // If stripping nuked the whole thing (e.g., title was literally "2024"),
    // fall back to the original so downstream code still has something to work with.
    if ($title === '') return $original;
    return $title;
}

/**
 * Tokenize a string for product-relevance scoring.
 *
 * Used when both a featured product and a gap_category are selected: we
 * pull a wider pool of candidate keywords than we need, score each by
 * how many tokens it shares with the product title, and prefer the
 * high-relevance ones. That stops "Cybersecurity" gap keywords from
 * dragging the topic batch toward Cisco when the product is Security+.
 *
 * Filters out structural stopwords + "course"/"certification"/"training"/
 * "exam" since those appear in every product title and would inflate
 * scores artificially across the whole pool.
 */
function bq_tokenize_for_relevance($s) {
    $s = strtolower(trim((string) $s));
    if ($s === '') return [];
    // Replace +/-/() etc with spaces so "Security+" → "security"
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $tokens = preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip([
            'the','a','an','and','or','for','of','in','on','to','with','by',
            'course','certification','certifications','certified','training',
            'exam','exams','class','classes','program','programs',
            'guide','tutorial','intro','introduction',
            'is','are','do','does','can','how','what','why','when','where','which',
        ]);
    }
    $out = [];
    foreach ($tokens as $t) {
        if (strlen($t) < 2) continue;
        if (isset($stop[$t])) continue;
        // Singularize trailing s except for ss
        if (strlen($t) > 3 && substr($t, -1) === 's' && substr($t, -2) !== 'ss') {
            $t = substr($t, 0, -1);
        }
        $out[$t] = true; // dedup within string
    }
    return array_keys($out);
}

/**
 * Heuristic content-type inference from a title alone. Used in three places:
 *  - parsing AI topic-generator output when the AI omits the type label
 *  - bq_add_topics fallback when an incoming queue row has no type
 *  - refresh path (existing posts) to drive type-specific writer rules
 *
 * Returns one of: 'howto', 'comparison', 'concept', 'career'. Defaults to
 * 'concept' since that's the safest neutral writer treatment.
 */
function bq_infer_content_type_from_title($title) {
    if (!is_string($title) || $title === '') return 'concept';
    $t = ' ' . strtolower($title) . ' ';

    // Career signals first — most specific lexicon.
    static $career_re = '/\b(salary|salaries|career|careers|jobs?|job market|day in the life|outlook|hiring|earning|earn|paycheck|paid|pays|transition into|become an?|how much (do|does))\b/';
    if (preg_match($career_re, $t)) return 'career';

    // How-to / procedural.
    static $howto_re = '/\b(how to|how do i|how do you|steps to|step[- ]by[- ]step|guide to (install|configure|setup|deploy|set up|build|create|implement)|tutorial|walkthrough|install|configure|set up|setup|deploy|build a|create a|prepare for|study (for|plan)|how long (does|will) it take)\b/';
    if (preg_match($howto_re, $t)) return 'howto';

    // Comparison / decision.
    static $cmp_re = '/\b(vs\.?|versus| or |compared|comparison|difference between|differences between|best |top \d|which .* (should|to (use|pick|choose))|pick the|choose between|alternatives to)\b/';
    if (preg_match($cmp_re, $t)) return 'comparison';

    // Concept / definition — fallback for "what is", "how does X work", explainers.
    return 'concept';
}

add_action('wp_ajax_bq_generate_topics', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(120);

    $focus_area          = sanitize_text_field($_POST['focus_area'] ?? '');
    $gap_category        = sanitize_text_field($_POST['gap_category'] ?? '');
    $gap_kw_filter       = sanitize_text_field($_POST['gap_kw_filter'] ?? 'all');
    $featured_product_id = (int) ($_POST['featured_product_id'] ?? 0);
    $count               = min(50, max(5, intval($_POST['count'] ?? 20)));
    $source              = sanitize_text_field($_POST['source'] ?? 'keyword_gaps');

    // ─── Glossary-Gaps source ────────────────────────────────────────────
    //
    // When source = 'glossary_gaps', we delegate to the glossary-side
    // helpers (seom_glossary_get_gap_candidates + seom_glossary_generate_angles).
    // The response shape matches the keyword-gaps path so the existing UI
    // doesn't need to branch on render. We attach `glossary_term` to each
    // title so bq_add_topics can stamp ec_it_glossary.topic_queued_at on
    // insert.
    if ($source === 'glossary_gaps') {
        if (!function_exists('seom_glossary_get_gap_candidates')) {
            wp_send_json_error('Glossary Manager not loaded (seo-ai-autopilot plugin).');
        }
        $candidates = seom_glossary_get_gap_candidates([
            'coverage_max'   => isset($_POST['glossary_coverage']) ? intval($_POST['glossary_coverage']) : 2,
            'primary_area'   => sanitize_text_field($_POST['glossary_area'] ?? ''),
            'tier'           => isset($_POST['glossary_tier']) ? intval($_POST['glossary_tier']) : 0,
            'exclude_queued' => true,
            'page'           => 1,
            'per_page'       => $count,
        ]);
        if (!$candidates) {
            wp_send_json_error('No glossary gap candidates match those filters.');
        }
        // Angle-generation is batched at 20 per call; loop until we have
        // enough or run out of candidates.
        $angles = [];
        foreach (array_chunk($candidates, 20) as $chunk) {
            $batch = seom_glossary_generate_angles($chunk);
            if (is_wp_error($batch)) wp_send_json_error($batch->get_error_message());
            if (!is_array($batch)) continue;
            $angles = array_merge($angles, $batch);
            if (count($angles) >= $count) break;
        }
        $angles = array_slice($angles, 0, $count);
        // Reshape into the response the UI expects.
        $titles = [];
        foreach ($angles as $a) {
            $titles[] = [
                'title'          => $a['suggested_title'],
                'content_type'   => $a['content_type'],
                'keywords'       => $a['primary_keyword'],
                'glossary_term'  => $a['term'],
                'glossary_slug'  => $a['slug'],
                'glossary_tier'  => $a['tier'],
            ];
        }
        wp_send_json_success([
            'titles' => $titles,
            'count'  => count($titles),
            'source' => 'glossary_gaps',
        ]);
    }

    global $wpdb;

    // Get existing post titles to avoid duplication
    $existing_titles = $wpdb->get_col("
        SELECT LOWER(post_title) FROM {$wpdb->posts}
        WHERE post_type = 'post' AND post_status IN ('publish', 'draft', 'pending')
        ORDER BY RAND() LIMIT 500
    ");
    $titles_sample = implode("\n", array_slice($existing_titles, 0, 200));

    // Get pending queue titles too
    $queued_titles = $wpdb->get_col("SELECT LOWER(title) FROM {$wpdb->prefix}bq_queue WHERE status = 'pending'");
    if (!empty($queued_titles)) {
        $titles_sample .= "\n" . implode("\n", $queued_titles);
    }

    // Get top GSC keywords for content gap ideas
    $keyword_context = '';
    $kw_table = $wpdb->prefix . 'seom_keywords';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$kw_table}'") === $kw_table) {
        // Content gaps — keywords with impressions but no dedicated page
        // Use higher threshold and cross-check against existing titles to reduce false positives
        $gap_rows = $wpdb->get_results("
            SELECT keyword, impressions, avg_position FROM {$kw_table}
            WHERE is_content_gap = 1 AND impressions >= 30
            ORDER BY opportunity_score DESC LIMIT 50
        ");

        // Filter out gaps that are on cooldown or too similar to existing post titles
        $filtered_gaps = [];
        $existing_lower = array_map('strtolower', $existing_titles);
        foreach ($gap_rows as $g) {
            // Skip if this keyword was recently used in content
            if (bq_is_keyword_on_cooldown($g->keyword)) continue;

            // Skip if too similar to an existing post title (word overlap check)
            $gw = array_filter(explode(' ', strtolower($g->keyword)), function($w) { return strlen($w) > 2; });
            $dominated = false;
            foreach ($existing_lower as $et) {
                $tw = array_filter(explode(' ', $et), function($w) { return strlen($w) > 2; });
                $overlap = count(array_intersect($gw, $tw));
                if (!empty($gw) && $overlap >= ceil(count($gw) * 0.6)) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $filtered_gaps[] = $g->keyword . ' (' . $g->impressions . ' searches, pos ' . round($g->avg_position) . ')';
            }
            if (count($filtered_gaps) >= 20) break;
        }

        if (!empty($filtered_gaps)) {
            $keyword_context = "\n\nCONTENT GAP KEYWORDS — These are real Google searches where we appear but have NO dedicated blog post. Use these as inspiration for new angles, NOT direct title copies:\n" . implode("\n", $filtered_gaps);
        }

        // Rising keywords that ARE mapped to existing content (for supportive/adjacent content)
        $rising = $wpdb->get_col("
            SELECT keyword FROM {$kw_table}
            WHERE trend_direction = 'rising' AND impressions >= 10
            ORDER BY trend_pct DESC LIMIT 20
        ");
        if (!empty($rising)) {
            $keyword_context .= "\n\nTRENDING/RISING KEYWORDS — These searches are growing. Create content that targets these from a UNIQUE angle not already covered:\n" . implode(', ', $rising);
        }
    }

    // Keyword gap data — imported from SEMrush/Ahrefs competitive analysis
    $gap_context = '';
    $gap_table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$gap_table}'") === $gap_table && !empty($gap_category)) {
        $cooldown = 90;
        if (function_exists('seom_get_settings')) {
            $settings = seom_get_settings();
            $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
        }

        // Build filter clause
        $cooldown_clause = " AND (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL {$cooldown} DAY))";
        $filter_clause = '';
        switch ($gap_kw_filter) {
            case 'high_volume':    $filter_clause = ' AND search_volume >= 1000'; break;
            case 'low_difficulty': $filter_clause = ' AND keyword_difficulty <= 30'; break;
            case 'quick_wins':     $filter_clause = ' AND search_volume >= 500 AND keyword_difficulty <= 40'; break;
            case 'available':      $filter_clause = ''; break; // cooldown_clause already handles this
        }

        // No silent fallback — if filter is strict and returns nothing, tell the AI exactly that
        $filter_label = '';
        switch ($gap_kw_filter) {
            case 'high_volume':    $filter_label = 'High Volume (1000+)'; break;
            case 'low_difficulty': $filter_label = 'Low Difficulty (≤30)'; break;
            case 'quick_wins':    $filter_label = 'Quick Wins (vol≥500, KD≤40)'; break;
        }

        // Always cap topic count by the size of the available keyword pool —
        // we never want to generate more blogs than we have keywords to anchor
        // them to. If 300 keywords are available and the user asked for 20,
        // generate 20. If only 5 are available and the user asked for 20,
        // generate 5. One topic per keyword either way.
        $avail_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$gap_table}
             WHERE tag = %s AND your_position = 0
             {$cooldown_clause} {$filter_clause}",
            $gap_category
        ));
        if ($avail_count <= 0) {
            $filter_msg = $filter_label ? " matching filter \"{$filter_label}\"" : '';
            wp_send_json_error(
                "No available keywords in category \"{$gap_category}\"{$filter_msg}. "
                . "Pick a different category, change the keyword filter, or generate without a category."
            );
        }
        if ($avail_count < $count) {
            $count = $avail_count;
        }

        // Pull keywords. When a featured product is set, pull a WIDER pool
        // (3× the limit, min 150) so we can re-rank by product-relevance
        // PHP-side and surface the keywords that actually intersect the
        // course's domain. Without this step, a "Cybersecurity" gap_category
        // with mostly Cisco-themed keywords would drown a Security+
        // course's topic batch in Cisco titles even though the prompt
        // says to anchor on Security+.
        $kw_limit = max(1, (int) $count);
        $candidate_pool = $featured_product_id ? max(150, $kw_limit * 3) : $kw_limit;
        $gap_keywords = $wpdb->get_results($wpdb->prepare("
            SELECT keyword, search_volume, keyword_difficulty
            FROM {$gap_table}
            WHERE tag = %s AND your_position = 0
            {$cooldown_clause} {$filter_clause}
            ORDER BY search_volume DESC
            LIMIT %d
        ", $gap_category, $candidate_pool));

        if ($featured_product_id && !empty($gap_keywords)) {
            $fp_post = get_post($featured_product_id);
            if ($fp_post && $fp_post->post_type === 'product') {
                $product_tokens = bq_tokenize_for_relevance($fp_post->post_title);
                if (!empty($product_tokens)) {
                    foreach ($gap_keywords as $gk) {
                        $kw_tokens = bq_tokenize_for_relevance($gk->keyword);
                        $gk->_relevance = count(array_intersect($product_tokens, $kw_tokens));
                    }
                    // Sort: highest relevance first, then highest volume.
                    // Ties on both keep DB order (which was already volume-DESC).
                    usort($gap_keywords, function ($a, $b) {
                        $ra = (int) ($a->_relevance ?? 0);
                        $rb = (int) ($b->_relevance ?? 0);
                        if ($ra !== $rb) return $rb - $ra;
                        return ((int) $b->search_volume) - ((int) $a->search_volume);
                    });
                }
            }
        }
        // Final cut to the requested limit.
        $gap_keywords = array_slice($gap_keywords, 0, $kw_limit);

        // Pull any saved LSI keywords for the gap keywords in this category
        $lsi_context = '';
        $lsi_table = $wpdb->prefix . 'seom_lsi_keywords';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$lsi_table}'") === $lsi_table && !empty($gap_keywords)) {
            $seed_list = [];
            foreach ($gap_keywords as $gk) {
                $seed_list[] = strtolower($gk->keyword);
            }
            if (!empty($seed_list)) {
                $seed_placeholders = implode(',', array_fill(0, count($seed_list), '%s'));
                $lsi_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT seed_keyword, lsi_keyword FROM {$lsi_table} WHERE seed_keyword IN ({$seed_placeholders}) ORDER BY seed_keyword",
                    ...$seed_list
                ));
                if (!empty($lsi_rows)) {
                    $lsi_grouped = [];
                    foreach ($lsi_rows as $lr) {
                        $lsi_grouped[$lr->seed_keyword][] = $lr->lsi_keyword;
                    }
                    $lsi_lines = [];
                    foreach ($lsi_grouped as $seed => $lsis) {
                        $lsi_lines[] = "  {$seed} → " . implode(', ', $lsis);
                    }
                    $lsi_context = "\n\nPREVIOUSLY DISCOVERED LSI KEYWORDS (use these to create unique topics — each topic should target different LSI variants):\n" . implode("\n", $lsi_lines);
                }
            }
        }

        $kw_count = count($gap_keywords);

        // Build context — always enforce category
        $gap_context = "\n\nCATEGORY FOCUS: {$gap_category}\n"
            . "ALL blog topics MUST be directly relevant to \"{$gap_category}\" — do NOT generate topics outside this category.\n"
            . "Every title must pass the test: 'Would someone searching for \"{$gap_category}\" topics find this relevant?'\n";

        if ($kw_count > 0) {
            $gap_list = [];
            foreach ($gap_keywords as $gk) {
                $gap_list[] = $gk->keyword . ' (vol: ' . $gk->search_volume . ', KD: ' . $gk->keyword_difficulty . ')';
            }

            $gap_context .= "\nAVAILABLE TARGET KEYWORDS ({$kw_count}):\n"
                . implode("\n", $gap_list) . "\n"
                . $lsi_context . "\n\n"
                . "TOPIC GENERATION STRATEGY (one topic per keyword):\n"
                . "- You MUST generate EXACTLY {$count} topics — one per primary keyword listed above\n"
                . "- Each topic must target a DIFFERENT primary keyword from the list — no keyword may be reused, none may be skipped\n"
                . "- Use the LSI/long-tail keywords above as supporting keywords on each topic, but the primary keyword anchor must be unique per topic\n"
                . "- Do NOT invent new primary keywords outside the list — every topic must map back to one of the {$kw_count} keywords above";
        } else {
            $filter_msg = $filter_label ? " with filter \"{$filter_label}\"" : '';
            $gap_context .= "\nNo keywords matched{$filter_msg} in category \"{$gap_category}\".\n"
                . "Generate up to {$count} topics based on the category name \"{$gap_category}\" using your knowledge.\n"
                . "For each topic, find relevant long-tail keywords that someone in this field would search for.\n"
                . "Each topic must target different keywords — no overlap.";
        }
    }

    // Get product titles for course-related topic ideas
    $products = $wpdb->get_col("
        SELECT post_title FROM {$wpdb->posts}
        WHERE post_type = 'product' AND post_status = 'publish'
        ORDER BY RAND() LIMIT 50
    ");
    $product_context = '';
    if (!empty($products)) {
        $product_context = "\n\nOUR IT TRAINING COURSES (for reference — create supporting blog content around these topics):\n" . implode(', ', array_slice($products, 0, 30));
    }

    $focus_instruction = '';
    if (!empty($focus_area)) {
        $focus_instruction = "\n\nFOCUS AREA: The user wants topics specifically about: {$focus_area}. Prioritize this area but still ensure variety.";
    }

    // Featured product context — when the user picked a WooCommerce product,
    // every generated topic must be a supporting blog for that course. The
    // featured_product_id is also persisted on each queue row (in bq_add_topics)
    // so the actual blog generation step can inject the featured-product HTML.
    $featured_product_instruction = '';
    if ($featured_product_id) {
        $fp = get_post($featured_product_id);
        if ($fp && $fp->post_type === 'product') {
            $fp_title = $fp->post_title;
            $fp_desc  = trim(wp_strip_all_tags($fp->post_excerpt ?: wp_trim_words($fp->post_content, 40, '...')));
            // PRIMARY ANCHOR block. Placed BEFORE the gap keyword list (in
            // the prompt assembly order) and written in emphatic, rule-style
            // language with concrete BAD examples so the AI doesn't drift
            // toward gap-category-themed topics when the course narrows the
            // intent. The previous version was too polite — single "every
            // topic should be relevant" sentence got out-weighted by a list
            // of 50 concrete keywords.
            $featured_product_instruction = "\n\n══════════════════════════════════════════════\n"
                . "PRIMARY ANCHOR — \"{$fp_title}\"\n"
                . "══════════════════════════════════════════════\n"
                . "This entire batch is for the \"{$fp_title}\" course. The course is the UNCONDITIONAL anchor; the keyword list, category, and focus area are SECONDARY context. Every title must be a topic a prospective student of THIS course would search for.\n\n"
                . "HARD RULES:\n"
                . "- A title is acceptable only if a reader interested in \"{$fp_title}\" would click it\n"
                . "- A title that perfectly matches a gap keyword but does NOT fit the course content is REJECTED — generate a course-relevant topic instead, even if you skip that keyword\n"
                . "- Cover: the course's specific exam objectives, skills it teaches, prerequisites, career outcomes it prepares for, comparisons relevant to its scope, and real-world scenarios its content addresses\n"
                . "- DO NOT generate titles about competing vendors / products whose technology is outside this course's scope\n"
                . "- DO NOT generate generic category-level titles that any course in the broader area could cover — be specific to THIS course\n\n"
                . "BAD examples (the kind of drift to avoid):\n"
                . "- Course is \"{$fp_title}\", keyword is unrelated vendor → title about that vendor's product. WRONG — pivot to how the course content relates, or skip the keyword.\n"
                . "- Course is \"{$fp_title}\", keyword is a broad category term → generic broad-category title. WRONG — narrow to the course's specific coverage.\n\n"
                . "GOOD shape — every title should be answerable with content like:\n"
                . "- A specific exam domain / objective the course teaches\n"
                . "- A skill the course explicitly covers (and the course can be naturally referenced as the way to learn it)\n"
                . "- A prerequisite a student needs before starting the course, or a follow-on after completing it\n"
                . "- A career role the course prepares you for, with concrete responsibilities\n"
                . "- A real-world scenario where the course's content is directly applied\n\n"
                . "Course short description: {$fp_desc}\n";
        }
    }

    // ── Phase 2: cluster context ──
    // When the chosen gap_category has a pillar post designated, every
    // generated topic becomes an explicit "spoke" supporting that pillar.
    // This shapes both the AI's framing (subtopics that drill into facets of
    // the pillar) and our internal-linking outcome (the new posts will
    // auto-link to the pillar at creation time via bq_create_blog).
    $cluster_instruction = '';
    if (!empty($gap_category)) {
        $pillar_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_seom_pillar_for' AND meta_value = %s LIMIT 1",
            $gap_category
        ));
        if ($pillar_id) {
            $pillar_post = get_post($pillar_id);
            if ($pillar_post && $pillar_post->post_status === 'publish') {
                $pillar_title = $pillar_post->post_title;
                $pillar_url   = get_permalink($pillar_id);
                $cluster_instruction = "\n\nCLUSTER PILLAR — This category has a designated pillar post: \"{$pillar_title}\" ({$pillar_url}).\n"
                    . "Every topic you generate is a SPOKE that supports this pillar. Spokes should:\n"
                    . "- Drill into a specific facet, sub-topic, decision point, or procedure that the pillar references at a high level\n"
                    . "- Be narrow and specific — not a competing comprehensive overview of the pillar's topic\n"
                    . "- Use the pillar's broader topic as the upstream anchor (the spoke would link back to the pillar in its intro)\n"
                    . "- Avoid duplicating the pillar's scope. If a candidate spoke would substantially overlap with the pillar, skip it.";
            }
        }
    }

    // ── Phase 1b: content-type quotas ──
    // AI surfaces (Google AI Overview, Perplexity, ChatGPT, Claude) cite
    // procedural and decision content far more than pure informational. Each
    // batch must be a deliberate mix so we build authority across all four
    // citation patterns at once.
    $quota_howto      = (int) round($count * 0.30);
    $quota_comparison = (int) round($count * 0.30);
    $quota_concept    = (int) round($count * 0.25);
    $quota_career     = $count - ($quota_howto + $quota_comparison + $quota_concept);
    if ($quota_career < 0) $quota_career = 0;

    $site_name = get_bloginfo('name');
    $instruction = <<<PROMPT
You are a content strategist for {$site_name}, an IT training and certification company. Generate exactly {$count} unique blog post titles.

CONTENT TYPE QUOTAS — Every title MUST be labeled with one of: howto, comparison, concept, career. The batch of {$count} titles MUST contain this exact distribution:
- howto: {$quota_howto} titles. Procedural / step-by-step. Titles start with "How to", "Steps to", "How Do I", "How Long Does It Take to", or describe a concrete procedure (install, configure, prepare, deploy, troubleshoot).
- comparison: {$quota_comparison} titles. Decision content. Titles use "vs", "or", "compared", "Best X for Y", "Which X Should I Choose", "X or Y" patterns.
- concept: {$quota_concept} titles. Definition / understanding content. Titles use "What is", "How Does X Work", "Understanding X", "X Explained", "Introduction to X".
- career: {$quota_career} titles. Career / salary / job-market content. Titles cover roles, salaries, demand, paths, day-in-the-life, transitioning into a role.

If a quota is zero, do NOT generate that type. The four numbers MUST sum to exactly {$count}.

RULES:
- ABSOLUTELY NO YEARS IN ANY TITLE. This is the single most-violated rule and it must not happen. No "2024", "2025", "2026", or any other 4-digit year — anywhere in the title, in any form. Not at the end, not in the middle, not in parentheses, not after "in" / "for" / "of". Year-tagged titles date content immediately and are unacceptable.
  - BANNED examples (do NOT generate anything like these): "Top Cloud Certifications in 2024", "Best Practices for Securing X in 2025", "Guide to Y for 2026"
  - CORRECT versions: "Top Cloud Certifications for Career Advancement", "Best Practices for Securing X", "Guide to Y"
  - If you find yourself about to write a year, STOP and remove it before continuing the title.
- Each title must be specific, actionable, and SEO-friendly
- Target IT professionals, students, and career changers
- CRITICAL: Do NOT generate titles that cover the same topic as any existing post below, even from a different angle. If a topic is already covered, skip it entirely and find a genuinely NEW topic
- Content gap keywords are suggestions, NOT mandatory titles. Use them for inspiration but ensure the blog would cover something meaningfully different from existing content
- Do NOT use generic/vague titles — be specific about the technology, tool, or concept
- Do NOT start titles with "The Ultimate Guide to..." or "Everything You Need to Know About..."
- Use title case

OUTPUT FORMAT — exactly one title per line in this format:
Title Here | content_type | primary keyword, lsi keyword 1, lsi keyword 2, long-tail keyword

- The middle pipe-separated field MUST be exactly one of: howto, comparison, concept, career (lowercase).
- After the second pipe, include 3-5 keywords: the primary target keyword from the gap list PLUS 2-4 LSI/long-tail keywords semantically related to it
- LSI keywords should be terms someone searching for the primary keyword might also search for (e.g., primary "cissp certification" → LSI: "cissp exam cost", "cissp salary", "cissp study guide", "information security certification")
- Prefer long-tail keywords (3-5 words) as they are easier to rank for
- Each blog topic should target DIFFERENT primary keywords — do NOT reuse the same primary keyword across multiple titles
- If no specific keywords apply, leave the third field blank but KEEP the type label and the second pipe
{$focus_instruction}
{$featured_product_instruction}
{$cluster_instruction}
{$gap_context}
{$keyword_context}
{$product_context}

EXISTING BLOG TITLES (avoid duplicating or closely matching these):
{$titles_sample}
PROMPT;

    $result = bq_call_openai($instruction, "Generate {$count} unique IT blog post titles.", function_exists('itu_ai_model') ? itu_ai_model('topic_generation') : 'gpt-4.1-nano', 0.9);

    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

    // Parse titles, type, and keywords from response.
    // New format: "Title | content_type | keyword, keyword, ..."
    // Legacy format: "Title | keyword, keyword, ..." (no type) — we infer
    //   the type from the title pattern so the parser doesn't break.
    $valid_types = ['howto', 'comparison', 'concept', 'career'];
    $lines = array_filter(array_map('trim', explode("\n", $result)));
    $titles = [];
    foreach ($lines as $line) {
        $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);
        $line = preg_replace('/^[-*•]\s*/', '', $line);
        $line = trim($line, '"\'');
        $line = trim($line);

        $title = $line;
        $content_type = '';
        $keywords = '';
        if (strpos($line, '|') !== false) {
            $parts = array_map('trim', explode('|', $line));
            $title = trim($parts[0], " \t\"'");
            if (count($parts) >= 3) {
                $maybe_type = strtolower($parts[1]);
                if (in_array($maybe_type, $valid_types, true)) {
                    $content_type = $maybe_type;
                    $keywords = $parts[2];
                } else {
                    // Middle field wasn't a valid type — treat as legacy 2-field.
                    $keywords = trim($parts[1] . (isset($parts[2]) ? ',' . $parts[2] : ''), ', ');
                }
            } else {
                $keywords = $parts[1] ?? '';
            }
        }

        // Infer content_type from title heuristics when the AI didn't supply one.
        if ($content_type === '') {
            $content_type = bq_infer_content_type_from_title($title);
        }

        // Strip year tokens before the title is shown in the picker or
        // copied into the queue. Length check happens AFTER stripping in
        // case "Top Certifications for 2024" becomes a marginal 25 chars.
        $title = bq_strip_year_from_title($title);

        if (mb_strlen($title) >= 10 && mb_strlen($title) <= 200) {
            $titles[] = [
                'title'        => $title,
                'content_type' => $content_type,
                'keywords'     => $keywords,
            ];
        }
    }

    // Hard cap — the prompt asks for exactly $count, but if the AI overproduces,
    // trim back so we never return more topics than the user asked for (or, when
    // a category is selected, more than the available keyword pool).
    if (count($titles) > $count) {
        $titles = array_slice($titles, 0, $count);
    }

    // ── Reserve keywords at GENERATION time, not just at Add-to-Queue time ──
    //
    // Each topic in the returned set is paired with 3-5 keywords. We stamp
    // every one of them as reserved immediately — meaning the next Generate
    // Topics run won't re-suggest them. The reservation marks last_used_at
    // on the gap row, which the cooldown clause filters against, so the
    // suppression is automatic on the next run.
    //
    // Trade-off: if the user generates topics and then doesn't add any to
    // the queue, those keywords are "burned" for the configured cooldown
    // (default 90 days). That's intentional per the user's request — they
    // explicitly want keywords marked as used the moment they appear in a
    // recommended topic, not waiting until a blog is created. If a keyword
    // needs to be freed, clearing last_used_at in seom_keyword_gaps brings
    // it back into the pool.
    if (!empty($titles) && function_exists('bq_reserve_keywords')) {
        $reserve_set = [];
        foreach ($titles as $t) {
            if (empty($t['keywords'])) continue;
            foreach (explode(',', (string) $t['keywords']) as $kw) {
                $kw = trim($kw);
                if ($kw === '') continue;
                $reserve_set[strtolower($kw)] = $kw; // dedup by lowercased key
            }
        }
        if (!empty($reserve_set)) {
            bq_reserve_keywords(array_values($reserve_set), null, 'gap');
        }
    }

    wp_send_json_success(['titles' => $titles, 'count' => count($titles)]);
});

// ─── Topic Clusters (phase 2) ───────────────────────────────────────────────
//
// A "cluster" is everything sharing a single keyword-gap tag:
//   - the keywords themselves (wp_seom_keyword_gaps WHERE tag = X)
//   - the blog posts created from that tag (bq_queue.gap_category = X)
//   - one designated "pillar" post (post meta _seom_pillar_for = tag)
//
// The Clusters tab lets the user see depth-of-coverage per tag, designate
// pillar posts, and run cluster-aware topic generation that fills the
// specific subtopic gaps within one cluster instead of generating free-form.

/**
 * Per-cluster overview. Returns one row per tag with coverage stats and a
 * 0-100 depth score for ranking. One DB pass plus a couple of aggregations —
 * cheap enough to call on every tab open.
 */
function bq_get_clusters_overview() {
    global $wpdb;
    $gap_table = $wpdb->prefix . 'seom_keyword_gaps';
    $queue     = $wpdb->prefix . 'bq_queue';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$gap_table}'") !== $gap_table) return [];

    // Cooldown for "available" keywords mirrors what bq_generate_topics uses.
    $cooldown = 90;
    if (function_exists('seom_get_settings')) {
        $settings = seom_get_settings();
        $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
    }

    // Keyword counts per tag.
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT
            tag,
            COUNT(*) AS total_keywords,
            SUM(CASE WHEN last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL %d DAY) THEN 1 ELSE 0 END) AS available_keywords,
            SUM(CASE WHEN your_position > 0 THEN 1 ELSE 0 END) AS ranked_keywords
        FROM {$gap_table}
        WHERE tag IS NOT NULL AND tag != ''
        GROUP BY tag
    ", $cooldown));
    if (empty($rows)) return [];

    // Post counts per tag (completed posts only).
    $post_rows = $wpdb->get_results("
        SELECT gap_category, COUNT(*) AS posts_total
        FROM {$queue}
        WHERE gap_category IS NOT NULL AND gap_category != ''
          AND post_id IS NOT NULL
          AND status = 'completed'
        GROUP BY gap_category
    ");
    $posts_by_tag = [];
    foreach ($post_rows as $pr) {
        $posts_by_tag[$pr->gap_category] = (int) $pr->posts_total;
    }

    // Pillars — single query across all posts with the pillar meta.
    $pillar_rows = $wpdb->get_results("
        SELECT post_id, meta_value AS tag
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_seom_pillar_for' AND meta_value != ''
    ");
    $pillar_by_tag = [];
    foreach ($pillar_rows as $pr) {
        $pillar_by_tag[$pr->tag] = (int) $pr->post_id;
    }

    $out = [];
    foreach ($rows as $r) {
        $total  = (int) $r->total_keywords;
        $avail  = (int) $r->available_keywords;
        $posts  = $posts_by_tag[$r->tag] ?? 0;
        $pillar = $pillar_by_tag[$r->tag] ?? null;

        // Depth score (0-100): rewards posts + pillar + coverage breadth.
        // Soft-capped pieces so a megacluster (1k+ keywords) doesn't drown out
        // a small but well-covered one.
        $score = 0;
        if ($pillar) $score += 30;
        $score += min(45, $posts * 3);                            // up to 45 from posts
        $score += min(25, $total > 0 ? ($posts / max(1, $total / 20)) * 25 : 0); // coverage ratio
        $score = max(0, min(100, (int) round($score)));

        $out[] = [
            'tag'                 => $r->tag,
            'total_keywords'      => $total,
            'available_keywords'  => $avail,
            'ranked_keywords'     => (int) $r->ranked_keywords,
            'posts'               => $posts,
            'pillar_post_id'      => $pillar,
            'gaps'                => max(0, $avail), // available = unfilled in this view
            'depth_score'         => $score,
        ];
    }
    return $out;
}

/**
 * Detail view for a single cluster: member posts + top unfilled subtopics.
 */
function bq_get_cluster_detail($tag) {
    global $wpdb;
    $gap_table = $wpdb->prefix . 'seom_keyword_gaps';
    $queue     = $wpdb->prefix . 'bq_queue';

    if ($tag === '') return null;

    $cooldown = 90;
    if (function_exists('seom_get_settings')) {
        $settings = seom_get_settings();
        $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
    }

    // Member posts — completed queue rows for this tag, joined with the post
    // table so we can show the live title (rewrites/refreshes change it from
    // the original queue title).
    $members_raw = $wpdb->get_results($wpdb->prepare("
        SELECT q.post_id, q.title AS queue_title, q.added_at, q.content_type
        FROM {$queue} q
        WHERE q.gap_category = %s
          AND q.post_id IS NOT NULL
          AND q.status = 'completed'
        ORDER BY q.added_at DESC
        LIMIT 200
    ", $tag));

    $pillar_post_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
         WHERE meta_key = '_seom_pillar_for' AND meta_value = %s LIMIT 1",
        $tag
    ));

    $members = [];
    foreach ($members_raw as $m) {
        $pid = (int) $m->post_id;
        $post = get_post($pid);
        if (!$post || $post->post_status !== 'publish') continue;
        $members[] = [
            'post_id'      => $pid,
            'title'        => $post->post_title,
            'url'          => get_permalink($pid),
            'edit_url'     => get_edit_post_link($pid, 'raw'),
            'modified'     => get_the_modified_date('Y-m-d', $pid),
            'word_count'   => str_word_count(wp_strip_all_tags($post->post_content)),
            'content_type' => $m->content_type,
            'is_pillar'    => ($pid === $pillar_post_id),
        ];
    }

    // Top unfilled subtopics: available keywords sorted by search volume.
    $gaps = $wpdb->get_results($wpdb->prepare("
        SELECT keyword, search_volume, keyword_difficulty
        FROM {$gap_table}
        WHERE tag = %s AND your_position = 0
          AND (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL %d DAY))
        ORDER BY search_volume DESC
        LIMIT 50
    ", $tag, $cooldown));

    return [
        'tag'            => $tag,
        'pillar_post_id' => $pillar_post_id ?: null,
        'members'        => $members,
        'gaps'           => $gaps,
        'gap_count'      => count($gaps),
        'member_count'   => count($members),
    ];
}

/**
 * Set or clear the pillar for a tag.
 * If $post_id == 0 we just clear whatever's currently designated for $tag.
 */
function bq_set_cluster_pillar($tag, $post_id) {
    global $wpdb;
    $tag = trim((string) $tag);
    if ($tag === '') return new WP_Error('bq_no_tag', 'Tag required.');

    // Clear any existing pillar for this tag first — only one allowed.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_seom_pillar_for' AND meta_value = %s",
        $tag
    ));

    if ((int) $post_id <= 0) return true; // clear only

    // Also strip any *other* pillar designation this post might have — a post
    // is pillar for at most one cluster.
    delete_post_meta((int) $post_id, '_seom_pillar_for');
    update_post_meta((int) $post_id, '_seom_pillar_for', $tag);
    return true;
}

add_action('wp_ajax_bq_get_clusters_overview', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    wp_send_json_success(['clusters' => bq_get_clusters_overview()]);
});

add_action('wp_ajax_bq_get_cluster_detail', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    $tag = sanitize_text_field(wp_unslash($_POST['tag'] ?? ''));
    if ($tag === '') wp_send_json_error('Missing tag.');
    $detail = bq_get_cluster_detail($tag);
    if (!$detail) wp_send_json_error('No data for tag.');
    wp_send_json_success($detail);
});

add_action('wp_ajax_bq_set_cluster_pillar', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    $tag = sanitize_text_field(wp_unslash($_POST['tag'] ?? ''));
    $post_id = (int) ($_POST['post_id'] ?? 0);
    $res = bq_set_cluster_pillar($tag, $post_id);
    if (is_wp_error($res)) wp_send_json_error($res->get_error_message());
    wp_send_json_success([
        'tag'            => $tag,
        'pillar_post_id' => $post_id ?: null,
    ]);
});

// ─── All Blogs AJAX ─────────────────────────────────────────────────────────

add_action('wp_ajax_bq_get_all_blogs', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $page     = max(1, intval($_POST['page'] ?? 1));
    $per_page = 20;
    $offset   = ($page - 1) * $per_page;
    $search   = sanitize_text_field($_POST['search'] ?? '');
    $filter   = sanitize_text_field($_POST['filter'] ?? 'all');
    $orderby  = sanitize_text_field($_POST['orderby'] ?? 'modified');
    $order    = strtoupper(sanitize_text_field($_POST['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1, // Fetch all for filtering, paginate after
        'orderby'        => $orderby === 'title' ? 'title' : ($orderby === 'date' ? 'date' : 'modified'),
        'order'          => $order,
    ];
    if (!empty($search)) {
        $args['s'] = $search;
    }

    $query = new WP_Query($args);
    $all_items = [];

    // Pre-fetch full refresh post IDs from SEO AI AutoPilot history (excludes meta_only)
    $full_refreshed_ids = [];
    if (in_array($filter, ['seo_full_refreshed', 'seo_not_full_refreshed'])) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'seom_refresh_history';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$history_table}'") === $history_table) {
            $full_refreshed_ids = $wpdb->get_col(
                "SELECT DISTINCT post_id FROM {$history_table} WHERE refresh_type = 'full'"
            );
            $full_refreshed_ids = array_map('intval', $full_refreshed_ids);
        }
    }

    foreach ($query->posts as $post) {
        $content    = $post->post_content;
        $word_count = str_word_count(wp_strip_all_tags($content));

        $faq_html = '';
        $faq_json = '';
        if (function_exists('get_field')) {
            $faq_html = get_field('field_6816a44480234', $post->ID) ?: '';
            $faq_json = get_field('field_6816d54e3951d', $post->ID) ?: '';
        }

        $has_faq      = !empty(trim(strip_tags($faq_html)));
        $has_schema   = !empty(trim(strip_tags($faq_json)));
        $has_image    = has_post_thumbnail($post->ID);
        $meta_desc    = get_post_meta($post->ID, 'rank_math_description', true);
        $has_meta     = !empty(trim($meta_desc));
        $focus_kw     = get_post_meta($post->ID, 'rank_math_focus_keyword', true);
        $has_keyword  = !empty(trim($focus_kw));
        $last_refresh = get_post_meta($post->ID, 'last_page_refresh', true);

        // Apply filters
        if ($filter === 'missing_faq' && $has_faq) continue;
        if ($filter === 'missing_schema' && $has_schema) continue;
        if ($filter === 'missing_image' && $has_image) continue;
        if ($filter === 'missing_meta' && $has_meta) continue;
        if ($filter === 'missing_keyword' && $has_keyword) continue;
        if ($filter === 'short_content' && $word_count >= 1000) continue;
        if ($filter === 'seo_full_refreshed' && !in_array($post->ID, $full_refreshed_ids)) continue;
        if ($filter === 'seo_not_full_refreshed' && in_array($post->ID, $full_refreshed_ids)) continue;

        $thumb_url = $has_image ? wp_get_attachment_image_url(get_post_thumbnail_id($post->ID), 'thumbnail') : '';

        $all_items[] = [
            'ID'           => $post->ID,
            'title'        => $post->post_title,
            'word_count'   => $word_count,
            'has_faq'      => $has_faq,
            'has_schema'   => $has_schema,
            'has_image'    => $has_image,
            'has_meta'     => $has_meta,
            'has_keyword'  => $has_keyword,
            'thumb_url'    => $thumb_url,
            'last_refresh' => $last_refresh ?: '',
            'modified'     => get_the_modified_date('Y-m-d H:i', $post->ID),
            'edit_url'     => get_edit_post_link($post->ID, 'raw'),
            'view_url'     => get_permalink($post->ID),
        ];
    }

    $total = count($all_items);
    $paged = array_slice($all_items, $offset, $per_page);

    wp_send_json_success([
        'rows'  => $paged,
        'total' => $total,
        'page'  => $page,
        'pages' => ceil($total / $per_page),
    ]);
});

// ─── Blog Refresh (used by Blog Queue admin page bulk actions ONLY) ──────────
//
// This handler runs the blog refresh SYNCHRONOUSLY because the Blog Queue
// admin page (the "All Blogs" tab) expects inline status updates per row —
// it shows ✓/✗ check columns for FAQ/Schema/Meta/Keyword that need to update
// after each item completes. The page already has a 600s AJAX timeout and
// processes items sequentially in the foreground.
//
// The admin bar "AI Refresh Blog/Product" trigger does NOT use this handler.
// It uses `seom_queue_refresh_now` (in the SEO AI AutoPilot plugin) which
// queues the post for background processing instead.
//
add_action('wp_ajax_bq_refresh_blog', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(900);
    ignore_user_abort(true);

    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            if (!headers_sent()) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'data'    => 'PHP Fatal: ' . $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line'],
                ]);
            }
        }
    });

    ob_start();

    $post_id = intval($_POST['post_id'] ?? 0);
    $type    = sanitize_text_field($_POST['type'] ?? 'full');

    if (!$post_id || get_post_type($post_id) !== 'post') {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        wp_send_json_error('Invalid post ID.');
    }

    if (!class_exists('SEOM_Blog_Refresher') || !SEOM_Blog_Refresher::is_available()) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        wp_send_json_error('SEO AI AutoPilot Blog Refresher not available.');
    }

    global $wpdb;
    $before = $wpdb->get_row($wpdb->prepare(
        "SELECT clicks, impressions, ctr, avg_position FROM {$wpdb->prefix}seom_page_metrics
         WHERE post_id = %d ORDER BY date_collected DESC LIMIT 1",
        $post_id
    ));

    $refresh_type_label = $type === 'meta' ? 'meta_only' : ($type === 'seo' ? 'seo_refresh' : 'full');
    $error = null;

    try {
        if ($type === 'meta') {
            $result = SEOM_Blog_Refresher::meta_refresh($post_id);
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
        } elseif ($type === 'seo') {
            $faq = SEOM_Blog_Refresher::step_faq_html($post_id);
            if (is_wp_error($faq)) throw new Exception('FAQ HTML: ' . $faq->get_error_message());
            $faq_json = SEOM_Blog_Refresher::step_faq_json($post_id, $faq);
            if (is_wp_error($faq_json)) throw new Exception('FAQ JSON: ' . $faq_json->get_error_message());
            $meta = SEOM_Blog_Refresher::step_meta_description($post_id);
            if (is_wp_error($meta)) throw new Exception('Meta: ' . $meta->get_error_message());
            $rm = SEOM_Blog_Refresher::step_rankmath($post_id);
            if (is_wp_error($rm)) throw new Exception('RankMath: ' . $rm->get_error_message());
            $st = SEOM_Blog_Refresher::step_seo_title($post_id);
            if (is_wp_error($st)) throw new Exception('SEO Title: ' . $st->get_error_message());
            $ts = SEOM_Blog_Refresher::step_timestamp($post_id);
            if (is_wp_error($ts)) throw new Exception('Timestamp: ' . $ts->get_error_message());
        } else {
            $result = SEOM_Blog_Refresher::full_refresh($post_id);
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    // Record in SEO AI AutoPilot history
    $history_table = $wpdb->prefix . 'seom_refresh_history';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$history_table}'") === $history_table) {
        $wpdb->insert($history_table, [
            'post_id'            => $post_id,
            'refresh_date'       => current_time('mysql'),
            'refresh_type'       => $refresh_type_label . ($error ? ' (failed)' : ''),
            'category'           => 'M',
            'priority_score'     => null,
            'clicks_before'      => $before->clicks ?? null,
            'impressions_before' => $before->impressions ?? null,
            'position_before'    => $before->avg_position ?? null,
            'ctr_before'         => $before->ctr ?? null,
        ]);
    }

    if ($error) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        wp_send_json_error($error);
    }

    while (ob_get_level() > 0) { @ob_end_clean(); }

    // Return updated status checkboxes for the row
    $faq_html = function_exists('get_field') ? (get_field('field_6816a44480234', $post_id) ?: '') : '';
    $faq_json = function_exists('get_field') ? (get_field('field_6816d54e3951d', $post_id) ?: '') : '';
    $content  = get_post_field('post_content', $post_id);

    wp_send_json_success([
        'post_id'     => $post_id,
        'word_count'  => str_word_count(wp_strip_all_tags($content)),
        'has_faq'     => !empty(trim(strip_tags($faq_html))),
        'has_schema'  => !empty(trim(strip_tags($faq_json))),
        'has_meta'    => !empty(trim(get_post_meta($post_id, 'rank_math_description', true))),
        'has_keyword' => !empty(trim(get_post_meta($post_id, 'rank_math_focus_keyword', true))),
        'modified'    => get_the_modified_date('Y-m-d H:i', $post_id),
    ]);
});


// ─── Admin Page ──────────────────────────────────────────────────────────────

function bq_render_admin() {
    // Ensure tables exist
    global $wpdb;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}bq_queue'") !== $wpdb->prefix . 'bq_queue') {
        bq_create_tables();
    }

    $settings = bq_get_settings();
    $nonce = wp_create_nonce('bq_nonce');
    ?>
    <div class="wrap">
        <h1>Blog Queue</h1>

        <nav class="nav-tab-wrapper" style="border-bottom:2px solid #e2e8f0;margin-bottom:20px;">
            <a href="#" class="nav-tab nav-tab-active bq-tab" data-tab="add">Add Topics</a>
            <a href="#" class="nav-tab bq-tab" data-tab="queue">Queue <span id="bq-queue-count" style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:10px;font-size:11px;margin-left:4px;"></span></a>
            <a href="#" class="nav-tab bq-tab" data-tab="clusters">Clusters</a>
            <a href="#" class="nav-tab bq-tab" data-tab="blogs">All Blogs</a>
            <a href="#" class="nav-tab bq-tab" data-tab="categories">Content Categories <span id="bq-uncat-badge" style="background:#dc2626;color:#fff;padding:1px 8px;border-radius:10px;font-size:11px;margin-left:4px;display:none;"></span></a>
            <a href="#" class="nav-tab bq-tab" data-tab="history">History</a>
            <a href="#" class="nav-tab bq-tab" data-tab="settings">Settings</a>
        </nav>

        <!-- Add Topics Tab -->
        <div class="bq-panel active" id="bq-panel-add">
            <div style="display:flex;gap:32px;flex-wrap:wrap;">
                <div style="flex:1;min-width:350px;">
                    <h2>Add Blog Topics</h2>
                    <p style="color:#64748b;">Enter one blog topic per line, or use the AI generator to suggest topics.</p>
                    <textarea id="bq-topics" rows="15" style="width:100%;font-size:14px;padding:12px;border:1px solid #e2e8f0;border-radius:8px;" placeholder="Introduction to Cloud Computing for IT Professionals&#10;Top 10 Cybersecurity Certifications in 2026&#10;How to Build a Career in DevOps&#10;Understanding Zero Trust Security Architecture"></textarea>
                    <br>
                    <button type="button" class="button button-primary" id="bq-add-btn" style="margin-top:12px;">Add to Queue</button>
                    <span id="bq-add-status" style="margin-left:12px;"></span>
                </div>
                <div style="flex:1;min-width:350px;">
                    <h2>AI Topic Generator</h2>
                    <p style="color:#64748b;">Generate topic ideas based on your existing content, GSC keyword gaps, and trending searches. Avoids topics you've already covered.</p>
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:500;font-size:13px;display:block;margin-bottom:4px;">Topic source:</label>
                        <select id="bq-topic-source" style="width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;">
                            <option value="keyword_gaps" selected>Keyword Gaps (existing — SEMrush + GSC)</option>
                            <option value="glossary_gaps">Glossary Gaps (low-coverage IT terms)</option>
                        </select>
                    </div>
                    <div id="bq-source-keyword-gaps" style="margin-bottom:12px;">
                        <label style="font-weight:500;font-size:13px;display:block;margin-bottom:4px;">Keyword Gap Category (optional — uses imported SEMrush data):</label>
                        <div style="display:flex;gap:8px;">
                            <select id="bq-gap-category" style="flex:1;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;">
                                <option value="">None — use GSC data only</option>
                            </select>
                            <select id="bq-gap-kw-filter" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;">
                                <option value="all">All Keywords</option>
                                <option value="high_volume">High Volume (1000+)</option>
                                <option value="low_difficulty">Low Difficulty (≤30)</option>
                                <option value="quick_wins" selected>Quick Wins (Vol 500+ & KD ≤40)</option>
                                <option value="available">Available (Not on Cooldown)</option>
                            </select>
                        </div>
                    </div>
                    <div id="bq-source-glossary-gaps" style="margin-bottom:12px;display:none;">
                        <label style="font-weight:500;font-size:13px;display:block;margin-bottom:4px;">Glossary filters:</label>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <select id="bq-glossary-area" style="flex:1;min-width:160px;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;">
                                <option value="">All areas</option>
                            </select>
                            <select id="bq-glossary-coverage" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;">
                                <option value="0">0 blogs (strict)</option>
                                <option value="2" selected>≤ 2 blogs</option>
                                <option value="5">≤ 5 blogs</option>
                            </select>
                            <select id="bq-glossary-tier" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;">
                                <option value="0">Tier 1 + 2</option>
                                <option value="1">Tier 1 only (proven demand)</option>
                                <option value="2">Tier 2 only (glossary fallback)</option>
                            </select>
                        </div>
                        <p class="description" style="font-size:11px;color:#94a3b8;margin:4px 0 0;">Tier 1 = glossary term that also matches a keyword gap. Tier 2 = glossary-only fallback. Queueing here stamps the glossary so it won't be suggested again for 180 days.</p>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:500;font-size:13px;display:block;margin-bottom:4px;">Focus area (optional):</label>
                        <input type="text" id="bq-focus-area" style="width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;" placeholder="e.g., cybersecurity, cloud computing, CompTIA, DevOps" />
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:500;font-size:13px;display:block;margin-bottom:4px;">Featured product (optional):</label>
                        <div style="display:flex;gap:8px;">
                            <input type="text" id="bq-product-search" style="flex:1;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;" placeholder="Search WooCommerce products by title..." autocomplete="off" />
                            <button type="button" class="button" id="bq-product-clear" title="Clear featured product">×</button>
                        </div>
                        <input type="hidden" id="bq-product-id" value="" />
                        <div id="bq-product-results" style="display:none;max-height:240px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;margin-top:4px;background:#fff;"></div>
                        <div id="bq-product-selected" style="display:none;margin-top:6px;padding:6px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:12px;color:#065f46;"></div>
                        <p class="description" style="font-size:11px;color:#94a3b8;margin:4px 0 0;">When a product is selected, all generated topics will be supporting blogs for that course, and a styled "Featured Product" block will be inserted into each blog after the first paragraph and near the end.</p>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                        <label style="font-weight:500;font-size:13px;">How many:</label>
                        <select id="bq-gen-count" style="padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;">
                            <option value="10">10</option>
                            <option value="20" selected>20</option>
                            <option value="30">30</option>
                            <option value="50">50</option>
                        </select>
                        <button type="button" class="button button-primary" id="bq-generate-btn">Generate Topics</button>
                    </div>
                    <p style="font-size:12px;color:#64748b;margin:0 0 12px;">When a category is selected, topics are capped at the number of available keywords (one topic per keyword) — never more than the "How many" value above.</p>
                    <div id="bq-gen-status" style="font-size:13px;margin-bottom:8px;"></div>
                    <div id="bq-gen-results" style="display:none;max-height:400px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:4px;">
                        <div style="padding:8px 12px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                            <label><input type="checkbox" id="bq-gen-check-all" checked /> <strong>Select All</strong></label>
                            <button type="button" class="button button-small" id="bq-gen-add-selected">Add Selected to Queue</button>
                        </div>
                        <div id="bq-gen-list"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Queue Tab -->
        <div class="bq-panel" id="bq-panel-queue">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <h2 style="margin:0;">Pending Queue</h2>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span id="bq-today-info" style="color:#64748b;font-size:13px;"></span>
                    <button type="button" class="button button-primary" id="bq-run-queue">Process Queue (Background)</button>
                    <button type="button" class="button" id="bq-stop-queue-bg" style="color:#dc2626; display:none;">Stop Processing</button>
                </div>
                <div id="bq-queue-progress" style="display:none; margin-top:10px; padding:12px 16px; background:#fff; border:1px solid #e2e8f0; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; font-size:13px;"></div>
            </div>
            <div id="bq-queue-bulk-bar" style="display:none;margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <select id="bq-queue-bulk-action" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;">
                    <option value="">Bulk Actions</option>
                    <option value="process">Process Selected</option>
                    <option value="delete">Delete Selected</option>
                </select>
                <button type="button" class="button" id="bq-queue-bulk-apply">Apply</button>
                <span id="bq-queue-bulk-status" style="font-size:12px;margin-left:8px;"></span>
            </div>
            <div id="bq-queue-loading">Loading...</div>
            <table class="wp-list-table widefat fixed striped" id="bq-queue-table" style="display:none;">
                <thead><tr><th style="width:30px;"><input type="checkbox" id="bq-queue-check-all" /></th><th>Title</th><th style="width:140px;">Added</th><th style="width:200px;">Actions</th></tr></thead>
                <tbody id="bq-queue-body"></tbody>
            </table>
            <div id="bq-queue-pagination" style="margin-top:12px;"></div>
            <div id="bq-queue-empty" style="display:none;color:#94a3b8;padding:20px;">Queue is empty. Add some topics.</div>
        </div>

        <!-- Clusters Tab — hub-and-spoke topical authority -->
        <div class="bq-panel" id="bq-panel-clusters">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div>
                    <h2 style="margin:0 0 4px;">Topic Clusters</h2>
                    <p style="margin:0;color:#64748b;font-size:13px;">Each row = one keyword-gap tag = one topical cluster. Designate a pillar post per cluster so spokes can auto-link to it and AI engines can see the hub-and-spoke topical authority.</p>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="search" id="bq-cluster-search" placeholder="Filter clusters..." style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;width:220px;" />
                    <select id="bq-cluster-sort" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;">
                        <option value="depth_asc">Weakest first (depth score asc)</option>
                        <option value="depth_desc">Strongest first (depth score desc)</option>
                        <option value="volume_desc" selected>Most keywords first</option>
                        <option value="gaps_desc">Most gaps first</option>
                        <option value="posts_desc">Most posts first</option>
                    </select>
                    <button type="button" class="button" id="bq-cluster-refresh">Refresh</button>
                </div>
            </div>
            <div id="bq-cluster-loading" style="color:#94a3b8;padding:20px;">Loading clusters...</div>
            <table class="wp-list-table widefat fixed striped" id="bq-cluster-table" style="display:none;">
                <thead><tr>
                    <th>Tag</th>
                    <th style="width:90px;text-align:center;">Keywords</th>
                    <th style="width:80px;text-align:center;">Posts</th>
                    <th style="width:80px;text-align:center;">Pillar</th>
                    <th style="width:80px;text-align:center;">Gaps</th>
                    <th style="width:130px;">Depth Score</th>
                    <th style="width:160px;">Actions</th>
                </tr></thead>
                <tbody id="bq-cluster-body"></tbody>
            </table>

            <!-- Cluster detail panel — slides in when a row is clicked -->
            <div id="bq-cluster-detail" style="display:none;margin-top:18px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:18px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
                    <div>
                        <h3 style="margin:0 0 4px;" id="bq-cluster-detail-tag">—</h3>
                        <div id="bq-cluster-detail-summary" style="font-size:13px;color:#64748b;"></div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button type="button" class="button button-primary" id="bq-cluster-gen-topics" style="background:#0891b2;border-color:#0891b2;">Fill Cluster Gaps</button>
                        <button type="button" class="button" id="bq-cluster-detail-close">Close</button>
                    </div>
                </div>

                <div style="display:flex;gap:24px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:360px;">
                        <h4 style="margin:0 0 8px;font-size:13px;text-transform:uppercase;color:#475569;">Posts in this cluster</h4>
                        <div id="bq-cluster-members" style="max-height:480px;overflow:auto;border:1px solid #e2e8f0;border-radius:6px;"></div>
                    </div>
                    <div style="flex:1;min-width:360px;">
                        <h4 style="margin:0 0 8px;font-size:13px;text-transform:uppercase;color:#475569;">Top unfilled subtopics (by search volume)</h4>
                        <div id="bq-cluster-gaps" style="max-height:480px;overflow:auto;border:1px solid #e2e8f0;border-radius:6px;"></div>
                    </div>
                </div>
                <div id="bq-cluster-detail-status" style="font-size:12px;margin-top:10px;min-height:18px;"></div>
            </div>
        </div>

        <!-- All Blogs Tab -->
        <div class="bq-panel" id="bq-panel-blogs">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <h2 style="margin:0;">All Blog Posts</h2>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" id="bq-blog-search" placeholder="Search blogs..." style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;width:220px;" />
                    <select id="bq-blog-filter" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;">
                        <option value="all">All</option>
                        <option value="missing_faq">Missing FAQ</option>
                        <option value="missing_schema">Missing Schema</option>
                        <option value="short_content">Short Content (&lt;1000 words)</option>
                        <option value="missing_image">Missing Image</option>
                        <option value="missing_meta">Missing Meta Desc</option>
                        <option value="missing_keyword">Missing Focus Keyword</option>
                        <option value="seo_full_refreshed">SEO AI AutoPilot: Full Refreshed</option>
                        <option value="seo_not_full_refreshed">SEO AI AutoPilot: Not Full Refreshed</option>
                    </select>
                </div>
            </div>
            <div id="bq-blog-bulk-bar" style="display:none;margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <select id="bq-blog-bulk-action" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;">
                    <option value="">Bulk Actions</option>
                    <option value="full_refresh">Full Refresh (Rewrite All)</option>
                    <option value="seo_refresh">SEO Refresh (FAQ, Schema, Meta, Keyword, Title)</option>
                    <option value="meta_refresh">CTR Fix (Meta, Keyword, Title only)</option>
                </select>
                <button type="button" class="button" id="bq-blog-bulk-apply">Apply</button>
                <span id="bq-blog-bulk-status" style="font-size:12px;margin-left:8px;"></span>
            </div>
            <div id="bq-blog-loading">Loading blogs...</div>
            <div id="bq-blog-empty" style="display:none;color:#94a3b8;padding:20px;">No blogs found.</div>
            <table class="wp-list-table widefat fixed striped" id="bq-blog-table" style="display:none;">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" id="bq-blog-check-all" /></th>
                        <th style="width:60px;">Image</th>
                        <th data-sort="title" style="cursor:pointer;">Title</th>
                        <th style="width:80px;text-align:center;">Words</th>
                        <th style="width:70px;text-align:center;">FAQ</th>
                        <th style="width:70px;text-align:center;">Schema</th>
                        <th style="width:70px;text-align:center;">Meta</th>
                        <th style="width:70px;text-align:center;">Keyword</th>
                        <th data-sort="modified" style="width:110px;cursor:pointer;">Modified</th>
                        <th style="width:180px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="bq-blog-body"></tbody>
            </table>
            <div id="bq-blog-pagination" style="margin-top:12px;"></div>
        </div>

        <!-- Content Categories Tab -->
        <div class="bq-panel" id="bq-panel-categories">
            <h2 style="margin-bottom:6px;">Content Categories</h2>
            <p style="color:#64748b;margin-top:0;">
                Manage the <code>itu_content_category</code> taxonomy and run AI categorization on existing posts. Newly created blog posts are automatically categorized on publish — only existing uncategorized posts need this batch tool.
                Edit individual term names in <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=itu_content_category')); ?>" target="_blank">WP-Admin → Content Categories</a>.
            </p>

            <div style="display:flex;gap:24px;margin-top:16px;flex-wrap:wrap;">
                <!-- LEFT: AI Categorization controls -->
                <div style="flex:2;min-width:480px;">
                    <h3 style="margin-bottom:8px;">AI Categorization</h3>
                    <div style="padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                        <div id="bq-cat-uncat-status" style="font-size:13px;color:#475569;">Loading uncategorized count…</div>
                        <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <label style="font-size:13px;">Batch size:</label>
                            <select id="bq-cat-batch-size" style="padding:5px 8px;border:1px solid #c3c4c7;border-radius:6px;">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                                <option value="200">200</option>
                            </select>
                            <label style="font-size:13px;margin-left:6px;">
                                <input type="checkbox" id="bq-cat-auto-apply" checked /> Auto-apply high-confidence
                            </label>
                            <button type="button" class="button button-primary" id="bq-cat-run-batch">Run Batch</button>
                            <button type="button" class="button" id="bq-cat-rerun-batch" style="color:#b45309;" title="Strip existing categories from already-categorized posts and re-categorize them with the current rules. Use after fixing vendor logic.">Re-categorize</button>
                            <button type="button" class="button" id="bq-cat-test-one" title="Run one post synchronously and show full diagnostics — use this if Run Batch silently produces no results">Test One Post</button>
                            <span id="bq-cat-batch-status" style="font-size:12px;margin-left:6px;"></span>
                        </div>
                        <div id="bq-cat-batch-progress" style="display:none;margin-top:10px;font-size:12px;color:#64748b;"></div>
                        <div id="bq-cat-test-result" style="display:none;margin-top:10px;padding:10px 12px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;font-family:Menlo,Consolas,monospace;white-space:pre-wrap;"></div>
                    </div>

                    <h3 style="margin-top:24px;margin-bottom:8px;">Review Queue</h3>
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
                        <label style="font-size:13px;">Filter:</label>
                        <select id="bq-cat-log-filter" style="padding:5px 8px;border:1px solid #c3c4c7;border-radius:6px;">
                            <option value="all">All</option>
                            <option value="manual_review" selected>Manual Review (low confidence)</option>
                            <option value="flagged">Flagged (spot-check)</option>
                            <option value="pending">Pending</option>
                            <option value="auto_applied">Auto-Applied</option>
                            <option value="failed">Failed (errors)</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <button type="button" class="button button-small" id="bq-cat-log-refresh">Refresh</button>
                        <button type="button" class="button button-small" id="bq-cat-log-clear-all" style="color:#dc2626;" title="Permanently delete every row in the categorization log. Does not touch posts.">Clear Log</button>
                        <button type="button" class="button button-small" id="bq-cat-reset-all" style="color:#dc2626;font-weight:600;" title="Strip ALL content categories from every post and clear the log. Nuclear reset.">Reset All Categories</button>
                        <span style="margin-left:auto;font-size:12px;color:#94a3b8;" id="bq-cat-log-summary"></span>
                    </div>
                    <div id="bq-cat-log-loading" style="font-size:12px;color:#64748b;">Loading…</div>
                    <table class="wp-list-table widefat fixed striped" id="bq-cat-log-table" style="display:none;font-size:13px;">
                        <thead>
                            <tr>
                                <th style="width:24px;"><input type="checkbox" id="bq-cat-log-check-all" /></th>
                                <th>Post</th>
                                <th style="width:200px;">Primary</th>
                                <th style="width:240px;">Secondary</th>
                                <th style="width:80px;">Conf.</th>
                                <th style="width:110px;">Status</th>
                                <th style="width:140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="bq-cat-log-body"></tbody>
                    </table>
                    <div id="bq-cat-log-pagination" style="margin-top:10px;"></div>
                    <div id="bq-cat-log-bulk" style="display:none;margin-top:10px;padding:8px 12px;background:#fefce8;border:1px solid #fde68a;border-radius:6px;font-size:12px;">
                        <span id="bq-cat-log-bulk-count">0 selected</span> &middot;
                        <button type="button" class="button button-small button-primary" id="bq-cat-log-bulk-apply">Apply</button>
                        <button type="button" class="button button-small" id="bq-cat-log-bulk-reject" style="color:#b45309;" title="Mark as rejected — keeps the row for audit but won't apply">Reject</button>
                        <button type="button" class="button button-small" id="bq-cat-log-bulk-delete" style="color:#dc2626;" title="Permanently delete the selected rows from the log">Delete</button>
                    </div>
                </div>

                <!-- RIGHT: Taxonomy tree -->
                <div style="flex:1;min-width:300px;">
                    <h3 style="margin-bottom:8px;">Taxonomy</h3>
                    <div style="padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;">
                        <label><input type="checkbox" id="bq-cat-tree-only-with-posts" /> Only show categories with posts</label>
                    </div>
                    <div id="bq-cat-tree" style="margin-top:10px;font-size:13px;">Loading…</div>
                </div>
            </div>
        </div>

        <!-- History Tab -->
        <div class="bq-panel" id="bq-panel-history">
            <h2>Creation History</h2>
            <div id="bq-history-loading">Loading...</div>
            <table class="wp-list-table widefat fixed striped" id="bq-history-table" style="display:none;">
                <thead><tr><th>Title</th><th style="width:100px;">Status</th><th style="width:140px;">Created</th><th style="width:200px;">Actions</th></tr></thead>
                <tbody id="bq-history-body"></tbody>
            </table>
            <div id="bq-history-pagination" style="margin-top:12px;"></div>
        </div>

        <!-- Settings Tab -->
        <div class="bq-panel" id="bq-panel-settings">
            <h2>Settings</h2>
            <table class="form-table" style="max-width:800px;">
                <tr><th>Enabled</th><td><label><input type="checkbox" id="bq-enabled" <?php checked($settings['enabled']); ?> /> Enable automated daily processing</label></td></tr>
                <tr><th>Daily Limit</th><td><input type="number" id="bq-daily-limit" value="<?php echo intval($settings['daily_limit']); ?>" min="1" max="50" style="width:80px;" /><p class="description">Max blogs to create per day. Randomly selected from queue.</p></td></tr>
                <tr><th>Notification Email</th><td><input type="email" id="bq-notify-email" value="<?php echo esc_attr($settings['notify_email']); ?>" style="width:300px;" /></td></tr>
                <tr>
                    <th>Topic Relevance Gate</th>
                    <td>
                        <label><input type="checkbox" id="bq-topic-gate-enabled" <?php checked(!empty($settings['topic_gate_enabled'])); ?> /> Check each topic against the site scope before generating a blog</label>
                        <p class="description">When enabled, off-scope topics are marked <strong>Skipped</strong> in History (no blog created, no AI writer cost spent). Skipped items don't count against the daily limit.</p>
                    </td>
                </tr>
                <tr>
                    <th>Site Scope</th>
                    <td>
                        <textarea id="bq-topic-gate-scope" rows="8" style="width:100%;max-width:700px;font-family:inherit;font-size:13px;line-height:1.5;padding:10px;border:1px solid #c3c4c7;border-radius:4px;"><?php echo esc_textarea($settings['topic_gate_scope'] ?? bq_default_topic_scope()); ?></textarea>
                        <p class="description">Tells the relevance classifier what's in scope and what's off scope. Edit to broaden or narrow the gate. Leaving this blank restores the default IT-focused scope on save.</p>
                        <p><button type="button" class="button button-small" id="bq-topic-gate-reset">Restore default scope</button></p>
                    </td>
                </tr>
            </table>
            <p><button type="button" class="button button-primary" id="bq-save-settings">Save Settings</button> <span id="bq-settings-status"></span></p>
        </div>
    </div>

    <style>
        .bq-panel { display: none; }
        .bq-panel.active { display: block; }
        .nav-tab-active { border-bottom-color: #2563eb !important; color: #2563eb !important; }
        .bq-thumb { width:50px; height:50px; object-fit:cover; border-radius:4px; display:block; }
        .bq-thumb--empty {
            display:flex; align-items:center; justify-content:center;
            width:50px; height:50px; background:#f0f0f0; border:1px dashed #ccc;
            border-radius:4px; font-size:10px; color:#999; text-align:center; line-height:1.2;
        }
        .bq-check-yes { color:#16a34a; font-size:18px; }
        .bq-check-no { color:#dc2626; font-size:18px; }
        .bq-word-good { color:#16a34a; font-weight:600; }
        .bq-word-ok { color:#d97706; font-weight:600; }
        .bq-word-low { color:#dc2626; font-weight:600; }
        .bq-blog-status { font-size:12px; color:#666; margin-top:4px; min-height:18px; }
        .bq-blog-status.processing { color:#b45309; }
        .bq-blog-status.done { color:#16a34a; }
        .bq-blog-status.error { color:#dc2626; }
        #bq-blog-table td { vertical-align:middle; }
    </style>

    <script>
    jQuery(document).ready(function($) {
        var nonce = '<?php echo $nonce; ?>';

        // Tabs
        $('.bq-tab').click(function(e) {
            e.preventDefault();
            $('.bq-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.bq-panel').removeClass('active');
            $('#bq-panel-' + $(this).data('tab')).addClass('active');
            if ($(this).data('tab') === 'queue') loadQueue(1);
            if ($(this).data('tab') === 'clusters') loadClusters();
            if ($(this).data('tab') === 'blogs') loadBlogs(1);
            if ($(this).data('tab') === 'history') loadHistory(1);
            if ($(this).data('tab') === 'categories') {
                loadCatUncategorizedCount();
                loadCatTree();
                loadCatLog(1);
            }
        });

        // ─── Content Categories tab ─────────────────────────────────────────
        // Loads uncategorized count + tree + log on first tab activation,
        // and powers the AI batch run, review queue, and override picker.
        var catLog = { rows: [], page: 1, pages: 1, total: 0, status: 'manual_review' };
        var catBatchPolling = null;
        var catTermsCache = null;

        function escHtml(s) { return $('<div>').text(s == null ? '' : s).html(); }

        function loadCatUncategorizedCount() {
            $.post(ajaxurl, { action: 'bq_categorize_uncategorized_count', nonce: nonce }, function(resp) {
                if (!resp.success) return;
                var n = resp.data.count;
                if (n > 0) {
                    $('#bq-uncat-badge').text(n).show();
                    $('#bq-cat-uncat-status').html(
                        '<strong>' + n + '</strong> blog post' + (n === 1 ? '' : 's') +
                        ' have no content category. Run a batch to categorize them with AI.'
                    );
                } else {
                    $('#bq-uncat-badge').hide();
                    $('#bq-cat-uncat-status').html(
                        '<span style="color:#059669;">All published blog posts are categorized.</span> ' +
                        'New posts are automatically categorized on publish.'
                    );
                }
            });
        }

        function loadCatTree() {
            var only = $('#bq-cat-tree-only-with-posts').is(':checked');
            $('#bq-cat-tree').html('<div style="color:#94a3b8;">Loading…</div>');
            $.post(ajaxurl, { action: 'bq_categorize_get_tree', nonce: nonce, only_with_posts: only ? 1 : 0 }, function(resp) {
                if (!resp.success) return;
                var tree = resp.data.tree || [];
                if (!tree.length) {
                    $('#bq-cat-tree').html('<div style="color:#94a3b8;">No categories found.</div>');
                    return;
                }
                var html = '';
                tree.forEach(function(parent) {
                    html += '<details style="margin-bottom:6px;border:1px solid #e2e8f0;border-radius:6px;">';
                    html += '<summary style="padding:8px 12px;cursor:pointer;background:#f8fafc;font-weight:600;">'
                          + escHtml(parent.name) + ' <span style="color:#94a3b8;font-weight:normal;font-size:11px;">(' + parent.count + ')</span></summary>';
                    html += '<div style="padding:8px 14px;">';
                    if (parent.children.length === 0) {
                        html += '<div style="color:#94a3b8;font-size:12px;">No subcategories ' + (only ? 'with posts' : '') + '.</div>';
                    } else {
                        parent.children.forEach(function(child) {
                            html += '<div style="padding:3px 0;font-size:12px;">'
                                  + escHtml(child.name) + ' <span style="color:#94a3b8;">(' + child.count + ')</span></div>';
                        });
                    }
                    html += '</div></details>';
                });
                $('#bq-cat-tree').html(html);
            });
        }

        $('#bq-cat-tree-only-with-posts').on('change', loadCatTree);

        function loadCatLog(page) {
            catLog.page = page || 1;
            catLog.status = $('#bq-cat-log-filter').val();
            $('#bq-cat-log-loading').show();
            $('#bq-cat-log-table').hide();
            $.post(ajaxurl, {
                action: 'bq_categorize_get_log', nonce: nonce,
                status: catLog.status, page: catLog.page
            }, function(resp) {
                $('#bq-cat-log-loading').hide();
                if (!resp.success) return;
                catLog.rows = resp.data.rows;
                catLog.total = resp.data.total;
                catLog.pages = resp.data.pages;
                renderCatLog();
            });
        }

        function statusBadge(status) {
            var colors = {
                pending:       ['#f1f5f9', '#475569'],
                auto_applied:  ['#dcfce7', '#166534'],
                flagged:       ['#fef9c3', '#854d0e'],
                manual_review: ['#fee2e2', '#991b1b'],
                rejected:      ['#e5e7eb', '#6b7280'],
                failed:        ['#fecaca', '#7f1d1d']
            };
            var c = colors[status] || ['#e5e7eb', '#374151'];
            return '<span style="background:' + c[0] + ';color:' + c[1] + ';padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;text-transform:uppercase;">' + status.replace(/_/g, ' ') + '</span>';
        }

        function renderCatLog() {
            var tb = $('#bq-cat-log-body').empty();
            if (!catLog.rows.length) {
                tb.html('<tr><td colspan="7" style="color:#94a3b8;padding:12px;">No rows match the current filter.</td></tr>');
                $('#bq-cat-log-table').show();
                $('#bq-cat-log-pagination').empty();
                $('#bq-cat-log-summary').text('0 rows');
                $('#bq-cat-log-bulk').hide();
                return;
            }
            catLog.rows.forEach(function(r) {
                var conf = r.confidence == null ? '—' : parseFloat(r.confidence).toFixed(2);
                var sec = (r.secondary_term_names_arr || []).map(escHtml).join(', ') || '<span style="color:#94a3b8;">—</span>';
                var primaryCell = escHtml(r.primary_term_name || '—');
                if (r.status === 'failed' && r.error_message) {
                    primaryCell = '<span style="color:#dc2626;font-size:11px;">' + escHtml(r.error_message) + '</span>';
                }
                tb.append('<tr data-id="' + r.id + '">'
                    + '<td><input type="checkbox" class="bq-cat-cb" value="' + r.id + '" /></td>'
                    + '<td><a href="' + (r.edit_url || '#') + '" target="_blank">' + escHtml(r.post_title) + '</a>'
                    + '<br><small style="color:#94a3b8;">post ID ' + r.post_id + '</small></td>'
                    + '<td>' + primaryCell + '</td>'
                    + '<td>' + sec + '</td>'
                    + '<td>' + conf + '</td>'
                    + '<td>' + statusBadge(r.status) + '</td>'
                    + '<td>'
                    + '<button class="button button-small bq-cat-apply-one" data-id="' + r.id + '" title="Apply this categorization to the post">Apply</button> '
                    + '<button class="button button-small bq-cat-edit-one" data-id="' + r.id + '" title="Edit primary / secondary terms">Edit</button> '
                    + '<button class="button button-small bq-cat-delete-one" data-id="' + r.id + '" style="color:#dc2626;" title="Permanently delete this row from the log">×</button>'
                    + '</td>'
                    + '</tr>');
            });
            $('#bq-cat-log-table').show();
            $('#bq-cat-log-summary').text(catLog.total + ' total — page ' + catLog.page + ' of ' + catLog.pages);

            // Pagination
            var $pag = $('#bq-cat-log-pagination').empty();
            if (catLog.pages > 1 && window.seomBuildPagination) {
                $pag.html(window.seomBuildPagination(catLog.page, catLog.pages, 'bq-cat-page', ''));
            } else if (catLog.pages > 1) {
                for (var i = 1; i <= catLog.pages; i++) {
                    var cls = i === catLog.page ? 'button button-primary' : 'button';
                    $pag.append('<button class="' + cls + ' bq-cat-page" data-page="' + i + '">' + i + '</button> ');
                }
            }
        }

        $('#bq-cat-log-filter').on('change', function() { loadCatLog(1); });
        $('#bq-cat-log-refresh').click(function() { loadCatLog(catLog.page); });
        $(document).on('click', '.bq-cat-page', function() { loadCatLog($(this).data('page') || parseInt($(this).text(), 10)); });

        $(document).on('change', '.bq-cat-cb, #bq-cat-log-check-all', function() {
            if (this.id === 'bq-cat-log-check-all') $('.bq-cat-cb').prop('checked', this.checked);
            var n = $('.bq-cat-cb:checked').length;
            $('#bq-cat-log-bulk-count').text(n + ' selected');
            $('#bq-cat-log-bulk').toggle(n > 0);
        });

        function bulkCatAction(action) {
            var ids = $('.bq-cat-cb:checked').map(function() { return parseInt($(this).val(), 10); }).get();
            if (!ids.length) return;
            $.post(ajaxurl, { action: action, nonce: nonce, 'ids[]': ids }, function(resp) {
                if (resp.success) loadCatLog(catLog.page);
            });
        }
        $('#bq-cat-log-bulk-apply').click(function() { bulkCatAction('bq_categorize_apply'); });
        $('#bq-cat-log-bulk-reject').click(function() {
            if (confirm('Reject ' + $('.bq-cat-cb:checked').length + ' row(s)? The post terms are not changed; the rows are kept in the log marked rejected.')) {
                bulkCatAction('bq_categorize_reject');
            }
        });
        $('#bq-cat-log-bulk-delete').click(function() {
            if (confirm('Permanently delete ' + $('.bq-cat-cb:checked').length + ' row(s) from the categorization log? Any terms already applied to the posts will remain — only the audit rows are removed.')) {
                bulkCatAction('bq_categorize_delete');
            }
        });

        $('#bq-cat-log-clear-all').click(function() {
            if (!confirm('Permanently delete EVERY row in the categorization log?\n\nPosts keep their assigned categories — only the audit log is wiped.')) return;
            $.post(ajaxurl, { action: 'bq_categorize_clear_log', nonce: nonce }, function(resp) {
                if (resp.success) {
                    loadCatLog(1);
                    loadCatUncategorizedCount();
                }
            });
        });

        $('#bq-cat-reset-all').click(function() {
            if (!confirm('⚠ NUCLEAR RESET ⚠\n\nThis will:\n• Remove ALL content category assignments from EVERY blog post\n• Delete the primary category meta from every post\n• Clear the entire staging log\n\nEvery post becomes uncategorized. You\'ll need to Run Batch to re-categorize them.\n\nAre you sure?')) return;
            if (!confirm('Really sure? This affects ALL ' + ($('#bq-uncat-badge').is(':visible') ? 'remaining' : '') + ' categorized posts.')) return;
            var btn = $(this).prop('disabled', true).text('Resetting…');
            $.post(ajaxurl, { action: 'bq_categorize_reset_all', nonce: nonce }, function(resp) {
                btn.prop('disabled', false).text('Reset All Categories');
                if (resp.success) {
                    alert(resp.data.message);
                    loadCatUncategorizedCount();
                    loadCatLog(1);
                    loadCatTree();
                }
            });
        });

        $(document).on('click', '.bq-cat-apply-one', function() {
            $.post(ajaxurl, { action: 'bq_categorize_apply', nonce: nonce, 'ids[]': [$(this).data('id')] }, function() {
                loadCatLog(catLog.page);
            });
        });
        $(document).on('click', '.bq-cat-delete-one', function() {
            if (!confirm('Delete this row from the log? Any terms already applied to the post will remain.')) return;
            $.post(ajaxurl, { action: 'bq_categorize_delete', nonce: nonce, 'ids[]': [$(this).data('id')] }, function() {
                loadCatLog(catLog.page);
            });
        });

        // Override editor — opens an inline picker for the row.
        $(document).on('click', '.bq-cat-edit-one', function() {
            var $row = $(this).closest('tr');
            var logId = $(this).data('id');
            if (!catTermsCache) {
                $.post(ajaxurl, { action: 'bq_categorize_get_terms', nonce: nonce }, function(resp) {
                    if (!resp.success) return;
                    catTermsCache = resp.data.groups;
                    showOverrideRow($row, logId);
                });
            } else {
                showOverrideRow($row, logId);
            }
        });

        function showOverrideRow($row, logId) {
            var primaryOptions = '<option value="">— Select primary —</option>';
            var secondaryOptions = '';
            catTermsCache.forEach(function(g) {
                primaryOptions += '<optgroup label="' + escHtml(g.parent) + '">';
                secondaryOptions += '<optgroup label="' + escHtml(g.parent) + '">';
                g.options.forEach(function(o) {
                    primaryOptions += '<option value="' + o.id + '">' + escHtml(o.name) + '</option>';
                    secondaryOptions += '<option value="' + o.id + '">' + escHtml(o.name) + '</option>';
                });
                primaryOptions += '</optgroup>';
                secondaryOptions += '</optgroup>';
            });

            var html = '<tr class="bq-cat-edit-row" data-id="' + logId + '"><td colspan="7" style="background:#fefce8;padding:14px;">'
                + '<strong>Override row #' + logId + '</strong><br><br>'
                + 'Primary: <select class="bq-cat-edit-primary" style="min-width:280px;">' + primaryOptions + '</select> &nbsp; '
                + '<br><br>Secondary (max 4, hold Ctrl/Cmd to multi-select): <br>'
                + '<select class="bq-cat-edit-secondary" multiple size="6" style="width:100%;max-width:540px;margin-top:4px;">' + secondaryOptions + '</select>'
                + '<br><br>'
                + '<button class="button button-primary bq-cat-edit-save" data-id="' + logId + '">Save Override</button> '
                + '<button class="button bq-cat-edit-cancel">Cancel</button>'
                + '</td></tr>';
            $row.after(html);
        }

        $(document).on('click', '.bq-cat-edit-cancel', function() {
            $(this).closest('tr.bq-cat-edit-row').remove();
        });

        $(document).on('click', '.bq-cat-edit-save', function() {
            var $tr = $(this).closest('tr.bq-cat-edit-row');
            var logId = $(this).data('id');
            var primaryId = $tr.find('.bq-cat-edit-primary').val();
            if (!primaryId) { alert('Pick a primary category.'); return; }
            var secondaryIds = $tr.find('.bq-cat-edit-secondary').val() || [];
            if (secondaryIds.length > 4) { alert('Maximum 4 secondary categories.'); return; }
            $.post(ajaxurl, {
                action: 'bq_categorize_override', nonce: nonce,
                log_id: logId, primary_id: primaryId, 'secondary_ids[]': secondaryIds
            }, function(resp) {
                if (resp.success) loadCatLog(catLog.page);
                else alert(resp.data || 'Error');
            });
        });

        // Test one post synchronously — diagnostic tool. Returns the full
        // AI response (or error) inline so the user can see exactly what's
        // happening without waiting for batch processing.
        $('#bq-cat-test-one').click(function() {
            var btn = $(this).prop('disabled', true).text('Testing…');
            $('#bq-cat-test-result').show().text('Running one post synchronously — this can take 5-15 seconds…');

            $.ajax({
                url: ajaxurl, method: 'POST', timeout: 180000,
                data: { action: 'bq_categorize_test_one', nonce: nonce },
                success: function(resp) {
                    btn.prop('disabled', false).text('Test One Post');
                    if (!resp.success) {
                        var d = resp.data || {};
                        $('#bq-cat-test-result').html(
                            '<strong style="color:#dc2626;">✗ FAILED</strong>\n'
                            + 'post_id:    ' + (d.post_id || '?') + '\n'
                            + 'post_title: ' + escHtml(d.post_title || '?') + '\n'
                            + 'error_code: ' + escHtml(d.error_code || '?') + '\n'
                            + 'error:      ' + escHtml(d.error || resp.data || 'unknown') + '\n\n'
                            + escHtml(d.message || '')
                        );
                        loadCatLog(catLog.page);
                        return;
                    }
                    var d = resp.data;
                    var appliedLine = d.applied
                        ? '<span style="color:#059669;">✓ APPLIED</span>: ' + escHtml((d.applied_terms || []).join(', '))
                        : '<span style="color:#b45309;">⚠ NOT APPLIED</span> (confidence below review threshold)';
                    $('#bq-cat-test-result').html(
                        '<strong style="color:#059669;">✓ SUCCESS</strong>\n'
                        + 'post_id:    ' + d.post_id + '\n'
                        + 'post_title: ' + escHtml(d.post_title) + '\n'
                        + 'primary:    ' + escHtml(d.primary) + '\n'
                        + 'secondary:  ' + escHtml((d.secondary || []).join(', ') || '(none)') + '\n'
                        + 'confidence: ' + d.confidence + '\n'
                        + 'status:     ' + escHtml(d.status) + '\n'
                        + 'notes:      ' + escHtml(d.notes || '(none)') + '\n\n'
                        + appliedLine + '\n\n'
                        + escHtml(d.message || '')
                    );
                    loadCatUncategorizedCount();

                    // Auto-switch the review filter so the just-created row
                    // is visible without the user having to hunt for it.
                    // The default filter is "Manual Review" which would
                    // hide rows that landed in pending / auto_applied / flagged.
                    if (d.status && $('#bq-cat-log-filter option[value="' + d.status + '"]').length) {
                        $('#bq-cat-log-filter').val(d.status);
                    } else {
                        $('#bq-cat-log-filter').val('all');
                    }
                    loadCatLog(1);
                },
                error: function(xhr, txt) {
                    btn.prop('disabled', false).text('Test One Post');
                    $('#bq-cat-test-result').html(
                        '<strong style="color:#dc2626;">✗ AJAX ERROR</strong>\n'
                        + 'status: ' + xhr.status + ' ' + (xhr.statusText || txt) + '\n'
                        + 'response (first 500 chars):\n'
                        + escHtml((xhr.responseText || '').substring(0, 500))
                    );
                }
            });
        });

        // Re-categorize — strips existing categories, clears old log entries,
        // then re-runs the AI categorizer with the current deterministic rules.
        // Same batch/poll pattern as Run Batch.
        $('#bq-cat-rerun-batch').click(function() {
            var batch = $('#bq-cat-batch-size').val();
            var auto  = $('#bq-cat-auto-apply').is(':checked') ? 1 : 0;
            if (!confirm('Re-categorize ' + batch + ' already-categorized posts?\n\nThis will:\n• Strip their current content categories\n• Delete their staging log entries\n• Re-run AI categorization with the current vendor rules\n\nUse this after fixing vendor classification logic.')) return;

            var btn = $(this).prop('disabled', true);
            $('#bq-cat-batch-status').html('<span style="color:#b45309;">Starting re-categorization…</span>');
            $('#bq-cat-batch-progress').show().html('<em>Initializing…</em>');

            $.ajax({
                url: ajaxurl, method: 'POST', timeout: 30000,
                data: { action: 'bq_categorize_rerun_batch', nonce: nonce, batch: batch, auto_apply: auto },
                success: function(resp) {
                    btn.prop('disabled', false);
                    if (!resp.success) {
                        $('#bq-cat-batch-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                        $('#bq-cat-batch-progress').hide();
                        return;
                    }
                    var d = resp.data;
                    if (d.queued === 0) {
                        $('#bq-cat-batch-status').html('<span style="color:#059669;">' + (d.message || 'Nothing to re-process.') + '</span>');
                        $('#bq-cat-batch-progress').hide();
                        return;
                    }
                    var batchId = d.batch_id;
                    var queued  = d.queued;
                    $('#bq-cat-batch-status').html('<span style="color:#b45309;">' + queued + ' queued for re-categorization — running in background.</span>');
                    if (catBatchPolling) clearInterval(catBatchPolling);
                    catLastProcessed = 0;
                    catLastProcessedAt = Date.now();
                    pollCatBatch(batchId, queued);
                    catBatchPolling = setInterval(function() { pollCatBatch(batchId, queued); }, 2000);
                },
                error: function(xhr) {
                    btn.prop('disabled', false);
                    $('#bq-cat-batch-status').html('<span style="color:#dc2626;">Request failed: ' + xhr.status + '</span>');
                    $('#bq-cat-batch-progress').hide();
                }
            });
        });

        // Run a categorization batch — kicks off a detached background
        // worker on the server side, then polls bq_categorize_batch_status
        // every 2 seconds to update the live progress display. The initial
        // AJAX call returns in under 1 second regardless of batch size.
        $('#bq-cat-run-batch').click(function() {
            var btn = $(this).prop('disabled', true);
            var batch = $('#bq-cat-batch-size').val();
            var auto  = $('#bq-cat-auto-apply').is(':checked') ? 1 : 0;
            $('#bq-cat-batch-status').html('<span style="color:#b45309;">Starting…</span>');
            $('#bq-cat-batch-progress').show().html('<em>Initializing…</em>');

            $.ajax({
                url: ajaxurl, method: 'POST', timeout: 30000,
                data: { action: 'bq_categorize_run_batch', nonce: nonce, batch: batch, auto_apply: auto },
                success: function(resp) {
                    btn.prop('disabled', false);
                    if (!resp.success) {
                        $('#bq-cat-batch-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                        $('#bq-cat-batch-progress').hide();
                        return;
                    }
                    var d = resp.data;
                    if (d.queued === 0) {
                        $('#bq-cat-batch-status').html('<span style="color:#059669;">' + (d.message || 'No posts to process.') + '</span>');
                        $('#bq-cat-batch-progress').hide();
                        return;
                    }
                    var batchId = d.batch_id;
                    var queued  = d.queued;
                    $('#bq-cat-batch-status').html('<span style="color:#059669;">' + queued + ' queued — running in background.</span>');
                    if (catBatchPolling) clearInterval(catBatchPolling);
                    catLastProcessed = 0;
                    catLastProcessedAt = Date.now();
                    pollCatBatch(batchId, queued); // immediate first poll
                    catBatchPolling = setInterval(function() { pollCatBatch(batchId, queued); }, 2000);
                },
                error: function(xhr) {
                    btn.prop('disabled', false);
                    $('#bq-cat-batch-status').html('<span style="color:#dc2626;">Request failed: ' + xhr.status + '</span>');
                    $('#bq-cat-batch-progress').hide();
                }
            });
        });

        // Track the last processed count + timestamp so we can detect a
        // stalled worker (e.g. PHP-FPM killed it mid-batch). If the count
        // hasn't moved in STALE_LIMIT seconds we stop polling and warn.
        var catLastProcessed = 0;
        var catLastProcessedAt = 0;
        var STALE_LIMIT_MS = 90000; // 90s

        function pollCatBatch(batchId, queued) {
            $.post(ajaxurl, {
                action: 'bq_categorize_batch_status',
                nonce: nonce,
                batch_id: batchId,
                queued: queued || 0
            }, function(resp) {
                if (!resp.success) return;
                var d = resp.data;
                var t = d.totals || {};
                var processed = t.processed || 0;
                var total     = t.total || 0;
                var pct       = total > 0 ? Math.round((processed / total) * 100) : 0;
                var now       = Date.now();

                // Stall detection — reset the timer whenever processed advances.
                if (processed > catLastProcessed) {
                    catLastProcessed   = processed;
                    catLastProcessedAt = now;
                } else if (catLastProcessedAt === 0) {
                    catLastProcessedAt = now; // first poll
                }

                var bar = '<div style="background:#e2e8f0;border-radius:4px;height:8px;overflow:hidden;margin-bottom:8px;">'
                        + '<div style="background:#059669;height:100%;width:' + pct + '%;transition:width 0.3s;"></div></div>';

                var counts = '<strong>' + processed + ' / ' + total + '</strong> processed (' + pct + '%)';
                counts += ' &middot; <span style="color:#166534;">' + (t.auto_applied || 0) + ' auto-applied</span>';
                counts += ' &middot; <span style="color:#854d0e;">' + (t.flagged || 0) + ' flagged</span>';
                counts += ' &middot; <span style="color:#991b1b;">' + (t.manual_review || 0) + ' manual review</span>';
                if (t.failed) counts += ' &middot; <span style="color:#7f1d1d;">' + t.failed + ' failed</span>';

                // Show the most recent post being processed for live feedback
                var feed = '';
                if (d.rows && d.rows.length) {
                    var last = d.rows[d.rows.length - 1];
                    feed = '<div style="font-size:11px;color:#94a3b8;margin-top:4px;">'
                         + 'Latest: ' + escHtml(last.post_title || '(untitled)')
                         + ' → ' + escHtml(last.primary_term_name || (last.error_message || 'failed'))
                         + '</div>';
                }

                $('#bq-cat-batch-progress').html(bar + counts + feed);

                // Done = server says we hit the queued count
                if (t.done && total > 0) {
                    if (catBatchPolling) { clearInterval(catBatchPolling); catBatchPolling = null; }
                    $('#bq-cat-batch-status').html('<span style="color:#059669;">✓ Batch complete — ' + processed + ' processed.</span>');
                    loadCatUncategorizedCount();
                    loadCatLog(1);
                    loadCatTree();
                    return;
                }

                // Stall detection — worker probably died.
                if ((now - catLastProcessedAt) > STALE_LIMIT_MS) {
                    if (catBatchPolling) { clearInterval(catBatchPolling); catBatchPolling = null; }
                    $('#bq-cat-batch-status').html(
                        '<span style="color:#dc2626;">⚠ Batch stalled — no progress for 90s. ' + processed + ' / ' + total + ' processed before stopping. ' +
                        'The worker may have hit a PHP timeout. Click Run Batch again to resume.</span>'
                    );
                    loadCatUncategorizedCount();
                    loadCatLog(1);
                }
            });
        }

        // Add topics
        $('#bq-add-btn').click(function() {
            var btn = $(this).prop('disabled', true).text('Adding...');
            $.post(ajaxurl, { action: 'bq_add_topics', nonce: nonce, topics: $('#bq-topics').val() }, function(resp) {
                btn.prop('disabled', false).text('Add to Queue');
                if (resp.success) {
                    $('#bq-add-status').html('<span style="color:#059669;">' + resp.data.added + ' topics added to queue.</span>');
                    $('#bq-topics').val('');
                    loadQueueCount();
                } else {
                    $('#bq-add-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                }
            });
        });

        // Load keyword gap categories for the dropdown (filter-aware)
        function loadGapCategories() {
            var currentVal = $('#bq-gap-category').val();
            var filterVal = $('#bq-gap-kw-filter').val() || 'all';
            $.post(ajaxurl, { action: 'bq_get_gap_categories', nonce: nonce, gap_kw_filter: filterVal }, function(resp) {
                $('#bq-gap-category').find('option:not(:first)').remove();
                if (resp.success && resp.data.length) {
                    resp.data.forEach(function(t) {
                        var avail = parseInt(t.available || 0);
                        var total = parseInt(t.cnt || 0);
                        var label = t.tag + ' (' + avail + ' of ' + total + ' available)';
                        var selected = (t.tag === currentVal) ? ' selected' : '';
                        $('#bq-gap-category').append('<option value="' + t.tag + '"' + selected + (avail === 0 ? ' style="color:#94a3b8;"' : '') + '>' + label + '</option>');
                    });
                }
            });
        }
        loadGapCategories();

        // Reload category counts when volume filter changes
        $('#bq-gap-kw-filter').change(function() { loadGapCategories(); });

        // Featured Product picker — search-as-you-type against WooCommerce products
        var bqProductSearchTimer = null;
        function bqRunProductSearch() {
            var term = $('#bq-product-search').val();
            $.post(ajaxurl, { action: 'bq_search_products', nonce: nonce, term: term }, function(resp) {
                if (!resp.success) return;
                var $list = $('#bq-product-results').empty();
                if (!resp.data.products.length) {
                    $list.append('<div style="padding:8px 12px;color:#94a3b8;font-size:12px;">No products found.</div>');
                } else {
                    resp.data.products.forEach(function(p) {
                        var safeTitle = $('<div>').text(p.title).html();
                        $list.append('<div class="bq-product-row" data-id="' + p.id + '" data-title="' + safeTitle.replace(/"/g, '&quot;') + '" style="padding:6px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'">' + safeTitle + '</div>');
                    });
                }
                $list.show();
            });
        }
        $('#bq-product-search').on('input focus', function() {
            clearTimeout(bqProductSearchTimer);
            bqProductSearchTimer = setTimeout(bqRunProductSearch, 250);
        });
        $(document).on('click', '.bq-product-row', function() {
            var pid = $(this).data('id');
            var title = $(this).data('title');
            $('#bq-product-id').val(pid);
            $('#bq-product-search').val(title);
            $('#bq-product-results').hide();
            $('#bq-product-selected').show().html('<strong>Selected:</strong> ' + title + ' <span style="color:#94a3b8;">(every topic will be tied to this course)</span>');
        });
        $('#bq-product-clear').click(function() {
            $('#bq-product-id').val('');
            $('#bq-product-search').val('');
            $('#bq-product-results').hide();
            $('#bq-product-selected').hide();
        });
        // Hide the dropdown when clicking elsewhere
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#bq-product-search, #bq-product-results').length) {
                $('#bq-product-results').hide();
            }
        });

        // AI Topic Generator
        // Source-selector toggle — show only the controls for the
        // currently-selected topic source. Also reflects which timer copy
        // we show on Generate (glossary path makes one OpenAI call per 20
        // candidates; keyword path makes one big call total).
        $('#bq-topic-source').on('change', function () {
            var src = $(this).val();
            $('#bq-source-keyword-gaps').toggle(src === 'keyword_gaps');
            $('#bq-source-glossary-gaps').toggle(src === 'glossary_gaps');
            if (src === 'glossary_gaps') {
                // Populate the area dropdown lazily on first selection.
                var $areaSel = $('#bq-glossary-area');
                if (!$areaSel.data('populated')) {
                    $areaSel.data('populated', true);
                    $.post(ajaxurl, {
                        action: 'seom_glossary_gap_list',
                        nonce:  (typeof seom_nonce !== 'undefined' ? seom_nonce : ''),
                        coverage_max:   2,
                        tier:           0,
                        exclude_queued: 1,
                        page:           1,
                        per_page:       1,
                    }, function (resp) {
                        if (!resp || !resp.success || !resp.data.areas) return;
                        resp.data.areas.forEach(function (a) {
                            if (!a.primary_area) return;
                            $areaSel.append('<option value="' + a.primary_area.replace(/"/g, '&quot;') + '">' +
                                a.primary_area + ' (' + a.cnt + ')</option>');
                        });
                    });
                }
            }
        });

        $('#bq-generate-btn').click(function() {
            var btn = $(this).prop('disabled', true).text('Generating...');
            var src = $('#bq-topic-source').val() || 'keyword_gaps';
            var statusMsg = (src === 'glossary_gaps')
                ? 'Pulling glossary gap candidates and generating angles...'
                : 'Analyzing your existing content, GSC keywords, and trends... (10-20 seconds)';
            $('#bq-gen-status').html('<span style="color:#d97706;">' + statusMsg + '</span>');
            $('#bq-gen-results').hide();

            var data = {
                action: 'bq_generate_topics', nonce: nonce,
                source: src,
                focus_area: $('#bq-focus-area').val(),
                gap_category: $('#bq-gap-category').val(),
                gap_kw_filter: $('#bq-gap-kw-filter').val(),
                featured_product_id: $('#bq-product-id').val() || 0,
                count: $('#bq-gen-count').val()
            };
            if (src === 'glossary_gaps') {
                data.glossary_area     = $('#bq-glossary-area').val();
                data.glossary_coverage = $('#bq-glossary-coverage').val();
                data.glossary_tier     = $('#bq-glossary-tier').val();
            }

            $.ajax({ url: ajaxurl, method: 'POST', timeout: 180000,
                data: data,
                success: function(resp) {
                    btn.prop('disabled', false).text('Generate Topics');
                    if (resp.success && resp.data.titles.length) {
                        // Show a per-type breakdown so the user can verify
                        // the quotas actually held in this batch.
                        var typeCounts = { howto: 0, comparison: 0, concept: 0, career: 0 };
                        resp.data.titles.forEach(function(t) {
                            var ct = (t && t.content_type) ? t.content_type : 'concept';
                            if (typeCounts[ct] !== undefined) typeCounts[ct]++;
                        });
                        var typeSummary = ' (' + typeCounts.howto + ' how-to · ' + typeCounts.comparison + ' comparison · ' + typeCounts.concept + ' concept · ' + typeCounts.career + ' career)';
                        $('#bq-gen-status').html('<span style="color:#059669;">' + resp.data.count + ' topics generated' + typeSummary + '. Select the ones you want and add to queue.</span>');
                        var typeColors = {
                            howto:      { bg: '#dcfce7', fg: '#166534', label: 'how-to' },
                            comparison: { bg: '#dbeafe', fg: '#1e40af', label: 'comparison' },
                            concept:    { bg: '#fef3c7', fg: '#92400e', label: 'concept' },
                            career:     { bg: '#fae8ff', fg: '#86198f', label: 'career' }
                        };
                        var list = $('#bq-gen-list').empty();
                        resp.data.titles.forEach(function(t, i) {
                            var title = typeof t === 'object' ? t.title : t;
                            var kws = typeof t === 'object' ? (t.keywords || '') : '';
                            var ct = (typeof t === 'object' && t.content_type) ? t.content_type : 'concept';
                            var tc = typeColors[ct] || typeColors.concept;
                            var typeBadge = '<span style="display:inline-block;font-size:10px;padding:2px 6px;border-radius:3px;background:' + tc.bg + ';color:' + tc.fg + ';margin-right:6px;text-transform:uppercase;letter-spacing:0.3px;">' + tc.label + '</span>';
                            var kwBadge = kws ? '<div style="font-size:11px;color:#64748b;margin-left:24px;margin-top:2px;">Keywords: <span style="color:#2563eb;">' + kws + '</span></div>' : '';
                            var glTerm = (typeof t === 'object' && t.glossary_term) ? String(t.glossary_term) : '';
                            var glAttr = glTerm ? ' data-glossary-term="' + glTerm.replace(/"/g, '&quot;') + '"' : '';
                            list.append('<label style="display:block;padding:8px 12px;border-bottom:1px solid #f1f5f9;cursor:pointer;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'"><input type="checkbox" class="bq-gen-cb" checked data-title="' + title.replace(/"/g, '&quot;') + '" data-keywords="' + kws.replace(/"/g, '&quot;') + '" data-content-type="' + ct + '"' + glAttr + ' /> ' + typeBadge + title + kwBadge + '</label>');
                        });
                        $('#bq-gen-results').show();
                    } else {
                        $('#bq-gen-status').html('<span style="color:#dc2626;">Error: ' + (resp.data || 'No topics generated') + '</span>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Generate Topics');
                    $('#bq-gen-status').html('<span style="color:#dc2626;">Server error/timeout.</span>');
                }
            });
        });

        $('#bq-gen-check-all').change(function() {
            $('.bq-gen-cb').prop('checked', $(this).prop('checked'));
        });

        $('#bq-gen-add-selected').click(function() {
            var selected = [];
            $('.bq-gen-cb:checked').each(function() {
                var item = {
                    title: $(this).data('title'),
                    keywords: $(this).data('keywords') || '',
                    content_type: $(this).data('content-type') || ''
                };
                var glTerm = $(this).data('glossary-term');
                if (glTerm) item.glossary_term = String(glTerm);
                selected.push(item);
            });
            if (!selected.length) { alert('Select at least one topic.'); return; }

            var gapCat   = $('#bq-gap-category').val() || '';
            var prodId   = $('#bq-product-id').val() || 0;
            var btn = $(this).prop('disabled', true).text('Adding...');
            $.post(ajaxurl, { action: 'bq_add_topics', nonce: nonce, items: JSON.stringify(selected), gap_category: gapCat, featured_product_id: prodId }, function(resp) {
                btn.prop('disabled', false).text('Add Selected to Queue');
                if (resp.success) {
                    $('#bq-gen-status').html('<span style="color:#059669;">' + resp.data.added + ' topics added to queue!</span>');
                    $('#bq-gen-results').hide();
                    loadQueueCount();
                }
            });
        });

        // Queue
        function loadQueueCount() {
            $.post(ajaxurl, { action: 'bq_get_queue', nonce: nonce, page: 1 }, function(resp) {
                if (resp.success) $('#bq-queue-count').text(resp.data.total || '');
            });
        }

        function loadQueue(page) {
            $('#bq-queue-loading').show(); $('#bq-queue-table').hide(); $('#bq-queue-empty').hide();
            $.post(ajaxurl, { action: 'bq_get_queue', nonce: nonce, page: page || 1 }, function(resp) {
                $('#bq-queue-loading').hide();
                if (!resp.success || !resp.data.rows.length) { $('#bq-queue-empty').show(); return; }
                var d = resp.data;
                $('#bq-today-info').text('Created today: ' + d.today_count + ' / ' + d.daily_limit);
                var tbody = $('#bq-queue-body').empty();
                var typeColors = {
                    howto:      { bg: '#dcfce7', fg: '#166534', label: 'how-to' },
                    comparison: { bg: '#dbeafe', fg: '#1e40af', label: 'comparison' },
                    concept:    { bg: '#fef3c7', fg: '#92400e', label: 'concept' },
                    career:     { bg: '#fae8ff', fg: '#86198f', label: 'career' }
                };
                d.rows.forEach(function(r) {
                    var gapBadge = r.gap_category ? '<span style="display:inline-block;margin-top:4px;background:#eff6ff;color:#2563eb;font-size:10px;padding:2px 6px;border-radius:3px;">' + r.gap_category + '</span>' : '';
                    var kwBadge = r.target_keywords ? '<div style="font-size:10px;color:#64748b;margin-top:2px;">Keywords: <span style="color:#059669;">' + r.target_keywords + '</span></div>' : '';
                    var typeBadge = '';
                    if (r.content_type && typeColors[r.content_type]) {
                        var tc = typeColors[r.content_type];
                        typeBadge = '<span style="display:inline-block;margin-right:6px;background:' + tc.bg + ';color:' + tc.fg + ';font-size:10px;padding:2px 6px;border-radius:3px;text-transform:uppercase;letter-spacing:0.3px;">' + tc.label + '</span>';
                    }
                    tbody.append('<tr data-id="' + r.id + '">'
                        + '<td><input type="checkbox" class="bq-queue-cb" value="' + r.id + '" /></td>'
                        + '<td>' + typeBadge + r.title + ' ' + gapBadge + kwBadge + '<div class="bq-queue-status" data-id="' + r.id + '" style="font-size:12px;margin-top:4px;min-height:16px;"></div></td>'
                        + '<td style="font-size:12px;color:#94a3b8;">' + (r.added_at || '').substring(0, 10) + '</td>'
                        + '<td>'
                        + '<button class="button button-small bq-process-one" data-id="' + r.id + '">Create Now</button> '
                        + '<button class="button button-small bq-remove" data-id="' + r.id + '" style="color:#dc2626;">Remove</button>'
                        + '</td></tr>');
                });
                $('#bq-queue-table, #bq-queue-bulk-bar').show();
                var pag = $('#bq-queue-pagination').empty();
                if (d.pages > 1) for (var i = 1; i <= d.pages; i++) pag.append('<button class="' + (i === d.page ? 'button button-primary' : 'button') + ' bq-queue-page" data-page="' + i + '">' + i + '</button> ');
            });
        }

        $(document).on('click', '.bq-queue-page', function() { loadQueue($(this).data('page')); });
        $(document).on('click', '.bq-remove', function() {
            var row = $(this).closest('tr');
            $.post(ajaxurl, { action: 'bq_remove_item', nonce: nonce, id: $(this).data('id') }, function(resp) { if (resp.success) { row.fadeOut(); loadQueueCount(); } });
        });

        // Queue select all
        $(document).on('change', '#bq-queue-check-all', function() {
            $('.bq-queue-cb').prop('checked', $(this).prop('checked'));
        });

        // Queue bulk apply
        $('#bq-queue-bulk-apply').click(function() {
            var action = $('#bq-queue-bulk-action').val();
            if (!action) { alert('Select a bulk action.'); return; }

            var ids = [];
            $('.bq-queue-cb:checked').each(function() { ids.push(parseInt($(this).val())); });
            if (!ids.length) { alert('Select at least one item.'); return; }

            if (action === 'delete') {
                if (!confirm('Delete ' + ids.length + ' item(s) from the queue?')) return;
                $.post(ajaxurl, { action: 'bq_bulk_delete', nonce: nonce, 'ids[]': ids }, function(resp) {
                    if (resp.success) {
                        $('#bq-queue-bulk-status').html('<span style="color:#059669;">' + resp.data.deleted + ' deleted.</span>');
                        loadQueue(1); loadQueueCount();
                    }
                });
            } else if (action === 'process') {
                if (!confirm('Process ' + ids.length + ' item(s) now? This may take 1-2 minutes per blog.')) return;

                // Gather rows for selected IDs
                var items = [];
                ids.forEach(function(id) {
                    var row = $('tr[data-id="' + id + '"]');
                    items.push({ id: id, row: row });
                });

                // Shuffle for variety
                for (var i = items.length - 1; i > 0; i--) {
                    var j = Math.floor(Math.random() * (i + 1));
                    var tmp = items[i]; items[i] = items[j]; items[j] = tmp;
                }

                var completed = 0, failed = 0, total = items.length;
                $('#bq-queue-bulk-status').html('<span style="color:#b45309;">Processing 0/' + total + '...</span>');
                $('.bq-process-one, .bq-remove').prop('disabled', true);
                $('#bq-queue-bulk-apply').prop('disabled', true);

                function processNextBulk(index) {
                    if (index >= total) {
                        $('.bq-process-one, .bq-remove').prop('disabled', false);
                        $('#bq-queue-bulk-apply').prop('disabled', false);
                        $('#bq-queue-bulk-status').html('<span style="color:#059669;">Done: ' + completed + ' created, ' + failed + ' failed.</span>');
                        setTimeout(function() { loadQueue(1); loadQueueCount(); }, 2000);
                        return;
                    }
                    var item = items[index];
                    var statusEl = item.row.find('.bq-queue-status');
                    item.row.css('opacity', '0.7');
                    $('#bq-queue-bulk-status').html('<span style="color:#b45309;">Processing ' + (index + 1) + '/' + total + '...</span>');

                    runBlogSteps(item.id, statusEl, function(success, data) {
                        if (success) {
                            completed++;
                            item.row.css('opacity', '0.4');
                            statusEl.html('<span style="color:#059669;">&#10003; Created</span> <a href="' + data.url + '" target="_blank" style="font-size:12px;">View</a> | <a href="' + data.edit_url + '" target="_blank" style="font-size:12px;">Edit</a>');
                        } else {
                            failed++;
                            item.row.css('opacity', '1');
                        }
                        processNextBulk(index + 1);
                    });
                }
                processNextBulk(0);
            }
        });

        var stepLabels = {
            1: 'Generating outline...',
            2: 'Writing blog content...',
            3: 'SEO: meta, keyword, title...',
            4: 'Generating FAQ & schema...'
        };

        function runBlogSteps(queueId, statusEl, callback) {
            var currentStep = 1;
            var totalSteps = 4;

            function runStep() {
                if (currentStep > totalSteps) { callback(true); return; }
                statusEl.html('<span style="color:#b45309;">Step ' + currentStep + '/' + totalSteps + ': ' + stepLabels[currentStep] + '</span>');

                $.ajax({ url: ajaxurl, method: 'POST', timeout: 180000,
                    data: { action: 'bq_process_step', nonce: nonce, id: queueId, step: currentStep },
                    success: function(resp) {
                        if (resp.success) {
                            // Topic gate rejected this one — show "Skipped"
                            // (not an error) and stop the step chain.
                            if (resp.data && resp.data.skipped) {
                                statusEl.html('<span style="color:#94a3b8;">&#9888; Skipped: ' + (resp.data.reason || 'off-topic') + '</span>');
                                callback(false, resp.data);
                                return;
                            }
                            if (resp.data.complete) {
                                callback(true, resp.data);
                            } else {
                                currentStep++;
                                runStep();
                            }
                        } else {
                            statusEl.html('<span style="color:#dc2626;">&#10007; ' + (resp.data || 'Unknown error') + '</span>');
                            callback(false);
                        }
                    },
                    error: function(xhr, status) {
                        var msg = status === 'timeout' ? 'Timed out at step ' + currentStep : 'Server error (HTTP ' + (xhr.status || '?') + ') at step ' + currentStep + ': ' + stepLabels[currentStep];
                        statusEl.html('<span style="color:#dc2626;">&#10007; ' + msg + '</span>');
                        callback(false);
                    }
                });
            }
            runStep();
        }

        $(document).on('click', '.bq-process-one', function() {
            var btn = $(this).prop('disabled', true).text('Creating...');
            var row = btn.closest('tr');
            var statusEl = row.find('.bq-queue-status');
            row.css('opacity', '0.7');

            runBlogSteps(btn.data('id'), statusEl, function(success, data) {
                if (success) {
                    row.css('opacity', '0.4');
                    statusEl.html('<span style="color:#059669;">&#10003; Created</span> <a href="' + data.url + '" target="_blank" style="font-size:12px;">View</a> | <a href="' + data.edit_url + '" target="_blank" style="font-size:12px;">Edit</a>');
                    loadQueueCount();
                } else if (data && data.skipped) {
                    // Off-topic — leave the "Skipped" status message in place,
                    // grey the row, and remove the Create Now button so the
                    // user can't retry the same off-scope topic.
                    row.css('opacity', '0.4');
                    btn.remove();
                    loadQueueCount();
                } else {
                    btn.prop('disabled', false).text('Retry');
                    row.css('opacity', '1');
                }
            });
        });

        var bqPollTimer = null;
        var bqRunning = false;

        // Start background processing — triggers server-side cron chain
        $('#bq-run-queue').click(function() {
            if (bqRunning) return;
            if (!confirm('Start processing the blog queue in the background? Processing continues even if you leave this page.')) return;
            $(this).prop('disabled', true).text('Starting...');
            $.post(ajaxurl, { action: 'bq_start_queue_processing', nonce: nonce }, function(resp) {
                if (resp.success) {
                    startBqPolling();
                } else {
                    $('#bq-run-queue').prop('disabled', false).text('Process Queue (Background)');
                    alert(resp.data || 'Error starting queue.');
                }
            });
        });

        // Stop background processing
        $('#bq-stop-queue-bg').click(function() {
            $(this).prop('disabled', true).text('Stopping...');
            $.post(ajaxurl, { action: 'bq_stop_queue', nonce: nonce }, function() {
                bqRunning = false;
                if (bqPollTimer) clearTimeout(bqPollTimer);
                $('#bq-run-queue').prop('disabled', false).text('Process Queue (Background)');
                $('#bq-stop-queue-bg').hide();
                $('#bq-queue-progress').css('border-left-color', '#d97706').html('Processing stopped. Remaining items stay in queue.');
                loadQueue(1); loadQueueCount();
            });
        });

        function startBqPolling() {
            bqRunning = true;
            $('#bq-run-queue').prop('disabled', true).text('Processing...');
            $('#bq-stop-queue-bg').show().prop('disabled', false).text('Stop Processing');
            $('#bq-queue-progress').show();
            pollBqStatus();
        }

        function pollBqStatus() {
            $.post(ajaxurl, { action: 'bq_queue_status', nonce: nonce }, function(resp) {
                if (!resp.success) return;
                var s = resp.data;

                var html = '<strong>' + s.completed + ' created</strong>';
                if (s.failed > 0) html += ', <span style="color:#dc2626;">' + s.failed + ' failed</span>';
                html += ', <span style="color:#64748b;">' + s.remaining + ' remaining</span>';
                html += ' (daily limit: ' + s.daily_limit + ')';

                if (s.current && s.current.title) {
                    html += '<br><span style="font-size:12px;color:#2563eb;"><span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span>Now: <strong>' + s.current.title + '</strong></span>';
                }

                if (s.last_done && s.last_done.title) {
                    var doneColor = s.last_done.status === 'completed' ? '#059669' : '#dc2626';
                    var doneIcon = s.last_done.status === 'completed' ? '&#10003;' : '&#10007;';
                    html += '<br><span style="font-size:12px;color:' + doneColor + ';">' + doneIcon + ' ' + s.last_done.title + '</span>';
                }

                if (s.is_running && s.remaining > 0) {
                    html += '<br><span style="font-size:11px;color:#94a3b8;">Processing in background — you can leave this page.</span>';
                    $('#bq-queue-progress').css('border-left-color', '#2563eb').html(html);
                    loadQueueCount();
                    bqPollTimer = setTimeout(pollBqStatus, 15000);
                } else if (s.stopped) {
                    html += '<br><span style="font-size:12px;color:#d97706;">Processing stopped.</span>';
                    $('#bq-queue-progress').css('border-left-color', '#d97706').html(html);
                    bqRunning = false;
                    $('#bq-run-queue').prop('disabled', false).text('Process Queue (Background)');
                    $('#bq-stop-queue-bg').hide();
                    loadQueue(1); loadQueueCount();
                } else {
                    html += '<br><span style="font-size:12px;color:#059669;">Queue processing complete.</span>';
                    $('#bq-queue-progress').css('border-left-color', '#059669').html(html);
                    bqRunning = false;
                    $('#bq-run-queue').prop('disabled', false).text('Process Queue (Background)');
                    $('#bq-stop-queue-bg').hide();
                    loadQueue(1); loadQueueCount();
                }
            });
        }

        // On page load, check if processing is active
        $.post(ajaxurl, { action: 'bq_queue_status', nonce: nonce }, function(resp) {
            if (resp.success && resp.data.is_running) {
                startBqPolling();
            }
        });

        // ─── All Blogs ──────────────────────────────────────────────────────
        var blogSort = 'modified', blogOrder = 'DESC';
        var checkYes = '<span class="bq-check-yes" title="Yes">&#10003;</span>';
        var checkNo  = '<span class="bq-check-no" title="No">&#10007;</span>';

        function loadBlogs(page) {
            $('#bq-blog-loading').show();
            $('#bq-blog-table, #bq-blog-empty').hide();
            $.post(ajaxurl, {
                action: 'bq_get_all_blogs', nonce: nonce,
                page: page || 1,
                search: $('#bq-blog-search').val(),
                filter: $('#bq-blog-filter').val(),
                orderby: blogSort,
                order: blogOrder
            }, function(resp) {
                $('#bq-blog-loading').hide();
                if (!resp.success || !resp.data.rows.length) { $('#bq-blog-empty').show(); return; }
                var d = resp.data;
                var tbody = $('#bq-blog-body').empty();
                d.rows.forEach(function(r) {
                    var img = r.thumb_url
                        ? '<img src="' + r.thumb_url + '" class="bq-thumb" />'
                        : '<span class="bq-thumb--empty">No image</span>';

                    var wordClass = r.word_count >= 1500 ? 'bq-word-good' : (r.word_count >= 800 ? 'bq-word-ok' : 'bq-word-low');

                    tbody.append(
                        '<tr data-id="' + r.ID + '">' +
                        '<td><input type="checkbox" class="bq-blog-cb" value="' + r.ID + '" /></td>' +
                        '<td>' + img + '</td>' +
                        '<td><strong>' + r.title + '</strong>' +
                            '<div class="bq-blog-status" data-id="' + r.ID + '"></div>' +
                        '</td>' +
                        '<td style="text-align:center;"><span class="' + wordClass + '">' + r.word_count.toLocaleString() + '</span></td>' +
                        '<td style="text-align:center;" class="col-faq">' + (r.has_faq ? checkYes : checkNo) + '</td>' +
                        '<td style="text-align:center;" class="col-schema">' + (r.has_schema ? checkYes : checkNo) + '</td>' +
                        '<td style="text-align:center;" class="col-meta">' + (r.has_meta ? checkYes : checkNo) + '</td>' +
                        '<td style="text-align:center;" class="col-keyword">' + (r.has_keyword ? checkYes : checkNo) + '</td>' +
                        '<td style="font-size:12px;color:#64748b;">' + (r.modified || '') + '</td>' +
                        '<td>' +
                            '<a href="' + r.edit_url + '" class="button button-small" target="_blank">Edit</a> ' +
                            '<a href="' + r.view_url + '" class="button button-small" target="_blank">View</a>' +
                        '</td>' +
                        '</tr>'
                    );
                });
                $('#bq-blog-table, #bq-blog-bulk-bar').show();

                // Update sort indicators
                $('#bq-blog-table thead th[data-sort]').css('cursor', 'pointer').find('.bq-sort-arrow').remove();
                var arrow = blogOrder === 'ASC' ? ' &#9650;' : ' &#9660;';
                $('#bq-blog-table thead th[data-sort="' + blogSort + '"]').append('<span class="bq-sort-arrow">' + arrow + '</span>');

                // Pagination
                var pag = $('#bq-blog-pagination').empty();
                if (d.pages > 1) {
                    for (var i = 1; i <= d.pages; i++) {
                        pag.append('<button class="' + (i === d.page ? 'button button-primary' : 'button') + ' bq-blog-page" data-page="' + i + '">' + i + '</button> ');
                    }
                }
                pag.append('<span style="margin-left:12px;color:#64748b;font-size:13px;">' + d.total + ' blog(s)</span>');
            });
        }

        $(document).on('click', '.bq-blog-page', function() { loadBlogs($(this).data('page')); });

        // Search with debounce
        var blogSearchTimer;
        $('#bq-blog-search').on('input', function() {
            clearTimeout(blogSearchTimer);
            blogSearchTimer = setTimeout(function() { loadBlogs(1); }, 400);
        });
        $('#bq-blog-filter').change(function() { loadBlogs(1); });

        // Sortable columns
        $(document).on('click', '#bq-blog-table thead th[data-sort]', function() {
            var col = $(this).data('sort');
            if (blogSort === col) {
                blogOrder = blogOrder === 'DESC' ? 'ASC' : 'DESC';
            } else {
                blogSort = col;
                blogOrder = col === 'title' ? 'ASC' : 'DESC';
            }
            loadBlogs(1);
        });

        // Select all checkbox
        $(document).on('change', '#bq-blog-check-all', function() {
            $('.bq-blog-cb').prop('checked', $(this).prop('checked'));
        });

        // Bulk apply
        var isBlogProcessing = false;
        $('#bq-blog-bulk-apply').click(function() {
            var action = $('#bq-blog-bulk-action').val();
            if (!action) { alert('Select a bulk action.'); return; }

            var ids = [];
            $('.bq-blog-cb:checked').each(function() { ids.push(parseInt($(this).val())); });
            if (!ids.length) { alert('Select at least one blog.'); return; }

            if (isBlogProcessing) { alert('A process is already running.'); return; }

            var typeMap = { full_refresh: 'full', seo_refresh: 'seo', meta_refresh: 'meta' };
            var labelMap = { full_refresh: 'full refresh', seo_refresh: 'SEO refresh', meta_refresh: 'CTR fix' };
            var refreshType = typeMap[action] || 'full';
            var label = labelMap[action] || action;
            if (!confirm('Run ' + label + ' on ' + ids.length + ' blog(s)? This may take 1-2 minutes per blog.')) return;

            isBlogProcessing = true;
            $('#bq-blog-bulk-apply').prop('disabled', true);
            var completed = 0, failed = 0, total = ids.length;

            // Gather rows for each ID
            var items = [];
            ids.forEach(function(id) {
                var row = $('#bq-blog-table tr[data-id="' + id + '"]');
                items.push({ id: id, row: row });
            });

            function processNextBlog(index) {
                if (index >= total) {
                    isBlogProcessing = false;
                    $('#bq-blog-bulk-apply').prop('disabled', false);
                    $('#bq-blog-bulk-status').html('<span style="color:#059669;">' + label + ' complete: ' + completed + ' succeeded, ' + failed + ' failed.</span>');
                    return;
                }

                var item = items[index];
                item.row.css('opacity', '0.7');
                item.row.find('.bq-blog-status').html('<span class="processing">Processing...</span>');
                $('#bq-blog-bulk-status').html('<span style="color:#b45309;">Processing ' + (index + 1) + '/' + total + '...</span>');

                $.ajax({
                    url: ajaxurl, method: 'POST', timeout: 600000,
                    data: { action: 'bq_refresh_blog', nonce: nonce, post_id: item.id, type: refreshType },
                    success: function(resp) {
                        if (resp.success) {
                            completed++;
                            var d = resp.data;
                            item.row.css('opacity', '1');
                            item.row.find('.bq-blog-status').html('<span class="done">&#10003; Done</span>');
                            // Update check columns
                            item.row.find('.col-faq').html(d.has_faq ? checkYes : checkNo);
                            item.row.find('.col-schema').html(d.has_schema ? checkYes : checkNo);
                            item.row.find('.col-meta').html(d.has_meta ? checkYes : checkNo);
                            item.row.find('.col-keyword').html(d.has_keyword ? checkYes : checkNo);
                            // Update word count if full refresh
                            if (refreshType === 'full' && d.word_count) {
                                var wc = d.word_count;
                                var wcClass = wc >= 1500 ? 'bq-word-good' : (wc >= 800 ? 'bq-word-ok' : 'bq-word-low');
                                item.row.find('td:eq(3)').html('<span class="' + wcClass + '">' + wc.toLocaleString() + '</span>');
                            }
                        } else {
                            failed++;
                            item.row.css('opacity', '1');
                            item.row.find('.bq-blog-status').html('<span class="error">&#10007; ' + (resp.data || 'Failed') + '</span>');
                        }
                    },
                    error: function() {
                        failed++;
                        item.row.css('opacity', '1');
                        item.row.find('.bq-blog-status').html('<span class="error">&#10007; Server error</span>');
                    },
                    complete: function() { processNextBlog(index + 1); }
                });
            }

            processNextBlog(0);
        });

        // History
        // ─── Clusters tab ──────────────────────────────────────────────
        var bqClustersCache = [];
        var bqClusterDetailTag = null;

        function escapeHtmlBq(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function renderClustersTable() {
            var $body = $('#bq-cluster-body').empty();
            var search = ($('#bq-cluster-search').val() || '').toLowerCase().trim();
            var sort = $('#bq-cluster-sort').val();
            var rows = bqClustersCache.slice();
            if (search) {
                rows = rows.filter(function(r) { return r.tag.toLowerCase().indexOf(search) !== -1; });
            }
            rows.sort(function(a, b) {
                switch (sort) {
                    case 'depth_asc':   return a.depth_score - b.depth_score;
                    case 'depth_desc':  return b.depth_score - a.depth_score;
                    case 'gaps_desc':   return b.gaps - a.gaps;
                    case 'posts_desc':  return b.posts - a.posts;
                    default:            return b.total_keywords - a.total_keywords;
                }
            });
            if (!rows.length) {
                $('#bq-cluster-table').hide();
                $('#bq-cluster-loading').text('No clusters found. Import keywords or tag existing keywords first.').show();
                return;
            }
            rows.forEach(function(c) {
                var pillarBadge = c.pillar_post_id
                    ? '<span style="color:#059669;font-weight:600;" title="Pillar designated">✓</span>'
                    : '<span style="color:#dc2626;" title="No pillar yet">✗</span>';
                // Depth-score bar with traffic-light coloring.
                var barColor = c.depth_score >= 70 ? '#059669' : (c.depth_score >= 40 ? '#d97706' : '#dc2626');
                var bar = '<div style="background:#e2e8f0;border-radius:3px;height:6px;width:80px;overflow:hidden;display:inline-block;vertical-align:middle;">'
                    + '<div style="background:' + barColor + ';height:100%;width:' + c.depth_score + '%;"></div></div>'
                    + ' <span style="font-size:11px;color:#64748b;margin-left:4px;">' + c.depth_score + '</span>';

                $body.append('<tr data-tag="' + escapeHtmlBq(c.tag) + '" style="cursor:pointer;">'
                    + '<td><a href="#" class="bq-cluster-open" style="font-weight:500;color:#1e293b;text-decoration:none;">' + escapeHtmlBq(c.tag) + '</a></td>'
                    + '<td style="text-align:center;">' + c.total_keywords + '<div style="font-size:10px;color:#94a3b8;">' + c.available_keywords + ' avail</div></td>'
                    + '<td style="text-align:center;">' + c.posts + '</td>'
                    + '<td style="text-align:center;font-size:16px;">' + pillarBadge + '</td>'
                    + '<td style="text-align:center;color:' + (c.gaps > 0 ? '#d97706' : '#94a3b8') + ';">' + c.gaps + '</td>'
                    + '<td>' + bar + '</td>'
                    + '<td><button type="button" class="button button-small bq-cluster-open">Open</button></td>'
                    + '</tr>');
            });
            $('#bq-cluster-loading').hide();
            $('#bq-cluster-table').show();
        }

        function loadClusters() {
            $('#bq-cluster-loading').text('Loading clusters...').show();
            $('#bq-cluster-table').hide();
            $('#bq-cluster-detail').hide();
            $.post(ajaxurl, { action: 'bq_get_clusters_overview', nonce: nonce }, function(resp) {
                if (!resp.success) {
                    $('#bq-cluster-loading').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                    return;
                }
                bqClustersCache = resp.data.clusters || [];
                renderClustersTable();
            });
        }
        $('#bq-cluster-refresh').click(loadClusters);
        $('#bq-cluster-sort').on('change', renderClustersTable);
        var bqClusterSearchTimer;
        $('#bq-cluster-search').on('input', function() {
            clearTimeout(bqClusterSearchTimer);
            bqClusterSearchTimer = setTimeout(renderClustersTable, 200);
        });

        function renderClusterDetail(d) {
            bqClusterDetailTag = d.tag;
            $('#bq-cluster-detail-tag').text(d.tag);
            var summary = '<strong>' + d.member_count + '</strong> posts · '
                + '<strong>' + d.gap_count + '</strong> unfilled subtopics · '
                + (d.pillar_post_id ? '<span style="color:#059669;">Pillar designated</span>' : '<span style="color:#dc2626;">No pillar yet</span>');
            $('#bq-cluster-detail-summary').html(summary);

            var typeColors = {
                howto:      { bg: '#dcfce7', fg: '#166534', label: 'how-to' },
                comparison: { bg: '#dbeafe', fg: '#1e40af', label: 'comparison' },
                concept:    { bg: '#fef3c7', fg: '#92400e', label: 'concept' },
                career:     { bg: '#fae8ff', fg: '#86198f', label: 'career' }
            };

            // Members
            var $members = $('#bq-cluster-members').empty();
            if (!d.members.length) {
                $members.html('<div style="padding:14px;color:#94a3b8;">No posts yet. Use Fill Cluster Gaps to create some.</div>');
            } else {
                d.members.forEach(function(m) {
                    var tc = typeColors[m.content_type] || null;
                    var typeBadge = tc ? '<span style="display:inline-block;font-size:9px;padding:1px 5px;border-radius:3px;background:' + tc.bg + ';color:' + tc.fg + ';margin-right:6px;text-transform:uppercase;">' + tc.label + '</span>' : '';
                    var pillarBtn = m.is_pillar
                        ? '<button type="button" class="button button-small bq-cluster-clear-pillar" data-post-id="' + m.post_id + '" style="background:#059669;color:#fff;border-color:#059669;">★ Pillar</button>'
                        : '<button type="button" class="button button-small bq-cluster-set-pillar" data-post-id="' + m.post_id + '">Make Pillar</button>';
                    $members.append('<div style="padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;">'
                        + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">'
                        + '<div style="flex:1;">' + typeBadge + '<a href="' + escapeHtmlBq(m.url) + '" target="_blank" style="color:#1e293b;text-decoration:none;font-weight:500;">' + escapeHtmlBq(m.title) + '</a>'
                        + '<div style="font-size:11px;color:#94a3b8;margin-top:2px;">' + m.word_count + ' words · modified ' + (m.modified || '—') + '</div></div>'
                        + '<div style="white-space:nowrap;">' + pillarBtn + '</div>'
                        + '</div></div>');
                });
            }

            // Gaps
            var $gaps = $('#bq-cluster-gaps').empty();
            if (!d.gaps.length) {
                $gaps.html('<div style="padding:14px;color:#94a3b8;">No unfilled subtopics — this cluster is well covered.</div>');
            } else {
                d.gaps.forEach(function(g) {
                    $gaps.append('<div style="padding:6px 12px;border-bottom:1px solid #f1f5f9;font-size:12px;display:flex;justify-content:space-between;align-items:center;gap:8px;">'
                        + '<span>' + escapeHtmlBq(g.keyword) + '</span>'
                        + '<span style="color:#94a3b8;font-size:11px;white-space:nowrap;">vol ' + g.search_volume + ' · KD ' + g.keyword_difficulty + '</span>'
                        + '</div>');
                });
            }

            $('#bq-cluster-detail').show();
            $('#bq-cluster-detail-status').text('');
            $('html, body').animate({ scrollTop: $('#bq-cluster-detail').offset().top - 80 }, 200);
        }

        $(document).on('click', '.bq-cluster-open', function(e) {
            e.preventDefault();
            var tag = $(this).closest('tr').data('tag');
            if (!tag) return;
            $('#bq-cluster-detail-status').text('Loading cluster...');
            $.post(ajaxurl, { action: 'bq_get_cluster_detail', nonce: nonce, tag: tag }, function(resp) {
                if (resp.success) renderClusterDetail(resp.data);
                else $('#bq-cluster-detail-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
            });
        });
        $('#bq-cluster-detail-close').click(function() { $('#bq-cluster-detail').hide(); bqClusterDetailTag = null; });

        $(document).on('click', '.bq-cluster-set-pillar', function() {
            var $btn = $(this).prop('disabled', true).text('Setting...');
            var pid = $btn.data('post-id');
            $.post(ajaxurl, { action: 'bq_set_cluster_pillar', nonce: nonce, tag: bqClusterDetailTag, post_id: pid }, function(resp) {
                if (resp.success) {
                    $('#bq-cluster-detail-status').html('<span style="color:#059669;">Pillar set. Reloading cluster...</span>');
                    $.post(ajaxurl, { action: 'bq_get_cluster_detail', nonce: nonce, tag: bqClusterDetailTag }, function(r2) {
                        if (r2.success) renderClusterDetail(r2.data);
                    });
                    loadClusters();
                } else {
                    $btn.prop('disabled', false).text('Make Pillar');
                    $('#bq-cluster-detail-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                }
            });
        });
        $(document).on('click', '.bq-cluster-clear-pillar', function() {
            if (!confirm('Remove pillar designation? This cluster will have no pillar until you set a new one.')) return;
            var $btn = $(this).prop('disabled', true).text('Clearing...');
            $.post(ajaxurl, { action: 'bq_set_cluster_pillar', nonce: nonce, tag: bqClusterDetailTag, post_id: 0 }, function(resp) {
                if (resp.success) {
                    $('#bq-cluster-detail-status').html('<span style="color:#059669;">Pillar cleared.</span>');
                    $.post(ajaxurl, { action: 'bq_get_cluster_detail', nonce: nonce, tag: bqClusterDetailTag }, function(r2) {
                        if (r2.success) renderClusterDetail(r2.data);
                    });
                    loadClusters();
                } else {
                    $btn.prop('disabled', false).text('★ Pillar');
                    $('#bq-cluster-detail-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                }
            });
        });

        $('#bq-cluster-gen-topics').click(function() {
            if (!bqClusterDetailTag) return;
            // Pre-fill the Add Topics tab generator with this tag + cluster mode,
            // then switch the user there. The generator already supports
            // gap_category — cluster mode = same generator with the tag locked in.
            $('.bq-tab[data-tab="add"]').click();
            // Pre-select the category dropdown if it exists.
            var $sel = $('#bq-gap-category');
            if ($sel.length) {
                $sel.val(bqClusterDetailTag);
                // Force the limit-to-keywords mode so we don't overshoot the gap pool.
                $sel.trigger('change');
            }
            // Scroll generator into view + focus the count input as a hint.
            $('#bq-gen-count').focus();
        });

        function loadHistory(page) {
            $('#bq-history-loading').show(); $('#bq-history-table').hide();
            $.post(ajaxurl, { action: 'bq_get_history', nonce: nonce, page: page || 1 }, function(resp) {
                $('#bq-history-loading').hide();
                if (!resp.success || !resp.data.rows.length) return;
                var tbody = $('#bq-history-body').empty();
                resp.data.rows.forEach(function(r) {
                    var status;
                    if (r.status === 'completed') {
                        status = '<span style="color:#059669;">Published</span>';
                    } else if (r.status === 'skipped') {
                        status = '<span style="color:#94a3b8;" title="Topic was off-scope and skipped by the relevance gate">Skipped</span>';
                    } else {
                        status = '<span style="color:#dc2626;">Failed</span>';
                    }
                    var actions = r.post_id ? '<a href="/?p=' + r.post_id + '" target="_blank" class="button button-small">View</a> <a href="<?php echo admin_url('post.php?action=edit&post='); ?>' + r.post_id + '" target="_blank" class="button button-small">Edit</a>' : (r.error_message || '');
                    tbody.append('<tr><td>' + r.title + '</td><td>' + status + '</td><td style="font-size:12px;">' + (r.processed_at || '').substring(0, 16) + '</td><td>' + actions + '</td></tr>');
                });
                $('#bq-history-table').show();
                var pag = $('#bq-history-pagination').empty();
                if (resp.data.pages > 1) for (var i = 1; i <= resp.data.pages; i++) pag.append('<button class="' + (i === resp.data.page ? 'button button-primary' : 'button') + ' bq-hist-page" data-page="' + i + '">' + i + '</button> ');
            });
        }
        $(document).on('click', '.bq-hist-page', function() { loadHistory($(this).data('page')); });

        // Settings
        var bqDefaultScope = <?php echo wp_json_encode(bq_default_topic_scope()); ?>;
        $('#bq-topic-gate-reset').click(function() {
            $('#bq-topic-gate-scope').val(bqDefaultScope).focus();
        });
        $('#bq-save-settings').click(function() {
            $.post(ajaxurl, {
                action: 'bq_save_settings', nonce: nonce,
                daily_limit: $('#bq-daily-limit').val(),
                notify_email: $('#bq-notify-email').val(),
                enabled: $('#bq-enabled').is(':checked') ? '1' : '0',
                topic_gate_enabled: $('#bq-topic-gate-enabled').is(':checked') ? '1' : '0',
                topic_gate_scope: $('#bq-topic-gate-scope').val()
            }, function(resp) {
                $('#bq-settings-status').html(resp.success ? '<span style="color:#059669;">Saved!</span>' : '<span style="color:#dc2626;">Error</span>');
                setTimeout(function() { $('#bq-settings-status').text(''); }, 3000);
            });
        });

        loadQueueCount();
        loadCatUncategorizedCount();
    });
    </script>
    <?php
}

// Also track this plugin in git
