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

            // Fetch true site-wide totals from GSC (no page dimension = full aggregate)
            $site_totals = $client->get_site_totals(28);
            if (!is_wp_error($site_totals)) {
                $totals_record = [
                    'clicks'      => $site_totals['clicks'],
                    'impressions' => $site_totals['impressions'],
                    'ctr'         => $site_totals['ctr'],
                    'position'    => $site_totals['position'],
                    'date'        => $today,
                ];
                update_option('seom_site_totals', $totals_record);

                // Also store historical totals for trend comparison
                $history = get_option('seom_site_totals_history', []);
                $history[$today] = $totals_record;
                // Keep last 90 days of history
                $cutoff = date('Y-m-d', strtotime('-90 days'));
                $history = array_filter($history, function($v) use ($cutoff) { return $v['date'] >= $cutoff; });
                update_option('seom_site_totals_history', $history);
            }

            // Also sum per-page data to track matched vs unmatched
            $gsc_page_impressions = array_sum(array_column($metrics, 'impressions'));
            $gsc_page_clicks = array_sum(array_column($metrics, 'clicks'));

            set_transient('seom_gsc_metrics_cache', $metrics, 3600);

            $post_types = $settings['process_post_types'];
            $type_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
            $total_posts = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($type_placeholders) AND post_status = 'publish'",
                ...$post_types
            ));

            return [
                'phase'        => 'gsc_fetched',
                'pages_in_gsc' => count($metrics),
                'total_posts'  => $total_posts,
                'total_batches'=> ceil($total_posts / $batch_size),
                'site_totals'  => $site_totals,
                'gsc_page_impressions' => $gsc_page_impressions,
                'gsc_page_clicks'      => $gsc_page_clicks,
            ];
        }

        // Phase 2: Process a batch of posts
        $metrics = get_transient('seom_gsc_metrics_cache');
        if (!$metrics || !is_array($metrics)) {
            return new WP_Error('no_cache', 'GSC metrics cache expired. Start collection again.');
        }

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

        // Load stored URLs in bulk — avoid calling get_permalink() for every post every day
        $post_ids_in_batch = wp_list_pluck($posts, 'ID');
        $id_placeholders = implode(',', array_fill(0, count($post_ids_in_batch), '%d'));
        $stored_urls = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, url FROM {$wpdb->prefix}seom_page_metrics
             WHERE post_id IN ({$id_placeholders}) AND url IS NOT NULL AND url != ''
             GROUP BY post_id, url",
            ...$post_ids_in_batch
        ), OBJECT_K);

        // Build a normalized lookup index from GSC URLs for reliable matching
        // Strips scheme, trailing slashes, and query parameters
        $normalized_metrics = [];
        $norm_to_gsc_url = []; // normalized => original GSC URL (for tracking matches)
        foreach ($metrics as $gsc_url => $gsc_data) {
            $norm = self::normalize_url($gsc_url);
            $normalized_metrics[$norm] = $gsc_data;
            $norm_to_gsc_url[$norm] = $gsc_url;
        }

        // Load matched URLs tracker (accumulated across batches)
        $matched_urls = get_transient('seom_matched_gsc_urls') ?: [];

        $saved = 0;
        foreach ($posts as $post) {
            // Use stored URL if available, only call get_permalink for new posts
            $url = isset($stored_urls[$post->ID]) ? $stored_urls[$post->ID]->url : get_permalink($post->ID);
            if (!$url) continue;

            // Try exact match first, then normalized match
            $matched_gsc_url = null;
            $data = $metrics[$url] ?? null;
            if ($data) { $matched_gsc_url = $url; }
            if (!$data) { $data = $metrics[rtrim($url, '/')] ?? null; if ($data) $matched_gsc_url = rtrim($url, '/'); }
            if (!$data) { $data = $metrics[$url . '/'] ?? null; if ($data) $matched_gsc_url = $url . '/'; }
            if (!$data) {
                $norm_url = self::normalize_url($url);
                $data = $normalized_metrics[$norm_url] ?? null;
                if ($data) $matched_gsc_url = $norm_to_gsc_url[$norm_url] ?? null;
            }

            // Track which GSC URLs we matched
            if ($matched_gsc_url) {
                $matched_urls[$matched_gsc_url] = true;
            }

            $clicks      = $data['clicks'] ?? 0;
            $impressions = $data['impressions'] ?? 0;
            $ctr         = $data['ctr'] ?? 0;
            $position    = $data['position'] ?? 0;

            // Upsert metrics for today
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}seom_page_metrics WHERE post_id = %d AND date_collected = %s",
                $post->ID, $today
            ));

            // Only fetch per-page queries for high-value pages (each is a separate API call ~1-2s)
            $top_queries = null;
            if ($clicks > 3) {
                $page_queries = $client->get_page_queries($url, 28, 5);
                if (!is_wp_error($page_queries) && !empty($page_queries)) {
                    $top_queries = json_encode($page_queries);
                }
            }

            $row = [
                'clicks'       => $clicks,
                'impressions'  => $impressions,
                'ctr'          => $ctr,
                'avg_position' => $position,
                'url'          => $url,
                'top_queries'  => $top_queries,
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

        // Save matched URLs tracker for next batch
        set_transient('seom_matched_gsc_urls', $matched_urls, 3600);

        $untracked_saved = 0;
        if (!$has_more) {
            // Final batch — save unmatched GSC URLs as untracked metrics
            $untracked_saved = self::save_untracked_urls($metrics, $matched_urls, $today);

            update_option('seom_last_collect', current_time('mysql'));
            delete_transient('seom_gsc_metrics_cache');
            delete_transient('seom_matched_gsc_urls');
        }

        return [
            'phase'          => 'saving',
            'batch'          => $batch_page,
            'saved'          => $saved,
            'processed'      => $processed_so_far,
            'total_posts'    => $total_posts,
            'has_more'       => $has_more,
            'untracked_saved'=> $untracked_saved,
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
                'ctr_after_30d'         => round(($row['ctr'] ?? 0) * 100, 4),
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
                'ctr_after_60d'         => round(($row['ctr'] ?? 0) * 100, 4),
            ], ['id' => $entry->id]);
        }
    }

    /**
     * Normalize a URL for comparison: strip scheme, query params, trailing slash.
     */
    public static function normalize_url($url) {
        $norm = preg_replace('#^https?://#', '', $url);
        $norm = strtok($norm, '?');
        return rtrim($norm, '/');
    }

    /**
     * Classify an untracked URL by its path pattern.
     */
    private static function classify_url($url) {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        if (preg_match('#/category/#i', $path))    return 'category';
        if (preg_match('#/tag/#i', $path))          return 'tag';
        if (preg_match('#/page/\d+#i', $path))      return 'pagination';
        if (preg_match('#/author/#i', $path))        return 'author';
        if (preg_match('#/feed#i', $path))           return 'feed';
        if (strpos($url, '?s=') !== false)           return 'search';
        if (preg_match('#/product-category/#i', $path)) return 'product_cat';
        if (preg_match('#/product-tag/#i', $path))   return 'product_tag';
        return 'other';
    }

    /**
     * Save unmatched GSC URLs to the untracked metrics table.
     *
     * @param array  $all_metrics   Full GSC metrics [url => data]
     * @param array  $matched_urls  GSC URLs that matched WordPress posts [url => true]
     * @param string $today         Collection date (Y-m-d)
     * @return int   Number of untracked URLs saved
     */
    private static function save_untracked_urls($all_metrics, $matched_urls, $today) {
        global $wpdb;
        $table = $wpdb->prefix . 'seom_untracked_metrics';

        // Build normalized matched set for comparison
        $matched_normalized = [];
        foreach ($matched_urls as $url => $v) {
            $matched_normalized[self::normalize_url($url)] = true;
        }

        $untracked = [];
        foreach ($all_metrics as $gsc_url => $data) {
            // Skip if this URL was matched to a WordPress post
            if (isset($matched_urls[$gsc_url])) continue;
            if (isset($matched_normalized[self::normalize_url($gsc_url)])) continue;

            // Skip URLs with zero impressions
            if (($data['impressions'] ?? 0) <= 0) continue;

            $untracked[] = [
                'url'          => $gsc_url,
                'clicks'       => $data['clicks'] ?? 0,
                'impressions'  => $data['impressions'] ?? 0,
                'ctr'          => $data['ctr'] ?? 0,
                'avg_position' => $data['position'] ?? 0,
                'url_type'     => self::classify_url($gsc_url),
            ];
        }

        if (empty($untracked)) return 0;

        // Clear today's untracked data (full replace for the day)
        $wpdb->delete($table, ['date_collected' => $today]);

        // Bulk insert in batches of 100
        $saved = 0;
        foreach (array_chunk($untracked, 100) as $batch) {
            $values = [];
            $placeholders = [];
            foreach ($batch as $row) {
                $placeholders[] = '(%s, %s, %d, %d, %f, %f, %s)';
                $values[] = $row['url'];
                $values[] = $today;
                $values[] = $row['clicks'];
                $values[] = $row['impressions'];
                $values[] = $row['ctr'];
                $values[] = $row['avg_position'];
                $values[] = $row['url_type'];
            }
            $sql = "INSERT INTO {$table} (url, date_collected, clicks, impressions, ctr, avg_position, url_type) VALUES "
                 . implode(', ', $placeholders);
            $wpdb->query($wpdb->prepare($sql, ...$values));
            $saved += count($batch);
        }

        // Cleanup old data (older than 90 days)
        $wpdb->query("DELETE FROM {$table} WHERE date_collected < DATE_SUB(CURDATE(), INTERVAL 90 DAY)");

        return $saved;
    }
}
