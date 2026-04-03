<?php
/*
Plugin Name: SEO Monitor
Description: Automated SEO performance monitoring using Google Search Console. Identifies underperforming pages and triggers AI content refresh via AI Product Manager.
Version: 1.0
Author: ITU Online
Requires Plugins: ai-product-manager
*/

if (!defined('ABSPATH')) exit;

// ─── Block WordPress.org update checks for all custom plugins ───────────────
// Prevents WP from matching our custom slugs to unrelated public plugins.
add_filter('site_transient_update_plugins', function ($transient) {
    if (!is_object($transient)) return $transient;
    $custom_plugins = [
        'seo-monitor/seo-monitor.php',
        'blog-queue/blog-queue.php',
        'Blog_Writer_Plugin/Blog_Writer_Plugin.php',
        'ai-product-manager/ai-product-manager.php',
        'practice-test-manager/practice-test-manager.php',
        'lms-api/lms-api.php',
        'seo-content-about.php',
    ];
    foreach ($custom_plugins as $plugin) {
        unset($transient->response[$plugin]);
    }
    return $transient;
});

// Block "View Details" modal from loading wrong plugin info
add_filter('plugins_api', function ($result, $action, $args) {
    $blocked_slugs = ['seo-monitor', 'blog-queue', 'blog-writer-plugin', 'ai-product-manager', 'practice-test-manager', 'lms-api', 'seo-content-about'];
    if ($action === 'plugin_information' && isset($args->slug) && in_array($args->slug, $blocked_slugs, true)) {
        return new WP_Error('no_plugin', 'This is a custom plugin. No updates are available from WordPress.org.');
    }
    return $result;
}, 10, 3);

define('SEOM_VERSION', '1.0');
define('SEOM_PATH', plugin_dir_path(__FILE__));
define('SEOM_URL', plugin_dir_url(__FILE__));

// ─── Emergency Cleanup (accessible via ?seom_cleanup=1 on any admin page) ────

add_action('admin_init', function () {
    if (!isset($_GET['seom_cleanup'])) return;
    if (!current_user_can('manage_woocommerce')) return;

    // Clear all caches, locks, and temp files
    delete_transient('seom_gsc_metrics_cache');
    delete_transient('seom_gsc_token');
    delete_transient('seom_processing_lock');
    delete_option('seom_gsc_appearances_cache');

    $cache_file = wp_upload_dir()['basedir'] . '/seom_appearances_cache.json';
    if (file_exists($cache_file)) @unlink($cache_file);

    // Clear any stale daily counters older than today
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'seom_daily_count_%' AND option_name != 'seom_daily_count_" . date('Y-m-d') . "'");

    wp_redirect(admin_url('admin.php?page=seo-monitor&seom_cleaned=1'));
    exit;
});

// Show cleanup success notice
add_action('admin_notices', function () {
    if (!isset($_GET['seom_cleaned'])) return;
    echo '<div class="notice notice-success is-dismissible"><p><strong>SEO Monitor:</strong> All caches, locks, and temp files cleared.</p></div>';
});

// Add cleanup button to admin bar for quick access
add_action('admin_bar_menu', function ($admin_bar) {
    if (!current_user_can('manage_woocommerce')) return;

    $admin_bar->add_node([
        'id'    => 'seom-cleanup',
        'title' => 'SEO Monitor: Clear Cache',
        'href'  => admin_url('?seom_cleanup=1'),
        'meta'  => ['title' => 'Clear all SEO Monitor caches, locks, and temp files'],
    ]);
}, 999);

// ─── Activation / Deactivation ───────────────────────────────────────────────

register_activation_hook(__FILE__, 'seom_activate');
function seom_activate() {
    seom_create_tables();
    seom_upgrade_tables();
    // Schedule cron events
    if (!wp_next_scheduled('seom_daily_collect')) {
        wp_schedule_event(strtotime('today 01:00'), 'daily', 'seom_daily_collect');
    }
    if (!wp_next_scheduled('seom_daily_analyze')) {
        wp_schedule_event(strtotime('today 02:00'), 'daily', 'seom_daily_analyze');
    }
    if (!wp_next_scheduled('seom_daily_process')) {
        wp_schedule_event(strtotime('today 06:00'), 'daily', 'seom_daily_process');
    }
    if (!wp_next_scheduled('seom_weekly_backfill')) {
        wp_schedule_event(strtotime('next wednesday 03:00'), 'weekly', 'seom_weekly_backfill');
    }
    if (!wp_next_scheduled('seom_daily_keywords')) {
        wp_schedule_event(strtotime('today 01:30'), 'daily', 'seom_daily_keywords');
    }
    if (!wp_next_scheduled('seom_weekly_autocomplete')) {
        wp_schedule_event(strtotime('next sunday 04:00'), 'weekly', 'seom_weekly_autocomplete');
    }
    if (!wp_next_scheduled('seom_daily_goal_email')) {
        wp_schedule_event(strtotime('today 08:00'), 'daily', 'seom_daily_goal_email');
    }
}

register_deactivation_hook(__FILE__, 'seom_deactivate');
function seom_deactivate() {
    wp_clear_scheduled_hook('seom_daily_collect');
    wp_clear_scheduled_hook('seom_daily_analyze');
    wp_clear_scheduled_hook('seom_daily_process');
    wp_clear_scheduled_hook('seom_weekly_backfill');
    wp_clear_scheduled_hook('seom_process_next');
    wp_clear_scheduled_hook('seom_daily_keywords');
    wp_clear_scheduled_hook('seom_weekly_autocomplete');
    wp_clear_scheduled_hook('seom_daily_goal_email');
}

// ─── Database Tables ─────────────────────────────────────────────────────────

function seom_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Page metrics snapshot (daily GSC data per page)
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_page_metrics (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        url varchar(500) NOT NULL,
        date_collected date NOT NULL,
        clicks int NOT NULL DEFAULT 0,
        impressions int NOT NULL DEFAULT 0,
        ctr decimal(5,2) NOT NULL DEFAULT 0,
        avg_position decimal(5,1) NOT NULL DEFAULT 0,
        top_queries text,
        search_appearance text,
        PRIMARY KEY (id),
        UNIQUE KEY page_date (post_id, date_collected),
        KEY date_collected (date_collected)
    ) $charset;");

    // Refresh queue
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_refresh_queue (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        post_type varchar(20) NOT NULL DEFAULT 'product',
        priority_score decimal(5,2) NOT NULL DEFAULT 0,
        category char(1) NOT NULL,
        refresh_type varchar(20) NOT NULL DEFAULT 'full',
        status varchar(20) NOT NULL DEFAULT 'pending',
        queued_at datetime NOT NULL,
        started_at datetime DEFAULT NULL,
        completed_at datetime DEFAULT NULL,
        error_message text DEFAULT NULL,
        PRIMARY KEY (id),
        KEY post_id (post_id),
        KEY status_priority (status, priority_score)
    ) $charset;");

    // Refresh history with before/after metrics
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_refresh_history (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        refresh_date datetime NOT NULL,
        refresh_type varchar(20) NOT NULL,
        category char(1) NOT NULL,
        priority_score decimal(5,2) DEFAULT NULL,
        clicks_before int DEFAULT NULL,
        impressions_before int DEFAULT NULL,
        position_before decimal(5,1) DEFAULT NULL,
        ctr_before decimal(5,2) DEFAULT NULL,
        clicks_after_30d int DEFAULT NULL,
        impressions_after_30d int DEFAULT NULL,
        position_after_30d decimal(5,1) DEFAULT NULL,
        ctr_after_30d decimal(5,2) DEFAULT NULL,
        clicks_after_60d int DEFAULT NULL,
        impressions_after_60d int DEFAULT NULL,
        position_after_60d decimal(5,1) DEFAULT NULL,
        ctr_after_60d decimal(5,2) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY post_id (post_id),
        KEY refresh_date (refresh_date)
    ) $charset;");

    // Keywords table — site-wide keyword intelligence
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_keywords (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        keyword varchar(255) NOT NULL,
        source varchar(20) NOT NULL DEFAULT 'gsc',
        impressions int DEFAULT 0,
        clicks int DEFAULT 0,
        avg_position decimal(5,1) DEFAULT 0,
        ctr decimal(5,2) DEFAULT 0,
        impressions_prev int DEFAULT 0,
        trend_direction varchar(10) DEFAULT NULL,
        trend_pct decimal(5,1) DEFAULT 0,
        opportunity_score decimal(5,2) DEFAULT 0,
        mapped_post_id bigint(20) unsigned DEFAULT NULL,
        cannibalization_ids text DEFAULT NULL,
        is_content_gap tinyint(1) DEFAULT 0,
        date_collected date NOT NULL,
        PRIMARY KEY (id),
        KEY keyword_date (keyword(191), date_collected),
        KEY opportunity_score (opportunity_score),
        KEY mapped_post_id (mapped_post_id),
        KEY is_content_gap (is_content_gap),
        KEY trend_direction (trend_direction)
    ) $charset;");

    // Keyword gaps — imported from SEMrush/Ahrefs competitive gap analysis
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_keyword_gaps (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        keyword varchar(255) NOT NULL,
        search_volume int DEFAULT 0,
        keyword_difficulty int DEFAULT 0,
        cpc decimal(6,2) DEFAULT 0,
        intent varchar(50) DEFAULT NULL,
        your_position int DEFAULT 0,
        competitor_1_position int DEFAULT 0,
        competitor_2_position int DEFAULT 0,
        tag varchar(100) DEFAULT NULL,
        source varchar(30) DEFAULT 'semrush',
        date_imported date NOT NULL,
        last_used_at date DEFAULT NULL,
        used_in_post_id bigint(20) unsigned DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY keyword_source (keyword(191), source),
        KEY tag (tag),
        KEY search_volume (search_volume),
        KEY date_imported (date_imported),
        KEY last_used_at (last_used_at)
    ) $charset;");

    // Keyword usage tracking — cooldown for both GSC and imported gap keywords
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_keyword_usage (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        keyword varchar(255) NOT NULL,
        source varchar(20) NOT NULL DEFAULT 'gsc',
        status varchar(10) NOT NULL DEFAULT 'used',
        used_at date NOT NULL,
        post_id bigint(20) unsigned DEFAULT NULL,
        queue_id bigint(20) unsigned DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY keyword_source (keyword(191), source),
        KEY used_at (used_at),
        KEY status (status)
    ) $charset;");

    // LSI keywords — long-tail keyword discoveries linked to seed keywords
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_lsi_keywords (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        seed_keyword varchar(255) NOT NULL,
        lsi_keyword varchar(255) NOT NULL,
        search_volume int DEFAULT 0,
        source varchar(30) DEFAULT 'ai',
        date_added date NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY seed_lsi (seed_keyword(100), lsi_keyword(100)),
        KEY seed_keyword (seed_keyword(191))
    ) $charset;");

    // Keyword suggestions from autocomplete
    // Goals tracking
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_goals (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        metric varchar(50) NOT NULL,
        direction varchar(10) NOT NULL DEFAULT 'reduce',
        target_value decimal(10,2) NOT NULL,
        target_type varchar(10) NOT NULL DEFAULT 'percent',
        baseline_value decimal(10,2) NOT NULL,
        current_value decimal(10,2) DEFAULT NULL,
        start_date date NOT NULL,
        deadline date NOT NULL,
        priority tinyint NOT NULL DEFAULT 3,
        created_at datetime NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'active',
        ai_assessment text,
        notes text,
        PRIMARY KEY (id),
        KEY status (status),
        KEY deadline (deadline)
    ) $charset;");

    dbDelta("CREATE TABLE {$wpdb->prefix}seom_keyword_suggestions (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        seed_keyword varchar(255) NOT NULL,
        suggestion varchar(255) NOT NULL,
        source varchar(20) NOT NULL DEFAULT 'autocomplete',
        date_collected date NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY seed_suggestion (seed_keyword(100), suggestion(100), source)
    ) $charset;");
}

// Add missing columns to existing tables (dbDelta doesn't handle ALTER TABLE)
function seom_upgrade_tables() {
    global $wpdb;
    $table = $wpdb->prefix . 'seom_page_metrics';

    // Check if search_appearance column exists
    $col = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'search_appearance'");
    if (!$col) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN search_appearance text AFTER top_queries");
    }
}

// Lightweight upgrade — no heavy DB operations on page load
add_action('wp_ajax_seom_upgrade_db', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    seom_upgrade_tables();
    wp_send_json_success('Done');
});

// Auto-create/upgrade keyword tables
add_action('admin_init', function () {
    global $wpdb;

    // Keyword gaps table
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        seom_create_tables();
    } else {
        $col = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'last_used_at'");
        if (!$col) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN last_used_at date DEFAULT NULL AFTER date_imported");
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN used_in_post_id bigint(20) unsigned DEFAULT NULL AFTER last_used_at");
            $wpdb->query("ALTER TABLE {$table} ADD KEY last_used_at (last_used_at)");
        }
    }

    // Keyword usage tracking table + LSI table
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") !== $usage_table) {
        seom_create_tables();
    } else {
        $col = $wpdb->get_var("SHOW COLUMNS FROM {$usage_table} LIKE 'status'");
        if (!$col) {
            $wpdb->query("ALTER TABLE {$usage_table} ADD COLUMN status varchar(10) NOT NULL DEFAULT 'used' AFTER source");
            $wpdb->query("ALTER TABLE {$usage_table} ADD COLUMN queue_id bigint(20) unsigned DEFAULT NULL AFTER post_id");
        }
    }
    $lsi_table = $wpdb->prefix . 'seom_lsi_keywords';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$lsi_table}'") !== $lsi_table) {
        seom_create_tables();
    }
    $goals_table = $wpdb->prefix . 'seom_goals';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$goals_table}'") !== $goals_table) {
        $charset = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$goals_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            metric varchar(50) NOT NULL,
            direction varchar(10) NOT NULL DEFAULT 'reduce',
            target_value decimal(10,2) NOT NULL,
            target_type varchar(10) NOT NULL DEFAULT 'percent',
            baseline_value decimal(10,2) NOT NULL,
            current_value decimal(10,2) DEFAULT NULL,
            start_date date NOT NULL,
            deadline date NOT NULL,
            priority tinyint NOT NULL DEFAULT 3,
            created_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            ai_assessment text,
            notes text,
            PRIMARY KEY (id),
            KEY status (status),
            KEY deadline (deadline)
        ) {$charset}");
    }
    // Always check for missing columns (table may already exist without them)
    $col = $wpdb->get_var("SHOW COLUMNS FROM {$goals_table} LIKE 'start_date'");
    if (!$col) {
        $wpdb->query("ALTER TABLE {$goals_table} ADD COLUMN start_date date NOT NULL DEFAULT '2026-04-01' AFTER current_value");
    }
    $col2 = $wpdb->get_var("SHOW COLUMNS FROM {$goals_table} LIKE 'priority'");
    if (!$col2) {
        $wpdb->query("ALTER TABLE {$goals_table} ADD COLUMN priority tinyint NOT NULL DEFAULT 3 AFTER deadline");
    }
}, 99);

// ─── Settings ────────────────────────────────────────────────────────────────

function seom_get_settings() {
    return wp_parse_args(get_option('seom_settings', []), [
        'gsc_credentials_json' => '',
        'gsc_property_url'     => '',
        'daily_limit'          => 20,
        'cooldown_days'        => 90,
        'process_post_types'   => ['product', 'post', 'page'],
        'exclude_post_ids'     => '',
        'exclude_categories'   => '',
        'ghost_threshold'      => 0,
        'ctr_fix_min_impressions' => 100,
        'ctr_fix_max_ctr'      => 1.5,
        'near_win_min_pos'     => 11,
        'near_win_max_pos'     => 20,
        'near_win_min_impressions' => 50,
        'decline_threshold_pct'=> 30,
        'buried_min_impressions'=> 30,
        'visible_min_impressions' => 500,
        'visible_max_clicks'   => 10,
        'gap_keyword_cooldown'  => 90,
        'gap_seed_categories'  => '',
        'enabled'              => false,
        'dry_run'              => true,
        'notify_email'         => get_option('admin_email'),
    ]);
}

function seom_update_settings($new) {
    $current = seom_get_settings();
    update_option('seom_settings', array_merge($current, $new));
}

// ─── Load Includes ───────────────────────────────────────────────────────────

require_once SEOM_PATH . 'includes/class-gsc-client.php';
require_once SEOM_PATH . 'includes/class-collector.php';
require_once SEOM_PATH . 'includes/class-analyzer.php';
require_once SEOM_PATH . 'includes/class-processor.php';
require_once SEOM_PATH . 'includes/class-blog-refresher.php';
require_once SEOM_PATH . 'includes/class-keyword-researcher.php';
require_once SEOM_PATH . 'includes/class-dashboard.php';

// ─── Cron Hooks ──────────────────────────────────────────────────────────────

add_action('seom_daily_collect', function () {
    // For cron, run all batches sequentially
    $result = SEOM_Collector::run(0);
    if (is_wp_error($result)) return;
    $total_batches = $result['total_batches'] ?? 0;
    for ($i = 1; $i <= $total_batches; $i++) {
        SEOM_Collector::run($i);
    }
});
add_action('seom_daily_analyze', ['SEOM_Analyzer', 'run']);
add_action('seom_daily_process', ['SEOM_Processor', 'start_daily']);
add_action('seom_process_next', ['SEOM_Processor', 'process_next']);
add_action('seom_weekly_backfill', ['SEOM_Collector', 'backfill_history']);
add_action('seom_daily_keywords', function () {
    @set_time_limit(600);
    // Phase 0: Fetch GSC data
    $result = SEOM_Keyword_Researcher::collect(0);
    if (is_wp_error($result)) return;

    // Process all batches
    $total_batches = $result['total_batches'] ?? 1;
    for ($b = 1; $b <= $total_batches + 1; $b++) {
        $r = SEOM_Keyword_Researcher::collect($b);
        if (is_wp_error($r)) break;
        if (isset($r['phase']) && $r['phase'] === 'complete') break;
    }
});
add_action('seom_weekly_autocomplete', function () {
    SEOM_Keyword_Researcher::expand_with_autocomplete(50);
});

