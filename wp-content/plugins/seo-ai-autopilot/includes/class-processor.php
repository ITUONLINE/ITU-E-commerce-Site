<?php
/**
 * Processor
 *
 * Executes content refreshes by calling AI Product Manager functions.
 * Handles daily limits, staggered processing, and history tracking.
 */

if (!defined('ABSPATH')) exit;

class SEOM_Processor {

    /**
     * Start the daily processing cycle (called by cron at 6 AM).
     */
    public static function start_daily() {
        $settings = seom_get_settings();
        if (!$settings['enabled']) return;

        // Reset daily counter
        update_option('seom_daily_count_' . date('Y-m-d'), 0);

        // Run the worker inline in this cron process. process_next() now drains
        // the whole queue here (respecting the daily cap) — no wp-cron loopback
        // chain. This is the same proven approach the sibling Blog Queue plugin
        // uses for its nightly run.
        self::process_next();
    }

    /**
     * Process the next batch of items in the queue (called by chained cron events).
     *
     * Self-sustaining batch loop:
     *   - Acquires a lock so concurrent cron firings don't race the queue
     *   - Processes meta items first, then full items, in a loop until either
     *     the time budget is hit, the daily limit is hit, or the queue empties
     *   - On time-budget exit, schedules the next event for time() (immediately
     *     due) and fires spawn_cron + a non-blocking wp-cron.php request so the
     *     chain self-sustains without depending on user page visits or pollers
     */
    public static function process_next($ignore_daily_limit = false) {
        // WP fires hooked callbacks with the event's args; the legacy
        // 'seom_process_next' event passes none, so guard against a stray
        // non-bool sneaking in and flipping the daily cap off unintentionally.
        $ignore_daily_limit = ($ignore_daily_limit === true);
        $settings = seom_get_settings();

        // Tracing — write a single line per invocation so error_log shows
        // whether the worker is even being triggered and where it bails.
        // Cheap (one fputs to PHP's error stream); critical for diagnosing
        // "queue starts but nothing happens" issues.
        $trace = function ($where, $extra = '') {
            error_log('[SEO AI AutoPilot] process_next ' . $where . ($extra !== '' ? ' — ' . $extra : ''));
        };
        $trace('enter');

        // Check stop flag (manual Stop / Reset State both set seom_queue_stop).
        if (self::should_stop()) {
            $trace('bail', 'should_stop returned true');
            delete_option('seom_queue_stop');
            delete_transient('seom_queue_session');
            $state = get_option('seom_queue_processing', []);
            if ($state) {
                $state['status'] = 'stopped';
                update_option('seom_queue_processing', $state);
            }
            return;
        }

        // Allow processing if triggered manually (even if 'enabled' is off for auto-processing)
        $manual = get_option('seom_queue_processing', null);
        if (!$settings['enabled'] && !$manual) {
            $trace('bail', 'not enabled and not manual');
            return;
        }

        // Single-runner lock — prevents two cron firings from clobbering each other
        if (get_transient('seom_queue_lock')) {
            $trace('bail', 'queue_lock held');
            return;
        }
        set_transient('seom_queue_lock', time(), 30 * MINUTE_IN_SECONDS);
        $trace('locked, starting work');

        global $wpdb;

        // Long-running inline worker. We process the WHOLE queue in this one
        // process — one AI call at a time — until it drains, is stopped, or hits
        // the daily cap. There is deliberately NO wp-cron loopback and NO
        // self-rescheduling.
        //
        // History: the old design did one AI call per request and re-armed the
        // chain via spawn_cron()/wp-cron.php after every step. On this host that
        // loopback both (a) failed to fire (queue stalled) and (b) when it did
        // fire, re-spawned itself into multiple concurrent wp-cron.php requests
        // that each ran long AI calls — exhausting the PHP-FPM worker pool and
        // returning 504s site-wide. The sibling Blog Queue plugin avoids all of
        // this by running its batch inline after detaching the response, and it
        // works reliably nightly and on manual click. This mirrors that.
        //
        // Callers:
        //   • manual  → seom_start_queue_processing() acks the request,
        //               fastcgi_finish_request()s, then calls us (one detached process)
        //   • nightly → SEOM_Processor::start_daily() calls us inline in the cron process
        @set_time_limit(0);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);

