<?php
/**
 * Data Collector
 *
 * Fetches GSC data and stores page metrics snapshots.
 * Also handles backfilling 30d/60d after-metrics in refresh history.
 */

if (!defined('ABSPATH')) exit;

class SEOM_Collector {

    /**
     * Run data collection in batches. Called via AJAX with a page parameter.
     * Phase 1 (page=0): Fetch all page metrics from GSC in one call, store in transient.
     * Phase 2 (page=1,2,...): Save metrics for batches of posts.
     */
    public static function run($batch_page = 0) {
        $settings = seom_get_settings();

        if (empty($settings['gsc_credentials_json']) || empty($settings['gsc_property_url'])) {
            return new WP_Error('not_configured', 'GSC credentials not configured.');
        }

        global $wpdb;
        $today = date('Y-m-d');
        $batch_size = 50;

        // Phase 1: Fetch GSC metrics (only on first call)
        if ($batch_page === 0) {
            $client = new SEOM_GSC_Client($settings['gsc_credentials_json'], $settings['gsc_property_url']);

            $metrics = $client->get_all_page_metrics(28);
            if (is_wp_error($metrics)) return $metrics;

            // Fetch search appearance data (SERP features: FAQ rich results, etc.)
            $serp_error = null;
            $appearances = $client->get_all_search_appearances(28);
            if (is_wp_error($appearances)) {
                $serp_error = $appearances->get_error_message();
                $appearances = [];
            }

            // Store in transients — use wp_options directly for large data
            set_transient('seom_gsc_metrics_cache', $metrics, 3600);
            // Store appearances in a temp file to avoid bloating wp_options
            $cache_file = wp_upload_dir()['basedir'] . '/seom_appearances_cache.json';
            file_put_contents($cache_file, json_encode($appearances));

            // Count total posts to process
            $post_types = $settings['process_post_types'];
            $type_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
            $total_posts = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($type_placeholders) AND post_status = 'publish'",
                ...$post_types
            ));