// Daily goal progress email
add_action('seom_daily_goal_email', function () {
    global $wpdb;
    $goals_table = $wpdb->prefix . 'seom_goals';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$goals_table}'") !== $goals_table) return;

    // ── Monthly goal rollover: on the 1st, close last month's goals and auto-create new ones ──
    if (date('j') === '1') {
        // Close any active goals whose deadline has passed
        $wpdb->query("UPDATE {$goals_table} SET status = 'missed' WHERE status = 'active' AND deadline < CURDATE()");

        // Check if we already have active goals for this month
        $month_end = date('Y-m-t'); // last day of current month
        $existing_this_month = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$goals_table} WHERE status = 'active' AND deadline >= %s",
            $month_end
        ));

        if ($existing_this_month === 0) {
            // Auto-create goals via the same logic as the AJAX handler
            @set_time_limit(60);
            $settings_g = seom_get_settings();
            $table = $wpdb->prefix . 'seom_page_metrics';
            $ghost_threshold = intval($settings_g['ghost_threshold']);
            $daily_limit = intval($settings_g['daily_limit']);
            $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");

            if ($latest_date) {
                $m = $wpdb->get_row($wpdb->prepare("
                    SELECT
                        SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
                        SUM(m.clicks) as total_clicks, SUM(m.impressions) as total_impressions,
                        AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
                        AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
                        SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
                        SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
                        SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
                        COUNT(DISTINCT m.post_id) as total_pages
                    FROM {$table} m
                    INNER JOIN (SELECT post_id, MAX(date_collected) as max_date FROM {$table} GROUP BY post_id) latest
                        ON m.post_id = latest.post_id AND m.date_collected = latest.max_date
                    JOIN {$wpdb->posts} p ON m.post_id = p.ID
                    WHERE p.post_status = 'publish' AND p.post_type IN ('product','post','page')
                ", $ghost_threshold));

                // Get prior month's results for AI context
                $last_month_goals = $wpdb->get_results("
                    SELECT metric, direction, target_value, target_type, baseline_value, current_value, status
                    FROM {$goals_table} WHERE deadline >= DATE_SUB(CURDATE(), INTERVAL 35 DAY) AND deadline < CURDATE()
                    ORDER BY deadline DESC
                ");
                $history_context = '';
                foreach ($last_month_goals as $lg) {
                    $met = $lg->status === 'completed' ? 'MET' : ($lg->status === 'missed' ? 'MISSED' : $lg->status);
                    $history_context .= "- {$lg->metric}: {$lg->direction} by {$lg->target_value}" . ($lg->target_type === 'percent' ? '%' : '')
                        . " (baseline {$lg->baseline_value}, ended at {$lg->current_value}) — {$met}\n";
                }

                $site_name = get_bloginfo('name');
                $now = new \DateTime();
                $month_end_dt = new \DateTime('last day of this month');
                $days_in_month = $now->diff($month_end_dt)->days;

                // Content production metrics
                $m->new_content_30d = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','product') AND post_status = 'publish' AND post_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
                $cooldown_d = intval($settings_g['cooldown_days']);
                $m->stale_pages = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'last_page_refresh' WHERE p.post_type IN ('post','product') AND p.post_status = 'publish' AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND (pm.meta_value IS NULL OR pm.meta_value < DATE_SUB(CURDATE(), INTERVAL %d DAY))", $cooldown_d));
                $m->refreshed_this_month = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history WHERE refresh_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");

                $metrics_summary = "Current metrics for {$site_name}:\n"
                    . "- Ghost Pages: {$m->ghost_pages}\n- Total Clicks (28d): {$m->total_clicks}\n"
                    . "- Total Impressions (28d): {$m->total_impressions}\n- Avg Position: " . round($m->avg_position, 1) . "\n"
                    . "- Avg CTR: " . round($m->avg_ctr, 2) . "%\n- Pages on Page 1: {$m->page1_pages}\n"
                    . "- Pages on Page 2: {$m->page2_pages}\n- Pages With Impressions: {$m->pages_with_impressions}\n"
                    . "- Total Pages: {$m->total_pages}\n"
                    . "- New Content Created (30d): {$m->new_content_30d}\n"
                    . "- Stale Pages (not refreshed 90+ days): {$m->stale_pages}\n"
                    . "- Pages Refreshed This Month: {$m->refreshed_this_month}\n"
                    . "- Daily Refresh Capacity: {$daily_limit}\n"
                    . "- Days in Month: {$days_in_month}\n";
                if ($history_context) $metrics_summary .= "\nLast month's goal results:\n{$history_context}\n";

                $prompt = "You are an SEO strategist. Based on the metrics below, create 3-5 realistic goals for this new month.\n\n"
                    . $metrics_summary . "\n"
                    . "RULES:\n"
                    . "- If prior goals were MISSED, suggest a more conservative version (lower target %)\n"
                    . "- If prior goals were MET, suggest a stretch version (higher target %) or a new metric\n"
                    . "- Use percentage targets (target_type: 'percent')\n"
                    . "- Include a mix of priorities (1=Critical through 5=Backlog)\n"
                    . "- Be realistic — SEO changes take 2-4 weeks to show\n\n"
                    . "Return a JSON object: {\"goals\": [{\"metric\": \"ghost_pages\", \"direction\": \"reduce\", \"target_value\": 10, \"target_type\": \"percent\", \"priority\": 2, \"notes\": \"reason\"}]}\n"
                    . "Valid metrics: ghost_pages, total_clicks, total_impressions, avg_position, avg_ctr, page1_pages, page2_pages, pages_with_impressions, new_content_30d, stale_pages, refreshed_this_month";

                $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
                $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

                if ($api_key) {
                    $body_payload = [
                        'model' => $model,
                        'messages' => [
                            ['role' => 'system', 'content' => $prompt],
                            ['role' => 'user', 'content' => 'Generate monthly SEO goals. Return JSON with a "goals" array.'],
                        ],
                        'temperature' => 0.4,
                    ];
                    if (strpos($model, 'gpt-4') !== false) $body_payload['response_format'] = ['type' => 'json_object'];

                    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                        'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
                        'body' => json_encode($body_payload), 'timeout' => 45,
                    ]);

                    if (!is_wp_error($response)) {
                        $data = json_decode(wp_remote_retrieve_body($response), true);
                        $raw = trim($data['choices'][0]['message']['content'] ?? '');
                        $raw = preg_replace('/^```[a-z]*\s*/m', '', $raw);
                        $raw = preg_replace('/\s*```\s*$/m', '', $raw);
                        $goals = json_decode($raw, true);
                        if (isset($goals['goals'])) $goals = $goals['goals'];

                        if (is_array($goals)) {
                            $metric_map = (array) $m;
                            $month_start = date('Y-m-01');
                            foreach ($goals as $g) {
                                $gm = sanitize_text_field($g['metric'] ?? '');
                                if (empty($gm) || !isset($metric_map[$gm])) continue;
                                $wpdb->insert($goals_table, [
                                    'metric'         => $gm,
                                    'direction'      => sanitize_text_field($g['direction'] ?? 'reduce'),
                                    'target_value'   => floatval($g['target_value'] ?? 5),
                                    'target_type'    => sanitize_text_field($g['target_type'] ?? 'percent'),
                                    'baseline_value' => floatval($metric_map[$gm]),
                                    'current_value'  => floatval($metric_map[$gm]),
                                    'start_date'     => $month_start,
                                    'deadline'       => $month_end,
                                    'priority'       => max(1, min(5, intval($g['priority'] ?? 3))),
                                    'created_at'     => current_time('mysql'),
                                    'status'         => 'active',
                                    'ai_assessment'  => '',
                                    'notes'          => sanitize_text_field($g['notes'] ?? '') . ' (auto-created)',
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    $active_goals = $wpdb->get_results("SELECT * FROM {$goals_table} WHERE status = 'active' ORDER BY deadline ASC");
    if (empty($active_goals)) return;

    $settings = seom_get_settings();
    $email = $settings['notify_email'] ?? get_option('admin_email');
    if (empty($email)) return;

    // Get current metrics
    $table = $wpdb->prefix . 'seom_page_metrics';
    $ghost_threshold = intval($settings['ghost_threshold']);
    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");
    if (!$latest_date) return;

    $metrics = $wpdb->get_row($wpdb->prepare("
        SELECT
            SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
            SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
            SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions
        FROM {$table} m
        INNER JOIN (SELECT post_id, MAX(date_collected) as max_date FROM {$table} GROUP BY post_id) latest
            ON m.post_id = latest.post_id AND m.date_collected = latest.max_date
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE p.post_status = 'publish' AND p.post_type IN ('product','post','page')
    ", $ghost_threshold));

    $metric_values = (array) $metrics;
    $metric_labels = [
        'ghost_pages'            => 'Ghost Pages',
        'total_clicks'           => 'Total Clicks (28d)',
        'total_impressions'      => 'Total Impressions (28d)',
        'avg_position'           => 'Avg Position',
        'avg_ctr'                => 'Avg CTR (%)',
        'page1_pages'            => 'Pages on Page 1',
        'page2_pages'            => 'Pages on Page 2',
        'pages_with_impressions' => 'Pages With Impressions',
        'new_content_30d'        => 'New Content (30d)',
        'stale_pages'            => 'Stale Pages (90+ days)',
        'refreshed_this_month'   => 'Refreshed This Month',
    ];

    $site_name = get_bloginfo('name');
    $today = date('Y-m-d');

    // Update current values and check deadlines
    $on_track = 0;
    $behind = 0;
    $goal_rows = '';

    foreach ($active_goals as $g) {
        $current = isset($metric_values[$g->metric]) ? floatval($metric_values[$g->metric]) : floatval($g->current_value);
        $wpdb->update($goals_table, ['current_value' => $current], ['id' => $g->id]);

        $baseline = floatval($g->baseline_value);
        $target = floatval($g->target_value);

        // Calculate target number
        if ($g->target_type === 'percent') {
            $change_needed = $baseline * ($target / 100);
            $target_num = ($g->direction === 'reduce') ? $baseline - $change_needed : $baseline + $change_needed;
        } else {
            $target_num = $target;
        }

        // Progress
        $total_change = abs($target_num - $baseline);
        $actual_change = ($g->direction === 'reduce') ? $baseline - $current : $current - $baseline;
        $progress = $total_change > 0 ? min(100, max(0, round(($actual_change / $total_change) * 100))) : 0;

        // Time progress
        $start = $g->start_date ?: substr($g->created_at, 0, 10);
        $total_days = max(1, (strtotime($g->deadline) - strtotime($start)) / 86400);
        $days_elapsed = max(0, (strtotime($today) - strtotime($start)) / 86400);
        $time_pct = min(100, round(($days_elapsed / $total_days) * 100));
        $days_left = max(0, ceil((strtotime($g->deadline) - time()) / 86400));

        $is_on_track = $progress >= $time_pct;
        if ($is_on_track) $on_track++; else $behind++;

        // Check if deadline passed
        if ($today > $g->deadline) {
            $met = ($g->direction === 'reduce') ? ($current <= $target_num) : ($current >= $target_num);
            $wpdb->update($goals_table, ['status' => $met ? 'completed' : 'missed', 'current_value' => $current], ['id' => $g->id]);
        }

        $label = $metric_labels[$g->metric] ?? $g->metric;
        $dir_arrow = ($g->direction === 'reduce') ? '&#9660;' : '&#9650;';
        $type_label = ($g->target_type === 'percent') ? $target . '%' : $target;
        $status_color = $is_on_track ? '#059669' : '#dc2626';
        $status_label = $is_on_track ? 'On Track' : 'Behind';
        $prog_color = $progress >= 75 ? '#059669' : ($progress >= 40 ? '#d97706' : '#dc2626');

        $goal_rows .= '<tr>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;">' . $dir_arrow . ' ' . ucfirst($g->direction) . ' ' . esc_html($label) . ' by ' . $type_label . '</td>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;">' . round($baseline, 1) . '</td>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;font-weight:700;">' . round($current, 1) . '</td>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;">' . round($target_num, 1) . '</td>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;">';
        $goal_rows .= '<div style="background:#e2e8f0;border-radius:4px;height:10px;width:100px;display:inline-block;vertical-align:middle;">';
        $goal_rows .= '<div style="background:' . $prog_color . ';height:100%;width:' . $progress . '%;border-radius:4px;"></div></div>';
        $goal_rows .= ' <strong>' . $progress . '%</strong></td>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;color:' . $status_color . ';font-weight:600;">' . $status_label . '</td>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;">' . $days_left . 'd</td>';
        $goal_rows .= '</tr>';
    }

    $subject = "[{$site_name}] SEO Goals: {$on_track} on track, {$behind} behind — " . date('M j');

    $body = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:700px;margin:0 auto;">';
    $body .= '<div style="background:#0f172a;color:#fff;padding:20px 28px;border-radius:10px 10px 0 0;">';
    $body .= '<h1 style="margin:0;font-size:20px;color:#fff;">SEO Goal Progress Report</h1>';
    $body .= '<p style="margin:6px 0 0;color:#94a3b8;font-size:13px;">' . $site_name . ' &mdash; ' . date('l, F j, Y') . '</p>';
    $body .= '</div>';

    $body .= '<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;padding:20px 28px;border-radius:0 0 10px 10px;">';

    // Summary cards
    $body .= '<div style="display:flex;gap:12px;margin-bottom:20px;">';
    $body .= '<div style="flex:1;background:#ecfdf5;padding:14px;border-radius:8px;text-align:center;"><div style="font-size:24px;font-weight:700;color:#059669;">' . $on_track . '</div><div style="font-size:11px;color:#065f46;text-transform:uppercase;">On Track</div></div>';
    $body .= '<div style="flex:1;background:#fee2e2;padding:14px;border-radius:8px;text-align:center;"><div style="font-size:24px;font-weight:700;color:#dc2626;">' . $behind . '</div><div style="font-size:11px;color:#991b1b;text-transform:uppercase;">Behind</div></div>';
    $body .= '<div style="flex:1;background:#f1f5f9;padding:14px;border-radius:8px;text-align:center;"><div style="font-size:24px;font-weight:700;color:#334155;">' . count($active_goals) . '</div><div style="font-size:11px;color:#64748b;text-transform:uppercase;">Active Goals</div></div>';
    $body .= '</div>';

    // Goals table
    $body .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
    $body .= '<thead><tr style="background:#f1f5f9;">';
    $body .= '<th style="padding:8px 14px;text-align:left;font-weight:600;border-bottom:2px solid #cbd5e1;">Goal</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Baseline</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Current</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Target</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Progress</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Status</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Left</th>';
    $body .= '</tr></thead><tbody>';
    $body .= $goal_rows;
    $body .= '</tbody></table>';

    $goals_url = admin_url('admin.php?page=seo-monitor&tab=goals');
    $body .= '<div style="margin-top:20px;text-align:center;">';
    $body .= '<a href="' . $goals_url . '" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">View Goals Dashboard</a>';
    $body .= '</div>';

    $body .= '<p style="margin-top:16px;font-size:11px;color:#94a3b8;text-align:center;">Data as of ' . $latest_date . '. Sent by SEO Monitor on ' . $site_name . '.</p>';
    $body .= '</div></div>';

    wp_mail($email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
});

// ─── Admin Menu ──────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_menu_page(
        'SEO Monitor',
        'SEO Monitor',
        'manage_woocommerce',
        'seo-monitor',
        ['SEOM_Dashboard', 'render'],
        'dashicons-chart-line',
        57
    );
    // Hidden page for documentation (no menu item — accessed via plugin action link)
    add_submenu_page(null, 'SEO Platform Documentation', '', 'manage_options', 'seo-platform-docs', function () {
        include SEOM_PATH . 'about.html';
    });
});

// "Documentation" link on the Plugins page for SEO Monitor
add_filter('plugin_action_links_seo-monitor/seo-monitor.php', function ($links) {
    $url = admin_url('admin.php?page=seo-platform-docs');
    array_unshift($links, '<a href="' . esc_url($url) . '">Documentation</a>');
    return $links;
});

// ─── AJAX Handlers ───────────────────────────────────────────────────────────

add_action('wp_ajax_seom_test_gsc', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $settings = seom_get_settings();
    $client = new SEOM_GSC_Client($settings['gsc_credentials_json'], $settings['gsc_property_url']);
    $result = $client->test_connection();

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success($result);
});

add_action('wp_ajax_seom_run_collect', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(300);

    $batch_page = intval($_POST['batch_page'] ?? 0);
    $result = SEOM_Collector::run($batch_page);
    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
    wp_send_json_success($result);
});

add_action('wp_ajax_seom_run_analyze', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $result = SEOM_Analyzer::run();
    wp_send_json_success($result);
});

// Process one: runs directly via AJAX with extended timeout.
add_action('wp_ajax_seom_process_one', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    // Extend PHP execution time for multi-step AI processing
    @set_time_limit(600);

    // Catch fatal errors so they return JSON instead of a blank server error
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'data'    => 'PHP Fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'],
                ]);
            }
        }
    });

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Missing post ID.');

    // Prevent concurrent processing — check if another refresh is already running
    $lock = get_transient('seom_processing_lock');
    if ($lock) {
        wp_send_json_error('Another refresh is currently running (started ' . $lock . '). Please wait.');
    }
    set_transient('seom_processing_lock', current_time('H:i:s'), 600); // 10 min max lock

    try {
        $result = SEOM_Processor::process_single($post_id);

        delete_transient('seom_processing_lock');

        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
        wp_send_json_success($result);
    } catch (Throwable $e) {
        delete_transient('seom_processing_lock');
        wp_send_json_error('Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
});

