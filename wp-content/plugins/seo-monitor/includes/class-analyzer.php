<?php
/**
 * Analyzer
 *
 * Scores pages by SEO priority and populates the refresh queue.
 */

if (!defined('ABSPATH')) exit;

class SEOM_Analyzer {

    /**
     * Expected CTR by position based on industry benchmarks.
     * Returns the expected CTR percentage for a given Google position.
     * Used for top-performer protection, CTR fix detection, and opportunity scoring.
     */
    public static function expected_ctr($position) {
        if ($position <= 0) return 0;
        if ($position <= 1) return 30;   // Position 1: ~28-35%
        if ($position <= 2) return 17;   // Position 2: ~15-20%
        if ($position <= 3) return 11;   // Position 3: ~10-12%
        if ($position <= 5) return 7;    // Position 4-5: ~6-9%
        if ($position <= 10) return 3;   // Position 6-10: ~2-5%
        if ($position <= 20) return 1;   // Page 2: ~1%
        return 0.5;                      // Page 3+: under 1%
    }

    /**
     * Check if a page is truly a top performer.
     * A page is "top performing" only if its CTR meets or exceeds
     * a reasonable fraction of the expected CTR for its position.
     * We use 60% of expected as the threshold — pages above this
     * are performing well enough to protect from auto-refresh.
     */
    public static function is_top_performer($clicks, $impressions, $ctr, $position) {
        // Must have meaningful traffic — at least some clicks
        if ($clicks < 3) return false;

        // Must have enough impressions to be statistically meaningful
        if ($impressions < 20) return false;

        $expected = self::expected_ctr($position);
        if ($expected <= 0) return false;

        // CTR must be at least 60% of expected for the position
        // e.g., Position 1 expects 30% — threshold is 18%
        // e.g., Position 5 expects 7% — threshold is 4.2%
        // e.g., Position 8 expects 3% — threshold is 1.8%
        $threshold = $expected * 0.6;

        return $ctr >= $threshold;
    }

    /**
     * Run the daily analysis: score all monitored pages and queue the top candidates.
     */
    // ── Goal-to-category mapping ──
    // Maps goal metrics to the categories that serve them
    private static $goal_category_map = [
        'ghost_pages'            => ['A'],
        'avg_ctr'                => ['B', 'E'],
        'page1_pages'            => ['C'],        // near wins → page 1
        'page2_pages'            => ['C'],        // reducing page 2 = moving to page 1
        'avg_position'           => ['C', 'F'],   // near wins + buried
        'total_clicks'           => ['B', 'C', 'D', 'E'], // all categories that affect clicks
        'total_impressions'      => ['A', 'C', 'F'],      // visibility categories
        'pages_with_impressions' => ['A'],                 // ghost→impressions
        'stale_pages'            => ['_STALE'],            // special: boosts staleness weight
        'new_content_30d'        => [],                    // no refresh category — blog queue handles this
        'refreshed_this_month'   => ['_ALL'],              // special: increase overall throughput
    ];

    // Success rate by category (conservative estimates)
    private static $success_rates = [
        'A' => 0.25,  // Ghost — many truly dead
        'B' => 0.55,  // CTR fix — meta changes work faster
        'C' => 0.45,  // Near wins — content refresh can push to page 1
        'D' => 0.40,  // Declining — depends on cause
        'E' => 0.50,  // Visible/ignored — meta fix helps
        'F' => 0.30,  // Buried — hardest to move
    ];

