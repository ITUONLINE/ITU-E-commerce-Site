<?php
/**
 * Analyzer
 *
 * Scores pages by SEO priority and populates the refresh queue.
 */

if (!defined('ABSPATH')) exit;

class SEOM_Analyzer {

    /**
     * Run the daily analysis: score all monitored pages and queue the top candidates.
     */
    public static function run() {
        global $wpdb;
        $settings = seom_get_settings();

        // Get latest metrics for each post
        $metrics = $wpdb->get_results("
            SELECT m.*
            FROM {$wpdb->prefix}seom_page_metrics m
            INNER JOIN (
                SELECT post_id, MAX(date_collected) as max_date
                FROM {$wpdb->prefix}seom_page_metrics
                GROUP BY post_id
            ) latest ON m.post_id = latest.post_id AND m.date_collected = latest.max_date
        ");

        if (empty($metrics)) {
            update_option('seom_last_analyze', current_time('mysql'));
            return ['scored' => 0, 'queued' => 0, 'message' => 'No metrics data available. Run data collection first.'];
        }

        // Get previous period metrics (28-56 days ago) for trend detection
        $prev_start = date('Y-m-d', strtotime('-56 days'));
        $prev_end = date('Y-m-d', strtotime('-29 days'));
        $prev_metrics = $wpdb->get_results($wpdb->prepare("
            SELECT post_id, SUM(clicks) as clicks, SUM(impressions) as impressions
            FROM {$wpdb->prefix}seom_page_metrics
            WHERE date_collected BETWEEN %s AND %s
            GROUP BY post_id
        ", $prev_start, $prev_end), OBJECT_K);

        // Get cooldown exclusions
        $cooldown_date = date('Y-m-d', strtotime("-{$settings['cooldown_days']} days"));

        // Build exclusion lists
        $excluded_ids = [];
        if (!empty($settings['exclude_post_ids'])) {
            $excluded_ids = array_map('intval', array_filter(explode(',', $settings['exclude_post_ids'])));
        }
        $excluded_categories = [];
        if (!empty($settings['exclude_categories'])) {
            $excluded_categories = array_map('trim', array_filter(explode(',', $settings['exclude_categories'])));
        }

        $candidates = [];
        foreach ($metrics as $m) {
            $post_id = intval($m->post_id);

            // Skip explicitly excluded post IDs
            if (in_array($post_id, $excluded_ids)) continue;

            // Skip pages — tracked for metrics only, never auto-refreshed
            if (get_post_type($post_id) === 'page') continue;

            // Skip excluded categories (works for posts and product_cat)
            if (!empty($excluded_categories)) {
                $post_cats = wp_get_post_categories($post_id, ['fields' => 'slugs']);
                $product_cats = wp_get_post_terms($post_id, 'product_cat', ['fields' => 'slugs']);
                if (!is_wp_error($product_cats)) $post_cats = array_merge($post_cats, $product_cats);
                if (array_intersect($post_cats, $excluded_categories)) continue;
            }

            // Note: Posts with shortcodes (e.g., practice tests) are no longer excluded.
            // The Blog Refresher extracts shortcodes before AI processing and
            // prepends them back to the content after generation.

            // Protect top performers — don't refresh pages that are performing well
            $clicks      = intval($m->clicks);
            $impressions = intval($m->impressions);
            $ctr         = floatval($m->ctr);
            $position    = floatval($m->avg_position);

            if ($clicks >= 5) {
                continue; // Top performer — driving real traffic, don't risk breaking it
            }

            // Check cooldown via last_page_refresh meta
            $last_refresh = get_post_meta($post_id, 'last_page_refresh', true);
            if ($last_refresh && $last_refresh >= $cooldown_date) continue;

            // Also check if already in active queue
            $in_queue = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_queue WHERE post_id = %d AND status IN ('pending', 'processing')",
                $post_id
            ));
            if ($in_queue > 0) continue;

            // Categorize the page
            $category = self::categorize($m, $prev_metrics[$post_id] ?? null, $settings);
            if (!$category) continue;

            // Score it
            $score = self::score($m, $prev_metrics[$post_id] ?? null, $category, $last_refresh, $settings);

            $candidates[] = [
                'post_id'        => $post_id,
                'post_type'      => get_post_type($post_id),
                'category'       => $category,
                'priority_score' => $score,
                'refresh_type'   => in_array($category, ['B', 'E']) ? 'meta_only' : 'full',
            ];
        }

        // Sort by priority score descending
        usort($candidates, function ($a, $b) {
            return $b['priority_score'] <=> $a['priority_score'];
        });

        // Queue the top N (daily limit)
        $to_queue = array_slice($candidates, 0, $settings['daily_limit']);
        $now = current_time('mysql');

        foreach ($to_queue as $c) {
            $wpdb->insert("{$wpdb->prefix}seom_refresh_queue", [
                'post_id'        => $c['post_id'],
                'post_type'      => $c['post_type'],
                'priority_score' => $c['priority_score'],
                'category'       => $c['category'],
                'refresh_type'   => $c['refresh_type'],
                'status'         => 'pending',
                'queued_at'      => $now,
            ]);
        }

        update_option('seom_last_analyze', current_time('mysql'));

        return [
            'scored' => count($candidates),
            'queued' => count($to_queue),
        ];
    }

    /**
     * Categorize a page into A/B/C/D/E or null (not underperforming).
     */
    private static function categorize($metrics, $prev, $settings) {
        $clicks      = intval($metrics->clicks);
        $impressions = intval($metrics->impressions);
        $ctr         = floatval($metrics->ctr);
        $position    = floatval($metrics->avg_position);

        // Category A: Ghost Pages (no impressions)
        if ($impressions <= $settings['ghost_threshold']) {
            return 'A';
        }

        // Category B: CTR Fix (ranks decently, good impressions, low CTR)
        // Position <= 15 to catch top of page 2 as well (they still show in some SERPs)
        if ($position > 0 && $position <= 15
            && $impressions >= $settings['ctr_fix_min_impressions']
            && $ctr < $settings['ctr_fix_max_ctr']) {
            return 'B';
        }

        // Category C: Near Wins (page 2, decent impressions)
        if ($position >= $settings['near_win_min_pos']
            && $position <= $settings['near_win_max_pos']
            && $impressions >= $settings['near_win_min_impressions']) {
            return 'C';
        }

        // Category D: Declining (clicks dropped significantly)
        if ($prev) {
            $prev_clicks = intval($prev->clicks);
            if ($prev_clicks > 0) {
                $decline_pct = (($prev_clicks - $clicks) / $prev_clicks) * 100;
                if ($decline_pct >= $settings['decline_threshold_pct']) {
                    return 'D';
                }
            }
        }

        // Category E: Visible but Ignored (high impressions, almost no clicks)
        if ($impressions >= $settings['visible_min_impressions']
            && $clicks <= $settings['visible_max_clicks']) {
            return 'E';
        }

        return null; // Page is performing acceptably
    }

    /**
     * Calculate composite priority score (0-100).
     */
    private static function score($metrics, $prev, $category, $last_refresh, $settings) {
        $opportunity = self::opportunity_score($metrics, $category);
        $momentum    = self::momentum_score($metrics, $prev);
        $quick_win   = self::quick_win_score($category, floatval($metrics->avg_position));
        $staleness   = self::staleness_score($last_refresh);

        return round(
            ($opportunity * 0.35) +
            ($momentum * 0.25) +
            ($quick_win * 0.25) +
            ($staleness * 0.15),
            2
        );
    }

    private static function opportunity_score($metrics, $category) {
        $position    = floatval($metrics->avg_position);
        $impressions = intval($metrics->impressions);

        if ($category === 'B') return 80;  // CTR fix on page 1

        if ($position >= 11 && $position <= 15 && $impressions > 100) return 90;
        if ($position >= 11 && $position <= 15) return 75;
        if ($position >= 16 && $position <= 20 && $impressions > 50) return 65;
        if ($position >= 16 && $position <= 20) return 55;
        if ($category === 'A') return 40;  // Ghost — unknown potential
        if ($category === 'E') return 60;

        return 30;
    }

    private static function momentum_score($metrics, $prev) {
        if (!$prev) return 50; // No historical data — neutral

        $current_clicks = intval($metrics->clicks);
        $prev_clicks    = intval($prev->clicks);

        if ($prev_clicks <= 0) return 50;

        $change = ($current_clicks - $prev_clicks) / $prev_clicks;

        if ($change < -0.5) return 100; // Rapidly declining
        if ($change < -0.3) return 80;
        if ($change < 0)    return 50;
        if ($change < 0.2)  return 20;  // Stable/growing
        return 10;                       // Growing well — low priority
    }

    private static function quick_win_score($category, $position) {
        switch ($category) {
            case 'B': return 100; // Meta-only fix
            case 'C': return $position <= 15 ? 90 : 70;
            case 'D': return 80;
            case 'E': return 60;
            case 'A': return 40;
            default:  return 30;
        }
    }

    private static function staleness_score($last_refresh) {
        if (empty($last_refresh)) return 100; // Never refreshed

        $months = (time() - strtotime($last_refresh)) / (30 * 86400);

        if ($months >= 12) return 80;
        if ($months >= 6)  return 60;
        if ($months >= 3)  return 30;
        return 0; // Recently refreshed
    }
}