        try {
            // Ensure the session transient exists so the UI can detect cron-driven processing.
            // The manual UI start handler creates this in advance; this fallback covers the
            // automated nightly cron path.
            if (!get_transient('seom_queue_session')) {
                $pending_now = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_queue WHERE status = 'pending'");
                if ($pending_now > 0) {
                    set_transient('seom_queue_session', [
                        'started_at'    => current_time('mysql'),
                        'total_initial' => $pending_now,
                        'completed'     => 0,
                        'failed'        => 0,
                        'current'       => null,
                        'source'        => $manual ? 'manual' : 'cron',
                    ], 6 * HOUR_IN_SECONDS);
                }
            }

            // Reset stuck items (>10 min in 'processing') back to pending for retry
            $wpdb->query("
                UPDATE {$wpdb->prefix}seom_refresh_queue
                SET status = 'pending', error_message = CONCAT('Auto-reset: stuck since ', started_at), started_at = NULL, completed_at = NULL
                WHERE status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            ");

            // Clean up completed items older than 24 hours
            $wpdb->query("
                DELETE FROM {$wpdb->prefix}seom_refresh_queue
                WHERE status = 'completed' AND completed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");

            $today_key = 'seom_daily_count_' . date('Y-m-d');

            // ── Inline drain loop ──────────────────────────────────────────────
            // Each iteration does ONE AI call via the proven process_one_step()
            // state machine (persisting progress in seom_bg_progress) and keeps
            // looping until the queue drains, a stop is requested, or the daily
            // cap is reached. No time-budget break, no handoff — this single
            // process owns the whole batch.
            $did_work = false;

            while (true) {
                // Refresh the runner lock so it never expires mid-batch (a full
                // queue can take much longer than the initial TTL).
                set_transient('seom_queue_lock', time(), 30 * MINUTE_IN_SECONDS);

                if (self::should_stop()) {
                    delete_option('seom_queue_stop');
                    self::release_queue_lock();
                    return;
                }

                // Only gate fresh items — never interrupt an item mid-progress.
                $in_progress = get_option('seom_bg_progress', null);
                if (!$in_progress) {
                    // Daily-limit gate, full refreshes only (meta-only items are
                    // exempt, matching the prior two-pass behaviour). If the cap is
                    // hit and no meta items remain, stop until tomorrow.
                    $count = (int) get_option($today_key, 0);
                    if (!$ignore_daily_limit && $count >= $settings['daily_limit']) {
                        $meta_pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_queue WHERE status = 'pending' AND refresh_type = 'meta_only'");
                        if ($meta_pending === 0) {
                            $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_queue WHERE status IN ('pending','processing')");
                            if ($remaining > 0) {
                                self::mark_session_stopped(
                                    'daily_limit',
                                    "Daily refresh limit of {$settings['daily_limit']} reached. {$remaining} item(s) remain in the queue and will resume tomorrow at the next scheduled run.",
                                    $remaining
                                );
                                delete_option('seom_queue_processing');
                                self::send_daily_summary($settings);
                                self::send_queue_stop_email('daily_limit', $remaining);
                            }
                            self::release_queue_lock();
                            return;
                        }
                        // else: meta items remain (exempt) — fall through so
                        // process_one_step picks one (it orders meta-only first).
                    }

                    // Queue fully drained → complete. (Session still intact here, so
                    // the completion email reports accurate counts.)
                    $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_queue WHERE status = 'pending'");
                    if ($pending === 0) {
                        self::mark_session_stopped('completed', 'Queue processing complete — all items refreshed.', 0);
                        delete_option('seom_queue_processing');
                        if ($did_work || $count > 0) self::send_daily_summary($settings);
                        self::send_queue_stop_email('completed', 0);
                        wp_schedule_single_event(time() + 300, 'seom_clear_queue_session');
                        self::release_queue_lock();
                        return;
                    }
                }

                // Do exactly ONE step (one AI call) of the current/next item.
                $result   = self::process_one_step();
                $did_work = true;
                $status   = is_array($result) ? ($result['status'] ?? '') : '';

                if ($status === 'stopped') {
                    self::release_queue_lock();
                    return;
                }
                if ($status === 'done') {
                    // Race: queue emptied between our pre-check and process_one_step,
                    // which already cleared session/options. Send completion notices.
                    self::send_daily_summary($settings);
                    self::send_queue_stop_email('completed', 0);
                    self::release_queue_lock();
                    return;
                }

                // Forward progress — loop straight into the next step. No time
                // budget, no handoff: this process drains the whole queue.
            }
        } catch (\Throwable $e) {
            error_log('[SEO AI AutoPilot] process_next exception: ' . $e->getMessage());
            self::release_queue_lock();
        }
    }

    /**
     * Release the queue runner lock.
     */
    private static function release_queue_lock() {
        delete_transient('seom_queue_lock');
    }

    /**
     * Check whether the worker should stop. Returns true ONLY when the explicit
     * seom_queue_stop flag is set. Both the manual "Stop" button and "Reset
     * State" write that flag (Reset State additionally tears down the session),
     * so honoring the flag alone covers every intentional stop.
     *
     * History note: this used to query wp_options directly to "bypass object
     * cache." That worked when transients lived in wp_options — but when a
     * persistent object cache is active (Redis / Memcached / W3 Total Cache
     * object cache / etc.), transients live in the CACHE, not wp_options,
     * and the direct query always returned empty. should_stop() then
     * incorrectly returned true on every call, the worker bailed at the top
     * of process_next, no items got processed, and the recovery loop fired
     * forever. The cache-busting below is what fixed that.
     *
     * Second root cause (this fix): even with cache-busting, treating a MISSING
     * seom_queue_session transient as "stop" made should_stop() a hair-trigger.
     * Any single cross-process read miss of that transient — object-cache
     * replication lag, a wp-cron loopback worker on a different php-fpm node, a
     * momentary eviction — made should_stop() return true at the very top of
     * process_next. That top check runs BEFORE the shutdown re-arm net is
     * registered AND it deletes the session, so one false positive killed the
     * cron chain permanently: Completed 0, "Next cron event NOT SCHEDULED",
     * auto-recover 3x, then a "Background Processing Stalled" email — while a
     * manual single-item refresh (which never calls should_stop) still worked.
     * The session check is therefore removed; the stop flag is written straight
     * to the options table on stop/reset and does not suffer that visibility
     * problem.
     */
    public static function should_stop() {
        // Drop the per-request memo cache so the next get_option() hits the
        // persistent backend. The 'alloptions' key is the bucket
        // wp_load_alloptions stuffs every autoloaded option into, which
        // includes options written via update_option without the third
        // 'autoload' argument.
        wp_cache_delete('seom_queue_stop', 'options');
        wp_cache_delete('alloptions',      'options');

        $stop_val = get_option('seom_queue_stop', false);
        if (!empty($stop_val) && $stop_val !== '0' && $stop_val !== 'false') {
            return true;
        }

        return false;
    }

    /**
     * Auto-recover the queue when the chain breaks (PHP timeout, fatal, dropped
     * cron event, etc.). Resets transient state and re-triggers the chain.
     * Used by the stale-detection path in seom_queue_status.
     */
    public static function auto_recover_queue() {
        global $wpdb;

        // Drop the runner lock so a new process_next can claim it
        delete_transient('seom_queue_lock');

        // Drop any lingering stop flag (only the manual stop endpoint should set this)
        delete_option('seom_queue_stop');

        // Drop any in-flight step progress (the previous worker died mid-step)
        delete_option('seom_bg_progress');

        // Reset items stuck in 'processing' back to pending so they get retried
        $wpdb->query("
            UPDATE {$wpdb->prefix}seom_refresh_queue
            SET status = 'pending',
                error_message = CONCAT('Auto-recovered from stalled background run at ', NOW()),
                started_at = NULL,
                completed_at = NULL
            WHERE status = 'processing'
        ");

        // Re-arm the chain — schedule immediately and fire cron
        wp_clear_scheduled_hook('seom_process_next');
        wp_schedule_single_event(time(), 'seom_process_next');
        self::trigger_cron_now();
    }

    /**
     * Fire WP cron without waiting — used to chain to the next process_next run.
     * Non-blocking so the calling PHP request can exit cleanly while the
     * receiving wp-cron.php request runs the next iteration in a fresh process.
     */
    public static function trigger_cron_now() {
        // spawn_cron handles the standard "trigger cron via wp-cron.php" path
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
        // Belt-and-suspenders: fire a direct non-blocking request to wp-cron.php
        wp_remote_post(site_url('/wp-cron.php?doing_wp_cron=' . microtime(true)), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => ['Cache-Control' => 'no-cache'],
        ]);
    }

    /**
     * Process ONE STEP of the next queue item (used by poll-based manual processing).
     * Uses step-based processing to avoid AJAX timeouts — each poll call does ONE AI call.
     * Returns the current state for the UI to display.
     */
    public static function process_one_step() {
        global $wpdb;

        // Check stop flag — bypasses object cache so cross-process stop signals are seen
        if (self::should_stop()) {
            delete_option('seom_queue_stop');
            delete_option('seom_queue_processing');
            delete_transient('seom_queue_session');
            return ['status' => 'stopped'];
        }

        // Reset stuck items (5 min timeout)
        $wpdb->query("
            UPDATE {$wpdb->prefix}seom_refresh_queue
            SET status = 'pending', error_message = CONCAT('Reset: stuck since ', started_at), started_at = NULL, completed_at = NULL
            WHERE status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");

        // Check if we're mid-step on an item
        $progress = get_option('seom_bg_progress', null);

        if ($progress) {
            // Continue processing the current item's next step
            $post_id = $progress['post_id'];
            $step = $progress['step'] + 1;
            $post_type = $progress['post_type'];
            $refresh_type = $progress['refresh_type'];
            $total_steps = $progress['total_steps'];

            // Heartbeat: bump started_at so the stuck-item reset (the 5-min query
            // above, and process_next's 10-min one) never recycles an item that is
            // actively being stepped across cron ticks. A full item spans several
            // AI calls and its total wall-clock can exceed those windows even though
            // each individual step is short.
            $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
                ['started_at' => current_time('mysql')],
                ['id' => $progress['item_id']]
            );

            @set_time_limit(180);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

            $error = null;
            try {
                if ($post_type === 'post') {
                    switch ($step) {
                        case 2:
                            if ($refresh_type === 'meta_only') {
                                $r = SEOM_Blog_Refresher::meta_refresh($post_id);
                                if (is_wp_error($r)) throw new \Exception($r->get_error_message());
                            } else {
                                $r = SEOM_Blog_Refresher::step_content($post_id);
                                if (is_wp_error($r)) throw new \Exception('Content: ' . $r->get_error_message());
                            }
                            break;
                        case 3:
                            $r = SEOM_Blog_Refresher::step_meta_description($post_id);
                            if (is_wp_error($r)) throw new \Exception('Meta: ' . $r->get_error_message());
                            break;
                        case 4: // FAQ + Schema + RankMath + SEO Title — collect ALL sub-errors
                            $sub_errors = [];

                            $faq = SEOM_Blog_Refresher::step_faq_html($post_id);
                            if (is_wp_error($faq)) {
                                $sub_errors[] = 'FAQ HTML: ' . $faq->get_error_message();
                            } else {
                                $faq_json = SEOM_Blog_Refresher::step_faq_json($post_id, $faq);
                                if (is_wp_error($faq_json)) {
                                    $sub_errors[] = 'FAQ JSON: ' . $faq_json->get_error_message();
                                }
                            }

                            $rm = SEOM_Blog_Refresher::step_rankmath($post_id);
                            if (is_wp_error($rm)) $sub_errors[] = 'RankMath: ' . $rm->get_error_message();

                            $st = SEOM_Blog_Refresher::step_seo_title($post_id);
                            if (is_wp_error($st)) $sub_errors[] = 'SEO Title: ' . $st->get_error_message();

                            if (!empty($sub_errors)) throw new \Exception(implode(' | ', $sub_errors));
                            break;
                        case 5:
                            SEOM_Blog_Refresher::step_timestamp($post_id);
                            break;
                    }
                } else {
                    // Product steps — all routed through SEOM_Blog_Refresher
                    // (which is type-aware and handles products via step_long_content)
                    switch ($step) {
                        case 2:
                            if ($refresh_type === 'meta_only') {
                                $r = SEOM_Blog_Refresher::step_meta_description($post_id);
                                if (is_wp_error($r)) throw new \Exception('Meta: ' . $r->get_error_message());
                                $r = SEOM_Blog_Refresher::step_rankmath($post_id);
                                if (is_wp_error($r)) throw new \Exception('RankMath: ' . $r->get_error_message());
                                $r = SEOM_Blog_Refresher::step_seo_title($post_id);
                                if (is_wp_error($r)) throw new \Exception('SEO Title: ' . $r->get_error_message());
                            } else {
                                $r = SEOM_Blog_Refresher::step_long_content($post_id);
                                if (is_wp_error($r)) throw new \Exception('Description: ' . $r->get_error_message());
                            }
                            break;
                        case 3:
                            $r = SEOM_Blog_Refresher::step_meta_description($post_id);
                            if (is_wp_error($r)) throw new \Exception('Short Desc: ' . $r->get_error_message());
                            break;
                        case 4:
                            $faq = SEOM_Blog_Refresher::step_faq_html($post_id);
                            if (is_wp_error($faq)) throw new \Exception('FAQ HTML: ' . $faq->get_error_message());
                            $faq_json = SEOM_Blog_Refresher::step_faq_json($post_id, $faq);
                            if (is_wp_error($faq_json)) throw new \Exception('FAQ JSON: ' . $faq_json->get_error_message());
                            break;
                        case 5:
                            $r = SEOM_Blog_Refresher::step_rankmath($post_id);
                            if (is_wp_error($r)) throw new \Exception('RankMath: ' . $r->get_error_message());
                            break;
                        case 6:
                            $r = SEOM_Blog_Refresher::step_seo_title($post_id);
                            if (is_wp_error($r)) throw new \Exception('SEO Title: ' . $r->get_error_message());
                            break;
                        case 7:
                            $r = SEOM_Blog_Refresher::step_timestamp($post_id);
                            if (is_wp_error($r)) throw new \Exception('Timestamp: ' . $r->get_error_message());
                            break;
                    }
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $is_final = ($refresh_type === 'meta_only' && $step === 2)
                || ($post_type === 'post' && $step >= $total_steps)
                || ($post_type !== 'post' && $step >= $total_steps);

            if ($is_final || $error) {
                // Item complete — update queue and history
                $status = $error ? 'failed' : 'completed';
                $wpdb->update("{$wpdb->prefix}seom_refresh_queue", [
                    'status' => $status, 'completed_at' => current_time('mysql'), 'error_message' => $error,
                ], ['id' => $progress['item_id']]);

                $before = $progress['before'];
                $wpdb->insert("{$wpdb->prefix}seom_refresh_history", [
                    'post_id' => $post_id, 'refresh_date' => current_time('mysql'),
                    'refresh_type' => $refresh_type, 'category' => $progress['category'],
                    'priority_score' => $progress['priority'],
                    'clicks_before' => $before->clicks ?? null, 'impressions_before' => $before->impressions ?? null,
                    'position_before' => $before->avg_position ?? null, 'ctr_before' => $before->ctr ?? null,
                ]);

                if (!$error && $refresh_type !== 'meta_only') {
                    $today_key = 'seom_daily_count_' . date('Y-m-d');
                    update_option($today_key, (int) get_option($today_key, 0) + 1);
                }

                // Update the session transient — bump completed/failed and clear the current item
                $session = get_transient('seom_queue_session');
                if (is_array($session)) {
                    if ($error) {
                        $session['failed'] = (int) ($session['failed'] ?? 0) + 1;
                    } else {
                        $session['completed'] = (int) ($session['completed'] ?? 0) + 1;
                    }
                    $session['current'] = null;
                    set_transient('seom_queue_session', $session, 6 * HOUR_IN_SECONDS);
                }

                delete_option('seom_bg_progress');
                delete_transient('seom_refresh_context_' . $post_id);

                // Send notification email
                self::send_refresh_email($post_id, $post_type, $refresh_type, $progress['category'], $error);

                return ['status' => $status, 'post_id' => $post_id, 'title' => get_the_title($post_id), 'step' => $step, 'total' => $total_steps, 'error' => $error];
            }

            // Save progress for next poll
            $progress['step'] = $step;
            update_option('seom_bg_progress', $progress);

            return ['status' => 'step', 'post_id' => $post_id, 'title' => get_the_title($post_id), 'step' => $step, 'total' => $total_steps];
        }

        // No item in progress — pick the next pending item and initialize
        $item = $wpdb->get_row("
            SELECT * FROM {$wpdb->prefix}seom_refresh_queue
            WHERE status = 'pending'
            ORDER BY (refresh_type = 'meta_only') DESC, priority_score DESC
            LIMIT 1
        ");

        if (!$item) {
            delete_option('seom_queue_processing');
            delete_option('seom_bg_progress');
            delete_transient('seom_queue_session');
            return ['status' => 'done'];
        }

        $post_id = intval($item->post_id);
        $post_type = get_post_type($post_id);

        // Mark as processing
        $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
            ['status' => 'processing', 'started_at' => current_time('mysql')],
            ['id' => $item->id]
        );

        // Reflect the current item in the session transient so the poll endpoint can show it
        $session = get_transient('seom_queue_session');
        if (is_array($session)) {
            $session['current'] = [
                'post_id'      => $post_id,
                'post_title'   => get_the_title($post_id),
                'refresh_type' => $item->refresh_type,
                'category'     => $item->category,
                'started_at'   => current_time('mysql'),
            ];
            set_transient('seom_queue_session', $session, 6 * HOUR_IN_SECONDS);
        }

        $before = $wpdb->get_row($wpdb->prepare(
            "SELECT clicks, impressions, ctr, avg_position FROM {$wpdb->prefix}seom_page_metrics
             WHERE post_id = %d ORDER BY date_collected DESC LIMIT 1", $post_id
        ));

        $total_steps = 2;
        if ($item->refresh_type !== 'meta_only') {
            $total_steps = ($post_type === 'post') ? 5 : 7;
        }

        // Update title year before processing
        if ($item->refresh_type !== 'meta_only') {
            SEOM_Blog_Refresher::update_title_year($post_id);
        }

        // Set up research context (same as step-based processor)
        $settings = seom_get_settings();
        $category_labels = [
            'A' => 'Ghost Page', 'B' => 'CTR Fix', 'C' => 'Near Win',
            'D' => 'Declining', 'E' => 'Visible but Ignored', 'F' => 'Buried Potential', 'M' => 'Manual',
        ];
        $top_queries_data = [];
        if (!empty($before->top_queries ?? '')) {
            $tq = json_decode($before->top_queries, true);
            if (is_array($tq)) $top_queries_data = $tq;
        }
        set_transient('seom_refresh_context_' . $post_id, [
            'clicks' => intval($before->clicks ?? 0), 'impressions' => intval($before->impressions ?? 0),
            'ctr' => floatval($before->ctr ?? 0), 'position' => floatval($before->avg_position ?? 0),
            'category' => $item->category, 'category_desc' => $category_labels[$item->category] ?? 'Unknown',
            'refresh_type' => $item->refresh_type, 'top_queries' => $top_queries_data,
        ], 3600);

        // Run research if applicable
        if (class_exists('SEOM_Researcher') && SEOM_Researcher::is_available() && $item->refresh_type !== 'meta_only') {
            $seo_ctx = get_transient('seom_refresh_context_' . $post_id);
            if ($seo_ctx && seom_should_research($item->category, $seo_ctx, $settings)) {
                $research = SEOM_Researcher::research($post_id, $seo_ctx);
                if (!is_wp_error($research) && !empty($research)) {
                    update_post_meta($post_id, '_seom_research', $research);
                    update_post_meta($post_id, '_seom_research_date', current_time('mysql'));
                    $seo_ctx['competitive_research'] = $research;
                    set_transient('seom_refresh_context_' . $post_id, $seo_ctx, 3600);
                }
            }
        }

        // Inject existing research
        $seo_ctx = get_transient('seom_refresh_context_' . $post_id);
        if ($seo_ctx && empty($seo_ctx['competitive_research'])) {
            $existing = get_post_meta($post_id, '_seom_research', true);
            if ($existing) {
                $seo_ctx['competitive_research'] = $existing;
                $seo_ctx['research_date'] = get_post_meta($post_id, '_seom_research_date', true);
                set_transient('seom_refresh_context_' . $post_id, $seo_ctx, 3600);
            }
        }

        // Save progress — step 1 (init) is done, next poll does step 2
        update_option('seom_bg_progress', [
            'item_id'      => $item->id,
            'post_id'      => $post_id,
            'post_type'    => $post_type,
            'refresh_type' => $item->refresh_type,
            'category'     => $item->category,
            'priority'     => $item->priority_score,
            'before'       => $before,
            'step'         => 1,
            'total_steps'  => $total_steps,
        ]);

        return ['status' => 'started', 'post_id' => $post_id, 'title' => get_the_title($post_id), 'step' => 1, 'total' => $total_steps];
    }

    /**
     * Process a specific post (manual trigger from dashboard).
     */
    public static function process_single($post_id = 0) {
        if (!$post_id) return new WP_Error('no_post', 'No post ID provided.');

        $settings = seom_get_settings();

        // Check if in queue
        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}seom_refresh_queue WHERE post_id = %d AND status = 'pending' LIMIT 1",
            $post_id
        ));

        if ($item) {
            return self::execute_refresh($item);
        }

        // Not in queue — create an ad-hoc entry and process
        $wpdb->insert("{$wpdb->prefix}seom_refresh_queue", [
            'post_id'        => $post_id,
            'post_type'      => get_post_type($post_id),
            'priority_score' => 99,
            'category'       => 'M', // Manual
            'refresh_type'   => 'full',
            'status'         => 'pending',
            'queued_at'      => current_time('mysql'),
        ]);

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}seom_refresh_queue WHERE post_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1",
            $post_id
        ));

        return self::execute_refresh($item);
    }

    /**
     * Send notification email after a refresh completes or fails.
     * Falls back to the WP admin email when notify_email isn't set.
     */
    public static function send_refresh_email($post_id, $post_type, $refresh_type, $category, $error = null) {
        $settings = seom_get_settings();
        $notify_to = !empty($settings['notify_email']) ? $settings['notify_email'] : get_option('admin_email');
        if (!$notify_to) return;

        $title = get_the_title($post_id);
        $status_label = $error ? 'Failed' : 'Refreshed';
        $subject = "[SEO AI AutoPilot] {$status_label}: {$title}";
        $cat_labels = ['A' => 'Ghost', 'B' => 'CTR Fix', 'C' => 'Near Win', 'D' => 'Declining', 'E' => 'Visible/Ignored', 'F' => 'Buried', 'M' => 'Manual'];
        $cat = $cat_labels[$category] ?? $category;
        $edit_url = admin_url('post.php?action=edit&post=' . $post_id);
        $view_url = get_permalink($post_id);
        $error_row = $error ? '<tr><td style="padding:8px 12px;color:#999;">Error</td><td style="padding:8px 12px;color:#dc2626;">' . esc_html($error) . '</td></tr>' : '';
        $status_color = $error ? '#dc2626' : '#059669';
        $status_icon  = $error ? '&#10007;' : '&#10003;';

        // Pull post-refresh SEO meta so the email shows what was actually saved
        $seo_rows_after = '';
        if (!$error) {
            $seo_title_after = get_post_meta($post_id, 'rank_math_title', true);
            $meta_desc_after = get_post_meta($post_id, 'rank_math_description', true);

            $seo_title_len = $seo_title_after ? mb_strlen($seo_title_after) : 0;
            $title_color   = $seo_title_len === 0 ? '#dc2626'
                           : ($seo_title_len <= 60 ? '#16a34a' : ($seo_title_len <= 70 ? '#d97706' : '#dc2626'));
            $title_display = $seo_title_after
                ? '<span style="color:#1e293b;">' . esc_html($seo_title_after) . '</span> <span style="color:' . $title_color . ';font-size:11px;">(' . $seo_title_len . ' chars)</span>'
                : '<span style="color:#dc2626;font-style:italic;">— not set —</span>';

            $meta_desc_len = $meta_desc_after ? mb_strlen($meta_desc_after) : 0;
            $desc_color    = $meta_desc_len === 0 ? '#dc2626'
                           : (($meta_desc_len >= 120 && $meta_desc_len <= 158) ? '#16a34a'
                           : ($meta_desc_len < 120 ? '#d97706' : '#dc2626'));
            $desc_display  = $meta_desc_after
                ? '<div style="color:#1e293b;line-height:1.45;">' . esc_html($meta_desc_after) . '</div><div style="color:' . $desc_color . ';font-size:11px;margin-top:4px;">' . $meta_desc_len . ' chars</div>'
                : '<span style="color:#dc2626;font-style:italic;">— not set —</span>';

            $seo_rows_after = '<tr style="background:#f9fafb;"><td style="padding:8px 12px;color:#999;vertical-align:top;">SEO Title</td><td style="padding:8px 12px;">' . $title_display . '</td></tr>'
                            . '<tr><td style="padding:8px 12px;color:#999;vertical-align:top;">Meta Description</td><td style="padding:8px 12px;">' . $desc_display . '</td></tr>';
        }

        $body = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;">'
            . '<div style="background:#1d2327;padding:16px 20px;border-radius:8px 8px 0 0;"><h1 style="margin:0;color:#fff;font-size:16px;">SEO AI AutoPilot</h1></div>'
            . '<div style="background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:20px;">'
            . '<div style="margin-bottom:12px;">'
            .   '<span style="font-size:22px;color:' . $status_color . ';font-weight:700;line-height:1;">' . $status_icon . '</span>'
            .   '<span style="font-size:14px;color:' . $status_color . ';font-weight:600;margin-left:8px;vertical-align:2px;">' . $status_label . '</span>'
            . '</div>'
            . '<h2 style="margin:0 0 12px;font-size:18px;">' . esc_html($title) . '</h2>'
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-size:13px;">'
            . '<tr><td style="padding:8px 12px;color:#999;width:140px;">Type</td><td style="padding:8px 12px;">' . esc_html($post_type) . '</td></tr>'
            . '<tr style="background:#f9fafb;"><td style="padding:8px 12px;color:#999;">Category</td><td style="padding:8px 12px;"><strong>' . esc_html($cat) . '</strong></td></tr>'
            . '<tr><td style="padding:8px 12px;color:#999;">Refresh</td><td style="padding:8px 12px;">' . esc_html($refresh_type) . '</td></tr>'
            . '<tr style="background:#f9fafb;"><td style="padding:8px 12px;color:#999;vertical-align:top;">AI Models</td><td style="padding:8px 12px;">' . esc_html(seom_get_refresh_models_summary($post_type, $refresh_type)) . '</td></tr>'
            . $seo_rows_after
            . $error_row . '</table>'
            . '<a href="' . esc_url($view_url) . '" style="display:inline-block;padding:8px 16px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-size:13px;margin-right:8px;">View</a>'
            . '<a href="' . esc_url($edit_url) . '" style="display:inline-block;padding:8px 16px;background:#f0f0f1;color:#1d2327;text-decoration:none;border-radius:4px;font-size:13px;">Edit</a>'
            . '<p style="margin-top:16px;color:#9ca3af;font-size:11px;">Refreshed via ' . esc_html(get_bloginfo('name')) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '</p>'
            . '</div></div>';

        $set_html = function () { return 'text/html'; };
        add_filter('wp_mail_content_type', $set_html);
        $sent = wp_mail($notify_to, $subject, $body);
        remove_filter('wp_mail_content_type', $set_html);

        if (!$sent) {
            error_log('[SEO AI AutoPilot] send_refresh_email wp_mail() returned false — to=' . $notify_to . ' post_id=' . $post_id);
        }
    }

    /**
     * Execute the actual refresh on a queue item.
     */
    private static function execute_refresh($item) {
        global $wpdb;

        // Last-second stop check before kicking off AI work — catches the case
        // where the user clicked Stop right as this item was being picked up
        if (self::should_stop()) {
            // Put the row back to pending so it's not lost
            $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
                ['status' => 'pending', 'started_at' => null, 'error_message' => null],
                ['id' => $item->id]
            );
            return ['status' => 'stopped'];
        }

        $settings = seom_get_settings();
        $post_id = intval($item->post_id);

        // Route by post type
        $post_type = get_post_type($post_id);
        if ($post_type === 'post') {
            if (!SEOM_Blog_Refresher::is_available()) {
                $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
                    ['status' => 'failed', 'error_message' => 'Blog Writer API key not configured.'],
                    ['id' => $item->id]
                );
                return new WP_Error('missing_key', 'Blog Writer API key not configured.');
            }
        } elseif ($post_type === 'page') {
            // Pages only support meta_only refresh
            if ($item->refresh_type !== 'meta_only') {
                $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
                    ['status' => 'skipped', 'completed_at' => current_time('mysql'), 'error_message' => 'Pages only support meta-only refresh.'],
                    ['id' => $item->id]
                );
                return new WP_Error('page_full', 'Pages only support meta-only refresh.');
            }
        } elseif ($post_type !== 'product') {
            $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
                ['status' => 'skipped', 'completed_at' => current_time('mysql'), 'error_message' => "Post type '{$post_type}' not supported."],
                ['id' => $item->id]
            );
            return new WP_Error('unsupported_type', "Post type not supported: {$post_type}");
        }

        // Mark as processing
        $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
            ['status' => 'processing', 'started_at' => current_time('mysql')],
            ['id' => $item->id]
        );

        // Reflect current item in the session transient for UI polling
        $session = get_transient('seom_queue_session');
        if (is_array($session)) {
            $session['current'] = [
                'post_id'      => $post_id,
                'post_title'   => get_the_title($post_id),
                'refresh_type' => $item->refresh_type,
                'category'     => $item->category,
                'started_at'   => current_time('mysql'),
            ];
            set_transient('seom_queue_session', $session, 6 * HOUR_IN_SECONDS);
        }

        // Get "before" metrics for history
        $before = $wpdb->get_row($wpdb->prepare(
            "SELECT clicks, impressions, ctr, avg_position FROM {$wpdb->prefix}seom_page_metrics
             WHERE post_id = %d ORDER BY date_collected DESC LIMIT 1",
            $post_id
        ));

        // Dry run mode — record everything but don't actually change content
        if ($settings['dry_run']) {
            $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
                ['status' => 'completed', 'completed_at' => current_time('mysql'), 'error_message' => 'Dry run — no changes made.'],
                ['id' => $item->id]
            );
            // Still record in history so dry runs are visible
            $wpdb->insert("{$wpdb->prefix}seom_refresh_history", [
                'post_id'            => $post_id,
                'refresh_date'       => current_time('mysql'),
                'refresh_type'       => $item->refresh_type . ' (dry run)',
                'category'           => $item->category,
                'priority_score'     => $item->priority_score,
                'clicks_before'      => $before->clicks ?? null,
                'impressions_before' => $before->impressions ?? null,
                'position_before'    => $before->avg_position ?? null,
                'ctr_before'         => $before->ctr ?? null,
            ]);
            return ['post_id' => $post_id, 'dry_run' => true, 'status' => 'dry_run'];
        }

        // Execute refresh based on post type and refresh type
        // Give the worker enough time for multiple AI calls (each step can take 30-60s)
        @set_time_limit(600);
        $error = null;
        try {
            // Single, type-aware refresh path. SEOM_Blog_Refresher dispatches
            // step_long_content() to step_content (blog) or step_product_description
            // (product) internally. All other steps are content-type neutral —
            // they just read post_content and write to standard meta fields.
            if ($post_type === 'page' || $item->refresh_type === 'meta_only') {
                // Pages only support meta-only; meta_only items skip the long-content step
                $result = SEOM_Blog_Refresher::meta_refresh($post_id);
            } else {
                $result = SEOM_Blog_Refresher::full_refresh($post_id);
            }
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        // Update queue status
        $status = $error ? 'failed' : 'completed';
        $wpdb->update("{$wpdb->prefix}seom_refresh_queue", [
            'status'        => $status,
            'completed_at'  => current_time('mysql'),
            'error_message' => $error,
        ], ['id' => $item->id]);

        // Update session transient — bump counter, clear current item
        $session = get_transient('seom_queue_session');
        if (is_array($session)) {
            if ($error) {
                $session['failed'] = (int) ($session['failed'] ?? 0) + 1;
            } else {
                $session['completed'] = (int) ($session['completed'] ?? 0) + 1;
            }
            $session['current'] = null;
            set_transient('seom_queue_session', $session, 6 * HOUR_IN_SECONDS);
        }

        // Record in history
        $wpdb->insert("{$wpdb->prefix}seom_refresh_history", [
            'post_id'            => $post_id,
            'refresh_date'       => current_time('mysql'),
            'refresh_type'       => $item->refresh_type,
            'category'           => $item->category,
            'priority_score'     => $item->priority_score,
            'clicks_before'      => $before->clicks ?? null,
            'impressions_before' => $before->impressions ?? null,
            'position_before'    => $before->avg_position ?? null,
            'ctr_before'         => $before->ctr ?? null,
        ]);

        // Send notification email — falls back to the WP admin email if the
        // SEO AI AutoPilot notify_email setting hasn't been configured, so we never
        // silently swallow per-item refresh notifications.
        $notify_to = !empty($settings['notify_email']) ? $settings['notify_email'] : get_option('admin_email');
        if ($notify_to) {
            $title     = get_the_title($post_id);
            $view_url  = get_permalink($post_id);
            $edit_url  = admin_url('post.php?action=edit&post=' . $post_id);
            $post_type = get_post_type($post_id);
            $type_label = $post_type === 'product' ? 'Product' : 'Blog Post';
            $cat_labels = ['A' => 'Ghost Page', 'B' => 'CTR Fix', 'C' => 'Near Win', 'D' => 'Declining', 'E' => 'Visible/Ignored', 'F' => 'Buried Potential', 'M' => 'Manual'];
            $cat_label  = $cat_labels[$item->category] ?? $item->category;

            $subject = $error
                ? "[SEO AI AutoPilot] Failed: {$title}"
                : "[SEO AI AutoPilot] Refreshed: {$title}";

            $status_color = $error ? '#dc2626' : '#16a34a';
            $status_label = $error ? 'Failed' : 'Success';
            $status_icon  = $error ? '&#10007;' : '&#10003;';

            $error_row = '';
            if ($error) {
                $error_row = '<tr><td style="padding:8px 16px;color:#999;font-size:13px;">Error</td><td style="padding:8px 16px;color:#dc2626;">' . esc_html($error) . '</td></tr>';
            }

            $before_row = '';
            if ($before) {
                $before_row = '<tr><td style="padding:8px 16px;color:#999;font-size:13px;">Before Metrics</td>'
                    . '<td style="padding:8px 16px;">'
                    . intval($before->clicks) . ' clicks &middot; '
                    . intval($before->impressions) . ' impressions &middot; '
                    . 'Pos ' . number_format(floatval($before->avg_position), 1) . ' &middot; '
                    . number_format(floatval($before->ctr), 1) . '% CTR'
                    . '</td></tr>';
            }

            // Pull post-refresh SEO meta so the email shows what was actually saved
            $seo_rows_after = '';
            if (!$error) {
                $seo_title_after = get_post_meta($post_id, 'rank_math_title', true);
                $meta_desc_after = get_post_meta($post_id, 'rank_math_description', true);

                $seo_title_len = $seo_title_after ? mb_strlen($seo_title_after) : 0;
                $title_color   = $seo_title_len === 0 ? '#dc2626'
                               : ($seo_title_len <= 60 ? '#16a34a' : ($seo_title_len <= 70 ? '#d97706' : '#dc2626'));
                $title_display = $seo_title_after
                    ? '<span style="color:#1e293b;">' . esc_html($seo_title_after) . '</span> <span style="color:' . $title_color . ';font-size:11px;">(' . $seo_title_len . ' chars)</span>'
                    : '<span style="color:#dc2626;font-style:italic;">— not set —</span>';

                $meta_desc_len = $meta_desc_after ? mb_strlen($meta_desc_after) : 0;
                $desc_color    = $meta_desc_len === 0 ? '#dc2626'
                               : (($meta_desc_len >= 120 && $meta_desc_len <= 158) ? '#16a34a'
                               : ($meta_desc_len < 120 ? '#d97706' : '#dc2626'));
                $desc_display  = $meta_desc_after
                    ? '<div style="color:#1e293b;line-height:1.45;">' . esc_html($meta_desc_after) . '</div><div style="color:' . $desc_color . ';font-size:11px;margin-top:4px;">' . $meta_desc_len . ' chars</div>'
                    : '<span style="color:#dc2626;font-style:italic;">— not set —</span>';

                $seo_rows_after = '<tr><td style="padding:8px 16px;color:#999;font-size:13px;vertical-align:top;">SEO Title</td><td style="padding:8px 16px;">' . $title_display . '</td></tr>'
                                . '<tr style="background:#f9fafb;"><td style="padding:8px 16px;color:#999;font-size:13px;vertical-align:top;">Meta Description</td><td style="padding:8px 16px;">' . $desc_display . '</td></tr>';
            }

            $body = '
            <div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;">
                <div style="background:#1d2327;padding:20px 24px;border-radius:8px 8px 0 0;">
                    <h1 style="margin:0;color:#fff;font-size:18px;font-weight:600;">SEO AI AutoPilot</h1>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:24px;">
                    <div style="display:inline-block;padding:6px 14px;border-radius:4px;background:' . $status_color . ';color:#fff;font-weight:600;font-size:14px;margin-bottom:16px;">
                        ' . $status_icon . ' ' . $status_label . '
                    </div>

                    <h2 style="margin:0 0 16px;font-size:20px;color:#1d2327;">' . esc_html($title) . '</h2>

                    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px;">
                        <tr><td style="padding:8px 16px;color:#999;font-size:13px;width:140px;">Type</td><td style="padding:8px 16px;">' . esc_html($type_label) . '</td></tr>
                        <tr style="background:#f9fafb;"><td style="padding:8px 16px;color:#999;font-size:13px;">Category</td><td style="padding:8px 16px;"><strong>' . esc_html($cat_label) . '</strong></td></tr>
                        <tr><td style="padding:8px 16px;color:#999;font-size:13px;">Refresh Type</td><td style="padding:8px 16px;">' . esc_html($item->refresh_type) . '</td></tr>
                        <tr style="background:#f9fafb;"><td style="padding:8px 16px;color:#999;font-size:13px;">Priority Score</td><td style="padding:8px 16px;">' . esc_html($item->priority_score) . '</td></tr>
                        ' . $before_row . '
                        ' . $seo_rows_after . '
                        ' . $error_row . '
                    </table>

                    <div style="margin-top:20px;">
                        <a href="' . esc_url($view_url) . '" style="display:inline-block;padding:10px 20px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;font-size:14px;margin-right:8px;">View Page</a>
                        <a href="' . esc_url($edit_url) . '" style="display:inline-block;padding:10px 20px;background:#f0f0f1;color:#1d2327;text-decoration:none;border-radius:4px;font-weight:600;font-size:14px;border:1px solid #c3c4c7;">Edit in WordPress</a>
                    </div>

                    <p style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;color:#999;font-size:12px;">
                        Sent by SEO AI AutoPilot on ' . esc_html(get_bloginfo('name')) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '
                    </p>
                </div>
            </div>';

            $set_html = function () { return 'text/html'; };
            add_filter('wp_mail_content_type', $set_html);
            $sent = wp_mail($notify_to, $subject, $body);
            remove_filter('wp_mail_content_type', $set_html);

            if (!$sent) {
                error_log('[SEO AI AutoPilot] wp_mail() returned false for refresh notification — to=' . $notify_to . ' post_id=' . $post_id);
            }
        }

        return [
            'post_id'  => $post_id,
            'status'   => $status,
            'category' => $item->category,
            'type'     => $item->refresh_type,
            'error'    => $error,
        ];
    }

    /**
     * Send a daily summary email of all refreshes processed today.
     * Only sends once per day (uses a transient to prevent duplicates from chained cron).
     */
    /**
     * Mark the current session transient as stopped (keeps it visible to the UI).
     * Reasons: 'daily_limit', 'completed', 'stale', 'error', 'manual'.
     */
    public static function mark_session_stopped($reason, $message, $remaining = null) {
        $session = get_transient('seom_queue_session');
        if (!is_array($session)) return;

        $session['stopped'] = true;
        $session['stop_reason'] = $reason;
        $session['stop_message'] = $message;
        $session['stopped_at'] = current_time('mysql');
        if ($remaining !== null) $session['remaining_at_stop'] = (int) $remaining;
        set_transient('seom_queue_session', $session, 6 * HOUR_IN_SECONDS);
    }

    /**
     * Send an email when the background queue stops — either completed, hit the daily
     * limit, or stopped due to an error/stall. One email per stop event.
     */
    public static function send_queue_stop_email($reason, $remaining) {
        $settings = seom_get_settings();
        if (empty($settings['notify_email'])) return;

        // Dedupe — don't send the same stop email twice for the same session
        $session = get_transient('seom_queue_session');
        $started_at = is_array($session) ? ($session['started_at'] ?? '') : '';
        $dedupe_key = 'seom_stop_email_' . md5($reason . '|' . $started_at);
        if (get_transient($dedupe_key)) return;
        set_transient($dedupe_key, true, 12 * HOUR_IN_SECONDS);

        $completed = is_array($session) ? intval($session['completed'] ?? 0) : 0;
        $failed    = is_array($session) ? intval($session['failed'] ?? 0) : 0;
        $total     = is_array($session) ? intval($session['total_initial'] ?? 0) : 0;
        $recovery  = is_array($session) ? intval($session['recovery_attempts'] ?? 0) : 0;

        // ── Diagnostic context for stall/error emails ──
        // Pull the last completed item, the last item with a non-empty
        // error_message, and a small tail of recent activity so the email
        // tells the user WHY processing stopped — not just THAT it stopped.
        // Only fired for stall/error so successful emails stay clean.
        global $wpdb;
        $diag_html = '';
        if (in_array($reason, ['stale', 'error'], true)) {
            $q_table = $wpdb->prefix . 'seom_refresh_queue';
            $last_completed = $wpdb->get_row(
                "SELECT post_id, completed_at FROM {$q_table}
                 WHERE status = 'completed' AND completed_at IS NOT NULL
                 ORDER BY completed_at DESC LIMIT 1"
            );
            $last_error = $wpdb->get_row(
                "SELECT post_id, error_message, started_at FROM {$q_table}
                 WHERE error_message IS NOT NULL AND error_message != ''
                 ORDER BY COALESCE(completed_at, started_at) DESC LIMIT 1"
            );
            $stuck_processing = $wpdb->get_results(
                "SELECT post_id, started_at FROM {$q_table}
                 WHERE status = 'processing'
                 ORDER BY started_at DESC LIMIT 5"
            );
            $cron_scheduled  = wp_next_scheduled('seom_process_next');
            $cron_label      = $cron_scheduled
                ? '<span style="color:#059669;">' . esc_html(gmdate('Y-m-d H:i:s', $cron_scheduled)) . ' UTC</span>'
                : '<span style="color:#dc2626;">NOT SCHEDULED — chain is broken</span>';

            $diag_rows = '';
            if ($last_completed) {
                $title = get_the_title((int) $last_completed->post_id);
                $diag_rows .= '<tr><td style="padding:10px 14px;color:#9ca3af;font-size:13px;width:200px;">Last item completed</td>'
                    . '<td style="padding:10px 14px;"><strong>#' . intval($last_completed->post_id) . '</strong> ' . esc_html($title)
                    . '<br><span style="font-size:11px;color:#9ca3af;">' . esc_html($last_completed->completed_at) . '</span></td></tr>';
            } else {
                $diag_rows .= '<tr><td style="padding:10px 14px;color:#9ca3af;font-size:13px;width:200px;">Last item completed</td>'
                    . '<td style="padding:10px 14px;color:#dc2626;">No items completed in this session</td></tr>';
            }

            if ($last_error) {
                $err_title = get_the_title((int) $last_error->post_id);
                $diag_rows .= '<tr style="background:#fef2f2;"><td style="padding:10px 14px;color:#9ca3af;font-size:13px;vertical-align:top;">Last error captured</td>'
                    . '<td style="padding:10px 14px;"><strong>#' . intval($last_error->post_id) . '</strong> ' . esc_html($err_title)
                    . '<br><span style="font-size:12px;color:#dc2626;font-family:Menlo,Consolas,monospace;">' . esc_html($last_error->error_message) . '</span>'
                    . '<br><span style="font-size:11px;color:#9ca3af;">started ' . esc_html($last_error->started_at) . '</span></td></tr>';
            } else {
                $diag_rows .= '<tr style="background:#fef2f2;"><td style="padding:10px 14px;color:#9ca3af;font-size:13px;">Last error captured</td>'
                    . '<td style="padding:10px 14px;color:#94a3b8;">No error_message on any queue row — the worker died silently. Tail your error log for [SEO AI AutoPilot] process_next lines.</td></tr>';
            }

            if (!empty($stuck_processing)) {
                $stuck_lines = [];
                foreach ($stuck_processing as $s) {
                    $stuck_lines[] = '#' . intval($s->post_id) . ' (started ' . esc_html($s->started_at) . ')';
                }
                $diag_rows .= '<tr><td style="padding:10px 14px;color:#9ca3af;font-size:13px;">Items still in &quot;processing&quot;</td>'
                    . '<td style="padding:10px 14px;font-size:12px;">' . implode('<br>', $stuck_lines) . '</td></tr>';
            }

            $diag_rows .= '<tr style="background:#f9fafb;"><td style="padding:10px 14px;color:#9ca3af;font-size:13px;">Next cron event</td>'
                . '<td style="padding:10px 14px;font-size:12px;">' . $cron_label . '</td></tr>';
            $diag_rows .= '<tr><td style="padding:10px 14px;color:#9ca3af;font-size:13px;">Recovery attempts</td>'
                . '<td style="padding:10px 14px;">' . $recovery . ' / 3</td></tr>';

            $diag_html = '<div style="margin:0 0 16px;padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;">'
                . '<div style="font-weight:600;color:#991b1b;font-size:13px;margin-bottom:8px;">Diagnostic context</div>'
                . '<table style="width:100%;border-collapse:collapse;font-size:13px;">' . $diag_rows . '</table>'
                . '</div>';
        }

        $reason_titles = [
            'completed'    => 'Queue Complete',
            'daily_limit'  => 'Daily Limit Reached',
            'stale'        => 'Background Processing Stalled',
            'error'        => 'Queue Stopped — Error',
            'manual'       => 'Queue Stopped Manually',
        ];
        $reason_color = ($reason === 'completed') ? '#059669' : ($reason === 'daily_limit' ? '#d97706' : '#dc2626');
        $title = $reason_titles[$reason] ?? 'Queue Stopped';

        $reason_messages = [
            'completed'   => 'All queued items have been processed.',
            'daily_limit' => "The daily refresh cap of {$settings['daily_limit']} was reached. {$remaining} item(s) remain in the queue and will resume tomorrow at the next scheduled run.",
            'stale'       => "Background processing stopped unexpectedly with {$remaining} item(s) still in the queue. The diagnostic block below shows the last completed item, the last captured error, and whether the cron chain is still scheduled — use it to figure out what to fix before restarting.",
            'error'       => "Background processing stopped due to an error with {$remaining} item(s) still pending.",
            'manual'      => "Background processing was stopped manually with {$remaining} item(s) still pending.",
        ];
        $message = $reason_messages[$reason] ?? 'Queue processing stopped.';

        $site_name = get_bloginfo('name');
        $subject = "[SEO AI AutoPilot] {$title} — {$completed} completed, {$remaining} remaining";

        $body = '
        <div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;">
            <div style="background:#1d2327;padding:20px 24px;border-radius:8px 8px 0 0;">
                <h1 style="margin:0;color:#fff;font-size:18px;">SEO AI AutoPilot</h1>
            </div>
            <div style="background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:24px;">
                <div style="display:inline-block;padding:6px 14px;border-radius:4px;background:' . $reason_color . ';color:#fff;font-weight:600;font-size:14px;margin-bottom:16px;">' . esc_html($title) . '</div>
                <p style="margin:0 0 20px;color:#374151;font-size:14px;line-height:1.6;">' . esc_html($message) . '</p>
                ' . $diag_html . '
                <table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;margin-bottom:20px;">
                    <tr><td style="padding:10px 14px;color:#9ca3af;font-size:13px;width:160px;">Started</td><td style="padding:10px 14px;">' . esc_html($started_at) . '</td></tr>
                    <tr style="background:#f9fafb;"><td style="padding:10px 14px;color:#9ca3af;font-size:13px;">Initial queue size</td><td style="padding:10px 14px;">' . $total . '</td></tr>
                    <tr><td style="padding:10px 14px;color:#9ca3af;font-size:13px;">Completed</td><td style="padding:10px 14px;color:#059669;font-weight:600;">' . $completed . '</td></tr>
                    <tr style="background:#f9fafb;"><td style="padding:10px 14px;color:#9ca3af;font-size:13px;">Failed</td><td style="padding:10px 14px;color:#dc2626;font-weight:600;">' . $failed . '</td></tr>
                    <tr><td style="padding:10px 14px;color:#9ca3af;font-size:13px;">Remaining</td><td style="padding:10px 14px;font-weight:600;">' . intval($remaining) . '</td></tr>
                </table>
                <p><a href="' . esc_url(admin_url('admin.php?page=seo-ai-autopilot&tab=queue')) . '" style="color:#2563eb;">Open Queue &rarr;</a></p>
                <p style="margin-top:12px;color:#9ca3af;font-size:12px;">Sent by SEO AI AutoPilot on ' . esc_html($site_name) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '</p>
            </div>
        </div>';

        $set_html = function () { return 'text/html'; };
        add_filter('wp_mail_content_type', $set_html);
        wp_mail($settings['notify_email'], $subject, $body);
        remove_filter('wp_mail_content_type', $set_html);
    }

    private static function send_daily_summary($settings) {
        if (empty($settings['notify_email'])) return;

        $sent_key = 'seom_summary_sent_' . date('Y-m-d');
        if (get_transient($sent_key)) return; // Already sent today
        set_transient($sent_key, true, 86400);

        global $wpdb;
        $today = date('Y-m-d');
        $cat_labels = ['A' => 'Ghost', 'B' => 'CTR Fix', 'C' => 'Near Win', 'D' => 'Declining', 'E' => 'Visible/Ignored', 'F' => 'Buried', 'M' => 'Manual'];

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT q.post_id, q.post_type, q.category, q.refresh_type, q.status, q.error_message,
                   p.post_title
            FROM {$wpdb->prefix}seom_refresh_queue q
            JOIN {$wpdb->posts} p ON q.post_id = p.ID
            WHERE DATE(q.completed_at) = %s
            ORDER BY q.completed_at ASC
        ", $today));

        if (empty($results)) return;

        $completed = 0;
        $failed = 0;
        $rows = '';
        foreach ($results as $r) {
            $is_ok = ($r->status === 'completed');
            if ($is_ok) $completed++; else $failed++;
            $status_color = $is_ok ? '#059669' : '#dc2626';
            $status_label = $is_ok ? 'Completed' : 'Failed';
            $cat = $cat_labels[$r->category] ?? $r->category;
            $edit = admin_url('post.php?action=edit&post=' . $r->post_id);
            $error_text = $r->error_message ? '<br><span style="font-size:11px;color:#dc2626;">' . esc_html($r->error_message) . '</span>' : '';

            $rows .= '<tr>'
                . '<td style="padding:8px 12px;"><a href="' . esc_url($edit) . '">' . esc_html($r->post_title) . '</a>'
                . '<br><span style="font-size:11px;color:#9ca3af;">' . esc_html($r->post_type) . '</span></td>'
                . '<td style="padding:8px 12px;">' . esc_html($r->refresh_type) . '</td>'
                . '<td style="padding:8px 12px;">' . esc_html($cat) . '</td>'
                . '<td style="padding:8px 12px;color:' . $status_color . ';font-weight:600;">' . $status_label . $error_text . '</td>'
                . '</tr>';
        }

        $site_name = get_bloginfo('name');
        $subject = "[SEO AI AutoPilot] Daily Summary: {$completed} refreshed, {$failed} failed";

        $body = '
        <div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:700px;margin:0 auto;">
            <div style="background:#1d2327;padding:20px 24px;border-radius:8px 8px 0 0;">
                <h1 style="margin:0;color:#fff;font-size:18px;">SEO AI AutoPilot — Daily Refresh Summary</h1>
            </div>
            <div style="background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:24px;">
                <div style="display:flex;gap:24px;margin-bottom:20px;">
                    <div><span style="font-size:32px;font-weight:700;color:#059669;">' . $completed . '</span><br><span style="color:#6b7280;font-size:13px;">Completed</span></div>
                    <div><span style="font-size:32px;font-weight:700;color:#dc2626;">' . $failed . '</span><br><span style="color:#6b7280;font-size:13px;">Failed</span></div>
                    <div><span style="font-size:32px;font-weight:700;color:#374151;">' . count($results) . '</span><br><span style="color:#6b7280;font-size:13px;">Total</span></div>
                </div>
                <table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;">
                    <thead><tr style="background:#f9fafb;">
                        <th style="padding:8px 12px;text-align:left;">Page</th>
                        <th style="padding:8px 12px;text-align:left;">Type</th>
                        <th style="padding:8px 12px;text-align:left;">Category</th>
                        <th style="padding:8px 12px;text-align:left;">Status</th>
                    </tr></thead>
                    <tbody>' . $rows . '</tbody>
                </table>
                <p style="margin-top:20px;"><a href="' . esc_url(admin_url('admin.php?page=seo-ai-autopilot&tab=tracker')) . '" style="color:#2563eb;">View Performance Tracker &rarr;</a></p>
                <p style="margin-top:12px;color:#9ca3af;font-size:12px;">Sent by SEO AI AutoPilot on ' . esc_html($site_name) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '</p>
            </div>
        </div>';

        $set_html = function () { return 'text/html'; };
        add_filter('wp_mail_content_type', $set_html);
        wp_mail($settings['notify_email'], $subject, $body);
        remove_filter('wp_mail_content_type', $set_html);
    }
}
