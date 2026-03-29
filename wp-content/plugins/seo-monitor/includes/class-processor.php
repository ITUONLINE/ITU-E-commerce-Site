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

        // Schedule first processing
        if (!wp_next_scheduled('seom_process_next')) {
            wp_schedule_single_event(time() + 60, 'seom_process_next');
        }
    }

    /**
     * Process the next item in the queue (called by chained cron events).
     */
    public static function process_next() {
        $settings = seom_get_settings();
        if (!$settings['enabled']) return;

        // Check daily limit
        $today_key = 'seom_daily_count_' . date('Y-m-d');
        $count = (int) get_option($today_key, 0);
        if ($count >= $settings['daily_limit']) return;

        global $wpdb;

        // Get next pending item
        $item = $wpdb->get_row("
            SELECT * FROM {$wpdb->prefix}seom_refresh_queue
            WHERE status = 'pending'
            ORDER BY priority_score DESC
            LIMIT 1
        ");

        if (!$item) return; // Queue empty

        // Process it
        $result = self::execute_refresh($item);

        // Update counter
        update_option($today_key, $count + 1);

        // Schedule next in 10 minutes (stagger to avoid Google volatility)
        if ($count + 1 < $settings['daily_limit']) {
            wp_schedule_single_event(time() + 600, 'seom_process_next');
        }
    }

    /**
     * Process a specific post (manual trigger from dashboard).
     */
    public static function process_single($post_id = 0) {
        if (!$post_id) return new WP_Error('no_post', 'No post ID provided.');

        // Check AI Product Manager is available
        if (!function_exists('aipm_step_description')) {
            return new WP_Error('missing_dep', 'AI Product Manager plugin not active.');
        }

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
     * Execute the actual refresh on a queue item.
     */
    private static function execute_refresh($item) {
        global $wpdb;
        $settings = seom_get_settings();
        $post_id = intval($item->post_id);

        // Check AI Product Manager is available
        if (!function_exists('aipm_step_description')) {
            $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
                ['status' => 'failed', 'error_message' => 'AI Product Manager not active.'],
                ['id' => $item->id]
            );
            return new WP_Error('missing_dep', 'AI Product Manager plugin not active.');
        }

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
        } elseif ($post_type !== 'product') {
            $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
                ['status' => 'skipped', 'error_message' => "Post type '{$post_type}' not supported."],
                ['id' => $item->id]
            );
            return new WP_Error('unsupported_type', "Post type not supported: {$post_type}");
        }

        // Mark as processing
        $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
            ['status' => 'processing', 'started_at' => current_time('mysql')],
            ['id' => $item->id]
        );

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
        $error = null;
        try {
            if ($post_type === 'post') {
                // ─── Blog Post Refresh (via Blog Refresher) ───
                if ($item->refresh_type === 'meta_only') {
                    $result = SEOM_Blog_Refresher::meta_refresh($post_id);
                    if (is_wp_error($result)) throw new Exception($result->get_error_message());
                } else {
                    $result = SEOM_Blog_Refresher::full_refresh($post_id);
                    if (is_wp_error($result)) throw new Exception($result->get_error_message());
                }
            } elseif ($item->refresh_type === 'meta_only') {
                // ─── Product Meta-Only Refresh (CTR fix: title + meta + keyword) ───
                $result = aipm_step_short_description($post_id);
                if (is_wp_error($result)) throw new Exception($result->get_error_message());

                $result = aipm_step_rankmath($post_id);
                if (is_wp_error($result)) throw new Exception($result->get_error_message());

                if (function_exists('aipm_step_seo_title')) {
                    aipm_step_seo_title($post_id);
                }
            } else {
                // ─── Product Full Refresh ───
                $desc = aipm_step_description($post_id);
                if (is_wp_error($desc)) throw new Exception('Step 1 (Description): ' . $desc->get_error_message());

                $short = aipm_step_short_description($post_id, $desc);
                if (is_wp_error($short)) throw new Exception('Step 2 (Short Desc): ' . $short->get_error_message());

                $faq = aipm_step_faq_html($post_id);
                if (is_wp_error($faq)) throw new Exception('Step 3 (FAQ HTML): ' . $faq->get_error_message());

                $json = aipm_step_faq_json($post_id, $faq);
                if (is_wp_error($json)) throw new Exception('Step 4 (FAQ JSON): ' . $json->get_error_message());

                $seo = aipm_step_rankmath($post_id);
                if (is_wp_error($seo)) throw new Exception('Step 5 (RankMath): ' . $seo->get_error_message());

                if (function_exists('aipm_step_seo_title')) {
                    aipm_step_seo_title($post_id);
                }
            }

            // Always save timestamp
            if ($post_type === 'post') {
                SEOM_Blog_Refresher::step_timestamp($post_id);
            } else {
                aipm_step_timestamp($post_id);
            }

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

        // Send notification if configured
        if ($settings['notify_email']) {
            $title     = get_the_title($post_id);
            $view_url  = get_permalink($post_id);
            $edit_url  = admin_url('post.php?action=edit&post=' . $post_id);
            $post_type = get_post_type($post_id);
            $type_label = $post_type === 'product' ? 'Product' : 'Blog Post';
            $cat_labels = ['A' => 'Ghost Page', 'B' => 'CTR Fix', 'C' => 'Near Win', 'D' => 'Declining', 'E' => 'Visible/Ignored', 'M' => 'Manual'];
            $cat_label  = $cat_labels[$item->category] ?? $item->category;

            $subject = $error
                ? "[SEO Monitor] Failed: {$title}"
                : "[SEO Monitor] Refreshed: {$title}";

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

            $body = '
            <div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;">
                <div style="background:#1d2327;padding:20px 24px;border-radius:8px 8px 0 0;">
                    <h1 style="margin:0;color:#fff;font-size:18px;font-weight:600;">SEO Monitor</h1>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:24px;">
                    <div style="display:inline-block;padding:6px 14px;border-radius:4px;background:' . $status_color . ';color:#fff;font-weight:600;font-size:14px;margin-bottom:16px;">
                        ' . $status_icon . ' ' . $status_label . '
                    </div>

                    <h2 style="margin:0 0 16px;font-size:20px;color:#1d2327;">' . esc_html($title) . '</h2>

                    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
                        <tr><td style="padding:8px 16px;color:#999;font-size:13px;width:120px;">Type</td><td style="padding:8px 16px;">' . esc_html($type_label) . '</td></tr>
                        <tr style="background:#f9fafb;"><td style="padding:8px 16px;color:#999;font-size:13px;">Category</td><td style="padding:8px 16px;"><strong>' . esc_html($cat_label) . '</strong></td></tr>
                        <tr><td style="padding:8px 16px;color:#999;font-size:13px;">Refresh Type</td><td style="padding:8px 16px;">' . esc_html($item->refresh_type) . '</td></tr>
                        <tr style="background:#f9fafb;"><td style="padding:8px 16px;color:#999;font-size:13px;">Priority Score</td><td style="padding:8px 16px;">' . esc_html($item->priority_score) . '</td></tr>
                        ' . $before_row . '
                        ' . $error_row . '
                    </table>

                    <div style="margin-top:20px;">
                        <a href="' . esc_url($view_url) . '" style="display:inline-block;padding:10px 20px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;font-size:14px;margin-right:8px;">View Page</a>
                        <a href="' . esc_url($edit_url) . '" style="display:inline-block;padding:10px 20px;background:#f0f0f1;color:#1d2327;text-decoration:none;border-radius:4px;font-weight:600;font-size:14px;border:1px solid #c3c4c7;">Edit in WordPress</a>
                    </div>

                    <p style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;color:#999;font-size:12px;">
                        Sent by SEO Monitor on ' . esc_html(get_bloginfo('name')) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '
                    </p>
                </div>
            </div>';

            $set_html = function () { return 'text/html'; };
            add_filter('wp_mail_content_type', $set_html);
            wp_mail($settings['notify_email'], $subject, $body);
            remove_filter('wp_mail_content_type', $set_html);
        }

        return [
            'post_id'  => $post_id,
            'status'   => $status,
            'category' => $item->category,
            'type'     => $item->refresh_type,
            'error'    => $error,
        ];
    }
}