// Step-based processing to avoid 504 gateway timeouts on manual/bulk processing
add_action('wp_ajax_seom_process_step', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(300);
    ob_start();

    $post_id = intval($_POST['post_id'] ?? 0);
    $step    = intval($_POST['step'] ?? 1);
    if (!$post_id) { ob_end_clean(); wp_send_json_error('Missing post ID.'); }

    $settings = seom_get_settings();
    $post_type = get_post_type($post_id);
    $progress_key = 'seom_step_' . $post_id;

    global $wpdb;

    // Step 1: Setup — find/create queue item, get before metrics, determine refresh type
    if ($step === 1) {
        if (!function_exists('aipm_step_description')) {
            ob_end_clean();
            wp_send_json_error('AI Product Manager plugin not active.');
        }

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}seom_refresh_queue WHERE post_id = %d AND status = 'pending' LIMIT 1",
            $post_id
        ));

        if (!$item) {
            $wpdb->insert("{$wpdb->prefix}seom_refresh_queue", [
                'post_id'        => $post_id,
                'post_type'      => $post_type,
                'priority_score' => 99,
                'category'       => 'M',
                'refresh_type'   => 'full',
                'status'         => 'pending',
                'queued_at'      => current_time('mysql'),
            ]);
            $item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}seom_refresh_queue WHERE post_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1",
                $post_id
            ));
        }

        $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
            ['status' => 'processing', 'started_at' => current_time('mysql')],
            ['id' => $item->id]
        );

        $before = $wpdb->get_row($wpdb->prepare(
            "SELECT clicks, impressions, ctr, avg_position FROM {$wpdb->prefix}seom_page_metrics
             WHERE post_id = %d ORDER BY date_collected DESC LIMIT 1",
            $post_id
        ));

        set_transient($progress_key, [
            'item_id'      => $item->id,
            'post_type'    => $post_type,
            'refresh_type' => $item->refresh_type,
            'category'     => $item->category,
            'priority'     => $item->priority_score,
            'before'       => $before,
        ], 3600);

        // Dry run check
        if ($settings['dry_run']) {
            $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
                ['status' => 'completed', 'completed_at' => current_time('mysql'), 'error_message' => 'Dry run — no changes made.'],
                ['id' => $item->id]
            );
            $wpdb->insert("{$wpdb->prefix}seom_refresh_history", [
                'post_id' => $post_id, 'refresh_date' => current_time('mysql'),
                'refresh_type' => $item->refresh_type . ' (dry run)', 'category' => $item->category,
                'priority_score' => $item->priority_score,
                'clicks_before' => $before->clicks ?? null, 'impressions_before' => $before->impressions ?? null,
                'position_before' => $before->avg_position ?? null, 'ctr_before' => $before->ctr ?? null,
            ]);
            delete_transient($progress_key);
            ob_end_clean();
            wp_send_json_success(['step' => 1, 'complete' => true, 'dry_run' => true, 'post_id' => $post_id]);
        }

        $total_steps = 2; // default for meta_only
        if ($item->refresh_type !== 'meta_only') {
            $total_steps = ($post_type === 'post') ? 5 : 7; // blog: outline+content, meta, faq, schema, timestamp / product: desc, short, faq_html, faq_json, rankmath, seo_title, timestamp
        }

        ob_end_clean();
        wp_send_json_success(['step' => 1, 'total_steps' => $total_steps, 'post_type' => $post_type, 'refresh_type' => $item->refresh_type]);
    }

    // Get progress
    $progress = get_transient($progress_key);
    if (!$progress) { ob_end_clean(); wp_send_json_error('Lost progress data. Start over.'); }

    $refresh_type = $progress['refresh_type'];
    $error = null;

    try {
        if ($post_type === 'post') {
            // Blog steps
            switch ($step) {
                case 2: // Content (or meta for meta_only)
                    if ($refresh_type === 'meta_only') {
                        $r = SEOM_Blog_Refresher::meta_refresh($post_id);
                        if (is_wp_error($r)) throw new Exception($r->get_error_message());
                    } else {
                        $r = SEOM_Blog_Refresher::step_content($post_id);
                        if (is_wp_error($r)) throw new Exception('Content: ' . $r->get_error_message());
                    }
                    break;
                case 3: // Meta description
                    $r = SEOM_Blog_Refresher::step_meta_description($post_id);
                    if (is_wp_error($r)) throw new Exception('Meta: ' . $r->get_error_message());
                    break;
                case 4: // FAQ + Schema
                    $faq = SEOM_Blog_Refresher::step_faq_html($post_id);
                    if (!is_wp_error($faq)) {
                        SEOM_Blog_Refresher::step_faq_json($post_id, $faq);
                    }
                    SEOM_Blog_Refresher::step_rankmath($post_id);
                    SEOM_Blog_Refresher::step_seo_title($post_id);
                    break;
                case 5: // Finalize
                    SEOM_Blog_Refresher::step_timestamp($post_id);
                    break;
            }
        } else {
            // Product steps
            switch ($step) {
                case 2: // Description (or meta_only bundle)
                    if ($refresh_type === 'meta_only') {
                        $r = aipm_step_short_description($post_id);
                        if (is_wp_error($r)) throw new Exception($r->get_error_message());
                        $r = aipm_step_rankmath($post_id);
                        if (is_wp_error($r)) throw new Exception($r->get_error_message());
                        if (function_exists('aipm_step_seo_title')) aipm_step_seo_title($post_id);
                    } else {
                        $r = aipm_step_description($post_id);
                        if (is_wp_error($r)) throw new Exception('Description: ' . $r->get_error_message());
                        $progress['description'] = $r;
                        set_transient($progress_key, $progress, 3600);
                    }
                    break;
                case 3:
                    $r = aipm_step_short_description($post_id, $progress['description'] ?? '');
                    if (is_wp_error($r)) throw new Exception('Short Desc: ' . $r->get_error_message());
                    break;
                case 4:
                    $r = aipm_step_faq_html($post_id);
                    if (is_wp_error($r)) throw new Exception('FAQ HTML: ' . $r->get_error_message());
                    $progress['faq_html'] = $r;
                    set_transient($progress_key, $progress, 3600);
                    break;
                case 5:
                    $r = aipm_step_faq_json($post_id, $progress['faq_html'] ?? '');
                    if (is_wp_error($r)) throw new Exception('FAQ JSON: ' . $r->get_error_message());
                    break;
                case 6:
                    $r = aipm_step_rankmath($post_id);
                    if (is_wp_error($r)) throw new Exception('RankMath: ' . $r->get_error_message());
                    if (function_exists('aipm_step_seo_title')) aipm_step_seo_title($post_id);
                    break;
                case 7:
                    aipm_step_timestamp($post_id);
                    break;
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    // Determine if this is the final step
    $is_final = false;
    if ($refresh_type === 'meta_only') {
        $is_final = ($step === 2);
    } else {
        $is_final = ($post_type === 'post' && $step === 5) || ($post_type !== 'post' && $step === 7);
    }

    // Finalize on last step or error
    if ($is_final || $error) {
        $status = $error ? 'failed' : 'completed';
        $wpdb->update("{$wpdb->prefix}seom_refresh_queue", [
            'status'        => $status,
            'completed_at'  => current_time('mysql'),
            'error_message' => $error,
        ], ['id' => $progress['item_id']]);

        $before = $progress['before'];
        $wpdb->insert("{$wpdb->prefix}seom_refresh_history", [
            'post_id'            => $post_id,
            'refresh_date'       => current_time('mysql'),
            'refresh_type'       => $refresh_type,
            'category'           => $progress['category'],
            'priority_score'     => $progress['priority'],
            'clicks_before'      => $before->clicks ?? null,
            'impressions_before' => $before->impressions ?? null,
            'position_before'    => $before->avg_position ?? null,
            'ctr_before'         => $before->ctr ?? null,
        ]);

        delete_transient($progress_key);

        // Send notification email for completed/failed refresh
        $settings = seom_get_settings();
        if ($settings['notify_email']) {
            $title = get_the_title($post_id);
            $status_label = $error ? 'Failed' : 'Refreshed';
            $subject = "[SEO Monitor] {$status_label}: {$title}";
            $cat_labels = ['A' => 'Ghost', 'B' => 'CTR Fix', 'C' => 'Near Win', 'D' => 'Declining', 'E' => 'Visible/Ignored', 'F' => 'Buried', 'M' => 'Manual'];
            $cat = $cat_labels[$progress['category']] ?? $progress['category'];
            $edit_url = admin_url('post.php?action=edit&post=' . $post_id);
            $view_url = get_permalink($post_id);
            $error_row = $error ? '<tr><td style="padding:8px 12px;color:#999;">Error</td><td style="padding:8px 12px;color:#dc2626;">' . esc_html($error) . '</td></tr>' : '';
            $status_color = $error ? '#dc2626' : '#059669';

            $email_body = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;">'
                . '<div style="background:#1d2327;padding:16px 20px;border-radius:8px 8px 0 0;"><h1 style="margin:0;color:#fff;font-size:16px;">SEO Monitor</h1></div>'
                . '<div style="background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:20px;">'
                . '<div style="display:inline-block;padding:4px 12px;border-radius:4px;background:' . $status_color . ';color:#fff;font-weight:600;font-size:13px;margin-bottom:12px;">' . $status_label . '</div>'
                . '<h2 style="margin:0 0 12px;font-size:18px;">' . esc_html($title) . '</h2>'
                . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">'
                . '<tr><td style="padding:8px 12px;color:#999;">Type</td><td style="padding:8px 12px;">' . esc_html($post_type) . '</td></tr>'
                . '<tr><td style="padding:8px 12px;color:#999;">Category</td><td style="padding:8px 12px;"><strong>' . esc_html($cat) . '</strong></td></tr>'
                . '<tr><td style="padding:8px 12px;color:#999;">Refresh</td><td style="padding:8px 12px;">' . esc_html($refresh_type) . '</td></tr>'
                . $error_row . '</table>'
                . '<a href="' . esc_url($view_url) . '" style="display:inline-block;padding:8px 16px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-size:13px;margin-right:8px;">View</a>'
                . '<a href="' . esc_url($edit_url) . '" style="display:inline-block;padding:8px 16px;background:#f0f0f1;color:#1d2327;text-decoration:none;border-radius:4px;font-size:13px;">Edit</a>'
                . '<p style="margin-top:16px;color:#9ca3af;font-size:11px;">Manual refresh via ' . esc_html(get_bloginfo('name')) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '</p>'
                . '</div></div>';

            $set_html = function () { return 'text/html'; };
            add_filter('wp_mail_content_type', $set_html);
            wp_mail($settings['notify_email'], $subject, $email_body);
            remove_filter('wp_mail_content_type', $set_html);
        }

        if ($error) {
            ob_end_clean();
            wp_send_json_error('Step ' . $step . ': ' . $error);
        }

        ob_end_clean();
        wp_send_json_success(['step' => $step, 'complete' => true, 'post_id' => $post_id, 'status' => 'completed']);
    }

    ob_end_clean();
    wp_send_json_success(['step' => $step]);
});

add_action('wp_ajax_seom_skip_item', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $id = intval($_POST['queue_id'] ?? 0);
    if (!$id) wp_send_json_error('Missing queue ID.');

    $wpdb->update("{$wpdb->prefix}seom_refresh_queue", ['status' => 'skipped'], ['id' => $id]);
    wp_send_json_success();
});

// Bulk queue actions
add_action('wp_ajax_seom_queue_bulk', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_refresh_queue';
    $action = sanitize_text_field($_POST['bulk_action'] ?? '');
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));

    switch ($action) {
        case 'skip':
            if (empty($ids)) wp_send_json_error('No items selected.');
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'skipped' WHERE id IN ($placeholders)", ...$ids));
            wp_send_json_success(['affected' => count($ids)]);
            break;

        case 'delete':
            if (empty($ids)) wp_send_json_error('No items selected.');
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders) AND status = 'pending'", ...$ids));
            wp_send_json_success(['affected' => count($ids)]);
            break;

        case 'prioritize':
            if (empty($ids)) wp_send_json_error('No items selected.');
            // Set priority to 999 so they process first
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("UPDATE {$table} SET priority_score = 999 WHERE id IN ($placeholders)", ...$ids));
            wp_send_json_success(['affected' => count($ids)]);
            break;

        case 'clear':
            $affected = $wpdb->query("DELETE FROM {$table} WHERE status = 'pending'");
            wp_send_json_success(['affected' => $affected]);
            break;

        default:
            wp_send_json_error('Invalid action.');
    }
});

add_action('wp_ajax_seom_save_settings', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $fields = [
        'gsc_property_url', 'notify_email', 'exclude_post_ids', 'exclude_categories', 'gap_seed_categories',
    ];
    $int_fields = [
        'daily_limit', 'cooldown_days', 'ghost_threshold',
        'ctr_fix_min_impressions', 'near_win_min_impressions',
        'buried_min_impressions', 'visible_min_impressions', 'visible_max_clicks', 'gap_keyword_cooldown',
    ];
    $float_fields = ['ctr_fix_max_ctr', 'near_win_min_pos', 'near_win_max_pos', 'decline_threshold_pct'];
    $bool_fields = ['enabled', 'dry_run'];

    $new = [];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) $new[$f] = sanitize_text_field($_POST[$f]);
    }
    // Handle credentials JSON separately — only update if new valid JSON with a real private key is pasted
    if (isset($_POST['gsc_credentials_json']) && !empty(trim($_POST['gsc_credentials_json']))) {
        $json_raw = wp_unslash($_POST['gsc_credentials_json']);
        $parsed = json_decode($json_raw, true);
        if ($parsed && !empty($parsed['client_email']) && !empty($parsed['private_key'])) {
            // Skip if this is the redacted display version — keep the existing saved credentials
            if (strpos($parsed['private_key'], 'REDACTED') === false && strpos($parsed['private_key'], 'BEGIN PRIVATE KEY') !== false) {
                $new['gsc_credentials_json'] = $json_raw;
            }
        }
    }
    foreach ($int_fields as $f) {
        if (isset($_POST[$f])) $new[$f] = intval($_POST[$f]);
    }
    foreach ($float_fields as $f) {
        if (isset($_POST[$f])) $new[$f] = floatval($_POST[$f]);
    }
    foreach ($bool_fields as $f) {
        $new[$f] = isset($_POST[$f]) && $_POST[$f] === '1';
    }
    if (isset($_POST['process_post_types'])) {
        $new['process_post_types'] = array_map('sanitize_text_field', (array) $_POST['process_post_types']);
    }

    seom_update_settings($new);
    wp_send_json_success('Settings saved.');
});

