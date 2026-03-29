<?php
/**
 * Keyword Researcher
 *
 * Collects site-wide keyword data from GSC, detects content gaps,
 * keyword cannibalization, rising/declining queries, and computes
 * opportunity scores. Also fetches Google Autocomplete suggestions.
 */

if (!defined('ABSPATH')) exit;

class SEOM_Keyword_Researcher {

    /**
     * Ensure keyword tables exist.
     */
    private static function ensure_tables() {
        global $wpdb;

        $kw_table = $wpdb->prefix . 'seom_keywords';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$kw_table}'") === $kw_table) return;

        // Tables don't exist — create them
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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

    /**
     * Collect site-wide keywords from GSC and store in the keywords table.
     * Called via cron or manual trigger.
     */
    public static function collect() {
        // Ensure tables exist (in case plugin was updated without reactivation)
        self::ensure_tables();

        $settings = seom_get_settings();
        if (empty($settings['gsc_credentials_json']) || empty($settings['gsc_property_url'])) {
            return new WP_Error('not_configured', 'GSC not configured.');
        }

        $client = new SEOM_GSC_Client($settings['gsc_credentials_json'], $settings['gsc_property_url']);

        // Get current and previous period queries for trend detection
        $trends = $client->get_query_trends(28);
        if (is_wp_error($trends)) return $trends;

        global $wpdb;
        $table = $wpdb->prefix . 'seom_keywords';
        $today = date('Y-m-d');

        // Clear today's data if re-running
        $wpdb->delete($table, ['date_collected' => $today]);

        // Get all existing focus keywords and post titles for gap detection
        $focus_keywords = $wpdb->get_results("
            SELECT post_id, meta_value as keyword FROM {$wpdb->postmeta}
            WHERE meta_key = 'rank_math_focus_keyword' AND meta_value != ''
        ");
        $kw_to_post = [];
        foreach ($focus_keywords as $fk) {
            $kw_to_post[strtolower($fk->keyword)] = intval($fk->post_id);
        }

        $post_titles = $wpdb->get_results("
            SELECT ID, LOWER(post_title) as title FROM {$wpdb->posts}
            WHERE post_type IN ('product', 'post') AND post_status = 'publish'
        ");
        $title_map = [];
        foreach ($post_titles as $pt) {
            $title_map[$pt->title] = intval($pt->ID);
        }

        // Get page-query mapping for cannibalization detection
        $page_queries_raw = $wpdb->get_col("
            SELECT top_queries FROM {$wpdb->prefix}seom_page_metrics
            WHERE top_queries IS NOT NULL AND top_queries != ''
            AND date_collected = (SELECT MAX(date_collected) FROM {$wpdb->prefix}seom_page_metrics)
        ");
        $query_to_pages = [];
        foreach ($page_queries_raw as $json) {
            $queries = json_decode($json, true);
            if (!is_array($queries)) continue;
            foreach ($queries as $q) {
                $qtext = strtolower($q['query'] ?? '');
                if (!$qtext) continue;
                if (!isset($query_to_pages[$qtext])) $query_to_pages[$qtext] = [];
                // We don't have the post_id here, but we track the count
                $query_to_pages[$qtext][] = 1;
            }
        }

        $inserted = 0;
        foreach ($trends as $query => $data) {
            $cur = $data['current'];
            $prev = $data['previous'];

            // Skip very low-volume queries
            if ($cur['impressions'] < 5 && $prev['impressions'] < 5) continue;

            // Check if any post targets this keyword
            $q_lower = strtolower($query);
            $mapped_post = $kw_to_post[$q_lower] ?? null;

            // If not mapped by focus keyword, try matching against titles
            if (!$mapped_post) {
                foreach ($title_map as $title => $pid) {
                    if (strpos($title, $q_lower) !== false || strpos($q_lower, $title) !== false) {
                        $mapped_post = $pid;
                        break;
                    }
                }
            }

            $is_gap = (!$mapped_post && $cur['impressions'] >= 20) ? 1 : 0;

            // Cannibalization: check if multiple pages rank for this query
            $cannibal_count = count($query_to_pages[$q_lower] ?? []);
            $cannibalization_ids = $cannibal_count > 1 ? json_encode(array_fill(0, $cannibal_count, true)) : null;

            // Opportunity score
            $opp = self::compute_opportunity($cur, $data['trend_pct'], $is_gap);

            $wpdb->insert($table, [
                'keyword'              => mb_substr($query, 0, 255),
                'source'               => 'gsc',
                'impressions'          => $cur['impressions'],
                'clicks'               => $cur['clicks'],
                'avg_position'         => $cur['position'],
                'ctr'                  => $cur['ctr'],
                'impressions_prev'     => $prev['impressions'],
                'trend_direction'      => $data['direction'],
                'trend_pct'            => $data['trend_pct'],
                'opportunity_score'    => $opp,
                'mapped_post_id'       => $mapped_post,
                'cannibalization_ids'   => $cannibalization_ids,
                'is_content_gap'       => $is_gap,
                'date_collected'       => $today,
            ]);
            $inserted++;
        }

        update_option('seom_last_keyword_collect', current_time('mysql'));

        return [
            'keywords_collected' => $inserted,
            'total_queries'      => count($trends),
            'content_gaps'       => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_content_gap = 1 AND date_collected = '{$today}'"),
            'rising'             => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE trend_direction = 'rising' AND date_collected = '{$today}'"),
        ];
    }

    /**
     * Compute opportunity score for a keyword (0-100).
     */
    private static function compute_opportunity($metrics, $trend_pct, $is_gap) {
        $impressions = $metrics['impressions'];
        $position = $metrics['position'];
        $ctr = $metrics['ctr'];

        // Impression volume weight (0-30)
        $vol = min(30, ($impressions / 500) * 30);

        // Position gap weight (0-30) — higher score for near-page-1 keywords
        $pos_score = 0;
        if ($position >= 11 && $position <= 20) $pos_score = 30;       // Page 2 — best opportunity
        elseif ($position >= 21 && $position <= 30) $pos_score = 20;
        elseif ($position >= 4 && $position <= 10) $pos_score = 15;    // Page 1 but not top 3
        elseif ($position >= 1 && $position <= 3) $pos_score = 5;      // Already top — low opp
        elseif ($position > 30) $pos_score = 10;

        // CTR gap weight (0-20) — how much CTR could improve
        $expected_ctr = max(1, 30 - ($position * 2));
        $ctr_gap = max(0, min(20, ($expected_ctr - $ctr) * 2));

        // Trend weight (0-15)
        $trend = 0;
        if ($trend_pct > 50) $trend = 15;
        elseif ($trend_pct > 20) $trend = 10;
        elseif ($trend_pct > 0) $trend = 5;

        // Content gap bonus (0-5)
        $gap_bonus = $is_gap ? 5 : 0;

        return round(min(100, $vol + $pos_score + $ctr_gap + $trend + $gap_bonus), 2);
    }

    /**
     * Get target keywords for a specific post from GSC data.
     * Used by content generation pipeline.
     *
     * @param int $post_id
     * @return array ['primary' => string, 'secondary' => [strings]]
     */
    public static function get_target_keywords($post_id) {
        global $wpdb;

        // First check if we have top_queries from GSC collection
        $top_queries_json = $wpdb->get_var($wpdb->prepare("
            SELECT top_queries FROM {$wpdb->prefix}seom_page_metrics
            WHERE post_id = %d AND top_queries IS NOT NULL AND top_queries != ''
            ORDER BY date_collected DESC LIMIT 1
        ", $post_id));

        $primary = '';
        $secondary = [];

        if ($top_queries_json) {
            $queries = json_decode($top_queries_json, true);
            if (is_array($queries) && !empty($queries)) {
                // Sort by impressions descending
                usort($queries, function ($a, $b) {
                    return ($b['impressions'] ?? 0) <=> ($a['impressions'] ?? 0);
                });

                $primary = $queries[0]['query'] ?? '';
                for ($i = 1; $i < min(count($queries), 5); $i++) {
                    $secondary[] = $queries[$i]['query'] ?? '';
                }
                $secondary = array_filter($secondary);
            }
        }

        // If no GSC data, check the keywords table for this post's mapped keywords
        if (empty($primary)) {
            $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$wpdb->prefix}seom_keywords");
            if ($latest_date) {
                $mapped = $wpdb->get_results($wpdb->prepare("
                    SELECT keyword, impressions FROM {$wpdb->prefix}seom_keywords
                    WHERE mapped_post_id = %d AND date_collected = %s
                    ORDER BY opportunity_score DESC LIMIT 5
                ", $post_id, $latest_date));

                if (!empty($mapped)) {
                    $primary = $mapped[0]->keyword;
                    for ($i = 1; $i < count($mapped); $i++) {
                        $secondary[] = $mapped[$i]->keyword;
                    }
                }
            }
        }

        // Fallback to existing RankMath keyword
        if (empty($primary)) {
            $primary = get_post_meta($post_id, 'rank_math_focus_keyword', true) ?: '';
        }

        // Enrich secondary keywords with autocomplete suggestions for the primary keyword
        if (!empty($primary) && count($secondary) < 5) {
            $sug_table = $wpdb->prefix . 'seom_keyword_suggestions';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$sug_table}'") === $sug_table) {
                $suggestions = $wpdb->get_col($wpdb->prepare(
                    "SELECT suggestion FROM {$sug_table} WHERE seed_keyword = %s LIMIT 5", $primary
                ));
                foreach ($suggestions as $sug) {
                    if (!in_array(strtolower($sug), array_map('strtolower', $secondary)) && strtolower($sug) !== strtolower($primary)) {
                        $secondary[] = $sug;
                    }
                    if (count($secondary) >= 8) break;
                }
            }
        }

        // Also pull rising keywords mapped to this post from the keywords table
        $rising = [];
        $kw_table = $wpdb->prefix . 'seom_keywords';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$kw_table}'") === $kw_table) {
            $latest_kw_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$kw_table}");
            if ($latest_kw_date) {
                $rising = $wpdb->get_col($wpdb->prepare("
                    SELECT keyword FROM {$kw_table}
                    WHERE mapped_post_id = %d AND date_collected = %s AND trend_direction = 'rising'
                    ORDER BY trend_pct DESC LIMIT 3
                ", $post_id, $latest_kw_date));
            }
        }

        return [
            'primary'   => $primary,
            'secondary' => array_slice(array_unique($secondary), 0, 8),
            'rising'    => $rising,
            'source'    => $top_queries_json ? 'gsc' : (!empty($primary) ? 'rankmath' : 'none'),
        ];
    }

    /**
     * Fetch Google Autocomplete suggestions for a seed keyword.
     *
     * @param string $seed
     * @return array List of suggestion strings
     */
    public static function get_autocomplete($seed) {
        $url = 'https://suggestqueries.google.com/complete/search?client=firefox&q=' . urlencode($seed);

        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'application/json, text/javascript, */*',
            ],
        ]);
        if (is_wp_error($response)) return [];

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data[1])) return [];

        return array_slice($data[1], 0, 10);
    }

    /**
     * Expand top keywords with autocomplete suggestions and store them.
     *
     * @param int $limit Number of top keywords to expand
     */
    public static function expand_with_autocomplete($limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'seom_keyword_suggestions';
        $today = date('Y-m-d');

        $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$wpdb->prefix}seom_keywords");
        if (!$latest_date) return ['expanded' => 0];

        $top_keywords = $wpdb->get_col($wpdb->prepare("
            SELECT keyword FROM {$wpdb->prefix}seom_keywords
            WHERE date_collected = %s AND impressions >= 20
            ORDER BY opportunity_score DESC
            LIMIT %d
        ", $latest_date, $limit));

        self::ensure_tables();

        $total = 0;
        $seeds_with_results = 0;
        $first_error = '';
        foreach ($top_keywords as $seed) {
            $suggestions = self::get_autocomplete($seed);
            if (!empty($suggestions)) $seeds_with_results++;
            foreach ($suggestions as $sug) {
                if (strtolower($sug) === strtolower($seed)) continue;
                $wpdb->replace($table, [
                    'seed_keyword'   => mb_substr($seed, 0, 255),
                    'suggestion'     => mb_substr($sug, 0, 255),
                    'source'         => 'autocomplete',
                    'date_collected' => $today,
                ]);
                $total++;
            }
            // If first 3 seeds all return empty, Google may be blocking — test and report
            if ($seeds_with_results === 0 && $total === 0 && array_search($seed, $top_keywords) >= 2 && empty($first_error)) {
                $test_url = 'https://suggestqueries.google.com/complete/search?client=firefox&q=' . urlencode($seed);
                $test_resp = wp_remote_get($test_url, ['timeout' => 10, 'headers' => ['User-Agent' => 'Mozilla/5.0']]);
                $first_error = is_wp_error($test_resp) ? $test_resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($test_resp) . ': ' . mb_substr(wp_remote_retrieve_body($test_resp), 0, 200);
            }
            usleep(500000); // 500ms delay between requests
        }

        return [
            'expanded'           => $total,
            'seeds'              => count($top_keywords),
            'seeds_with_results' => $seeds_with_results,
            'debug_error'        => $first_error ?: null,
        ];
    }
}