    /**
     * Calculate capacity requirements for all active goals.
     * Returns array with per-goal breakdown and recommendations.
     */
    public static function calculate_capacity() {
        global $wpdb;
        $settings = seom_get_settings();
        $daily_limit = intval($settings['daily_limit']);
        $goals_table = $wpdb->prefix . 'seom_goals';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$goals_table}'") !== $goals_table) {
            return ['goals' => [], 'total_daily_needed' => 0, 'current_limit' => $daily_limit, 'recommended_limit' => $daily_limit];
        }

        $active_goals = $wpdb->get_results("SELECT * FROM {$goals_table} WHERE status = 'active' ORDER BY priority ASC");
        if (empty($active_goals)) {
            return ['goals' => [], 'total_daily_needed' => 0, 'current_limit' => $daily_limit, 'recommended_limit' => $daily_limit];
        }

        // Count available candidates per category
        $table = $wpdb->prefix . 'seom_page_metrics';
        $ghost_threshold = intval($settings['ghost_threshold']);
        $cat_counts = [
            'A' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} m INNER JOIN (SELECT post_id, MAX(date_collected) as md FROM {$table} GROUP BY post_id) l ON m.post_id=l.post_id AND m.date_collected=l.md WHERE m.impressions <= %d", $ghost_threshold)),
            'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'F' => 0,
        ];
        // Rough counts for other categories (approximate — full analysis is expensive)
        $cat_counts['C'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} m INNER JOIN (SELECT post_id, MAX(date_collected) as md FROM {$table} GROUP BY post_id) l ON m.post_id=l.post_id AND m.date_collected=l.md WHERE m.avg_position >= %d AND m.avg_position <= %d AND m.impressions >= %d", $settings['near_win_min_pos'], $settings['near_win_max_pos'], $settings['near_win_min_impressions']));
        $cat_counts['F'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} m INNER JOIN (SELECT post_id, MAX(date_collected) as md FROM {$table} GROUP BY post_id) l ON m.post_id=l.post_id AND m.date_collected=l.md WHERE m.avg_position > 20 AND m.impressions >= %d", $settings['buried_min_impressions']));

        $goal_details = [];
        $total_daily_needed = 0;

        foreach ($active_goals as $g) {
            $baseline = floatval($g->baseline_value);
            $current = floatval($g->current_value);
            $target = floatval($g->target_value);
            $days_left = max(1, ceil((strtotime($g->deadline) - time()) / 86400));
            $start = $g->start_date ?: substr($g->created_at, 0, 10);
            $total_days = max(1, (strtotime($g->deadline) - strtotime($start)) / 86400);
            $days_elapsed = max(0, (time() - strtotime($start)) / 86400);
            $time_pct = min(100, round(($days_elapsed / $total_days) * 100));

            // Calculate target number
            if ($g->target_type === 'percent') {
                $change_needed = $baseline * ($target / 100);
                $target_num = ($g->direction === 'reduce') ? $baseline - $change_needed : $baseline + $change_needed;
            } else {
                $target_num = $target;
            }

            // How much more change is needed
            $remaining_change = ($g->direction === 'reduce')
                ? max(0, $current - $target_num)
                : max(0, $target_num - $current);

            // Progress
            $total_change = abs($target_num - $baseline);
            $actual_change = ($g->direction === 'reduce') ? $baseline - $current : $current - $baseline;
            $progress = $total_change > 0 ? min(100, max(0, ($actual_change / $total_change) * 100)) : 0;

            // Urgency multiplier based on progress vs time
            $urgency = 1.0;
            $urgency_label = 'on_track';
            if ($progress < $time_pct - 50) { $urgency = 2.0; $urgency_label = 'critical'; }
            elseif ($progress < $time_pct - 25) { $urgency = 1.5; $urgency_label = 'behind'; }
            elseif ($progress < $time_pct - 10) { $urgency = 1.25; $urgency_label = 'slightly_behind'; }
            elseif ($progress >= 100) { $urgency = 0.25; $urgency_label = 'completed'; }

            // Map to categories and estimate required refreshes
            $categories = self::$goal_category_map[$g->metric] ?? [];
            $avg_success = 0.35;
            if (!empty($categories) && $categories[0] !== '_STALE' && $categories[0] !== '_ALL') {
                $rates = array_map(function($c) { return self::$success_rates[$c] ?? 0.35; }, $categories);
                $avg_success = array_sum($rates) / count($rates);
            }

            // Metrics are either page-count (1 refresh per page) or aggregate (indirect impact).
            // Page-count: ghost_pages, page1_pages, page2_pages, pages_with_impressions, stale_pages, refreshed_this_month
            // Aggregate: total_clicks, total_impressions, avg_position, avg_ctr, new_content_30d
            $page_count_metrics = ['ghost_pages', 'page1_pages', 'page2_pages', 'pages_with_impressions', 'stale_pages', 'refreshed_this_month'];

            if (in_array($g->metric, $page_count_metrics)) {
                // Each refresh can fix ~1 page at the success rate
                $refreshes_needed = $remaining_change > 0 ? ceil($remaining_change / max(0.01, $avg_success)) : 0;
            } else {
                // Aggregate metrics: estimate how many refreshes move the needle.
                // These are site-wide metrics — refreshing one page doesn't add 100 impressions to the total,
                // it improves THAT page's performance which contributes to the aggregate over time.
                // We estimate the total refreshes needed to move the site-wide metric by the target amount.
                $refreshes_per_pct = [
                    'total_clicks'       => 15,    // ~15 refreshes to move total clicks by 1% of baseline
                    'total_impressions'  => 15,    // ~15 refreshes to move total impressions by 1% of baseline
                    'avg_position'       => 20,    // ~20 refreshes to improve avg position by 1 spot
                    'avg_ctr'            => 10,    // ~10 refreshes to improve avg CTR by 0.1%
                    'new_content_30d'    => 0,     // blog queue, not refreshes — no capacity needed
                ];
                $per_unit = $refreshes_per_pct[$g->metric] ?? 10;
                if ($g->metric === 'new_content_30d' || $per_unit === 0) {
                    $refreshes_needed = 0;
                } elseif ($g->metric === 'avg_position') {
                    // remaining_change is in position points
                    $refreshes_needed = ceil($remaining_change * $per_unit);
                } elseif ($g->metric === 'avg_ctr') {
                    // remaining_change is in CTR percentage points
                    $refreshes_needed = ceil($remaining_change * 10 * $per_unit); // 0.1% units
                } else {
                    // clicks/impressions: convert remaining to % of baseline, then multiply
                    $pct_remaining = $baseline > 0 ? ($remaining_change / $baseline) * 100 : 0;
                    $refreshes_needed = ceil($pct_remaining * $per_unit);
                }
            }

            $daily_rate_needed = $days_left > 0 ? round($refreshes_needed / $days_left, 1) : $refreshes_needed;

            // Priority weight: P1=5, P2=4, P3=3, P4=2, P5=1
            $priority_weight = max(1, 6 - intval($g->priority));

            $total_daily_needed += $daily_rate_needed;

            $goal_details[] = [
                'id'                => $g->id,
                'metric'            => $g->metric,
                'direction'         => $g->direction,
                'priority'          => intval($g->priority),
                'priority_weight'   => $priority_weight,
                'categories'        => $categories,
                'baseline'          => $baseline,
                'current'           => $current,
                'target_num'        => round($target_num, 1),
                'remaining_change'  => round($remaining_change, 1),
                'progress'          => round($progress, 1),
                'time_pct'          => round($time_pct, 1),
                'days_left'         => $days_left,
                'urgency'           => $urgency,
                'urgency_label'     => $urgency_label,
                'success_rate'      => round($avg_success, 2),
                'refreshes_needed'  => $refreshes_needed,
                'daily_rate_needed' => $daily_rate_needed,
                'feasible'          => $daily_rate_needed <= $daily_limit,
            ];
        }

        $limit_max = intval($settings['limit_max'] ?? 50);
        $recommended = min($limit_max, max($daily_limit, ceil($total_daily_needed * 1.2))); // 20% buffer, capped at configured max

        return [
            'goals'               => $goal_details,
            'cat_counts'          => $cat_counts,
            'total_daily_needed'  => round($total_daily_needed, 1),
            'current_limit'       => $daily_limit,
            'recommended_limit'   => $recommended,
            'sufficient'          => $total_daily_needed <= $daily_limit,
        ];
    }

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

        // Pre-check: is there an active stale_pages goal?
        $goals_t = $wpdb->prefix . 'seom_goals';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$goals_t}'") === $goals_t) {
            $stale_goal = $wpdb->get_var("SELECT COUNT(*) FROM {$goals_t} WHERE status = 'active' AND metric = 'stale_pages'");
            self::$boost_staleness = ($stale_goal > 0);
        }

        // Bulk-load last refresh info from history (date + type of most recent refresh per post)
        $refresh_history_map = []; // post_id => ['date' => ..., 'type' => ...]
        $history_rows = $wpdb->get_results(
            "SELECT h.post_id, h.refresh_date, h.refresh_type
             FROM {$wpdb->prefix}seom_refresh_history h
             INNER JOIN (
                 SELECT post_id, MAX(id) as max_id
                 FROM {$wpdb->prefix}seom_refresh_history
                 GROUP BY post_id
             ) latest ON h.id = latest.max_id"
        );
        foreach ($history_rows as $hr) {
            $refresh_history_map[$hr->post_id] = [
                'date' => $hr->refresh_date,
                'type' => $hr->refresh_type,
            ];
        }

        // Also track if a post has EVER had a meta_only refresh (for escalation)
        $had_meta_map = array_flip($wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->prefix}seom_refresh_history WHERE refresh_type = 'meta_only'"
        ));

        // Bulk-load posts already in active queue
        $queued_ids_map = array_flip($wpdb->get_col(
            "SELECT post_id FROM {$wpdb->prefix}seom_refresh_queue WHERE status IN ('pending', 'processing')"
        ));

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

            // Skip posts published less than 90 days ago — new content needs time
            // to be indexed and ranked before we can evaluate its performance.
            $post_date = get_post_field('post_date', $post_id);
            if ($post_date && strtotime($post_date) > strtotime('-90 days')) continue;

            // Note: Posts with shortcodes (e.g., practice tests) are no longer excluded.
            // The Blog Refresher extracts shortcodes before AI processing and
            // prepends them back to the content after generation.

            // Protect top performers — pages whose CTR meets position benchmarks
            $clicks      = intval($m->clicks);
            $impressions = intval($m->impressions);
            $ctr         = floatval($m->ctr);
            $position    = floatval($m->avg_position);

            if (self::is_top_performer($clicks, $impressions, $ctr, $position)) {
                continue; // CTR is healthy for this position — don't risk breaking it
            }

            // Check cooldown — use BOTH post meta AND refresh history (whichever is more recent)
            $last_refresh_meta = get_post_meta($post_id, 'last_page_refresh', true);
            $hist = $refresh_history_map[$post_id] ?? null;
            $last_refresh_history = $hist ? $hist['date'] : null;
            $last_refresh_type = $hist ? $hist['type'] : null;

            $last_refresh = null;
            if ($last_refresh_meta && $last_refresh_history) {
                $last_refresh = max($last_refresh_meta, $last_refresh_history);
            } else {
                $last_refresh = $last_refresh_meta ?: $last_refresh_history;
            }

            if ($last_refresh) {
                // Meta-only refreshes get a shorter cooldown (30 days) — if the title/description
                // change didn't improve CTR within 2-4 weeks, it won't. Allow full refresh sooner.
                $meta_cooldown_date = date('Y-m-d', strtotime('-30 days'));

                if ($last_refresh_type === 'meta_only') {
                    // Short cooldown for meta-only: skip if within 30 days
                    if ($last_refresh >= $meta_cooldown_date) continue;
                } else {
                    // Full cooldown for full refreshes
                    if ($last_refresh >= $cooldown_date) continue;
                }
            }

            // Check if already in active queue (using bulk-loaded map)
            if (isset($queued_ids_map[$post_id])) continue;

            // Categorize the page
            $category = self::categorize($m, $prev_metrics[$post_id] ?? null, $settings);
            if (!$category) continue;

            // Score it
            $score = self::score($m, $prev_metrics[$post_id] ?? null, $category, $last_refresh, $settings);

            // Determine refresh type — default meta_only for B/E, full for everything else
            $refresh_type = in_array($category, ['B', 'E']) ? 'meta_only' : 'full';

            // Meta escalation: if this post had a previous meta_only refresh and is back
            // in the queue, the meta fix didn't move the needle — escalate to full refresh
            if ($refresh_type === 'meta_only' && isset($had_meta_map[$post_id])) {
                $refresh_type = 'full';
            }

            $candidates[] = [
                'post_id'        => $post_id,
                'post_type'      => get_post_type($post_id),
                'category'       => $category,
                'priority_score' => $score,
                'refresh_type'   => $refresh_type,
            ];
        }

        // Calculate goal-aware capacity and apply urgency multipliers
        $capacity = self::calculate_capacity();
        $goal_urgency_map = []; // category => highest urgency multiplier from goals
        $has_stale_goal = false;

        foreach ($capacity['goals'] as $gd) {
            if (in_array('_STALE', $gd['categories'])) $has_stale_goal = true;
            foreach ($gd['categories'] as $gc) {
                if ($gc[0] === '_') continue; // skip special markers
                $existing = $goal_urgency_map[$gc] ?? 1.0;
                $goal_urgency_map[$gc] = max($existing, $gd['urgency'] * $gd['priority_weight'] / 3);
            }
        }

        // Apply goal urgency as a tiebreaker bonus (keeps scores in 0-100 range)
        // Adds up to +15 points for highest urgency goals — enough to reorder within a category
        // but not enough to make a weak candidate outscore a strong one from another category
        foreach ($candidates as &$c) {
            $multiplier = $goal_urgency_map[$c['category']] ?? 1.0;
            $bonus = min(15, ($multiplier - 1.0) * 10); // 1.0=+0, 1.5=+5, 2.0=+10, 2.5=+15
            $c['priority_score'] = min(100, round($c['priority_score'] + $bonus, 2));
        }
        unset($c);

        // Determine effective daily limit (goal-adaptive if configured)
        $daily_limit = intval($settings['daily_limit']);
        $limit_mode = $settings['limit_mode'] ?? 'fixed';
        $limit_max = intval($settings['limit_max'] ?? $daily_limit);

        if ($limit_mode === 'adaptive' && !empty($capacity['goals'])) {
            $daily_limit = min($limit_max, max($daily_limit, $capacity['recommended_limit']));
        } elseif ($limit_mode === 'burst' && !empty($capacity['goals'])) {
            // Burst only if any goal is behind
            $any_behind = false;
            foreach ($capacity['goals'] as $gd) {
                if ($gd['urgency'] > 1.0) { $any_behind = true; break; }
            }
            if ($any_behind) {
                $daily_limit = min($limit_max, ceil($daily_limit * 1.5));
            }
        }

        // Goal-weighted queue allocation
        $to_queue = self::goal_weighted_queue($candidates, $daily_limit, $capacity['goals']);
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
     * Categorize a page into A/B/C/D/E/F or null (not underperforming).
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

        // Category B: CTR Fix (ranks decently, good impressions, CTR below position benchmark)
        // A position-1 page should have ~30% CTR; position 5 should have ~7%.
        // Flag if CTR is below 50% of expected for the position.
        if ($position > 0 && $position <= 15
            && $impressions >= $settings['ctr_fix_min_impressions']) {
            $expected = self::expected_ctr($position);
            if ($expected > 0 && $ctr < ($expected * 0.5)) {
                return 'B';
            }
        }

        // Category C: Near Wins (page 2, decent impressions)
        if ($position >= $settings['near_win_min_pos']
            && $position <= $settings['near_win_max_pos']
            && $impressions >= $settings['near_win_min_impressions']) {
            return 'C';
        }

        // Category F: Buried Potential (page 3+, Google knows the page but ranks it poorly)
        // These have impressions so Google considers them relevant — content refresh can unlock them
        if ($position > 20 && $impressions >= $settings['buried_min_impressions']) {
            return 'F';
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
    private static $boost_staleness = false;

    private static function score($metrics, $prev, $category, $last_refresh, $settings) {
        $opportunity = self::opportunity_score($metrics, $category);
        $momentum    = self::momentum_score($metrics, $prev);
        $quick_win   = self::quick_win_score($category, floatval($metrics->avg_position));
        $staleness   = self::staleness_score($last_refresh);

        // If there's an active "reduce stale pages" goal, boost staleness weight
        if (self::$boost_staleness) {
            return round(
                ($opportunity * 0.25) +
                ($momentum * 0.20) +
                ($quick_win * 0.20) +
                ($staleness * 0.35),
                2
            );
        }

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

        // Category F: Buried Potential — Google shows it but ranks poorly
        // Higher impressions = more relevance signal = bigger opportunity
        if ($category === 'F') {
            if ($impressions >= 200) return 75;
            if ($impressions >= 100) return 65;
            return 55;
        }

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
            case 'F': return 50;  // Buried — needs full refresh, more effort but high upside
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

    /**
     * Build a goal-weighted queue.
     *
     * Instead of equal round-robin, allocates slots proportional to
     * active goal priorities and urgency. Falls back to balanced
     * round-robin if no goals are active.
     *
     * @param array $candidates All scored candidates
     * @param int   $limit      Daily limit (may be adjusted by adaptive mode)
     * @param array $goal_details From calculate_capacity()
     * @return array Selected candidates for the queue
     */
    private static function goal_weighted_queue($candidates, $limit, $goal_details = []) {
        if (empty($candidates)) return [];

        // Group by category, sorted by score within each group
        $groups = [];
        foreach ($candidates as $c) {
            $groups[$c['category']][] = $c;
        }
        foreach ($groups as $cat => &$items) {
            usort($items, function ($a, $b) {
                return $b['priority_score'] <=> $a['priority_score'];
            });
        }
        unset($items);

        $all_cats = array_keys($groups);

        // If no goals, fall back to equal round-robin
        if (empty($goal_details)) {
            return self::round_robin_pick($groups, $all_cats, $limit);
        }

        // ── Step 1: Calculate slot allocation per category based on goals ──
        $cat_weights = array_fill_keys($all_cats, 1); // base weight of 1 each

        foreach ($goal_details as $gd) {
            if ($gd['urgency_label'] === 'completed') continue; // goal already met
            foreach ($gd['categories'] as $gc) {
                if ($gc[0] === '_') continue;
                if (!isset($cat_weights[$gc])) continue;
                // Weight = priority_weight × urgency × daily_rate_needed (capped)
                $boost = $gd['priority_weight'] * $gd['urgency'] * max(0.5, min(3, $gd['daily_rate_needed']));
                $cat_weights[$gc] += $boost;
            }
        }

        // ── Step 2: Convert weights to slot counts ──
        $total_weight = array_sum($cat_weights);
        $slot_allocation = [];
        $allocated = 0;

        foreach ($cat_weights as $cat => $weight) {
            // Every category gets at least 1 slot (if it has candidates)
            $slots = max(1, round(($weight / $total_weight) * $limit));
            // Can't allocate more than available candidates
            $slots = min($slots, count($groups[$cat] ?? []));
            $slot_allocation[$cat] = $slots;
            $allocated += $slots;
        }

        // Distribute any remaining slots to highest-weight categories with remaining candidates
        $remaining = $limit - $allocated;
        if ($remaining > 0) {
            arsort($cat_weights);
            foreach ($cat_weights as $cat => $w) {
                if ($remaining <= 0) break;
                $available = count($groups[$cat] ?? []) - $slot_allocation[$cat];
                if ($available > 0) {
                    $give = min($remaining, $available);
                    $slot_allocation[$cat] += $give;
                    $remaining -= $give;
                }
            }
        }

        // ── Step 3: Pick top N from each category per allocation ──
        $queue = [];
        foreach ($slot_allocation as $cat => $slots) {
            if (!isset($groups[$cat])) continue;
            for ($i = 0; $i < $slots && $i < count($groups[$cat]); $i++) {
                $queue[] = $groups[$cat][$i];
            }
        }

        // Sort final queue by score descending for processing order
        usort($queue, function ($a, $b) {
            return $b['priority_score'] <=> $a['priority_score'];
        });

        return array_slice($queue, 0, $limit);
    }

    /**
     * Simple round-robin fallback when no goals are active.
     */
    private static function round_robin_pick($groups, $category_order, $limit) {
        $queue = [];
        $pointers = array_fill_keys($category_order, 0);

        while (count($queue) < $limit) {
            $picked = false;
            foreach ($category_order as $cat) {
                if (count($queue) >= $limit) break;
                if ($pointers[$cat] < count($groups[$cat])) {
                    $queue[] = $groups[$cat][$pointers[$cat]];
                    $pointers[$cat]++;
                    $picked = true;
                }
            }
            if (!$picked) break;
        }

        return $queue;
    }
}