add_action('wp_ajax_seom_get_indexed', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $post_type = sanitize_text_field($_POST['post_type'] ?? 'product');
    $type_sql = ($post_type === 'all')
        ? "p.post_type IN ('product','post','page')"
        : $wpdb->prepare("p.post_type = %s", $post_type);
    $sort = sanitize_text_field($_POST['sort'] ?? 'clicks');
    $order = sanitize_text_field($_POST['order'] ?? 'DESC');
    $filter = sanitize_text_field($_POST['filter'] ?? 'all');
    $page = max(1, intval($_POST['page'] ?? 1));
    $date_range = max(1, min(365, intval($_POST['date_range'] ?? 28)));
    $per_page = 50;
    $offset = ($page - 1) * $per_page;
    $date_warning = '';

    // For non-default date ranges, fetch fresh from GSC and store in temp table
    $use_live_gsc = ($date_range !== 28);
    $metrics_table = $wpdb->prefix . 'seom_page_metrics';
    $temp_table = '';

    if ($use_live_gsc) {
        @set_time_limit(60);
        $settings_gsc = seom_get_settings();
        if (empty($settings_gsc['gsc_credentials_json']) || empty($settings_gsc['gsc_property_url'])) {
            wp_send_json_error('GSC not configured. Date range filtering requires Google Search Console connection.');
        }

        // Check cache first (1 hour per date range)
        $cache_key = 'seom_gsc_range_' . $date_range;
        $cached = get_transient($cache_key);

        if (!$cached) {
            $client = new SEOM_GSC_Client($settings_gsc['gsc_credentials_json'], $settings_gsc['gsc_property_url']);
            $live_metrics = $client->get_all_page_metrics($date_range);
            if (is_wp_error($live_metrics)) {
                wp_send_json_error('GSC API error: ' . $live_metrics->get_error_message());
            }
            set_transient($cache_key, $live_metrics, 3600);
            $cached = $live_metrics;
        }

        // Check how many days of stored data we have
        $earliest = $wpdb->get_var("SELECT MIN(date_collected) FROM {$metrics_table}");
        if ($earliest) {
            $days_available = (int) ((time() - strtotime($earliest)) / 86400);
            if ($days_available < $date_range) {
                $date_warning = "Our data collection started {$days_available} days ago. "
                    . "The GSC API is providing data for the full {$date_range}-day range, but our local trend tracking and history only covers {$days_available} days. "
                    . "The metrics shown below come directly from GSC and are accurate for the selected range.";
            }
        }

        // Build a temp table in memory for the live data
        $temp_table = $wpdb->prefix . 'seom_live_metrics';
        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}");
        $wpdb->query("CREATE TEMPORARY TABLE {$temp_table} (
            post_id BIGINT UNSIGNED NOT NULL,
            url VARCHAR(500),
            clicks INT DEFAULT 0,
            impressions INT DEFAULT 0,
            ctr FLOAT DEFAULT 0,
            avg_position FLOAT DEFAULT 0,
            date_collected DATE,
            top_queries TEXT,
            PRIMARY KEY (post_id)
        ) ENGINE=MEMORY");

        // Build URL→post_id map from existing metrics table (already stored URLs, no get_permalink calls)
        $url_map = [];
        $stored_urls = $wpdb->get_results("SELECT post_id, url FROM {$wpdb->prefix}seom_page_metrics WHERE url IS NOT NULL AND url != '' GROUP BY post_id, url");
        foreach ($stored_urls as $su) {
            $url_map[$su->url] = (int) $su->post_id;
            $url_map[rtrim($su->url, '/')] = (int) $su->post_id;
        }

        // Batch insert GSC data using bulk VALUES
        $today = date('Y-m-d');
        $inserted_ids = [];
        $batch_values = [];
        $batch_count = 0;

        foreach ($cached as $url => $data) {
            $pid = $url_map[$url] ?? $url_map[rtrim($url, '/')] ?? $url_map[$url . '/'] ?? null;
            if (!$pid || isset($inserted_ids[$pid])) continue;

            $clicks = intval($data['clicks'] ?? 0);
            $impressions = intval($data['impressions'] ?? 0);
            $ctr = floatval($data['ctr'] ?? 0);
            $position = floatval($data['position'] ?? 0);
            $safe_url = $wpdb->prepare('%s', $url);

            $batch_values[] = "({$pid}, {$safe_url}, {$clicks}, {$impressions}, {$ctr}, {$position}, '{$today}')";
            $inserted_ids[$pid] = true;
            $batch_count++;

            // Flush every 500 rows
            if ($batch_count >= 500) {
                $wpdb->query("INSERT INTO {$temp_table} (post_id, url, clicks, impressions, ctr, avg_position, date_collected) VALUES " . implode(',', $batch_values));
                $batch_values = [];
                $batch_count = 0;
            }
        }

        // Insert zero-rows for posts we track but GSC didn't return — bulk from existing post_ids
        $tracked_ids = $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->prefix}seom_page_metrics");
        foreach ($tracked_ids as $tid) {
            $tid = (int) $tid;
            if (isset($inserted_ids[$tid])) continue;
            $batch_values[] = "({$tid}, '', 0, 0, 0, 0, '{$today}')";
            $inserted_ids[$tid] = true;
            $batch_count++;
            if ($batch_count >= 500) {
                $wpdb->query("INSERT INTO {$temp_table} (post_id, url, clicks, impressions, ctr, avg_position, date_collected) VALUES " . implode(',', $batch_values));
                $batch_values = [];
                $batch_count = 0;
            }
        }

        // Flush remaining
        if (!empty($batch_values)) {
            $wpdb->query("INSERT INTO {$temp_table} (post_id, url, clicks, impressions, ctr, avg_position, date_collected) VALUES " . implode(',', $batch_values));
        }

        $metrics_table = $temp_table;
    }

    $allowed_sorts = ['clicks', 'impressions', 'ctr', 'avg_position', 'post_title', 'post_date', 'last_refresh'];
    if (!in_array($sort, $allowed_sorts)) $sort = 'clicks';
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    $sort_map = [
        'post_title' => 'p.post_title',
        'post_date'  => 'p.post_date',
        'last_refresh' => 'lr_meta.meta_value',
    ];
    $sort_col = $sort_map[$sort] ?? "m.$sort";

    $settings = seom_get_settings();

    // Build filter clause for standard metric-based filters
    $filter_sql = '';
    switch ($filter) {
        case 'ghost':
            $filter_sql = ' AND m.impressions <= ' . intval($settings['ghost_threshold']) . ' AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY)';
            break;
        case 'new':
            $filter_sql = ' AND p.post_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)';
            break;
        case 'page1':
            $filter_sql = ' AND m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0';
            break;
        case 'page2':
            $filter_sql = ' AND m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0';
            break;
        case 'low_ctr':
            // CTR below 50% of position benchmark (matches Category B logic)
            $min_imp = intval($settings['ctr_fix_min_impressions']);
            $filter_sql = " AND m.impressions >= {$min_imp} AND ("
                        . "(m.avg_position <= 1 AND m.ctr < 15)"     // Pos 1: expect 30%, flag <15%
                        . " OR (m.avg_position > 1 AND m.avg_position <= 2 AND m.ctr < 8.5)"  // Pos 2: expect 17%, flag <8.5%
                        . " OR (m.avg_position > 2 AND m.avg_position <= 3 AND m.ctr < 5.5)"  // Pos 3: expect 11%, flag <5.5%
                        . " OR (m.avg_position > 3 AND m.avg_position <= 5 AND m.ctr < 3.5)"  // Pos 4-5: expect 7%, flag <3.5%
                        . " OR (m.avg_position > 5 AND m.avg_position <= 10 AND m.ctr < 1.5)" // Pos 6-10: expect 3%, flag <1.5%
                        . " OR (m.avg_position > 10 AND m.avg_position <= 15 AND m.ctr < 0.5)" // Pos 11-15: expect 1%, flag <0.5%
                        . ")";
            break;
        case 'buried':
            $filter_sql = ' AND m.avg_position > 20 AND m.impressions >= ' . intval($settings['buried_min_impressions']);
            break;
        case 'underperforming':
            $ghost = intval($settings['ghost_threshold']);
            $near_imp = intval($settings['near_win_min_impressions']);
            $buried_imp = intval($settings['buried_min_impressions']);
            $ctr_imp = intval($settings['ctr_fix_min_impressions']);
            $vis_imp = intval($settings['visible_min_impressions']);
            $vis_clicks = intval($settings['visible_max_clicks']);
            $filter_sql = " AND ("
                        . "m.impressions <= {$ghost}"                                                          // Ghost
                        . " OR (m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions >= {$near_imp})" // Near Wins
                        . " OR (m.avg_position > 20 AND m.impressions >= {$buried_imp})"                       // Buried
                        . " OR (m.impressions >= {$vis_imp} AND m.clicks <= {$vis_clicks})"                    // Visible but Ignored
                        // CTR Fix — position-aware
                        . " OR (m.impressions >= {$ctr_imp} AND ("
                        .   "(m.avg_position <= 1 AND m.ctr < 15)"
                        .   " OR (m.avg_position > 1 AND m.avg_position <= 2 AND m.ctr < 8.5)"
                        .   " OR (m.avg_position > 2 AND m.avg_position <= 3 AND m.ctr < 5.5)"
                        .   " OR (m.avg_position > 3 AND m.avg_position <= 5 AND m.ctr < 3.5)"
                        .   " OR (m.avg_position > 5 AND m.avg_position <= 10 AND m.ctr < 1.5)"
                        .   " OR (m.avg_position > 10 AND m.avg_position <= 15 AND m.ctr < 0.5)"
                        . "))"
                        . ")";
            break;
        case 'limited':
            // Limited Visibility: has some impressions but very few (<100), OR position is 30+
            $filter_sql = ' AND m.impressions > 0 AND (m.impressions < 100 OR m.avg_position >= 30)';
            break;
        case 'top_performers':
            // Top Performers: CTR meets or exceeds 60% of expected for position + meaningful traffic
            $filter_sql = " AND m.clicks >= 3 AND m.impressions >= 20 AND ("
                        . "(m.avg_position <= 1 AND m.ctr >= 18)"       // Pos 1: 60% of 30%
                        . " OR (m.avg_position > 1 AND m.avg_position <= 2 AND m.ctr >= 10.2)"  // Pos 2: 60% of 17%
                        . " OR (m.avg_position > 2 AND m.avg_position <= 3 AND m.ctr >= 6.6)"   // Pos 3: 60% of 11%
                        . " OR (m.avg_position > 3 AND m.avg_position <= 5 AND m.ctr >= 4.2)"   // Pos 4-5: 60% of 7%
                        . " OR (m.avg_position > 5 AND m.avg_position <= 10 AND m.ctr >= 1.8)"  // Pos 6-10: 60% of 3%
                        . " OR (m.avg_position > 10 AND m.avg_position <= 20 AND m.ctr >= 0.6)" // Page 2: 60% of 1%
                        . ")";
            break;
        case 'stars':
            // Stars: High-traffic pages carrying the site — 15+ clicks and 200+ impressions
            $filter_sql = ' AND m.clicks >= 15 AND m.impressions >= 200';
            break;
    }

    // For the temp table (live GSC), no need for the MAX(date_collected) subquery — one row per post
    $latest_join = $use_live_gsc
        ? ""
        : "INNER JOIN (
            SELECT post_id, MAX(date_collected) as max_date
            FROM {$metrics_table}
            GROUP BY post_id
        ) latest ON m.post_id = latest.post_id AND m.date_collected = latest.max_date";

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT m.*, p.post_title, p.post_type, p.post_date,
               lr_meta.meta_value as last_refresh
        FROM {$metrics_table} m
        {$latest_join}
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        LEFT JOIN {$wpdb->postmeta} lr_meta ON p.ID = lr_meta.post_id AND lr_meta.meta_key = 'last_page_refresh'
        WHERE {$type_sql} AND p.post_status = 'publish'
        $filter_sql
        ORDER BY $sort_col $order
        LIMIT %d OFFSET %d
    ", $per_page, $offset));

    $total = (int) $wpdb->get_var("
        SELECT COUNT(DISTINCT m.post_id)
        FROM {$metrics_table} m
        {$latest_join}
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE {$type_sql} AND p.post_status = 'publish'
        $filter_sql
    ");

    $ghost_threshold = intval($settings['ghost_threshold']);
    // Summary stats — filtered to match the current view
    $summary = $wpdb->get_row("
        SELECT
            COUNT(DISTINCT m.post_id) as total_pages,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
            SUM(CASE WHEN m.impressions <= {$ghost_threshold} AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(CASE WHEN m.impressions <= {$ghost_threshold} AND p.post_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as new_pages
        FROM {$metrics_table} m
        {$latest_join}
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE {$type_sql} AND p.post_status = 'publish'
        $filter_sql
    ");

    // Check which posts are already in queue
    $queued_ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->prefix}seom_refresh_queue WHERE status IN ('pending', 'processing')");
    $queued_map = array_flip($queued_ids);

    // Get prior period metrics for comparison (previous collection date)
    $prior_map = [];
    if (!empty($rows) && !$use_live_gsc) {
        $row_ids = array_map(function($r) { return (int) $r->post_id; }, $rows);
        $id_list = implode(',', $row_ids);

        // Find the two most recent collection dates
        $recent_dates = $wpdb->get_col("SELECT DISTINCT date_collected FROM {$wpdb->prefix}seom_page_metrics ORDER BY date_collected DESC LIMIT 2");
        if (count($recent_dates) >= 2) {
            $prev_date = $recent_dates[1];
            $prior_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, clicks, impressions, ctr, avg_position
                 FROM {$wpdb->prefix}seom_page_metrics
                 WHERE date_collected = %s AND post_id IN ({$id_list})",
                $prev_date
            ), OBJECT_K);
            $prior_map = $prior_rows;
        }
    }

    // Get last refresh info for each post (most recent history entry — single source of truth)
    $refresh_map = [];
    if (!empty($rows)) {
        $row_ids = array_map(function($r) { return (int) $r->post_id; }, $rows);
        $id_list = implode(',', $row_ids);
        $refresh_rows = $wpdb->get_results(
            "SELECT h.post_id, h.category, h.refresh_type, h.refresh_date
             FROM {$wpdb->prefix}seom_refresh_history h
             INNER JOIN (
                 SELECT post_id, MAX(id) as max_id
                 FROM {$wpdb->prefix}seom_refresh_history
                 WHERE post_id IN ({$id_list})
                 GROUP BY post_id
             ) latest ON h.id = latest.max_id"
        );
        foreach ($refresh_rows as $rr) {
            $refresh_map[$rr->post_id] = [
                'category'     => $rr->category,
                'refresh_type' => $rr->refresh_type,
                'refresh_date' => $rr->refresh_date,
            ];
        }
    }

    $fourteen_days_ago = date('Y-m-d', strtotime('-14 days'));

    // Enrich each row with prior metrics and refresh history
    foreach ($rows as &$r) {
        $r->in_queue = isset($queued_map[$r->post_id]);

        // Prior period data
        $prior = $prior_map[$r->post_id] ?? null;
        $r->clicks_prior = $prior ? (int) $prior->clicks : null;
        $r->impressions_prior = $prior ? (int) $prior->impressions : null;
        $r->position_prior = $prior ? (float) $prior->avg_position : null;

        // Last refresh — use history table as single source of truth
        $hist = $refresh_map[$r->post_id] ?? null;
        if ($hist) {
            $r->was_category = $hist['category'];
            // Use history date, but also check post meta in case it's more recent
            // (e.g., admin bar refresh sets meta but uses category 'M')
            $meta_date = $r->last_refresh ?? null;
            $hist_date = $hist['refresh_date'] ?? null;
            $r->last_refresh = ($meta_date && $meta_date > $hist_date) ? $meta_date : $hist_date;
        } else {
            // No refresh history — check if it was refreshed via other means (Blog Queue, admin bar)
            if (!empty($r->last_refresh)) {
                $r->was_category = 'M'; // Manual/external refresh
            } elseif ($r->post_date >= $fourteen_days_ago) {
                $r->was_category = 'NEW';
            } else {
                $r->was_category = 'NEVER';
            }
        }
    }

    wp_send_json_success([
        'rows'         => $rows,
        'total'        => $total,
        'page'         => $page,
        'summary'      => $summary,
        'date_warning' => $date_warning ?: null,
        'date_range'   => $date_range,
    ]);
});

// Manual add to queue
add_action('wp_ajax_seom_add_to_queue', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $post_id = intval($_POST['post_id'] ?? 0);
    $refresh_type = sanitize_text_field($_POST['refresh_type'] ?? 'full');
    $priority = floatval($_POST['priority'] ?? 99);

    if (!$post_id) wp_send_json_error('Missing post ID.');

    // Check if already in queue
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}seom_refresh_queue WHERE post_id = %d AND status IN ('pending', 'processing')",
        $post_id
    ));
    if ($existing) wp_send_json_error('Already in queue.');

    $wpdb->insert("{$wpdb->prefix}seom_refresh_queue", [
        'post_id'        => $post_id,
        'post_type'      => get_post_type($post_id),
        'priority_score' => $priority,
        'category'       => 'M',
        'refresh_type'   => $refresh_type,
        'status'         => 'pending',
        'queued_at'      => current_time('mysql'),
    ]);

    wp_send_json_success(['queued' => true]);
});

add_action('wp_ajax_seom_get_queue', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $rows = $wpdb->get_results("
        SELECT q.*, p.post_title, p.post_type,
            m.clicks, m.impressions, m.ctr, m.avg_position, m.top_queries
        FROM {$wpdb->prefix}seom_refresh_queue q
        JOIN {$wpdb->posts} p ON q.post_id = p.ID
        LEFT JOIN (
            SELECT sm.* FROM {$wpdb->prefix}seom_page_metrics sm
            INNER JOIN (
                SELECT post_id, MAX(date_collected) as max_date
                FROM {$wpdb->prefix}seom_page_metrics GROUP BY post_id
            ) lat ON sm.post_id = lat.post_id AND sm.date_collected = lat.max_date
        ) m ON q.post_id = m.post_id
        WHERE q.status IN ('pending', 'processing')
        ORDER BY q.priority_score DESC
        LIMIT 100
    ");

    foreach ($rows as &$row) {
        $row->url = get_permalink($row->post_id);
        $row->last_refresh = get_post_meta($row->post_id, 'last_page_refresh', true) ?: null;
        $row->has_description = !empty(trim(strip_tags(get_post_field('post_content', $row->post_id))));
        $row->has_excerpt = !empty(trim(get_post_field('post_excerpt', $row->post_id)));
    }

    wp_send_json_success($rows);
});