            return [
                'phase'              => 'gsc_fetched',
                'pages_in_gsc'       => count($metrics),
                'total_posts'        => $total_posts,
                'total_batches'      => ceil($total_posts / $batch_size),
                'serp_pages_found'   => count($appearances),
                'serp_error'         => $serp_error,
                'serp_cache_written' => file_exists($cache_file),
            ];
        }

        // Phase 2: Process a batch of posts
        $metrics = get_transient('seom_gsc_metrics_cache');
        if (!$metrics || !is_array($metrics)) {
            return new WP_Error('no_cache', 'GSC metrics cache expired. Start collection again.');
        }

        $cache_file = wp_upload_dir()['basedir'] . '/seom_appearances_cache.json';
        $appearances = file_exists($cache_file) ? json_decode(file_get_contents($cache_file), true) : [];
        $client = new SEOM_GSC_Client($settings['gsc_credentials_json'], $settings['gsc_property_url']);

        $post_types = $settings['process_post_types'];
        $type_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $offset = ($batch_page - 1) * $batch_size;

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type FROM {$wpdb->posts}
             WHERE post_type IN ($type_placeholders) AND post_status = 'publish'
             ORDER BY ID ASC LIMIT %d OFFSET %d",
            ...array_merge($post_types, [$batch_size, $offset])
        ));

        $saved = 0;
        foreach ($posts as $post) {
            $url = get_permalink($post->ID);
            if (!$url) continue;

            $data = $metrics[$url] ?? $metrics[rtrim($url, '/')] ?? $metrics[$url . '/'] ?? null;

            $clicks      = $data['clicks'] ?? 0;
            $impressions = $data['impressions'] ?? 0;
            $ctr         = $data['ctr'] ?? 0;
            $position    = $data['position'] ?? 0;

            // Upsert metrics for today
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}seom_page_metrics WHERE post_id = %d AND date_collected = %s",
                $post->ID, $today
            ));

            // Fetch top queries only for pages with significant impressions
            // Higher threshold to keep batch processing fast (each is a separate API call)
            $top_queries = null;
            if ($impressions > 50) {
                $page_queries = $client->get_page_queries($url, 28, 5);
                if (!is_wp_error($page_queries) && !empty($page_queries)) {
                    $top_queries = json_encode($page_queries);
                }
            }

            // Match search appearance (SERP features) for this URL
            $page_appearances = $appearances[$url] ?? $appearances[rtrim($url, '/')] ?? $appearances[$url . '/'] ?? null;
            $search_appearance = $page_appearances ? json_encode($page_appearances) : null;

            $row = [
                'clicks'            => $clicks,
                'impressions'       => $impressions,
                'ctr'               => $ctr,
                'avg_position'      => $position,
                'url'               => $url,
                'top_queries'       => $top_queries,
                'search_appearance' => $search_appearance,
            ];

            if ($existing) {
                $wpdb->update("{$wpdb->prefix}seom_page_metrics", $row, ['id' => $existing]);
            } else {
                $row['post_id'] = $post->ID;
                $row['date_collected'] = $today;
                $wpdb->insert("{$wpdb->prefix}seom_page_metrics", $row);
            }
            $saved++;
        }

        $total_posts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($type_placeholders) AND post_status = 'publish'",
            ...$post_types
        ));

        $processed_so_far = $offset + count($posts);
        $has_more = $processed_so_far < $total_posts;

        if (!$has_more) {
            update_option('seom_last_collect', current_time('mysql'));
            delete_transient('seom_gsc_metrics_cache');
            $cache_file = wp_upload_dir()['basedir'] . '/seom_appearances_cache.json';
            if (file_exists($cache_file)) @unlink($cache_file);
        }

        return [
            'phase'         => 'saving',
            'batch'         => $batch_page,
            'saved'         => $saved,
            'processed'     => $processed_so_far,
            'total_posts'   => $total_posts,
            'has_more'      => $has_more,
        ];
    }

    /**
     * Backfill 30d and 60d after-metrics in refresh history.
     * Runs weekly.
     */
    public static function backfill_history() {
        $settings = seom_get_settings();
        if (empty($settings['gsc_credentials_json']) || empty($settings['gsc_property_url'])) return;

        global $wpdb;
        $client = new SEOM_GSC_Client($settings['gsc_credentials_json'], $settings['gsc_property_url']);

        // Find history entries needing 30d backfill (refreshed 30-37 days ago, no 30d data yet)
        $need_30d = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}seom_refresh_history
            WHERE clicks_after_30d IS NULL
            AND refresh_date <= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND refresh_date >= DATE_SUB(NOW(), INTERVAL 45 DAY)
            LIMIT 50
        ");

        // Find history entries needing 60d backfill
        $need_60d = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}seom_refresh_history
            WHERE clicks_after_60d IS NULL
            AND clicks_after_30d IS NOT NULL
            AND refresh_date <= DATE_SUB(NOW(), INTERVAL 60 DAY)
            AND refresh_date >= DATE_SUB(NOW(), INTERVAL 75 DAY)
            LIMIT 50
        ");

        foreach ($need_30d as $entry) {
            $url = get_permalink($entry->post_id);
            if (!$url) continue;

            // Get metrics for the 28 days after the refresh settled (starting 3 days after refresh)
            $start = date('Y-m-d', strtotime($entry->refresh_date . ' +3 days'));
            $end   = date('Y-m-d', strtotime($entry->refresh_date . ' +31 days'));

            $result = $client->get_search_analytics($start, $end, ['page'], 1, 0);
            if (is_wp_error($result)) continue;

            $row = $result['rows'][0] ?? null;
            if (!$row) continue;

            // Check this is our page
            if (($row['keys'][0] ?? '') !== $url && ($row['keys'][0] ?? '') !== rtrim($url, '/')) continue;

            $wpdb->update("{$wpdb->prefix}seom_refresh_history", [
                'clicks_after_30d'      => $row['clicks'] ?? 0,
                'impressions_after_30d' => $row['impressions'] ?? 0,
                'position_after_30d'    => round($row['position'] ?? 0, 1),
                'ctr_after_30d'         => round(($row['ctr'] ?? 0) * 100, 2),
            ], ['id' => $entry->id]);
        }

        foreach ($need_60d as $entry) {
            $url = get_permalink($entry->post_id);
            if (!$url) continue;

            $start = date('Y-m-d', strtotime($entry->refresh_date . ' +33 days'));
            $end   = date('Y-m-d', strtotime($entry->refresh_date . ' +61 days'));

            $result = $client->get_search_analytics($start, $end, ['page'], 1, 0);
            if (is_wp_error($result)) continue;

            $row = $result['rows'][0] ?? null;
            if (!$row) continue;

            $wpdb->update("{$wpdb->prefix}seom_refresh_history", [
                'clicks_after_60d'      => $row['clicks'] ?? 0,
                'impressions_after_60d' => $row['impressions'] ?? 0,
                'position_after_60d'    => round($row['position'] ?? 0, 1),
                'ctr_after_60d'         => round(($row['ctr'] ?? 0) * 100, 2),
            ], ['id' => $entry->id]);
        }
    }
}
