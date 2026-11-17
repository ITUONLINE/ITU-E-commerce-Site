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

            // ── Multi-range site totals cache ──
            // Fetch GSC's authoritative no-dimension aggregates for every date
            // range the Indexed Pages tab exposes. These are the only numbers
            // that match the GSC web dashboard exactly — the per-page sum drifts
            // because the ['page'] dimension call drops anonymized/unattributed
            // traffic. Six extra API calls per night is trivial against quota.
            $range_totals = [];
            foreach ([7, 14, 28, 60, 90, 180] as $range_days) {
                $rt = $client->get_site_totals($range_days);
                if (!is_wp_error($rt)) {
                    $range_totals[$range_days] = [
                        'clicks'      => $rt['clicks'],
                        'impressions' => $rt['impressions'],
                        'ctr'         => $rt['ctr'],
                        'position'    => $rt['position'],
                        'date'        => $today,
                    ];
                }
            }
            if (!empty($range_totals)) {
                update_option('seom_site_totals_by_range', $range_totals);
            }

            // Collect finalized single-day metrics for T-3 (3 days ago — GSC data is complete by then)
            $daily_date = date('Y-m-d', strtotime('-3 days'));
            $daily_totals = $client->get_daily_totals($daily_date);
            if (!is_wp_error($daily_totals) && ($daily_totals['impressions'] > 0 || $daily_totals['clicks'] > 0)) {
                self::store_daily_metrics($daily_date, $daily_totals);
            }

            // Collect per-page single-day data for T-3 (builds detailed historical dataset)
            self::collect_daily_pages($daily_date);

            // ── Reconciliation DISABLED ──
            // Previously we summed seom_daily_page_metrics over the 28-day T-3
            // window and overwrote the snapshot in seom_page_metrics with those
            // verified-but-stale values. That defeated the entire point of the
            // snapshot — which is supposed to hold the FRESHEST GSC data
            // (including yesterday and day-before-yesterday) so the cards on
            // the Indexed Pages tab show the most current view possible.
            //
            // After the daily collection switched to using ['page'] dimension
            // (instead of a separate site-wide aggregate), reconciliation was
            // no longer adding any verification value — it was just making the
            // snapshot match the daily-sum exactly, which is why the cards and
            // the chart were showing identical numbers.
            //
            // If we ever need verification again, do it WITHOUT overwriting
            // the snapshot — log discrepancies for review instead.
            // self::reconcile_28day_totals();

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
     * Store finalized single-day GSC metrics into seom_daily_summary.
     */
    public static function store_daily_metrics($date, $daily_totals) {
        global $wpdb;
        $table = $wpdb->prefix . 'seom_daily_summary';

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE date_collected = %s", $date));
        $data = [
            'daily_clicks'       => intval($daily_totals['clicks']),
            'daily_impressions'  => intval($daily_totals['impressions']),
            'daily_avg_position' => round(floatval($daily_totals['position']), 1),
            'daily_ctr'          => round(floatval($daily_totals['ctr']), 4),
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $data['date_collected'] = $date;
            $wpdb->insert($table, $data);
        }
    }

    /**
     * Manually collect single-day GSC data for a specific date.
     * Called from the UI to backfill or update daily metrics.
     */
    public static function collect_daily($date) {
        $settings = seom_get_settings();
        if (empty($settings['gsc_credentials_json']) || empty($settings['gsc_property_url'])) {
            return new \WP_Error('not_configured', 'GSC not configured.');
        }

        $client = new SEOM_GSC_Client($settings['gsc_credentials_json'], $settings['gsc_property_url']);
        $daily = $client->get_daily_totals($date);
        if (is_wp_error($daily)) return $daily;

        if ($daily['impressions'] == 0 && $daily['clicks'] == 0) {
            return new \WP_Error('no_data', "No GSC data available for {$date}. Data may not be finalized yet.");
        }

        self::store_daily_metrics($date, $daily);
        return $daily;
    }

    /**
     * Collect and store per-page single-day GSC metrics for a specific date.
     * Matches URLs to WordPress post IDs where possible.
     */
    public static function collect_daily_pages($date) {
        $settings = seom_get_settings();
        if (empty($settings['gsc_credentials_json']) || empty($settings['gsc_property_url'])) {
            return new \WP_Error('not_configured', 'GSC not configured.');
        }

        $client = new SEOM_GSC_Client($settings['gsc_credentials_json'], $settings['gsc_property_url']);
        $page_data = $client->get_daily_page_metrics($date);
        if (is_wp_error($page_data)) return $page_data;
        if (empty($page_data)) return 0;

        global $wpdb;
        $table = $wpdb->prefix . 'seom_daily_page_metrics';

        // Build URL → post_id map from existing metrics
        $url_map = [];
        $stored = $wpdb->get_results("SELECT post_id, url FROM {$wpdb->prefix}seom_page_metrics WHERE url IS NOT NULL AND url != '' GROUP BY post_id, url");
        foreach ($stored as $s) {
            $url_map[self::normalize_url($s->url)] = (int) $s->post_id;
        }

        // Clear existing data for this date (full replace)
        $wpdb->delete($table, ['date_collected' => $date]);

        // Bulk insert
        $saved = 0;
        $values = [];
        $placeholders = [];
        foreach ($page_data as $url => $data) {
            $norm = self::normalize_url($url);
            $post_id = $url_map[$norm] ?? 0;

            $placeholders[] = '(%s, %d, %s, %d, %d, %f, %f)';
            $values[] = $date;
            $values[] = $post_id;
            $values[] = $url;
            $values[] = $data['clicks'];
            $values[] = $data['impressions'];
            $values[] = $data['ctr'];
            $values[] = $data['position'];
            $saved++;

            // Flush every 200 rows
            if (count($placeholders) >= 200) {
                $sql = "INSERT INTO {$table} (date_collected, post_id, url, clicks, impressions, ctr, avg_position) VALUES " . implode(', ', $placeholders);
                $wpdb->query($wpdb->prepare($sql, ...$values));
                $placeholders = [];
                $values = [];
            }
        }

        // Flush remaining
        if (!empty($placeholders)) {
            $sql = "INSERT INTO {$table} (date_collected, post_id, url, clicks, impressions, ctr, avg_position) VALUES " . implode(', ', $placeholders);
            $wpdb->query($wpdb->prepare($sql, ...$values));
        }

        return $saved;
    }

    /**
     * Reconcile seom_page_metrics with true 28-day totals from daily per-page data.
     * Only runs when we have 28+ days of daily history.
     * Updates the most recent collection date's snapshot with verified numbers.
     */
    /**
     * Reconcile seom_page_metrics with true 28-day totals from daily per-page data.
     * Requires a complete, unbroken 28-day window of daily collection runs.
     * Notifies admin if gaps are detected.
     */
    public static function reconcile_28day_totals() {
        global $wpdb;
        $daily_table = $wpdb->prefix . 'seom_daily_page_metrics';
        $metrics_table = $wpdb->prefix . 'seom_page_metrics';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$daily_table}'") !== $daily_table) return;

        // The 28-day window must end at T-3 (today minus 3 days) — the most recent date
        // where GSC data is finalized. This matches what GSC reports as the 28-day period.
        $window_end = date('Y-m-d', strtotime('-3 days'));
        $window_start = date('Y-m-d', strtotime($window_end . ' -27 days'));

        $earliest_daily = $wpdb->get_var("SELECT MIN(date_collected) FROM {$daily_table}");
        if (!$earliest_daily) return;

        // Check if we even have data spanning the full window
        $span = (int) ((strtotime($window_end) - strtotime($earliest_daily)) / 86400) + 1;

        // Even during building phase, check for gaps in what we DO have
        $actual_dates_all = $wpdb->get_col("SELECT DISTINCT date_collected FROM {$daily_table} ORDER BY date_collected ASC");
        $actual_set_all = array_flip($actual_dates_all);
        $range_start = max($earliest_daily, $window_start);
        $range_end = min($window_end, date('Y-m-d', strtotime('-3 days')));
        $early_gaps = [];
        $d = $range_start;
        while ($d <= $range_end) {
            if (!isset($actual_set_all[$d])) {
                $early_gaps[] = $d;
            }
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }

        $days_collected = count($actual_dates_all);
        $settings = seom_get_settings();

        if ($span < 28) {
            $status_msg = "Collecting daily data: {$days_collected} days collected, need 28 consecutive days ({$window_start} to {$window_end}).";
            if (!empty($early_gaps)) {
                $status_msg .= " WARNING: " . count($early_gaps) . " gap(s) detected in existing data.";
            }

            update_option('seom_reconcile_status', [
                'status'  => 'building',
                'message' => $status_msg,
                'span'    => $span,
                'days_collected' => $days_collected,
                'gaps'    => $early_gaps,
                'window'  => $window_start . ' to ' . $window_end,
                'updated' => current_time('mysql'),
            ]);

            // Email admin with building status + any early gaps
            if (!empty($settings['notify_email'])) {
                $subject = '[SEO AI AutoPilot] Daily data collection status: ' . $days_collected . '/28 days';
                $body = "Daily GSC per-page data collection progress:\n\n"
                    . "Days collected: {$days_collected}\n"
                    . "Days needed: 28 consecutive ({$window_start} to {$window_end})\n"
                    . "Span: {$span} days\n\n";

                if (!empty($early_gaps)) {
                    $body .= "⚠ GAPS DETECTED in existing data:\n"
                        . implode(', ', $early_gaps) . "\n\n"
                        . "These dates need to be collected manually from the History tab, "
                        . "or they will prevent reconciliation when 28 days is reached.\n\n";
                } else {
                    $body .= "✓ No gaps detected — all collected dates are consecutive.\n\n";
                }

                $body .= "Reconciliation will activate automatically once 28 consecutive days of data are available.\n"
                    . "You can manually collect missing dates from History > Collect Daily Data.";

                wp_mail($settings['notify_email'], $subject, $body);
            }

            return;
        }

        // Get ALL dates that should exist in the window (28 consecutive days)
        $expected_dates = [];
        for ($i = 0; $i < 28; $i++) {
            $expected_dates[] = date('Y-m-d', strtotime($window_start . " +{$i} days"));
        }

        // Get dates we actually have in the daily table
        $actual_dates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT date_collected FROM {$daily_table} WHERE date_collected BETWEEN %s AND %s ORDER BY date_collected",
            $window_start, $window_end
        ));

        $actual_set = array_flip($actual_dates);
        $missing_dates = [];
        foreach ($expected_dates as $d) {
            if (!isset($actual_set[$d])) {
                $missing_dates[] = $d;
            }
        }

        // Any gaps = data is dirty, do NOT reconcile
        if (!empty($missing_dates)) {
            $settings = seom_get_settings();
            $missing_list = implode(', ', $missing_dates);

            update_option('seom_reconcile_status', [
                'status'       => 'gaps',
                'message'      => count($missing_dates) . " missing day(s) in the 28-day window. Data is not clean enough to reconcile.",
                'missing'      => $missing_dates,
                'window'       => $window_start . ' to ' . $window_end,
                'days_present' => count($actual_dates),
                'updated'      => current_time('mysql'),
            ]);

            // Notify admin about gaps
            if (!empty($settings['notify_email'])) {
                $subject = '[SEO AI AutoPilot] Daily GSC data gaps detected';
                $body = "The 28-day reconciliation window ({$window_start} to {$window_end}) is missing data for:\n\n"
                    . $missing_list . "\n\n"
                    . "Present: " . count($actual_dates) . " of 28 days.\n"
                    . "Missing: " . count($missing_dates) . " day(s).\n\n"
                    . "Reconciliation is paused until all 28 days have data. "
                    . "You can manually collect missing dates from the History tab.";
                wp_mail($settings['notify_email'], $subject, $body);
            }

            return;
        }

        // Full 28-day window present — update the most recent page_metrics snapshot
        // Use today's collection date (the dirty snapshot we're correcting)
        $target_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$metrics_table}");
        if (!$target_date) return;

        $already = get_option('seom_last_reconcile_date', '');
        if ($already === $target_date) return;

        // Aggregate verified 28-day totals per post from daily data
        // Posts that appeared partway through the window naturally get fewer days — that's correct
        $reconciled = $wpdb->get_results($wpdb->prepare("
            SELECT
                post_id,
                SUM(clicks) as clicks,
                SUM(impressions) as impressions,
                CASE WHEN SUM(impressions) > 0 THEN ROUND(SUM(clicks) / SUM(impressions) * 100, 4) ELSE 0 END as ctr,
                ROUND(
                    CASE WHEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END) > 0
                        THEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN avg_position * impressions ELSE 0 END) /
                             SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END)
                        ELSE AVG(CASE WHEN avg_position > 0 THEN avg_position END)
                    END, 1
                ) as avg_position,
                COUNT(DISTINCT date_collected) as days_with_data
            FROM {$daily_table}
            WHERE date_collected BETWEEN %s AND %s
            AND post_id > 0
            GROUP BY post_id
        ", $window_start, $window_end));

        if (empty($reconciled)) return;

        // Update seom_page_metrics with verified totals
        $updated = 0;
        foreach ($reconciled as $r) {
            $affected = $wpdb->update($metrics_table, [
                'clicks'       => intval($r->clicks),
                'impressions'  => intval($r->impressions),
                'ctr'          => floatval($r->ctr),
                'avg_position' => floatval($r->avg_position),
            ], [
                'post_id'        => intval($r->post_id),
                'date_collected' => $target_date,
            ]);
            if ($affected) $updated++;
        }

        // Mark as reconciled
        update_option('seom_last_reconcile_date', $target_date);
        update_option('seom_reconcile_status', [
            'status'         => 'clean',
            'message'        => "Reconciled {$updated} pages with verified 28-day data.",
            'date'           => $target_date,
            'window'         => $window_start . ' to ' . $window_end,
            'days_present'   => 28,
            'pages_updated'  => $updated,
            'reconciled_at'  => current_time('mysql'),
        ]);

        // Update daily summary with corrected data
        seom_update_daily_summary($target_date);
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