add_action('wp_ajax_seom_get_history', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $page = max(1, intval($_POST['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT h.*, p.post_title
        FROM {$wpdb->prefix}seom_refresh_history h
        JOIN {$wpdb->posts} p ON h.post_id = p.ID
        ORDER BY h.refresh_date DESC
        LIMIT %d OFFSET %d
    ", $limit, $offset));

    foreach ($rows as &$row) {
        $row->url = get_permalink($row->post_id);
    }

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history");

    wp_send_json_success(['rows' => $rows, 'total' => $total, 'page' => $page]);
});

add_action('wp_ajax_seom_get_overview', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;

    // Use a faster count — count from the latest date only instead of DISTINCT across all dates
    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$wpdb->prefix}seom_page_metrics");
    $monitored = $latest_date ? (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}seom_page_metrics WHERE date_collected = %s", $latest_date
    )) : 0;
    $in_queue = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_queue WHERE status = 'pending'");
    $refreshed_month = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history WHERE refresh_date >= %s",
        date('Y-m-01 00:00:00')
    ));
    $today_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history WHERE DATE(refresh_date) = CURDATE()");

    // Category breakdown in queue
    $categories = $wpdb->get_results("
        SELECT category, COUNT(*) as cnt
        FROM {$wpdb->prefix}seom_refresh_queue
        WHERE status = 'pending'
        GROUP BY category
    ");

    // Recent improvements (history with after data — clicks went up)
    $improvements = $wpdb->get_results("
        SELECT h.*, p.post_title,
            (h.clicks_after_30d - h.clicks_before) as click_change,
            (h.position_after_30d - h.position_before) as position_change
        FROM {$wpdb->prefix}seom_refresh_history h
        JOIN {$wpdb->posts} p ON h.post_id = p.ID
        WHERE h.clicks_after_30d IS NOT NULL
        AND h.clicks_after_30d > h.clicks_before
        ORDER BY (h.clicks_after_30d - h.clicks_before) DESC
        LIMIT 10
    ");

    // Recent declines (clicks went down after refresh)
    $declines = $wpdb->get_results("
        SELECT h.*, p.post_title,
            (h.clicks_after_30d - h.clicks_before) as click_change,
            (h.position_after_30d - h.position_before) as position_change
        FROM {$wpdb->prefix}seom_refresh_history h
        JOIN {$wpdb->posts} p ON h.post_id = p.ID
        WHERE h.clicks_after_30d IS NOT NULL
        AND h.clicks_after_30d < h.clicks_before
        ORDER BY (h.clicks_after_30d - h.clicks_before) ASC
        LIMIT 10
    ");

    // Recent refreshes — compare current metrics vs before metrics for pages refreshed in last 14 days
    // Use the latest collection date for a fast join instead of per-row MAX subquery
    $recent_changes = [];
    if ($latest_date) {
        $recent_changes = $wpdb->get_results($wpdb->prepare("
            SELECT h.post_id, h.refresh_date, h.refresh_type, h.category,
                h.clicks_before, h.impressions_before, h.position_before, h.ctr_before,
                m.clicks as clicks_now, m.impressions as impressions_now,
                m.avg_position as position_now, m.ctr as ctr_now,
                p.post_title, p.post_type,
                (m.clicks - h.clicks_before) as click_change,
                (m.avg_position - h.position_before) as position_change,
                DATEDIFF(NOW(), h.refresh_date) as days_since
            FROM {$wpdb->prefix}seom_refresh_history h
            JOIN {$wpdb->posts} p ON h.post_id = p.ID
            LEFT JOIN {$wpdb->prefix}seom_page_metrics m
                ON h.post_id = m.post_id AND m.date_collected = %s
            WHERE h.refresh_date >= DATE_SUB(NOW(), INTERVAL 14 DAY)
            AND h.clicks_before IS NOT NULL
            ORDER BY (m.clicks - h.clicks_before) DESC, h.refresh_date DESC
            LIMIT 30
        ", $latest_date));
    }

    $settings = seom_get_settings();
    $last_collect = get_option('seom_last_collect', 'Never');
    $last_analyze = get_option('seom_last_analyze', 'Never');

    wp_send_json_success([
        'monitored'       => $monitored,
        'in_queue'        => $in_queue,
        'refreshed_month' => $refreshed_month,
        'today_count'     => $today_count,
        'daily_limit'     => $settings['daily_limit'],
        'categories'      => $categories,
        'improvements'    => $improvements,
        'declines'        => $declines,
        'recent_changes'  => $recent_changes,
        'last_collect'    => $last_collect,
        'last_analyze'    => $last_analyze,
        'enabled'         => $settings['enabled'],
        'dry_run'         => $settings['dry_run'],
    ]);
});

// Paginated tracker data — separate from overview for performance
add_action('wp_ajax_seom_get_tracker', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = min(9999, max(1, intval($_POST['per_page'] ?? 25)));
    $offset = ($page - 1) * $per_page;
    $days = intval($_POST['days'] ?? 14);
    $post_type = sanitize_text_field($_POST['post_type'] ?? 'all');

    $type_sql = '';
    if ($post_type !== 'all') {
        $type_sql = $wpdb->prepare(" AND p.post_type = %s", $post_type);
    }

    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$wpdb->prefix}seom_page_metrics");

    $total = $latest_date ? (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history h
        JOIN {$wpdb->posts} p ON h.post_id = p.ID
        WHERE h.refresh_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
        AND h.clicks_before IS NOT NULL
        $type_sql
    ", $days)) : 0;

    $rows = [];
    if ($latest_date) {
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT h.post_id, h.refresh_date, h.refresh_type, h.category,
                h.clicks_before, h.impressions_before, h.position_before, h.ctr_before,
                m.clicks as clicks_now, m.impressions as impressions_now,
                m.avg_position as position_now, m.ctr as ctr_now,
                p.post_title, p.post_type,
                (COALESCE(m.clicks,0) - h.clicks_before) as click_change,
                (COALESCE(m.avg_position,0) - h.position_before) as position_change,
                DATEDIFF(NOW(), h.refresh_date) as days_since
            FROM {$wpdb->prefix}seom_refresh_history h
            JOIN {$wpdb->posts} p ON h.post_id = p.ID
            LEFT JOIN {$wpdb->prefix}seom_page_metrics m
                ON h.post_id = m.post_id AND m.date_collected = %s
            WHERE h.refresh_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
            AND h.clicks_before IS NOT NULL
            $type_sql
            ORDER BY h.refresh_date DESC
            LIMIT %d OFFSET %d
        ", $latest_date, $days, $per_page, $offset));
    }

    // Summary counts + click trend
    $summary = ['improving' => 0, 'declining' => 0, 'flat' => 0, 'total' => $total, 'total_clicks_before' => 0, 'total_clicks_now' => 0, 'total_impressions_before' => 0, 'total_impressions_now' => 0];
    if ($latest_date) {
        $all_changes = $wpdb->get_results($wpdb->prepare("
            SELECT (COALESCE(m.clicks,0) - h.clicks_before) as click_change,
                   (COALESCE(m.avg_position,0) - h.position_before) as position_change,
                   DATEDIFF(NOW(), h.refresh_date) as days_since,
                   h.clicks_before, COALESCE(m.clicks,0) as clicks_now,
                   h.impressions_before, COALESCE(m.impressions,0) as impressions_now
            FROM {$wpdb->prefix}seom_refresh_history h
            JOIN {$wpdb->posts} p ON h.post_id = p.ID
            LEFT JOIN {$wpdb->prefix}seom_page_metrics m
                ON h.post_id = m.post_id AND m.date_collected = %s
            WHERE h.refresh_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
            AND h.clicks_before IS NOT NULL
            $type_sql
        ", $latest_date, $days));

        foreach ($all_changes as $c) {
            $cd = intval($c->click_change);
            $pd = floatval($c->position_change);
            $summary['total_clicks_before'] += intval($c->clicks_before);
            $summary['total_clicks_now'] += intval($c->clicks_now);
            $summary['total_impressions_before'] += intval($c->impressions_before);
            $summary['total_impressions_now'] += intval($c->impressions_now);
            if ($cd > 0 || ($cd >= 0 && $pd < -0.3)) $summary['improving']++;
            elseif ($cd < 0) $summary['declining']++;
            else $summary['flat']++;
        }
    }

    wp_send_json_success([
        'rows'    => $rows,
        'total'   => $total,
        'page'    => $page,
        'pages'   => ceil($total / $per_page),
        'summary' => $summary,
    ]);
});

// ─── Keyword Research AJAX ───────────────────────────────────────────────────

add_action('wp_ajax_seom_collect_keywords', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(180);

    $batch_page = intval($_POST['batch_page'] ?? 0);
    $result = SEOM_Keyword_Researcher::collect($batch_page);
    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
    wp_send_json_success($result);
});

add_action('wp_ajax_seom_expand_keywords', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(300);

    $limit = intval($_POST['limit'] ?? 50);
    $result = SEOM_Keyword_Researcher::expand_with_autocomplete($limit);
    wp_send_json_success($result);
});

// ─── Keyword Gaps AJAX ──────────────────────────────────────────────────────

// Bulk upsert helper for gap keywords — uses INSERT ON DUPLICATE KEY UPDATE
function seom_bulk_upsert_gaps($wpdb, $table, $rows, $source, $today) {
    if (empty($rows)) return 0;

    $values = [];
    foreach ($rows as $r) {
        $values[] = $wpdb->prepare(
            "(%s, %d, %d, %f, %s, %d, %d, %d, %s, %s)",
            $r['keyword'], $r['volume'], $r['difficulty'], $r['cpc'],
            $r['intent'], $r['your_pos'], $r['comp1_pos'], $r['comp2_pos'],
            $source, $today
        );
    }

    $sql = "INSERT INTO {$table}
        (keyword, search_volume, keyword_difficulty, cpc, intent, your_position, competitor_1_position, competitor_2_position, source, date_imported)
        VALUES " . implode(',', $values) . "
        ON DUPLICATE KEY UPDATE
            search_volume = VALUES(search_volume),
            keyword_difficulty = VALUES(keyword_difficulty),
            cpc = VALUES(cpc),
            intent = VALUES(intent),
            your_position = VALUES(your_position),
            competitor_1_position = VALUES(competitor_1_position),
            competitor_2_position = VALUES(competitor_2_position),
            date_imported = VALUES(date_imported)";

    $wpdb->query($sql);
    return count($rows);
}

// Import CSV — batched: phase 0 = parse & cache, phase 1+ = upsert batches, final = restore usage
add_action('wp_ajax_seom_import_keyword_gaps', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(120);

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    $phase = intval($_POST['phase'] ?? 0);
    $source = sanitize_text_field($_POST['source'] ?? 'semrush');
    $today = date('Y-m-d');

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        seom_create_tables();
    }

    // Phase 0: Parse CSV, cache parsed rows
    if ($phase === 0) {
        $csv_data = wp_unslash($_POST['csv_data'] ?? '');
        if (empty($csv_data)) wp_send_json_error('No CSV data provided.');

        $lines = preg_split('/\r?\n/', $csv_data);
        $header = str_getcsv(array_shift($lines));
        $header = array_map(function($h) { return strtolower(trim($h, "\xEF\xBB\xBF \t")); }, $header);

        $col_map = [];
        foreach ($header as $i => $h) {
            if ($h === 'keyword') $col_map['keyword'] = $i;
            elseif ($h === 'volume' || $h === 'search volume') $col_map['volume'] = $i;
            elseif ($h === 'keyword difficulty' || $h === 'kd' || $h === 'difficulty') $col_map['difficulty'] = $i;
            elseif ($h === 'cpc') $col_map['cpc'] = $i;
            elseif ($h === 'intents' || $h === 'intent') $col_map['intent'] = $i;
            elseif (strpos($h, 'ituonline') !== false && strpos($h, 'pages') === false) $col_map['your_pos'] = $i;
            elseif (strpos($h, 'visiontraining') !== false && strpos($h, 'pages') === false) $col_map['your_pos'] = $i;
            elseif (!isset($col_map['comp1']) && strpos($h, '(pages)') === false && strpos($h, '.com') !== false) $col_map['comp1'] = $i;
            elseif (!isset($col_map['comp2']) && strpos($h, '(pages)') === false && strpos($h, '.com') !== false) $col_map['comp2'] = $i;
        }
        if (!isset($col_map['keyword'])) wp_send_json_error('CSV must have a "Keyword" column.');

        $parsed = [];
        $skipped = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $cols = str_getcsv($line);
            $keyword = sanitize_text_field(trim($cols[$col_map['keyword']] ?? ''));
            if (empty($keyword)) continue;

            $non_latin = preg_match_all('/[\p{Han}\p{Hangul}\p{Katakana}\p{Hiragana}\p{Cyrillic}\p{Arabic}\p{Thai}\p{Devanagari}]/u', $keyword);
            if ($non_latin > 0 && $non_latin >= mb_strlen($keyword) * 0.3) { $skipped++; continue; }

            $parsed[] = [
                'keyword'    => mb_substr($keyword, 0, 255),
                'volume'     => intval($cols[$col_map['volume'] ?? -1] ?? 0),
                'difficulty' => intval($cols[$col_map['difficulty'] ?? -1] ?? 0),
                'cpc'        => floatval($cols[$col_map['cpc'] ?? -1] ?? 0),
                'intent'     => mb_substr(sanitize_text_field($cols[$col_map['intent'] ?? -1] ?? ''), 0, 50),
                'your_pos'   => intval($cols[$col_map['your_pos'] ?? -1] ?? 0),
                'comp1_pos'  => intval($cols[$col_map['comp1'] ?? -1] ?? 0),
                'comp2_pos'  => intval($cols[$col_map['comp2'] ?? -1] ?? 0),
            ];
        }

        // Cache parsed rows — use a WP option since transients may not support large data
        update_option('seom_gap_import_cache', $parsed, false);
        update_option('seom_gap_import_meta', ['skipped' => $skipped, 'source' => $source, 'total' => count($parsed)], false);

        wp_send_json_success([
            'phase'   => 'parsed',
            'total'   => count($parsed),
            'skipped' => $skipped,
            'batches' => ceil(count($parsed) / 500),
        ]);
    }

    // Phase 1+: Process batch of cached rows
    if ($phase >= 1) {
        $batch_size = 500;
        $cached = get_option('seom_gap_import_cache', []);
        $meta = get_option('seom_gap_import_meta', []);
        if (empty($cached)) wp_send_json_error('Import cache expired. Start over.');

        $offset = ($phase - 1) * $batch_size;
        $batch_rows = array_slice($cached, $offset, $batch_size);
        $is_last = ($offset + count($batch_rows)) >= count($cached);

        // Bulk upsert this batch
        $imported = 0;
        $chunk_size = 50;
        for ($i = 0; $i < count($batch_rows); $i += $chunk_size) {
            $chunk = array_slice($batch_rows, $i, $chunk_size);
            $imported += seom_bulk_upsert_gaps($wpdb, $table, $chunk, $meta['source'] ?? $source, $today);
        }

        if (!$is_last) {
            wp_send_json_success([
                'phase'     => 'importing',
                'batch'     => $phase,
                'imported'  => $imported,
                'processed' => min($offset + count($batch_rows), count($cached)),
                'total'     => count($cached),
            ]);
        }

        // Last batch — restore usage and clean up
        $restored = 0;
        $usage_table = $wpdb->prefix . 'seom_keyword_usage';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") === $usage_table) {
            $restored += (int) $wpdb->query("
                UPDATE {$table} g
                INNER JOIN {$usage_table} u ON LOWER(g.keyword) = LOWER(u.keyword)
                SET g.last_used_at = u.used_at, g.used_in_post_id = u.post_id
                WHERE g.last_used_at IS NULL AND u.post_id IS NOT NULL
            ");
            $restored += (int) $wpdb->query("
                UPDATE {$table} g
                INNER JOIN {$wpdb->postmeta} pm ON LOWER(g.keyword) = LOWER(pm.meta_value)
                    AND pm.meta_key = 'rank_math_focus_keyword'
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_status = 'publish'
                SET g.last_used_at = DATE(p.post_modified), g.used_in_post_id = p.ID
                WHERE g.last_used_at IS NULL
            ");
        }

        // Clean up cache
        delete_option('seom_gap_import_cache');
        delete_option('seom_gap_import_meta');

        wp_send_json_success([
            'phase'    => 'complete',
            'imported' => count($cached),
            'skipped'  => $meta['skipped'] ?? 0,
            'restored' => $restored,
        ]);
    }
});

// Get keyword gaps with filtering, sorting, pagination
add_action('wp_ajax_seom_get_keyword_gaps', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        wp_send_json_success(['rows' => [], 'total' => 0, 'tags' => [], 'page' => 1, 'pages' => 0]);
    }

    $page     = max(1, intval($_POST['page'] ?? 1));
    $per_page = 50;
    $offset   = ($page - 1) * $per_page;
    $tag      = sanitize_text_field($_POST['tag'] ?? 'all');
    $search   = sanitize_text_field($_POST['search'] ?? '');
    $sort     = sanitize_text_field($_POST['sort'] ?? 'search_volume');
    $order    = strtoupper(sanitize_text_field($_POST['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $filter   = sanitize_text_field($_POST['filter'] ?? 'all');

    $allowed_sorts = ['keyword', 'search_volume', 'keyword_difficulty', 'cpc', 'your_position', 'competitor_1_position', 'date_imported', 'tag'];
    if (!in_array($sort, $allowed_sorts)) $sort = 'search_volume';

    $where = '1=1';
    if ($tag !== 'all') {
        if ($tag === 'untagged') {
            $where .= " AND (tag IS NULL OR tag = '')";
        } else {
            $where .= $wpdb->prepare(" AND tag = %s", $tag);
        }
    }
    if (!empty($search)) {
        $where .= $wpdb->prepare(" AND keyword LIKE %s", '%' . $wpdb->esc_like($search) . '%');
    }
    if ($filter === 'high_volume') $where .= ' AND search_volume >= 1000';
    if ($filter === 'low_difficulty') $where .= ' AND keyword_difficulty <= 30';
    if ($filter === 'quick_wins') $where .= ' AND search_volume >= 500 AND keyword_difficulty <= 40';
    if ($filter === 'not_ranking') $where .= ' AND your_position = 0';
    if ($filter === 'on_cooldown') $where .= ' AND last_used_at IS NOT NULL AND last_used_at > DATE_SUB(CURDATE(), INTERVAL 90 DAY)';
    if ($filter === 'available') $where .= ' AND (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL 90 DAY))';

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");

    $rows = $wpdb->get_results("
        SELECT * FROM {$table}
        WHERE {$where}
        ORDER BY {$sort} {$order}
        LIMIT {$per_page} OFFSET {$offset}
    ");

    // Enrich rows with linked post info
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    $has_usage = $wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") === $usage_table;
    foreach ($rows as &$r) {
        $r->linked_post = null;

        // First check the gap table's own used_in_post_id
        $pid = intval($r->used_in_post_id ?? 0);

        // If not there, check the unified usage table
        if (!$pid && $has_usage) {
            $pid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$usage_table} WHERE keyword = %s AND post_id IS NOT NULL ORDER BY used_at DESC LIMIT 1",
                strtolower($r->keyword)
            ));
        }

        if ($pid && get_post_status($pid)) {
            $r->linked_post = [
                'id'    => $pid,
                'title' => get_the_title($pid),
                'type'  => get_post_type($pid),
                'url'   => get_permalink($pid),
                'edit'  => admin_url('post.php?action=edit&post=' . $pid),
            ];
        }
    }
    unset($r);

    // Get all unique tags for the filter dropdown
    $tags = $wpdb->get_col("SELECT DISTINCT tag FROM {$table} WHERE tag IS NOT NULL AND tag != '' ORDER BY tag ASC");

    // Summary stats
    $summary = $wpdb->get_row("
        SELECT COUNT(*) as total_keywords,
            SUM(CASE WHEN tag IS NULL OR tag = '' THEN 1 ELSE 0 END) as untagged,
            COUNT(DISTINCT tag) as tag_count,
            AVG(search_volume) as avg_volume
        FROM {$table}
    ");

    wp_send_json_success([
        'rows'    => $rows,
        'total'   => $total,
        'page'    => $page,
        'pages'   => ceil($total / $per_page),
        'tags'    => $tags,
        'summary' => $summary,
    ]);
});

