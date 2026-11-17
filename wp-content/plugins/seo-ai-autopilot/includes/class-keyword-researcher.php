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
            status varchar(10) NOT NULL DEFAULT 'active',
            first_seen date DEFAULT NULL,
            last_seen date DEFAULT NULL,
            date_collected date NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY keyword_source (keyword(191), source),
            KEY opportunity_score (opportunity_score),
            KEY mapped_post_id (mapped_post_id),
            KEY is_content_gap (is_content_gap),
            KEY trend_direction (trend_direction),
            KEY status (status),
            KEY last_seen (last_seen)
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
     * Batched: Phase 0 = fetch GSC + cache, Phase 1+ = process keywords in batches.
     */
    public static function collect($batch_page = 0) {
        self::ensure_tables();

        global $wpdb;
        $table = $wpdb->prefix . 'seom_keywords';
        $today = date('Y-m-d');
        $batch_size = 200;

        // Migrate schema if needed
        $col = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'status'");
        if (!$col) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN status varchar(10) NOT NULL DEFAULT 'active' AFTER is_content_gap");
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN first_seen date DEFAULT NULL AFTER status");
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN last_seen date DEFAULT NULL AFTER first_seen");
            $idx = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name = 'keyword_source'");
            if (!$idx) {
                $wpdb->query("DELETE k1 FROM {$table} k1 INNER JOIN {$table} k2 WHERE k1.id < k2.id AND k1.keyword = k2.keyword AND k1.source = k2.source");
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY keyword_source (keyword(191), source)");
            }
            $wpdb->query("UPDATE {$table} SET first_seen = date_collected, last_seen = date_collected WHERE first_seen IS NULL");
        }

        // Phase 0: Fetch GSC data and cache it
        if ($batch_page === 0) {
            $settings = seom_get_settings();
            if (empty($settings['gsc_credentials_json']) || empty($settings['gsc_property_url'])) {
                return new WP_Error('not_configured', 'GSC not configured.');
            }

            $client = new SEOM_GSC_Client($settings['gsc_credentials_json'], $settings['gsc_property_url']);
            $trends = $client->get_query_trends(28);
            if (is_wp_error($trends)) return $trends;

            // Filter low-volume and serialize for cache
            $filtered = [];
            foreach ($trends as $query => $data) {
                if ($data['current']['impressions'] < 5 && $data['previous']['impressions'] < 5) continue;
                $filtered[$query] = $data;
            }

            set_transient('seom_kw_trends_cache', $filtered, 3600);

            return [
                'phase'          => 'gsc_fetched',
                'total_queries'  => count($filtered),
                'total_batches'  => ceil(count($filtered) / $batch_size),
            ];
        }

        // Phase 1+: Process a batch of keywords
        $trends = get_transient('seom_kw_trends_cache');
        if (!$trends || !is_array($trends)) {
            return new WP_Error('no_cache', 'Keyword cache expired. Start collection again.');
        }

        // Slice the batch
        $all_queries = array_keys($trends);
        $offset = ($batch_page - 1) * $batch_size;
        $batch_queries = array_slice($all_queries, $offset, $batch_size);
        $is_last_batch = ($offset + count($batch_queries)) >= count($all_queries);

        // Get all existing focus keywords and post titles for gap detection (cached per request)
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
        foreach ($batch_queries as $query) {
            $data = $trends[$query];
            $cur = $data['current'];
            $prev = $data['previous'];

            // Check if any post targets this keyword
            $q_lower = strtolower($query);
            $mapped_post = $kw_to_post[$q_lower] ?? null;

            // If not mapped by exact focus keyword, try fuzzy matching
            if (!$mapped_post) {
                // 1. Check if focus keyword words overlap significantly with query
                $q_words = array_filter(explode(' ', $q_lower), function($w) { return strlen($w) > 2; });
                foreach ($kw_to_post as $kw => $pid) {
                    $kw_words = array_filter(explode(' ', $kw), function($w) { return strlen($w) > 2; });
                    if (empty($kw_words)) continue;
                    $overlap = count(array_intersect($q_words, $kw_words));
                    // If 60%+ of the keyword's significant words match, it's the same topic
                    if ($overlap >= max(2, ceil(count($kw_words) * 0.6))) {
                        $mapped_post = $pid;
                        break;
                    }
                }
            }

            // 2. If still not mapped, try word-overlap matching against post titles
            if (!$mapped_post) {
                $q_words = array_filter(explode(' ', $q_lower), function($w) { return strlen($w) > 2; });
                $best_overlap = 0;
                $best_pid = null;
                foreach ($title_map as $title => $pid) {
                    $t_words = array_filter(explode(' ', $title), function($w) { return strlen($w) > 2; });
                    if (empty($t_words)) continue;
                    $overlap = count(array_intersect($q_words, $t_words));
                    // Need at least 2 significant words in common, and 50%+ of query words match
                    if ($overlap >= 2 && $overlap > $best_overlap && $overlap >= ceil(count($q_words) * 0.5)) {
                        $best_overlap = $overlap;
                        $best_pid = $pid;
                    }
                }
                if ($best_pid) $mapped_post = $best_pid;
            }

            $is_gap = (!$mapped_post && $cur['impressions'] >= 20) ? 1 : 0;

            // Cannibalization: check if multiple pages rank for this query
            $cannibal_count = count($query_to_pages[$q_lower] ?? []);
            $cannibalization_ids = $cannibal_count > 1 ? json_encode(array_fill(0, $cannibal_count, true)) : null;

            // Opportunity score
            $opp = self::compute_opportunity($cur, $data['trend_pct'], $is_gap);

            $keyword_safe = mb_substr($query, 0, 255);
            // keyword tracked via last_seen date for lost detection

            // Check if keyword already exists
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, first_seen FROM {$table} WHERE keyword = %s AND source = 'gsc'",
                $keyword_safe
            ));

            if ($existing) {
                // Update existing record — preserve first_seen, update everything else
                $wpdb->update($table, [
                    'impressions'          => $cur['impressions'],
                    'clicks'               => $cur['clicks'],
                    'avg_position'         => $cur['position'],
                    'ctr'                  => $cur['ctr'],
                    'impressions_prev'     => $prev['impressions'],
                    'trend_direction'      => $data['direction'],
                    'trend_pct'            => $data['trend_pct'],
                    'opportunity_score'    => $opp,
                    'mapped_post_id'       => $mapped_post,
                    'cannibalization_ids'  => $cannibalization_ids,
                    'is_content_gap'       => $is_gap,
                    'status'               => 'active',
                    'last_seen'            => $today,
                    'date_collected'       => $today,
                ], ['id' => $existing->id]);
            } else {
                // Insert new keyword
                $wpdb->insert($table, [
                    'keyword'              => $keyword_safe,
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
                    'cannibalization_ids'  => $cannibalization_ids,
                    'is_content_gap'       => $is_gap,
                    'status'               => 'active',
                    'first_seen'           => $today,
                    'last_seen'            => $today,
                    'date_collected'       => $today,
                ]);
            }
            $inserted++;
        }

        // Only on the last batch: mark lost keywords and finalize
        if ($is_last_batch) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'lost'
                 WHERE source = 'gsc'
                 AND status = 'active'
                 AND last_seen < %s",
                $today
            ));

            update_option('seom_last_keyword_collect', current_time('mysql'));
            delete_transient('seom_kw_trends_cache');

            $lost_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'lost'");

            return [
                'phase'              => 'complete',
                'keywords_collected' => $inserted,
                'total_queries'      => count($all_queries),
                'content_gaps'       => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE is_content_gap = 1 AND status = 'active' AND last_seen = %s", $today)),
                'rising'             => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE trend_direction = 'rising' AND status = 'active' AND last_seen = %s", $today)),
                'lost'               => $lost_count,
            ];
        }

        return [
            'phase'     => 'processing',
            'batch'     => $batch_page,
            'processed' => count($batch_queries),
            'total'     => count($all_queries),
            'has_more'  => true,
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

        // CTR gap weight (0-20) — how much CTR could improve vs position benchmark
        $expected_ctr = SEOM_Analyzer::expected_ctr($position);
        $ctr_gap = ($expected_ctr > 0) ? max(0, min(20, (($expected_ctr - $ctr) / $expected_ctr) * 20)) : 0;

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
