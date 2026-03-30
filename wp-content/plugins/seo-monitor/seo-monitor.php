<?php
/*
Plugin Name: SEO Monitor
Description: Automated SEO performance monitoring using Google Search Console. Identifies underperforming pages and triggers AI content refresh via AI Product Manager.
Version: 1.0
Author: ITU Online
Requires Plugins: ai-product-manager
*/

if (!defined('ABSPATH')) exit;

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

    // Keyword suggestions from autocomplete
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
        'visible_min_impressions' => 500,
        'visible_max_clicks'   => 10,
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
add_action('seom_daily_keywords', ['SEOM_Keyword_Researcher', 'collect']);
add_action('seom_weekly_autocomplete', function () {
    SEOM_Keyword_Researcher::expand_with_autocomplete(50);
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
        'gsc_property_url', 'notify_email', 'exclude_post_ids', 'exclude_categories',
    ];
    $int_fields = [
        'daily_limit', 'cooldown_days', 'ghost_threshold',
        'ctr_fix_min_impressions', 'near_win_min_impressions',
        'visible_min_impressions', 'visible_max_clicks',
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
    $sort = sanitize_text_field($_POST['sort'] ?? 'clicks');
    $order = sanitize_text_field($_POST['order'] ?? 'DESC');
    $filter = sanitize_text_field($_POST['filter'] ?? 'all');
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = 50;
    $offset = ($page - 1) * $per_page;

    $allowed_sorts = ['clicks', 'impressions', 'ctr', 'avg_position', 'post_title'];
    if (!in_array($sort, $allowed_sorts)) $sort = 'clicks';
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    $sort_col = $sort === 'post_title' ? 'p.post_title' : "m.$sort";

    $settings = seom_get_settings();

    // Build filter clause for standard metric-based filters
    $filter_sql = '';
    switch ($filter) {
        case 'ghost':
            $filter_sql = ' AND m.impressions <= ' . intval($settings['ghost_threshold']);
            break;
        case 'page1':
            $filter_sql = ' AND m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0';
            break;
        case 'page2':
            $filter_sql = ' AND m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0';
            break;
        case 'low_ctr':
            $filter_sql = ' AND m.avg_position <= 10 AND m.impressions >= ' . intval($settings['ctr_fix_min_impressions'])
                        . ' AND m.ctr < ' . floatval($settings['ctr_fix_max_ctr']);
            break;
        case 'underperforming':
            $filter_sql = ' AND (m.impressions <= ' . intval($settings['ghost_threshold'])
                        . ' OR (m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions >= ' . intval($settings['near_win_min_impressions']) . ')'
                        . ' OR (m.avg_position <= 10 AND m.impressions >= ' . intval($settings['ctr_fix_min_impressions']) . ' AND m.ctr < ' . floatval($settings['ctr_fix_max_ctr']) . ')'
                        . ' OR (m.impressions >= ' . intval($settings['visible_min_impressions']) . ' AND m.clicks <= ' . intval($settings['visible_max_clicks']) . ')'
                        . ')';
            break;
        case 'limited':
            // Limited Visibility: has some impressions but very few (<100), OR position is 30+
            $filter_sql = ' AND m.impressions > 0 AND (m.impressions < 100 OR m.avg_position >= 30)';
            break;
        case 'top_performers':
            // Top Performers: Driving real traffic — 5+ clicks regardless of position or CTR
            $filter_sql = ' AND m.clicks >= 5 AND m.impressions > 0';
            break;
        case 'stars':
            // Stars: High-traffic pages carrying the site — 15+ clicks and 200+ impressions
            $filter_sql = ' AND m.clicks >= 15 AND m.impressions >= 200';
            break;
    }

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT m.*, p.post_title, p.post_type
        FROM {$wpdb->prefix}seom_page_metrics m
        INNER JOIN (
            SELECT post_id, MAX(date_collected) as max_date
            FROM {$wpdb->prefix}seom_page_metrics
            GROUP BY post_id
        ) latest ON m.post_id = latest.post_id AND m.date_collected = latest.max_date
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE p.post_type = %s AND p.post_status = 'publish'
        $filter_sql
        ORDER BY $sort_col $order
        LIMIT %d OFFSET %d
    ", $post_type, $per_page, $offset));

    $total = (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT m.post_id)
        FROM {$wpdb->prefix}seom_page_metrics m
        INNER JOIN (
            SELECT post_id, MAX(date_collected) as max_date
            FROM {$wpdb->prefix}seom_page_metrics
            GROUP BY post_id
        ) latest ON m.post_id = latest.post_id AND m.date_collected = latest.max_date
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE p.post_type = %s AND p.post_status = 'publish'
        $filter_sql
    ", $post_type));

    // Summary stats — filtered to match the current view
    $summary = $wpdb->get_row($wpdb->prepare("
        SELECT
            COUNT(DISTINCT m.post_id) as total_pages,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
            SUM(CASE WHEN m.impressions <= %d THEN 1 ELSE 0 END) as ghost_pages
        FROM {$wpdb->prefix}seom_page_metrics m
        INNER JOIN (
            SELECT post_id, MAX(date_collected) as max_date
            FROM {$wpdb->prefix}seom_page_metrics
            GROUP BY post_id
        ) latest ON m.post_id = latest.post_id AND m.date_collected = latest.max_date
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE p.post_type = %s AND p.post_status = 'publish'
        $filter_sql
    ", intval($settings['ghost_threshold']), $post_type));

    // Check which posts are already in queue
    $queued_ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->prefix}seom_refresh_queue WHERE status IN ('pending', 'processing')");
    $queued_map = array_flip($queued_ids);

    // Add queue status and last refresh to each row
    foreach ($rows as &$r) {
        $r->in_queue = isset($queued_map[$r->post_id]);
        $r->last_refresh = get_post_meta($r->post_id, 'last_page_refresh', true) ?: null;
    }

    wp_send_json_success([
        'rows'    => $rows,
        'total'   => $total,
        'page'    => $page,
        'summary' => $summary,
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
    $today_count = (int) get_option('seom_daily_count_' . date('Y-m-d'), 0);

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
            ORDER BY h.refresh_date DESC
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
    $per_page = 25;
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
    $summary = ['improving' => 0, 'declining' => 0, 'flat' => 0, 'total' => $total, 'total_clicks_before' => 0, 'total_clicks_now' => 0];
    if ($latest_date) {
        $all_changes = $wpdb->get_results($wpdb->prepare("
            SELECT (COALESCE(m.clicks,0) - h.clicks_before) as click_change,
                   (COALESCE(m.avg_position,0) - h.position_before) as position_change,
                   DATEDIFF(NOW(), h.refresh_date) as days_since,
                   h.clicks_before, COALESCE(m.clicks,0) as clicks_now
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
    @set_time_limit(300);

    $result = SEOM_Keyword_Researcher::collect();
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

    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");
    if (!$latest_date) {
        wp_send_json_success(['rows' => [], 'total' => 0, 'page' => 1, 'summary' => null]);
        return;
    }

    $filter_sql = '';
    switch ($filter) {
        case 'rising':    $filter_sql = " AND trend_direction = 'rising'"; break;
        case 'declining': $filter_sql = " AND trend_direction = 'declining'"; break;
        case 'gaps':      $filter_sql = " AND is_content_gap = 1"; break;
        case 'page2':     $filter_sql = " AND avg_position >= 11 AND avg_position <= 20"; break;
        case 'top':       $filter_sql = " AND avg_position > 0 AND avg_position <= 10"; break;
    }

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT k.*, p.post_title as mapped_title
        FROM {$table} k
        LEFT JOIN {$wpdb->posts} p ON k.mapped_post_id = p.ID
        WHERE k.date_collected = %s $filter_sql
        ORDER BY $sort $order
        LIMIT %d OFFSET %d
    ", $latest_date, $per_page, $offset));

    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE date_collected = %s $filter_sql", $latest_date
    ));

    // Get autocomplete suggestions for displayed keywords
    $sug_table = $wpdb->prefix . 'seom_keyword_suggestions';
    foreach ($rows as &$r) {
        $sug = $wpdb->get_col($wpdb->prepare(
            "SELECT suggestion FROM {$sug_table} WHERE seed_keyword = %s LIMIT 5", $r->keyword
        ));
        $r->suggestions = $sug;
    }

    // Summary
    $summary = $wpdb->get_row($wpdb->prepare("
        SELECT
            COUNT(*) as total_keywords,
            SUM(CASE WHEN trend_direction = 'rising' THEN 1 ELSE 0 END) as rising,
            SUM(CASE WHEN trend_direction = 'declining' THEN 1 ELSE 0 END) as declining,
            SUM(CASE WHEN is_content_gap = 1 THEN 1 ELSE 0 END) as content_gaps,
            SUM(CASE WHEN avg_position >= 11 AND avg_position <= 20 THEN 1 ELSE 0 END) as page2_keywords
        FROM {$table} WHERE date_collected = %s
    ", $latest_date));

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
    ];

    // Schedules for each hook
    $hook_schedules = [
        'seom_daily_collect'     => ['recurrence' => 'daily', 'time' => '01:00'],
        'seom_daily_analyze'     => ['recurrence' => 'daily', 'time' => '02:00'],
        'seom_daily_process'     => ['recurrence' => 'daily', 'time' => '06:00'],
        'seom_daily_keywords'    => ['recurrence' => 'daily', 'time' => '01:30'],
        'seom_weekly_backfill'   => ['recurrence' => 'weekly', 'time' => '03:00'],
        'seom_weekly_autocomplete' => ['recurrence' => 'weekly', 'time' => '04:00'],
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