// AI Auto-tag untagged keywords
add_action('wp_ajax_seom_autotag_gaps', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(120);
    ob_start();

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    $batch = min(40, intval($_POST['batch'] ?? 40));

    $settings = seom_get_settings();

    // Step 1: Auto-merge obvious duplicates (singular/plural, trailing 's', minor variations)
    $all_tags = $wpdb->get_results("SELECT tag, COUNT(*) as cnt FROM {$table} WHERE tag IS NOT NULL AND tag != '' GROUP BY tag ORDER BY cnt DESC");
    $tag_names = [];
    foreach ($all_tags as $t) $tag_names[$t->tag] = $t->cnt;

    // Build normalized map — lowercase, stripped trailing 's', stripped common suffixes
    $normalized = [];
    foreach ($tag_names as $tag => $cnt) {
        $norm = strtolower(trim($tag));
        $norm = preg_replace('/\s+/', ' ', $norm);
        // Normalize trailing 's' for plural detection
        $norm_singular = rtrim($norm, 's');
        if (!isset($normalized[$norm_singular])) {
            $normalized[$norm_singular] = $tag; // Keep the first (highest count) version
        } else {
            // Merge this tag into the canonical version
            $canonical = $normalized[$norm_singular];
            if ($tag !== $canonical) {
                $wpdb->query($wpdb->prepare("UPDATE {$table} SET tag = %s WHERE tag = %s", $canonical, $tag));
            }
        }
    }

    // Step 2: Build the category list for AI — existing tags + seed categories
    $existing_tags = $wpdb->get_col("SELECT DISTINCT tag FROM {$table} WHERE tag IS NOT NULL AND tag != '' ORDER BY tag ASC");
    $seed_cats = array_filter(array_map('trim', explode("\n", $settings['gap_seed_categories'] ?? '')));

    // Merge seed + existing, dedup
    $master_list = array_unique(array_merge($seed_cats, $existing_tags));
    sort($master_list);

    // Step 3: Get untagged keywords
    $keywords = $wpdb->get_results("
        SELECT id, keyword, search_volume, intent FROM {$table}
        WHERE tag IS NULL OR tag = ''
        ORDER BY search_volume DESC
        LIMIT {$batch}
    ");

    if (empty($keywords)) {
        ob_end_clean();
        wp_send_json_success(['tagged' => 0, 'remaining' => 0, 'message' => 'All keywords are already tagged.']);
    }

    $kw_list = [];
    foreach ($keywords as $kw) {
        $kw_list[] = $kw->keyword;
    }

    // Step 4: Build strict prompt
    $tag_context = !empty($master_list)
        ? "\n\nMASTER CATEGORY LIST — You MUST assign each keyword to one of these categories:\n" . implode("\n", $master_list)
        : '';

    $instruction = "You are an IT training content strategist. Categorize each keyword into ONE topical category.\n\n"
        . "CRITICAL RULES:\n"
        . "- Return a JSON object mapping each keyword to its category\n"
        . "- You MUST use categories from the MASTER CATEGORY LIST below whenever possible\n"
        . "- ONLY create a new category if a keyword truly does not fit ANY existing category\n"
        . "- If you create a new category, use Title Case, keep it broad (should fit 5+ keywords), and DO NOT duplicate or create variations of existing categories\n"
        . "- NEVER create singular/plural variations (e.g., if 'AI Courses' exists, do NOT create 'AI Course')\n"
        . "- NEVER create variations with minor word differences (e.g., if 'AWS Certifications' exists, do NOT create 'AWS Certification' or 'AWS Cert')\n"
        . "- When in doubt, use the closest existing category rather than creating a new one\n"
        . "- Avoid overly generic categories like 'IT', 'Technology', 'General', 'Other', or 'Unknown'\n"
        . "- Return ONLY the JSON object, no explanation"
        . $tag_context;

    $prompt = "Categorize these keywords:\n" . implode("\n", $kw_list);

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) { ob_end_clean(); wp_send_json_error('API key not configured.'); }

    $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

    $body_payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $instruction],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.3,
    ];
    // Request JSON mode if model supports it
    if (strpos($model, 'gpt-4') !== false || strpos($model, 'gpt-3.5') !== false) {
        $body_payload['response_format'] = ['type' => 'json_object'];
    }

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($body_payload),
        'timeout' => 120,
    ]);

    if (is_wp_error($response)) { ob_end_clean(); wp_send_json_error('API error: ' . $response->get_error_message()); }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $raw = trim($data['choices'][0]['message']['content'] ?? '');

    // Strip code fences
    $raw = preg_replace('/^```[a-zA-Z]*\s*/m', '', $raw);
    $raw = preg_replace('/\s*```\s*$/m', '', $raw);
    $raw = trim($raw);

    // Attempt JSON repair — fix missing opening quotes on values
    // Pattern: "key": value" → "key": "value"
    $repaired = preg_replace('/:(\s*)([A-Z][^",}]+)"/', ':$1"$2"', $raw);
    if ($repaired !== $raw) {
        $raw = $repaired;
    }

    $mapping = json_decode($raw, true);

    // If still invalid, try line-by-line parsing as fallback
    if (!is_array($mapping)) {
        $mapping = [];
        // Try to parse "keyword": "category" pairs from raw text
        if (preg_match_all('/"([^"]+)"\s*:\s*"([^"]+)"/', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $mapping[$m[1]] = $m[2];
            }
        }
    }

    if (empty($mapping)) { ob_end_clean(); wp_send_json_error('AI returned unparseable response. Try again — smaller batches improve reliability.'); }

    $tagged = 0;
    foreach ($keywords as $kw) {
        $tag = $mapping[$kw->keyword] ?? $mapping[strtolower($kw->keyword)] ?? null;
        if ($tag) {
            $tag = sanitize_text_field(trim($tag));
            $wpdb->update($table, ['tag' => $tag], ['id' => $kw->id]);
            $tagged++;
        }
    }

    $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE tag IS NULL OR tag = ''");

    ob_end_clean();
    wp_send_json_success(['tagged' => $tagged, 'batch' => count($keywords), 'remaining' => $remaining]);
});

// Consolidate tags — AI merges 1000+ tags into ~30-40 categories
// Consolidate tags — multi-phase: 0=programmatic dedup, 1+=AI batches, apply+=DB updates
add_action('wp_ajax_seom_consolidate_gap_tags', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(120);
    ob_start();

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    $phase = sanitize_text_field($_POST['phase'] ?? 'dedup');

    // ── Phase: dedup — Aggressive programmatic merge (no AI needed) ──
    if ($phase === 'dedup') {
        $target_count = intval($_POST['target'] ?? 50);
        update_option('seom_consolidate_target', $target_count, false);

        $tags = $wpdb->get_results("SELECT tag, COUNT(*) as cnt FROM {$table} WHERE tag IS NOT NULL AND tag != '' GROUP BY tag ORDER BY cnt DESC");
        $old_count = count($tags);

        // Build canonical map: normalize → best tag name (highest keyword count wins)
        $canonical = []; // normalized_key => ['tag' => BestName, 'cnt' => count]
        foreach ($tags as $t) {
            $norm = strtolower(trim($t->tag));
            $norm = preg_replace('/\s+/', ' ', $norm);
            $norm = rtrim($norm, 's');                  // plural → singular
            $norm = preg_replace('/\s*&\s*/', ' and ', $norm); // & → and
            $norm = preg_replace('/[^a-z0-9 ]/', '', $norm);   // strip special chars

            if (!isset($canonical[$norm]) || $t->cnt > $canonical[$norm]['cnt']) {
                $canonical[$norm] = ['tag' => $t->tag, 'cnt' => $t->cnt];
            }
        }

        // Apply merges
        $deduped = 0;
        foreach ($tags as $t) {
            $norm = strtolower(trim($t->tag));
            $norm = preg_replace('/\s+/', ' ', $norm);
            $norm = rtrim($norm, 's');
            $norm = preg_replace('/\s*&\s*/', ' and ', $norm);
            $norm = preg_replace('/[^a-z0-9 ]/', '', $norm);

            $best = $canonical[$norm]['tag'] ?? $t->tag;
            if ($best !== $t->tag) {
                $wpdb->query($wpdb->prepare("UPDATE {$table} SET tag = %s WHERE tag = %s", $best, $t->tag));
                $deduped++;
            }
        }

        $new_count = (int) $wpdb->get_var("SELECT COUNT(DISTINCT tag) FROM {$table} WHERE tag IS NOT NULL AND tag != ''");

        // If already at target, done
        if ($new_count <= $target_count) {
            ob_end_clean();
            wp_send_json_success(['phase' => 'complete', 'deduped' => $deduped, 'old_count' => $old_count, 'new_count' => $new_count]);
        }

        // Cache remaining tags for AI phase
        $remaining_tags = $wpdb->get_results("SELECT tag, COUNT(*) as cnt FROM {$table} WHERE tag IS NOT NULL AND tag != '' GROUP BY tag ORDER BY cnt DESC");
        $tag_list = [];
        foreach ($remaining_tags as $t) $tag_list[] = ['tag' => $t->tag, 'cnt' => $t->cnt];
        update_option('seom_consolidate_tags', $tag_list, false);
        update_option('seom_consolidate_changes', [], false);

        ob_end_clean();
        wp_send_json_success([
            'phase'     => 'deduped',
            'deduped'   => $deduped,
            'old_count' => $old_count,
            'remaining' => count($remaining_tags),
            'ai_batches' => ceil(count($remaining_tags) / 80),
        ]);
    }

    // ── Phase: ai_batch — Send chunks of tags to AI for consolidation ──
    if ($phase === 'ai_batch') {
        $batch_num = intval($_POST['batch'] ?? 0);
        $target_count = intval(get_option('seom_consolidate_target', 50));
        $tag_list = get_option('seom_consolidate_tags', []);
        $all_changes = get_option('seom_consolidate_changes', []);

        if (empty($tag_list)) { ob_end_clean(); wp_send_json_error('Tag cache expired. Start over.'); }

        $batch_size = 80;
        $offset = $batch_num * $batch_size;
        $batch_tags = array_slice($tag_list, $offset, $batch_size);
        $is_last = ($offset + count($batch_tags)) >= count($tag_list);

        if (empty($batch_tags)) {
            // All AI batches done — save and move to apply phase
            update_option('seom_consolidate_changes', $all_changes, false);
            ob_end_clean();
            wp_send_json_success([
                'phase'   => 'ai_done',
                'changes' => count($all_changes),
                'apply_batches' => ceil(count($all_changes) / 20),
            ]);
        }

        // Build the batch prompt — include ALL known target categories so AI is consistent
        $target_cats = [];
        foreach ($all_changes as $c) $target_cats[] = $c['new'];
        // Also include tags from previous batches that were kept as-is
        $kept = $wpdb->get_col("SELECT DISTINCT tag FROM {$table} WHERE tag IS NOT NULL AND tag != '' ORDER BY tag ASC");
        $target_cats = array_unique(array_merge($target_cats, $kept));
        sort($target_cats);
        // Limit context to top 200 categories by frequency
        $target_cats = array_slice($target_cats, 0, 200);

        $batch_lines = [];
        foreach ($batch_tags as $bt) $batch_lines[] = $bt['tag'] . ' (' . $bt['cnt'] . ')';

        $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
        if (!$api_key) { ob_end_clean(); wp_send_json_error('API key not configured.'); }
        $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

        $instruction = "You are consolidating keyword categories. Map each tag below to a broader category.\n\n"
            . "RULES:\n"
            . "- Return JSON mapping each current tag to its consolidated name\n"
            . "- Target ~{$target_count} total categories across the whole dataset\n"
            . "- REUSE categories from the existing list below whenever possible\n"
            . "- If a tag is already good, map it to itself\n"
            . "- Merge variations: singular/plural, abbreviations, minor wording differences\n"
            . "- Use short Title Case names\n"
            . "- Return ONLY JSON, no explanation\n\n"
            . "EXISTING TARGET CATEGORIES (reuse these):\n" . implode(', ', array_slice($target_cats, 0, 100)) . "\n\n"
            . "TAGS TO MAP:\n" . implode("\n", $batch_lines);

        $body_payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $instruction],
                ['role' => 'user', 'content' => 'Map these ' . count($batch_tags) . ' tags. Return JSON.'],
            ],
            'temperature' => 0.2,
        ];
        if (strpos($model, 'gpt-4') !== false) {
            $body_payload['response_format'] = ['type' => 'json_object'];
        }

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
            'body' => json_encode($body_payload),
            'timeout' => 90,
        ]);

        if (is_wp_error($response)) { ob_end_clean(); wp_send_json_error('API error: ' . $response->get_error_message()); }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $raw = trim($data['choices'][0]['message']['content'] ?? '');
        $raw = preg_replace('/^```[a-zA-Z]*\s*/m', '', $raw);
        $raw = preg_replace('/\s*```\s*$/m', '', $raw);

        $repaired = preg_replace('/:(\s*)([A-Z][^",}]+)"/', ':$1"$2"', $raw);
        $mapping = json_decode($repaired, true);
        if (!is_array($mapping)) {
            $mapping = [];
            if (preg_match_all('/"([^"]+)"\s*:\s*"([^"]+)"/', $raw, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) $mapping[$m[1]] = $m[2];
            }
        }

        // Add non-identity changes to the cumulative list
        foreach ($mapping as $old => $new) {
            $old = trim($old);
            $new = sanitize_text_field(trim($new));
            if (!empty($new) && $old !== $new) {
                $all_changes[] = ['old' => $old, 'new' => $new];
            }
        }
        update_option('seom_consolidate_changes', $all_changes, false);

        if ($is_last) {
            ob_end_clean();
            wp_send_json_success([
                'phase'   => 'ai_done',
                'changes' => count($all_changes),
                'apply_batches' => ceil(count($all_changes) / 20),
            ]);
        }

        ob_end_clean();
        wp_send_json_success([
            'phase'     => 'ai_batch',
            'batch'     => $batch_num,
            'processed' => $offset + count($batch_tags),
            'total'     => count($tag_list),
            'changes_so_far' => count($all_changes),
        ]);
    }

    // ── Phase: apply — Apply cached changes in batches ──
    if ($phase === 'apply') {
        $batch_num = intval($_POST['batch'] ?? 0);
        $changes = get_option('seom_consolidate_changes', []);
        if (empty($changes)) { ob_end_clean(); wp_send_json_error('Changes cache expired.'); }

        $batch_size = 20;
        $offset = $batch_num * $batch_size;
        $batch = array_slice($changes, $offset, $batch_size);
        $is_last = ($offset + count($batch)) >= count($changes);

        $merged = 0;
        foreach ($batch as $change) {
            $affected = $wpdb->query($wpdb->prepare("UPDATE {$table} SET tag = %s WHERE tag = %s", $change['new'], $change['old']));
            if ($affected) $merged++;
        }

        if (!$is_last) {
            ob_end_clean();
            wp_send_json_success([
                'phase'     => 'applying',
                'batch'     => $batch_num,
                'processed' => $offset + count($batch),
                'total'     => count($changes),
            ]);
        }

        // Done — clean up
        delete_option('seom_consolidate_tags');
        delete_option('seom_consolidate_changes');
        delete_option('seom_consolidate_target');
        $new_count = (int) $wpdb->get_var("SELECT COUNT(DISTINCT tag) FROM {$table} WHERE tag IS NOT NULL AND tag != ''");

        ob_end_clean();
        wp_send_json_success([
            'phase'     => 'complete',
            'merged'    => count($changes),
            'new_count' => $new_count,
        ]);
    }

    ob_end_clean();
    wp_send_json_error('Unknown phase.');
});

// Clean up non-English keywords from gaps table
add_action('wp_ajax_seom_cleanup_gap_keywords', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    // Only delete keywords where 30%+ of characters are CJK, Cyrillic, Arabic, etc.
    // Fetch candidates and check in PHP for accurate Unicode handling
    $candidates = $wpdb->get_results("SELECT id, keyword FROM {$table}");
    $to_delete = [];
    foreach ($candidates as $c) {
        $non_latin = preg_match_all('/[\p{Han}\p{Hangul}\p{Katakana}\p{Hiragana}\p{Cyrillic}\p{Arabic}\p{Thai}\p{Devanagari}]/u', $c->keyword);
        $len = mb_strlen($c->keyword);
        if ($non_latin > 0 && $len > 0 && ($non_latin / $len) >= 0.3) {
            $to_delete[] = $c->id;
        }
    }
    $deleted = 0;
    if (!empty($to_delete)) {
        $placeholders = implode(',', array_fill(0, count($to_delete), '%d'));
        $deleted = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders)", ...$to_delete));
    }
    wp_send_json_success(['deleted' => $deleted]);
});

// Update tag for specific keywords (manual edit)
add_action('wp_ajax_seom_update_gap_tag', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    $ids   = array_map('intval', (array) ($_POST['ids'] ?? []));
    $tag   = sanitize_text_field($_POST['tag'] ?? '');

    if (empty($ids)) wp_send_json_error('No keywords selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET tag = %s WHERE id IN ($placeholders)",
        $tag, ...$ids
    ));

    wp_send_json_success(['updated' => count($ids)]);
});

// Delete keyword gaps
add_action('wp_ajax_seom_delete_keyword_gaps', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    $ids   = array_map('intval', (array) ($_POST['ids'] ?? []));

    if (empty($ids)) wp_send_json_error('No keywords selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders)", ...$ids));

    wp_send_json_success(['deleted' => count($ids)]);
});

add_action('wp_ajax_seom_get_keywords', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keywords';
    $filter = sanitize_text_field($_POST['filter'] ?? 'all');
    $sort = sanitize_text_field($_POST['sort'] ?? 'opportunity_score');
    $order = strtoupper(sanitize_text_field($_POST['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = 50;
    $offset = ($page - 1) * $per_page;

    $allowed_sorts = ['keyword', 'impressions', 'clicks', 'avg_position', 'ctr', 'trend_pct', 'opportunity_score'];
    if (!in_array($sort, $allowed_sorts)) $sort = 'opportunity_score';

    $has_status = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'status'");

    // Default: show active keywords. If no status column yet, fall back to latest date_collected.
    $filter_sql = '';
    $base_where = $has_status ? "k.status = 'active'" : "k.date_collected = (SELECT MAX(date_collected) FROM {$table})";

    switch ($filter) {
        case 'rising':    $filter_sql = " AND trend_direction = 'rising'"; break;
        case 'declining': $filter_sql = " AND trend_direction = 'declining'"; break;
        case 'gaps':      $filter_sql = " AND is_content_gap = 1"; break;
        case 'page2':     $filter_sql = " AND avg_position >= 11 AND avg_position <= 20"; break;
        case 'top':       $filter_sql = " AND avg_position > 0 AND avg_position <= 10"; break;
        case 'lost':      if ($has_status) { $base_where = "k.status = 'lost'"; } break;
    }

    $rows = $wpdb->get_results("
        SELECT k.*, p.post_title as mapped_title
        FROM {$table} k
        LEFT JOIN {$wpdb->posts} p ON k.mapped_post_id = p.ID
        WHERE {$base_where} {$filter_sql}
        ORDER BY {$sort} {$order}
        LIMIT {$per_page} OFFSET {$offset}
    ");

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} k WHERE {$base_where} {$filter_sql}");

    // Get autocomplete suggestions for displayed keywords
    $sug_table = $wpdb->prefix . 'seom_keyword_suggestions';
    foreach ($rows as &$r) {
        $sug = $wpdb->get_col($wpdb->prepare(
            "SELECT suggestion FROM {$sug_table} WHERE seed_keyword = %s LIMIT 5", $r->keyword
        ));
        $r->suggestions = $sug;
    }

    // Summary — all active keywords
    $active_where = $has_status ? "status = 'active'" : "date_collected = (SELECT MAX(date_collected) FROM {$table})";
    $lost_count = $has_status ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'lost'") : 0;

    $summary = $wpdb->get_row("
        SELECT
            COUNT(*) as total_keywords,
            SUM(CASE WHEN trend_direction = 'rising' THEN 1 ELSE 0 END) as rising,
            SUM(CASE WHEN trend_direction = 'declining' THEN 1 ELSE 0 END) as declining,
            SUM(CASE WHEN is_content_gap = 1 THEN 1 ELSE 0 END) as content_gaps,
            SUM(CASE WHEN avg_position >= 11 AND avg_position <= 20 THEN 1 ELSE 0 END) as page2_keywords
        FROM {$table} WHERE {$active_where}
    ");
    if ($summary) $summary->lost = $lost_count;

    wp_send_json_success([
        'rows'    => $rows,
        'total'   => $total,
        'page'    => $page,
        'pages'   => ceil($total / $per_page),
        'summary' => $summary,
        'last_collected' => get_option('seom_last_keyword_collect', 'Never'),
    ]);
});

// ─── Page Trends AJAX (all pages, not just refreshed) ────────────────────────

add_action('wp_ajax_seom_get_page_trends', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_page_metrics';
    $post_type = sanitize_text_field($_POST['post_type'] ?? 'all');
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;
    $filter = sanitize_text_field($_POST['filter'] ?? 'all');

    $type_sql = '';
    if ($post_type !== 'all') {
        $type_sql = $wpdb->prepare(" AND p.post_type = %s", $post_type);
    }

    // Get two most recent collection dates
    $dates = $wpdb->get_col("SELECT DISTINCT date_collected FROM {$table} ORDER BY date_collected DESC LIMIT 2");
    if (count($dates) < 2) {
        wp_send_json_success(['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 0,
            'summary' => ['total' => 0, 'improving' => 0, 'declining' => 0, 'flat' => 0, 'total_clicks_now' => 0, 'total_clicks_prev' => 0],
            'message' => 'Need at least 2 data collections to show trends.']);
        return;
    }

    $current_date = $dates[0];
    $prev_date = $dates[1];

    // Filter SQL for trend direction
    $having_sql = '';
    switch ($filter) {
        case 'improving': $having_sql = ' HAVING click_change > 0'; break;
        case 'declining': $having_sql = ' HAVING click_change < 0'; break;
        case 'new_traffic': $having_sql = ' HAVING prev_clicks = 0 AND cur_clicks > 0'; break;
        case 'lost_traffic': $having_sql = ' HAVING prev_clicks > 0 AND cur_clicks = 0'; break;
    }

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT
            cur.post_id, p.post_title, p.post_type, cur.url,
            cur.clicks as cur_clicks, cur.impressions as cur_impressions,
            cur.avg_position as cur_position, cur.ctr as cur_ctr,
            COALESCE(prev.clicks, 0) as prev_clicks, COALESCE(prev.impressions, 0) as prev_impressions,
            COALESCE(prev.avg_position, 0) as prev_position, COALESCE(prev.ctr, 0) as prev_ctr,
            (cur.clicks - COALESCE(prev.clicks, 0)) as click_change,
            (cur.avg_position - COALESCE(prev.avg_position, 0)) as position_change,
            (cur.impressions - COALESCE(prev.impressions, 0)) as impression_change
        FROM {$table} cur
        JOIN {$wpdb->posts} p ON cur.post_id = p.ID
        LEFT JOIN {$table} prev ON cur.post_id = prev.post_id AND prev.date_collected = %s
        WHERE cur.date_collected = %s AND p.post_status = 'publish'
        $type_sql
        $having_sql
        ORDER BY ABS(cur.clicks - COALESCE(prev.clicks, 0)) DESC
        LIMIT %d OFFSET %d
    ", $prev_date, $current_date, $per_page, $offset));

    // Total count
    $count_rows = $wpdb->get_results($wpdb->prepare("
        SELECT
            cur.post_id,
            cur.clicks as cur_clicks, COALESCE(prev.clicks, 0) as prev_clicks,
            (cur.clicks - COALESCE(prev.clicks, 0)) as click_change
        FROM {$table} cur
        JOIN {$wpdb->posts} p ON cur.post_id = p.ID
        LEFT JOIN {$table} prev ON cur.post_id = prev.post_id AND prev.date_collected = %s
        WHERE cur.date_collected = %s AND p.post_status = 'publish'
        $type_sql
        $having_sql
    ", $prev_date, $current_date));

    $total = count($count_rows);

    // Summary
    $summary = ['total' => $total, 'improving' => 0, 'declining' => 0, 'flat' => 0, 'total_clicks_now' => 0, 'total_clicks_prev' => 0];
    foreach ($count_rows as $cr) {
        $cd = intval($cr->click_change);
        $summary['total_clicks_now'] += intval($cr->cur_clicks);
        $summary['total_clicks_prev'] += intval($cr->prev_clicks);
        if ($cd > 0) $summary['improving']++;
        elseif ($cd < 0) $summary['declining']++;
        else $summary['flat']++;
    }

    wp_send_json_success([
        'rows'         => $rows,
        'total'        => $total,
        'page'         => $page,
        'pages'        => ceil($total / $per_page),
        'summary'      => $summary,
        'current_date' => $current_date,
        'prev_date'    => $prev_date,
    ]);
});

// ─── Goals AJAX ─────────────────────────────────────────────────────────────

// Get current site metrics snapshot (for goal baseline and progress)
add_action('wp_ajax_seom_get_goal_metrics', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_page_metrics';
    $settings = seom_get_settings();
    $ghost_threshold = intval($settings['ghost_threshold']);

    // Optional: get metrics as of a specific historical date
    $as_of = sanitize_text_field($_POST['as_of_date'] ?? '');

    if ($as_of) {
        // Find the closest collection date on or before the requested date
        $target_date = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(date_collected) FROM {$table} WHERE date_collected <= %s", $as_of
        ));
        if (!$target_date) {
            // Fall back to the earliest date we have
            $target_date = $wpdb->get_var("SELECT MIN(date_collected) FROM {$table}");
        }
    } else {
        $target_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");
    }

    if (!$target_date) wp_send_json_error('No GSC data collected yet.');

    $metrics = $wpdb->get_row($wpdb->prepare("
        SELECT
            COUNT(DISTINCT m.post_id) as total_pages,
            SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(%s, INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
            SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
            SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
            SUM(CASE WHEN m.avg_position > 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page3plus_pages,
            SUM(CASE WHEN p.post_date >= DATE_SUB(%s, INTERVAL 14 DAY) THEN 1 ELSE 0 END) as new_pages
        FROM {$table} m
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE m.date_collected = %s
        AND p.post_status = 'publish' AND p.post_type IN ('product','post','page')
    ", $ghost_threshold, $target_date, $target_date, $target_date));

    // Available collection dates for the date picker
    $available_dates = $wpdb->get_col("SELECT DISTINCT date_collected FROM {$table} ORDER BY date_collected ASC");

    // Additional metrics: new content created and stale pages
    $cooldown_days = intval($settings['cooldown_days']);

    // New content: posts/products published in last 30 days
    $new_content_30d = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type IN ('post','product') AND post_status = 'publish'
        AND post_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");

    // Stale pages: published posts/products older than 90 days that have NEVER been refreshed
    // or whose last refresh is older than cooldown period
    $stale_pages = (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'last_page_refresh'
        WHERE p.post_type IN ('post','product') AND p.post_status = 'publish'
        AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        AND (pm.meta_value IS NULL OR pm.meta_value < DATE_SUB(CURDATE(), INTERVAL %d DAY))
    ", $cooldown_days));

    // Refreshed this month count
    $refreshed_this_month = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history
        WHERE refresh_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    ");

    // Add to metrics object
    $metrics->new_content_30d = $new_content_30d;
    $metrics->stale_pages = $stale_pages;
    $metrics->refreshed_this_month = $refreshed_this_month;

    // Daily refresh capacity
    $daily_limit = intval($settings['daily_limit']);

    wp_send_json_success([
        'metrics'         => $metrics,
        'daily_limit'     => $daily_limit,
        'cooldown_days'   => $cooldown_days,
        'collected_date'  => $target_date,
        'available_dates' => $available_dates,
    ]);
});

// Get all goals
add_action('wp_ajax_seom_get_goals', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $goals = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}seom_goals ORDER BY FIELD(status, 'active', 'completed', 'missed', 'cancelled'), priority ASC, deadline ASC");
    wp_send_json_success($goals ?: []);
});

// Create a goal
add_action('wp_ajax_seom_create_goal', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $metric       = sanitize_text_field($_POST['metric'] ?? '');
    $direction    = sanitize_text_field($_POST['direction'] ?? 'reduce');
    $target_value = floatval($_POST['target_value'] ?? 0);
    $target_type  = sanitize_text_field($_POST['target_type'] ?? 'percent');
    $baseline     = floatval($_POST['baseline_value'] ?? 0);
    $start_date   = sanitize_text_field($_POST['start_date'] ?? date('Y-m-d'));
    $deadline     = sanitize_text_field($_POST['deadline'] ?? '');
    $priority     = max(1, min(5, intval($_POST['priority'] ?? 3)));
    $notes        = sanitize_textarea_field($_POST['notes'] ?? '');
    $ai_assessment = sanitize_textarea_field($_POST['ai_assessment'] ?? '');

    if (empty($metric) || empty($deadline) || $target_value <= 0) {
        wp_send_json_error('Missing required fields.');
    }

    // Get the actual current value for this metric (not the historical baseline)
    $table = $wpdb->prefix . 'seom_page_metrics';
    $ghost_threshold = intval(seom_get_settings()['ghost_threshold']);
    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");
    $current_value = $baseline; // fallback
    if ($latest_date) {
        $cur_metrics = $wpdb->get_row($wpdb->prepare("
            SELECT
                SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
                SUM(m.clicks) as total_clicks,
                SUM(m.impressions) as total_impressions,
                AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
                AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
                SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
                SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
                SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions
            FROM {$table} m
            INNER JOIN (SELECT post_id, MAX(date_collected) as max_date FROM {$table} GROUP BY post_id) latest
                ON m.post_id = latest.post_id AND m.date_collected = latest.max_date
            JOIN {$wpdb->posts} p ON m.post_id = p.ID
            WHERE p.post_status = 'publish' AND p.post_type IN ('product','post','page')
        ", $ghost_threshold));
        if ($cur_metrics && isset($cur_metrics->$metric)) {
            $current_value = floatval($cur_metrics->$metric);
        }
    }

    $wpdb->insert($wpdb->prefix . 'seom_goals', [
        'metric'         => $metric,
        'direction'      => $direction,
        'target_value'   => $target_value,
        'target_type'    => $target_type,
        'baseline_value' => $baseline,
        'current_value'  => $current_value,
        'start_date'     => $start_date,
        'deadline'       => $deadline,
        'priority'       => $priority,
        'created_at'     => current_time('mysql'),
        'status'         => 'active',
        'ai_assessment'  => $ai_assessment,
        'notes'          => $notes,
    ]);

    wp_send_json_success(['id' => $wpdb->insert_id]);
});

// Update goal status
add_action('wp_ajax_seom_update_goal', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('Invalid.');

    $mode = sanitize_text_field($_POST['mode'] ?? 'status');

    if ($mode === 'full') {
        // Full edit of an active goal
        $update = [];
        if (isset($_POST['target_value']))   $update['target_value']   = floatval($_POST['target_value']);
        if (isset($_POST['target_type']))    $update['target_type']    = sanitize_text_field($_POST['target_type']);
        if (isset($_POST['direction']))      $update['direction']      = sanitize_text_field($_POST['direction']);
        if (isset($_POST['priority']))       $update['priority']       = max(1, min(5, intval($_POST['priority'])));
        if (isset($_POST['deadline']))       $update['deadline']       = sanitize_text_field($_POST['deadline']);
        if (isset($_POST['notes']))          $update['notes']          = sanitize_textarea_field($_POST['notes']);
        if (isset($_POST['metric']))         $update['metric']         = sanitize_text_field($_POST['metric']);
        if (isset($_POST['baseline_value'])) $update['baseline_value'] = floatval($_POST['baseline_value']);
        if (isset($_POST['start_date']))     $update['start_date']     = sanitize_text_field($_POST['start_date']);

        if (empty($update)) wp_send_json_error('Nothing to update.');
        $wpdb->update($wpdb->prefix . 'seom_goals', $update, ['id' => $id]);
    } else {
        // Status-only update
        $status = sanitize_text_field($_POST['status'] ?? '');
        if (!in_array($status, ['active', 'completed', 'cancelled'])) wp_send_json_error('Invalid status.');
        $wpdb->update($wpdb->prefix . 'seom_goals', ['status' => $status], ['id' => $id]);
    }

    wp_send_json_success();
});

// Delete goal
add_action('wp_ajax_seom_delete_goal', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('Invalid.');
    $wpdb->delete($wpdb->prefix . 'seom_goals', ['id' => $id]);
    wp_send_json_success();
});

// AI feasibility check
add_action('wp_ajax_seom_check_goal_feasibility', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $metric       = sanitize_text_field($_POST['metric'] ?? '');
    $direction    = sanitize_text_field($_POST['direction'] ?? '');
    $target_value = floatval($_POST['target_value'] ?? 0);
    $target_type  = sanitize_text_field($_POST['target_type'] ?? 'percent');
    $baseline     = floatval($_POST['baseline_value'] ?? 0);
    $days         = intval($_POST['days'] ?? 30);
    $daily_limit  = intval($_POST['daily_limit'] ?? 20);

    $site_name = get_bloginfo('name');

    $metric_labels = [
        'ghost_pages'           => 'Ghost Pages (zero impressions, older than 14 days)',
        'total_clicks'          => 'Total Clicks (28-day)',
        'total_impressions'     => 'Total Impressions (28-day)',
        'avg_position'          => 'Average Position',
        'avg_ctr'               => 'Average CTR (%)',
        'page1_pages'           => 'Pages Ranking on Page 1',
        'page2_pages'           => 'Pages Ranking on Page 2',
        'pages_with_impressions'=> 'Pages With Impressions',
        'new_content_30d'       => 'New Content Created in Last 30 Days',
        'stale_pages'           => 'Stale Pages (not refreshed in 90+ days)',
        'refreshed_this_month'  => 'Pages Refreshed This Month',
    ];
    $metric_label = $metric_labels[$metric] ?? $metric;

    // Calculate the actual target number
    if ($target_type === 'percent') {
        $change = $baseline * ($target_value / 100);
        $target_num = ($direction === 'reduce') ? $baseline - $change : $baseline + $change;
        $change_desc = "{$direction} by {$target_value}% (from {$baseline} to " . round($target_num, 1) . ")";
    } else {
        $target_num = $target_value;
        $change_desc = "{$direction} from {$baseline} to {$target_value}";
    }

    $max_refreshes = $daily_limit * $days;

    $prompt = "You are an SEO strategist for {$site_name}, an IT training and certification website with approximately {$baseline} baseline for the metric below.

GOAL: {$change_desc} for metric \"{$metric_label}\" within {$days} days.
CAPACITY: We can refresh up to {$daily_limit} pages per day ({$max_refreshes} total in the period).
CURRENT BASELINE: {$baseline}

Evaluate this goal and respond with EXACTLY this JSON format:
{
  \"feasibility\": \"achievable\" or \"stretch\" or \"unlikely\" or \"unrealistic\",
  \"confidence\": 1-100,
  \"reasoning\": \"2-3 sentence explanation of why\",
  \"recommendation\": \"1-2 sentence suggestion for adjusting if needed\",
  \"suggested_target\": number (your recommended realistic target value using the same unit),
  \"suggested_days\": number (your recommended timeline in days)
}

Key considerations:
- SEO changes take 2-4 weeks to show in GSC data
- Ghost pages may need content rewrites + resubmission to Search Console
- CTR improvements from meta/title changes show faster (1-2 weeks)
- Position improvements from content refresh average 2-5 positions over 30-60 days
- Not every refresh produces improvement — expect 30-40% success rate
- Large percentage targets on already-good metrics are harder than fixing poor ones
- Factor in the {$max_refreshes} refresh capacity vs the scale of change needed";

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) wp_send_json_error('API key not configured.');
    $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => 'Evaluate this goal feasibility. Return only JSON.'],
        ],
        'temperature' => 0.3,
    ];
    if (strpos($model, 'gpt-4') !== false) {
        $body['response_format'] = ['type' => 'json_object'];
    }

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
        'body'    => json_encode($body),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) wp_send_json_error('API error: ' . $response->get_error_message());

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $raw = trim($data['choices'][0]['message']['content'] ?? '');
    $raw = preg_replace('/^```[a-z]*\s*/m', '', $raw);
    $raw = preg_replace('/\s*```\s*$/m', '', $raw);
    $result = json_decode($raw, true);

    if (!$result) wp_send_json_error('AI returned invalid response.');

    wp_send_json_success($result);
});

// Refresh goal progress (update current_value for all active goals)
add_action('wp_ajax_seom_refresh_goal_progress', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_page_metrics';
    $goals_table = $wpdb->prefix . 'seom_goals';
    $settings = seom_get_settings();
    $ghost_threshold = intval($settings['ghost_threshold']);

    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");
    if (!$latest_date) wp_send_json_error('No data.');

    $metrics = $wpdb->get_row($wpdb->prepare("
        SELECT
            SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
            SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
            SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions
        FROM {$table} m
        INNER JOIN (SELECT post_id, MAX(date_collected) as max_date FROM {$table} GROUP BY post_id) latest
            ON m.post_id = latest.post_id AND m.date_collected = latest.max_date
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE p.post_status = 'publish' AND p.post_type IN ('product','post','page')
    ", $ghost_threshold));

    // Additional metrics
    $cooldown_days = intval($settings['cooldown_days']);
    $metrics->new_content_30d = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type IN ('post','product') AND post_status = 'publish'
        AND post_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $metrics->stale_pages = (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'last_page_refresh'
        WHERE p.post_type IN ('post','product') AND p.post_status = 'publish'
        AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        AND (pm.meta_value IS NULL OR pm.meta_value < DATE_SUB(CURDATE(), INTERVAL %d DAY))
    ", $cooldown_days));
    $metrics->refreshed_this_month = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history
        WHERE refresh_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    ");

    $metric_values = (array) $metrics;

    // Update all active goals
    $active = $wpdb->get_results("SELECT * FROM {$goals_table} WHERE status = 'active'");
    $updated = 0;
    $today = date('Y-m-d');

    foreach ($active as $goal) {
        $current = isset($metric_values[$goal->metric]) ? floatval($metric_values[$goal->metric]) : null;
        if ($current === null) continue;

        $update = ['current_value' => $current];

        // Check if deadline passed
        if ($today > $goal->deadline) {
            // Evaluate if goal was met
            if ($goal->target_type === 'percent') {
                $change_needed = $goal->baseline_value * ($goal->target_value / 100);
                $target_num = ($goal->direction === 'reduce')
                    ? $goal->baseline_value - $change_needed
                    : $goal->baseline_value + $change_needed;
            } else {
                $target_num = $goal->target_value;
            }

            $met = ($goal->direction === 'reduce') ? ($current <= $target_num) : ($current >= $target_num);
            $update['status'] = $met ? 'completed' : 'missed';
        }

        $wpdb->update($goals_table, $update, ['id' => $goal->id]);
        $updated++;
    }

    wp_send_json_success(['updated' => $updated]);
});

// AI auto-create monthly goals
add_action('wp_ajax_seom_auto_create_goals', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(60);

    global $wpdb;
    $goals_table = $wpdb->prefix . 'seom_goals';
    $table = $wpdb->prefix . 'seom_page_metrics';
    $settings = seom_get_settings();
    $ghost_threshold = intval($settings['ghost_threshold']);
    $daily_limit = intval($settings['daily_limit']);

    // Get current metrics
    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");
    if (!$latest_date) wp_send_json_error('No GSC data. Run collection first.');

    $m = $wpdb->get_row($wpdb->prepare("
        SELECT
            SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
            SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
            SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
            COUNT(DISTINCT m.post_id) as total_pages
        FROM {$table} m
        INNER JOIN (SELECT post_id, MAX(date_collected) as max_date FROM {$table} GROUP BY post_id) latest
            ON m.post_id = latest.post_id AND m.date_collected = latest.max_date
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE p.post_status = 'publish' AND p.post_type IN ('product','post','page')
    ", $ghost_threshold));

    // Check which metrics already have active goals this month
    $now = new \DateTime();
    $month_end = new \DateTime('last day of this month');
    $existing = $wpdb->get_col("SELECT metric FROM {$goals_table} WHERE status = 'active' AND deadline >= CURDATE()");

    // Get last month's goals for context
    $last_month_goals = $wpdb->get_results("
        SELECT metric, direction, target_value, target_type, baseline_value, current_value, status
        FROM {$goals_table}
        WHERE deadline < CURDATE() AND deadline >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
        ORDER BY deadline DESC
    ");
    $history_context = '';
    foreach ($last_month_goals as $lg) {
        $met = $lg->status === 'completed' ? 'MET' : ($lg->status === 'missed' ? 'MISSED' : $lg->status);
        $history_context .= "- {$lg->metric}: {$lg->direction} by {$lg->target_value}" . ($lg->target_type === 'percent' ? '%' : '')
            . " (baseline {$lg->baseline_value}, ended at {$lg->current_value}) — {$met}\n";
    }

    $site_name = get_bloginfo('name');
    // Content production metrics
    $cooldown_d = intval($settings['cooldown_days']);
    $m->new_content_30d = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','product') AND post_status = 'publish' AND post_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $m->stale_pages = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'last_page_refresh' WHERE p.post_type IN ('post','product') AND p.post_status = 'publish' AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND (pm.meta_value IS NULL OR pm.meta_value < DATE_SUB(CURDATE(), INTERVAL %d DAY))", $cooldown_d));
    $m->refreshed_this_month = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history WHERE refresh_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");

    $metrics_summary = "Current metrics for {$site_name}:\n"
        . "- Ghost Pages: {$m->ghost_pages}\n"
        . "- Total Clicks (28d): {$m->total_clicks}\n"
        . "- Total Impressions (28d): {$m->total_impressions}\n"
        . "- Avg Position: " . round($m->avg_position, 1) . "\n"
        . "- Avg CTR: " . round($m->avg_ctr, 2) . "%\n"
        . "- Pages on Page 1: {$m->page1_pages}\n"
        . "- Pages on Page 2: {$m->page2_pages}\n"
        . "- Pages With Impressions: {$m->pages_with_impressions}\n"
        . "- Total Pages: {$m->total_pages}\n"
        . "- New Content Created (30d): {$m->new_content_30d}\n"
        . "- Stale Pages (not refreshed 90+ days): {$m->stale_pages}\n"
        . "- Pages Refreshed This Month: {$m->refreshed_this_month}\n"
        . "- Daily Refresh Capacity: {$daily_limit}\n"
        . "- Days Left in Month: " . $now->diff($month_end)->days . "\n";

    if ($existing) $metrics_summary .= "\nAlready have active goals for: " . implode(', ', $existing) . " — do NOT create duplicates.\n";
    if ($history_context) $metrics_summary .= "\nPrior month goal results:\n{$history_context}\n";

    // User priority context
    $user_priority = sanitize_textarea_field($_POST['user_priority'] ?? '');
    $priority_context = '';
    if (!empty($user_priority)) {
        $priority_context = "\n\nUSER PRIORITY:\nThe user has specified this as their main focus: \"{$user_priority}\"\nWeight your goal suggestions heavily toward this priority.\n";
    }

    $prompt = "You are an SEO strategist. Based on the current metrics below, recommend 3-5 realistic monthly goals.\n\n"
        . $metrics_summary
        . $priority_context . "\n"
        . "RULES:\n"
        . "- Each goal should be achievable within the remaining days this month\n"
        . "- Goals should prioritize the biggest opportunities for improvement\n"
        . "- If prior goals were MISSED, suggest a more conservative version\n"
        . "- If prior goals were MET, suggest a stretch version or new area\n"
        . "- Do NOT suggest goals for metrics that already have active goals\n"
        . "- Use percentage targets (target_type: 'percent')\n"
        . "- Include a mix of priorities (1=Critical, 2=High, 3=Medium, 4=Low, 5=Backlog)\n"
        . "- SEO changes take 2-4 weeks to show results — be realistic\n\n"
        . "Return a JSON object with a \"goals\" array:\n"
        . "{\"goals\": [{\"metric\": \"ghost_pages\", \"direction\": \"reduce\", \"target_value\": 10, \"target_type\": \"percent\", \"priority\": 2, \"notes\": \"reason\"}]}\n"
        . "Valid metrics: ghost_pages, total_clicks, total_impressions, avg_position, avg_ctr, page1_pages, page2_pages, pages_with_impressions, new_content_30d, stale_pages, refreshed_this_month";

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) wp_send_json_error('API key not configured.');
    $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => 'Generate monthly SEO goals based on the metrics and priorities above. Return JSON with a "goals" array.'],
        ],
        'temperature' => 0.4,
    ];
    if (strpos($model, 'gpt-4') !== false) $body['response_format'] = ['type' => 'json_object'];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
        'body'    => json_encode($body),
        'timeout' => 45,
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'API error: ' . $response->get_error_message(), 'prompt_context' => $metrics_summary . $priority_context]);
    }

    $resp_body = wp_remote_retrieve_body($response);
    $data = json_decode($resp_body, true);
    $raw = trim($data['choices'][0]['message']['content'] ?? '');
    $raw = preg_replace('/^```[a-z]*\s*/m', '', $raw);
    $raw = preg_replace('/\s*```\s*$/m', '', $raw);

    $goals = json_decode($raw, true);
    // Handle {"goals": [...]} wrapper
    if (isset($goals['goals'])) $goals = $goals['goals'];
    // Handle direct array
    if (is_array($goals) && isset($goals[0]['metric'])) { /* already an array */ }
    elseif (!is_array($goals) || empty($goals)) {
        // Try regex fallback
        if (preg_match_all('/\{[^{}]*"metric"\s*:\s*"[^"]+?"[^{}]*\}/s', $raw, $matches)) {
            $goals = [];
            foreach ($matches[0] as $match) {
                $g = json_decode($match, true);
                if ($g && isset($g['metric'])) $goals[] = $g;
            }
        }
        if (empty($goals)) {
            wp_send_json_error([
                'message' => 'AI returned unparseable response.',
                'raw_response' => substr($raw, 0, 1000),
                'prompt_context' => $metrics_summary . $priority_context,
            ]);
        }
    }

    $metric_map = (array) $m;
    $month_start = date('Y-m-01');
    $month_deadline = $month_end->format('Y-m-d');
    $created = 0;

    foreach ($goals as $g) {
        $metric = sanitize_text_field($g['metric'] ?? '');
        if (empty($metric) || !isset($metric_map[$metric])) continue;
        if (in_array($metric, $existing)) continue; // skip duplicates

        $baseline = floatval($metric_map[$metric]);
        $current = $baseline; // current = baseline for current snapshot

        $wpdb->insert($goals_table, [
            'metric'         => $metric,
            'direction'      => sanitize_text_field($g['direction'] ?? 'reduce'),
            'target_value'   => floatval($g['target_value'] ?? 5),
            'target_type'    => sanitize_text_field($g['target_type'] ?? 'percent'),
            'baseline_value' => $baseline,
            'current_value'  => $current,
            'start_date'     => $month_start,
            'deadline'       => $month_deadline,
            'priority'       => max(1, min(5, intval($g['priority'] ?? 3))),
            'created_at'     => current_time('mysql'),
            'status'         => 'active',
            'ai_assessment'  => '',
            'notes'          => sanitize_text_field($g['notes'] ?? 'Auto-created by AI'),
        ]);
        $existing[] = $metric;
        $created++;
    }

    wp_send_json_success(['created' => $created, 'suggested' => count($goals), 'prompt_context' => $metrics_summary . $priority_context]);
});

// ─── Cron Management AJAX ────────────────────────────────────────────────────

add_action('wp_ajax_seom_cron_action', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $hook = sanitize_text_field($_POST['hook'] ?? '');
    $action = sanitize_text_field($_POST['cron_action'] ?? '');

    // Only allow our hooks
    $allowed_hooks = [
        'seom_daily_collect', 'seom_daily_analyze', 'seom_daily_process',
        'seom_daily_keywords', 'seom_weekly_backfill', 'seom_weekly_autocomplete',
        'seom_daily_goal_email',
    ];

    // Schedules for each hook
    $hook_schedules = [
        'seom_daily_collect'       => ['recurrence' => 'daily', 'time' => '01:00'],
        'seom_daily_analyze'       => ['recurrence' => 'daily', 'time' => '02:00'],
        'seom_daily_process'       => ['recurrence' => 'daily', 'time' => '06:00'],
        'seom_daily_keywords'      => ['recurrence' => 'daily', 'time' => '01:30'],
        'seom_weekly_backfill'     => ['recurrence' => 'weekly', 'time' => '03:00'],
        'seom_weekly_autocomplete' => ['recurrence' => 'weekly', 'time' => '04:00'],
        'seom_daily_goal_email'    => ['recurrence' => 'daily', 'time' => '08:00'],
    ];

    if ($action === 'reschedule_all') {
        foreach ($hook_schedules as $h => $sched) {
            wp_clear_scheduled_hook($h);
            $start = $sched['recurrence'] === 'weekly'
                ? strtotime('next sunday ' . $sched['time'])
                : strtotime('today ' . $sched['time']);
            if ($start < time()) $start += ($sched['recurrence'] === 'weekly' ? 604800 : 86400);
            wp_schedule_event($start, $sched['recurrence'], $h);
        }
        wp_send_json_success('All tasks rescheduled.');
    }

    if (!in_array($hook, $allowed_hooks)) wp_send_json_error('Invalid hook.');

    switch ($action) {
        case 'run':
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            @set_time_limit(300);
            do_action($hook);
            wp_send_json_success('Executed.');
            break;

        case 'disable':
            wp_clear_scheduled_hook($hook);
            wp_send_json_success('Disabled.');
            break;

        case 'enable':
            $sched = $hook_schedules[$hook] ?? ['recurrence' => 'daily', 'time' => '01:00'];
            $start = $sched['recurrence'] === 'weekly'
                ? strtotime('next sunday ' . $sched['time'])
                : strtotime('today ' . $sched['time']);
            if ($start < time()) $start += ($sched['recurrence'] === 'weekly' ? 604800 : 86400);
            wp_schedule_event($start, $sched['recurrence'], $hook);
            wp_send_json_success('Enabled.');
            break;

        default:
            wp_send_json_error('Invalid action.');
    }
});
