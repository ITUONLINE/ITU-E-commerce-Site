<?php
/*
Plugin Name: SEO AI AutoPilot
Description: AI-powered SEO automation platform — monitors Google Search Console, scores pages, executes AI content refresh with competitive research, tracks goals, and manages the full content optimization lifecycle.
Version: 1.0
Author: ITU Online
Requires Plugins: ai-product-manager
*/

if (!defined('ABSPATH')) exit;

// ─── Block WordPress.org update checks for all custom plugins ───────────────
// Prevents WP from matching our custom slugs to unrelated public plugins.
add_filter('site_transient_update_plugins', function ($transient) {
    if (!is_object($transient)) return $transient;
    $custom_plugins = [
        'seo-ai-autopilot/seo-ai-autopilot.php',
        'blog-queue/blog-queue.php',
        'Blog_Writer_Plugin/Blog_Writer_Plugin.php',
        'ai-product-manager/ai-product-manager.php',
        'practice-test-manager/practice-test-manager.php',
        'lms-api/lms-api.php',
        'seo-content-about.php',
    ];
    foreach ($custom_plugins as $plugin) {
        unset($transient->response[$plugin]);
    }
    return $transient;
});

// Block "View Details" modal from loading wrong plugin info
add_filter('plugins_api', function ($result, $action, $args) {
    $blocked_slugs = ['seo-ai-autopilot', 'blog-queue', 'blog-writer-plugin', 'ai-product-manager', 'practice-test-manager', 'lms-api', 'seo-content-about'];
    if ($action === 'plugin_information' && isset($args->slug) && in_array($args->slug, $blocked_slugs, true)) {
        return new WP_Error('no_plugin', 'This is a custom plugin. No updates are available from WordPress.org.');
    }
    return $result;
}, 10, 3);

define('SEOM_VERSION', '1.0');
define('SEOM_PATH', plugin_dir_path(__FILE__));
define('SEOM_URL', plugin_dir_url(__FILE__));

// Load OAuth client credentials (config.php is gitignored — see config.php for setup)
if (file_exists(SEOM_PATH . 'config.php')) {
    require_once SEOM_PATH . 'config.php';
}

/**
 * Returns true when local GCP OAuth credentials are wired up (used for the
 * direct/self-hosted GCP fallback path on the operator's own site).
 */
function seom_oauth_configured() {
    return defined('SEOM_GSC_OAUTH_CLIENT_ID')
        && defined('SEOM_GSC_OAUTH_CLIENT_SECRET')
        && SEOM_GSC_OAUTH_CLIENT_ID
        && SEOM_GSC_OAUTH_CLIENT_SECRET
        && strpos(SEOM_GSC_OAUTH_CLIENT_ID, 'YOUR_GCP') === false
        && strpos(SEOM_GSC_OAUTH_CLIENT_SECRET, 'YOUR_GCP') === false;
}

/**
 * Returns true when the SaaS OAuth proxy is wired up. This is the primary
 * connect path for resellers — they never see GCP. The proxy is responsible
 * for holding the GCP client secret centrally.
 */
function seom_oauth_proxy_configured() {
    return defined('SEOM_GSC_PROXY_URL')
        && defined('SEOM_GSC_PROXY_SHARED_SECRET')
        && SEOM_GSC_PROXY_URL
        && SEOM_GSC_PROXY_SHARED_SECRET
        && strpos(SEOM_GSC_PROXY_SHARED_SECRET, 'REPLACE_') === false
        && strpos(SEOM_GSC_PROXY_URL, 'YOUR_') === false;
}

/**
 * Returns the redirect URI we register with Google. Must match the value
 * configured in the GCP OAuth client byte-for-byte.
 */
function seom_oauth_redirect_uri() {
    return admin_url('admin.php?page=seo-ai-autopilot&tab=settings&oauth_callback=1');
}

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

    wp_redirect(admin_url('admin.php?page=seo-ai-autopilot&seom_cleaned=1'));
    exit;
});

// Show cleanup success notice
add_action('admin_notices', function () {
    if (!isset($_GET['seom_cleaned'])) return;
    echo '<div class="notice notice-success is-dismissible"><p><strong>SEO AI AutoPilot:</strong> All caches, locks, and temp files cleared.</p></div>';
});

// Add cleanup button to admin bar for quick access
add_action('admin_bar_menu', function ($admin_bar) {
    if (!current_user_can('manage_woocommerce')) return;

    $admin_bar->add_node([
        'id'    => 'seom-cleanup',
        'title' => 'SEO AI AutoPilot: Clear Cache',
        'href'  => admin_url('?seom_cleanup=1'),
        'meta'  => ['title' => 'Clear all SEO AI AutoPilot caches, locks, and temp files'],
    ]);

    // ── Front-end "AI Refresh" menu ──
    // Adds an admin bar dropdown when an admin views a single post/product/page.
    // Each option queues the post into the SEO AI AutoPilot priority queue, which
    // is processed by the seom_priority_check cron (every 5 minutes) or any
    // already-running queue worker. The user gets an email when it completes.
    if (!is_admin() && is_singular() && current_user_can('manage_options')) {
        $post_id = get_queried_object_id();
        $post_type = $post_id ? get_post_type($post_id) : '';
        if ($post_id && in_array($post_type, ['post', 'product', 'page'], true)) {
            // ── SEO stats node — most recent 28-day GSC snapshot for this URL ──
            global $wpdb;
            $metrics_table = $wpdb->prefix . 'seom_page_metrics';
            $stats = $wpdb->get_row($wpdb->prepare(
                "SELECT clicks, impressions, avg_position, date_collected
                 FROM {$metrics_table}
                 WHERE post_id = %d
                 ORDER BY date_collected DESC
                 LIMIT 1",
                $post_id
            ));

            if ($stats) {
                $clicks_fmt = number_format_i18n((int) $stats->clicks);
                $impr_fmt   = number_format_i18n((int) $stats->impressions);
                $pos_fmt    = number_format((float) $stats->avg_position, 1);
                $bar_title  = sprintf(
                    '<span class="ab-icon dashicons dashicons-chart-line" style="margin-top:2px;"></span> %s clicks · %s impr · pos %s',
                    $clicks_fmt, $impr_fmt, $pos_fmt
                );
                $tooltip = sprintf(
                    '28-day GSC snapshot as of %s — clicks: %s, impressions: %s, avg position: %s',
                    $stats->date_collected, $clicks_fmt, $impr_fmt, $pos_fmt
                );
            } else {
                $bar_title = '<span class="ab-icon dashicons dashicons-chart-line" style="margin-top:2px;"></span> No GSC data yet';
                $tooltip   = 'No row in seom_page_metrics for this post yet — wait for tonight\'s collector run.';
            }

            $admin_bar->add_node([
                'id'    => 'seom-page-stats',
                'title' => $bar_title,
                'href'  => admin_url('admin.php?page=seo-ai-autopilot#indexed'),
                'meta'  => [
                    'title' => $tooltip,
                ],
            ]);

            $label = $post_type === 'product'
                ? 'AI Refresh Product'
                : ($post_type === 'page' ? 'AI Refresh Page' : 'AI Refresh Blog');

            $admin_bar->add_node([
                'id'    => 'seom-ai-refresh',
                'title' => '<span class="ab-icon dashicons dashicons-update" style="margin-top:2px;"></span> ' . $label,
                'href'  => '#',
                'meta'  => [
                    'class' => 'seom-ai-refresh-node',
                    'title' => 'Queue this page for an AI refresh — runs in the background',
                ],
            ]);

            // Full Refresh — only for posts and products (pages don't get content rewrites)
            if (in_array($post_type, ['post', 'product'], true)) {
                $admin_bar->add_node([
                    'id'     => 'seom-ai-refresh-full',
                    'parent' => 'seom-ai-refresh',
                    'title'  => 'Full Refresh (Rewrite All)',
                    'href'   => '#',
                    'meta'   => ['class' => 'seom-ai-refresh-trigger', 'data-type' => 'full'],
                ]);
            }

            // SEO Refresh — blog posts only (FAQ + Schema + Meta + Title)
            if ($post_type === 'post') {
                $admin_bar->add_node([
                    'id'     => 'seom-ai-refresh-seo',
                    'parent' => 'seom-ai-refresh',
                    'title'  => 'SEO Refresh (FAQ, Schema, Meta)',
                    'href'   => '#',
                    'meta'   => ['class' => 'seom-ai-refresh-trigger', 'data-type' => 'seo'],
                ]);
            }

            // CTR Fix / Meta Only — available for all supported types
            $admin_bar->add_node([
                'id'     => 'seom-ai-refresh-meta',
                'parent' => 'seom-ai-refresh',
                'title'  => 'CTR Fix (Meta, Keyword, Title)',
                'href'   => '#',
                'meta'   => ['class' => 'seom-ai-refresh-trigger', 'data-type' => 'meta'],
            ]);

            // ── Featured Product node ──
            // Blog posts only. Opens a modal that lets the admin attach a
            // WooCommerce product, immediately injects the shortcode into
            // the post body, and reloads the page so the result is visible
            // without running an AI refresh.
            if ($post_type === 'post') {
                $current_fp = (int) get_post_meta($post_id, defined('SEOM_FEATURED_PRODUCT_META_KEY') ? SEOM_FEATURED_PRODUCT_META_KEY : '_seom_featured_product_id', true);
                $fp_label   = $current_fp ? 'Featured Product ✓' : 'Inject Featured Product';
                $admin_bar->add_node([
                    'id'    => 'seom-featured-product',
                    'title' => '<span class="ab-icon dashicons dashicons-cart" style="margin-top:2px;"></span> ' . $fp_label,
                    'href'  => '#',
                    'meta'  => [
                        'class' => 'seom-featured-product-node',
                        'title' => 'Attach a WooCommerce product to this post and inject the Featured Product block immediately',
                    ],
                ]);
            }
        }
    }
}, 999);

// ── Front-end JS that powers the admin bar "AI Refresh" menu ──
// Lives here in the plugin so it travels with the plugin instead of depending
// on a site-specific child theme. Loaded only on singular post/product/page
// views for users who can manage_options.
add_action('wp_footer', function () {
    if (is_admin() || !is_singular() || !current_user_can('manage_options')) return;

    $post_id = get_queried_object_id();
    $post_type = $post_id ? get_post_type($post_id) : '';
    if (!$post_id || !in_array($post_type, ['post', 'product', 'page'], true)) return;

    $nonce = wp_create_nonce('seom_nonce');
    $orig_label = $post_type === 'product'
        ? 'AI Refresh Product'
        : ($post_type === 'page' ? 'AI Refresh Page' : 'AI Refresh Blog');
    ?>
    <script>
    (function() {
        var postId = <?php echo (int) $post_id; ?>;
        var nonce = '<?php echo esc_js($nonce); ?>';
        var origLabel = '<?php echo esc_js($orig_label); ?>';
        var queued = false;

        function setBarText(html) {
            var a = document.querySelector('#wp-admin-bar-seom-ai-refresh > a');
            if (a) a.innerHTML = html;
        }
        function resetBar() {
            setBarText('<span class="ab-icon dashicons dashicons-update" style="margin-top:2px;"></span> ' + origLabel);
        }

        document.addEventListener('click', function(e) {
            var link = e.target.closest('#wp-admin-bar-seom-ai-refresh-full a, #wp-admin-bar-seom-ai-refresh-seo a, #wp-admin-bar-seom-ai-refresh-meta a');
            if (!link) return;
            e.preventDefault();

            if (queued) { alert('Already queued. Wait for the completion email.'); return; }

            var menuId = link.closest('li').id;
            var type = 'full';
            if (menuId.indexOf('seo') !== -1) type = 'seo';
            else if (menuId.indexOf('meta') !== -1) type = 'meta';

            var labels = {full: 'Full Refresh', seo: 'SEO Refresh', meta: 'CTR Fix (Meta Only)'};
            if (!confirm('Queue ' + labels[type] + ' for this page? It will run in the background and you\'ll get an email when it completes.')) return;

            setBarText('<span class="ab-icon dashicons dashicons-update" style="margin-top:2px;animation:rotation 1s linear infinite;"></span> Queueing...');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo esc_url(admin_url('admin-ajax.php')); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.timeout = 30000;
            xhr.onload = function() {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        queued = true;
                        setBarText('<span class="ab-icon dashicons dashicons-yes-alt" style="margin-top:2px;color:#16a34a;"></span> Queued — processing in background');
                        var msg = (resp.data && resp.data.message) || 'Queued for priority processing.';
                        alert(msg + '\n\nYou can close this page.');
                    } else {
                        resetBar();
                        alert('Failed to queue: ' + (resp.data || 'Unknown error'));
                    }
                } catch (e) {
                    resetBar();
                    alert('Failed to queue (invalid server response).');
                }
            };
            xhr.onerror = xhr.ontimeout = function() {
                resetBar();
                alert('Request failed or timed out while queueing.');
            };
            xhr.send('action=seom_queue_refresh_now&nonce=' + nonce + '&post_id=' + postId + '&type=' + type);
        });
    })();
    </script>

    <?php if ($post_type === 'post') :
        $current_fp = (int) get_post_meta($post_id, SEOM_FEATURED_PRODUCT_META_KEY, true);
        $current_fp_title = '';
        if ($current_fp) {
            $fp = get_post($current_fp);
            if ($fp && $fp->post_type === 'product') $current_fp_title = $fp->post_title;
        }
        ?>
        <!-- Featured Product Picker Modal (front-end) -->
        <div id="seom-fp-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:999999;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
            <div style="max-width:580px;margin:80px auto;background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,0.35);overflow:hidden;">
                <div style="padding:18px 24px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;font-size:16px;color:#1e293b;">Inject Featured Product</h3>
                    <button type="button" id="seom-fp-close" style="border:none;background:none;font-size:22px;line-height:1;cursor:pointer;color:#64748b;padding:0 4px;">&times;</button>
                </div>
                <div style="padding:20px 24px;">
                    <div id="seom-fp-current" style="<?php echo $current_fp_title ? '' : 'display:none;'; ?>margin-bottom:14px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:13px;color:#065f46;">
                        <strong>Currently featured:</strong> <?php echo esc_html($current_fp_title); ?>
                    </div>
                    <p style="margin:0 0 10px;font-size:12px;color:#64748b;line-height:1.5;">
                        Search for a WooCommerce product. The <code style="font-size:11px;background:#f1f5f9;padding:1px 4px;border-radius:3px;">[itu_featured_product]</code> shortcode will be inserted into the post body after the first paragraph and near the bottom, then the page will reload so you can see the result.
                    </p>
                    <input type="text" id="seom-fp-search" placeholder="Search products by title…" autocomplete="off"
                           style="width:100%;padding:9px 12px;border:1px solid #c3c4c7;border-radius:6px;font-size:14px;box-sizing:border-box;" />
                    <div id="seom-fp-results" style="display:none;margin-top:6px;max-height:240px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;background:#fff;"></div>
                    <div style="margin-top:16px;display:flex;gap:8px;justify-content:space-between;align-items:center;">
                        <?php if ($current_fp) : ?>
                            <button type="button" id="seom-fp-clear" style="padding:7px 14px;border:1px solid #fecaca;background:#fff;color:#dc2626;border-radius:6px;cursor:pointer;font-size:13px;">Remove Featured Product</button>
                        <?php else : ?>
                            <span></span>
                        <?php endif; ?>
                        <button type="button" id="seom-fp-cancel" style="padding:7px 14px;border:1px solid #c3c4c7;background:#fff;color:#475569;border-radius:6px;cursor:pointer;font-size:13px;">Cancel</button>
                    </div>
                    <div id="seom-fp-status" style="margin-top:10px;font-size:12px;text-align:right;min-height:16px;"></div>
                </div>
            </div>
        </div>
        <script>
        (function() {
            var postId = <?php echo (int) $post_id; ?>;
            var nonce  = '<?php echo esc_js($nonce); ?>';
            var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var modal   = document.getElementById('seom-fp-modal');
            var search  = document.getElementById('seom-fp-search');
            var results = document.getElementById('seom-fp-results');
            var status  = document.getElementById('seom-fp-status');
            var searchTimer = null;

            function openModal() {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
                search.value = '';
                results.style.display = 'none';
                results.innerHTML = '';
                status.innerHTML = '';
                setTimeout(function() { search.focus(); runSearch(''); }, 50);
            }
            function closeModal() {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }

            // Open from the admin bar node
            document.addEventListener('click', function(e) {
                var trigger = e.target.closest('#wp-admin-bar-seom-featured-product a');
                if (trigger) {
                    e.preventDefault();
                    openModal();
                }
            });

            document.getElementById('seom-fp-close').addEventListener('click', closeModal);
            document.getElementById('seom-fp-cancel').addEventListener('click', closeModal);
            modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'block') closeModal();
            });

            // Search
            function runSearch(term) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (!resp.success) return;
                        results.innerHTML = '';
                        if (!resp.data.products.length) {
                            results.innerHTML = '<div style="padding:10px 14px;color:#94a3b8;font-size:13px;">No products found.</div>';
                        } else {
                            resp.data.products.forEach(function(p) {
                                var row = document.createElement('div');
                                row.className = 'seom-fp-row';
                                row.dataset.id = p.id;
                                row.dataset.title = p.title;
                                row.style.cssText = 'padding:9px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;color:#1e293b;';
                                row.textContent = p.title;
                                row.addEventListener('mouseover', function() { row.style.background = '#f8fafc'; });
                                row.addEventListener('mouseout',  function() { row.style.background = ''; });
                                row.addEventListener('click', function() { pickProduct(p.id, p.title); });
                                results.appendChild(row);
                            });
                        }
                        results.style.display = 'block';
                    } catch (e) {}
                };
                xhr.send('action=seom_search_products&nonce=' + nonce + '&term=' + encodeURIComponent(term));
            }
            search.addEventListener('input', function() {
                clearTimeout(searchTimer);
                var term = search.value;
                searchTimer = setTimeout(function() { runSearch(term); }, 250);
            });

            // Pick — save + inject + reload
            function pickProduct(productId, productTitle) {
                if (!confirm('Inject "' + productTitle + '" as the Featured Product on this post?\n\nThis will update the post body and reload the page.')) return;
                status.innerHTML = '<span style="color:#b45309;">Saving and injecting…</span>';
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.timeout = 30000;
                xhr.onload = function() {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.success) {
                            status.innerHTML = '<span style="color:#059669;">Done. Reloading…</span>';
                            setTimeout(function() { window.location.reload(); }, 600);
                        } else {
                            status.innerHTML = '<span style="color:#dc2626;">Error: ' + (resp.data || 'Save failed') + '</span>';
                        }
                    } catch (e) {
                        status.innerHTML = '<span style="color:#dc2626;">Invalid server response.</span>';
                    }
                };
                xhr.onerror = xhr.ontimeout = function() {
                    status.innerHTML = '<span style="color:#dc2626;">Request failed or timed out.</span>';
                };
                xhr.send('action=seom_inject_featured_product_now&nonce=' + nonce + '&post_id=' + postId + '&product_id=' + productId);
            }

            // Clear button
            var clearBtn = document.getElementById('seom-fp-clear');
            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    if (!confirm('Remove the Featured Product from this post and strip the shortcode from the body?')) return;
                    status.innerHTML = '<span style="color:#b45309;">Clearing…</span>';
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success) {
                                status.innerHTML = '<span style="color:#059669;">Cleared. Reloading…</span>';
                                setTimeout(function() { window.location.reload(); }, 600);
                            } else {
                                status.innerHTML = '<span style="color:#dc2626;">Error: ' + (resp.data || 'Clear failed') + '</span>';
                            }
                        } catch (e) {}
                    };
                    xhr.send('action=seom_inject_featured_product_now&nonce=' + nonce + '&post_id=' + postId + '&product_id=0');
                });
            }
        })();
        </script>
    <?php endif; ?>

    <style>
    @keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>
    <?php
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
    if (!wp_next_scheduled('seom_daily_goal_email')) {
        wp_schedule_event(strtotime('today 08:00'), 'daily', 'seom_daily_goal_email');
    }
    // Priority queue check — runs every 5 minutes to pick up admin-bar manual
    // refreshes (priority 100). Lightweight: bails immediately if there's no
    // priority work or a worker is already running.
    if (!wp_next_scheduled('seom_priority_check')) {
        wp_schedule_event(time() + 60, 'seom_five_minutes', 'seom_priority_check');
    }
}

// Custom 5-minute schedule for the priority queue check
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['seom_five_minutes'])) {
        $schedules['seom_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 Minutes (SEO AI AutoPilot)',
        ];
    }
    return $schedules;
});

// Self-heal: ensure the priority check is scheduled even on existing installs
// where the activation hook already ran before this code was added. Runs once
// per request and only does work if the event isn't already scheduled.
add_action('init', function () {
    if (!wp_next_scheduled('seom_priority_check')) {
        wp_schedule_event(time() + 60, 'seom_five_minutes', 'seom_priority_check');
    }
}, 20);

register_deactivation_hook(__FILE__, 'seom_deactivate');
function seom_deactivate() {
    wp_clear_scheduled_hook('seom_daily_collect');
    wp_clear_scheduled_hook('seom_daily_analyze');
    wp_clear_scheduled_hook('seom_daily_process');
    wp_clear_scheduled_hook('seom_priority_check');
    wp_clear_scheduled_hook('seom_weekly_backfill');
    wp_clear_scheduled_hook('seom_process_next');
    wp_clear_scheduled_hook('seom_daily_keywords');
    wp_clear_scheduled_hook('seom_weekly_autocomplete');
    wp_clear_scheduled_hook('seom_daily_goal_email');
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
        ctr decimal(7,4) NOT NULL DEFAULT 0,
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
        ctr_before decimal(7,4) DEFAULT NULL,
        clicks_after_30d int DEFAULT NULL,
        impressions_after_30d int DEFAULT NULL,
        position_after_30d decimal(5,1) DEFAULT NULL,
        ctr_after_30d decimal(7,4) DEFAULT NULL,
        clicks_after_60d int DEFAULT NULL,
        impressions_after_60d int DEFAULT NULL,
        position_after_60d decimal(5,1) DEFAULT NULL,
        ctr_after_60d decimal(7,4) DEFAULT NULL,
        research_results text DEFAULT NULL,
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
        ctr decimal(7,4) DEFAULT 0,
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

    // Daily per-page GSC metrics — true single-day data for historical analysis
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_daily_page_metrics (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        date_collected date NOT NULL,
        post_id bigint(20) unsigned NOT NULL DEFAULT 0,
        url varchar(500) NOT NULL,
        clicks int NOT NULL DEFAULT 0,
        impressions int NOT NULL DEFAULT 0,
        ctr decimal(7,4) NOT NULL DEFAULT 0,
        avg_position decimal(5,1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY date_url (date_collected, url(191)),
        KEY date_collected (date_collected),
        KEY post_id (post_id)
    ) $charset;");

    // Keyword gaps — imported from SEMrush/Ahrefs competitive gap analysis
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_keyword_gaps (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        keyword varchar(255) NOT NULL,
        search_volume int DEFAULT 0,
        keyword_difficulty int DEFAULT 0,
        cpc decimal(10,2) DEFAULT 0,
        intent varchar(50) DEFAULT NULL,
        your_position int DEFAULT 0,
        competitor_1_position int DEFAULT 0,
        competitor_2_position int DEFAULT 0,
        tag varchar(100) DEFAULT NULL,
        source varchar(30) DEFAULT 'semrush',
        date_imported date NOT NULL,
        last_used_at date DEFAULT NULL,
        used_in_post_id bigint(20) unsigned DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY keyword_source (keyword(191), source),
        KEY tag (tag),
        KEY search_volume (search_volume),
        KEY date_imported (date_imported),
        KEY last_used_at (last_used_at)
    ) $charset;");

    // Keyword usage tracking — cooldown for both GSC and imported gap keywords
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_keyword_usage (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        keyword varchar(255) NOT NULL,
        source varchar(20) NOT NULL DEFAULT 'gsc',
        status varchar(10) NOT NULL DEFAULT 'used',
        used_at date NOT NULL,
        post_id bigint(20) unsigned DEFAULT NULL,
        queue_id bigint(20) unsigned DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY keyword_source (keyword(191), source),
        KEY used_at (used_at),
        KEY status (status)
    ) $charset;");

    // LSI keywords — long-tail keyword discoveries linked to seed keywords
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_lsi_keywords (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        seed_keyword varchar(255) NOT NULL,
        lsi_keyword varchar(255) NOT NULL,
        search_volume int DEFAULT 0,
        source varchar(30) DEFAULT 'ai',
        date_added date NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY seed_lsi (seed_keyword(100), lsi_keyword(100)),
        KEY seed_keyword (seed_keyword(191))
    ) $charset;");

    // Keyword suggestions from autocomplete
    // Goals tracking
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_goals (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        metric varchar(50) NOT NULL,
        direction varchar(10) NOT NULL DEFAULT 'reduce',
        target_value decimal(10,4) NOT NULL,
        target_type varchar(10) NOT NULL DEFAULT 'percent',
        baseline_value decimal(10,4) NOT NULL,
        current_value decimal(10,4) DEFAULT NULL,
        start_date date NOT NULL,
        deadline date NOT NULL,
        priority tinyint NOT NULL DEFAULT 3,
        created_at datetime NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'active',
        ai_assessment text,
        notes text,
        PRIMARY KEY (id),
        KEY status (status),
        KEY deadline (deadline)
    ) $charset;");

    // Untracked URLs — GSC data for URLs with no matching WordPress post
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_untracked_metrics (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        url varchar(500) NOT NULL,
        date_collected date NOT NULL,
        clicks int NOT NULL DEFAULT 0,
        impressions int NOT NULL DEFAULT 0,
        ctr decimal(7,4) NOT NULL DEFAULT 0,
        avg_position decimal(5,1) NOT NULL DEFAULT 0,
        url_type varchar(30) DEFAULT 'other',
        PRIMARY KEY (id),
        UNIQUE KEY url_date (url(191), date_collected),
        KEY date_collected (date_collected),
        KEY url_type (url_type)
    ) $charset;");

    // Daily aggregated metrics summary for trend charts (pre-computed for performance)
    dbDelta("CREATE TABLE {$wpdb->prefix}seom_daily_summary (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        date_collected date NOT NULL,
        total_pages int NOT NULL DEFAULT 0,
        pages_with_impressions int NOT NULL DEFAULT 0,
        ghost_pages int NOT NULL DEFAULT 0,
        total_clicks int NOT NULL DEFAULT 0,
        total_impressions int NOT NULL DEFAULT 0,
        avg_position decimal(5,1) DEFAULT 0,
        avg_ctr decimal(7,4) DEFAULT 0,
        page1_pages int NOT NULL DEFAULT 0,
        page2_pages int NOT NULL DEFAULT 0,
        gsc_total_clicks int DEFAULT NULL,
        gsc_total_impressions int DEFAULT NULL,
        gsc_avg_position decimal(5,1) DEFAULT NULL,
        gsc_avg_ctr decimal(7,4) DEFAULT NULL,
        daily_clicks int DEFAULT NULL,
        daily_impressions int DEFAULT NULL,
        daily_avg_position decimal(5,1) DEFAULT NULL,
        daily_ctr decimal(7,4) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY date_collected (date_collected)
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

// Auto-create/upgrade keyword tables
add_action('admin_init', function () {
    global $wpdb;

    // Keyword gaps table
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        seom_create_tables();
    } else {
        $col = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'last_used_at'");
        if (!$col) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN last_used_at date DEFAULT NULL AFTER date_imported");
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN used_in_post_id bigint(20) unsigned DEFAULT NULL AFTER last_used_at");
            $wpdb->query("ALTER TABLE {$table} ADD KEY last_used_at (last_used_at)");
        }
        $cpc_col = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'cpc'");
        if ($cpc_col && stripos($cpc_col->Type, 'decimal(6,2)') !== false) {
            $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN cpc decimal(10,2) DEFAULT 0");
        }
    }

    // Keyword usage tracking table + LSI table
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") !== $usage_table) {
        seom_create_tables();
    } else {
        $col = $wpdb->get_var("SHOW COLUMNS FROM {$usage_table} LIKE 'status'");
        if (!$col) {
            $wpdb->query("ALTER TABLE {$usage_table} ADD COLUMN status varchar(10) NOT NULL DEFAULT 'used' AFTER source");
            $wpdb->query("ALTER TABLE {$usage_table} ADD COLUMN queue_id bigint(20) unsigned DEFAULT NULL AFTER post_id");
        }
    }
    $lsi_table = $wpdb->prefix . 'seom_lsi_keywords';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$lsi_table}'") !== $lsi_table) {
        seom_create_tables();
    }
    // Daily per-page metrics table
    $dpm_table = $wpdb->prefix . 'seom_daily_page_metrics';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$dpm_table}'") !== $dpm_table) {
        seom_create_tables();
    }
    // Daily summary table
    $summary_table = $wpdb->prefix . 'seom_daily_summary';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$summary_table}'") !== $summary_table) {
        seom_create_tables();
    }
    // Untracked metrics table
    $untracked_table = $wpdb->prefix . 'seom_untracked_metrics';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$untracked_table}'") !== $untracked_table) {
        seom_create_tables();
    }
    $goals_table = $wpdb->prefix . 'seom_goals';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$goals_table}'") !== $goals_table) {
        $charset = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$goals_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            metric varchar(50) NOT NULL,
            direction varchar(10) NOT NULL DEFAULT 'reduce',
            target_value decimal(10,4) NOT NULL,
            target_type varchar(10) NOT NULL DEFAULT 'percent',
            baseline_value decimal(10,4) NOT NULL,
            current_value decimal(10,4) DEFAULT NULL,
            start_date date NOT NULL,
            deadline date NOT NULL,
            priority tinyint NOT NULL DEFAULT 3,
            created_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            ai_assessment text,
            notes text,
            PRIMARY KEY (id),
            KEY status (status),
            KEY deadline (deadline)
        ) {$charset}");
    }
    // Always check for missing columns (table may already exist without them)
    $col = $wpdb->get_var("SHOW COLUMNS FROM {$goals_table} LIKE 'start_date'");
    if (!$col) {
        $wpdb->query("ALTER TABLE {$goals_table} ADD COLUMN start_date date NOT NULL DEFAULT '2026-04-01' AFTER current_value");
    }
    $col2 = $wpdb->get_var("SHOW COLUMNS FROM {$goals_table} LIKE 'priority'");
    if (!$col2) {
        $wpdb->query("ALTER TABLE {$goals_table} ADD COLUMN priority tinyint NOT NULL DEFAULT 3 AFTER deadline");
    }

    // Increase decimal precision for CTR and goal values on existing tables
    $metrics_table = $wpdb->prefix . 'seom_page_metrics';
    $ctr_type = $wpdb->get_row("SHOW COLUMNS FROM {$metrics_table} LIKE 'ctr'");
    if ($ctr_type && strpos($ctr_type->Type, '5,2') !== false) {
        $wpdb->query("ALTER TABLE {$metrics_table} MODIFY ctr decimal(7,4) NOT NULL DEFAULT 0");
    }
    $history_table = $wpdb->prefix . 'seom_refresh_history';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$history_table}'") === $history_table) {
        $ctr_col = $wpdb->get_row("SHOW COLUMNS FROM {$history_table} LIKE 'ctr_before'");
        if ($ctr_col && strpos($ctr_col->Type, '5,2') !== false) {
            $wpdb->query("ALTER TABLE {$history_table} MODIFY ctr_before decimal(7,4) DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$history_table} MODIFY ctr_after_30d decimal(7,4) DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$history_table} MODIFY ctr_after_60d decimal(7,4) DEFAULT NULL");
        }
    }
    $keywords_table = $wpdb->prefix . 'seom_keywords';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$keywords_table}'") === $keywords_table) {
        $kw_ctr = $wpdb->get_row("SHOW COLUMNS FROM {$keywords_table} LIKE 'ctr'");
        if ($kw_ctr && strpos($kw_ctr->Type, '5,2') !== false) {
            $wpdb->query("ALTER TABLE {$keywords_table} MODIFY ctr decimal(7,4) DEFAULT 0");
        }
    }
    $bl_col = $wpdb->get_row("SHOW COLUMNS FROM {$goals_table} LIKE 'baseline_value'");
    if ($bl_col && strpos($bl_col->Type, '10,2') !== false) {
        $wpdb->query("ALTER TABLE {$goals_table} MODIFY target_value decimal(10,4) NOT NULL");
        $wpdb->query("ALTER TABLE {$goals_table} MODIFY baseline_value decimal(10,4) NOT NULL");
        $wpdb->query("ALTER TABLE {$goals_table} MODIFY current_value decimal(10,4) DEFAULT NULL");
    }
    // Add research_results column to refresh history
    $hist_table = $wpdb->prefix . 'seom_refresh_history';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$hist_table}'") === $hist_table) {
        $rc = $wpdb->get_var("SHOW COLUMNS FROM {$hist_table} LIKE 'research_results'");
        if (!$rc) {
            $wpdb->query("ALTER TABLE {$hist_table} ADD COLUMN research_results text DEFAULT NULL AFTER ctr_after_60d");
        }
    }
    // Add daily metric columns to seom_daily_summary
    $ds_table = $wpdb->prefix . 'seom_daily_summary';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$ds_table}'") === $ds_table) {
        $dc = $wpdb->get_var("SHOW COLUMNS FROM {$ds_table} LIKE 'daily_clicks'");
        if (!$dc) {
            $wpdb->query("ALTER TABLE {$ds_table} ADD COLUMN daily_clicks int DEFAULT NULL AFTER gsc_avg_ctr");
            $wpdb->query("ALTER TABLE {$ds_table} ADD COLUMN daily_impressions int DEFAULT NULL AFTER daily_clicks");
            $wpdb->query("ALTER TABLE {$ds_table} ADD COLUMN daily_avg_position decimal(5,1) DEFAULT NULL AFTER daily_impressions");
            $wpdb->query("ALTER TABLE {$ds_table} ADD COLUMN daily_ctr decimal(7,4) DEFAULT NULL AFTER daily_avg_position");
        }
    }

    // ─── Glossary Manager migrations ────────────────────────────────────────
    // The glossary table `ec_it_glossary` (unprefixed) is owned by the theme.
    // We layer two columns onto it: `slug` for stable URL keys and per-term
    // matching, and `definition_original` so AI-expanded definitions can be
    // reverted to whatever the row originally shipped with.
    $glossary_table = 'ec_it_glossary';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$glossary_table}'") === $glossary_table) {
        $slug_col = $wpdb->get_var("SHOW COLUMNS FROM {$glossary_table} LIKE 'slug'");
        if (!$slug_col) {
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN slug varchar(190) DEFAULT NULL AFTER term");
            $wpdb->query("ALTER TABLE {$glossary_table} ADD KEY slug (slug)");
        }
        $orig_col = $wpdb->get_var("SHOW COLUMNS FROM {$glossary_table} LIKE 'definition_original'");
        if (!$orig_col) {
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN definition_original longtext DEFAULT NULL");
        }
        $exp_col = $wpdb->get_var("SHOW COLUMNS FROM {$glossary_table} LIKE 'definition_expanded_at'");
        if (!$exp_col) {
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN definition_expanded_at datetime DEFAULT NULL");
        }
        $scan_col = $wpdb->get_var("SHOW COLUMNS FROM {$glossary_table} LIKE 'crosswalk_scanned_at'");
        if (!$scan_col) {
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN crosswalk_scanned_at datetime DEFAULT NULL");
        }
        // `topic_queued_at` is stamped when a glossary term gets pushed into
        // the blog queue as a topic candidate — used to suppress duplicate
        // suggestions on subsequent gap-finder runs.
        $tq_col = $wpdb->get_var("SHOW COLUMNS FROM {$glossary_table} LIKE 'topic_queued_at'");
        if (!$tq_col) {
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN topic_queued_at datetime DEFAULT NULL");
        }
        // `definition_expanded` holds the AI-generated long-form content.
        // The original short sentence stays in `definition` so the accordion
        // index page can always render the concise version, and the
        // per-term permalink page renders the expanded form.
        $de_col = $wpdb->get_var("SHOW COLUMNS FROM {$glossary_table} LIKE 'definition_expanded'");
        if (!$de_col) {
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN definition_expanded longtext DEFAULT NULL");
            // Backfill: rows expanded under the previous layout have the
            // long form sitting in `definition` and the short snapshot in
            // `definition_original`. Swap them so the new model is
            // consistent: definition = short, definition_expanded = long.
            // The marker for "old-style expanded" is definition_original
            // being non-empty.
            $wpdb->query(
                "UPDATE {$glossary_table}
                 SET definition_expanded = definition,
                     definition          = definition_original
                 WHERE definition_original IS NOT NULL
                   AND definition_original != ''
                   AND (definition_expanded IS NULL OR definition_expanded = '')"
            );
        }
        // Per-row tracking for the OpenAI Batch API. `batch_id` is the
        // OpenAI batch this row was submitted in; `batch_status` is the
        // per-row state ('pending', 'completed', 'failed'). We don't
        // index batch_id because there are at most a few active batches
        // ever — the rows are looked up by term, not by batch.
        $bid_col = $wpdb->get_var("SHOW COLUMNS FROM {$glossary_table} LIKE 'batch_id'");
        if (!$bid_col) {
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN batch_id varchar(80) DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN batch_custom_id varchar(190) DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN batch_status varchar(20) DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN batch_submitted_at datetime DEFAULT NULL");
        }
        // Per-term SEO metadata. `tldr` is the 30-50 word AI answer
        // rendered at the top of the page (data-speakable=true). The
        // FAQ JSON is a list of [{question, answer}, …] that drives
        // both the visible accordion AND the FAQPage JSON-LD schema.
        $st_col = $wpdb->get_var("SHOW COLUMNS FROM {$glossary_table} LIKE 'seo_title'");
        if (!$st_col) {
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN seo_title varchar(190) DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN seo_description varchar(255) DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN tldr text DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN faq_json longtext DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$glossary_table} ADD COLUMN seo_meta_at datetime DEFAULT NULL");
        }
    }

    // Crosswalk table — maps glossary terms (by slug) to the post IDs that
    // reference them, with a relevance score so per-term pages can pick the
    // top-K most relevant blogs deterministically and tail with random picks
    // from lower-scoring matches. Match type captures *where* the term was
    // found (title / heading / body) for analytics and weighting.
    $cw_table = $wpdb->prefix . 'seom_glossary_crosswalk';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$cw_table}'") !== $cw_table) {
        $charset = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$cw_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_slug varchar(190) NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            match_type varchar(20) NOT NULL DEFAULT 'body',
            relevance_score int unsigned NOT NULL DEFAULT 0,
            discovered_at datetime NOT NULL,
            last_verified_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_term_post (term_slug, post_id),
            KEY idx_term_slug (term_slug),
            KEY idx_post_id (post_id),
            KEY idx_relevance (term_slug, relevance_score)
        ) {$charset}");
    }
}, 99);

// ─── Site-wide GSC totals ────────────────────────────────────────────────────

/**
 * Get the true site-wide GSC totals (collected directly from GSC without page dimension).
 * These are the accurate site-wide numbers — not limited to matched WordPress posts.
 *
 * @param string|null $as_of_date Optional date to get historical totals for (YYYY-MM-DD).
 *                                Returns the closest available record on or before this date.
 */
function seom_get_site_totals($as_of_date = null) {
    $empty = ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0, 'date' => null];

    if (!$as_of_date) {
        return get_option('seom_site_totals', $empty);
    }

    // Look up historical totals
    $history = get_option('seom_site_totals_history', []);
    if (empty($history)) return $empty;

    // Find closest date on or before the requested date
    $best = null;
    foreach ($history as $date => $record) {
        if ($date <= $as_of_date) {
            if (!$best || $date > $best['date']) {
                $best = $record;
            }
        }
    }

    return $best ?: $empty;
}

/**
 * Get the cached GSC no-dimension site totals for a specific date range.
 * Populated nightly by SEOM_Collector for ranges 7, 14, 28, 60, 90, 180.
 * Returns null if no cached entry exists for that range (caller falls back
 * to per-page weighted calculation with an "(approx)" label).
 */
function seom_get_site_totals_for_range($days) {
    $cache = get_option('seom_site_totals_by_range', []);
    return $cache[(int) $days] ?? null;
}

// ─── Featured Product ────────────────────────────────────────────────────────
//
// Blog posts can be associated with a WooCommerce product via the
// _seom_featured_product_id post meta. The [itu_featured_product id="…"]
// shortcode renders a styled block using a user-editable HTML template
// stored in the seom_featured_product_template option. The refresher
// re-injects the shortcode on every full refresh so it survives content
// rewrites.
//
// A shared constant keeps the meta key identical across Blog Queue,
// SEO AI AutoPilot, and the refresher so they can't drift out of sync.
if (!defined('SEOM_FEATURED_PRODUCT_META_KEY')) {
    define('SEOM_FEATURED_PRODUCT_META_KEY', '_seom_featured_product_id');
}

/**
 * Default HTML template used when no user-edited template is stored.
 * Inline styles only — the plugin can't depend on site-specific CSS.
 * Placeholders: {title} {short_description} {button_url} {button_label}
 *               {button_target} {permalink} {udemy_url}
 */
function seom_get_default_featured_product_template() {
    return <<<HTML
<div style="margin:32px 0;border:2px dashed #C026D3;background:rgba(192,38,211,0.04);padding:32px 36px;box-sizing:border-box;">
    <div style="font-family:'Fira Code',Menlo,Consolas,monospace;font-size:0.85rem;letter-spacing:2.5px;text-transform:uppercase;color:#C026D3;margin-bottom:14px;font-weight:600;">Featured Product</div>
    <h2 style="margin:0 0 12px;font-size:1.6rem;line-height:1.3;color:#1e293b;">{title}</h2>
    <p style="margin:0 0 22px;color:#475569;font-size:1rem;line-height:1.55;">{short_description}</p>
    <a href="{button_url}" {button_target} style="display:inline-block;padding:12px 26px;font-family:'Fira Code',Menlo,Consolas,monospace;font-size:0.9rem;font-weight:600;color:#C026D3;text-decoration:none;border:1.5px solid #C026D3;border-radius:0;border-top-right-radius:14px;background:#fff;">{button_label}</a>
</div>
HTML;
}

/**
 * Returns the current Featured Product template — user's saved copy if
 * set, otherwise the default. Never returns an empty string.
 */
function seom_get_featured_product_template() {
    $stored = get_option('seom_featured_product_template', '');
    $stored = is_string($stored) ? trim($stored) : '';
    if ($stored === '') return seom_get_default_featured_product_template();
    return $stored;
}

/**
 * Render the [itu_featured_product id="123"] shortcode.
 * Pulls the product's title, short description, permalink and optional
 * ACF "udemy_url" field, fills the placeholders in the stored template,
 * and returns the HTML.
 */
function seom_shortcode_featured_product($atts) {
    $atts = shortcode_atts(['id' => 0], $atts, 'itu_featured_product');
    $product_id = (int) $atts['id'];
    if (!$product_id) return '';

    $post = get_post($product_id);
    if (!$post || $post->post_type !== 'product' || $post->post_status !== 'publish') return '';

    $title = get_the_title($product_id);
    $short_desc = trim(wp_strip_all_tags($post->post_excerpt));
    if ($short_desc === '') {
        $short_desc = wp_trim_words(wp_strip_all_tags($post->post_content), 30, '...');
    }

    // Prefer ACF udemy_url when present — these products send traffic to Udemy.
    $udemy_url = '';
    if (function_exists('get_field')) {
        $udemy_url = trim((string) get_field('udemy_url', $product_id));
    }
    $permalink = get_permalink($product_id);

    if ($udemy_url !== '' && filter_var($udemy_url, FILTER_VALIDATE_URL)) {
        $button_url    = $udemy_url;
        $button_label  = 'Get this course on Udemy at the lowest price →';
        $button_target = 'target="_blank" rel="noopener sponsored"';
    } else {
        $button_url    = $permalink;
        $button_label  = 'View Course →';
        $button_target = '';
    }

    $template = seom_get_featured_product_template();

    // Template is stored as raw HTML by an admin-only settings field,
    // so we intentionally allow HTML. All substituted VALUES are escaped
    // individually to prevent XSS from product titles / descriptions.
    $replacements = [
        '{title}'             => esc_html($title),
        '{short_description}' => esc_html($short_desc),
        '{button_url}'        => esc_url($button_url),
        '{button_label}'      => esc_html($button_label),
        '{button_target}'     => $button_target,  // static string — safe
        '{permalink}'         => esc_url($permalink),
        '{udemy_url}'         => esc_url($udemy_url),
    ];
    return strtr($template, $replacements);
}
// Guard against a site that already ships the same shortcode.
if (!shortcode_exists('itu_featured_product')) {
    add_shortcode('itu_featured_product', 'seom_shortcode_featured_product');
}

// ─── Table of Contents ──────────────────────────────────────────────────────
//
// [itu_toc] shortcode — renders a collapsible "In This Article" panel
// built from the H2/H3 headings in the current post's content. Evaluated
// at render time so it's always in sync with the live headings, even after
// a content refresh. The shortcode itself is preserved through AI rewrites
// (added to the preserved list in step_content).

/**
 * Add id="slug" attributes to all <h2> and <h3> tags in HTML content
 * that don't already have an id. Returns the modified HTML. Deduplicates
 * slugs by appending -2, -3, etc.
 */
function seom_add_heading_ids($html) {
    if (empty($html) || !is_string($html)) return $html;

    $used_ids = [];

    $html = preg_replace_callback('/<(h2)(\s[^>]*)?>(.+?)<\/\1>/is', function ($m) use (&$used_ids) {
        $tag     = $m[1];
        $attrs   = $m[2] ?? '';
        $text    = $m[3];

        // Skip if it already has an id
        if (preg_match('/\bid\s*=/i', $attrs)) {
            return $m[0];
        }

        // Generate slug from the heading text
        $plain = wp_strip_all_tags($text);
        $slug  = sanitize_title($plain);
        if (empty($slug)) $slug = 'section';

        // Deduplicate
        $base = $slug;
        $n = 1;
        while (isset($used_ids[$slug])) {
            $n++;
            $slug = $base . '-' . $n;
        }
        $used_ids[$slug] = true;

        return '<' . $tag . ' id="' . esc_attr($slug) . '"' . $attrs . '>' . $text . '</' . $tag . '>';
    }, $html);

    return $html;
}

/**
 * Build the TOC HTML from the current post's content. Returns the
 * rendered <details> panel or empty string if fewer than 3 headings.
 */
function seom_build_toc_html($content) {
    if (empty($content)) return '';

    // Parse H2 headings only — H3 subheadings are excluded to keep the TOC clean
    if (!preg_match_all('/<(h2)\s[^>]*id="([^"]+)"[^>]*>(.+?)<\/\1>/is', $content, $matches, PREG_SET_ORDER)) {
        return '';
    }

    if (count($matches) < 3) return ''; // not worth a TOC for 1-2 headings

    $items = '';
    foreach ($matches as $m) {
        $level = $m[1]; // h2 or h3
        $id    = $m[2];
        $text  = wp_strip_all_tags($m[3]);
        if (empty($text)) continue;

        // Skip generic intro/conclusion headings
        $lower = strtolower(trim($text));
        if (preg_match('/^(introduction|conclusion|summary|final thoughts|wrap[- ]?up|closing)$/i', $lower)) continue;

        $items .= '<li><a href="#' . esc_attr($id) . '">' . esc_html($text) . '</a></li>';
    }

    if (empty($items)) return '';

    return '<div class="itu-toc" data-collapsed="true">'
        . '<div class="itu-toc__header">'
        .   '<span class="itu-toc__title">In This Article</span>'
        . '</div>'
        . '<nav class="itu-toc__nav"><ol class="itu-toc__list">' . $items . '</ol></nav>'
        . '<div class="itu-toc__expand"><span class="itu-toc__arrow">&#9660;</span></div>'
        . '</div>';
}

/**
 * [itu_toc_placeholder] — renders in the template OUTSIDE post_content,
 * right after the plans bar and before the post body. Reads the current
 * post's content, adds heading IDs, and builds the TOC. This avoids all
 * the issues with trying to inject inside post_content (featured product
 * H2 collision, shortcode ordering, filter priority).
 */
add_shortcode('itu_toc_placeholder', function () {
    if (!is_singular('post') || is_admin()) return '';

    $post_id = get_the_ID();
    if (!$post_id) return '';

    $content = get_post_field('post_content', $post_id);
    if (empty($content)) return '';

    // Need at least 3 H2 headings to justify a TOC
    if (substr_count(strtolower($content), '<h2') < 3) return '';

    // Add id attributes to headings (in-memory for TOC generation)
    $content = seom_add_heading_ids($content);

    return seom_build_toc_html($content);
});

/**
 * Ensure H2 tags in post_content have id attributes so the TOC anchor
 * links actually work when clicked. This filter adds ids at render time
 * without modifying the database.
 */
add_filter('the_content', function ($content) {
    if (!is_singular('post') || is_feed() || is_admin()) return $content;
    return seom_add_heading_ids($content);
}, 5);

/**
 * TOC panel styles — emitted in wp_head on single posts only.
 */
add_action('wp_head', function () {
    if (!is_singular('post')) return;
    ?>
    <style>
    .itu-toc {
        margin: 0 0 28px;
        border: 1px dashed #585858;
        border-radius: 0;
        overflow: hidden;

    }
    .itu-toc__header {
        padding: 12px 20px 0;
    }
    .itu-toc__title {
        font-family: var(--wp--preset--font-family--fira-code, 'Fira Code', Menlo, Consolas, monospace);
        font-size: var(--wp--preset--font-size--medium, 1.1rem);
        font-weight: 600;
        color: #9b9b9b;
        padding-bottom: 10px;
    }
    /* Nav — collapsed shows ~85px, expanded shows all */
    .itu-toc__nav {
        padding: 0 20px;
        max-height: 85px;
        overflow: hidden;
        transition: max-height 0.35s ease;
    }

    .itu-toc[data-collapsed="false"] .itu-toc__nav {
        max-height: 2000px;
        padding-bottom: 12px;
    }
    /* Bottom bar with centered arrow — the expand/collapse trigger */
    .itu-toc__expand {
        background: #86198f;
        text-align: center;
        padding: 0px 0;
        cursor: pointer;
        user-select: none;
        transition: background 0.15s;
    }
    .itu-toc__expand:hover { background: #a21caf; }
    .itu-toc__arrow {
        display: inline-block;
        font-size: 10px;
        color: #fff;
        transition: transform 0.3s ease;
    }
    .itu-toc[data-collapsed="false"] .itu-toc__arrow {
        transform: rotate(180deg);
    }
    /* List styles */
    .itu-toc__list {
        list-style: none;
        margin: 0;
        padding: 0;
        counter-reset: toc;
    }
    .itu-toc__list li {
        margin: 0;
        padding: 3px 0;
    }
    .itu-toc__list li::before {
        counter-increment: toc;
        content: counter(toc) '.';
        display: inline-block;
        width: 24px;
        color: #94a3b8;
        font-weight: 600;
        font-size: 12px;
    }
    .itu-toc__list a {
        color: #C026D3;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
    }
    .itu-toc__list a:hover { color: #86198f; }
    html { scroll-behavior: smooth; }
    </style>
    <script>
    document.addEventListener('click', function(e) {
        var expand = e.target.closest('.itu-toc__expand');
        if (!expand) return;
        var toc = expand.closest('.itu-toc');
        if (!toc) return;
        var collapsed = toc.getAttribute('data-collapsed') !== 'false';
        toc.setAttribute('data-collapsed', collapsed ? 'false' : 'true');
    });
    </script>
    <?php
});


/**
 * Inject [itu_featured_product id="…"] into blog content at the canonical
 * two spots: right after the first </p> and just before the last <h2>.
 * If the shortcode is already present, replaces existing instances to
 * match the current product ID (so a user switching the association
 * updates the body on next refresh).
 */
function seom_inject_featured_product_shortcode($content, $product_id) {
    $product_id = (int) $product_id;
    if (!$product_id || $content === '') return $content;

    $shortcode = '[itu_featured_product id="' . $product_id . '"]';

    // Remove any pre-existing copies (old IDs, malformed, etc.)
    $content = preg_replace('/\[itu_featured_product[^\]]*\]/', '', $content);

    // Insertion 1: after first </p>
    $first_p = strpos($content, '</p>');
    if ($first_p !== false) {
        $content = substr($content, 0, $first_p + 4)
                 . "\n\n" . $shortcode . "\n\n"
                 . substr($content, $first_p + 4);
    } else {
        $content = $shortcode . "\n\n" . $content;
    }

    // Insertion 2: before the last <h2>
    $last_h2 = strrpos($content, '<h2');
    if ($last_h2 !== false) {
        $content = substr($content, 0, $last_h2) . $shortcode . "\n\n" . substr($content, $last_h2);
    } else {
        $content .= "\n\n" . $shortcode;
    }

    return $content;
}

/**
 * Single source of truth for Performance Tracker trend classification.
 *
 * Used by both seom_get_tracker (cards/table) and seom_get_refresh_trends
 * (chart bar). They MUST classify identically or the counts diverge.
 *
 * Rules (per user spec — every page falls into exactly one bucket):
 *  - Strong:           clicks UP   AND impressions UP   AND position improved
 *  - Declining:        clicks DOWN AND impressions DOWN AND position worsened
 *  - Clicks Up:        clicks up (not Strong)
 *  - Clicks Down:      clicks down (not Declining)
 *  - Impressions Up:   impressions up, clicks flat
 *  - Impressions Down: impressions down, clicks flat
 *  - Ranking Up:       position improved or newly ranked, clicks/impressions flat
 *  - Ranking Down:     position worsened or lost ranking, clicks/impressions flat
 *  - Too Early:        < 5 days since refresh, no other movement
 *  - Flat:             everything else
 *
 *  - Position improvement / decline use a 0.3 deadband (matches JS getTrend()
 *    so the row badges in the table are consistent with the bucket).
 *  - Decline classifications require a real prior position (pb > 0). A page
 *    going 0 → 5 isn't "ranking down" — it's newly ranked.
 *
 * @param int   $cb         clicks before
 * @param int   $cn         clicks now
 * @param float $pb         position before
 * @param float $pn         position now
 * @param int   $days_since days since refresh
 * @param int   $ib         impressions before
 * @param int   $in         impressions now
 * @return string one of: strong | clicks_up | ranking_up | declining
 *                       | clicks_down | ranking_down | too_early | flat
 */
function seom_classify_refresh_trend($cb, $cn, $pb, $pn, $days_since, $ib = 0, $in = 0) {
    $cb = (int) $cb;
    $cn = (int) $cn;
    $ib = (int) $ib;
    $in = (int) $in;
    $pb = (float) $pb;
    $pn = (float) $pn;
    $both_ranked  = ($pb > 0 && $pn > 0);
    $pos_improved = $both_ranked && ($pn < $pb - 0.3);
    $pos_declined = $both_ranked && ($pn > $pb + 0.3);
    $newly_ranked = ($pb <= 0 && $pn > 0);
    $lost_ranking = ($pb > 0  && $pn <= 0);

    // Strong = ALL THREE up. Declining = ALL THREE down. Hard buckets first.
    if ($cn > $cb && $in > $ib && $pos_improved) return 'strong';
    if ($cn < $cb && $in < $ib && ($pos_declined || $lost_ranking)) return 'declining';

    // Single-axis movements next. Clicks take priority (highest signal),
    // then impressions, then position.
    if ($cn > $cb) return 'clicks_up';
    if ($cn < $cb) return 'clicks_down';
    if ($in > $ib) return 'impressions_up';
    if ($in < $ib) return 'impressions_down';
    if ($pos_improved || $newly_ranked) return 'ranking_up';
    if ($pos_declined || $lost_ranking) return 'ranking_down';

    if ((int) $days_since < 5) return 'too_early';
    return 'flat';
}

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
        'buried_min_impressions'=> 30,
        'visible_min_impressions' => 500,
        'visible_max_clicks'   => 10,
        'gap_keyword_cooldown'  => 90,
        'gap_seed_categories'  => '',
        'limit_mode'           => 'fixed',
        'limit_max'            => 50,
        'enabled'              => false,
        'dry_run'              => true,
        'notify_email'         => get_option('admin_email'),

        // ── Cybersecurity promo slide-out tab (rendered by the theme) ──
        'cyber_tab_enabled'    => true,
        'cyber_tab_title'      => 'Cybersecurity In Focus - Free Trial',
        'cyber_tab_url'        => 'https://www.ituonline.com/courses/free-courses/cybersecurity-focus-training-10-day-free-trial/',
        'research_enabled'     => false,
        'research_provider'    => 'openai',  // 'openai' or 'perplexity'
        'perplexity_api_key'   => '',
        'research_model'       => '',        // blank = auto-select based on provider
        'research_categories'  => 'B,C,D,E,F',  // which categories trigger research (comma-separated)
        'research_min_impressions' => 0,     // minimum impressions to trigger research (0 = always for enabled categories)
        'research_min_position'    => 0,     // minimum position to trigger (0 = any position, 50 = only pages ranking 50+)

        // ── Authority / AI visibility (phase 1a) ──
        // Drives both the visible publisher byline and the Organization +
        // knowsAbout schema graph emitted on every blog post.
        'authority_org_name'      => 'ITU Online',
        'authority_org_url'       => home_url('/'),
        'authority_founding_date' => '2012-01-01',
        'authority_about_blurb'   => 'IT training provider since 2012, specializing in CompTIA, Cybersecurity, Project Management, Cisco, Microsoft, AWS, Azure, and Cloud certifications.',
        'authority_same_as'       => '',   // newline-separated URLs (LinkedIn, About page, etc.)
        'authority_knows_about'   => "CompTIA Certifications\nCybersecurity\nCloud Computing\nAWS Certifications\nMicrosoft Azure\nProject Management\nITIL\nCisco Networking\nLinux\nDevOps",
        // Sub-team byline mapping. JSON-encoded map: team_name => [tag, tag, ...]
        // (also matched by case-insensitive substring against the post's gap_category).
        'authority_byline_map'    => wp_json_encode([
            'ITU Online Cybersecurity Team'         => ['Cybersecurity', 'CISSP', 'CEH', 'Security+', 'CySA', 'Pentest', 'SOC', 'Ethical Hacking'],
            'ITU Online CompTIA Team'               => ['CompTIA', 'A+', 'Network+', 'Linux+', 'Cloud+', 'Server+', 'Data+'],
            'ITU Online Cloud Team'                 => ['AWS', 'Azure', 'GCP', 'Google Cloud', 'Cloud', 'Kubernetes', 'DevOps'],
            'ITU Online Networking Team'            => ['Cisco', 'CCNA', 'CCNP', 'Networking', 'Network'],
            'ITU Online Project & Service Management Team' => ['PMP', 'PRINCE2', 'ITIL', 'Project Management', 'Scrum', 'Agile', 'Service Management'],
            'ITU Online Microsoft Team'             => ['Microsoft', 'MCSA', 'MCSE', 'M365', 'Office 365', 'Windows Server'],
            'ITU Online Editorial Team'             => [], // fallback when nothing matches
        ]),
        // robots.txt + llms.txt
        'ai_bots_allow'           => true, // false = revert to default WP robots behavior for AI bots
        'llms_txt_enabled'        => true,
        'llms_txt_extra_lines'    => '',   // free-form text appended to the generated llms.txt

        // ── Glossary integration (phase 3a) ──
        // Drives the writer-prompt auto-linking step. The DefinedTermSet
        // schema on /it-glossary/ itself is theme-owned and always on.
        'glossary_links_enabled'      => true,
        'glossary_max_links_per_post' => 5,
        'glossary_url'                => '', // blank = default to home_url('/it-glossary/')
    ]);
}

/**
 * Check if competitive research should run for this specific refresh.
 * Evaluates category, impressions, and position thresholds.
 */
function seom_should_research($category, $seo_ctx, $settings) {
    // Manual refreshes (M) always get research — the user explicitly chose to refresh this page
    if ($category === 'M') return true;

    // Check if this category is in the enabled list
    $enabled_cats = array_map('trim', explode(',', $settings['research_categories'] ?? 'B,C,D,E,F'));
    if (!in_array($category, $enabled_cats)) return false;

    // Check minimum impressions threshold
    $min_imp = intval($settings['research_min_impressions'] ?? 0);
    if ($min_imp > 0 && ($seo_ctx['impressions'] ?? 0) < $min_imp) return false;

    // Check minimum position threshold (0 = any, only research pages ranking worse than X)
    $min_pos = intval($settings['research_min_position'] ?? 0);
    if ($min_pos > 0) {
        $pos = $seo_ctx['position'] ?? 0;
        // If page has no position (ghost), allow research. Otherwise check threshold.
        if ($pos > 0 && $pos < $min_pos) return false;
    }

    return true;
}

/**
 * Unified OpenAI API call that auto-selects Chat Completions or Responses API.
 * GPT-5.x and o-series models use Responses API (no temperature support).
 * GPT-4.1 family uses Chat Completions (temperature supported).
 *
 * @param string $system    System instruction / prompt
 * @param string $user      User message (optional)
 * @param string $model     Model ID
 * @param float  $temperature Temperature (ignored for 5.x models)
 * @param int    $timeout   Request timeout in seconds
 * @return string|WP_Error  Response text or error
 */
function seom_ai_call($system, $user = '', $model = '', $temperature = 0.7, $timeout = 60) {
    if (!$model) $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) return new WP_Error('no_key', 'API key not configured.');

    $use_responses = (bool) preg_match('/^(gpt-5|o[1-9])/', $model);

    if ($use_responses) {
        // Responses API — no temperature, uses 'instructions' + 'input'
        $body = ['model' => $model];
        if (!empty($system)) $body['instructions'] = $system;
        $body['input'] = $user ?: $system;
        if (!empty($user)) $body['input'] = [['role' => 'user', 'content' => $user]];

        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
            'body'    => json_encode($body),
            'timeout' => $timeout,
        ]);

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) return new WP_Error('api_error', $data['error']['message'] ?? "HTTP {$code}");

        $content = '';
        foreach (($data['output'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'message') {
                foreach (($block['content'] ?? []) as $part) {
                    if (($part['type'] ?? '') === 'output_text') $content .= $part['text'] ?? '';
                }
            }
        }
        return trim($content) ?: new WP_Error('empty', 'No content returned.');
    }

    // Chat Completions API — standard for gpt-4.1 family
    $messages = [['role' => 'system', 'content' => $system]];
    if ($user) $messages[] = ['role' => 'user', 'content' => $user];

    $body = ['model' => $model, 'messages' => $messages, 'temperature' => $temperature];
    if (strpos($model, 'gpt-4') !== false && strpos($user . $system, 'JSON') !== false) {
        $body['response_format'] = ['type' => 'json_object'];
    }

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
        'body'    => json_encode($body),
        'timeout' => $timeout,
    ]);

    if (is_wp_error($response)) return $response;
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $content = trim($data['choices'][0]['message']['content'] ?? '');
    return $content ?: new WP_Error('empty', 'No content returned.');
}

/**
 * Get a summary of AI models used for a refresh, based on post type and refresh type.
 * Reads from centralized AI settings if available.
 */
function seom_get_refresh_models_summary($post_type, $refresh_type) {
    if (!function_exists('itu_ai_model')) {
        $default = 'gpt-4.1-nano';
        return "Content: {$default}";
    }

    $models = [];
    if ($post_type === 'post') {
        if ($refresh_type === 'meta_only') {
            $models['Meta'] = itu_ai_model('blog_meta');
            $models['Keyword'] = itu_ai_model('blog_seo');
            $models['SEO Title'] = itu_ai_model('blog_seo_title');
        } else {
            $models['Content'] = itu_ai_model('blog_content');
            $models['Meta'] = itu_ai_model('blog_meta');
            $models['FAQ'] = itu_ai_model('blog_faq');
            $models['Keyword'] = itu_ai_model('blog_seo');
            $models['SEO Title'] = itu_ai_model('blog_seo_title');
        }
    } else {
        if ($refresh_type === 'meta_only') {
            $models['Short Desc'] = itu_ai_model('product_short_desc');
            $models['Keyword'] = itu_ai_model('product_seo');
            $models['SEO Title'] = itu_ai_model('product_seo_title');
        } else {
            $models['Description'] = itu_ai_model('product_description');
            $models['Short Desc'] = itu_ai_model('product_short_desc');
            $models['FAQ'] = itu_ai_model('product_faq');
            $models['Keyword'] = itu_ai_model('product_seo');
            $models['SEO Title'] = itu_ai_model('product_seo_title');
        }
    }

    // Add research model if research was used
    $research_model = function_exists('itu_ai_model') ? itu_ai_model('research') : '';
    if ($research_model) {
        $models['Research'] = $research_model;
    }

    // Deduplicate — if all the same model, just show it once
    $unique = array_unique($models);
    if (count($unique) === 1) {
        return reset($unique) . ' (all steps)';
    }

    // Show each step's model
    $parts = [];
    foreach ($models as $step => $model) {
        $parts[] = "{$step}: {$model}";
    }
    return implode(' | ', $parts);
}

/**
 * Populate the daily summary table for a specific date.
 * Aggregates per-page metrics + GSC site totals into a single row.
 * Safe to call multiple times for the same date (upserts).
 */
function seom_update_daily_summary($date = null) {
    global $wpdb;
    if (!$date) $date = date('Y-m-d');

    $table = $wpdb->prefix . 'seom_page_metrics';
    $summary_table = $wpdb->prefix . 'seom_daily_summary';
    $settings = seom_get_settings();
    $ghost_threshold = intval($settings['ghost_threshold']);

    // Check if we have data for this date
    $has_data = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE date_collected = %s", $date));
    if (!$has_data) return;

    // Aggregate per-page metrics for this date
    $agg = $wpdb->get_row($wpdb->prepare("
        SELECT
            COUNT(DISTINCT m.post_id) as total_pages,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
            SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(%s, INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
            SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
            SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages
        FROM {$table} m
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE m.date_collected = %s
        AND p.post_status = 'publish' AND p.post_type IN ('product','post','page')
    ", $ghost_threshold, $date, $date));

    // Get GSC site totals for this date if available
    $gsc = seom_get_site_totals($date);

    $row = [
        'date_collected'       => $date,
        'total_pages'          => intval($agg->total_pages ?? 0),
        'pages_with_impressions' => intval($agg->pages_with_impressions ?? 0),
        'ghost_pages'          => intval($agg->ghost_pages ?? 0),
        'total_clicks'         => intval($agg->total_clicks ?? 0),
        'total_impressions'    => intval($agg->total_impressions ?? 0),
        'avg_position'         => round(floatval($agg->avg_position ?? 0), 1),
        'avg_ctr'              => round(floatval($agg->avg_ctr ?? 0), 4),
        'page1_pages'          => intval($agg->page1_pages ?? 0),
        'page2_pages'          => intval($agg->page2_pages ?? 0),
        'gsc_total_clicks'     => $gsc['date'] ? intval($gsc['clicks']) : null,
        'gsc_total_impressions'=> $gsc['date'] ? intval($gsc['impressions']) : null,
        'gsc_avg_position'     => $gsc['date'] ? round(floatval($gsc['position']), 1) : null,
        'gsc_avg_ctr'          => $gsc['date'] ? round(floatval($gsc['ctr']), 4) : null,
    ];

    // Upsert
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$summary_table} WHERE date_collected = %s", $date));
    if ($existing) {
        $wpdb->update($summary_table, $row, ['id' => $existing]);
    } else {
        $wpdb->insert($summary_table, $row);
    }
}

/**
 * Backfill daily summaries for all dates that have page_metrics data but no summary row.
 */
function seom_backfill_daily_summaries() {
    global $wpdb;
    $summary_table = $wpdb->prefix . 'seom_daily_summary';
    $metrics_table = $wpdb->prefix . 'seom_page_metrics';

    $missing_dates = $wpdb->get_col("
        SELECT DISTINCT pm.date_collected
        FROM {$metrics_table} pm
        LEFT JOIN {$summary_table} ds ON pm.date_collected = ds.date_collected
        WHERE ds.id IS NULL
        ORDER BY pm.date_collected ASC
    ");

    foreach ($missing_dates as $date) {
        seom_update_daily_summary($date);
    }

    return count($missing_dates);
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
require_once SEOM_PATH . 'includes/class-researcher.php';
require_once SEOM_PATH . 'includes/class-dashboard.php';
require_once SEOM_PATH . 'includes/authority.php';
require_once SEOM_PATH . 'includes/glossary.php';
require_once SEOM_PATH . 'includes/glossary-manager.php';
require_once SEOM_PATH . 'includes/glossary-batch.php';
require_once SEOM_PATH . 'includes/glossary-term-page.php';
require_once SEOM_PATH . 'includes/glossary-sitemap.php';
require_once SEOM_PATH . 'includes/course-archive-schema.php';
require_once SEOM_PATH . 'includes/practice-test-study-guide.php';
require_once SEOM_PATH . 'includes/surgical-insert.php';
require_once SEOM_PATH . 'includes/inline-images.php';

// ─── Cron Hooks ──────────────────────────────────────────────────────────────

add_action('seom_daily_collect', function () {
    // Always record that the cron fired, even if it later errors out — so the
    // freshness banner on the Indexed Pages tab can warn when collection has
    // been silently broken for several days.
    update_option('seom_last_collect_attempt', current_time('mysql'), 'no');

    // Give the cron worker enough time for several GSC API calls + DB inserts
    @set_time_limit(900);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);

    $errors = [];

    // ── Phase 1: full GSC fetch + per-page snapshot + daily T-3 collection ──
    // If this errors out we used to silently bail; now we record the error and
    // STILL try the daily-pages fallback below so a transient failure on the
    // 28-day fetch doesn't take down the daily collection too.
    try {
        $result = SEOM_Collector::run(0);
        if (is_wp_error($result)) {
            $errors[] = 'Phase 1 (GSC fetch + snapshot): ' . $result->get_error_message();
            error_log('[SEO AI AutoPilot] seom_daily_collect phase 1 failed: ' . $result->get_error_message());
        } else {
            // Phase 2: process post batches
            $total_batches = $result['total_batches'] ?? 0;
            for ($i = 1; $i <= $total_batches; $i++) {
                $batch_result = SEOM_Collector::run($i);
                if (is_wp_error($batch_result)) {
                    $errors[] = "Phase 2 batch {$i}: " . $batch_result->get_error_message();
                    error_log('[SEO AI AutoPilot] seom_daily_collect batch ' . $i . ' failed: ' . $batch_result->get_error_message());
                    // Don't break — keep going so other batches can succeed
                }
            }
            // Update today's daily summary for trend charts (success path)
            seom_update_daily_summary();
        }
    } catch (\Throwable $e) {
        $errors[] = 'Phase 1 exception: ' . $e->getMessage();
        error_log('[SEO AI AutoPilot] seom_daily_collect phase 1 threw: ' . $e->getMessage());
    }

    // ── Independent daily-pages fallback ──
    // The daily T-3 per-page collection is critical for the chart and reconciliation,
    // and must NOT be coupled to the success of the (more expensive) 28-day snapshot
    // fetch above. Run it independently so a Phase 1 GSC quota error doesn't also
    // kill daily collection — which is the silent-failure mode we just hit.
    $daily_table = $GLOBALS['wpdb']->prefix . 'seom_daily_page_metrics';
    $t3_date = date('Y-m-d', strtotime('-3 days'));
    $already_have_t3 = (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
        "SELECT COUNT(*) FROM {$daily_table} WHERE date_collected = %s",
        $t3_date
    ));

    if (!$already_have_t3) {
        try {
            $daily_result = SEOM_Collector::collect_daily_pages($t3_date);
            if (is_wp_error($daily_result)) {
                $errors[] = 'Daily T-3 fallback: ' . $daily_result->get_error_message();
                error_log('[SEO AI AutoPilot] seom_daily_collect daily fallback failed: ' . $daily_result->get_error_message());
            }
        } catch (\Throwable $e) {
            $errors[] = 'Daily T-3 fallback exception: ' . $e->getMessage();
            error_log('[SEO AI AutoPilot] seom_daily_collect daily fallback threw: ' . $e->getMessage());
        }
    }

    // Record outcome for the freshness banner on the Indexed Pages tab
    if (empty($errors)) {
        update_option('seom_last_collect', current_time('mysql'), 'no');
        delete_option('seom_last_collect_error');
    } else {
        update_option('seom_last_collect_error', [
            'time'   => current_time('mysql'),
            'errors' => $errors,
        ], 'no');

        // Email the admin so silent failures don't go unnoticed for days
        $settings = seom_get_settings();
        $notify_to = !empty($settings['notify_email']) ? $settings['notify_email'] : get_option('admin_email');
        if ($notify_to) {
            $subject = '[SEO AI AutoPilot] Daily collection had errors';
            $body = "The seom_daily_collect cron ran but encountered errors:\n\n"
                . implode("\n\n", $errors)
                . "\n\nThis may mean GSC data on the dashboard is stale. Visit the Indexed Pages tab and check the data freshness banner above the cards.";
            wp_mail($notify_to, $subject, $body);
        }
    }
});
add_action('seom_daily_analyze', ['SEOM_Analyzer', 'run']);
add_action('seom_daily_process', ['SEOM_Processor', 'start_daily']);
add_action('seom_process_next', ['SEOM_Processor', 'process_next']);
// Delayed cleanup of the session transient after a queue completes — gives the UI
// a 5-minute window to read and display the final state before it disappears.
add_action('seom_clear_queue_session', function () {
    delete_transient('seom_queue_session');
});

// Priority queue check — runs every 5 minutes via cron. If there's a priority
// 100 row pending in the queue (admin-bar manual refresh), kick off the
// processor. Lightweight: bails immediately if no work or worker already running.
add_action('seom_priority_check', function () {
    global $wpdb;

    // Bail if a worker is already processing the queue — it'll pick up our row
    if (get_transient('seom_queue_lock')) return;

    // Quick check: any pending priority-100+ rows waiting?
    $high_priority = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_queue
         WHERE status = 'pending' AND priority_score >= 100"
    );
    if (!$high_priority) return;

    // Bootstrap a manual session if there isn't one already, so the worker
    // runs even if 'enabled' is off (manual overrides automation).
    if (!get_option('seom_queue_processing')) {
        update_option('seom_queue_processing', [
            'started_at' => current_time('mysql'),
            'status'     => 'running',
            'source'     => 'priority_check',
        ], 'no');
    }
    if (!get_transient('seom_queue_session')) {
        set_transient('seom_queue_session', [
            'started_at'    => current_time('mysql'),
            'total_initial' => $high_priority,
            'completed'     => 0,
            'failed'        => 0,
            'current'       => null,
            'source'        => 'priority_check',
        ], 6 * HOUR_IN_SECONDS);
    }

    // Run the processor inline. We're already in a cron context (no browser
    // waiting), so it's safe to call directly without spawning another request.
    if (class_exists('SEOM_Processor')) {
        SEOM_Processor::process_next();
    }
});

// Manual "Process Now" — triggers the cron-based processor immediately
add_action('wp_ajax_seom_start_queue_processing', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;

    // Block double-start: if a background session is already running (manual or cron),
    // refuse to start another one. Concurrent runs would race the queue and cause failures.
    $existing_session = get_transient('seom_queue_session');
    if (is_array($existing_session)) {
        wp_send_json_error('Queue is already processing in the background — wait for it to finish or stop it first.');
    }

    $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_queue WHERE status = 'pending'");
    if (!$pending) wp_send_json_error('No pending items in queue.');

    // Clear any stop/stale flags from previous runs
    // Force-clear all stop/state at the DB level so we don't inherit stale values
    // from a prior crashed/stopped run (object cache and direct-DB checks must agree)
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s, %s, %s, %s)",
        'seom_queue_stop',
        'seom_bg_progress',
        '_transient_seom_queue_lock',
        '_transient_timeout_seom_queue_lock',
        '_transient_seom_queue_session',
        '_transient_timeout_seom_queue_session'
    ));
    wp_cache_delete('seom_queue_stop', 'options');
    wp_cache_delete('seom_bg_progress', 'options');
    wp_cache_delete('seom_queue_lock', 'transient');
    wp_cache_delete('seom_queue_session', 'transient');
    wp_cache_delete('alloptions', 'options');

    // Reset any items stuck in 'processing' from a prior crashed run
    $wpdb->query("
        UPDATE {$wpdb->prefix}seom_refresh_queue
        SET status = 'pending', error_message = NULL, started_at = NULL, completed_at = NULL
        WHERE status = 'processing'
    ");

    // Store processing state for UI
    update_option('seom_queue_processing', [
        'started_at' => current_time('mysql'),
        'status'     => 'running',
    ]);

    // Single source of truth for the active background session — updated by the
    // processor as items complete and read by the poll endpoint. Auto-expires after
    // 6 hours so an orphaned session never sticks around.
    set_transient('seom_queue_session', [
        'started_at'    => current_time('mysql'),
        'total_initial' => $pending,
        'completed'     => 0,
        'failed'        => 0,
        'current'       => null, // {post_id, post_title, refresh_type}
    ], 6 * HOUR_IN_SECONDS);

    // Reset the daily counter so the queue can process today
    update_option('seom_daily_count_' . date('Y-m-d'), 0);

    // ── Detach the response, then run the worker inline ──────────────────────
    // This mirrors the sibling Blog Queue plugin (which runs reliably nightly
    // and on manual click). We do NOT schedule seom_process_next or fire a
    // wp-cron.php loopback: that approach re-spawned into multiple concurrent
    // wp-cron.php requests, each running long AI calls, which exhausted the
    // PHP-FPM worker pool and 504'd the whole site — and on this host the
    // loopback was unreliable to begin with.
    //
    // Instead we acknowledge the AJAX request, flush it with
    // fastcgi_finish_request() so the browser returns instantly, then drain the
    // queue inline in this single (now detached) process. One process, one item
    // at a time — it can never exhaust the worker pool. The browser just polls
    // seom_queue_status to show progress; closing the tab does NOT stop the run.
    wp_clear_scheduled_hook('seom_process_next'); // kill any stray legacy event

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);
    @set_time_limit(0);

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    }
    echo wp_json_encode([
        'success' => true,
        'data'    => ['pending' => $pending, 'message' => 'Queue processing started. It runs in the background — you can leave this page.'],
    ]);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }

    // Drain the queue inline. process_next(true) ignores the daily cap because
    // the user explicitly asked to process the queue now.
    if (class_exists('SEOM_Processor')) {
        SEOM_Processor::process_next(true);
    }
    exit;
});

// Lightweight check — is processing active? (no actual processing, fast response)
add_action('wp_ajax_seom_queue_check', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $session = get_transient('seom_queue_session');
    $stopped = get_option('seom_queue_stop', false);
    wp_send_json_success([
        'is_running' => is_array($session) && !$stopped,
        'source'     => is_array($session) ? ($session['source'] ?? 'manual') : null,
    ]);
});

// Poll processing status — reads the session transient as the single source of truth
add_action('wp_ajax_seom_queue_status', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $session = get_transient('seom_queue_session');
    $stop_flag = (bool) get_option('seom_queue_stop', false);

    // Live remaining count from the queue table (transient doesn't track this in real time)
    $pending    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_queue WHERE status = 'pending'");
    $processing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_queue WHERE status = 'processing'");
    $remaining  = $pending + $processing;

    // Which step the current item is on (seom_bg_progress is the per-item state
    // machine). 'step' is the last initialized/executed step; total_steps is
    // 2 (meta only), 5 (post), or 7 (product). Surfaced so the UI can show
    // "Step n/total" while an item is mid-refresh.
    $bg            = get_option('seom_bg_progress', null);
    $current_step  = is_array($bg) ? (int) ($bg['step'] ?? 0) : 0;
    $current_total = is_array($bg) ? (int) ($bg['total_steps'] ?? 0) : 0;

    if (is_array($session)) {
        $session_stopped = !empty($session['stopped']);

        // Stale detection + auto-recovery: if the session says running but no cron
        // event is scheduled and nothing is processing, the chain has broken.
        // Auto-reset state and re-arm the chain. Only give up after 3 attempts.
        if (!$session_stopped) {
            $next_scheduled = wp_next_scheduled('seom_process_next');

            // Real "last activity" = the freshest heartbeat in the queue table.
            // process_one_step() bumps started_at on the in-flight item every
            // step and stamps completed_at when an item finishes, so the latest
            // of those timestamps reflects genuine forward progress.
            //
            // The old code fell back to $session['started_at'] (the session's
            // fixed start time) whenever 'current' was null — which is exactly
            // the brief gap between items and right after a recovery. idle then
            // grew without bound, so any poll that landed in that gap with
            // processing === 0 counted a false "stale" strike. With the browser
            // driver stepping items in quick succession, those gaps tripped the
            // 3-strike stop almost immediately. Measuring from the table fixes
            // that. We also compare in WP-local time on both sides (was time()
            // [UTC] vs a local timestamp, an offset that skewed idle further).
            $last_ts = $wpdb->get_var(
                "SELECT GREATEST(COALESCE(MAX(started_at), '1970-01-01 00:00:00'),
                                 COALESCE(MAX(completed_at), '1970-01-01 00:00:00'))
                 FROM {$wpdb->prefix}seom_refresh_queue"
            );
            $session_start = strtotime($session['started_at'] ?? current_time('mysql'));
            $last_activity = $last_ts ? strtotime($last_ts) : $session_start;
            if ($last_activity < $session_start) $last_activity = $session_start; // never older than the run
            $idle_seconds = strtotime(current_time('mysql')) - $last_activity;

            // Stale window: idle 3+ minutes with no scheduled event and no processing.
            // Was 600s (10 min) historically; dropped to 180s so a broken cron chain
            // surfaces faster instead of sitting silent for ten minutes per attempt.
            if (!$next_scheduled && $processing === 0 && $idle_seconds > 180 && $remaining > 0) {
                $recovery_attempts = (int) ($session['recovery_attempts'] ?? 0);

                if ($recovery_attempts < 3) {
                    // Auto-recover and re-arm the chain
                    SEOM_Processor::auto_recover_queue();

                    $session['recovery_attempts'] = $recovery_attempts + 1;
                    $session['last_recovery_at']  = current_time('mysql');
                    // Refresh the started_at "heartbeat" so the next stale check has a fresh window
                    $session['current'] = null;
                    set_transient('seom_queue_session', $session, 6 * HOUR_IN_SECONDS);

                    // Don't mark stopped — the chain is being restarted
                } else {
                    // Three strikes — truly give up and require manual reset
                    SEOM_Processor::mark_session_stopped(
                        'stale',
                        "Background processing failed to recover after 3 auto-restart attempts. {$remaining} item(s) remain. Click \"Reset State\" then \"Process Queue\" to try again, or check error logs for the underlying issue.",
                        $remaining
                    );
                    SEOM_Processor::send_queue_stop_email('stale', $remaining);
                    $session = get_transient('seom_queue_session'); // re-read after update
                    $session_stopped = true;
                }
            }
        }

        wp_send_json_success([
            'remaining'         => $remaining,
            'pending'           => $pending,
            'processing'        => $processing,
            'total_initial'     => intval($session['total_initial'] ?? 0),
            'completed'         => intval($session['completed'] ?? 0),
            'failed'            => intval($session['failed'] ?? 0),
            'is_running'        => !$session_stopped && !$stop_flag,
            'stopped'           => $session_stopped || $stop_flag,
            'stop_reason'       => $session['stop_reason'] ?? null,
            'stop_message'      => $session['stop_message'] ?? null,
            'stopped_at'        => $session['stopped_at'] ?? null,
            'current'           => $session_stopped ? null : ($session['current'] ?? null),
            'current_step'      => $session_stopped ? 0 : $current_step,
            'current_total'     => $session_stopped ? 0 : $current_total,
            'started_at'        => $session['started_at'] ?? null,
            'source'            => $session['source'] ?? 'manual',
            'recovery_attempts' => intval($session['recovery_attempts'] ?? 0),
        ]);
    }

    // No active session
    wp_send_json_success([
        'remaining'     => $remaining,
        'pending'       => $pending,
        'processing'    => $processing,
        'total_initial' => 0,
        'completed'     => 0,
        'failed'        => 0,
        'is_running'    => false,
        'stopped'       => $stop_flag,
        'stop_reason'   => null,
        'stop_message'  => null,
        'stopped_at'    => null,
        'current'       => null,
        'started_at'    => null,
        'source'        => null,
    ]);
});

// Process one step of the background queue (called by JS chain, not by poll)
add_action('wp_ajax_seom_process_queue_step', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $manual = get_option('seom_queue_processing', null);
    if (!$manual) wp_send_json_error('Queue processing not active.');

    $stopped = get_option('seom_queue_stop', false);
    if ($stopped) {
        delete_option('seom_queue_stop');
        delete_option('seom_queue_processing');
        delete_option('seom_bg_progress');
        wp_send_json_success(['action' => 'stopped']);
    }

    // Coordinate with the cron worker (process_next). It holds seom_queue_lock
    // for the duration of its batch; if it's active, let it own this step and
    // just report 'busy' so the browser backs off instead of double-processing
    // the same item. Otherwise we claim the lock ourselves for this single step.
    if (get_transient('seom_queue_lock')) {
        wp_send_json_success(['status' => 'busy']);
    }
    set_transient('seom_queue_lock', time(), 5 * MINUTE_IN_SECONDS);

    @set_time_limit(300);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    try {
        $result = SEOM_Processor::process_one_step();
    } catch (\Throwable $e) {
        $result = ['status' => 'error', 'error' => $e->getMessage()];
    } finally {
        delete_transient('seom_queue_lock');
    }

    wp_send_json_success($result ?: ['status' => 'done']);
});

// Mark a queue row as failed from the client side (e.g. on AJAX 504/timeout).
// Used by Process Now / Process button when a step times out so the row doesn't
// stay stuck in 'processing' and the user gets a failure notification.
add_action('wp_ajax_seom_mark_step_failed', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $post_id = intval($_POST['post_id'] ?? 0);
    $step    = sanitize_text_field($_POST['step'] ?? '');
    $reason  = sanitize_text_field($_POST['reason'] ?? 'Step timed out or returned an HTTP error.');
    if (!$post_id) wp_send_json_error('Missing post ID.');

    $error_msg = "Process aborted at {$step}: {$reason}";

    // Update queue row if it exists in processing state
    $item = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}seom_refresh_queue WHERE post_id = %d AND status IN ('pending','processing') ORDER BY id DESC LIMIT 1",
        $post_id
    ));
    if ($item) {
        $wpdb->update("{$wpdb->prefix}seom_refresh_queue", [
            'status'        => 'failed',
            'completed_at'  => current_time('mysql'),
            'error_message' => $error_msg,
        ], ['id' => $item->id]);

        // Record in history so the failure is visible in Performance Tracker
        $wpdb->insert("{$wpdb->prefix}seom_refresh_history", [
            'post_id'       => $post_id,
            'refresh_date'  => current_time('mysql'),
            'refresh_type'  => $item->refresh_type . ' (aborted)',
            'category'      => $item->category,
            'priority_score'=> $item->priority_score,
        ]);
    }

    // Clean up any in-flight progress transient so a future retry starts clean
    delete_transient('seom_step_' . $post_id);
    delete_transient('seom_refresh_context_' . $post_id);

    // Send the same failure email format as the regular processor
    $settings = seom_get_settings();
    if ($settings['notify_email']) {
        $title = get_the_title($post_id);
        $subject = "[SEO AI AutoPilot] Failed: {$title}";
        $edit_url = admin_url('post.php?action=edit&post=' . $post_id);
        $body = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;">'
            . '<div style="background:#1d2327;padding:16px 20px;border-radius:8px 8px 0 0;"><h1 style="margin:0;color:#fff;font-size:16px;">SEO AI AutoPilot</h1></div>'
            . '<div style="background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:20px;">'
            . '<div style="display:inline-block;padding:4px 12px;border-radius:4px;background:#dc2626;color:#fff;font-weight:600;font-size:13px;margin-bottom:12px;">Failed</div>'
            . '<h2 style="margin:0 0 12px;font-size:18px;">' . esc_html($title) . '</h2>'
            . '<p style="color:#dc2626;margin:0 0 16px;">' . esc_html($error_msg) . '</p>'
            . '<a href="' . esc_url($edit_url) . '" style="display:inline-block;padding:8px 16px;background:#f0f0f1;color:#1d2327;text-decoration:none;border-radius:4px;font-size:13px;">Edit</a>'
            . '</div></div>';
        $set_html = function () { return 'text/html'; };
        add_filter('wp_mail_content_type', $set_html);
        wp_mail($settings['notify_email'], $subject, $body);
        remove_filter('wp_mail_content_type', $set_html);
    }

    wp_send_json_success(['marked_failed' => true]);
});

// ─── Admin Bar "AI Refresh" → Queue Now ──────────────────────────────────────
//
// Type-agnostic handler for the front-end admin bar refresh trigger. Works for
// blog posts, products, and pages — adds the post to seom_refresh_queue at
// priority 100 and triggers the background processor (which sends the email
// when the item completes). Returns within ~1s by flushing the response via
// fastcgi_finish_request() before doing any cron work.
//
// Used by: child theme admin bar JS (functions.php)
//
add_action('wp_ajax_seom_queue_refresh_now', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $post_id = intval($_POST['post_id'] ?? 0);
    $type    = sanitize_text_field($_POST['type'] ?? 'full');
    if (!$post_id) wp_send_json_error('Missing post ID.');

    $post_type = get_post_type($post_id);
    if (!in_array($post_type, ['post', 'product', 'page'], true)) {
        wp_send_json_error('Unsupported post type: ' . $post_type);
    }

    // Pages only support meta-only refresh (no full content rewrite)
    $refresh_type = $type === 'meta' ? 'meta_only' : 'full';
    if ($post_type === 'page') $refresh_type = 'meta_only';

    global $wpdb;
    $queue_table = $wpdb->prefix . 'seom_refresh_queue';

    // Insert / bump the queue row
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$queue_table} WHERE post_id = %d AND status IN ('pending','processing') ORDER BY id DESC LIMIT 1",
        $post_id
    ));

    if ($existing) {
        $wpdb->update($queue_table, [
            'priority_score' => 100,
            'refresh_type'   => $refresh_type,
        ], ['id' => (int) $existing]);
        $queue_id = (int) $existing;
    } else {
        $inserted = $wpdb->insert($queue_table, [
            'post_id'        => $post_id,
            'post_type'      => $post_type,
            'priority_score' => 100,
            'category'       => 'M', // Manual
            'refresh_type'   => $refresh_type,
            'status'         => 'pending',
            'queued_at'      => current_time('mysql'),
        ]);
        if ($inserted === false) {
            wp_send_json_error('Failed to insert queue row: ' . $wpdb->last_error);
        }
        $queue_id = (int) $wpdb->insert_id;
    }

    // ── Detect if a worker is already running ──
    // If so, the priority-100 row will be picked up on the worker's next loop
    // iteration. Don't spawn a second cron — they'd race the runner lock.
    $worker_lock = get_transient('seom_queue_lock');
    $session     = get_transient('seom_queue_session');
    $next_event  = wp_next_scheduled('seom_process_next');
    $already_running = ($worker_lock || ($session && empty($session['stopped'])));

    // Schedule a new event only if one isn't already due within the next 60s
    if (!$next_event || $next_event > time() + 60) {
        wp_schedule_single_event(time(), 'seom_process_next');
    }

    $message = $already_running
        ? 'Queued for priority processing. The background worker is already running and will pick this up next.'
        : 'Queued for priority processing. You\'ll receive an email when it completes.';

    // Manually flush JSON to the browser BEFORE doing any cron work, so the
    // user gets the response in <100ms regardless of how long spawn_cron takes.
    $payload = [
        'success' => true,
        'data'    => [
            'queued'          => true,
            'queue_id'        => $queue_id,
            'post_id'         => $post_id,
            'post_type'       => $post_type,
            'refresh_type'    => $refresh_type,
            'already_running' => $already_running,
            'message'         => $message,
        ],
    ];

    @header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    echo wp_json_encode($payload);

    // Flush response to browser
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }

    // Skip cron-spawn if a worker is already running — it'll pick up our row
    if ($already_running) {
        exit;
    }

    // Best-effort fire wp-cron after the response has been delivered
    @ignore_user_abort(true);
    @set_time_limit(15);

    if (function_exists('spawn_cron')) {
        @spawn_cron();
    }
    @wp_remote_post(site_url('/wp-cron.php?doing_wp_cron=' . microtime(true)), [
        'timeout'   => 0.5,
        'blocking'  => false,
        'sslverify' => false,
    ]);

    exit;
});

// Stop queue processing
add_action('wp_ajax_seom_stop_queue', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;

    // Set the stop flag with autoload=no AND force a direct DB write so the
    // long-running worker process picks it up via should_stop() (which queries
    // the options table directly to bypass per-process object cache).
    update_option('seom_queue_stop', '1', 'no');
    // Belt-and-suspenders: also write directly in case object cache is hot
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
         ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
        'seom_queue_stop', '1'
    ));
    wp_cache_delete('seom_queue_stop', 'options');
    wp_cache_delete('alloptions', 'options');

    wp_clear_scheduled_hook('seom_process_next');

    $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_queue WHERE status IN ('pending','processing')");
    SEOM_Processor::mark_session_stopped(
        'manual',
        "Background processing stopped manually. {$remaining} item(s) remain in the queue.",
        $remaining
    );
    SEOM_Processor::send_queue_stop_email('manual', $remaining);

    wp_send_json_success('Queue processing stopped. Current item will finish, then the worker will exit.');
});
add_action('seom_weekly_backfill', ['SEOM_Collector', 'backfill_history']);
add_action('seom_daily_keywords', function () {
    @set_time_limit(600);
    // Phase 0: Fetch GSC data
    $result = SEOM_Keyword_Researcher::collect(0);
    if (is_wp_error($result)) return;

    // Process all batches
    $total_batches = $result['total_batches'] ?? 1;
    for ($b = 1; $b <= $total_batches + 1; $b++) {
        $r = SEOM_Keyword_Researcher::collect($b);
        if (is_wp_error($r)) break;
        if (isset($r['phase']) && $r['phase'] === 'complete') break;
    }
});
add_action('seom_weekly_autocomplete', function () {
    SEOM_Keyword_Researcher::expand_with_autocomplete(50);
});

// Daily goal progress email
add_action('seom_daily_goal_email', function () {
    // ── Idempotency lock ──
    // WP-Cron is not perfectly atomic — when this handler runs slowly
    // (GSC queries + AI auto-creation + heavy email render), overlapping
    // wp-cron.php requests can fire the same scheduled event multiple
    // times before the first one finishes. That was the root cause of
    // the 10-20 identical nightly emails. We stamp today's date in an
    // option and bail if it's already today. Stamping happens BEFORE the
    // heavy work so concurrent fires only race on the get/update window
    // (milliseconds), not on the multi-second handler body.
    $today = date('Y-m-d');
    if (get_option('seom_last_goal_email_date', '') === $today) return;
    update_option('seom_last_goal_email_date', $today, false);

    global $wpdb;
    $goals_table = $wpdb->prefix . 'seom_goals';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$goals_table}'") !== $goals_table) return;

    // ── Monthly goal rollover: on the 1st, close last month's goals and auto-create new ones ──
    if (date('j') === '1') {
        // Close any active goals whose deadline has passed
        $wpdb->query("UPDATE {$goals_table} SET status = 'missed' WHERE status = 'active' AND deadline < CURDATE()");

        // Check if we already have active goals for this month
        $month_end = date('Y-m-t'); // last day of current month
        $existing_this_month = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$goals_table} WHERE status = 'active' AND deadline >= %s",
            $month_end
        ));

        if ($existing_this_month === 0) {
            // Auto-create goals via the same logic as the AJAX handler
            @set_time_limit(60);
            $settings_g = seom_get_settings();
            $table = $wpdb->prefix . 'seom_page_metrics';
            $ghost_threshold = intval($settings_g['ghost_threshold']);
            $daily_limit = intval($settings_g['daily_limit']);
            $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");

            if ($latest_date) {
                $m = $wpdb->get_row($wpdb->prepare("
                    SELECT
                        SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
                        SUM(m.clicks) as total_clicks, SUM(m.impressions) as total_impressions,
                        AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
                        AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
                        SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
                        SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
                        SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
                        COUNT(DISTINCT m.post_id) as total_pages
                    FROM {$table} m
                    JOIN {$wpdb->posts} p ON m.post_id = p.ID
                    WHERE m.date_collected = %s
                    AND p.post_status = 'publish' AND p.post_type IN ('product','post','page')
                ", $ghost_threshold, $latest_date));

                // Override with true GSC site-wide totals
                $st = seom_get_site_totals();
                if ($st['date']) {
                    $m->total_clicks = intval($st['clicks']);
                    $m->total_impressions = intval($st['impressions']);
                    $m->avg_ctr = floatval($st['ctr']);
                    $m->avg_position = floatval($st['position']);
                }

                // Get prior month's results for AI context
                $last_month_goals = $wpdb->get_results("
                    SELECT metric, direction, target_value, target_type, baseline_value, current_value, status
                    FROM {$goals_table} WHERE deadline >= DATE_SUB(CURDATE(), INTERVAL 35 DAY) AND deadline < CURDATE()
                    ORDER BY deadline DESC
                ");
                $history_context = '';
                foreach ($last_month_goals as $lg) {
                    $met = $lg->status === 'completed' ? 'MET' : ($lg->status === 'missed' ? 'MISSED' : $lg->status);
                    $history_context .= "- {$lg->metric}: {$lg->direction} by {$lg->target_value}" . ($lg->target_type === 'percent' ? '%' : '')
                        . " (baseline {$lg->baseline_value}, ended at {$lg->current_value}) — {$met}\n";
                }

                $site_name = get_bloginfo('name');
                $now = new \DateTime();
                $month_end_dt = new \DateTime('last day of this month');
                $days_in_month = $now->diff($month_end_dt)->days;

                // Content production metrics
                $m->new_content_30d = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','product','page') AND post_status = 'publish'");
                $cooldown_d = intval($settings_g['cooldown_days']);
                $m->stale_pages = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'last_page_refresh' WHERE p.post_type IN ('post','product') AND p.post_status = 'publish' AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND (pm.meta_value IS NULL OR pm.meta_value < DATE_SUB(CURDATE(), INTERVAL %d DAY))", $cooldown_d));
                $m->refreshed_this_month = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history WHERE refresh_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");

                $metrics_summary = "Current metrics for {$site_name}:\n"
                    . "- Ghost Pages: {$m->ghost_pages}\n- Total Clicks (28d): {$m->total_clicks}\n"
                    . "- Total Impressions (28d): {$m->total_impressions}\n- Avg Position: " . round($m->avg_position, 1) . "\n"
                    . "- Avg CTR: " . round($m->avg_ctr, 4) . "%\n- Pages on Page 1: {$m->page1_pages}\n"
                    . "- Pages on Page 2: {$m->page2_pages}\n- Pages With Impressions: {$m->pages_with_impressions}\n"
                    . "- Total Pages: {$m->total_pages}\n"
                    . "- Total Published Content: {$m->new_content_30d}\n"
                    . "- Stale Pages (not refreshed 90+ days): {$m->stale_pages}\n"
                    . "- Pages Refreshed This Month: {$m->refreshed_this_month}\n"
                    . "- Daily Refresh Capacity: {$daily_limit}\n"
                    . "- Days in Month: {$days_in_month}\n";
                if ($history_context) $metrics_summary .= "\nLast month's goal results:\n{$history_context}\n";

                $prompt = "You are an SEO strategist. Based on the metrics below, create 3-5 realistic goals for this new month.\n\n"
                    . $metrics_summary . "\n"
                    . "RULES:\n"
                    . "- If prior goals were MISSED, suggest a more conservative version (lower target %)\n"
                    . "- If prior goals were MET, suggest a stretch version (higher target %) or a new metric\n"
                    . "- Use percentage targets (target_type: 'percent')\n"
                    . "- Include a mix of priorities (1=Critical through 5=Backlog)\n"
                    . "- IMPORTANT: For avg_position, ghost_pages, stale_pages, page2_pages, direction MUST be 'reduce'. For all others, direction MUST be 'increase'.\n"
                    . "- Be realistic — SEO changes take 2-4 weeks to show\n\n"
                    . "Return a JSON object: {\"goals\": [{\"metric\": \"ghost_pages\", \"direction\": \"reduce\", \"target_value\": 10, \"target_type\": \"percent\", \"priority\": 2, \"notes\": \"reason\"}]}\n"
                    . "Valid metrics: ghost_pages, total_clicks, total_impressions, avg_position, avg_ctr, page1_pages, page2_pages, pages_with_impressions, new_content_30d, stale_pages, refreshed_this_month";

                $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
                $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

                if ($api_key) {
                    $body_payload = [
                        'model' => $model,
                        'messages' => [
                            ['role' => 'system', 'content' => $prompt],
                            ['role' => 'user', 'content' => 'Generate monthly SEO goals. Return JSON with a "goals" array.'],
                        ],
                        'temperature' => 0.4,
                    ];
                    if (strpos($model, 'gpt-4') !== false) $body_payload['response_format'] = ['type' => 'json_object'];

                    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                        'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
                        'body' => json_encode($body_payload), 'timeout' => 45,
                    ]);

                    if (!is_wp_error($response)) {
                        $data = json_decode(wp_remote_retrieve_body($response), true);
                        $raw = trim($data['choices'][0]['message']['content'] ?? '');
                        $raw = preg_replace('/^```[a-z]*\s*/m', '', $raw);
                        $raw = preg_replace('/\s*```\s*$/m', '', $raw);
                        $goals = json_decode($raw, true);
                        if (isset($goals['goals'])) $goals = $goals['goals'];

                        if (is_array($goals)) {
                            $metric_map = (array) $m;
                            $month_start = date('Y-m-01');
                            foreach ($goals as $g) {
                                $gm = sanitize_text_field($g['metric'] ?? '');
                                if (empty($gm) || !isset($metric_map[$gm])) continue;
                                $wpdb->insert($goals_table, [
                                    'metric'         => $gm,
                                    'direction'      => sanitize_text_field($g['direction'] ?? 'reduce'),
                                    'target_value'   => floatval($g['target_value'] ?? 5),
                                    'target_type'    => sanitize_text_field($g['target_type'] ?? 'percent'),
                                    'baseline_value' => floatval($metric_map[$gm]),
                                    'current_value'  => floatval($metric_map[$gm]),
                                    'start_date'     => $month_start,
                                    'deadline'       => $month_end,
                                    'priority'       => max(1, min(5, intval($g['priority'] ?? 3))),
                                    'created_at'     => current_time('mysql'),
                                    'status'         => 'active',
                                    'ai_assessment'  => '',
                                    'notes'          => sanitize_text_field($g['notes'] ?? '') . ' (auto-created)',
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    $active_goals = $wpdb->get_results("SELECT * FROM {$goals_table} WHERE status = 'active' ORDER BY deadline ASC");
    if (empty($active_goals)) return;

    $settings = seom_get_settings();
    $email = $settings['notify_email'] ?? get_option('admin_email');
    if (empty($email)) return;

    // Get current metrics
    $table = $wpdb->prefix . 'seom_page_metrics';
    $ghost_threshold = intval($settings['ghost_threshold']);
    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");
    if (!$latest_date) return;

    $metrics = $wpdb->get_row($wpdb->prepare("
        SELECT
            SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
            SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
            SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions
        FROM {$table} m
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE m.date_collected = %s
        AND p.post_status = 'publish' AND p.post_type IN ('product','post','page')
    ", $ghost_threshold, $latest_date));

    // Override with true GSC site-wide totals
    $site_totals = seom_get_site_totals();
    if ($site_totals['date']) {
        $metrics->total_clicks = intval($site_totals['clicks']);
        $metrics->total_impressions = intval($site_totals['impressions']);
        $metrics->avg_ctr = floatval($site_totals['ctr']);
        $metrics->avg_position = floatval($site_totals['position']);
    }

    $metric_values = (array) $metrics;
    $metric_labels = [
        'ghost_pages'            => 'Ghost Pages',
        'total_clicks'           => 'Total Clicks (28d)',
        'total_impressions'      => 'Total Impressions (28d)',
        'avg_position'           => 'Avg Position',
        'avg_ctr'                => 'Avg CTR (%)',
        'page1_pages'            => 'Pages on Page 1',
        'page2_pages'            => 'Pages on Page 2',
        'pages_with_impressions' => 'Pages With Impressions',
        'new_content_30d'        => 'Total Published Content',
        'stale_pages'            => 'Stale Pages (90+ days)',
        'refreshed_this_month'   => 'Refreshed This Month',
    ];

    $site_name = get_bloginfo('name');
    $today = date('Y-m-d');

    // Update current values and check deadlines
    $on_track = 0;
    $behind = 0;
    $goal_rows = '';

    foreach ($active_goals as $g) {
        $current = isset($metric_values[$g->metric]) ? floatval($metric_values[$g->metric]) : floatval($g->current_value);
        $wpdb->update($goals_table, ['current_value' => $current], ['id' => $g->id]);

        $baseline = floatval($g->baseline_value);
        $target = floatval($g->target_value);

        // Calculate target number
        if ($g->target_type === 'percent') {
            $change_needed = $baseline * ($target / 100);
            $target_num = ($g->direction === 'reduce') ? $baseline - $change_needed : $baseline + $change_needed;
        } else {
            $target_num = $target;
        }

        // Progress
        $total_change = abs($target_num - $baseline);
        $actual_change = ($g->direction === 'reduce') ? $baseline - $current : $current - $baseline;
        $progress = $total_change > 0 ? min(100, max(0, round(($actual_change / $total_change) * 100))) : 0;

        // Time progress
        $start = $g->start_date ?: substr($g->created_at, 0, 10);
        $total_days = max(1, (strtotime($g->deadline) - strtotime($start)) / 86400);
        $days_elapsed = max(0, (strtotime($today) - strtotime($start)) / 86400);
        $time_pct = min(100, round(($days_elapsed / $total_days) * 100));
        $days_left = max(0, ceil((strtotime($g->deadline) - time()) / 86400));

        $is_on_track = $progress >= $time_pct;
        if ($is_on_track) $on_track++; else $behind++;

        // Check if deadline passed
        if ($today > $g->deadline) {
            $met = ($g->direction === 'reduce') ? ($current <= $target_num) : ($current >= $target_num);
            $wpdb->update($goals_table, ['status' => $met ? 'completed' : 'missed', 'current_value' => $current], ['id' => $g->id]);
        }

        $label = $metric_labels[$g->metric] ?? $g->metric;
        $dir_arrow = ($g->direction === 'reduce') ? '&#9660;' : '&#9650;';
        $type_label = ($g->target_type === 'percent') ? $target . '%' : $target;
        $status_color = $is_on_track ? '#059669' : '#dc2626';
        $status_label = $is_on_track ? 'On Track' : 'Behind';
        $prog_color = $progress >= 75 ? '#059669' : ($progress >= 40 ? '#d97706' : '#dc2626');

        $goal_rows .= '<tr>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;">' . $dir_arrow . ' ' . ucfirst($g->direction) . ' ' . esc_html($label) . ' by ' . $type_label . '</td>';
        $precision = in_array($g->metric, ['avg_ctr', 'avg_position']) ? 2 : 1;
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;">' . round($baseline, $precision) . '</td>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;font-weight:700;">' . round($current, $precision) . '</td>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;">' . round($target_num, $precision) . '</td>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;">';
        $goal_rows .= '<div style="background:#e2e8f0;border-radius:4px;height:10px;width:100px;display:inline-block;vertical-align:middle;">';
        $goal_rows .= '<div style="background:' . $prog_color . ';height:100%;width:' . $progress . '%;border-radius:4px;"></div></div>';
        $goal_rows .= ' <strong>' . $progress . '%</strong></td>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;color:' . $status_color . ';font-weight:600;">' . $status_label . '</td>';
        $goal_rows .= '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;">' . $days_left . 'd</td>';
        $goal_rows .= '</tr>';
    }

    $subject = "[{$site_name}] SEO Goals: {$on_track} on track, {$behind} behind — " . date('M j');

    $body = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:700px;margin:0 auto;">';
    $body .= '<div style="background:#0f172a;color:#fff;padding:20px 28px;border-radius:10px 10px 0 0;">';
    $body .= '<h1 style="margin:0;font-size:20px;color:#fff;">SEO Goal Progress Report</h1>';
    $body .= '<p style="margin:6px 0 0;color:#94a3b8;font-size:13px;">' . $site_name . ' &mdash; ' . date('l, F j, Y') . '</p>';
    $body .= '</div>';

    $body .= '<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;padding:20px 28px;border-radius:0 0 10px 10px;">';

    // Summary cards
    $body .= '<div style="display:flex;gap:12px;margin-bottom:20px;">';
    $body .= '<div style="flex:1;background:#ecfdf5;padding:14px;border-radius:8px;text-align:center;"><div style="font-size:24px;font-weight:700;color:#059669;">' . $on_track . '</div><div style="font-size:11px;color:#065f46;text-transform:uppercase;">On Track</div></div>';
    $body .= '<div style="flex:1;background:#fee2e2;padding:14px;border-radius:8px;text-align:center;"><div style="font-size:24px;font-weight:700;color:#dc2626;">' . $behind . '</div><div style="font-size:11px;color:#991b1b;text-transform:uppercase;">Behind</div></div>';
    $body .= '<div style="flex:1;background:#f1f5f9;padding:14px;border-radius:8px;text-align:center;"><div style="font-size:24px;font-weight:700;color:#334155;">' . count($active_goals) . '</div><div style="font-size:11px;color:#64748b;text-transform:uppercase;">Active Goals</div></div>';
    $body .= '</div>';

    // Goals table
    $body .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
    $body .= '<thead><tr style="background:#f1f5f9;">';
    $body .= '<th style="padding:8px 14px;text-align:left;font-weight:600;border-bottom:2px solid #cbd5e1;">Goal</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Baseline</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Current</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Target</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Progress</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Status</th>';
    $body .= '<th style="padding:8px 14px;text-align:center;font-weight:600;border-bottom:2px solid #cbd5e1;">Left</th>';
    $body .= '</tr></thead><tbody>';
    $body .= $goal_rows;
    $body .= '</tbody></table>';

    $goals_url = admin_url('admin.php?page=seo-ai-autopilot&tab=goals');
    $body .= '<div style="margin-top:20px;text-align:center;">';
    $body .= '<a href="' . $goals_url . '" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">View Goals Dashboard</a>';
    $body .= '</div>';

    $body .= '<p style="margin-top:16px;font-size:11px;color:#94a3b8;text-align:center;">Data as of ' . $latest_date . '. Sent by SEO AI AutoPilot on ' . $site_name . '.</p>';
    $body .= '</div></div>';

    wp_mail($email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
});

// ─── Admin Menu ──────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_menu_page(
        'SEO AI AutoPilot',
        'SEO AI AutoPilot',
        'manage_woocommerce',
        'seo-ai-autopilot',
        ['SEOM_Dashboard', 'render'],
        'dashicons-chart-line',
        57
    );
    // Hidden page for documentation (no menu item — accessed via plugin action link)
    add_submenu_page(null, 'SEO Platform Documentation', '', 'manage_options', 'seo-platform-docs', function () {
        include SEOM_PATH . 'about.html';
    });
});

// "Documentation" link on the Plugins page for SEO AI AutoPilot
add_filter('plugin_action_links_seo-ai-autopilot/seo-ai-autopilot.php', function ($links) {
    $url = admin_url('admin.php?page=seo-platform-docs');
    array_unshift($links, '<a href="' . esc_url($url) . '">Documentation</a>');
    return $links;
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

// ─── GSC OAuth Handlers ──────────────────────────────────────────────────────
//
// The "Connect to Google Search Console" flow on the Settings tab uses these
// AJAX endpoints + the admin_init callback handler below. Service account
// callers (above) are completely untouched.

/**
 * Auto-migrate existing service-account installs on first plugin load.
 * If credentials JSON is set but no auth method is recorded, default to
 * 'service_account' so behavior is identical to pre-OAuth releases.
 */
add_action('init', function () {
    if (get_option('seom_gsc_auth_method', '') !== '') return;
    $settings = get_option('seom_settings', []);
    if (!empty($settings['gsc_credentials_json'])) {
        update_option('seom_gsc_auth_method', 'service_account', 'no');
    }
});

/**
 * OAuth callback handler — runs when Google redirects the user back after
 * approving the consent screen. Exchanges the code for tokens, stores them,
 * sets auth method to 'oauth', then redirects back to Settings with a notice.
 */
add_action('admin_init', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'seo-ai-autopilot') return;
    if (!isset($_GET['oauth_callback']) || $_GET['oauth_callback'] !== '1') return;
    if (!current_user_can('manage_woocommerce')) return;

    $settings_url = admin_url('admin.php?page=seo-ai-autopilot&tab=settings');

    // User denied or Google returned an error
    if (isset($_GET['error'])) {
        $err = sanitize_text_field($_GET['error_description'] ?? $_GET['error']);
        wp_safe_redirect(add_query_arg('oauth_error', urlencode($err), $settings_url));
        exit;
    }

    if (empty($_GET['code'])) {
        wp_safe_redirect(add_query_arg('oauth_error', urlencode('No authorization code returned.'), $settings_url));
        exit;
    }

    // Optional CSRF check — match the state we set when starting the flow
    $stored_state = get_transient('seom_gsc_oauth_state');
    delete_transient('seom_gsc_oauth_state');
    if ($stored_state && (empty($_GET['state']) || $_GET['state'] !== $stored_state)) {
        wp_safe_redirect(add_query_arg('oauth_error', urlencode('OAuth state mismatch — possible CSRF attempt.'), $settings_url));
        exit;
    }

    $code = sanitize_text_field($_GET['code']);

    // Exchange code → tokens
    $tokens = SEOM_GSC_Client::exchange_authorization_code($code, seom_oauth_redirect_uri());
    if (is_wp_error($tokens)) {
        wp_safe_redirect(add_query_arg('oauth_error', urlencode($tokens->get_error_message()), $settings_url));
        exit;
    }

    if (empty($tokens['refresh_token'])) {
        wp_safe_redirect(add_query_arg('oauth_error', urlencode('No refresh token returned. Try disconnecting at https://myaccount.google.com/permissions and connecting again.'), $settings_url));
        exit;
    }

    // Fetch the email of the connected Google account for display
    $email = SEOM_GSC_Client::fetch_user_email($tokens['access_token']);

    // Store everything
    update_option('seom_gsc_oauth_access_token', $tokens['access_token'], 'no');
    update_option('seom_gsc_oauth_refresh_token', $tokens['refresh_token'], 'no');
    update_option('seom_gsc_oauth_token_expiry', time() + $tokens['expires_in'] - 60, 'no');
    update_option('seom_gsc_oauth_email', $email, 'no');
    update_option('seom_gsc_oauth_connected_at', current_time('mysql'), 'no');

    // Switching to OAuth makes it the active method
    update_option('seom_gsc_auth_method', 'oauth', 'no');

    // Clear the legacy service-account-tied transient so the next API call
    // re-fetches via the OAuth path
    delete_transient('seom_gsc_token');

    wp_safe_redirect(add_query_arg('oauth_connected', '1', $settings_url));
    exit;
});

/**
 * AJAX: start the OAuth flow.
 *
 * SaaS-mode default: calls the hosted proxy at SEOM_GSC_PROXY_URL, which
 * returns the Google authorize URL. The proxy holds the GCP client secret,
 * so resellers never need to set up GCP themselves.
 *
 * Direct-mode fallback: if SEOM_GSC_PROXY_URL is not set OR is set to a
 * placeholder, the plugin falls back to building the authorize URL itself
 * using the local SEOM_GSC_OAUTH_CLIENT_ID/SECRET. Useful for the operator's
 * own site (ituonline.com) or for advanced users who want to host their own
 * GCP project.
 */
add_action('wp_ajax_seom_gsc_oauth_start', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    // Generate a per-install nonce so the proxy round-trips it back and we
    // can verify the response actually corresponds to this connect attempt
    $site_nonce = wp_generate_password(32, false);
    set_transient('seom_gsc_oauth_site_nonce', $site_nonce, 10 * MINUTE_IN_SECONDS);

    // ── SaaS proxy mode ──
    if (seom_oauth_proxy_configured()) {
        $proxy_url = SEOM_GSC_PROXY_URL;
        $resp = wp_remote_post($proxy_url, [
            'timeout' => 30,
            'body'    => [
                'action'     => 'start',
                'site_url'   => home_url('/'),
                'site_nonce' => $site_nonce,
            ],
        ]);
        if (is_wp_error($resp)) {
            wp_send_json_error('Could not reach the OAuth proxy: ' . $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || empty($body['authorize_url'])) {
            $err = $body['error'] ?? "Proxy returned HTTP $code";
            wp_send_json_error('OAuth proxy error: ' . $err);
        }
        wp_send_json_success(['authorize_url' => $body['authorize_url']]);
    }

    // ── Direct (self-hosted GCP) mode ──
    if (!seom_oauth_configured()) {
        wp_send_json_error('OAuth is not configured. The hosted OAuth proxy is unreachable and no local GCP client credentials are set in config.php.');
    }

    $state = wp_generate_password(32, false);
    set_transient('seom_gsc_oauth_state', $state, 10 * MINUTE_IN_SECONDS);

    $url = SEOM_GSC_Client::build_oauth_authorize_url(seom_oauth_redirect_uri(), $state);
    if (is_wp_error($url)) wp_send_json_error($url->get_error_message());

    wp_send_json_success(['authorize_url' => $url]);
});

/**
 * REST endpoint: receive signed tokens from the OAuth proxy.
 *
 * The proxy POSTs here after Google completes the consent flow. We verify
 * the HMAC signature using the shared secret, then store the tokens locally
 * and mark OAuth as the active method. The user's browser is redirected by
 * the proxy itself (not from here) — this endpoint just stores state.
 */
add_action('rest_api_init', function () {
    register_rest_route('seom/v1', '/oauth-receive', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true', // signature is the auth check
        'callback'            => 'seom_gsc_oauth_receive_callback',
    ]);
});

function seom_gsc_oauth_receive_callback($request) {
    $body = $request->get_json_params();
    if (!is_array($body) || empty($body['payload']) || empty($body['signature'])) {
        return new WP_REST_Response(['error' => 'Malformed request'], 400);
    }

    if (!defined('SEOM_GSC_PROXY_SHARED_SECRET') || strpos(SEOM_GSC_PROXY_SHARED_SECRET, 'REPLACE_') !== false) {
        return new WP_REST_Response(['error' => 'Plugin proxy secret not configured'], 500);
    }

    $payload = $body['payload'];
    $signature_received = (string) $body['signature'];

    // Verify HMAC signature using the shared secret. We sort the payload
    // keys and JSON-encode the same way the proxy does so signatures match.
    if (!is_array($payload)) {
        return new WP_REST_Response(['error' => 'Bad payload'], 400);
    }
    ksort($payload);
    $expected_sig = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), SEOM_GSC_PROXY_SHARED_SECRET);
    if (!hash_equals($expected_sig, $signature_received)) {
        return new WP_REST_Response(['error' => 'Signature mismatch — payload not from a trusted proxy'], 401);
    }

    // Verify required fields
    if (empty($payload['access_token']) || empty($payload['refresh_token'])) {
        return new WP_REST_Response(['error' => 'Token fields missing'], 400);
    }

    // Verify the site_nonce matches what we set when starting the flow.
    // This prevents an old or replayed payload from being applied.
    $expected_nonce = get_transient('seom_gsc_oauth_site_nonce');
    if (!$expected_nonce || empty($payload['site_nonce']) || !hash_equals($expected_nonce, (string) $payload['site_nonce'])) {
        return new WP_REST_Response(['error' => 'Site nonce mismatch — connect attempt is stale or replayed'], 401);
    }
    delete_transient('seom_gsc_oauth_site_nonce');

    // Reasonable freshness check — payload should be at most a couple minutes old
    if (!empty($payload['issued_at']) && (time() - (int) $payload['issued_at']) > 300) {
        return new WP_REST_Response(['error' => 'Payload too old'], 401);
    }

    // All checks passed — store the tokens
    update_option('seom_gsc_oauth_access_token',  $payload['access_token'], 'no');
    update_option('seom_gsc_oauth_refresh_token', $payload['refresh_token'], 'no');
    update_option('seom_gsc_oauth_token_expiry',  time() + (int) ($payload['expires_in'] ?? 3600) - 60, 'no');
    update_option('seom_gsc_oauth_email',         $payload['email'] ?? '', 'no');
    update_option('seom_gsc_oauth_connected_at',  current_time('mysql'), 'no');
    update_option('seom_gsc_auth_method', 'oauth', 'no');

    // Clear the legacy service-account-tied transient
    delete_transient('seom_gsc_token');

    return new WP_REST_Response(['ok' => true], 200);
}

/**
 * AJAX: disconnect the OAuth connection. Revokes the token at Google's side
 * and deletes all OAuth options. If service account credentials are still
 * configured, the auth method automatically falls back to 'service_account'.
 */
add_action('wp_ajax_seom_gsc_oauth_disconnect', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    // Best-effort revoke at Google's side — don't fail the disconnect if the
    // remote call errors out, since we still want to clean up local state
    $access  = get_option('seom_gsc_oauth_access_token', '');
    $refresh = get_option('seom_gsc_oauth_refresh_token', '');
    if ($access)  SEOM_GSC_Client::revoke_oauth_token($access);
    if ($refresh) SEOM_GSC_Client::revoke_oauth_token($refresh);

    delete_option('seom_gsc_oauth_access_token');
    delete_option('seom_gsc_oauth_refresh_token');
    delete_option('seom_gsc_oauth_token_expiry');
    delete_option('seom_gsc_oauth_email');
    delete_option('seom_gsc_oauth_connected_at');

    // Fall back to service account if it's still configured, otherwise unset
    $settings = get_option('seom_settings', []);
    if (!empty($settings['gsc_credentials_json'])) {
        update_option('seom_gsc_auth_method', 'service_account', 'no');
    } else {
        delete_option('seom_gsc_auth_method');
    }

    wp_send_json_success(['disconnected' => true]);
});

/**
 * AJAX: list the GSC properties accessible to the connected OAuth account.
 * Used to populate the property dropdown after connecting.
 */
add_action('wp_ajax_seom_gsc_oauth_list_properties', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $access = get_option('seom_gsc_oauth_access_token', '');
    $expiry = (int) get_option('seom_gsc_oauth_token_expiry', 0);

    // If the cached access token is expired, force a refresh by instantiating
    // the client and calling the public test_connection's prep step indirectly.
    // The simplest way: instantiate and call list_sites_for_token after
    // optionally refreshing via the dispatcher in get_access_token.
    if (!$access || $expiry < time() + 60) {
        // Trigger a refresh via the client (no-op if no refresh token stored)
        $client = new SEOM_GSC_Client('', '');
        // Use a public method to drive the dispatcher; test_connection happens
        // to call get_access_token internally, but we don't want to test —
        // instead, we re-read the option after a manual refresh attempt below.
        // We handle the refresh inline so failures surface as JSON errors.
        $refresh = get_option('seom_gsc_oauth_refresh_token', '');
        if (!$refresh) wp_send_json_error('Not connected.');

        $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id'     => SEOM_GSC_OAUTH_CLIENT_ID,
                'client_secret' => SEOM_GSC_OAUTH_CLIENT_SECRET,
                'refresh_token' => $refresh,
                'grant_type'    => 'refresh_token',
            ],
            'timeout' => 30,
        ]);
        if (is_wp_error($resp)) wp_send_json_error($resp->get_error_message());
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($body['access_token'])) {
            wp_send_json_error($body['error_description'] ?? 'Failed to refresh token');
        }
        $access = $body['access_token'];
        update_option('seom_gsc_oauth_access_token', $access, 'no');
        update_option('seom_gsc_oauth_token_expiry', time() + (int) ($body['expires_in'] ?? 3600) - 60, 'no');
    }

    $sites = SEOM_GSC_Client::list_sites_for_token($access);
    if (is_wp_error($sites)) wp_send_json_error($sites->get_error_message());

    wp_send_json_success(['sites' => $sites]);
});

/**
 * AJAX: explicitly switch which auth method is active when both are configured.
 */
add_action('wp_ajax_seom_gsc_set_auth_method', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $method = sanitize_text_field($_POST['method'] ?? '');
    if (!in_array($method, ['oauth', 'service_account'], true)) {
        wp_send_json_error('Invalid auth method.');
    }

    // Validate the chosen method actually has credentials
    if ($method === 'oauth' && !get_option('seom_gsc_oauth_refresh_token', '')) {
        wp_send_json_error('OAuth is not connected — connect first.');
    }
    if ($method === 'service_account') {
        $settings = get_option('seom_settings', []);
        if (empty($settings['gsc_credentials_json'])) {
            wp_send_json_error('Service account JSON is not configured.');
        }
    }

    update_option('seom_gsc_auth_method', $method, 'no');
    delete_transient('seom_gsc_token'); // force token re-fetch on next call

    wp_send_json_success(['active_method' => $method]);
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

// Get daily trend data for charts
add_action('wp_ajax_seom_get_trends', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $days = max(1, min(365, intval($_POST['days'] ?? 30)));
    $post_type = sanitize_text_field($_POST['post_type'] ?? 'all');
    $filter = sanitize_text_field($_POST['filter'] ?? 'all');
    $group  = sanitize_text_field($_POST['group'] ?? 'daily');
    if (!in_array($group, ['daily', 'weekly', 'monthly', 'quarterly'], true)) $group = 'daily';

    // Custom date window — same shape as seom_get_indexed.
    $date_from_in = sanitize_text_field($_POST['date_from'] ?? '');
    $date_to_in   = sanitize_text_field($_POST['date_to'] ?? '');
    $custom_window = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from_in)
                  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to_in);
    if ($custom_window && $date_from_in > $date_to_in) {
        $tmp = $date_from_in; $date_from_in = $date_to_in; $date_to_in = $tmp;
    }
    if ($custom_window) {
        $days = max(1, (int) ((strtotime($date_to_in) - strtotime($date_from_in)) / 86400) + 1);
    }
    $summary_table = $wpdb->prefix . 'seom_daily_summary';
    $metrics_table = $wpdb->prefix . 'seom_page_metrics';
    $settings = seom_get_settings();
    $ghost_threshold = intval($settings['ghost_threshold']);

    // ── Compute date windows anchored to the NEWEST AVAILABLE DAILY DATE ──
    //
    // Critical: do NOT anchor to today(). Google Search Console has a T-3
    // finalization lag, so the newest finalized day in seom_daily_page_metrics
    // is normally ~3 days ago. If we anchored "current_end" to today, the
    // "trailing 28 days" window would span calendar dates today-28 → today
    // but only contain ~26 actual day rows — making the chart's summary bar
    // sum fewer days than the user asked for, and the prior-period overlay
    // misalign with the current window.
    //
    // Anchoring to the newest available daily date makes the chart show a
    // genuine N-day window of finalized data, with the prior overlay being
    // the N days immediately before that.
    //
    // For "other" (untracked) post types we anchor to seom_untracked_metrics
    // since that's where the data lives.
    $anchor_table = ($post_type === 'other')
        ? $wpdb->prefix . 'seom_untracked_metrics'
        : $wpdb->prefix . 'seom_daily_page_metrics';

    $newest_available = null;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$anchor_table}'") === $anchor_table) {
        $newest_available = $wpdb->get_var("SELECT MAX(date_collected) FROM {$anchor_table}");
    }
    // Fall back to today if we have no data yet — the queries will just return empty
    if (!$newest_available) {
        $newest_available = date('Y-m-d');
    }

    // Current window: N days ending at the newest available date (inclusive),
    // OR the user-supplied custom date pair when one was provided.
    if ($custom_window) {
        $current_start = $date_from_in;
        $current_end   = $date_to_in;
    } else {
        $current_end   = $newest_available;
        $current_start = date('Y-m-d', strtotime("{$current_end} -" . ($days - 1) . " days"));
    }
    // Prior window: the N days immediately before current_start
    $prior_end     = date('Y-m-d', strtotime("{$current_start} -1 day"));
    $prior_start   = date('Y-m-d', strtotime("{$prior_end} -" . ($days - 1) . " days"));

    // Group SQL — bucket the per-day rows into the requested grain.
    // The backend always returns one row per bucket; the chart already handles
    // sparse points and prior overlay by index.
    switch ($group) {
        case 'weekly':
            // ISO year-week: rows in the same week share a key like "2026-14"
            $bucket_expr = "DATE_FORMAT(date_collected, '%x-%v')";
            $bucket_label_expr = "MIN(date_collected)"; // label = first day of the bucket
            break;
        case 'monthly':
            $bucket_expr = "DATE_FORMAT(date_collected, '%Y-%m')";
            $bucket_label_expr = "DATE_FORMAT(MIN(date_collected), '%Y-%m')";
            break;
        case 'quarterly':
            $bucket_expr = "CONCAT(YEAR(date_collected), '-Q', QUARTER(date_collected))";
            $bucket_label_expr = "CONCAT(YEAR(MIN(date_collected)), '-Q', QUARTER(MIN(date_collected)))";
            break;
        default: // daily
            $bucket_expr = "date_collected";
            $bucket_label_expr = "date_collected";
    }

    // Other (untracked URLs) — query seom_untracked_metrics instead
    if ($post_type === 'other') {
        $ut_table = $wpdb->prefix . 'seom_untracked_metrics';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$ut_table}'") === $ut_table) {
            $ut_sql = "
                SELECT {$bucket_label_expr} as date_collected,
                    COUNT(DISTINCT url) as total_pages,
                    SUM(CASE WHEN impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
                    SUM(CASE WHEN impressions = 0 THEN 1 ELSE 0 END) as ghost_pages,
                    SUM(clicks) as total_clicks,
                    SUM(impressions) as total_impressions,
                    AVG(CASE WHEN avg_position > 0 THEN avg_position END) as avg_position,
                    0 as page1_pages
                FROM {$ut_table}
                WHERE date_collected BETWEEN %s AND %s
                GROUP BY {$bucket_expr}
                ORDER BY MIN(date_collected) ASC
            ";
            $current_rows = $wpdb->get_results($wpdb->prepare($ut_sql, $current_start, $current_end));
            $prior_rows   = $wpdb->get_results($wpdb->prepare($ut_sql, $prior_start, $prior_end));
            wp_send_json_success(['current' => $current_rows ?: [], 'prior' => $prior_rows ?: []]);
        }
        wp_send_json_success(['current' => [], 'prior' => []]);
    }

    // Fast path: unfiltered "all" view — prefer seom_daily_page_metrics (true single-day data,
    // includes any backfilled/imported daily GSC data). Falls back to the daily_summary table
    // when daily per-page data isn't available.
    if ($post_type === 'all' && $filter === 'all') {
        $daily_table = $wpdb->prefix . 'seom_daily_page_metrics';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$daily_table}'") === $daily_table) {
            $daily_sql = "
                SELECT {$bucket_label_expr} as date_collected,
                    COUNT(DISTINCT url) as total_pages,
                    SUM(CASE WHEN impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
                    0 as ghost_pages,
                    SUM(clicks) as total_clicks,
                    SUM(impressions) as total_impressions,
                    CASE WHEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END) > 0
                        THEN ROUND(SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN avg_position * impressions ELSE 0 END) / SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END), 1)
                        ELSE 0
                    END as avg_position,
                    CASE WHEN SUM(impressions) > 0 THEN ROUND(SUM(clicks) / SUM(impressions) * 100, 4) ELSE 0 END as avg_ctr,
                    0 as page1_pages,
                    SUM(clicks) as gsc_total_clicks,
                    SUM(impressions) as gsc_total_impressions,
                    CASE WHEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END) > 0
                        THEN ROUND(SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN avg_position * impressions ELSE 0 END) / SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END), 1)
                        ELSE 0
                    END as gsc_avg_position,
                    CASE WHEN SUM(impressions) > 0 THEN ROUND(SUM(clicks) / SUM(impressions) * 100, 4) ELSE 0 END as gsc_avg_ctr
                FROM {$daily_table}
                WHERE date_collected BETWEEN %s AND %s
                GROUP BY {$bucket_expr}
                ORDER BY MIN(date_collected) ASC
            ";
            $current_rows = $wpdb->get_results($wpdb->prepare($daily_sql, $current_start, $current_end));
            $prior_rows   = $wpdb->get_results($wpdb->prepare($daily_sql, $prior_start, $prior_end));
            if (!empty($current_rows)) {
                wp_send_json_success(['current' => $current_rows, 'prior' => $prior_rows ?: []]);
            }
        }

        // Fall back to pre-aggregated summary table
        if ($wpdb->get_var("SHOW TABLES LIKE '{$summary_table}'") === $summary_table) {
            $sum_sql = "
                SELECT {$bucket_label_expr} as date_collected,
                    SUM(total_pages) as total_pages,
                    SUM(pages_with_impressions) as pages_with_impressions,
                    SUM(ghost_pages) as ghost_pages,
                    SUM(total_clicks) as total_clicks,
                    SUM(total_impressions) as total_impressions,
                    AVG(avg_position) as avg_position,
                    AVG(avg_ctr) as avg_ctr,
                    SUM(page1_pages) as page1_pages,
                    SUM(gsc_total_clicks) as gsc_total_clicks,
                    SUM(gsc_total_impressions) as gsc_total_impressions,
                    AVG(gsc_avg_position) as gsc_avg_position,
                    AVG(gsc_avg_ctr) as gsc_avg_ctr
                FROM {$summary_table}
                WHERE date_collected BETWEEN %s AND %s
                GROUP BY {$bucket_expr}
                ORDER BY MIN(date_collected) ASC
            ";
            $current_rows = $wpdb->get_results($wpdb->prepare($sum_sql, $current_start, $current_end));
            if (empty($current_rows)) {
                seom_backfill_daily_summaries();
                $current_rows = $wpdb->get_results($wpdb->prepare($sum_sql, $current_start, $current_end));
            }
            $prior_rows = $wpdb->get_results($wpdb->prepare($sum_sql, $prior_start, $prior_end));
            if (!empty($current_rows)) {
                wp_send_json_success(['current' => $current_rows, 'prior' => $prior_rows ?: []]);
            }
        }
    }

    // Filtered path: query seom_page_metrics dynamically grouped by date
    $type_sql = ($post_type === 'all')
        ? "p.post_type IN ('product','post','page')"
        : $wpdb->prepare("p.post_type = %s", $post_type);

    // Build filter clause (must match seom_get_indexed exactly)
    $filter_sql = '';
    switch ($filter) {
        case 'ghost':
            $filter_sql = " AND m.impressions <= {$ghost_threshold} AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY)";
            break;
        case 'new':
            $filter_sql = " AND p.post_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)";
            break;
        case 'page1':
            $filter_sql = " AND m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0";
            break;
        case 'page2':
            $filter_sql = " AND m.avg_position >= " . intval($settings['near_win_min_pos'])
                . " AND m.avg_position <= " . intval($settings['near_win_max_pos'])
                . " AND m.impressions >= " . intval($settings['near_win_min_impressions']);
            break;
        case 'buried':
            $filter_sql = " AND m.avg_position > 20 AND m.impressions >= " . intval($settings['buried_min_impressions']);
            break;
        case 'low_ctr':
            $min_imp = intval($settings['ctr_fix_min_impressions']);
            $filter_sql = " AND m.impressions >= {$min_imp} AND ("
                . "(m.avg_position <= 1 AND m.ctr < 15)"
                . " OR (m.avg_position > 1 AND m.avg_position <= 3 AND m.ctr < 5.5)"
                . " OR (m.avg_position > 3 AND m.avg_position <= 5 AND m.ctr < 3.5)"
                . " OR (m.avg_position > 5 AND m.avg_position <= 10 AND m.ctr < 1.5)"
                . " OR (m.avg_position > 10 AND m.avg_position <= 15 AND m.ctr < 0.5)"
                . ")";
            break;
        case 'limited':
            $filter_sql = " AND m.impressions > 0 AND (m.impressions < 100 OR m.avg_position >= 30)";
            break;
        case 'top_performers':
            $filter_sql = " AND m.clicks >= 3 AND m.impressions >= 20 AND ("
                . "(m.avg_position <= 1 AND m.ctr >= 18)"
                . " OR (m.avg_position > 1 AND m.avg_position <= 2 AND m.ctr >= 10.2)"
                . " OR (m.avg_position > 2 AND m.avg_position <= 3 AND m.ctr >= 6.6)"
                . " OR (m.avg_position > 3 AND m.avg_position <= 5 AND m.ctr >= 4.2)"
                . " OR (m.avg_position > 5 AND m.avg_position <= 10 AND m.ctr >= 1.8)"
                . " OR (m.avg_position > 10 AND m.avg_position <= 20 AND m.ctr >= 0.6)"
                . ")";
            break;
        case 'stars':
            $filter_sql = " AND m.clicks >= 15 AND m.impressions >= 200";
            break;
        case 'underperforming':
            $ctr_min_imp = intval($settings['ctr_fix_min_impressions']);
            $filter_sql = " AND ("
                . "(m.impressions <= {$ghost_threshold} AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY))"
                . " OR (m.avg_position >= " . intval($settings['near_win_min_pos']) . " AND m.avg_position <= " . intval($settings['near_win_max_pos']) . " AND m.impressions >= " . intval($settings['near_win_min_impressions']) . ")"
                . " OR (m.impressions >= {$ctr_min_imp} AND m.avg_position <= 15 AND m.ctr < 3)"
                . " OR (m.impressions >= " . intval($settings['visible_min_impressions']) . " AND m.clicks <= " . intval($settings['visible_max_clicks']) . ")"
                . ")";
            break;
        case 'lost':
            // Pre-fetch lost post IDs (pages that previously had impressions but now have zero)
            // Done as a separate query to avoid correlated subquery in the GROUP BY
            $lost_ids = $wpdb->get_col("
                SELECT DISTINCT curr.post_id
                FROM {$metrics_table} curr
                JOIN {$wpdb->posts} p2 ON curr.post_id = p2.ID
                WHERE curr.date_collected = (SELECT MAX(date_collected) FROM {$metrics_table})
                AND curr.impressions <= {$ghost_threshold}
                AND p2.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                AND curr.post_id IN (
                    SELECT post_id FROM {$metrics_table} WHERE impressions > 10
                )
            ");
            if (!empty($lost_ids)) {
                $filter_sql = " AND m.post_id IN (" . implode(',', array_map('intval', $lost_ids)) . ")";
            } else {
                $filter_sql = " AND 1=0"; // no lost pages — return empty
            }
            break;
        case 'stale_year':
            $current_year = (int) date('Y');
            $year_patterns = [];
            for ($y = 2020; $y < $current_year; $y++) {
                $year_patterns[] = $wpdb->prepare("p.post_title LIKE %s", '%' . $y . '%');
            }
            if (!empty($year_patterns)) {
                $filter_sql = " AND (" . implode(' OR ', $year_patterns) . ")";
            }
            break;
    }

    // ── Filtered/typed view ──
    // Two-step approach: (1) identify the SET of posts matching the type+filter from the
    // latest snapshot, then (2) sum their daily metrics from seom_daily_page_metrics over
    // the current and prior date ranges. This gives true daily granularity for the chart
    // even when seom_page_metrics snapshots are sparse.
    $daily_table_ft = $wpdb->prefix . 'seom_daily_page_metrics';
    $has_daily_ft = ($wpdb->get_var("SHOW TABLES LIKE '{$daily_table_ft}'") === $daily_table_ft);

    if ($has_daily_ft) {
        // Resolve the matching post_id set from the latest snapshot
        $matching_ids = $wpdb->get_col("
            SELECT DISTINCT m.post_id
            FROM {$metrics_table} m
            INNER JOIN (
                SELECT post_id, MAX(date_collected) as max_date
                FROM {$metrics_table}
                GROUP BY post_id
            ) latest ON m.post_id = latest.post_id AND m.date_collected = latest.max_date
            JOIN {$wpdb->posts} p ON m.post_id = p.ID
            WHERE {$type_sql} AND p.post_status = 'publish'
            {$filter_sql}
        ");

        if (empty($matching_ids)) {
            wp_send_json_success(['current' => [], 'prior' => []]);
        }

        $id_list = implode(',', array_map('intval', $matching_ids));
        $daily_filtered_sql = "
            SELECT {$bucket_label_expr} as date_collected,
                COUNT(DISTINCT post_id) as total_pages,
                SUM(CASE WHEN impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
                0 as ghost_pages,
                SUM(clicks) as total_clicks,
                SUM(impressions) as total_impressions,
                CASE WHEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END) > 0
                    THEN ROUND(SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN avg_position * impressions ELSE 0 END) / SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END), 1)
                    ELSE 0
                END as avg_position,
                0 as page1_pages
            FROM {$daily_table_ft}
            WHERE date_collected BETWEEN %s AND %s
            AND post_id IN ({$id_list})
            GROUP BY {$bucket_expr}
            ORDER BY MIN(date_collected) ASC
        ";
        $current_rows = $wpdb->get_results($wpdb->prepare($daily_filtered_sql, $current_start, $current_end));
        $prior_rows   = $wpdb->get_results($wpdb->prepare($daily_filtered_sql, $prior_start, $prior_end));

        wp_send_json_success(['current' => $current_rows ?: [], 'prior' => $prior_rows ?: []]);
    }

    // Fallback when daily per-page data isn't available — use snapshot-date aggregation
    // Bucket expressions reference m.date_collected so we have to swap the column ref.
    $bucket_expr_m = str_replace('date_collected', 'm.date_collected', $bucket_expr);
    $bucket_label_expr_m = str_replace('date_collected', 'm.date_collected', $bucket_label_expr);
    $filtered_sql = "
        SELECT {$bucket_label_expr_m} as date_collected,
            COUNT(DISTINCT m.post_id) as total_pages,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
            SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(m.date_collected, INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages
        FROM {$metrics_table} m
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE m.date_collected BETWEEN %s AND %s
        AND {$type_sql} AND p.post_status = 'publish'
        {$filter_sql}
        GROUP BY {$bucket_expr_m}
        ORDER BY MIN(m.date_collected) ASC
    ";
    $current_rows = $wpdb->get_results($wpdb->prepare($filtered_sql, $ghost_threshold, $current_start, $current_end));
    $prior_rows   = $wpdb->get_results($wpdb->prepare($filtered_sql, $ghost_threshold, $prior_start, $prior_end));

    wp_send_json_success(['current' => $current_rows ?: [], 'prior' => $prior_rows ?: []]);
});

// Get refresh performance trends for the Performance Tracker chart
add_action('wp_ajax_seom_get_refresh_trends', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $days = max(7, min(365, intval($_POST['days'] ?? 30)));
    $post_type = sanitize_text_field($_POST['post_type'] ?? 'all');
    $trend = sanitize_text_field($_POST['trend'] ?? 'all');
    $date_from = sanitize_text_field($_POST['date_from'] ?? '');
    $date_to   = sanitize_text_field($_POST['date_to'] ?? '');
    $group     = sanitize_text_field($_POST['group'] ?? 'daily');
    if (!in_array($group, ['daily', 'weekly', 'monthly', 'quarterly'], true)) $group = 'daily';
    $history = $wpdb->prefix . 'seom_refresh_history';
    $metrics = $wpdb->prefix . 'seom_page_metrics';
    $daily_pages_table = $wpdb->prefix . 'seom_daily_page_metrics';

    // Build post_type filter for history joins
    $type_join = '';
    $type_where = '';
    if ($post_type !== 'all') {
        $type_join = "JOIN {$wpdb->posts} p ON h.post_id = p.ID";
        $type_where = $wpdb->prepare(" AND p.post_type = %s", $post_type);
    }

    // Date range — custom dates take precedence. We need two parallel
    // fragments because the inner subquery uses alias h2 while the outer
    // queries (perf trend, daily activity) use alias h.
    $use_custom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)
               && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to);
    if ($use_custom && $date_from > $date_to) {
        $tmp = $date_from; $date_from = $date_to; $date_to = $tmp;
    }
    if ($use_custom) {
        $range_h  = $wpdb->prepare("h.refresh_date BETWEEN %s AND %s",  $date_from . ' 00:00:00', $date_to . ' 23:59:59');
        $range_h2 = $wpdb->prepare("h2.refresh_date BETWEEN %s AND %s", $date_from . ' 00:00:00', $date_to . ' 23:59:59');
    } else {
        $range_h  = $wpdb->prepare("h.refresh_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",  $days);
        $range_h2 = $wpdb->prepare("h2.refresh_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)", $days);
    }

    // Daily refresh counts — computed after trend filtering so it only shows matching pages

    // Aggregate before vs current performance for pages refreshed in this period
    $refreshed = $wpdb->get_results("
        SELECT h.post_id,
            h.clicks_before, h.impressions_before, h.position_before, h.ctr_before,
            h.refresh_date, h.category
        FROM {$history} h
        INNER JOIN (
            SELECT h2.post_id, MAX(h2.id) as max_id
            FROM {$history} h2
            " . ($post_type !== 'all' ? "JOIN {$wpdb->posts} p2 ON h2.post_id = p2.ID" : "") . "
            WHERE {$range_h2}
            AND h2.refresh_type NOT LIKE '%dry run%'
            " . ($post_type !== 'all' ? $wpdb->prepare("AND p2.post_type = %s", $post_type) : "") . "
            GROUP BY h2.post_id
        ) latest ON h.id = latest.max_id
    ");

    if (!empty($refreshed)) {
        $post_ids = array_map(function($r) { return (int) $r->post_id; }, $refreshed);
        $id_list = implode(',', $post_ids);

        // ── Per-page window aggregates ──
        // Same source as seom_get_tracker — pull each post's clicks /
        // impressions / weighted-position over the current window AND the
        // prior window from seom_daily_page_metrics. Classification is then
        // performed against THESE numbers, so the chart bar and the cards
        // can never disagree on bucket membership.
        $daily_pg_table = $wpdb->prefix . 'seom_daily_page_metrics';
        $has_daily_pg   = ($wpdb->get_var("SHOW TABLES LIKE '{$daily_pg_table}'") === $daily_pg_table);

        if ($use_custom) {
            $cw_start_c = $date_from_in;
            $cw_end_c   = $date_to_in;
            $cw_days_c  = max(1, (int) ((strtotime($cw_end_c) - strtotime($cw_start_c)) / 86400) + 1);
        } else {
            $newest_dp_c = $has_daily_pg ? $wpdb->get_var("SELECT MAX(date_collected) FROM {$daily_pg_table}") : null;
            if (!$newest_dp_c) $newest_dp_c = date('Y-m-d');
            $cw_end_c   = $newest_dp_c;
            $cw_start_c = date('Y-m-d', strtotime("{$cw_end_c} -" . ($days - 1) . " days"));
            $cw_days_c  = $days;
        }
        $pw_end_c   = date('Y-m-d', strtotime("{$cw_start_c} -1 day"));
        $pw_start_c = date('Y-m-d', strtotime("{$pw_end_c} -" . ($cw_days_c - 1) . " days"));

        $cur_by_post = []; $prv_by_post = [];
        if ($has_daily_pg) {
            $agg_sql_c = "
                SELECT post_id,
                    SUM(clicks) AS clicks,
                    SUM(impressions) AS impressions,
                    CASE WHEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END) > 0
                        THEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN avg_position * impressions ELSE 0 END)
                             / SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END)
                        ELSE 0 END AS avg_position
                FROM {$daily_pg_table}
                WHERE post_id IN ({$id_list})
                AND date_collected BETWEEN %s AND %s
                GROUP BY post_id
            ";
            $cur_rows_c = $wpdb->get_results($wpdb->prepare($agg_sql_c, $cw_start_c, $cw_end_c));
            foreach ($cur_rows_c as $cr) $cur_by_post[(int) $cr->post_id] = $cr;
            $prv_rows_c = $wpdb->get_results($wpdb->prepare($agg_sql_c, $pw_start_c, $pw_end_c));
            foreach ($prv_rows_c as $pr) $prv_by_post[(int) $pr->post_id] = $pr;
        }

        // Classify each refresh's trend and filter
        $total_clicks_before = 0; $total_clicks_now = 0;
        $total_imp_before = 0; $total_imp_now = 0;
        $positions_before = []; $positions_now = [];
        $improving = 0; $declining = 0; $flat = 0;
        $filtered_ids = [];

        foreach ($refreshed as $r) {
            $pid = (int) $r->post_id;
            $cur = $cur_by_post[$pid] ?? null;
            $prv = $prv_by_post[$pid] ?? null;

            $cb = $prv ? (int)   $prv->clicks       : 0;
            $ib = $prv ? (int)   $prv->impressions  : 0;
            $pb = $prv ? (float) $prv->avg_position : 0;
            $cn = $cur ? (int)   $cur->clicks       : 0;
            $in = $cur ? (int)   $cur->impressions  : 0;
            $pn = $cur ? (float) $cur->avg_position : 0;

            // Single source of truth — same helper, same window data as
            // seom_get_tracker. Bar count and card counts always agree.
            $days_since = (int) ((time() - strtotime($r->refresh_date)) / 86400);
            $page_trend = seom_classify_refresh_trend($cb, $cn, $pb, $pn, $days_since, $ib, $in);

            // Apply trend filter — exact match per bucket.
            $include = ($trend === 'all') || ($page_trend === $trend);
            if (!$include) continue;

            // Counts are derived from the FILTERED set so the info bar
            // matches the chart and the table. When a single-bucket filter
            // is active, only that bucket increments — the other two stay 0.
            if (in_array($page_trend, ['strong', 'clicks_up', 'impressions_up', 'ranking_up'])) $improving++;
            elseif (in_array($page_trend, ['clicks_down', 'impressions_down', 'ranking_down', 'declining'])) $declining++;
            else $flat++;

            $filtered_ids[] = (int) $r->post_id;
            $total_clicks_before += $cb;
            $total_clicks_now += $cn;
            $total_imp_before += $ib;
            $total_imp_now += $in;
            if ($pb > 0) $positions_before[] = $pb;
            if ($pn > 0) $positions_now[] = $pn;
        }

        $avg_pos_before = !empty($positions_before) ? round(array_sum($positions_before) / count($positions_before), 1) : 0;
        $avg_pos_now = !empty($positions_now) ? round(array_sum($positions_now) / count($positions_now), 1) : 0;

        $summary = [
            'pages_refreshed'     => count($filtered_ids),
            'clicks_before'       => $total_clicks_before,
            'clicks_now'          => $total_clicks_now,
            'impressions_before'  => $total_imp_before,
            'impressions_now'     => $total_imp_now,
            'avg_position_before' => $avg_pos_before,
            'avg_position_now'    => $avg_pos_now,
            'improving'           => $improving,
            'declining'           => $declining,
            'flat'                => $flat,
        ];
    } else {
        $summary = ['pages_refreshed' => 0, 'clicks_before' => 0, 'clicks_now' => 0, 'impressions_before' => 0, 'impressions_now' => 0, 'avg_position_before' => 0, 'avg_position_now' => 0, 'improving' => 0, 'declining' => 0, 'flat' => 0];
        $filtered_ids = [];
    }

    // ── Performance trend ──
    // Mirrors the Indexed Pages chart logic exactly: anchor windows to the
    // newest available daily date (or use custom dates), bucket by the
    // chosen group, and return current + prior arrays so the JS can draw
    // the prior period as a dashed overlay. Scoped to filtered_ids so the
    // chart only reflects pages that were actually refreshed in the window.
    $perf = ['current' => [], 'prior' => []];
    if (!empty($filtered_ids) && $wpdb->get_var("SHOW TABLES LIKE '{$daily_pages_table}'") === $daily_pages_table) {
        $id_list = implode(',', array_map('intval', $filtered_ids));

        // Bucketing — same expressions as seom_get_trends
        switch ($group) {
            case 'weekly':
                $bucket_expr = "DATE_FORMAT(date_collected, '%x-%v')";
                $bucket_label_expr = "MIN(date_collected)";
                break;
            case 'monthly':
                $bucket_expr = "DATE_FORMAT(date_collected, '%Y-%m')";
                $bucket_label_expr = "DATE_FORMAT(MIN(date_collected), '%Y-%m')";
                break;
            case 'quarterly':
                $bucket_expr = "CONCAT(YEAR(date_collected), '-Q', QUARTER(date_collected))";
                $bucket_label_expr = "CONCAT(YEAR(MIN(date_collected)), '-Q', QUARTER(MIN(date_collected)))";
                break;
            default:
                $bucket_expr = "date_collected";
                $bucket_label_expr = "date_collected";
        }

        // Anchor: newest available daily date OR explicit custom dates.
        if ($use_custom) {
            $cur_start = $date_from;
            $cur_end   = $date_to;
            $win_days  = max(1, (int) ((strtotime($cur_end) - strtotime($cur_start)) / 86400) + 1);
        } else {
            $newest_perf = $wpdb->get_var("SELECT MAX(date_collected) FROM {$daily_pages_table}");
            if (!$newest_perf) $newest_perf = date('Y-m-d');
            $cur_end   = $newest_perf;
            $cur_start = date('Y-m-d', strtotime("{$cur_end} -" . ($days - 1) . " days"));
            $win_days  = $days;
        }
        $prv_end   = date('Y-m-d', strtotime("{$cur_start} -1 day"));
        $prv_start = date('Y-m-d', strtotime("{$prv_end} -" . ($win_days - 1) . " days"));

        $perf_sql = "
            SELECT {$bucket_label_expr} as day,
                SUM(clicks) as total_clicks,
                SUM(impressions) as total_impressions,
                COUNT(DISTINCT post_id) as page_count
            FROM {$daily_pages_table}
            WHERE date_collected BETWEEN %s AND %s
            AND post_id IN ({$id_list})
            GROUP BY {$bucket_expr}
            ORDER BY MIN(date_collected) ASC
        ";
        $perf['current'] = $wpdb->get_results($wpdb->prepare($perf_sql, $cur_start, $cur_end)) ?: [];
        $perf['prior']   = $wpdb->get_results($wpdb->prepare($perf_sql, $prv_start, $prv_end)) ?: [];
    }
    // Backwards-compat alias for any older callers (will be removed once no
    // legacy frontends remain) — flat array of current rows under the old key.
    $perf_trend = $perf['current'];

    // Daily refresh activity — scoped to filtered post IDs so it matches the trend filter
    $daily = [];
    if (!empty($filtered_ids)) {
        $fid_list = implode(',', array_map('intval', $filtered_ids));
        $daily = $wpdb->get_results("
            SELECT DATE(h.refresh_date) as day,
                COUNT(*) as total_refreshed,
                SUM(CASE WHEN h.refresh_type = 'full' THEN 1 ELSE 0 END) as full_refreshes,
                SUM(CASE WHEN h.refresh_type = 'meta_only' THEN 1 ELSE 0 END) as meta_refreshes
            FROM {$history} h
            WHERE {$range_h}
            AND h.refresh_type NOT LIKE '%dry run%'
            AND h.post_id IN ({$fid_list})
            GROUP BY DATE(h.refresh_date)
            ORDER BY day ASC
        ");
    }

    wp_send_json_success([
        'daily'   => $daily,
        'perf'    => $perf, // { current: [...], prior: [...] }
        'summary' => $summary,
    ]);
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

// Step-based processing to avoid 504 gateway timeouts on manual/bulk processing
add_action('wp_ajax_seom_process_step', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(300);
    ob_start();

    $post_id = intval($_POST['post_id'] ?? 0);
    $step    = intval($_POST['step'] ?? 1);
    if (!$post_id) { ob_end_clean(); wp_send_json_error('Missing post ID.'); }

    $settings = seom_get_settings();
    $post_type = get_post_type($post_id);
    $progress_key = 'seom_step_' . $post_id;

    global $wpdb;

    // Step 1: Setup — find/create queue item, get before metrics, determine refresh type
    if ($step === 1) {
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}seom_refresh_queue WHERE post_id = %d AND status IN ('pending', 'processing') LIMIT 1",
            $post_id
        ));

        if (!$item) {
            $wpdb->insert("{$wpdb->prefix}seom_refresh_queue", [
                'post_id'        => $post_id,
                'post_type'      => $post_type,
                'priority_score' => 99,
                'category'       => 'M',
                'refresh_type'   => 'full',
                'status'         => 'pending',
                'queued_at'      => current_time('mysql'),
            ]);
            $item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}seom_refresh_queue WHERE post_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1",
                $post_id
            ));
        }

        $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
            ['status' => 'processing', 'started_at' => current_time('mysql')],
            ['id' => $item->id]
        );

        $before = $wpdb->get_row($wpdb->prepare(
            "SELECT clicks, impressions, ctr, avg_position, top_queries FROM {$wpdb->prefix}seom_page_metrics
             WHERE post_id = %d ORDER BY date_collected DESC LIMIT 1",
            $post_id
        ));

        // Store SEO performance context for the blog refresher AI prompts
        $category_labels = [
            'A' => 'Ghost Page — zero/near-zero impressions, Google is not showing this page in search results at all',
            'B' => 'CTR Fix — ranking well with impressions but click-through rate is far below expected for the position',
            'C' => 'Near Win — ranking on page 2 (positions 11-20) with decent impressions, close to breaking into page 1',
            'D' => 'Declining — significant click drop compared to prior period, content is losing relevance',
            'E' => 'Visible but Ignored — high impressions but almost no clicks, people see it but don\'t click',
            'F' => 'Buried Potential — ranking on page 3+ but Google considers it relevant (has impressions)',
            'M' => 'Manual refresh — queued by admin',
        ];
        $top_queries_data = [];
        if (!empty($before->top_queries)) {
            $tq = json_decode($before->top_queries, true);
            if (is_array($tq)) $top_queries_data = $tq;
        }
        set_transient('seom_refresh_context_' . $post_id, [
            'clicks'       => intval($before->clicks ?? 0),
            'impressions'  => intval($before->impressions ?? 0),
            'ctr'          => floatval($before->ctr ?? 0),
            'position'     => floatval($before->avg_position ?? 0),
            'category'     => $item->category,
            'category_desc'=> $category_labels[$item->category] ?? 'Unknown',
            'refresh_type' => $item->refresh_type,
            'top_queries'  => $top_queries_data,
            'priority'     => floatval($item->priority_score),
        ], 3600);

        // Update stale years in title BEFORE research and content generation
        // so research queries use the current year and AI writes for the right year
        if ($item->refresh_type !== 'meta_only') {
            SEOM_Blog_Refresher::update_title_year($post_id);
        }

        // Resolve the AI model that will be used for this refresh
        $ai_model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

        set_transient($progress_key, [
            'item_id'      => $item->id,
            'post_type'    => $post_type,
            'refresh_type' => $item->refresh_type,
            'category'     => $item->category,
            'priority'     => $item->priority_score,
            'before'       => $before,
            'ai_model'     => $ai_model,
        ], 3600);

        // Run competitive research if enabled and thresholds met (Step 0)
        // Research is stored as post meta so it persists and can be reused
        $research_status = 'skipped';
        if ($item->refresh_type === 'meta_only') {
            $research_status = 'skipped:meta_only';
        } elseif (!SEOM_Researcher::is_available()) {
            $research_status = 'skipped:not_enabled';
        } else {
            $seo_ctx = get_transient('seom_refresh_context_' . $post_id);
            if (!$seo_ctx) {
                $research_status = 'skipped:no_context';
            } elseif (!seom_should_research($item->category, $seo_ctx, $settings)) {
                $research_status = 'skipped:threshold_not_met(cat=' . $item->category . ',imp=' . ($seo_ctx['impressions'] ?? 0) . ')';
            } else {
                $research = SEOM_Researcher::research($post_id, $seo_ctx);
                if (is_wp_error($research)) {
                    $research_status = 'error:' . $research->get_error_message();
                } elseif (empty($research)) {
                    $research_status = 'error:empty_response';
                } else {
                    update_post_meta($post_id, '_seom_research', $research);
                    update_post_meta($post_id, '_seom_research_date', current_time('mysql'));
                    $seo_ctx['competitive_research'] = $research;
                    set_transient('seom_refresh_context_' . $post_id, $seo_ctx, 3600);
                    $research_status = 'collected';
                }
            }
        }

        // Always inject existing research into context if available (even if we didn't just run it)
        if ($item->refresh_type !== 'meta_only') {
            $seo_ctx = get_transient('seom_refresh_context_' . $post_id);
            if ($seo_ctx && empty($seo_ctx['competitive_research'])) {
                $existing_research = get_post_meta($post_id, '_seom_research', true);
                if (!empty($existing_research)) {
                    $research_date = get_post_meta($post_id, '_seom_research_date', true);
                    $seo_ctx['competitive_research'] = $existing_research;
                    $seo_ctx['research_date'] = $research_date;
                    set_transient('seom_refresh_context_' . $post_id, $seo_ctx, 3600);
                }
            }
        }

        // Dry run check
        if ($settings['dry_run']) {
            $wpdb->update("{$wpdb->prefix}seom_refresh_queue",
                ['status' => 'completed', 'completed_at' => current_time('mysql'), 'error_message' => 'Dry run — no changes made.'],
                ['id' => $item->id]
            );
            $wpdb->insert("{$wpdb->prefix}seom_refresh_history", [
                'post_id' => $post_id, 'refresh_date' => current_time('mysql'),
                'refresh_type' => $item->refresh_type . ' (dry run)', 'category' => $item->category,
                'priority_score' => $item->priority_score,
                'clicks_before' => $before->clicks ?? null, 'impressions_before' => $before->impressions ?? null,
                'position_before' => $before->avg_position ?? null, 'ctr_before' => $before->ctr ?? null,
            ]);
            delete_transient($progress_key);
            ob_end_clean();
            wp_send_json_success(['step' => 1, 'complete' => true, 'dry_run' => true, 'post_id' => $post_id]);
        }

        $total_steps = 2; // default for meta_only
        if ($item->refresh_type !== 'meta_only') {
            $total_steps = ($post_type === 'post') ? 5 : 7; // blog: outline+content, meta, faq, schema, timestamp / product: desc, short, faq_html, faq_json, rankmath, seo_title, timestamp
        }

        ob_end_clean();
        wp_send_json_success(['step' => 1, 'total_steps' => $total_steps, 'post_type' => $post_type, 'refresh_type' => $item->refresh_type]);
    }

    // Get progress
    $progress = get_transient($progress_key);
    if (!$progress) { ob_end_clean(); wp_send_json_error('Lost progress data. Start over.'); }

    $refresh_type = $progress['refresh_type'];
    $error = null;

    try {
        if ($post_type === 'post') {
            // Blog steps
            switch ($step) {
                case 2: // Content (or meta for meta_only)
                    if ($refresh_type === 'meta_only') {
                        $r = SEOM_Blog_Refresher::meta_refresh($post_id);
                        if (is_wp_error($r)) throw new Exception($r->get_error_message());
                    } else {
                        $r = SEOM_Blog_Refresher::step_content($post_id);
                        if (is_wp_error($r)) throw new Exception('Content: ' . $r->get_error_message());
                    }
                    break;
                case 3: // Meta description
                    $r = SEOM_Blog_Refresher::step_meta_description($post_id);
                    if (is_wp_error($r)) throw new Exception('Meta: ' . $r->get_error_message());
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

                    if (!empty($sub_errors)) throw new Exception(implode(' | ', $sub_errors));
                    break;
                case 5: // Finalize
                    SEOM_Blog_Refresher::step_timestamp($post_id);
                    break;
            }
        } else {
            // Product steps — all routed through SEOM_Blog_Refresher (which is
            // type-aware via step_long_content / get_post_type checks)
            switch ($step) {
                case 2: // Description (or meta_only bundle)
                    if ($refresh_type === 'meta_only') {
                        $r = SEOM_Blog_Refresher::step_meta_description($post_id);
                        if (is_wp_error($r)) throw new Exception('Meta: ' . $r->get_error_message());
                        $r = SEOM_Blog_Refresher::step_rankmath($post_id);
                        if (is_wp_error($r)) throw new Exception('RankMath: ' . $r->get_error_message());
                        $r = SEOM_Blog_Refresher::step_seo_title($post_id);
                        if (is_wp_error($r)) throw new Exception('SEO Title: ' . $r->get_error_message());
                    } else {
                        $r = SEOM_Blog_Refresher::step_long_content($post_id);
                        if (is_wp_error($r)) throw new Exception('Description: ' . $r->get_error_message());
                        $progress['description'] = $r;
                        set_transient($progress_key, $progress, 3600);
                    }
                    break;
                case 3:
                    $r = SEOM_Blog_Refresher::step_meta_description($post_id);
                    if (is_wp_error($r)) throw new Exception('Short Desc: ' . $r->get_error_message());
                    break;
                case 4:
                    $r = SEOM_Blog_Refresher::step_faq_html($post_id);
                    if (is_wp_error($r)) throw new Exception('FAQ HTML: ' . $r->get_error_message());
                    $progress['faq_html'] = $r;
                    set_transient($progress_key, $progress, 3600);
                    break;
                case 5:
                    $r = SEOM_Blog_Refresher::step_faq_json($post_id, $progress['faq_html'] ?? '');
                    if (is_wp_error($r)) throw new Exception('FAQ JSON: ' . $r->get_error_message());
                    break;
                case 6:
                    $r = SEOM_Blog_Refresher::step_rankmath($post_id);
                    if (is_wp_error($r)) throw new Exception('RankMath: ' . $r->get_error_message());
                    $r = SEOM_Blog_Refresher::step_seo_title($post_id);
                    if (is_wp_error($r)) throw new Exception('SEO Title: ' . $r->get_error_message());
                    break;
                case 7:
                    $r = SEOM_Blog_Refresher::step_timestamp($post_id);
                    if (is_wp_error($r)) throw new Exception('Timestamp: ' . $r->get_error_message());
                    break;
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    // Determine if this is the final step
    $is_final = false;
    if ($refresh_type === 'meta_only') {
        $is_final = ($step === 2);
    } else {
        $is_final = ($post_type === 'post' && $step === 5) || ($post_type !== 'post' && $step === 7);
    }

    // Finalize on last step or error
    if ($is_final || $error) {
        $status = $error ? 'failed' : 'completed';
        $wpdb->update("{$wpdb->prefix}seom_refresh_queue", [
            'status'        => $status,
            'completed_at'  => current_time('mysql'),
            'error_message' => $error,
        ], ['id' => $progress['item_id']]);

        $before = $progress['before'];
        // Retrieve research results from context before it's deleted
        $seo_ctx = get_transient('seom_refresh_context_' . $post_id);
        $research_text = ($seo_ctx && !empty($seo_ctx['competitive_research'])) ? $seo_ctx['competitive_research'] : null;

        $wpdb->insert("{$wpdb->prefix}seom_refresh_history", [
            'post_id'            => $post_id,
            'refresh_date'       => current_time('mysql'),
            'refresh_type'       => $refresh_type,
            'category'           => $progress['category'],
            'priority_score'     => $progress['priority'],
            'clicks_before'      => $before->clicks ?? null,
            'impressions_before' => $before->impressions ?? null,
            'position_before'    => $before->avg_position ?? null,
            'ctr_before'         => $before->ctr ?? null,
            'research_results'   => $research_text,
        ]);

        delete_transient($progress_key);
        delete_transient('seom_refresh_context_' . $post_id);

        // Send notification email for completed/failed refresh
        $settings = seom_get_settings();
        if ($settings['notify_email']) {
            $title = get_the_title($post_id);
            $status_label = $error ? 'Failed' : 'Refreshed';
            $subject = "[SEO AI AutoPilot] {$status_label}: {$title}";
            $cat_labels = ['A' => 'Ghost', 'B' => 'CTR Fix', 'C' => 'Near Win', 'D' => 'Declining', 'E' => 'Visible/Ignored', 'F' => 'Buried', 'M' => 'Manual'];
            $cat = $cat_labels[$progress['category']] ?? $progress['category'];
            $edit_url = admin_url('post.php?action=edit&post=' . $post_id);
            $view_url = get_permalink($post_id);
            $error_row = $error ? '<tr><td style="padding:8px 12px;color:#999;">Error</td><td style="padding:8px 12px;color:#dc2626;">' . esc_html($error) . '</td></tr>' : '';
            $status_color = $error ? '#dc2626' : '#059669';
            $status_icon  = $error ? '&#10007;' : '&#10003;';
            $preview_text = ($error ? '✗ Failed: ' : '✓ Refreshed: ') . $title;

            // Pull the post-refresh SEO meta so the email shows what was actually saved
            $seo_title_after = get_post_meta($post_id, 'rank_math_title', true);
            $meta_desc_after = get_post_meta($post_id, 'rank_math_description', true);
            $seo_rows = '';
            if (!$error) {
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

                $seo_rows = '<tr style="background:#f9fafb;"><td style="padding:8px 12px;color:#999;vertical-align:top;">SEO Title</td><td style="padding:8px 12px;">' . $title_display . '</td></tr>'
                          . '<tr><td style="padding:8px 12px;color:#999;vertical-align:top;">Meta Description</td><td style="padding:8px 12px;">' . $desc_display . '</td></tr>';
            }

            $email_body = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;">'
                // Hidden preheader — pulled into inbox preview text by most clients
                . '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:transparent;">' . esc_html($preview_text) . '</div>'
                . '<div style="background:#1d2327;padding:16px 20px;border-radius:8px 8px 0 0;"><h1 style="margin:0;color:#fff;font-size:16px;">SEO AI AutoPilot</h1></div>'
                . '<div style="background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:20px;">'
                // Colored icon + label as the first visible text — drives the preview snippet
                . '<div style="margin-bottom:12px;">'
                .   '<span style="font-size:22px;color:' . $status_color . ';font-weight:700;line-height:1;">' . $status_icon . '</span>'
                .   '<span style="font-size:14px;color:' . $status_color . ';font-weight:600;margin-left:8px;vertical-align:2px;">' . $status_label . '</span>'
                . '</div>'
                . '<h2 style="margin:0 0 12px;font-size:18px;">' . esc_html($title) . '</h2>'
                . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-size:13px;">'
                . '<tr><td style="padding:8px 12px;color:#999;width:130px;">Type</td><td style="padding:8px 12px;">' . esc_html($post_type) . '</td></tr>'
                . '<tr style="background:#f9fafb;"><td style="padding:8px 12px;color:#999;">Category</td><td style="padding:8px 12px;"><strong>' . esc_html($cat) . '</strong></td></tr>'
                . '<tr><td style="padding:8px 12px;color:#999;">Refresh</td><td style="padding:8px 12px;">' . esc_html($refresh_type) . '</td></tr>'
                . '<tr style="background:#f9fafb;"><td style="padding:8px 12px;color:#999;vertical-align:top;">AI Models</td><td style="padding:8px 12px;">' . esc_html(seom_get_refresh_models_summary($post_type, $refresh_type)) . '</td></tr>'
                . $seo_rows
                . $error_row . '</table>'
                . '<a href="' . esc_url($view_url) . '" style="display:inline-block;padding:8px 16px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;font-size:13px;margin-right:8px;">View</a>'
                . '<a href="' . esc_url($edit_url) . '" style="display:inline-block;padding:8px 16px;background:#f0f0f1;color:#1d2327;text-decoration:none;border-radius:4px;font-size:13px;">Edit</a>'
                . '<p style="margin-top:16px;color:#9ca3af;font-size:11px;">Manual refresh via ' . esc_html(get_bloginfo('name')) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '</p>'
                . '</div></div>';

            $set_html = function () { return 'text/html'; };
            add_filter('wp_mail_content_type', $set_html);
            wp_mail($settings['notify_email'], $subject, $email_body);
            remove_filter('wp_mail_content_type', $set_html);
        }

        if ($error) {
            ob_end_clean();
            wp_send_json_error('Step ' . $step . ': ' . $error);
        }

        ob_end_clean();
        wp_send_json_success(['step' => $step, 'complete' => true, 'post_id' => $post_id, 'status' => 'completed']);
    }

    ob_end_clean();
    wp_send_json_success(['step' => $step]);
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
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders) AND status IN ('pending','processing')", ...$ids));
            wp_send_json_success(['affected' => count($ids)]);
            break;

        case 'delete':
            if (empty($ids)) wp_send_json_error('No items selected.');
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders) AND status IN ('pending','processing')", ...$ids));
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
            $affected = $wpdb->query("DELETE FROM {$table} WHERE status IN ('pending','processing')");
            wp_send_json_success(['affected' => $affected]);
            break;

        default:
            wp_send_json_error('Invalid action.');
    }
});

// ─── Featured Product AJAX ───────────────────────────────────────────────────

// Search WooCommerce products by title for the Featured Product picker.
add_action('wp_ajax_seom_search_products', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $term = sanitize_text_field(wp_unslash($_POST['term'] ?? ''));
    if ($term === '') {
        $rows = $wpdb->get_results("
            SELECT ID, post_title FROM {$wpdb->posts}
            WHERE post_type = 'product' AND post_status = 'publish'
            ORDER BY post_modified DESC
            LIMIT 30
        ");
    } else {
        $like = '%' . $wpdb->esc_like($term) . '%';
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT ID, post_title FROM {$wpdb->posts}
            WHERE post_type = 'product' AND post_status = 'publish'
            AND post_title LIKE %s
            ORDER BY post_title ASC
            LIMIT 30
        ", $like));
    }
    $products = [];
    foreach ($rows as $r) $products[] = ['id' => (int) $r->ID, 'title' => $r->post_title];
    wp_send_json_success(['products' => $products]);
});

// Read the currently-associated featured product for a post.
add_action('wp_ajax_seom_get_post_featured_product', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $post_id = (int) ($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Missing post ID.');

    $pid = (int) get_post_meta($post_id, SEOM_FEATURED_PRODUCT_META_KEY, true);
    $product = null;
    if ($pid) {
        $p = get_post($pid);
        if ($p && $p->post_type === 'product') {
            $product = ['id' => $pid, 'title' => $p->post_title];
        }
    }
    wp_send_json_success(['post_id' => $post_id, 'product' => $product]);
});

// One-shot: save the association, inject the shortcode into the live post
// body, and return success. Used by the front-end "Inject Featured Product"
// admin bar trigger so the user sees the block on page reload — no AI
// refresh needed.
add_action('wp_ajax_seom_inject_featured_product_now', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

    $post_id    = (int) ($_POST['post_id'] ?? 0);
    $product_id = (int) ($_POST['product_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Missing post ID.');

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post') wp_send_json_error('Invalid post.');

    // product_id = 0 means "clear association + strip the shortcode from the body"
    if ($product_id <= 0) {
        delete_post_meta($post_id, SEOM_FEATURED_PRODUCT_META_KEY);
        delete_post_meta($post_id, '_seom_featured_product_auto_selected');
        $stripped = preg_replace('/\[itu_featured_product[^\]]*\]\s*/', '', $post->post_content);
        if ($stripped !== $post->post_content) {
            wp_update_post([
                'ID'                => $post_id,
                'post_content'      => $stripped,
                'post_modified'     => $post->post_modified,
                'post_modified_gmt' => $post->post_modified_gmt,
            ]);
        }
        wp_send_json_success(['cleared' => true]);
    }

    $product = get_post($product_id);
    if (!$product || $product->post_type !== 'product') wp_send_json_error('Invalid product.');

    // 1. Persist the association
    update_post_meta($post_id, SEOM_FEATURED_PRODUCT_META_KEY, $product_id);
    delete_post_meta($post_id, '_seom_featured_product_auto_selected');

    // 2. Inject the shortcode into the live post body (replaces any
    //    existing [itu_featured_product] copies first, so switching to
    //    a different product cleans up the old one).
    $new_body = seom_inject_featured_product_shortcode($post->post_content, $product_id);
    if ($new_body !== $post->post_content) {
        // Preserve the original modified date — shortcode injection is not
        // a content edit and should not bump the post in date-sorted archives.
        $update = wp_update_post([
            'ID'                => $post_id,
            'post_content'      => $new_body,
            'post_modified'     => $post->post_modified,
            'post_modified_gmt' => $post->post_modified_gmt,
        ], true);
        if (is_wp_error($update)) wp_send_json_error('Update failed: ' . $update->get_error_message());
    }

    wp_send_json_success([
        'product' => ['id' => $product_id, 'title' => $product->post_title],
    ]);
});

// Set or clear the featured product association. Passing product_id=0 clears it.
add_action('wp_ajax_seom_set_post_featured_product', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $post_id    = (int) ($_POST['post_id'] ?? 0);
    $product_id = (int) ($_POST['product_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Missing post ID.');

    if ($product_id > 0) {
        $p = get_post($product_id);
        if (!$p || $p->post_type !== 'product') wp_send_json_error('Invalid product.');
        update_post_meta($post_id, SEOM_FEATURED_PRODUCT_META_KEY, $product_id);
        delete_post_meta($post_id, '_seom_featured_product_auto_selected');

        // Also inject the shortcode into the post body so the featured
        // product block renders immediately — not just on the next refresh.
        $post = get_post($post_id);
        if ($post && function_exists('seom_inject_featured_product_shortcode')) {
            $new_body = seom_inject_featured_product_shortcode($post->post_content, $product_id);
            if ($new_body !== $post->post_content) {
                wp_update_post([
                    'ID'                => $post_id,
                    'post_content'      => $new_body,
                    'post_modified'     => $post->post_modified,
                    'post_modified_gmt' => $post->post_modified_gmt,
                ]);
            }
        }

        wp_send_json_success(['product' => ['id' => $product_id, 'title' => $p->post_title]]);
    }

    // Clear: remove meta + strip shortcode from body
    $post = get_post($post_id);
    delete_post_meta($post_id, SEOM_FEATURED_PRODUCT_META_KEY);
    delete_post_meta($post_id, '_seom_featured_product_auto_selected');
    if ($post) {
        $stripped = preg_replace('/\[itu_featured_product[^\]]*\]\s*/', '', $post->post_content);
        if ($stripped !== $post->post_content) {
            wp_update_post([
                'ID'                => $post_id,
                'post_content'      => $stripped,
                'post_modified'     => $post->post_modified,
                'post_modified_gmt' => $post->post_modified_gmt,
            ]);
        }
    }
    wp_send_json_success(['product' => null]);
});

add_action('wp_ajax_seom_save_settings', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $fields = [
        'gsc_property_url', 'notify_email', 'exclude_post_ids', 'exclude_categories', 'gap_seed_categories',
        'perplexity_api_key', 'research_provider', 'research_model', 'research_categories',
        // ── Authority / AI visibility (phase 1a) ──
        'authority_org_name', 'authority_org_url', 'authority_founding_date',
        // ── Glossary (phase 3a) ──
        'glossary_url',
        // ── Cybersecurity promo tab ──
        'cyber_tab_title',
    ];
    $int_fields = [
        'daily_limit', 'cooldown_days', 'ghost_threshold',
        'ctr_fix_min_impressions', 'near_win_min_impressions',
        'buried_min_impressions', 'visible_min_impressions', 'visible_max_clicks', 'gap_keyword_cooldown',
        'limit_max', 'research_min_impressions', 'research_min_position',
        'glossary_max_links_per_post',
    ];
    $float_fields = ['ctr_fix_max_ctr', 'near_win_min_pos', 'near_win_max_pos', 'decline_threshold_pct'];
    $bool_fields = ['enabled', 'dry_run', 'research_enabled', 'ai_bots_allow', 'llms_txt_enabled', 'glossary_links_enabled', 'cyber_tab_enabled'];
    // Multi-line text fields — preserve newlines instead of sanitize_text_field which strips them.
    $multiline_fields = [
        'authority_about_blurb', 'authority_same_as', 'authority_knows_about', 'llms_txt_extra_lines',
    ];

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
    if (isset($_POST['limit_mode']) && in_array($_POST['limit_mode'], ['fixed', 'adaptive', 'burst'])) {
        $new['limit_mode'] = $_POST['limit_mode'];
    }
    // Cybersecurity promo tab URL — store a clean URL.
    if (isset($_POST['cyber_tab_url'])) {
        $new['cyber_tab_url'] = esc_url_raw(trim(wp_unslash($_POST['cyber_tab_url'])));
    }
    foreach ($multiline_fields as $f) {
        if (!isset($_POST[$f])) continue;
        $raw = wp_unslash($_POST[$f]);
        // Strip HTML tags but preserve line breaks; trim each line.
        $clean = wp_strip_all_tags((string) $raw);
        $clean = preg_replace("/\r\n?/", "\n", $clean);
        $lines = array_map('trim', explode("\n", $clean));
        $new[$f] = implode("\n", $lines);
    }
    // Sub-team byline map is JSON — validate before saving so a typo can't
    // brick the byline resolver.
    if (isset($_POST['authority_byline_map'])) {
        $raw = wp_unslash($_POST['authority_byline_map']);
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            // Re-encode compact (without pretty-print) for storage; UI will
            // pretty-print on render.
            $cleaned = [];
            foreach ($decoded as $team => $tags) {
                $team = sanitize_text_field((string) $team);
                if ($team === '') continue;
                if (!is_array($tags)) $tags = [];
                $tags = array_values(array_filter(array_map(function ($t) {
                    return sanitize_text_field((string) $t);
                }, $tags), function ($t) { return $t !== ''; }));
                $cleaned[$team] = $tags;
            }
            $new['authority_byline_map'] = wp_json_encode($cleaned, JSON_UNESCAPED_SLASHES);
        }
    }

    seom_update_settings($new);

    // Featured product template is stored in its own option (raw HTML,
    // not inside the settings array) so we can render it cheaply from
    // the shortcode without touching seom_get_settings(). wp_unslash to
    // undo the auto-slashing WordPress applies to $_POST.
    if (isset($_POST['featured_product_template'])) {
        $tpl = wp_unslash($_POST['featured_product_template']);
        // Admin-only field — allow HTML but strip <script> and event handlers.
        $tpl = wp_kses($tpl, array_merge(wp_kses_allowed_html('post'), [
            'div'  => ['style' => [], 'class' => []],
            'span' => ['style' => [], 'class' => []],
            'a'    => ['href' => [], 'style' => [], 'class' => [], 'target' => [], 'rel' => []],
            'h1'   => ['style' => [], 'class' => []],
            'h2'   => ['style' => [], 'class' => []],
            'h3'   => ['style' => [], 'class' => []],
            'p'    => ['style' => [], 'class' => []],
            'strong' => ['style' => [], 'class' => []],
            'em'   => ['style' => [], 'class' => []],
            'br'   => [],
        ]));
        update_option('seom_featured_product_template', $tpl);
    }

    wp_send_json_success('Settings saved.');
});

add_action('wp_ajax_seom_get_indexed', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $post_type = sanitize_text_field($_POST['post_type'] ?? 'all');
    $sort = sanitize_text_field($_POST['sort'] ?? 'clicks');
    $order = sanitize_text_field($_POST['order'] ?? 'DESC');
    $filter = sanitize_text_field($_POST['filter'] ?? 'all');
    $page = max(1, intval($_POST['page'] ?? 1));
    $date_range = max(1, min(365, intval($_POST['date_range'] ?? 28)));
    $per_page = 50;

    // ── Custom date window ──
    // When the client supplies date_from + date_to, those become the
    // authoritative window and override $date_range. We still synthesize
    // an integer $date_range from the inclusive day count so downstream
    // period-prior comparisons + the site-totals override key continue to
    // work without per-call special-casing.
    $date_from = sanitize_text_field($_POST['date_from'] ?? '');
    $date_to   = sanitize_text_field($_POST['date_to'] ?? '');
    $custom_window = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)
                  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to);
    if ($custom_window && $date_from > $date_to) {
        $tmp = $date_from; $date_from = $date_to; $date_to = $tmp;
    }
    if ($custom_window) {
        $date_range = max(1, (int) ((strtotime($date_to) - strtotime($date_from)) / 86400) + 1);
    }

    // Handle "Other" type — serve untracked URLs from seom_untracked_metrics
    if ($post_type === 'other') {
        $ut_table = $wpdb->prefix . 'seom_untracked_metrics';
        $ut_exists = ($wpdb->get_var("SHOW TABLES LIKE '{$ut_table}'") === $ut_table);
        if (!$ut_exists) wp_send_json_success(['rows' => [], 'total' => 0, 'page' => 1, 'summary' => (object)['total_pages' => 0, 'pages_with_impressions' => 0, 'ghost_pages' => 0, 'new_pages' => 0, 'total_clicks' => 0, 'total_impressions' => 0, 'avg_position' => 0], 'date_range' => $date_range, 'cooldown_days' => 90, 'is_other' => true]);

        $ut_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$ut_table}");
        if (!$ut_date) wp_send_json_success(['rows' => [], 'total' => 0, 'page' => 1, 'summary' => (object)['total_pages' => 0, 'pages_with_impressions' => 0, 'ghost_pages' => 0, 'new_pages' => 0, 'total_clicks' => 0, 'total_impressions' => 0, 'avg_position' => 0], 'date_range' => $date_range, 'cooldown_days' => 90, 'is_other' => true]);

        $allowed_sorts = ['url', 'clicks', 'impressions', 'ctr', 'avg_position', 'url_type'];
        if (!in_array($sort, $allowed_sorts)) $sort = 'impressions';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $offset = ($page - 1) * $per_page;

        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ut_table} WHERE date_collected = %s", $ut_date));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT url, clicks, impressions, ctr, avg_position, url_type, date_collected FROM {$ut_table} WHERE date_collected = %s ORDER BY {$sort} {$order} LIMIT %d OFFSET %d", $ut_date, $per_page, $offset));

        // Build summary matching the expected format
        $ut_summary = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) as total_pages, SUM(CASE WHEN impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions, SUM(CASE WHEN impressions = 0 THEN 1 ELSE 0 END) as ghost_pages, 0 as new_pages, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions, AVG(CASE WHEN avg_position > 0 THEN avg_position END) as avg_position FROM {$ut_table} WHERE date_collected = %s", $ut_date));

        // Get prior collection date for delta comparison
        $prior_dates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT date_collected FROM {$ut_table} WHERE date_collected < %s ORDER BY date_collected DESC LIMIT 1", $ut_date
        ));
        $prior_date = !empty($prior_dates) ? $prior_dates[0] : null;

        // Build prior data lookup by URL
        $prior_map = [];
        if ($prior_date) {
            $prior_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT url, clicks, impressions, avg_position FROM {$ut_table} WHERE date_collected = %s", $prior_date
            ));
            foreach ($prior_rows as $pr) {
                $prior_map[$pr->url] = $pr;
            }
        }

        // Add fields the JS expects + prior period data
        foreach ($rows as &$r) {
            $prior = $prior_map[$r->url] ?? null;
            $r->post_id = 0;
            $r->post_title = $r->url;
            $r->post_type = 'other';
            $r->post_date = $r->date_collected;
            $r->last_refresh = null;
            $r->last_refresh_type = null;
            $r->in_queue = false;
            $r->was_category = 'OTHER';
            $r->research_date = null;
            $r->clicks_prior = $prior ? (int) $prior->clicks : null;
            $r->impressions_prior = $prior ? (int) $prior->impressions : null;
            $r->position_prior = $prior ? (float) $prior->avg_position : null;
        }
        unset($r);

        wp_send_json_success([
            'rows'          => $rows,
            'total'         => $total,
            'page'          => $page,
            'summary'       => $ut_summary,
            'date_range'    => $date_range,
            'cooldown_days' => intval(seom_get_settings()['cooldown_days']),
            'is_other'      => true,
        ]);
    }

    $type_sql = ($post_type === 'all')
        ? "p.post_type IN ('product','post','page')"
        : $wpdb->prepare("p.post_type = %s", $post_type);
    $per_page = 50;
    $offset = ($page - 1) * $per_page;
    $date_warning = '';

    // For non-default date ranges, build a temp table with the requested period's totals.
    // Prefer local seom_daily_page_metrics (fast, no GSC quota) when it has enough history;
    // fall back to a live GSC fetch only when local daily data doesn't cover the range.
    // Custom windows always go through the temp-table path — no shortcut to the
    // 28-day snapshot, since the snapshot represents a single hard-coded window.
    $use_live_gsc = ($date_range !== 28) || $custom_window;
    $metrics_table = $wpdb->prefix . 'seom_page_metrics';
    $temp_table = '';

    $temp_table_built_locally = false;
    if ($use_live_gsc) {
        @set_time_limit(60);

        // Check if local daily per-page data covers the requested range
        $daily_table_name = $wpdb->prefix . 'seom_daily_page_metrics';
        $has_daily = ($wpdb->get_var("SHOW TABLES LIKE '{$daily_table_name}'") === $daily_table_name);
        $daily_earliest = $has_daily ? $wpdb->get_var("SELECT MIN(date_collected) FROM {$daily_table_name}") : null;
        $daily_days_available = $daily_earliest ? ((int) ((time() - strtotime($daily_earliest)) / 86400) + 1) : 0;
        // Custom windows: as long as we have any daily data, use it. Partial
        // overlap with the user's chosen window is acceptable — the temp table
        // simply won't include rows outside the available range.
        $use_local_daily = $custom_window
            ? $has_daily
            : ($has_daily && $daily_days_available >= $date_range);

        if ($use_local_daily) {
            // ── Build temp table from local daily data ──
            $temp_table = $wpdb->prefix . 'seom_live_metrics';
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}");
            $wpdb->query("SET SESSION max_heap_table_size = 67108864"); // 64MB
            $wpdb->query("CREATE TEMPORARY TABLE {$temp_table} (
                post_id BIGINT UNSIGNED NOT NULL,
                url VARCHAR(500),
                clicks INT DEFAULT 0,
                impressions INT DEFAULT 0,
                ctr FLOAT DEFAULT 0,
                avg_position FLOAT DEFAULT 0,
                date_collected DATE,
                top_queries VARCHAR(500),
                PRIMARY KEY (post_id)
            ) ENGINE=MEMORY");

            // Anchor the window to the NEWEST AVAILABLE daily date, not today.
            // Google's T-3 finalization lag means anchoring to today() returns
            // an incomplete window (missing the 2-3 most recent days entirely)
            // instead of N consecutive finalized days. The chart already does
            // this correctly via $newest_available; we mirror it here for the
            // cards so the per-page sums and avg_position match.
            if ($custom_window) {
                $window_start_date = $date_from;
                $window_end_date   = $date_to;
            } else {
                $newest_daily = $wpdb->get_var("SELECT MAX(date_collected) FROM {$daily_table_name}");
                if (!$newest_daily) $newest_daily = date('Y-m-d');
                $window_end_date   = $newest_daily;
                $window_start_date = date('Y-m-d', strtotime("{$window_end_date} -" . ($date_range - 1) . " days"));
            }

            $today = date('Y-m-d');
            $wpdb->query($wpdb->prepare("
                INSERT INTO {$temp_table} (post_id, url, clicks, impressions, ctr, avg_position, date_collected)
                SELECT
                    post_id,
                    MAX(url) as url,
                    SUM(clicks) as clicks,
                    SUM(impressions) as impressions,
                    CASE WHEN SUM(impressions) > 0 THEN ROUND(SUM(clicks) / SUM(impressions) * 100, 4) ELSE 0 END as ctr,
                    CASE WHEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END) > 0
                        THEN ROUND(SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN avg_position * impressions ELSE 0 END) / SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END), 1)
                        ELSE 0
                    END as avg_position,
                    %s as date_collected
                FROM {$daily_table_name}
                WHERE date_collected BETWEEN %s AND %s
                AND post_id > 0
                GROUP BY post_id
            ", $today, $window_start_date, $window_end_date));

            // Add zero-rows for tracked posts that have no daily data in this window
            $wpdb->query("
                INSERT IGNORE INTO {$temp_table} (post_id, url, clicks, impressions, ctr, avg_position, date_collected)
                SELECT DISTINCT pm.post_id, '', 0, 0, 0, 0, '{$today}'
                FROM {$wpdb->prefix}seom_page_metrics pm
                WHERE pm.post_id > 0
                AND pm.post_id NOT IN (SELECT post_id FROM {$temp_table})
            ");

            $metrics_table = $temp_table;
            $temp_table_built_locally = true;
        }
    }

    // Live GSC fallback — only when local daily data didn't cover the requested range
    if ($use_live_gsc && !$temp_table_built_locally) {
        // Custom date windows can't use the GSC live fetch — that endpoint only
        // accepts a "days back from yesterday" window, not arbitrary start/end
        // dates. Bail with a clear message instead of silently swapping windows.
        if ($custom_window) {
            wp_send_json_error('No daily per-page data is available for that custom date range. Custom dates can only span the period covered by the local daily collection table.');
        }
        // ── Fall back to live GSC fetch ──
        $settings_gsc = seom_get_settings();
        if (empty($settings_gsc['gsc_credentials_json']) || empty($settings_gsc['gsc_property_url'])) {
            wp_send_json_error('GSC not configured. Date range filtering requires Google Search Console connection.');
        }

        // Check cache first (1 hour per date range)
        $cache_key = 'seom_gsc_range_' . $date_range;
        $cached = get_transient($cache_key);

        if (!$cached) {
            $client = new SEOM_GSC_Client($settings_gsc['gsc_credentials_json'], $settings_gsc['gsc_property_url']);
            $live_metrics = $client->get_all_page_metrics($date_range);
            if (is_wp_error($live_metrics)) {
                wp_send_json_error('GSC API error: ' . $live_metrics->get_error_message());
            }
            set_transient($cache_key, $live_metrics, 3600);
            $cached = $live_metrics;
        }

        // Check how many days of stored data we have
        $earliest = $wpdb->get_var("SELECT MIN(date_collected) FROM {$metrics_table}");
        if ($earliest) {
            $days_available = (int) ((time() - strtotime($earliest)) / 86400);
            if ($days_available < $date_range) {
                $date_warning = "Our data collection started {$days_available} days ago. "
                    . "The GSC API is providing data for the full {$date_range}-day range, but our local trend tracking and history only covers {$days_available} days. "
                    . "The metrics shown below come directly from GSC and are accurate for the selected range.";
            }
        }

        // Build a temp table in memory for the live data
        $temp_table = $wpdb->prefix . 'seom_live_metrics';
        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp_table}");
        // MEMORY engine allocates full VARCHAR width per row — keep columns small
        // and increase heap limit to handle ~4000+ rows
        $wpdb->query("SET SESSION max_heap_table_size = 67108864"); // 64MB
        $wpdb->query("CREATE TEMPORARY TABLE {$temp_table} (
            post_id BIGINT UNSIGNED NOT NULL,
            url VARCHAR(500),
            clicks INT DEFAULT 0,
            impressions INT DEFAULT 0,
            ctr FLOAT DEFAULT 0,
            avg_position FLOAT DEFAULT 0,
            date_collected DATE,
            top_queries VARCHAR(500),
            PRIMARY KEY (post_id)
        ) ENGINE=MEMORY");

        // Build URL→post_id map from existing metrics table (already stored URLs, no get_permalink calls)
        $url_map = [];
        $stored_urls = $wpdb->get_results("SELECT post_id, url FROM {$wpdb->prefix}seom_page_metrics WHERE url IS NOT NULL AND url != '' GROUP BY post_id, url");
        foreach ($stored_urls as $su) {
            $url_map[$su->url] = (int) $su->post_id;
            $url_map[rtrim($su->url, '/')] = (int) $su->post_id;
        }

        // Batch insert GSC data using bulk VALUES
        $today = date('Y-m-d');
        $inserted_ids = [];
        $batch_values = [];
        $batch_count = 0;

        foreach ($cached as $url => $data) {
            $pid = $url_map[$url] ?? $url_map[rtrim($url, '/')] ?? $url_map[$url . '/'] ?? null;
            if (!$pid || isset($inserted_ids[$pid])) continue;

            $clicks = intval($data['clicks'] ?? 0);
            $impressions = intval($data['impressions'] ?? 0);
            $ctr = floatval($data['ctr'] ?? 0);
            $position = floatval($data['position'] ?? 0);
            $safe_url = $wpdb->prepare('%s', $url);

            $batch_values[] = "({$pid}, {$safe_url}, {$clicks}, {$impressions}, {$ctr}, {$position}, '{$today}')";
            $inserted_ids[$pid] = true;
            $batch_count++;

            // Flush every 500 rows
            if ($batch_count >= 500) {
                $wpdb->query("INSERT INTO {$temp_table} (post_id, url, clicks, impressions, ctr, avg_position, date_collected) VALUES " . implode(',', $batch_values));
                $batch_values = [];
                $batch_count = 0;
            }
        }

        // Insert zero-rows for posts we track but GSC didn't return — bulk from existing post_ids
        $tracked_ids = $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->prefix}seom_page_metrics");
        foreach ($tracked_ids as $tid) {
            $tid = (int) $tid;
            if (isset($inserted_ids[$tid])) continue;
            $batch_values[] = "({$tid}, '', 0, 0, 0, 0, '{$today}')";
            $inserted_ids[$tid] = true;
            $batch_count++;
            if ($batch_count >= 500) {
                $wpdb->query("INSERT INTO {$temp_table} (post_id, url, clicks, impressions, ctr, avg_position, date_collected) VALUES " . implode(',', $batch_values));
                $batch_values = [];
                $batch_count = 0;
            }
        }

        // Flush remaining
        if (!empty($batch_values)) {
            $wpdb->query("INSERT INTO {$temp_table} (post_id, url, clicks, impressions, ctr, avg_position, date_collected) VALUES " . implode(',', $batch_values));
        }

        $metrics_table = $temp_table;
    }

    $allowed_sorts = ['clicks', 'impressions', 'ctr', 'avg_position', 'post_title', 'post_date', 'last_refresh'];
    if (!in_array($sort, $allowed_sorts)) $sort = 'clicks';
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    $sort_map = [
        'post_title' => 'p.post_title',
        'post_date'  => 'p.post_date',
        'last_refresh' => 'lr_meta.meta_value',
    ];
    $sort_col = $sort_map[$sort] ?? "m.$sort";

    $settings = seom_get_settings();

    // Build filter clause for standard metric-based filters
    $filter_sql = '';
    switch ($filter) {
        case 'ghost':
            $filter_sql = ' AND m.impressions <= ' . intval($settings['ghost_threshold']) . ' AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY)';
            break;
        case 'new':
            $filter_sql = ' AND p.post_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)';
            break;
        case 'page1':
            $filter_sql = ' AND m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0';
            break;
        case 'page2':
            $filter_sql = ' AND m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0';
            break;
        case 'low_ctr':
            // CTR below 50% of position benchmark (matches Category B logic)
            $min_imp = intval($settings['ctr_fix_min_impressions']);
            $filter_sql = " AND m.impressions >= {$min_imp} AND ("
                        . "(m.avg_position <= 1 AND m.ctr < 15)"     // Pos 1: expect 30%, flag <15%
                        . " OR (m.avg_position > 1 AND m.avg_position <= 2 AND m.ctr < 8.5)"  // Pos 2: expect 17%, flag <8.5%
                        . " OR (m.avg_position > 2 AND m.avg_position <= 3 AND m.ctr < 5.5)"  // Pos 3: expect 11%, flag <5.5%
                        . " OR (m.avg_position > 3 AND m.avg_position <= 5 AND m.ctr < 3.5)"  // Pos 4-5: expect 7%, flag <3.5%
                        . " OR (m.avg_position > 5 AND m.avg_position <= 10 AND m.ctr < 1.5)" // Pos 6-10: expect 3%, flag <1.5%
                        . " OR (m.avg_position > 10 AND m.avg_position <= 15 AND m.ctr < 0.5)" // Pos 11-15: expect 1%, flag <0.5%
                        . ")";
            break;
        case 'buried':
            $filter_sql = ' AND m.avg_position > 20 AND m.impressions >= ' . intval($settings['buried_min_impressions']);
            break;
        case 'underperforming':
            $ghost = intval($settings['ghost_threshold']);
            $near_imp = intval($settings['near_win_min_impressions']);
            $buried_imp = intval($settings['buried_min_impressions']);
            $ctr_imp = intval($settings['ctr_fix_min_impressions']);
            $vis_imp = intval($settings['visible_min_impressions']);
            $vis_clicks = intval($settings['visible_max_clicks']);
            $filter_sql = " AND ("
                        . "m.impressions <= {$ghost}"                                                          // Ghost
                        . " OR (m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions >= {$near_imp})" // Near Wins
                        . " OR (m.avg_position > 20 AND m.impressions >= {$buried_imp})"                       // Buried
                        . " OR (m.impressions >= {$vis_imp} AND m.clicks <= {$vis_clicks})"                    // Visible but Ignored
                        // CTR Fix — position-aware
                        . " OR (m.impressions >= {$ctr_imp} AND ("
                        .   "(m.avg_position <= 1 AND m.ctr < 15)"
                        .   " OR (m.avg_position > 1 AND m.avg_position <= 2 AND m.ctr < 8.5)"
                        .   " OR (m.avg_position > 2 AND m.avg_position <= 3 AND m.ctr < 5.5)"
                        .   " OR (m.avg_position > 3 AND m.avg_position <= 5 AND m.ctr < 3.5)"
                        .   " OR (m.avg_position > 5 AND m.avg_position <= 10 AND m.ctr < 1.5)"
                        .   " OR (m.avg_position > 10 AND m.avg_position <= 15 AND m.ctr < 0.5)"
                        . "))"
                        . ")";
            break;
        case 'limited':
            // Limited Visibility: has some impressions but very few (<100), OR position is 30+
            $filter_sql = ' AND m.impressions > 0 AND (m.impressions < 100 OR m.avg_position >= 30)';
            break;
        case 'top_performers':
            // Top Performers: CTR meets or exceeds 60% of expected for position + meaningful traffic
            $filter_sql = " AND m.clicks >= 3 AND m.impressions >= 20 AND ("
                        . "(m.avg_position <= 1 AND m.ctr >= 18)"       // Pos 1: 60% of 30%
                        . " OR (m.avg_position > 1 AND m.avg_position <= 2 AND m.ctr >= 10.2)"  // Pos 2: 60% of 17%
                        . " OR (m.avg_position > 2 AND m.avg_position <= 3 AND m.ctr >= 6.6)"   // Pos 3: 60% of 11%
                        . " OR (m.avg_position > 3 AND m.avg_position <= 5 AND m.ctr >= 4.2)"   // Pos 4-5: 60% of 7%
                        . " OR (m.avg_position > 5 AND m.avg_position <= 10 AND m.ctr >= 1.8)"  // Pos 6-10: 60% of 3%
                        . " OR (m.avg_position > 10 AND m.avg_position <= 20 AND m.ctr >= 0.6)" // Page 2: 60% of 1%
                        . ")";
            break;
        case 'stars':
            // Stars: High-traffic pages carrying the site — 15+ clicks and 200+ impressions
            $filter_sql = ' AND m.clicks >= 15 AND m.impressions >= 200';
            break;
        case 'lost':
            // Lost: Pages that currently have 0 impressions but previously had meaningful impressions
            // in an earlier collection — these have dropped out of the index. Distinct from ghosts
            // which may never have had impressions.
            $ghost_t = intval($settings['ghost_threshold']);
            $filter_sql = " AND m.impressions <= {$ghost_t} AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY)"
                . " AND m.post_id IN (SELECT pm2.post_id FROM {$wpdb->prefix}seom_page_metrics pm2"
                . " WHERE pm2.impressions > 10 AND pm2.post_id = m.post_id AND pm2.date_collected < m.date_collected)";
            break;
        case 'stale_year':
            // Pages with a date-context year in the title that is older than the current year
            // Excludes product version numbers (Windows Server 2016, Word 2019, SQL 2022, etc.)
            $current_year = (int) date('Y');
            $year_patterns = [];
            // Exclude titles containing known product version patterns
            $version_excludes = ['Server%', 'Windows%', 'Office%', 'Word%', 'Excel%', 'Outlook%',
                'SQL%', 'Exchange%', 'SharePoint%', 'Visual Studio%', 'AutoCAD%', 'R2%'];
            for ($y = 2020; $y < $current_year; $y++) {
                $year_patterns[] = $wpdb->prepare("p.post_title LIKE %s", '%' . $y . '%');
            }
            if (!empty($year_patterns)) {
                $exclude_parts = [];
                foreach ($version_excludes as $ve) {
                    for ($y = 2020; $y < $current_year; $y++) {
                        $exclude_parts[] = $wpdb->prepare("p.post_title LIKE %s", '%' . rtrim($ve, '%') . '%' . $y . '%');
                    }
                }
                $filter_sql = " AND (" . implode(' OR ', $year_patterns) . ")";
                if (!empty($exclude_parts)) {
                    $filter_sql .= " AND NOT (" . implode(' OR ', $exclude_parts) . ")";
                }
            }
            break;
    }

    // For the temp table (live GSC), no need for the MAX(date_collected) subquery — one row per post
    $latest_join = $use_live_gsc
        ? ""
        : "INNER JOIN (
            SELECT post_id, MAX(date_collected) as max_date
            FROM {$metrics_table}
            GROUP BY post_id
        ) latest ON m.post_id = latest.post_id AND m.date_collected = latest.max_date";

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT m.*, p.post_title, p.post_type, p.post_date,
               lr_meta.meta_value as last_refresh,
               rh_latest.refresh_type as last_refresh_type
        FROM {$metrics_table} m
        {$latest_join}
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        LEFT JOIN {$wpdb->postmeta} lr_meta ON p.ID = lr_meta.post_id AND lr_meta.meta_key = 'last_page_refresh'
        LEFT JOIN (
            SELECT h.post_id, h.refresh_type
            FROM {$wpdb->prefix}seom_refresh_history h
            INNER JOIN (SELECT post_id, MAX(id) as max_id FROM {$wpdb->prefix}seom_refresh_history GROUP BY post_id) hmax
                ON h.id = hmax.max_id
        ) rh_latest ON m.post_id = rh_latest.post_id
        WHERE {$type_sql} AND p.post_status = 'publish'
        $filter_sql
        ORDER BY $sort_col $order
        LIMIT %d OFFSET %d
    ", $per_page, $offset));

    $total = (int) $wpdb->get_var("
        SELECT COUNT(DISTINCT m.post_id)
        FROM {$metrics_table} m
        {$latest_join}
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE {$type_sql} AND p.post_status = 'publish'
        $filter_sql
    ");

    $ghost_threshold = intval($settings['ghost_threshold']);
    // Summary stats — filtered to match the current view.
    //
    // avg_position is computed as IMPRESSION-WEIGHTED average to match how
    // Google Search Console reports it. Plain AVG() over per-page rows would
    // give every page equal weight regardless of how many impressions it had,
    // which makes the long tail of low-impression pages at position 50+ pull
    // the average way up. GSC weights each impression equally, so a page with
    // 10,000 impressions at position 5 contributes 10,000× more than a page
    // with 1 impression at position 50.
    //
    // Formula: SUM(position × impressions) / SUM(impressions)
    // Filter: only count rows where both position > 0 AND impressions > 0
    // (rows with no impressions have no contribution to the average anyway).
    $summary = $wpdb->get_row("
        SELECT
            COUNT(DISTINCT m.post_id) as total_pages,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            CASE
                WHEN SUM(CASE WHEN m.avg_position > 0 AND m.impressions > 0 THEN m.impressions ELSE 0 END) > 0
                THEN ROUND(
                    SUM(CASE WHEN m.avg_position > 0 AND m.impressions > 0 THEN m.avg_position * m.impressions ELSE 0 END)
                    / SUM(CASE WHEN m.avg_position > 0 AND m.impressions > 0 THEN m.impressions ELSE 0 END),
                    1
                )
                ELSE 0
            END as avg_position,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
            SUM(CASE WHEN m.impressions <= {$ghost_threshold} AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(CASE WHEN m.impressions <= {$ghost_threshold} AND p.post_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as new_pages
        FROM {$metrics_table} m
        {$latest_join}
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE {$type_sql} AND p.post_status = 'publish'
        $filter_sql
    ");

    // Track whether avg_position came from GSC's authoritative no-dimension
    // aggregate or was computed locally from per-page rows. The card uses this
    // to show an "(approx)" label on the avg_position metric when it's the
    // local computation, since per-page reconstruction can drift from GSC's
    // own number by 2-3 positions (the long tail of low-impression pages
    // weighted into our average doesn't appear in GSC's site-wide aggregate).
    $summary->avg_position_source = 'computed';

    if (!$custom_window && $post_type === 'all' && $filter === 'all') {
        // ── Site-totals override (avg_position + clicks + impressions) ──
        //
        // For unfiltered All / All views, replace the per-page-derived metrics
        // with GSC's authoritative no-dimension site-wide aggregates. Those
        // numbers are computed by Google directly from the search-results
        // impression log and exactly match the GSC web dashboard. The per-page
        // sum drifts because the ['page'] dimension call drops anonymized
        // queries and impressions Google can't attribute to a single canonical
        // page — that dropped slice skews high-impression / good-position, so
        // our weighted average ends up worse (higher number = lower rank).
        //
        // The 28-day default reads from update_option('seom_site_totals')
        // (legacy single-range cache). Other ranges read from the new
        // seom_site_totals_by_range cache populated nightly by the collector
        // for [7, 14, 28, 60, 90, 180].
        //
        // Filtered/typed views (further down — this block doesn't run) keep
        // the per-page weighted calc with an "(approx)" label since GSC has
        // no concept of WordPress post type.
        $override = null;
        if ($date_range === 28) {
            $st = seom_get_site_totals();
            if ($st['date'] && floatval($st['position']) > 0) $override = $st;
        } else {
            $override = seom_get_site_totals_for_range($date_range);
        }
        if ($override && !empty($override['date']) && floatval($override['position']) > 0) {
            $summary->avg_position        = floatval($override['position']);
            $summary->total_clicks        = intval($override['clicks']);
            $summary->total_impressions   = intval($override['impressions']);
            $summary->avg_position_source = 'gsc_aggregate';
        }

        // Total Pages / With Impressions count tracked WP posts only. Untracked
        // GSC URLs (query-string variants, archives, stale URLs, etc.) inflate
        // the number well past the actual page count and misrepresent indexing
        // health. Browse them via the "Other" post-type filter instead.
    }

    // ── Prior-period summary for count card deltas ──
    // Build a second temp table aggregated over the prior date_range window
    // and run the same filter-aware summary query against it.
    $summary_prior = null;
    $prior_temp = '';
    $prior_temp_built = false;
    $daily_table_name_pp = $wpdb->prefix . 'seom_daily_page_metrics';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$daily_table_name_pp}'") === $daily_table_name_pp) {
        $earliest_pp = $wpdb->get_var("SELECT MIN(date_collected) FROM {$daily_table_name_pp}");
        $days_available_pp = $earliest_pp ? ((int) ((time() - strtotime($earliest_pp)) / 86400) + 1) : 0;
        // Need at least 2x the range so a prior window exists
        if ($days_available_pp >= ($date_range * 2)) {
            $prior_temp = $wpdb->prefix . 'seom_live_metrics_prior';
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$prior_temp}");
            $wpdb->query("SET SESSION max_heap_table_size = 67108864");
            $wpdb->query("CREATE TEMPORARY TABLE {$prior_temp} (
                post_id BIGINT UNSIGNED NOT NULL,
                url VARCHAR(500),
                clicks INT DEFAULT 0,
                impressions INT DEFAULT 0,
                ctr FLOAT DEFAULT 0,
                avg_position FLOAT DEFAULT 0,
                date_collected DATE,
                top_queries VARCHAR(500),
                PRIMARY KEY (post_id)
            ) ENGINE=MEMORY");

            // Anchor the prior window to the day immediately before the current
            // window's anchor — same logic as the chart's prior overlay. Without
            // this, the prior period is computed from today() and ends up with
            // gaps + a misaligned comparison vs the current period.
            if ($custom_window) {
                $current_window_start = $date_from;
                $prior_end_pp   = date('Y-m-d', strtotime("{$current_window_start} -1 day"));
                $prior_start_pp = date('Y-m-d', strtotime("{$prior_end_pp} -" . ($date_range - 1) . " days"));
            } else {
                $newest_pp = $wpdb->get_var("SELECT MAX(date_collected) FROM {$daily_table_name_pp}");
                if (!$newest_pp) $newest_pp = date('Y-m-d');
                $current_window_start = date('Y-m-d', strtotime("{$newest_pp} -" . ($date_range - 1) . " days"));
                $prior_end_pp   = date('Y-m-d', strtotime("{$current_window_start} -1 day"));
                $prior_start_pp = date('Y-m-d', strtotime("{$prior_end_pp} -" . ($date_range - 1) . " days"));
            }

            $wpdb->query($wpdb->prepare("
                INSERT INTO {$prior_temp} (post_id, url, clicks, impressions, ctr, avg_position, date_collected)
                SELECT
                    post_id,
                    MAX(url) as url,
                    SUM(clicks) as clicks,
                    SUM(impressions) as impressions,
                    CASE WHEN SUM(impressions) > 0 THEN ROUND(SUM(clicks) / SUM(impressions) * 100, 4) ELSE 0 END as ctr,
                    CASE WHEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END) > 0
                        THEN ROUND(SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN avg_position * impressions ELSE 0 END) / SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END), 1)
                        ELSE 0
                    END as avg_position,
                    CURDATE() as date_collected
                FROM {$daily_table_name_pp}
                WHERE date_collected BETWEEN %s AND %s
                AND post_id > 0
                GROUP BY post_id
            ", $prior_start_pp, $prior_end_pp));
            $prior_temp_built = true;

            // Add zero-rows for tracked posts not in prior data so counts include them
            $wpdb->query("
                INSERT IGNORE INTO {$prior_temp} (post_id, url, clicks, impressions, ctr, avg_position, date_collected)
                SELECT DISTINCT pm.post_id, '', 0, 0, 0, 0, CURDATE()
                FROM {$wpdb->prefix}seom_page_metrics pm
                WHERE pm.post_id > 0
                AND pm.post_id NOT IN (SELECT post_id FROM {$prior_temp})
            ");

            // Same filter-aware summary against prior data — uses the same
            // impression-weighted avg_position formula as the current summary
            // so the period-over-period delta on the Avg Position card is
            // computed apples-to-apples (both weighted, both matching GSC).
            // total_pages counts posts that existed as of the prior window's end.
            // Without the post_date filter, the zero-fill above would force
            // prior total = current total (every tracked post gets a zero-row),
            // making the delta always zero. With the filter, the delta reflects
            // pages published between prior_end and now.
            $summary_prior = $wpdb->get_row("
                SELECT
                    SUM(CASE WHEN p.post_date <= '{$prior_end_pp}' THEN 1 ELSE 0 END) as total_pages,
                    SUM(m.clicks) as total_clicks,
                    SUM(m.impressions) as total_impressions,
                    CASE
                        WHEN SUM(CASE WHEN m.avg_position > 0 AND m.impressions > 0 THEN m.impressions ELSE 0 END) > 0
                        THEN ROUND(
                            SUM(CASE WHEN m.avg_position > 0 AND m.impressions > 0 THEN m.avg_position * m.impressions ELSE 0 END)
                            / SUM(CASE WHEN m.avg_position > 0 AND m.impressions > 0 THEN m.impressions ELSE 0 END),
                            1
                        )
                        ELSE 0
                    END as avg_position,
                    SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
                    SUM(CASE WHEN m.impressions <= {$ghost_threshold} AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
                    SUM(CASE WHEN m.impressions <= {$ghost_threshold} AND p.post_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as new_pages
                FROM {$prior_temp} m
                JOIN {$wpdb->posts} p ON m.post_id = p.ID
                WHERE {$type_sql} AND p.post_status = 'publish'
                $filter_sql
            ");
        }
    }

    // Check which posts are already in queue
    $queued_ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->prefix}seom_refresh_queue WHERE status IN ('pending', 'processing')");
    $queued_map = array_flip($queued_ids);

    // ── Per-row prior period metrics ──
    // Two sources, picked based on the active range:
    //
    //   1. Non-default ranges (7/14/60/90/180/custom): we already built a
    //      $prior_temp table earlier that aggregates the immediately-preceding
    //      window of the same length from seom_daily_page_metrics. Use it so
    //      every row's Δ reflects "this period vs the same-length prior period".
    //
    //   2. Default 28-day view: fall back to comparing the two most recent
    //      28-day snapshots from seom_page_metrics. That's the historical
    //      behavior — Δ means "vs the previous nightly snapshot".
    //
    // The previous code only fired branch 2 (and only when !$use_live_gsc),
    // so 7-day / 14-day / etc. views had blank Δ columns even when prior
    // data existed. This now populates them whenever we have a prior_temp.
    $prior_map = [];
    if (!empty($rows)) {
        $row_ids = array_map(function($r) { return (int) $r->post_id; }, $rows);
        $id_list = implode(',', $row_ids);

        if ($prior_temp_built) {
            // Branch 1: windowed prior data from the temp table.
            // NOTE: SHOW TABLES LIKE does NOT list MySQL TEMPORARY tables, so
            // we use a flag set at build time instead of probing the schema.
            $prior_rows = $wpdb->get_results(
                "SELECT post_id, clicks, impressions, ctr, avg_position
                 FROM {$prior_temp}
                 WHERE post_id IN ({$id_list})",
                OBJECT_K
            );
            $prior_map = $prior_rows ?: [];
        } elseif (!$use_live_gsc) {
            // Branch 2: previous nightly snapshot
            $recent_dates = $wpdb->get_col("SELECT DISTINCT date_collected FROM {$wpdb->prefix}seom_page_metrics ORDER BY date_collected DESC LIMIT 2");
            if (count($recent_dates) >= 2) {
                $prev_date = $recent_dates[1];
                $prior_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT post_id, clicks, impressions, ctr, avg_position
                     FROM {$wpdb->prefix}seom_page_metrics
                     WHERE date_collected = %s AND post_id IN ({$id_list})",
                    $prev_date
                ), OBJECT_K);
                $prior_map = $prior_rows ?: [];
            }
        }
    }

    // Get last refresh info for each post (most recent history entry — single source of truth)
    $refresh_map = [];
    if (!empty($rows)) {
        $row_ids = array_map(function($r) { return (int) $r->post_id; }, $rows);
        $id_list = implode(',', $row_ids);
        $refresh_rows = $wpdb->get_results(
            "SELECT h.post_id, h.category, h.refresh_type, h.refresh_date
             FROM {$wpdb->prefix}seom_refresh_history h
             INNER JOIN (
                 SELECT post_id, MAX(id) as max_id
                 FROM {$wpdb->prefix}seom_refresh_history
                 WHERE post_id IN ({$id_list})
                 GROUP BY post_id
             ) latest ON h.id = latest.max_id"
        );
        foreach ($refresh_rows as $rr) {
            $refresh_map[$rr->post_id] = [
                'category'     => $rr->category,
                'refresh_type' => $rr->refresh_type,
                'refresh_date' => $rr->refresh_date,
            ];
        }
    }

    $fourteen_days_ago = date('Y-m-d', strtotime('-14 days'));

    // Enrich each row with prior metrics and refresh history
    foreach ($rows as &$r) {
        $r->in_queue = isset($queued_map[$r->post_id]);

        // Prior period data
        $prior = $prior_map[$r->post_id] ?? null;
        $r->clicks_prior = $prior ? (int) $prior->clicks : null;
        $r->impressions_prior = $prior ? (int) $prior->impressions : null;
        $r->position_prior = $prior ? (float) $prior->avg_position : null;

        // Last refresh — use history table as single source of truth
        $hist = $refresh_map[$r->post_id] ?? null;
        if ($hist) {
            $r->was_category = $hist['category'];
            $r->last_refresh_type = $hist['refresh_type'];
            // Use history date, but also check post meta in case it's more recent
            // (e.g., admin bar refresh sets meta but uses category 'M')
            $meta_date = $r->last_refresh ?? null;
            $hist_date = $hist['refresh_date'] ?? null;
            $r->last_refresh = ($meta_date && $meta_date > $hist_date) ? $meta_date : $hist_date;
        } else {
            // No refresh history — check if it was refreshed via other means (Blog Queue, admin bar)
            $r->last_refresh_type = null;
            if (!empty($r->last_refresh)) {
                $r->was_category = 'M'; // Manual/external refresh
            } elseif ($r->post_date >= $fourteen_days_ago) {
                $r->was_category = 'NEW';
            } else {
                $r->was_category = 'NEVER';
            }
        }

        // Add research date
        $r->research_date = get_post_meta($r->post_id, '_seom_research_date', true) ?: null;

        // Featured product association (if any) — shown on row actions as a
        // "Featured: {title}" button, allowing the user to edit/clear.
        $fpid = (int) get_post_meta($r->post_id, SEOM_FEATURED_PRODUCT_META_KEY, true);
        $r->featured_product_id    = $fpid ?: null;
        $r->featured_product_title = null;
        if ($fpid) {
            $fp = get_post($fpid);
            if ($fp && $fp->post_type === 'product') {
                $r->featured_product_title = $fp->post_title;
            }
        }

        // SEO Title from RankMath (the value used as the search-result title)
        $r->seo_title = get_post_meta($r->post_id, 'rank_math_title', true) ?: '';
        // Meta description from RankMath (the snippet shown in search results)
        $r->meta_description = get_post_meta($r->post_id, 'rank_math_description', true) ?: '';
    }

    // Data freshness — surface the actual collection dates so the user knows
    // exactly what data the cards reflect, regardless of any cron lag
    $snapshot_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$wpdb->prefix}seom_page_metrics");
    $daily_table_freshness = $wpdb->prefix . 'seom_daily_page_metrics';
    $daily_max_date = ($wpdb->get_var("SHOW TABLES LIKE '{$daily_table_freshness}'") === $daily_table_freshness)
        ? $wpdb->get_var("SELECT MAX(date_collected) FROM {$daily_table_freshness}")
        : null;

    // Last automated cron run (when the daily collector last fired)
    $last_collect_run = get_option('seom_last_collect', '');

    // Available daily-data span — bounds for the custom date pickers.
    $daily_min_date = ($wpdb->get_var("SHOW TABLES LIKE '{$daily_table_freshness}'") === $daily_table_freshness)
        ? $wpdb->get_var("SELECT MIN(date_collected) FROM {$daily_table_freshness}")
        : null;

    wp_send_json_success([
        'rows'             => $rows,
        'total'            => $total,
        'page'             => $page,
        'summary'          => $summary,
        'summary_prior'    => $summary_prior,
        'date_warning'     => $date_warning ?: null,
        'date_range'       => $date_range,
        'custom_window'    => $custom_window ? ['from' => $date_from, 'to' => $date_to] : null,
        'cooldown_days'    => intval($settings['cooldown_days']),
        'data_freshness'   => [
            'snapshot_date'    => $snapshot_date,    // newest 28-day rolling snapshot
            'daily_max_date'   => $daily_max_date,   // newest single-day per-page row
            'last_collect_run' => $last_collect_run, // when the cron last ran (success or fail)
        ],
        'available' => [
            'date_from' => $daily_min_date,
            'date_to'   => $daily_max_date,
        ],
    ]);
});

// Get untracked URLs (GSC data with no matching WordPress post) + reconciliation
add_action('wp_ajax_seom_get_untracked', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_untracked_metrics';
    $table_exists = ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table);

    $empty_ut = ['clicks' => 0, 'impressions' => 0, 'url_count' => 0];

    // Get tracked totals (always available from page_metrics)
    $metrics_table = $wpdb->prefix . 'seom_page_metrics';
    $tracked_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$metrics_table}");
    $tt = $tracked_date ? $wpdb->get_row($wpdb->prepare("SELECT SUM(clicks) as clicks, SUM(impressions) as impressions, COUNT(*) as url_count FROM {$metrics_table} WHERE date_collected = %s", $tracked_date)) : null;
    $tracked_totals = [
        'clicks'      => intval($tt->clicks ?? 0),
        'impressions' => intval($tt->impressions ?? 0),
        'url_count'   => intval($tt->url_count ?? 0),
    ];

    $latest_date = $table_exists ? $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}") : null;
    if (!$latest_date) {
        wp_send_json_success([
            'rows' => [], 'total' => 0, 'type_counts' => [],
            'untracked_totals' => $empty_ut,
            'tracked_totals' => $tracked_totals,
            'gsc_totals' => seom_get_site_totals(),
            'needs_collection' => true,
        ]);
    }

    $url_type = sanitize_text_field($_POST['url_type'] ?? '');
    $sort = sanitize_text_field($_POST['sort'] ?? 'impressions');
    $order = strtoupper(sanitize_text_field($_POST['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = 50;
    $offset_val = ($page - 1) * $per_page;

    $allowed_sorts = ['url', 'clicks', 'impressions', 'ctr', 'avg_position', 'url_type'];
    if (!in_array($sort, $allowed_sorts)) $sort = 'impressions';

    $where = $wpdb->prepare("WHERE date_collected = %s", $latest_date);
    if ($url_type) $where .= $wpdb->prepare(" AND url_type = %s", $url_type);

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");
    $rows = $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY {$sort} {$order} LIMIT {$per_page} OFFSET {$offset_val}");

    // Untracked totals (all types, not filtered)
    $ut = $wpdb->get_row($wpdb->prepare("SELECT SUM(clicks) as clicks, SUM(impressions) as impressions, COUNT(*) as url_count FROM {$table} WHERE date_collected = %s", $latest_date));

    // Type breakdown
    $type_counts = $wpdb->get_results($wpdb->prepare("SELECT url_type, COUNT(*) as cnt, SUM(impressions) as impressions FROM {$table} WHERE date_collected = %s GROUP BY url_type ORDER BY impressions DESC", $latest_date));

    wp_send_json_success([
        'rows'  => $rows,
        'total' => $total,
        'page'  => $page,
        'per_page' => $per_page,
        'date'  => $latest_date,
        'untracked_totals' => [
            'clicks'      => intval($ut->clicks ?? 0),
            'impressions' => intval($ut->impressions ?? 0),
            'url_count'   => intval($ut->url_count ?? 0),
        ],
        'tracked_totals' => [
            'clicks'      => intval($tt->clicks ?? 0),
            'impressions' => intval($tt->impressions ?? 0),
            'url_count'   => intval($tt->url_count ?? 0),
        ],
        'gsc_totals' => seom_get_site_totals(),
        'type_counts' => $type_counts,
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

    // Block re-add ONLY if the item is currently pending or actively processing.
    // Completed and failed items can be re-queued at any time — manual override
    // (clicking Refresh on a cooldown'd item) needs this to work.
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}seom_refresh_queue WHERE post_id = %d AND status IN ('pending', 'processing') LIMIT 1",
        $post_id
    ));
    if ($existing) wp_send_json_error('Already in the active queue.');

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

// Manual competitive research collection for a single post
add_action('wp_ajax_seom_collect_research', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Missing post ID.');

    // For manual research, check if a provider is configured (don't require the auto-enable toggle)
    $r_settings = seom_get_settings();
    $r_provider = $r_settings['research_provider'] ?? 'openai';
    $has_key = false;
    if ($r_provider === 'perplexity') {
        $has_key = !empty($r_settings['perplexity_api_key']);
    } else {
        $has_key = !empty(function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key'));
    }
    if (!$has_key) {
        wp_send_json_error('Research API key not configured. Set up a provider in Settings > Competitive Research.');
    }

    global $wpdb;
    $before = $wpdb->get_row($wpdb->prepare(
        "SELECT clicks, impressions, ctr, avg_position, top_queries FROM {$wpdb->prefix}seom_page_metrics
         WHERE post_id = %d ORDER BY date_collected DESC LIMIT 1",
        $post_id
    ));

    $top_queries_data = [];
    if (!empty($before->top_queries)) {
        $tq = json_decode($before->top_queries, true);
        if (is_array($tq)) $top_queries_data = $tq;
    }

    $seo_ctx = [
        'clicks'       => intval($before->clicks ?? 0),
        'impressions'  => intval($before->impressions ?? 0),
        'ctr'          => floatval($before->ctr ?? 0),
        'position'     => floatval($before->avg_position ?? 0),
        'category'     => 'M',
        'category_desc'=> 'Manual research collection',
        'top_queries'  => $top_queries_data,
    ];

    $research = SEOM_Researcher::research($post_id, $seo_ctx);
    if (is_wp_error($research)) {
        wp_send_json_error('Research failed: ' . $research->get_error_message());
    }

    update_post_meta($post_id, '_seom_research', $research);
    update_post_meta($post_id, '_seom_research_date', current_time('mysql'));

    wp_send_json_success([
        'research' => $research,
        'date'     => current_time('mysql'),
        'post_id'  => $post_id,
    ]);
});

// View stored research for a post
add_action('wp_ajax_seom_get_research', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Missing post ID.');

    $research = get_post_meta($post_id, '_seom_research', true);
    $date = get_post_meta($post_id, '_seom_research_date', true);
    $title = get_the_title($post_id);

    if (empty($research)) {
        wp_send_json_error('No research collected for this page yet.');
    }

    wp_send_json_success([
        'post_id'  => $post_id,
        'title'    => $title,
        'research' => $research,
        'date'     => $date,
    ]);
});

// Reset stuck processing state
// One-shot backfill: queue posts that have been refreshed at least once but
// still have NO custom rank_math_title meta. Used to recover from the historical
// step_seo_title bug that silently dropped titles >70 chars.
//
// Important: this ONLY targets posts that have been refreshed before. Never-refreshed
// posts are expected to lack a custom SEO title — they'll get one on their first
// scheduled refresh and don't need to be backfilled.
add_action('wp_ajax_seom_backfill_seo_titles', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $dry_run = !empty($_POST['dry_run']);

    // Find posts that:
    //  1) have been refreshed at least once (entry in seom_refresh_history OR last_page_refresh meta), AND
    //  2) have an empty rank_math_title meta (the bug victims).
    $missing = $wpdb->get_results("
        SELECT DISTINCT p.ID as post_id, p.post_title, p.post_type
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} rm ON p.ID = rm.post_id AND rm.meta_key = 'rank_math_title'
        LEFT JOIN {$wpdb->postmeta} lr ON p.ID = lr.post_id AND lr.meta_key = 'last_page_refresh'
        LEFT JOIN {$wpdb->prefix}seom_refresh_history h ON p.ID = h.post_id
        WHERE p.post_status = 'publish'
        AND p.post_type IN ('post','product','page')
        AND (rm.meta_value IS NULL OR rm.meta_value = '')
        AND (
            (lr.meta_value IS NOT NULL AND lr.meta_value != '')
            OR h.id IS NOT NULL
        )
    ");

    $count = count($missing);

    if ($dry_run) {
        wp_send_json_success([
            'dry_run' => true,
            'count'   => $count,
            'sample'  => array_slice($missing, 0, 10),
        ]);
    }

    // Queue them for meta-only refresh, skipping any already in the queue
    $queued_ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->prefix}seom_refresh_queue WHERE status IN ('pending','processing')");
    $queued_map = array_flip(array_map('intval', $queued_ids));

    $now = current_time('mysql');
    $added = 0;
    $skipped = 0;
    foreach ($missing as $m) {
        $pid = (int) $m->post_id;
        if (isset($queued_map[$pid])) { $skipped++; continue; }
        $wpdb->insert("{$wpdb->prefix}seom_refresh_queue", [
            'post_id'        => $pid,
            'post_type'      => $m->post_type,
            'priority_score' => 95, // high but not max — leave room for true emergencies
            'category'       => 'B', // CTR Fix bucket since we're fixing meta
            'refresh_type'   => 'meta_only',
            'status'         => 'pending',
            'queued_at'      => $now,
        ]);
        $added++;
    }

    wp_send_json_success([
        'count'         => $count,
        'queued'        => $added,
        'already_in_queue' => $skipped,
        'message'       => "Queued {$added} post(s) with missing SEO titles for a meta-only refresh. " .
                           ($skipped > 0 ? "{$skipped} already in queue." : '') .
                           " Click \"Process Queue (Background)\" to run them.",
    ]);
});

// ─── Backfill Sweep — queue ALL eligible posts for full refresh ─────────────
//
// One-shot tool that picks every post matching a chosen filter and inserts
// it into seom_refresh_queue with refresh_type='full'. Used to catch up
// historical posts on the writer rules added in phases 1a/1b/2/3a: TL;DR
// + At-a-Glance, type-specific blocks, cluster pillar links, glossary
// auto-linking. The existing queue processor (manual or daily cron)
// handles the actual refresh under the configured daily_limit.
//
// Filter modes:
//   missing_tldr — posts whose post_content has no .itu-tldr block. Highest
//                  signal that a post predates phase 1a; default.
//   never        — posts that have never had last_page_refresh stamped.
//   30d / 90d    — temporal — posts not refreshed in N days.
//   all          — every published post (use with care).
//
// Dry-run reports count + sample so the admin can size the batch before
// committing. Apply enqueues at priority 50 (medium — won't bump urgent
// fixes) and category 'M' (manual sweep).
add_action('wp_ajax_seom_backfill_all_posts', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(180);

    global $wpdb;
    $dry_run = !empty($_POST['dry_run']);
    $filter  = sanitize_text_field($_POST['filter'] ?? 'missing_tldr');

    // Build candidate query. We join last_page_refresh meta because the temporal
    // filters need it; the LIKE filter just adds an AND on post_content.
    $joins   = "LEFT JOIN {$wpdb->postmeta} lr ON p.ID = lr.post_id AND lr.meta_key = 'last_page_refresh'";
    $where   = "p.post_status = 'publish' AND p.post_type = 'post'";

    switch ($filter) {
        case 'missing_tldr':
            $where .= " AND p.post_content NOT LIKE '%itu-tldr%'";
            break;
        case 'never':
            $where .= " AND (lr.meta_value IS NULL OR lr.meta_value = '')";
            break;
        case '30d':
            $where .= " AND (lr.meta_value IS NULL OR lr.meta_value = '' OR STR_TO_DATE(lr.meta_value, '%Y-%m-%d %H:%i:%s') < DATE_SUB(NOW(), INTERVAL 30 DAY))";
            break;
        case '90d':
            $where .= " AND (lr.meta_value IS NULL OR lr.meta_value = '' OR STR_TO_DATE(lr.meta_value, '%Y-%m-%d %H:%i:%s') < DATE_SUB(NOW(), INTERVAL 90 DAY))";
            break;
        case 'all':
            // no extra clause
            break;
        default:
            wp_send_json_error('Unknown filter.');
    }

    $sql = "SELECT p.ID, p.post_title FROM {$wpdb->posts} p {$joins} WHERE {$where} ORDER BY p.ID ASC";
    $candidates = $wpdb->get_results($sql);
    $count = count($candidates);

    if ($dry_run) {
        wp_send_json_success([
            'dry_run' => true,
            'filter'  => $filter,
            'count'   => $count,
            'sample'  => array_slice($candidates, 0, 10),
        ]);
    }

    // Skip anything already queued — same de-dup pattern the SEO title
    // backfill uses, so a re-run of the sweep is safe.
    $queue_table = $wpdb->prefix . 'seom_refresh_queue';
    $queued_ids  = $wpdb->get_col("SELECT post_id FROM {$queue_table} WHERE status IN ('pending','processing')");
    $queued_map  = array_flip(array_map('intval', $queued_ids));

    $now = current_time('mysql');
    $added = 0;
    $skipped = 0;
    foreach ($candidates as $c) {
        $pid = (int) $c->ID;
        if (isset($queued_map[$pid])) { $skipped++; continue; }
        $wpdb->insert($queue_table, [
            'post_id'        => $pid,
            'post_type'      => 'post',
            'priority_score' => 50,
            'category'       => 'M',
            'refresh_type'   => 'full',
            'status'         => 'pending',
            'queued_at'      => $now,
        ]);
        $added++;
    }

    $settings    = seom_get_settings();
    $daily_limit = max(1, (int) ($settings['daily_limit'] ?? 20));
    $est_days    = (int) ceil($added / $daily_limit);

    wp_send_json_success([
        'filter'           => $filter,
        'count'            => $count,
        'queued'           => $added,
        'already_in_queue' => $skipped,
        'daily_limit'      => $daily_limit,
        'estimated_days'   => $est_days,
        'message'          => "Queued {$added} post(s) for full refresh under current writer rules. "
                              . ($skipped > 0 ? "{$skipped} already in queue. " : '')
                              . "At daily_limit {$daily_limit}/day this will take ~{$est_days} day(s). "
                              . "Use Process Queue (Background) on the Queue tab to start now, or let the daily cron pick them up.",
    ]);
});

// ─── SEO Title Number Audit (one-time cleanup tool) ─────────────────────────
//
// Finds blog posts whose rank_math_title starts with a number and re-runs
// ONLY the SEO title step against the new number-accuracy validator. Does
// NOT update last_page_refresh, the refresh history table, focus keyword,
// or meta description. Pure title rewrite.
//
// Used to fix the inventory of titles like "5 Ways to Improve Your Security
// Posture" that were generated before the body-count validation existed.
//
// Two modes:
//   dry_run=1     → returns the list of candidates with current titles
//   batch processing → POST processes the next N candidates with offset
add_action('wp_ajax_seom_seo_title_audit', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $dry_run = !empty($_POST['dry_run']);
    $apply   = !empty($_POST['apply']);
    $offset  = max(0, (int) ($_POST['offset'] ?? 0));
    $batch   = max(1, min(10, (int) ($_POST['batch'] ?? 5)));

    // Find posts where rank_math_title contains a standalone integer
    // between 1 and 12 anywhere in the title — leading or embedded.
    // Catches "5 Ways to…", "Cloud Deployment Models: 4 Models", "ITAM
    // in 7 Steps", etc. The 12-cap eliminates years, exam codes (701,
    // 800, 900), versions, and ISO/SOC standards in one shot — list
    // counts in titles are almost always single-digit or low double-digit.
    //
    // [[:<:]] / [[:>:]] are MySQL word-boundary markers so we don't
    // match digits inside larger tokens like "SY0-701" or "ISO27001".
    $sql = "
        SELECT p.ID as post_id, p.post_title, rm.meta_value as current_seo_title
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} rm ON p.ID = rm.post_id AND rm.meta_key = 'rank_math_title'
        WHERE p.post_status = 'publish'
        AND p.post_type = 'post'
        AND rm.meta_value REGEXP '[[:<:]]([1-9]|1[0-2])[[:>:]]'
        ORDER BY p.ID ASC
    ";
    $rows = $wpdb->get_results($sql);
    $total = count($rows);

    // Dry-run path — return the list (or a sample of it) and exit.
    if ($dry_run) {
        wp_send_json_success([
            'dry_run' => true,
            'total'   => $total,
            'sample'  => array_slice($rows, 0, 25),
        ]);
    }

    // Apply path — process the requested batch slice. Each call returns
    // progress so the JS can poll forward until complete.
    if (!$apply) wp_send_json_error('Missing apply flag.');

    if (!class_exists('SEOM_Blog_Refresher') || !SEOM_Blog_Refresher::is_available()) {
        wp_send_json_error('SEOM_Blog_Refresher not available.');
    }

    // Don't let a long batch hold up the request — set time limit and
    // close the session so concurrent admin requests aren't blocked.
    @set_time_limit(180);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $slice = array_slice($rows, $offset, $batch);
    $results = ['updated' => [], 'unchanged' => [], 'failed' => []];

    foreach ($slice as $row) {
        $post_id = (int) $row->post_id;
        $before  = (string) $row->current_seo_title;

        // Call ONLY step_seo_title — no other side effects. The new
        // number-accuracy guard inside that method will reject inflated
        // numbers and either retry or strip the leading number.
        $result = SEOM_Blog_Refresher::step_seo_title($post_id);
        if (is_wp_error($result)) {
            $results['failed'][] = [
                'post_id' => $post_id,
                'title'   => $row->post_title,
                'before'  => $before,
                'error'   => $result->get_error_message(),
            ];
            continue;
        }

        $after = (string) $result;
        if ($after === $before) {
            $results['unchanged'][] = [
                'post_id' => $post_id,
                'title'   => $row->post_title,
                'seo'     => $after,
            ];
        } else {
            $results['updated'][] = [
                'post_id' => $post_id,
                'title'   => $row->post_title,
                'before'  => $before,
                'after'   => $after,
            ];
        }
    }

    $next_offset = $offset + $batch;
    $done = $next_offset >= $total;

    wp_send_json_success([
        'total'       => $total,
        'processed'   => min($next_offset, $total),
        'next_offset' => $done ? null : $next_offset,
        'done'        => $done,
        'results'     => $results,
    ]);
});

add_action('wp_ajax_seom_reset_queue_state', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;

    // Set the stop flag FIRST so any in-flight worker exits cleanly on its next
    // should_stop() check, then tear down all the state. Direct DB writes bypass
    // the per-process WordPress object cache.
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
         ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
        'seom_queue_stop', '1'
    ));

    // Direct DELETEs from the options table — bypasses the object cache so the
    // worker's should_stop() (which queries the DB directly) sees the changes.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s, %s)",
        'seom_queue_processing',
        'seom_bg_progress',
        '_transient_seom_queue_lock',
        '_transient_timeout_seom_queue_lock'
    ));
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
        '_transient_seom_queue_session',
        '_transient_timeout_seom_queue_session'
    ));

    // Reset stuck processing/skipped items
    $wpdb->query("UPDATE {$wpdb->prefix}seom_refresh_queue SET status = 'pending', started_at = NULL, error_message = NULL WHERE status IN ('processing', 'skipped')");

    // Clear the local cache so this same request also sees the cleared state
    wp_cache_delete('seom_queue_processing', 'options');
    wp_cache_delete('seom_bg_progress', 'options');
    wp_cache_delete('seom_queue_lock', 'transient');
    wp_cache_delete('seom_queue_session', 'transient');
    wp_cache_delete('alloptions', 'options');

    // Now schedule a delayed clear of the stop flag itself so future runs work.
    // Worker has 30 seconds to see the stop signal and exit.
    wp_schedule_single_event(time() + 30, 'seom_clear_stop_flag');

    wp_send_json_success('Queue state reset. Stuck items moved back to pending.');
});

// Cron callback that clears the stop flag a few seconds after a Reset State,
// so the next Process Queue click can start a fresh run cleanly.
add_action('seom_clear_stop_flag', function () {
    delete_option('seom_queue_stop');
});

add_action('wp_ajax_seom_get_queue', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $queue_table = $wpdb->prefix . 'seom_refresh_queue';

    // Purge stale pending entries — pages refreshed after being queued (via Blog Queue, manual, etc.)
    $wpdb->query("
        DELETE q FROM {$queue_table} q
        INNER JOIN {$wpdb->prefix}seom_refresh_history h ON q.post_id = h.post_id
        WHERE q.status = 'pending'
        AND h.refresh_date > q.queued_at
    ");
    $stale_pending = $wpdb->get_results("SELECT id, post_id, queued_at FROM {$queue_table} WHERE status = 'pending'");
    foreach ($stale_pending as $sp) {
        $meta_refresh = get_post_meta($sp->post_id, 'last_page_refresh', true);
        if ($meta_refresh && $meta_refresh > $sp->queued_at) {
            $wpdb->delete($queue_table, ['id' => $sp->id]);
        }
    }

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

    // Get last refresh dates from SEO AI AutoPilot history (not post meta which includes Blog Queue creation dates)
    $queue_post_ids = wp_list_pluck($rows, 'post_id');
    $refresh_dates = [];
    if (!empty($queue_post_ids)) {
        $id_list = implode(',', array_map('intval', $queue_post_ids));
        $refresh_rows = $wpdb->get_results(
            "SELECT post_id, MAX(refresh_date) as last_refresh
             FROM {$wpdb->prefix}seom_refresh_history
             WHERE post_id IN ({$id_list})
             GROUP BY post_id"
        );
        foreach ($refresh_rows as $rr) {
            $refresh_dates[$rr->post_id] = $rr->last_refresh;
        }
    }

    // Bulk load URLs from stored metrics to avoid get_permalink per row
    $stored_urls = [];
    if (!empty($queue_post_ids)) {
        $id_list = implode(',', array_map('intval', $queue_post_ids));
        $url_rows = $wpdb->get_results("SELECT post_id, url FROM {$wpdb->prefix}seom_page_metrics WHERE post_id IN ({$id_list}) AND url IS NOT NULL AND url != '' GROUP BY post_id, url");
        foreach ($url_rows as $ur) $stored_urls[$ur->post_id] = $ur->url;
    }

    foreach ($rows as &$row) {
        $row->url = $stored_urls[$row->post_id] ?? get_permalink($row->post_id);
        $row->last_refresh = $refresh_dates[$row->post_id] ?? null;
        $row->has_description = !empty(trim(strip_tags(get_post_field('post_content', $row->post_id))));
        $row->has_excerpt = !empty(trim(get_post_field('post_excerpt', $row->post_id)));
    }

    wp_send_json_success($rows);
});

// Manually collect single-day GSC data for a specific date
add_action('wp_ajax_seom_collect_daily', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $date = sanitize_text_field($_POST['date'] ?? '');
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) wp_send_json_error('Invalid date format. Use YYYY-MM-DD.');

    // Don't allow dates in the future or less than 2 days ago (GSC won't have data)
    if ($date > date('Y-m-d', strtotime('-2 days'))) wp_send_json_error('GSC data is typically delayed 2-3 days. Choose an earlier date.');

    $result = SEOM_Collector::collect_daily($date);
    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

    // Also collect per-page data for this date
    $pages_saved = SEOM_Collector::collect_daily_pages($date);

    wp_send_json_success([
        'date'        => $date,
        'clicks'      => $result['clicks'],
        'impressions' => $result['impressions'],
        'ctr'         => $result['ctr'],
        'position'    => $result['position'],
        'pages_saved' => is_wp_error($pages_saved) ? 0 : $pages_saved,
    ]);
});

// GSC metrics history — daily/weekly/monthly/quarterly/annual aggregation
add_action('wp_ajax_seom_get_gsc_history', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_page_metrics';
    $period = sanitize_text_field($_POST['period'] ?? 'daily');
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = 31;

    // Build date grouping based on period
    switch ($period) {
        case 'weekly':
            $date_expr = "DATE_FORMAT(date_collected, '%x-W%v')"; // ISO year-week
            $date_label = "CONCAT(MIN(date_collected), ' to ', MAX(date_collected))";
            break;
        case 'monthly':
            $date_expr = "DATE_FORMAT(date_collected, '%Y-%m')";
            $date_label = "DATE_FORMAT(date_collected, '%Y-%m')";
            break;
        case 'quarterly':
            $date_expr = "CONCAT(YEAR(date_collected), '-Q', QUARTER(date_collected))";
            $date_label = "CONCAT(YEAR(date_collected), '-Q', QUARTER(date_collected))";
            break;
        case 'annual':
            $date_expr = "YEAR(date_collected)";
            $date_label = "YEAR(date_collected)";
            break;
        default: // daily
            $date_expr = "date_collected";
            $date_label = "date_collected";
    }

    $offset = ($page - 1) * $per_page;
    $summary_table = $wpdb->prefix . 'seom_daily_summary';
    $has_summary = ($wpdb->get_var("SHOW TABLES LIKE '{$summary_table}'") === $summary_table);

    // Use seom_daily_page_metrics for ALL period views (true single-day per-page data).
    // seom_page_metrics contains 28-day rolling totals per page — summing those across
    // a week/month would massively inflate the numbers (each row already holds 28 days).
    $daily_table = $wpdb->prefix . 'seom_daily_page_metrics';
    $has_daily = ($wpdb->get_var("SHOW TABLES LIKE '{$daily_table}'") === $daily_table);
    if ($has_daily) {
        $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT {$date_expr}) FROM {$daily_table}");
        if ($period === 'daily') {
            $select_label = "CONCAT(UPPER(LEFT(DAYNAME(date_collected), 3)), ' - ', date_collected)";
        } else {
            $select_label = $date_label;
        }
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT
                {$select_label} as period_label,
                {$date_expr} as period_key,
                COUNT(DISTINCT url) as total_pages,
                SUM(impressions) as impressions,
                SUM(clicks) as clicks,
                CASE WHEN SUM(impressions) > 0 THEN ROUND(SUM(clicks) / SUM(impressions) * 100, 4) ELSE 0 END as ctr,
                CASE WHEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END) > 0
                    THEN ROUND(SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN avg_position * impressions ELSE 0 END) / SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END), 1)
                    ELSE ROUND(AVG(CASE WHEN avg_position > 0 THEN avg_position END), 1)
                END as avg_position,
                '{$period}' as data_source
            FROM {$daily_table}
            GROUP BY period_key
            ORDER BY period_key DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset));
    } else {
        // Fallback: no daily table available — use page_metrics but only for daily view
        $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT {$date_expr}) FROM {$table}");
        $rows = $wpdb->get_results("
            SELECT
                {$date_label} as period_label,
                {$date_expr} as period_key,
                COUNT(DISTINCT post_id) as total_pages,
                SUM(impressions) as impressions,
                SUM(clicks) as clicks,
                CASE WHEN SUM(impressions) > 0 THEN ROUND(SUM(clicks) / SUM(impressions) * 100, 4) ELSE 0 END as ctr,
                CASE WHEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END) > 0
                    THEN ROUND(SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN avg_position * impressions ELSE 0 END) / SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END), 1)
                    ELSE ROUND(AVG(CASE WHEN avg_position > 0 THEN avg_position END), 1)
                END as avg_position,
                'tracked' as data_source
            FROM {$table}
            GROUP BY period_key
            ORDER BY period_key DESC
            LIMIT {$per_page} OFFSET {$offset}
        ");
    }

    wp_send_json_success([
        'rows'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'period'   => $period,
    ]);
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
    $today_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history WHERE DATE(refresh_date) = CURDATE()");

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
            ORDER BY (m.clicks - h.clicks_before) DESC, h.refresh_date DESC
            LIMIT 30
        ", $latest_date));
    }

    $settings = seom_get_settings();
    $last_collect = get_option('seom_last_collect', 'Never');
    $last_analyze = get_option('seom_last_analyze', 'Never');

    // Active goals for overview cards
    $active_goals = [];
    $gt = $wpdb->prefix . 'seom_goals';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$gt}'") === $gt) {
        $active_goals = $wpdb->get_results("SELECT * FROM {$gt} WHERE status = 'active' ORDER BY priority ASC, deadline ASC");
    }

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
        'active_goals'    => $active_goals,
    ]);
});

// Paginated tracker data — separate from overview for performance
add_action('wp_ajax_seom_get_tracker', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = min(9999, max(1, intval($_POST['per_page'] ?? 25)));
    $offset = ($page - 1) * $per_page;
    $days = intval($_POST['days'] ?? 14);
    $post_type = sanitize_text_field($_POST['post_type'] ?? 'all');
    $trend     = sanitize_text_field($_POST['trend'] ?? 'all');
    $date_from = sanitize_text_field($_POST['date_from'] ?? '');
    $date_to   = sanitize_text_field($_POST['date_to'] ?? '');

    $type_sql = '';
    if ($post_type !== 'all') {
        $type_sql = $wpdb->prepare(" AND p.post_type = %s", $post_type);
    }

    // Build the date-range clause: custom dates take precedence over the
    // predefined N-day window. Both branches produce a fully-prepared SQL
    // fragment so the outer queries can drop the %d placeholder for days.
    $use_custom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)
               && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to);
    if ($use_custom && $date_from > $date_to) {
        $tmp = $date_from; $date_from = $date_to; $date_to = $tmp;
    }
    // Date math MUST match seom_get_refresh_trends exactly: CURDATE() (not NOW())
    // so the boundary day is included in full, and dry runs are excluded the
    // same way. Otherwise the cards and chart bar can disagree on the row count.
    $range_sql = $use_custom
        ? $wpdb->prepare("h.refresh_date BETWEEN %s AND %s", $date_from . ' 00:00:00', $date_to . ' 23:59:59')
        : $wpdb->prepare("h.refresh_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)", $days);
    // Same fragments aliased to h2/p2 for the dedupe subquery.
    $range_sql_h2 = $use_custom
        ? $wpdb->prepare("h2.refresh_date BETWEEN %s AND %s", $date_from . ' 00:00:00', $date_to . ' 23:59:59')
        : $wpdb->prepare("h2.refresh_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)", $days);
    $type_sql_h2 = '';
    if ($post_type !== 'all') {
        $type_sql_h2 = $wpdb->prepare(" AND p2.post_type = %s", $post_type);
    }

    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$wpdb->prefix}seom_page_metrics");

    // Available data span — MIN/MAX refresh_date for the current type filter,
    // returned to the client so it can clamp the custom date inputs and show
    // the user how far back custom queries can go.
    $available = $wpdb->get_row("
        SELECT DATE(MIN(h.refresh_date)) as date_from,
               DATE(MAX(h.refresh_date)) as date_to,
               COUNT(DISTINCT h.post_id) as total
        FROM {$wpdb->prefix}seom_refresh_history h
        JOIN {$wpdb->posts} p ON h.post_id = p.ID
        WHERE h.refresh_type NOT LIKE '%dry run%'
        $type_sql
    ");

    // ── Load one row per distinct post (latest refresh in the window) ──
    // The chart and the table both dedupe to "one entry per page" — the cards
    // must do the same. We pick each post's most recent refresh inside the
    // window via INNER JOIN against MAX(id), so a page refreshed five times
    // in the period contributes a single row. Single source of truth.
    $rows_all = [];
    if ($latest_date) {
        $rows_all = $wpdb->get_results($wpdb->prepare("
            SELECT h.post_id, h.refresh_date, h.refresh_type, h.category,
                h.clicks_before, h.impressions_before, h.position_before, h.ctr_before,
                COALESCE(m.clicks,0) as clicks_now, COALESCE(m.impressions,0) as impressions_now,
                COALESCE(m.avg_position,0) as position_now, COALESCE(m.ctr,0) as ctr_now,
                p.post_title, p.post_type,
                (COALESCE(m.clicks,0) - h.clicks_before) as click_change,
                (COALESCE(m.avg_position,0) - h.position_before) as position_change,
                DATEDIFF(NOW(), h.refresh_date) as days_since
            FROM {$wpdb->prefix}seom_refresh_history h
            INNER JOIN (
                SELECT h2.post_id, MAX(h2.id) as max_id
                FROM {$wpdb->prefix}seom_refresh_history h2
                JOIN {$wpdb->posts} p2 ON h2.post_id = p2.ID
                WHERE {$range_sql_h2}
                AND h2.refresh_type NOT LIKE '%%dry run%%'
                $type_sql_h2
                GROUP BY h2.post_id
            ) latest ON h.id = latest.max_id
            JOIN {$wpdb->posts} p ON h.post_id = p.ID
            LEFT JOIN {$wpdb->prefix}seom_page_metrics m
                ON h.post_id = m.post_id AND m.date_collected = %s
            ORDER BY h.refresh_date DESC
        ", $latest_date));
    }

    // ── Window-based per-page metrics ──
    // Classification has to use the SAME window comparison the cards and the
    // chart use, otherwise a page can be classified "clicks_down" because its
    // refresh-time snapshot was higher than the current 28d snapshot, even
    // though it gained clicks in the recent window vs the prior window. The
    // fix: pull each post's clicks/impressions/weighted-position aggregated
    // over the current window AND the prior window from seom_daily_page_metrics
    // (the same source the chart aggregates), then classify each page on
    // those numbers.
    $daily_pg_table = $wpdb->prefix . 'seom_daily_page_metrics';
    $has_daily_pg   = ($wpdb->get_var("SHOW TABLES LIKE '{$daily_pg_table}'") === $daily_pg_table);

    // Compute the window dates here (before classification) so the per-page
    // SQL below can use them. These are the same dates used later for the
    // card totals — single source of truth.
    if ($use_custom) {
        $cw_start = $date_from;
        $cw_end   = $date_to;
        $cw_days  = max(1, (int) ((strtotime($cw_end) - strtotime($cw_start)) / 86400) + 1);
    } else {
        $newest_dp = $has_daily_pg ? $wpdb->get_var("SELECT MAX(date_collected) FROM {$daily_pg_table}") : null;
        if (!$newest_dp) $newest_dp = date('Y-m-d');
        $cw_end   = $newest_dp;
        $cw_start = date('Y-m-d', strtotime("{$cw_end} -" . ($days - 1) . " days"));
        $cw_days  = $days;
    }
    $pw_end   = date('Y-m-d', strtotime("{$cw_start} -1 day"));
    $pw_start = date('Y-m-d', strtotime("{$pw_end} -" . ($cw_days - 1) . " days"));

    // Per-post window aggregates: one query each for current and prior windows.
    $cur_by_post = [];
    $prv_by_post = [];
    if ($has_daily_pg && !empty($rows_all)) {
        $all_post_ids = array_map(function($r) { return (int) $r->post_id; }, $rows_all);
        $all_post_ids = array_values(array_unique($all_post_ids));
        if (!empty($all_post_ids)) {
            $aid_list = implode(',', $all_post_ids);
            $agg_sql = "
                SELECT post_id,
                    SUM(clicks) AS clicks,
                    SUM(impressions) AS impressions,
                    CASE WHEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END) > 0
                        THEN SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN avg_position * impressions ELSE 0 END)
                             / SUM(CASE WHEN avg_position > 0 AND impressions > 0 THEN impressions ELSE 0 END)
                        ELSE 0 END AS avg_position
                FROM {$daily_pg_table}
                WHERE post_id IN ({$aid_list})
                AND date_collected BETWEEN %s AND %s
                GROUP BY post_id
            ";
            $cur_rows = $wpdb->get_results($wpdb->prepare($agg_sql, $cw_start, $cw_end));
            foreach ($cur_rows as $cr) $cur_by_post[(int) $cr->post_id] = $cr;
            $prv_rows = $wpdb->get_results($wpdb->prepare($agg_sql, $pw_start, $pw_end));
            foreach ($prv_rows as $pr) $prv_by_post[(int) $pr->post_id] = $pr;
        }
    }

    // Overlay window metrics onto each row, then classify.
    foreach ($rows_all as $r) {
        $pid = (int) $r->post_id;
        $cur = $cur_by_post[$pid] ?? null;
        $prv = $prv_by_post[$pid] ?? null;

        // Window numbers replace the legacy refresh-time/snapshot fields.
        // The table columns and row Δ now reflect "current window vs prior
        // window" so the badge and bucket are derived from the same numbers.
        $r->clicks_before      = $prv ? (int)   $prv->clicks      : 0;
        $r->clicks_now         = $cur ? (int)   $cur->clicks      : 0;
        $r->impressions_before = $prv ? (int)   $prv->impressions : 0;
        $r->impressions_now    = $cur ? (int)   $cur->impressions : 0;
        $r->position_before    = $prv ? (float) $prv->avg_position : 0;
        $r->position_now       = $cur ? (float) $cur->avg_position : 0;
        $r->click_change       = $r->clicks_now    - $r->clicks_before;
        $r->position_change    = $r->position_now  - $r->position_before;

        $r->trend_key = seom_classify_refresh_trend(
            $r->clicks_before,
            $r->clicks_now,
            $r->position_before,
            $r->position_now,
            $r->days_since,
            $r->impressions_before,
            $r->impressions_now
        );
    }

    // Apply the trend filter — every button maps to exactly one trend key.
    // No grouping: Declining means clicks down AND ranking worsened (the
    // hard-loss bucket). Clicks Down and Ranking Down have their own buttons
    // for the single-axis losses, so a page can't appear under more than one
    // negative bucket and the cards/table/chart can never disagree.
    $rows_filtered = [];
    if ($trend === 'all') {
        $rows_filtered = $rows_all;
    } else {
        foreach ($rows_all as $r) {
            if ($r->trend_key === $trend) $rows_filtered[] = $r;
        }
    }

    // Server-side pagination over the filtered set
    $total = count($rows_filtered);
    $rows  = array_slice($rows_filtered, $offset, $per_page);

    // Summary counts — derived from the filtered set so cards always match the
    // table. improving / declining / flat group the eight trend keys into the
    // three high-level buckets shown on the cards.
    $summary = [
        'improving' => 0, 'declining' => 0, 'flat' => 0,
        'total' => $total,
        'total_clicks_before' => 0, 'total_clicks_now' => 0,
        'total_impressions_before' => 0, 'total_impressions_now' => 0,
    ];
    // Bucket counts. When a single-key filter is active these will all match
    // $total (every row in the filtered set is in the same bucket); when
    // trend=all they show the breakdown across the whole window.
    foreach ($rows_filtered as $r) {
        if (in_array($r->trend_key, ['strong', 'clicks_up', 'impressions_up', 'ranking_up'], true)) $summary['improving']++;
        elseif (in_array($r->trend_key, ['declining', 'clicks_down', 'impressions_down', 'ranking_down'], true)) $summary['declining']++;
        else $summary['flat']++;
    }

    // ── Window-based card totals ──
    // Sum across the FILTERED rows. Each row's clicks/impressions are already
    // window-aggregated by the per-page block above, so the cards literally
    // reflect "for these pages, recent window vs prior window" — guaranteed
    // to be in the correct direction for whatever bucket is active.
    if ($has_daily_pg && !empty($rows_filtered)) {
        $tcb = 0; $tcn = 0; $tib = 0; $tin = 0;
        foreach ($rows_filtered as $r) {
            $tcb += (int) $r->clicks_before;
            $tcn += (int) $r->clicks_now;
            $tib += (int) $r->impressions_before;
            $tin += (int) $r->impressions_now;
        }
        $summary['total_clicks_now']         = $tcn;
        $summary['total_clicks_before']      = $tcb;
        $summary['total_impressions_now']    = $tin;
        $summary['total_impressions_before'] = $tib;
        $summary['window_current_start']     = $cw_start;
        $summary['window_current_end']       = $cw_end;
        $summary['window_prior_start']       = $pw_start;
        $summary['window_prior_end']         = $pw_end;
        $summary['distinct_refreshed_pages'] = count($rows_filtered);
    }

    wp_send_json_success([
        'rows'      => $rows,
        'total'     => $total,
        'page'      => $page,
        'pages'     => ceil($total / $per_page),
        'summary'   => $summary,
        'available' => $available ? [
            'date_from' => $available->date_from,
            'date_to'   => $available->date_to,
            'total'     => intval($available->total),
        ] : null,
    ]);
});

// ─── Keyword Research AJAX ───────────────────────────────────────────────────

add_action('wp_ajax_seom_collect_keywords', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(180);

    $batch_page = intval($_POST['batch_page'] ?? 0);
    $result = SEOM_Keyword_Researcher::collect($batch_page);
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

// ─── Keyword Gaps AJAX ──────────────────────────────────────────────────────

// Bulk upsert helper for gap keywords — uses INSERT ON DUPLICATE KEY UPDATE
function seom_bulk_upsert_gaps($wpdb, $table, $rows, $source, $today, $return_stats = false) {
    if (empty($rows)) {
        return $return_stats ? ['processed' => 0, 'inserted' => 0, 'updated' => 0] : 0;
    }

    $unique_keywords = [];
    foreach ($rows as $r) {
        $key = strtolower(mb_substr($r['keyword'], 0, 191));
        $unique_keywords[$key] = $r['keyword'];
    }

    $existing_count = 0;
    if ($return_stats && !empty($unique_keywords)) {
        $placeholders = implode(',', array_fill(0, count($unique_keywords), '%s'));
        $existing_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE source = %s AND keyword IN ({$placeholders})",
            $source,
            ...array_values($unique_keywords)
        ));
    }

    $values = [];
    foreach ($rows as $r) {
        $values[] = $wpdb->prepare(
            "(%s, %d, %d, %f, %s, %d, %d, %d, %s, %s)",
            $r['keyword'], $r['volume'], $r['difficulty'], $r['cpc'],
            $r['intent'], $r['your_pos'], $r['comp1_pos'], $r['comp2_pos'],
            $source, $today
        );
    }

    $sql = "INSERT INTO {$table}
        (keyword, search_volume, keyword_difficulty, cpc, intent, your_position, competitor_1_position, competitor_2_position, source, date_imported)
        VALUES " . implode(',', $values) . "
        ON DUPLICATE KEY UPDATE
            search_volume = VALUES(search_volume),
            keyword_difficulty = VALUES(keyword_difficulty),
            cpc = VALUES(cpc),
            intent = VALUES(intent),
            your_position = VALUES(your_position),
            competitor_1_position = VALUES(competitor_1_position),
            competitor_2_position = VALUES(competitor_2_position),
            date_imported = VALUES(date_imported)";

    $query_result = $wpdb->query($sql);

    if ($query_result === false) {
        $message = $wpdb->last_error ?: 'Unknown database error during keyword gap import.';
        if ($return_stats) {
            return [
                'processed' => 0,
                'inserted'  => 0,
                'updated'   => 0,
                'error'     => $message,
            ];
        }
        return 0;
    }

    if ($return_stats) {
        $unique_processed = count($unique_keywords);
        $inserted = max(0, $unique_processed - $existing_count);
        return [
            'processed' => count($rows),
            'inserted'  => $inserted,
            'updated'   => max(0, $unique_processed - $inserted),
        ];
    }

    return count($rows);
}

function seom_parse_gap_number($value, $type = 'int') {
    $value = is_string($value) ? trim($value) : $value;
    if ($value === '' || $value === null || $value === '-' || $value === 'n/a') return 0;
    $value = preg_replace('/[^0-9.\-]/', '', (string) $value);
    if ($value === '' || $value === '-' || $value === '.') return 0;
    return $type === 'float' ? (float) $value : (int) round((float) $value);
}

function seom_gap_col_map_from_header($header) {
    $header = array_map(function($h) { return strtolower(trim($h, "\xEF\xBB\xBF \t")); }, $header);
    $col_map = [];
    foreach ($header as $i => $h) {
        if ($h === 'keyword') $col_map['keyword'] = $i;
        elseif ($h === 'volume' || $h === 'search volume') $col_map['volume'] = $i;
        elseif ($h === 'keyword difficulty' || $h === 'kd' || $h === 'difficulty' || $h === 'kd %') $col_map['difficulty'] = $i;
        elseif ($h === 'cpc') $col_map['cpc'] = $i;
        elseif ($h === 'intents' || $h === 'intent') $col_map['intent'] = $i;
        elseif (strpos($h, 'ituonline') !== false && strpos($h, 'pages') === false) $col_map['your_pos'] = $i;
        elseif (strpos($h, 'visiontraining') !== false && strpos($h, 'pages') === false) $col_map['your_pos'] = $i;
        elseif (!isset($col_map['comp1']) && strpos($h, '(pages)') === false && strpos($h, '.com') !== false) $col_map['comp1'] = $i;
        elseif (!isset($col_map['comp2']) && strpos($h, '(pages)') === false && strpos($h, '.com') !== false) $col_map['comp2'] = $i;
    }
    return $col_map;
}

function seom_parse_gap_csv_lines($lines, $col_map) {
    $parsed = [];
    $skipped = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $cols = str_getcsv($line);
        $keyword = sanitize_text_field(trim($cols[$col_map['keyword']] ?? ''));
        if (empty($keyword)) continue;

        $non_latin = preg_match_all('/[\p{Han}\p{Hangul}\p{Katakana}\p{Hiragana}\p{Cyrillic}\p{Arabic}\p{Thai}\p{Devanagari}]/u', $keyword);
        if ($non_latin > 0 && $non_latin >= mb_strlen($keyword) * 0.3) { $skipped++; continue; }

        $parsed[] = [
            'keyword'    => mb_substr($keyword, 0, 255),
            'volume'     => seom_parse_gap_number($cols[$col_map['volume'] ?? -1] ?? 0),
            'difficulty' => seom_parse_gap_number($cols[$col_map['difficulty'] ?? -1] ?? 0),
            'cpc'        => seom_parse_gap_number($cols[$col_map['cpc'] ?? -1] ?? 0, 'float'),
            'intent'     => mb_substr(sanitize_text_field($cols[$col_map['intent'] ?? -1] ?? ''), 0, 50),
            'your_pos'   => seom_parse_gap_number($cols[$col_map['your_pos'] ?? -1] ?? 0),
            'comp1_pos'  => seom_parse_gap_number($cols[$col_map['comp1'] ?? -1] ?? 0),
            'comp2_pos'  => seom_parse_gap_number($cols[$col_map['comp2'] ?? -1] ?? 0),
        ];
    }

    return ['rows' => $parsed, 'skipped' => $skipped];
}

// Import CSV — batched: phase 0 = parse & cache, phase 1+ = upsert batches, final = restore usage
add_action('wp_ajax_seom_import_keyword_gaps', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(120);

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    $phase = sanitize_text_field($_POST['phase'] ?? '0');
    $source = sanitize_text_field($_POST['source'] ?? 'semrush');
    $today = date('Y-m-d');

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        seom_create_tables();
    }

    // Streamed import: start with the CSV header, then receive/upsert small line chunks.
    if ($phase === 'start') {
        $header_line = wp_unslash($_POST['header'] ?? '');
        if (empty($header_line)) wp_send_json_error('CSV header missing.');

        $col_map = seom_gap_col_map_from_header(str_getcsv($header_line));
        if (!isset($col_map['keyword'])) wp_send_json_error('CSV must have a "Keyword" column.');

        delete_option('seom_gap_import_cache');
        update_option('seom_gap_import_meta', [
            'col_map'  => $col_map,
            'skipped'  => 0,
            'source'   => $source,
            'total'    => 0,
            'imported' => 0,
            'inserted' => 0,
            'updated'  => 0,
        ], false);

        wp_send_json_success(['phase' => 'started']);
    }

    if ($phase === 'chunk') {
        $meta = get_option('seom_gap_import_meta', []);
        if (empty($meta['col_map'])) wp_send_json_error('Import session expired. Start over.');

        $csv_data = wp_unslash($_POST['csv_data'] ?? '');
        if ($csv_data === '') wp_send_json_error('No CSV chunk provided.');

        $lines = preg_split('/\r?\n/', $csv_data);
        $result = seom_parse_gap_csv_lines($lines, $meta['col_map']);
        $processed = 0;
        $inserted = 0;
        $updated = 0;
        foreach (array_chunk($result['rows'], 50) as $chunk) {
            $stats = seom_bulk_upsert_gaps($wpdb, $table, $chunk, $meta['source'] ?? $source, $today, true);
            if (!empty($stats['error'])) {
                wp_send_json_error('Database insert failed: ' . $stats['error']);
            }
            $processed += $stats['processed'];
            $inserted += $stats['inserted'];
            $updated += $stats['updated'];
        }

        $meta['imported'] = (int) ($meta['imported'] ?? 0) + $processed;
        $meta['inserted'] = (int) ($meta['inserted'] ?? 0) + $inserted;
        $meta['updated'] = (int) ($meta['updated'] ?? 0) + $updated;
        $meta['skipped'] = (int) ($meta['skipped'] ?? 0) + (int) $result['skipped'];
        $meta['total'] = (int) ($meta['total'] ?? 0) + count($result['rows']);
        update_option('seom_gap_import_meta', $meta, false);

        wp_send_json_success([
            'phase'    => 'chunk',
            'imported' => $meta['imported'],
            'inserted' => $meta['inserted'],
            'updated'  => $meta['updated'],
            'skipped'  => $meta['skipped'],
            'total'    => $meta['total'],
        ]);
    }

    if ($phase === 'complete') {
        $meta = get_option('seom_gap_import_meta', []);
        if (empty($meta)) wp_send_json_error('Import session expired. Start over.');

        $restore_batch = 50;
        $import_source = $meta['source'] ?? $source;
        $restored = (int) ($meta['restored'] ?? 0);
        $finalized_count = (int) ($meta['finalized_count'] ?? 0);
        $finalized_after_id = (int) ($meta['finalized_after_id'] ?? 0);
        $usage_table = $wpdb->prefix . 'seom_keyword_usage';
        $rows_to_restore = $wpdb->get_results($wpdb->prepare("
            SELECT id, keyword
            FROM {$table}
            WHERE date_imported = %s
            AND source = %s
            AND id > %d
            AND last_used_at IS NULL
            ORDER BY id ASC
            LIMIT %d
        ", $today, $import_source, $finalized_after_id, $restore_batch));

        if (!empty($rows_to_restore) && $wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") === $usage_table) {
            $by_keyword = [];
            foreach ($rows_to_restore as $row) {
                $by_keyword[strtolower($row->keyword)][] = (int) $row->id;
            }

            $keywords = array_keys($by_keyword);
            $placeholders = implode(',', array_fill(0, count($keywords), '%s'));
            $usage_rows = $wpdb->get_results($wpdb->prepare("
                SELECT LOWER(keyword) AS keyword_key, used_at, post_id
                FROM {$usage_table}
                WHERE keyword IN ({$placeholders})
                AND post_id IS NOT NULL
                ORDER BY used_at DESC
            ", ...$keywords));

            $matched_ids = [];
            foreach ($usage_rows as $usage) {
                $key = $usage->keyword_key;
                if (empty($by_keyword[$key])) continue;
                foreach ($by_keyword[$key] as $gap_id) {
                    if (isset($matched_ids[$gap_id])) continue;
                    $wpdb->update(
                        $table,
                        [
                            'last_used_at'    => $usage->used_at,
                            'used_in_post_id' => (int) $usage->post_id,
                        ],
                        ['id' => $gap_id],
                        ['%s', '%d'],
                        ['%d']
                    );
                    $matched_ids[$gap_id] = true;
                    $restored++;
                }
            }

            $remaining = [];
            foreach ($rows_to_restore as $row) {
                $gap_id = (int) $row->id;
                if (!isset($matched_ids[$gap_id])) {
                    $remaining[$gap_id] = strtolower($row->keyword);
                }
            }

            if (!empty($remaining)) {
                $meta_placeholders = implode(',', array_fill(0, count($remaining), '%s'));
                $postmeta_rows = $wpdb->get_results($wpdb->prepare("
                    SELECT LOWER(pm.meta_value) AS keyword_key, DATE(p.post_modified) AS used_at, p.ID AS post_id
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_status = 'publish'
                    WHERE pm.meta_key = 'rank_math_focus_keyword'
                    AND LOWER(pm.meta_value) IN ({$meta_placeholders})
                    ORDER BY p.post_modified DESC
                ", ...array_values($remaining)));

                $postmeta_matches = [];
                foreach ($postmeta_rows as $pm_row) {
                    if (!isset($postmeta_matches[$pm_row->keyword_key])) {
                        $postmeta_matches[$pm_row->keyword_key] = $pm_row;
                    }
                }

                foreach ($remaining as $gap_id => $keyword_key) {
                    if (empty($postmeta_matches[$keyword_key])) continue;
                    $match = $postmeta_matches[$keyword_key];
                    $wpdb->update(
                        $table,
                        [
                            'last_used_at'    => $match->used_at,
                            'used_in_post_id' => (int) $match->post_id,
                        ],
                        ['id' => $gap_id],
                        ['%s', '%d'],
                        ['%d']
                    );
                    $restored++;
                }
            }
        }

        if (!empty($rows_to_restore)) {
            $last_row = end($rows_to_restore);
            $meta['finalized_after_id'] = (int) $last_row->id;
            $meta['finalized_count'] = $finalized_count + count($rows_to_restore);
            $meta['restored'] = $restored;
            update_option('seom_gap_import_meta', $meta, false);
            wp_send_json_success([
                'phase'     => 'finalizing',
                'imported'  => (int) ($meta['imported'] ?? $meta['total'] ?? 0),
                'inserted'  => (int) ($meta['inserted'] ?? 0),
                'updated'   => (int) ($meta['updated'] ?? 0),
                'skipped'   => (int) ($meta['skipped'] ?? 0),
                'restored'  => $restored,
                'processed' => min((int) $meta['finalized_count'], (int) ($meta['imported'] ?? $meta['total'] ?? 0)),
                'total'     => (int) ($meta['imported'] ?? $meta['total'] ?? 0),
            ]);
        }

        $complete_summary = [
            'phase'    => 'complete',
            'imported' => (int) ($meta['imported'] ?? $meta['total'] ?? 0),
            'inserted' => (int) ($meta['inserted'] ?? 0),
            'updated'  => (int) ($meta['updated'] ?? 0),
            'skipped'  => (int) ($meta['skipped'] ?? 0),
            'restored' => $restored,
            'source'   => $import_source,
            'date'     => $today,
        ];
        update_option('seom_gap_last_import_summary', $complete_summary, false);

        delete_option('seom_gap_import_cache');
        delete_option('seom_gap_import_meta');

        wp_send_json_success($complete_summary);
    }

    // Phase 0: Parse CSV, cache parsed rows
    if ((int) $phase === 0) {
        $csv_data = wp_unslash($_POST['csv_data'] ?? '');
        if (empty($csv_data)) wp_send_json_error('No CSV data provided.');

        $lines = preg_split('/\r?\n/', $csv_data);
        $header = str_getcsv(array_shift($lines));
        $col_map = seom_gap_col_map_from_header($header);
        if (!isset($col_map['keyword'])) wp_send_json_error('CSV must have a "Keyword" column.');

        $result = seom_parse_gap_csv_lines($lines, $col_map);
        $parsed = $result['rows'];
        $skipped = $result['skipped'];

        // Cache parsed rows — use a WP option since transients may not support large data
        update_option('seom_gap_import_cache', $parsed, false);
        update_option('seom_gap_import_meta', ['skipped' => $skipped, 'source' => $source, 'total' => count($parsed)], false);

        wp_send_json_success([
            'phase'   => 'parsed',
            'total'   => count($parsed),
            'skipped' => $skipped,
            'batches' => ceil(count($parsed) / 500),
        ]);
    }

    // Phase 1+: Process batch of cached rows
    if ((int) $phase >= 1) {
        $batch_size = 500;
        $cached = get_option('seom_gap_import_cache', []);
        $meta = get_option('seom_gap_import_meta', []);
        if (empty($cached)) wp_send_json_error('Import cache expired. Start over.');

        $offset = ($phase - 1) * $batch_size;
        $batch_rows = array_slice($cached, $offset, $batch_size);
        $is_last = ($offset + count($batch_rows)) >= count($cached);

        // Bulk upsert this batch
        $imported = 0;
        $chunk_size = 50;
        for ($i = 0; $i < count($batch_rows); $i += $chunk_size) {
            $chunk = array_slice($batch_rows, $i, $chunk_size);
            $imported += seom_bulk_upsert_gaps($wpdb, $table, $chunk, $meta['source'] ?? $source, $today);
        }

        if (!$is_last) {
            wp_send_json_success([
                'phase'     => 'importing',
                'batch'     => $phase,
                'imported'  => $imported,
                'processed' => min($offset + count($batch_rows), count($cached)),
                'total'     => count($cached),
            ]);
        }

        // Last batch — restore usage and clean up
        $restored = 0;
        $usage_table = $wpdb->prefix . 'seom_keyword_usage';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") === $usage_table) {
            $restored += (int) $wpdb->query("
                UPDATE {$table} g
                INNER JOIN {$usage_table} u ON LOWER(g.keyword) = LOWER(u.keyword)
                SET g.last_used_at = u.used_at, g.used_in_post_id = u.post_id
                WHERE g.last_used_at IS NULL AND u.post_id IS NOT NULL
            ");
            $restored += (int) $wpdb->query("
                UPDATE {$table} g
                INNER JOIN {$wpdb->postmeta} pm ON LOWER(g.keyword) = LOWER(pm.meta_value)
                    AND pm.meta_key = 'rank_math_focus_keyword'
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_status = 'publish'
                SET g.last_used_at = DATE(p.post_modified), g.used_in_post_id = p.ID
                WHERE g.last_used_at IS NULL
            ");
        }

        // Clean up cache
        delete_option('seom_gap_import_cache');
        delete_option('seom_gap_import_meta');

        wp_send_json_success([
            'phase'    => 'complete',
            'imported' => count($cached),
            'skipped'  => $meta['skipped'] ?? 0,
            'restored' => $restored,
        ]);
    }
});

// Get keyword gaps with filtering, sorting, pagination
add_action('wp_ajax_seom_get_keyword_gaps', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        wp_send_json_success(['rows' => [], 'total' => 0, 'tags' => [], 'page' => 1, 'pages' => 0]);
    }

    $page     = max(1, intval($_POST['page'] ?? 1));
    $per_page = 50;
    $offset   = ($page - 1) * $per_page;
    $tag      = sanitize_text_field($_POST['tag'] ?? 'all');
    $search   = sanitize_text_field($_POST['search'] ?? '');
    $sort     = sanitize_text_field($_POST['sort'] ?? 'search_volume');
    $order    = strtoupper(sanitize_text_field($_POST['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $filter   = sanitize_text_field($_POST['filter'] ?? 'all');

    $allowed_sorts = ['keyword', 'search_volume', 'keyword_difficulty', 'cpc', 'your_position', 'competitor_1_position', 'date_imported', 'tag'];
    if (!in_array($sort, $allowed_sorts)) $sort = 'search_volume';

    $where = '1=1';
    if ($tag !== 'all') {
        if ($tag === 'untagged') {
            $where .= " AND (tag IS NULL OR tag = '')";
        } else {
            $where .= $wpdb->prepare(" AND tag = %s", $tag);
        }
    }
    if (!empty($search)) {
        $where .= $wpdb->prepare(" AND keyword LIKE %s", '%' . $wpdb->esc_like($search) . '%');
    }
    if ($filter === 'high_volume') $where .= ' AND search_volume >= 1000';
    if ($filter === 'low_difficulty') $where .= ' AND keyword_difficulty <= 30';
    if ($filter === 'quick_wins') $where .= ' AND search_volume >= 500 AND keyword_difficulty <= 40';
    if ($filter === 'not_ranking') $where .= ' AND your_position = 0';
    if ($filter === 'on_cooldown') $where .= ' AND last_used_at IS NOT NULL AND last_used_at > DATE_SUB(CURDATE(), INTERVAL 90 DAY)';
    if ($filter === 'available') $where .= ' AND (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL 90 DAY))';

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");

    $rows = $wpdb->get_results("
        SELECT * FROM {$table}
        WHERE {$where}
        ORDER BY {$sort} {$order}
        LIMIT {$per_page} OFFSET {$offset}
    ");

    // Enrich rows with linked post info
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    $has_usage = $wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") === $usage_table;
    foreach ($rows as &$r) {
        $r->linked_post = null;

        // First check the gap table's own used_in_post_id
        $pid = intval($r->used_in_post_id ?? 0);

        // If not there, check the unified usage table
        if (!$pid && $has_usage) {
            $pid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$usage_table} WHERE keyword = %s AND post_id IS NOT NULL ORDER BY used_at DESC LIMIT 1",
                strtolower($r->keyword)
            ));
        }

        if ($pid && get_post_status($pid)) {
            $r->linked_post = [
                'id'    => $pid,
                'title' => get_the_title($pid),
                'type'  => get_post_type($pid),
                'url'   => get_permalink($pid),
                'edit'  => admin_url('post.php?action=edit&post=' . $pid),
            ];
        }
    }
    unset($r);

    // Get all unique tags for the filter dropdown
    $tags = $wpdb->get_col("SELECT DISTINCT tag FROM {$table} WHERE tag IS NOT NULL AND tag != '' ORDER BY tag ASC");

    // Summary stats
    $summary = $wpdb->get_row("
        SELECT COUNT(*) as total_keywords,
            SUM(CASE WHEN tag IS NULL OR tag = '' THEN 1 ELSE 0 END) as untagged,
            COUNT(DISTINCT tag) as tag_count,
            AVG(search_volume) as avg_volume
        FROM {$table}
    ");
    if ($summary) {
        $summary->imported_today = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE date_imported = %s",
            $today
        ));
        $summary->source_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE source = %s",
            $source
        ));
        $summary->last_import = get_option('seom_gap_last_import_summary', null);
    }

    wp_send_json_success([
        'rows'    => $rows,
        'total'   => $total,
        'page'    => $page,
        'pages'   => ceil($total / $per_page),
        'tags'    => $tags,
        'summary' => $summary,
    ]);
});

/**
 * Normalize a gap tag into a canonical key used for fuzzy matching.
 * - lowercase, trim, collapse whitespace
 * - "&" → "and"
 * - strip non-alphanumeric (keep spaces)
 * - strip a fixed stopword list
 * - collapse trailing "ies" → "y" and trailing "s" → "" on the LAST word only
 *
 * Two tags that produce the same key are treated as duplicates.
 */
function seom_normalize_tag_key($tag) {
    if (!is_string($tag)) return '';
    $s = strtolower(trim($tag));
    if ($s === '') return '';
    $s = preg_replace('/\s*&\s*/', ' and ', $s);
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = trim($s);
    if ($s === '') return '';

    $stop = ['the', 'a', 'an', 'and', 'or', 'for', 'to', 'of', 'in', 'on', 'with'];
    $words = array_values(array_filter(explode(' ', $s), function ($w) use ($stop) {
        return $w !== '' && !in_array($w, $stop, true);
    }));
    if (empty($words)) return $s;

    // Singularize the final word only (more conservative than every word).
    $last = $words[count($words) - 1];
    if (strlen($last) > 3 && substr($last, -3) === 'ies') {
        $last = substr($last, 0, -3) . 'y';
    } elseif (strlen($last) > 3 && substr($last, -1) === 's' && substr($last, -2) !== 'ss') {
        $last = substr($last, 0, -1);
    }
    $words[count($words) - 1] = $last;
    return implode(' ', $words);
}

/**
 * Find an existing canonical tag that a candidate string maps to, or null.
 *
 * $candidate         — raw string we'd like to assign (AI suggestion or new keyword tag)
 * $normalized_index  — map of normalized_key => canonical_tag (built once per session)
 * $threshold         — similar_text() percentage above which we treat two tags as the same (default 88)
 *
 * Returns the canonical tag string, or null if no match.
 */
function seom_find_canonical_tag($candidate, $normalized_index, $threshold = 88.0) {
    $key = seom_normalize_tag_key($candidate);
    if ($key === '') return null;

    // Exact normalized match (covers >95% of variant cases).
    if (isset($normalized_index[$key])) return $normalized_index[$key];

    // Cheap fuzzy pass over keys. For 11k tags this is fast (string compare,
    // no regex). We compare against KEYS not original tags so casing/punct
    // never penalizes a real match.
    $best_score = 0.0;
    $best_tag   = null;
    foreach ($normalized_index as $existing_key => $existing_tag) {
        // Short-circuit: lengths must be within 30% to bother computing.
        $lk = strlen($key);
        $le = strlen($existing_key);
        if ($lk === 0 || $le === 0) continue;
        $ratio = min($lk, $le) / max($lk, $le);
        if ($ratio < 0.6) continue;

        similar_text($key, $existing_key, $pct);
        if ($pct > $best_score) {
            $best_score = $pct;
            $best_tag   = $existing_tag;
        }
        if ($pct >= 98.0) break; // near-perfect — stop early
    }
    return ($best_score >= $threshold) ? $best_tag : null;
}

/**
 * Build (or return cached) master tag context for an autotag session.
 *
 * Returns:
 *   [
 *     'master_list'      => string[] sorted canonical tag names,
 *     'normalized_index' => map normalized_key => canonical_tag,
 *     'frozen_at'        => unix timestamp,
 *   ]
 *
 * The list is FROZEN — once built for a session, every batch reuses the
 * same context so the AI doesn't see a moving target between batch 1 and
 * batch 38. Only tags with COUNT >= $min_count are admitted to the list,
 * which prevents low-volume mid-session noise from contaminating later
 * batches. Cached for 30 minutes via transient.
 */
function seom_get_autotag_session($force_rebuild = false, $min_count = 3) {
    $cache_key = 'seom_autotag_session';
    if (!$force_rebuild) {
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['master_list'])) return $cached;
    }

    global $wpdb;
    $table    = $wpdb->prefix . 'seom_keyword_gaps';
    $settings = function_exists('seom_get_settings') ? seom_get_settings() : [];

    // Seed categories are always admitted regardless of count.
    $seed_cats = array_filter(array_map('trim', explode("\n", $settings['gap_seed_categories'] ?? '')));

    // Existing tags with >= $min_count keywords. Higher-count canonical wins
    // on ties (preserves the most-used spelling/casing).
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT tag, COUNT(*) AS cnt FROM {$table}
         WHERE tag IS NOT NULL AND tag != ''
         GROUP BY tag
         HAVING cnt >= %d
         ORDER BY cnt DESC, tag ASC",
        $min_count
    ));

    $master = [];
    $normalized_index = [];
    foreach ($seed_cats as $seed) {
        $key = seom_normalize_tag_key($seed);
        if ($key !== '' && !isset($normalized_index[$key])) {
            $normalized_index[$key] = $seed;
            $master[] = $seed;
        }
    }
    foreach ($rows as $r) {
        $key = seom_normalize_tag_key($r->tag);
        if ($key === '') continue;
        if (isset($normalized_index[$key])) continue; // seed or earlier higher-count wins
        $normalized_index[$key] = $r->tag;
        $master[] = $r->tag;
    }
    sort($master);

    $session = [
        'master_list'      => $master,
        'normalized_index' => $normalized_index,
        'frozen_at'        => time(),
    ];
    set_transient($cache_key, $session, 30 * MINUTE_IN_SECONDS);
    return $session;
}

// AI Auto-tag untagged keywords
add_action('wp_ajax_seom_autotag_gaps', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(120);
    ob_start();

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    $batch = min(40, intval($_POST['batch'] ?? 40));
    $reset = !empty($_POST['reset']);

    // Step 1: Frozen master list for this session — built once, reused across
    // every batch in the run. Prevents the AI from seeing a moving target
    // (batch 1 sees 100 categories, batch 38 sees 300+) which is what was
    // letting AI invent variants in later batches.
    $session = seom_get_autotag_session($reset);
    $master_list      = $session['master_list'];
    $normalized_index = $session['normalized_index'];

    // Step 2: Get untagged keywords for this batch.
    $keywords = $wpdb->get_results($wpdb->prepare("
        SELECT id, keyword, search_volume, intent FROM {$table}
        WHERE tag IS NULL OR tag = ''
        ORDER BY search_volume DESC
        LIMIT %d
    ", $batch));

    if (empty($keywords)) {
        delete_transient('seom_autotag_session'); // free the cache when run is done
        ob_end_clean();
        wp_send_json_success(['tagged' => 0, 'remaining' => 0, 'message' => 'All keywords are already tagged.']);
    }

    // Step 3: Pre-AI normalization pass — try to assign each keyword to an
    // existing tag via fuzzy match against the frozen normalized_index BEFORE
    // touching the AI. This is the biggest win against tag explosion: an
    // imported keyword like "ITIL Certifications" matches an existing "ITIL
    // Certification" tag here and never hits the API.
    $tagged = 0;
    $pre_matched = 0;
    $kw_list = [];
    foreach ($keywords as $kw) {
        $canonical = seom_find_canonical_tag($kw->keyword, $normalized_index, 90.0);
        if ($canonical !== null) {
            $wpdb->update($table, ['tag' => $canonical], ['id' => $kw->id]);
            $tagged++;
            $pre_matched++;
        } else {
            $kw_list[] = ['id' => $kw->id, 'keyword' => $kw->keyword];
        }
    }

    if (empty($kw_list)) {
        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE tag IS NULL OR tag = ''");
        ob_end_clean();
        wp_send_json_success([
            'tagged'      => $tagged,
            'pre_matched' => $pre_matched,
            'batch'       => count($keywords),
            'remaining'   => $remaining,
        ]);
    }

    // Step 4: Build strict prompt for whatever didn't match.
    $kw_strings = array_map(function ($r) { return $r['keyword']; }, $kw_list);
    $tag_context = !empty($master_list)
        ? "\n\nMASTER CATEGORY LIST — You MUST assign each keyword to one of these categories:\n" . implode("\n", $master_list)
        : '';

    $instruction = "You are an IT training content strategist. Categorize each keyword into ONE topical category.\n\n"
        . "CRITICAL RULES:\n"
        . "- Return a JSON object mapping each keyword to its category\n"
        . "- You MUST use categories from the MASTER CATEGORY LIST below whenever possible\n"
        . "- ONLY create a new category if a keyword truly does not fit ANY existing category\n"
        . "- If you create a new category, use Title Case, keep it broad (should fit 5+ keywords), and DO NOT duplicate or create variations of existing categories\n"
        . "- NEVER create singular/plural variations (e.g., if 'AI Courses' exists, do NOT create 'AI Course')\n"
        . "- NEVER create variations with minor word differences (e.g., if 'AWS Certifications' exists, do NOT create 'AWS Certification' or 'AWS Cert')\n"
        . "- When in doubt, use the closest existing category rather than creating a new one\n"
        . "- Avoid overly generic categories like 'IT', 'Technology', 'General', 'Other', or 'Unknown'\n"
        . "- Return ONLY the JSON object, no explanation"
        . $tag_context;

    $prompt = "Categorize these keywords:\n" . implode("\n", $kw_strings);

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) { ob_end_clean(); wp_send_json_error('API key not configured.'); }

    $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

    $body_payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $instruction],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.3,
    ];
    // Request JSON mode if model supports it
    if (strpos($model, 'gpt-4') !== false || strpos($model, 'gpt-3.5') !== false) {
        $body_payload['response_format'] = ['type' => 'json_object'];
    }

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($body_payload),
        'timeout' => 120,
    ]);

    if (is_wp_error($response)) { ob_end_clean(); wp_send_json_error('API error: ' . $response->get_error_message()); }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $raw = trim($data['choices'][0]['message']['content'] ?? '');

    // Strip code fences
    $raw = preg_replace('/^```[a-zA-Z]*\s*/m', '', $raw);
    $raw = preg_replace('/\s*```\s*$/m', '', $raw);
    $raw = trim($raw);

    // Attempt JSON repair — fix missing opening quotes on values
    // Pattern: "key": value" → "key": "value"
    $repaired = preg_replace('/:(\s*)([A-Z][^",}]+)"/', ':$1"$2"', $raw);
    if ($repaired !== $raw) {
        $raw = $repaired;
    }

    $mapping = json_decode($raw, true);

    // If still invalid, try line-by-line parsing as fallback
    if (!is_array($mapping)) {
        $mapping = [];
        // Try to parse "keyword": "category" pairs from raw text
        if (preg_match_all('/"([^"]+)"\s*:\s*"([^"]+)"/', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $mapping[$m[1]] = $m[2];
            }
        }
    }

    if (empty($mapping)) { ob_end_clean(); wp_send_json_error('AI returned unparseable response. Try again — smaller batches improve reliability.'); }

    // Step 5: Post-AI validation gate.
    //
    // For each AI-suggested tag, run it through the same normalize+fuzzy match
    // against the FROZEN master list. If it matches an existing canonical, use
    // that. If it's genuinely new AND broad enough (not "Other"/"General"), it
    // becomes a new canonical AND gets added to the in-memory normalized index
    // so subsequent keywords in this same batch resolve to it. If it's flat-out
    // garbage (empty/banned/generic), the keyword is parked under
    // "Uncategorized" for manual review.
    $banned_generic = ['it', 'tech', 'technology', 'general', 'other', 'unknown', 'misc', 'miscellaneous'];
    $rejected = 0;
    $new_categories = 0;
    foreach ($kw_list as $kw_row) {
        $raw_tag = $mapping[$kw_row['keyword']] ?? $mapping[strtolower($kw_row['keyword'])] ?? null;
        if (!$raw_tag) continue;

        $clean = sanitize_text_field(trim($raw_tag));
        if ($clean === '') continue;

        $canonical = seom_find_canonical_tag($clean, $normalized_index, 88.0);
        if ($canonical === null) {
            // No match — vet the candidate before letting it become a new tag.
            $lower = strtolower($clean);
            if (in_array($lower, $banned_generic, true) || strlen($clean) < 3) {
                $wpdb->update($table, ['tag' => 'Uncategorized'], ['id' => $kw_row['id']]);
                $rejected++;
                continue;
            }
            // Accept as a new canonical and register it so the rest of this
            // batch can resolve to it without inventing a near-duplicate.
            $canonical = $clean;
            $key = seom_normalize_tag_key($canonical);
            if ($key !== '' && !isset($normalized_index[$key])) {
                $normalized_index[$key] = $canonical;
                $new_categories++;
            }
        }

        $wpdb->update($table, ['tag' => $canonical], ['id' => $kw_row['id']]);
        $tagged++;
    }

    // Refresh the frozen session if we admitted new canonicals — keeps
    // subsequent AJAX batches in sync.
    if ($new_categories > 0) {
        $session['normalized_index'] = $normalized_index;
        $session['master_list'] = array_values(array_unique($normalized_index));
        sort($session['master_list']);
        set_transient('seom_autotag_session', $session, 30 * MINUTE_IN_SECONDS);
    }

    $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE tag IS NULL OR tag = ''");
    if ($remaining === 0) {
        delete_transient('seom_autotag_session');
    }

    ob_end_clean();
    wp_send_json_success([
        'tagged'         => $tagged,
        'pre_matched'    => $pre_matched,
        'new_categories' => $new_categories,
        'rejected'       => $rejected,
        'batch'          => count($keywords),
        'remaining'      => $remaining,
    ]);
});

// Consolidate tags — AI merges 1000+ tags into ~30-40 categories
// Consolidate tags — multi-phase: 0=programmatic dedup, 1+=AI batches, apply+=DB updates
add_action('wp_ajax_seom_consolidate_gap_tags', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(120);
    ob_start();

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    $phase = sanitize_text_field($_POST['phase'] ?? 'dedup');

    // ── Phase: dedup — Aggressive programmatic merge (no AI needed) ──
    if ($phase === 'dedup') {
        $target_count = intval($_POST['target'] ?? 50);
        update_option('seom_consolidate_target', $target_count, false);

        $tags = $wpdb->get_results("SELECT tag, COUNT(*) as cnt FROM {$table} WHERE tag IS NOT NULL AND tag != '' GROUP BY tag ORDER BY cnt DESC");
        $old_count = count($tags);

        // Build canonical map using the same normalizer the autotag flow uses
        // (singular/plural collapse, stopword strip, &→and, etc.). Highest
        // keyword count wins the canonical name.
        $canonical = []; // normalized_key => ['tag' => BestName, 'cnt' => count]
        foreach ($tags as $t) {
            $norm = seom_normalize_tag_key($t->tag);
            if ($norm === '') continue;
            if (!isset($canonical[$norm]) || $t->cnt > $canonical[$norm]['cnt']) {
                $canonical[$norm] = ['tag' => $t->tag, 'cnt' => $t->cnt];
            }
        }

        // Group all source tags by their canonical, then issue ONE UPDATE per
        // canonical with WHERE tag IN (...) — way fewer queries than the old
        // per-tag loop.
        $merge_groups = []; // canonical_tag => [source_tag, source_tag, ...]
        $deduped = 0;
        foreach ($tags as $t) {
            $norm = seom_normalize_tag_key($t->tag);
            if ($norm === '' || !isset($canonical[$norm])) continue;
            $best = $canonical[$norm]['tag'];
            if ($best !== $t->tag) {
                $merge_groups[$best][] = $t->tag;
                $deduped++;
            }
        }
        foreach ($merge_groups as $best => $sources) {
            $placeholders = implode(',', array_fill(0, count($sources), '%s'));
            $params = array_merge([$best], $sources);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET tag = %s WHERE tag IN ({$placeholders})",
                $params
            ));
        }

        // The autotag session's normalized_index is stale after a dedup —
        // flush it so the next autotag run rebuilds from the post-merge state.
        delete_transient('seom_autotag_session');

        $new_count = (int) $wpdb->get_var("SELECT COUNT(DISTINCT tag) FROM {$table} WHERE tag IS NOT NULL AND tag != ''");

        // If already at target, done
        if ($new_count <= $target_count) {
            ob_end_clean();
            wp_send_json_success(['phase' => 'complete', 'deduped' => $deduped, 'old_count' => $old_count, 'new_count' => $new_count]);
        }

        // Cache remaining tags for the AI phase. We also pre-compute the
        // "kept canonical" list (top tags by count) and stash it once so the
        // ai_batch phase no longer needs to re-query the DB on every call —
        // that per-batch query was the O(N²) hot spot that made consolidation
        // time out.
        $remaining_tags = $wpdb->get_results("SELECT tag, COUNT(*) as cnt FROM {$table} WHERE tag IS NOT NULL AND tag != '' GROUP BY tag ORDER BY cnt DESC");
        $tag_list = [];
        foreach ($remaining_tags as $t) $tag_list[] = ['tag' => $t->tag, 'cnt' => $t->cnt];

        // Top 200 by count (was alphabetical-200, which was a separate bug —
        // the AI was being shown 200 random alphabetical tags instead of the
        // most-frequent ones).
        $top_kept = array_slice(array_map(function ($t) { return $t->tag; }, $remaining_tags), 0, 200);

        update_option('seom_consolidate_tags', $tag_list, false);
        update_option('seom_consolidate_kept', $top_kept, false);
        update_option('seom_consolidate_changes', [], false);

        ob_end_clean();
        wp_send_json_success([
            'phase'      => 'deduped',
            'deduped'    => $deduped,
            'old_count'  => $old_count,
            'remaining'  => count($remaining_tags),
            'ai_batches' => ceil(count($remaining_tags) / 200),
        ]);
    }

    // ── Phase: ai_batch — Send chunks of tags to AI for consolidation ──
    if ($phase === 'ai_batch') {
        $batch_num = intval($_POST['batch'] ?? 0);
        $target_count = intval(get_option('seom_consolidate_target', 50));
        $tag_list = get_option('seom_consolidate_tags', []);
        $all_changes = get_option('seom_consolidate_changes', []);

        if (empty($tag_list)) { ob_end_clean(); wp_send_json_error('Tag cache expired. Start over.'); }

        $batch_size = 200;
        $offset = $batch_num * $batch_size;
        $batch_tags = array_slice($tag_list, $offset, $batch_size);
        $is_last = ($offset + count($batch_tags)) >= count($tag_list);

        if (empty($batch_tags)) {
            // All AI batches done — save and move to apply phase
            update_option('seom_consolidate_changes', $all_changes, false);
            ob_end_clean();
            wp_send_json_success([
                'phase'   => 'ai_done',
                'changes' => count($all_changes),
                'apply_batches' => ceil(count($all_changes) / 200),
            ]);
        }

        // Pull the FROZEN "kept canonicals" list once (set during the dedup
        // phase) plus any new canonicals discovered by earlier ai_batch calls.
        // Crucially: no per-batch DB query — that was the O(N²) hot spot that
        // made consolidation time out on large tag sets.
        $kept = get_option('seom_consolidate_kept', []);
        $target_cats = $kept;
        foreach ($all_changes as $c) $target_cats[] = $c['new'];
        $target_cats = array_values(array_unique($target_cats));
        sort($target_cats);
        $target_cats = array_slice($target_cats, 0, 200);

        $batch_lines = [];
        foreach ($batch_tags as $bt) $batch_lines[] = $bt['tag'] . ' (' . $bt['cnt'] . ')';

        $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
        if (!$api_key) { ob_end_clean(); wp_send_json_error('API key not configured.'); }
        $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

        $instruction = "You are consolidating keyword categories. Map each tag below to a broader category.\n\n"
            . "RULES:\n"
            . "- Return JSON mapping each current tag to its consolidated name\n"
            . "- Target ~{$target_count} total categories across the whole dataset\n"
            . "- REUSE categories from the existing list below whenever possible\n"
            . "- If a tag is already good, map it to itself\n"
            . "- Merge variations: singular/plural, abbreviations, minor wording differences\n"
            . "- Use short Title Case names\n"
            . "- Return ONLY JSON, no explanation\n\n"
            . "EXISTING TARGET CATEGORIES (reuse these):\n" . implode(', ', array_slice($target_cats, 0, 100)) . "\n\n"
            . "TAGS TO MAP:\n" . implode("\n", $batch_lines);

        $body_payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $instruction],
                ['role' => 'user', 'content' => 'Map these ' . count($batch_tags) . ' tags. Return JSON.'],
            ],
            'temperature' => 0.2,
        ];
        if (strpos($model, 'gpt-4') !== false) {
            $body_payload['response_format'] = ['type' => 'json_object'];
        }

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
            'body' => json_encode($body_payload),
            'timeout' => 90,
        ]);

        if (is_wp_error($response)) { ob_end_clean(); wp_send_json_error('API error: ' . $response->get_error_message()); }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $raw = trim($data['choices'][0]['message']['content'] ?? '');
        $raw = preg_replace('/^```[a-zA-Z]*\s*/m', '', $raw);
        $raw = preg_replace('/\s*```\s*$/m', '', $raw);

        $repaired = preg_replace('/:(\s*)([A-Z][^",}]+)"/', ':$1"$2"', $raw);
        $mapping = json_decode($repaired, true);
        if (!is_array($mapping)) {
            $mapping = [];
            if (preg_match_all('/"([^"]+)"\s*:\s*"([^"]+)"/', $raw, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) $mapping[$m[1]] = $m[2];
            }
        }

        // Add non-identity changes to the cumulative list
        foreach ($mapping as $old => $new) {
            $old = trim($old);
            $new = sanitize_text_field(trim($new));
            if (!empty($new) && $old !== $new) {
                $all_changes[] = ['old' => $old, 'new' => $new];
            }
        }
        update_option('seom_consolidate_changes', $all_changes, false);

        if ($is_last) {
            ob_end_clean();
            wp_send_json_success([
                'phase'   => 'ai_done',
                'changes' => count($all_changes),
                'apply_batches' => ceil(count($all_changes) / 20),
            ]);
        }

        ob_end_clean();
        wp_send_json_success([
            'phase'     => 'ai_batch',
            'batch'     => $batch_num,
            'processed' => $offset + count($batch_tags),
            'total'     => count($tag_list),
            'changes_so_far' => count($all_changes),
        ]);
    }

    // ── Phase: apply — Apply cached changes in batches ──
    if ($phase === 'apply') {
        $batch_num = intval($_POST['batch'] ?? 0);
        $changes = get_option('seom_consolidate_changes', []);
        if (empty($changes)) { ob_end_clean(); wp_send_json_error('Changes cache expired.'); }

        $batch_size = 200;
        $offset = $batch_num * $batch_size;
        $batch = array_slice($changes, $offset, $batch_size);
        $is_last = ($offset + count($batch)) >= count($changes);

        // Group by canonical so each UPDATE merges multiple source tags at
        // once: UPDATE ... SET tag = 'Foo' WHERE tag IN ('foo','Foos','FOO')
        $groups = []; // canonical => [source, source, ...]
        foreach ($batch as $change) {
            $groups[$change['new']][] = $change['old'];
        }

        $merged = 0;
        foreach ($groups as $canonical => $sources) {
            $sources = array_values(array_unique($sources));
            $placeholders = implode(',', array_fill(0, count($sources), '%s'));
            $params = array_merge([$canonical], $sources);
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET tag = %s WHERE tag IN ({$placeholders})",
                $params
            ));
            if ($affected !== false) $merged += (int) $affected;
        }

        if (!$is_last) {
            ob_end_clean();
            wp_send_json_success([
                'phase'     => 'applying',
                'batch'     => $batch_num,
                'processed' => $offset + count($batch),
                'total'     => count($changes),
            ]);
        }

        // Done — clean up
        delete_option('seom_consolidate_tags');
        delete_option('seom_consolidate_changes');
        delete_option('seom_consolidate_kept');
        delete_option('seom_consolidate_target');
        delete_transient('seom_autotag_session'); // master list is now stale
        $new_count = (int) $wpdb->get_var("SELECT COUNT(DISTINCT tag) FROM {$table} WHERE tag IS NOT NULL AND tag != ''");

        ob_end_clean();
        wp_send_json_success([
            'phase'     => 'complete',
            'merged'    => count($changes),
            'new_count' => $new_count,
        ]);
    }

    ob_end_clean();
    wp_send_json_error('Unknown phase.');
});

// ─── Tag Cleanup — variant clusters, low-volume, and one-click merges ──────
//
// Backend for the cleanup panel on the keyword gaps dashboard. Three actions:
//   - seom_get_tag_cleanup: returns variant clusters + low-volume tag list
//   - seom_merge_gap_tags:  merges N source tags into a canonical tag
//   - seom_uncategorize_gap_tags: clears the tag column on selected tags
//
// All three operate ONLY on the seom_keyword_gaps table; nothing here touches
// WordPress post_tag terms.

/**
 * Tokenize a tag for anchor-cluster analysis.
 * - lowercase, strip non-alphanumeric
 * - drop stopwords + generic-cleanup words ("best", "top", "training", etc.
 *   that match too many tags to be useful as a cluster anchor)
 * - singularize each token (ies→y, trailing s except ss)
 */
function seom_tokenize_tag_for_cluster($tag) {
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip([
            // structural stopwords
            'the', 'a', 'an', 'and', 'or', 'for', 'to', 'of', 'in', 'on', 'with',
            'is', 'are', 'be', 'do', 'does', 'will', 'can', 'how', 'what', 'why',
            'when', 'where', 'who', 'which', 'vs', 'versus', 'as',
            // too-generic to be anchors — they cluster everything together
            'best', 'top', 'new', 'free', 'cheap', 'good', 'better',
            'guide', 'guides', 'tip', 'tips', 'help',
            'use', 'using', 'used',
            'it', 'tech', 'technology', 'general', 'other', 'misc',
        ]);
    }
    $s = strtolower(trim((string) $tag));
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', trim($s));
    if ($s === '') return [];
    $tokens = [];
    foreach (explode(' ', $s) as $t) {
        if ($t === '' || strlen($t) < 2) continue;
        if (isset($stop[$t])) continue;
        if (ctype_digit($t)) continue; // version numbers ("4", "2025") aren't anchors
        if (strlen($t) > 3 && substr($t, -3) === 'ies') {
            $t = substr($t, 0, -3) . 'y';
        } elseif (strlen($t) > 3 && substr($t, -1) === 's' && substr($t, -2) !== 'ss') {
            $t = substr($t, 0, -1);
        }
        $tokens[$t] = true; // dedup within a tag
    }
    return array_keys($tokens);
}

/**
 * Build anchor-based clusters from a set of (tag, count) rows.
 *
 * For each tag, the "anchor" is the token it contains that appears in the
 * FEWEST other tags (above $min_freq). The most-specific shared topic wins —
 * "ITIL Training" clusters under `itil` (appears in ~200 tags) instead of
 * `training` (appears in ~800), so we never accidentally lump "ITIL Training"
 * and "Cybersecurity Training" together.
 *
 * Tags with no qualifying anchor are returned as singletons (caller decides
 * what to do with them).
 *
 * Returns ['clusters' => [...], 'singletons' => [['tag' => ..., 'cnt' => ...]]]
 * where each cluster is:
 *   [
 *     'anchor'    => 'itil',
 *     'canonical' => suggested canonical tag (the variant that IS just the anchor
 *                    if it exists, otherwise the shortest highest-count variant),
 *     'variants'  => [['tag'=>..., 'cnt'=>...], ...] sorted by count desc,
 *     'total'     => sum of cnt across variants,
 *   ]
 */
function seom_build_anchor_clusters($rows, $min_freq = 2) {
    // Step 1: tokenize every tag and count token frequency across the set.
    $tag_tokens = [];
    $token_freq = [];
    foreach ($rows as $r) {
        $toks = seom_tokenize_tag_for_cluster($r->tag);
        if (empty($toks)) continue;
        $tag_tokens[$r->tag] = $toks;
        foreach ($toks as $t) {
            $token_freq[$t] = ($token_freq[$t] ?? 0) + 1;
        }
    }

    // Step 2: pick each tag's anchor = the token it contains that has the
    // LOWEST cross-tag frequency among tokens with freq >= $min_freq. That's
    // the most specific shared identifier (e.g., 'itil' wins over 'training').
    // Tie-breaker: shorter token (brand/product terms tend to be short).
    $tag_anchor = [];
    foreach ($tag_tokens as $tag => $toks) {
        $best_token = null;
        $best_freq  = PHP_INT_MAX;
        foreach ($toks as $t) {
            $f = $token_freq[$t] ?? 0;
            if ($f < $min_freq) continue;
            if ($f < $best_freq || ($f === $best_freq && $best_token !== null && strlen($t) < strlen($best_token))) {
                $best_freq  = $f;
                $best_token = $t;
            }
        }
        if ($best_token !== null) {
            $tag_anchor[$tag] = $best_token;
        }
    }

    // Step 3: group rows by anchor.
    $by_anchor = [];
    $singletons = [];
    foreach ($rows as $r) {
        if (!isset($tag_anchor[$r->tag])) {
            $singletons[] = ['tag' => $r->tag, 'cnt' => (int) $r->cnt];
            continue;
        }
        $a = $tag_anchor[$r->tag];
        if (!isset($by_anchor[$a])) $by_anchor[$a] = ['variants' => [], 'total' => 0];
        $by_anchor[$a]['variants'][] = ['tag' => $r->tag, 'cnt' => (int) $r->cnt];
        $by_anchor[$a]['total'] += (int) $r->cnt;
    }

    // Step 4: shape output. Anchors with only 1 variant fall back to
    // singletons (nothing to merge).
    $clusters = [];
    foreach ($by_anchor as $anchor => $group) {
        if (count($group['variants']) < 2) {
            $singletons[] = $group['variants'][0];
            continue;
        }
        usort($group['variants'], function ($a, $b) { return $b['cnt'] <=> $a['cnt']; });

        // Suggested canonical: prefer a variant whose normalized key equals
        // just the anchor (means "the bare topic name"). Otherwise the
        // highest-count variant.
        $canonical = $group['variants'][0]['tag'];
        foreach ($group['variants'] as $v) {
            $key = seom_normalize_tag_key($v['tag']);
            if ($key === $anchor) { $canonical = $v['tag']; break; }
        }

        $clusters[] = [
            'anchor'    => $anchor,
            'canonical' => $canonical,
            'variants'  => $group['variants'],
            'total'     => $group['total'],
        ];
    }
    usort($clusters, function ($a, $b) { return $b['total'] <=> $a['total']; });

    return ['clusters' => $clusters, 'singletons' => $singletons];
}

add_action('wp_ajax_seom_get_tag_cleanup', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';

    $low_volume_threshold = max(1, intval($_POST['low_volume'] ?? 5));
    $max_clusters         = max(10, min(500, intval($_POST['max_clusters'] ?? 200)));
    $min_anchor_freq      = max(2, intval($_POST['min_freq'] ?? 2));

    $rows = $wpdb->get_results("
        SELECT tag, COUNT(*) AS cnt FROM {$table}
        WHERE tag IS NOT NULL AND tag != ''
        GROUP BY tag
        ORDER BY cnt DESC, tag ASC
    ");

    $result = seom_build_anchor_clusters($rows, $min_anchor_freq);
    $clusters   = array_slice($result['clusters'], 0, $max_clusters);
    $singletons = $result['singletons'];

    // Low-volume = singletons under threshold.
    $low_volume = [];
    foreach ($singletons as $s) {
        if ($s['cnt'] < $low_volume_threshold) $low_volume[] = $s;
    }
    usort($low_volume, function ($a, $b) {
        if ($a['cnt'] === $b['cnt']) return strcmp($a['tag'], $b['tag']);
        return $a['cnt'] <=> $b['cnt'];
    });
    $low_volume = array_slice($low_volume, 0, 500);

    $total_tags        = count($rows);
    $cluster_tag_count = 0;
    foreach ($clusters as $c) $cluster_tag_count += count($c['variants']);

    wp_send_json_success([
        'total_tags'        => $total_tags,
        'clusters'          => $clusters,
        'cluster_count'     => count($result['clusters']), // total found, before slice
        'cluster_shown'     => count($clusters),
        'cluster_tag_count' => $cluster_tag_count,
        'low_volume'        => $low_volume,
        'low_volume_count'  => count($low_volume),
        'threshold'         => $low_volume_threshold,
        'min_freq'          => $min_anchor_freq,
    ]);
});

add_action('wp_ajax_seom_merge_gap_tags', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';

    $canonical_raw = wp_unslash($_POST['canonical'] ?? '');
    $sources_raw   = wp_unslash($_POST['sources'] ?? '');
    $canonical = sanitize_text_field(trim($canonical_raw));
    if ($canonical === '') wp_send_json_error('Canonical tag is required.');

    $sources = is_array($sources_raw) ? $sources_raw : json_decode($sources_raw, true);
    if (!is_array($sources) || empty($sources)) wp_send_json_error('No source tags provided.');

    $sources = array_values(array_unique(array_map(function ($s) {
        return sanitize_text_field(trim((string) $s));
    }, $sources)));
    $sources = array_values(array_filter($sources, function ($s) use ($canonical) {
        return $s !== '' && $s !== $canonical;
    }));
    if (empty($sources)) wp_send_json_error('No source tags differ from the canonical.');

    $placeholders = implode(',', array_fill(0, count($sources), '%s'));
    $params = array_merge([$canonical], $sources);
    $affected = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET tag = %s WHERE tag IN ({$placeholders})",
        $params
    ));

    // Any cached state that referenced the old tag names is now wrong.
    delete_transient('seom_autotag_session');

    wp_send_json_success([
        'merged'   => (int) $affected,
        'canonical' => $canonical,
        'sources'  => $sources,
    ]);
});

/**
 * Bulk-merge endpoint. Accepts an array of {canonical, sources[]} groups and
 * folds each group with a single grouped UPDATE. Used by the "Merge All
 * Clusters" button so the user can take down the entire FinOps/ITIL backlog
 * in one round-trip.
 */
add_action('wp_ajax_seom_bulk_merge_gap_tags', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(300);

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';

    $groups_raw = wp_unslash($_POST['groups'] ?? '');
    $groups = is_array($groups_raw) ? $groups_raw : json_decode($groups_raw, true);
    if (!is_array($groups) || empty($groups)) wp_send_json_error('No merge groups provided.');

    $total_merged    = 0;
    $clusters_merged = 0;
    foreach ($groups as $g) {
        if (!is_array($g)) continue;
        $canonical = sanitize_text_field(trim((string) ($g['canonical'] ?? '')));
        $sources_in = $g['sources'] ?? [];
        if ($canonical === '' || !is_array($sources_in) || empty($sources_in)) continue;

        $sources = array_values(array_unique(array_map(function ($s) {
            return sanitize_text_field(trim((string) $s));
        }, $sources_in)));
        $sources = array_values(array_filter($sources, function ($s) use ($canonical) {
            return $s !== '' && $s !== $canonical;
        }));
        if (empty($sources)) continue;

        $placeholders = implode(',', array_fill(0, count($sources), '%s'));
        $params = array_merge([$canonical], $sources);
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET tag = %s WHERE tag IN ({$placeholders})",
            $params
        ));
        if ($affected !== false) {
            $total_merged += (int) $affected;
            $clusters_merged++;
        }
    }

    // Frozen autotag context is stale now.
    delete_transient('seom_autotag_session');

    $remaining_distinct = (int) $wpdb->get_var("SELECT COUNT(DISTINCT tag) FROM {$table} WHERE tag IS NOT NULL AND tag != ''");

    wp_send_json_success([
        'clusters_merged' => $clusters_merged,
        'merged'          => $total_merged,
        'distinct_tags'   => $remaining_distinct,
    ]);
});

add_action('wp_ajax_seom_uncategorize_gap_tags', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';

    $tags_raw = wp_unslash($_POST['tags'] ?? '');
    $tags = is_array($tags_raw) ? $tags_raw : json_decode($tags_raw, true);
    if (!is_array($tags) || empty($tags)) wp_send_json_error('No tags provided.');

    $tags = array_values(array_unique(array_map(function ($t) {
        return sanitize_text_field(trim((string) $t));
    }, $tags)));
    $tags = array_values(array_filter($tags, function ($t) { return $t !== ''; }));
    if (empty($tags)) wp_send_json_error('No valid tags after sanitization.');

    $placeholders = implode(',', array_fill(0, count($tags), '%s'));
    $affected = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET tag = NULL WHERE tag IN ({$placeholders})",
        $tags
    ));

    delete_transient('seom_autotag_session');

    wp_send_json_success([
        'uncategorized' => (int) $affected,
        'tag_count'     => count($tags),
    ]);
});

// Clean up non-English keywords from gaps table
add_action('wp_ajax_seom_cleanup_gap_keywords', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    // Only delete keywords where 30%+ of characters are CJK, Cyrillic, Arabic, etc.
    // Fetch candidates and check in PHP for accurate Unicode handling
    $candidates = $wpdb->get_results("SELECT id, keyword FROM {$table}");
    $to_delete = [];
    foreach ($candidates as $c) {
        $non_latin = preg_match_all('/[\p{Han}\p{Hangul}\p{Katakana}\p{Hiragana}\p{Cyrillic}\p{Arabic}\p{Thai}\p{Devanagari}]/u', $c->keyword);
        $len = mb_strlen($c->keyword);
        if ($non_latin > 0 && $len > 0 && ($non_latin / $len) >= 0.3) {
            $to_delete[] = $c->id;
        }
    }
    $deleted = 0;
    if (!empty($to_delete)) {
        $placeholders = implode(',', array_fill(0, count($to_delete), '%d'));
        $deleted = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders)", ...$to_delete));
    }
    wp_send_json_success(['deleted' => $deleted]);
});

// Update tag for specific keywords (manual edit)
add_action('wp_ajax_seom_update_gap_tag', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    $ids   = array_map('intval', (array) ($_POST['ids'] ?? []));
    $tag   = sanitize_text_field($_POST['tag'] ?? '');

    if (empty($ids)) wp_send_json_error('No keywords selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET tag = %s WHERE id IN ($placeholders)",
        $tag, ...$ids
    ));

    wp_send_json_success(['updated' => count($ids)]);
});

// Delete keyword gaps
add_action('wp_ajax_seom_delete_keyword_gaps', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    $ids   = array_map('intval', (array) ($_POST['ids'] ?? []));

    if (empty($ids)) wp_send_json_error('No keywords selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders)", ...$ids));

    wp_send_json_success(['deleted' => count($ids)]);
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

    $has_status = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'status'");

    // Default: show active keywords. If no status column yet, fall back to latest date_collected.
    $filter_sql = '';
    $base_where = $has_status ? "k.status = 'active'" : "k.date_collected = (SELECT MAX(date_collected) FROM {$table})";

    switch ($filter) {
        case 'rising':    $filter_sql = " AND trend_direction = 'rising'"; break;
        case 'declining': $filter_sql = " AND trend_direction = 'declining'"; break;
        case 'gaps':      $filter_sql = " AND is_content_gap = 1"; break;
        case 'page2':     $filter_sql = " AND avg_position >= 11 AND avg_position <= 20"; break;
        case 'top':       $filter_sql = " AND avg_position > 0 AND avg_position <= 10"; break;
        case 'lost':      if ($has_status) { $base_where = "k.status = 'lost'"; } break;
    }

    $rows = $wpdb->get_results("
        SELECT k.*, p.post_title as mapped_title
        FROM {$table} k
        LEFT JOIN {$wpdb->posts} p ON k.mapped_post_id = p.ID
        WHERE {$base_where} {$filter_sql}
        ORDER BY {$sort} {$order}
        LIMIT {$per_page} OFFSET {$offset}
    ");

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} k WHERE {$base_where} {$filter_sql}");

    // Get autocomplete suggestions for displayed keywords
    $sug_table = $wpdb->prefix . 'seom_keyword_suggestions';
    foreach ($rows as &$r) {
        $sug = $wpdb->get_col($wpdb->prepare(
            "SELECT suggestion FROM {$sug_table} WHERE seed_keyword = %s LIMIT 5", $r->keyword
        ));
        $r->suggestions = $sug;
    }

    // Summary — all active keywords
    $active_where = $has_status ? "status = 'active'" : "date_collected = (SELECT MAX(date_collected) FROM {$table})";
    $lost_count = $has_status ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'lost'") : 0;

    $summary = $wpdb->get_row("
        SELECT
            COUNT(*) as total_keywords,
            SUM(CASE WHEN trend_direction = 'rising' THEN 1 ELSE 0 END) as rising,
            SUM(CASE WHEN trend_direction = 'declining' THEN 1 ELSE 0 END) as declining,
            SUM(CASE WHEN is_content_gap = 1 THEN 1 ELSE 0 END) as content_gaps,
            SUM(CASE WHEN avg_position >= 11 AND avg_position <= 20 THEN 1 ELSE 0 END) as page2_keywords
        FROM {$table} WHERE {$active_where}
    ");
    if ($summary) $summary->lost = $lost_count;

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

// ─── Goals AJAX ─────────────────────────────────────────────────────────────

// Get current site metrics snapshot (for goal baseline and progress)
add_action('wp_ajax_seom_get_goal_metrics', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_page_metrics';
    $settings = seom_get_settings();
    $ghost_threshold = intval($settings['ghost_threshold']);

    // Optional: get metrics as of a specific historical date
    $as_of = sanitize_text_field($_POST['as_of_date'] ?? '');

    if ($as_of) {
        // Find the closest collection date on or before the requested date
        $target_date = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(date_collected) FROM {$table} WHERE date_collected <= %s", $as_of
        ));
        if (!$target_date) {
            // Fall back to the earliest date we have
            $target_date = $wpdb->get_var("SELECT MIN(date_collected) FROM {$table}");
        }
    } else {
        $target_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");
    }

    if (!$target_date) wp_send_json_error('No GSC data collected yet.');

    $metrics = $wpdb->get_row($wpdb->prepare("
        SELECT
            COUNT(DISTINCT m.post_id) as total_pages,
            SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(%s, INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
            SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
            SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
            SUM(CASE WHEN m.avg_position > 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page3plus_pages,
            SUM(CASE WHEN p.post_date >= DATE_SUB(%s, INTERVAL 14 DAY) THEN 1 ELSE 0 END) as new_pages
        FROM {$table} m
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE m.date_collected = %s
        AND p.post_status = 'publish' AND p.post_type IN ('product','post','page')
    ", $ghost_threshold, $target_date, $target_date, $target_date));

    // Override site-wide aggregate metrics with true GSC totals
    // The per-post SUM only captures matched WordPress posts; GSC site totals are the real numbers
    $site_totals = seom_get_site_totals($as_of ?: null);
    if ($site_totals['date']) {
        $metrics->total_clicks = intval($site_totals['clicks']);
        $metrics->total_impressions = intval($site_totals['impressions']);
        $metrics->avg_ctr = floatval($site_totals['ctr']);
        $metrics->avg_position = floatval($site_totals['position']);
    }

    // Available collection dates for the date picker
    $available_dates = $wpdb->get_col("SELECT DISTINCT date_collected FROM {$table} ORDER BY date_collected ASC");

    // Additional metrics: new content created and stale pages
    // Use $target_date so baseline reflects the requested start date, not today
    $cooldown_days = intval($settings['cooldown_days']);

    // New content: total published pages/posts/products as of the target date
    // Baseline = how many pages existed at the start date; progress = how many exist now
    $new_content_30d = (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type IN ('post','product','page') AND post_status = 'publish'
        AND post_date <= %s
    ", $target_date));

    // Stale pages: published posts/products older than 90 days (relative to target date)
    // that have NEVER been refreshed or whose last refresh is older than cooldown period
    $stale_pages = (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'last_page_refresh'
        WHERE p.post_type IN ('post','product') AND p.post_status = 'publish'
        AND p.post_date < DATE_SUB(%s, INTERVAL 90 DAY)
        AND (pm.meta_value IS NULL OR pm.meta_value < DATE_SUB(%s, INTERVAL %d DAY))
    ", $target_date, $target_date, $cooldown_days));

    // Refreshed this month count (month of the target date)
    $refreshed_this_month = (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history
        WHERE refresh_date >= DATE_FORMAT(%s, '%%Y-%%m-01')
        AND refresh_date <= %s
    ", $target_date, $target_date));

    // Add to metrics object
    $metrics->new_content_30d = $new_content_30d;
    $metrics->stale_pages = $stale_pages;
    $metrics->refreshed_this_month = $refreshed_this_month;

    // Daily refresh capacity
    $daily_limit = intval($settings['daily_limit']);

    wp_send_json_success([
        'metrics'         => $metrics,
        'daily_limit'     => $daily_limit,
        'cooldown_days'   => $cooldown_days,
        'collected_date'  => $target_date,
        'available_dates' => $available_dates,
    ]);
});

// Get all goals
add_action('wp_ajax_seom_get_goals', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $goals = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}seom_goals ORDER BY FIELD(status, 'active', 'completed', 'missed', 'cancelled'), priority ASC, deadline ASC");
    wp_send_json_success($goals ?: []);
});

// Create a goal
add_action('wp_ajax_seom_create_goal', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $metric       = sanitize_text_field($_POST['metric'] ?? '');
    $direction    = sanitize_text_field($_POST['direction'] ?? 'reduce');
    $target_value = floatval($_POST['target_value'] ?? 0);
    $target_type  = sanitize_text_field($_POST['target_type'] ?? 'percent');
    $baseline     = floatval($_POST['baseline_value'] ?? 0);
    $start_date   = sanitize_text_field($_POST['start_date'] ?? date('Y-m-d'));
    $deadline     = sanitize_text_field($_POST['deadline'] ?? '');
    $priority     = max(1, min(5, intval($_POST['priority'] ?? 3)));
    $notes        = sanitize_textarea_field($_POST['notes'] ?? '');
    $ai_assessment = sanitize_textarea_field($_POST['ai_assessment'] ?? '');

    if (empty($metric) || empty($deadline) || $target_value <= 0) {
        wp_send_json_error('Missing required fields.');
    }

    // Check for duplicate: active goal with same metric whose period overlaps
    $goals_table = $wpdb->prefix . 'seom_goals';
    $duplicate = $wpdb->get_row($wpdb->prepare(
        "SELECT id, deadline, start_date FROM {$goals_table}
         WHERE metric = %s AND status = 'active'
         AND start_date <= %s AND deadline >= %s
         LIMIT 1",
        $metric, $deadline, $start_date
    ));
    if ($duplicate) {
        $metric_labels = [
            'ghost_pages' => 'Ghost Pages', 'total_clicks' => 'Total Clicks',
            'total_impressions' => 'Total Impressions', 'avg_position' => 'Avg Position',
            'avg_ctr' => 'Avg CTR', 'page1_pages' => 'Page 1 Pages',
            'page2_pages' => 'Page 2 Pages', 'pages_with_impressions' => 'Pages w/ Impressions',
            'new_content_30d' => 'New Content', 'stale_pages' => 'Stale Pages',
            'refreshed_this_month' => 'Refreshed This Month',
        ];
        $label = $metric_labels[$metric] ?? $metric;
        wp_send_json_error("You already have an active \"{$label}\" goal (through {$duplicate->deadline}). Complete, cancel, or edit the existing goal first.");
    }

    // Get the actual current value for this metric using the latest single collection date
    $table = $wpdb->prefix . 'seom_page_metrics';
    $ghost_threshold = intval(seom_get_settings()['ghost_threshold']);
    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");
    $current_value = $baseline; // fallback
    if ($latest_date) {
        $cur_metrics = $wpdb->get_row($wpdb->prepare("
            SELECT
                SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
                SUM(m.clicks) as total_clicks,
                SUM(m.impressions) as total_impressions,
                AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
                AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
                SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
                SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
                SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions
            FROM {$table} m
            JOIN {$wpdb->posts} p ON m.post_id = p.ID
            WHERE m.date_collected = %s
            AND p.post_status = 'publish' AND p.post_type IN ('product','post','page')
        ", $ghost_threshold, $latest_date));
        // Override site-wide totals with true GSC data
        $site_totals = seom_get_site_totals();
        if ($site_totals['date']) {
            $cur_metrics->total_clicks = intval($site_totals['clicks']);
            $cur_metrics->total_impressions = intval($site_totals['impressions']);
            $cur_metrics->avg_ctr = floatval($site_totals['ctr']);
            $cur_metrics->avg_position = floatval($site_totals['position']);
        }
        if ($cur_metrics && isset($cur_metrics->$metric)) {
            $current_value = floatval($cur_metrics->$metric);
        }
    }

    $wpdb->insert($wpdb->prefix . 'seom_goals', [
        'metric'         => $metric,
        'direction'      => $direction,
        'target_value'   => $target_value,
        'target_type'    => $target_type,
        'baseline_value' => $baseline,
        'current_value'  => $current_value,
        'start_date'     => $start_date,
        'deadline'       => $deadline,
        'priority'       => $priority,
        'created_at'     => current_time('mysql'),
        'status'         => 'active',
        'ai_assessment'  => $ai_assessment,
        'notes'          => $notes,
    ]);

    wp_send_json_success(['id' => $wpdb->insert_id]);
});

// Update goal status
add_action('wp_ajax_seom_update_goal', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('Invalid.');

    $mode = sanitize_text_field($_POST['mode'] ?? 'status');

    if ($mode === 'full') {
        // Full edit of an active goal
        $update = [];
        if (isset($_POST['target_value']))   $update['target_value']   = floatval($_POST['target_value']);
        if (isset($_POST['target_type']))    $update['target_type']    = sanitize_text_field($_POST['target_type']);
        if (isset($_POST['direction']))      $update['direction']      = sanitize_text_field($_POST['direction']);
        if (isset($_POST['priority']))       $update['priority']       = max(1, min(5, intval($_POST['priority'])));
        if (isset($_POST['deadline']))       $update['deadline']       = sanitize_text_field($_POST['deadline']);
        if (isset($_POST['notes']))          $update['notes']          = sanitize_textarea_field($_POST['notes']);
        if (isset($_POST['metric']))         $update['metric']         = sanitize_text_field($_POST['metric']);
        if (isset($_POST['baseline_value'])) $update['baseline_value'] = floatval($_POST['baseline_value']);
        if (isset($_POST['start_date']))     $update['start_date']     = sanitize_text_field($_POST['start_date']);

        if (empty($update)) wp_send_json_error('Nothing to update.');

        // If target value changed, update stale percentage references in notes and clear AI assessment
        if (isset($update['target_value'])) {
            $old = $wpdb->get_row($wpdb->prepare("SELECT target_value, target_type, notes, ai_assessment FROM {$wpdb->prefix}seom_goals WHERE id = %d", $id));
            if ($old && $old->target_type === 'percent' && floatval($old->target_value) !== $update['target_value']) {
                $old_pct = rtrim(rtrim(number_format($old->target_value, 2), '0'), '.');
                $new_pct = rtrim(rtrim(number_format($update['target_value'], 2), '0'), '.');
                // Replace old percentage in notes (e.g., "15%" → "5%")
                if (isset($update['notes']) && strpos($update['notes'], $old_pct . '%') !== false) {
                    $update['notes'] = str_replace($old_pct . '%', $new_pct . '%', $update['notes']);
                } elseif (!isset($update['notes']) && $old->notes && strpos($old->notes, $old_pct . '%') !== false) {
                    $update['notes'] = str_replace($old_pct . '%', $new_pct . '%', $old->notes);
                }
                // Clear stale AI assessment since it was based on the old target
                $update['ai_assessment'] = '';
            }
        }

        $wpdb->update($wpdb->prefix . 'seom_goals', $update, ['id' => $id]);
    } else {
        // Status-only update
        $status = sanitize_text_field($_POST['status'] ?? '');
        if (!in_array($status, ['active', 'completed', 'cancelled'])) wp_send_json_error('Invalid status.');
        $wpdb->update($wpdb->prefix . 'seom_goals', ['status' => $status], ['id' => $id]);
    }

    wp_send_json_success();
});

// Delete goal
add_action('wp_ajax_seom_delete_goal', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('Invalid.');
    $wpdb->delete($wpdb->prefix . 'seom_goals', ['id' => $id]);
    wp_send_json_success();
});

// AI feasibility check
add_action('wp_ajax_seom_check_goal_feasibility', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $metric       = sanitize_text_field($_POST['metric'] ?? '');
    $direction    = sanitize_text_field($_POST['direction'] ?? '');
    $target_value = floatval($_POST['target_value'] ?? 0);
    $target_type  = sanitize_text_field($_POST['target_type'] ?? 'percent');
    $baseline     = floatval($_POST['baseline_value'] ?? 0);
    $days         = intval($_POST['days'] ?? 30);
    $daily_limit  = intval($_POST['daily_limit'] ?? 20);

    $site_name = get_bloginfo('name');

    $metric_labels = [
        'ghost_pages'           => 'Ghost Pages (zero impressions, older than 14 days)',
        'total_clicks'          => 'Total Clicks (28-day)',
        'total_impressions'     => 'Total Impressions (28-day)',
        'avg_position'          => 'Average Position',
        'avg_ctr'               => 'Average CTR (%)',
        'page1_pages'           => 'Pages Ranking on Page 1',
        'page2_pages'           => 'Pages Ranking on Page 2',
        'pages_with_impressions'=> 'Pages With Impressions',
        'new_content_30d'       => 'Total Published Content',
        'stale_pages'           => 'Stale Pages (not refreshed in 90+ days)',
        'refreshed_this_month'  => 'Pages Refreshed This Month',
    ];
    $metric_label = $metric_labels[$metric] ?? $metric;

    // Calculate the actual target number
    if ($target_type === 'percent') {
        $change = $baseline * ($target_value / 100);
        $target_num = ($direction === 'reduce') ? $baseline - $change : $baseline + $change;
        $change_desc = "{$direction} by {$target_value}% (from {$baseline} to " . round($target_num, 1) . ")";
    } else {
        $target_num = $target_value;
        $change_desc = "{$direction} from {$baseline} to {$target_value}";
    }

    $max_refreshes = $daily_limit * $days;
    $abs_change = abs($target_num - $baseline);

    // Build metric-specific context for the AI
    if ($metric === 'new_content_30d') {
        $prompt = "You are an SEO strategist for {$site_name}, an IT training and certification website.

GOAL: {$change_desc} for metric \"{$metric_label}\" within {$days} days.
CURRENT BASELINE: {$baseline} total published pages/posts on the site as of the start date.
TARGET: " . round($target_num) . " total published pages — that means publishing approximately " . round($abs_change) . " new pages within {$days} days.

Evaluate whether publishing " . round($abs_change) . " new pages in {$days} days is feasible. Respond with EXACTLY this JSON format:
{
  \"feasibility\": \"achievable\" or \"stretch\" or \"unlikely\" or \"unrealistic\",
  \"confidence\": 1-100,
  \"reasoning\": \"2-3 sentence explanation of why\",
  \"recommendation\": \"1-2 sentence suggestion for adjusting if needed\",
  \"suggested_target\": number (your recommended target as a percentage if target_type is percent, or absolute number if absolute — use the SAME unit as the goal),
  \"suggested_days\": number (your recommended timeline in days)
}

Key considerations:
- This is about PUBLISHING NEW CONTENT, not refreshing existing pages
- A well-resourced team with AI-assisted content creation can publish 5-20+ pages per day
- " . round($abs_change) . " new pages over {$days} days = roughly " . round($abs_change / max(1, $days), 1) . " pages per day
- The site already has {$baseline} pages so this is a " . round(($abs_change / max(1, $baseline)) * 100, 2) . "% increase
- Consider whether the volume is reasonable for an IT training and certification website
- suggested_target must use the same unit as the goal: if target_type is percent, return a percentage number (e.g. 3 means 3%); if absolute, return the total page count";
    } else {
        $prompt = "You are an SEO strategist for {$site_name}, an IT training and certification website with approximately {$baseline} baseline for the metric below.

GOAL: {$change_desc} for metric \"{$metric_label}\" within {$days} days.
CAPACITY: We can refresh up to {$daily_limit} pages per day ({$max_refreshes} total in the period).
CURRENT BASELINE: {$baseline}

Evaluate this goal and respond with EXACTLY this JSON format:
{
  \"feasibility\": \"achievable\" or \"stretch\" or \"unlikely\" or \"unrealistic\",
  \"confidence\": 1-100,
  \"reasoning\": \"2-3 sentence explanation of why\",
  \"recommendation\": \"1-2 sentence suggestion for adjusting if needed\",
  \"suggested_target\": number (your recommended target as a percentage if target_type is percent, or absolute number if absolute — use the SAME unit as the goal),
  \"suggested_days\": number (your recommended timeline in days)
}

Key considerations:
- SEO changes take 2-4 weeks to show in GSC data
- Ghost pages may need content rewrites + resubmission to Search Console
- CTR improvements from meta/title changes show faster (1-2 weeks)
- Position improvements from content refresh average 2-5 positions over 30-60 days
- Not every refresh produces improvement — expect 30-40% success rate
- Large percentage targets on already-good metrics are harder than fixing poor ones
- Factor in the {$max_refreshes} refresh capacity vs the scale of change needed
- suggested_target must use the same unit as the goal: if target_type is percent, return a percentage number (e.g. 5 means 5%); if absolute, return the metric value";
    }

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) wp_send_json_error('API key not configured.');
    $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => 'Evaluate this goal feasibility. Return only JSON.'],
        ],
        'temperature' => 0.3,
    ];
    if (strpos($model, 'gpt-4') !== false) {
        $body['response_format'] = ['type' => 'json_object'];
    }

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
        'body'    => json_encode($body),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) wp_send_json_error('API error: ' . $response->get_error_message());

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $raw = trim($data['choices'][0]['message']['content'] ?? '');
    $raw = preg_replace('/^```[a-z]*\s*/m', '', $raw);
    $raw = preg_replace('/\s*```\s*$/m', '', $raw);
    $result = json_decode($raw, true);

    if (!$result) wp_send_json_error('AI returned invalid response.');

    // Normalize suggested_target: AI often returns an absolute number even when
    // the goal uses percentage. Detect and convert back to a percentage.
    if ($target_type === 'percent' && isset($result['suggested_target']) && $baseline > 0) {
        $st = floatval($result['suggested_target']);
        // If the suggested value looks like an absolute number (much larger than
        // a reasonable percentage), convert it to a percentage of baseline
        if ($st > $target_value * 10 && $st > 100) {
            $result['suggested_target'] = round(abs($st - $baseline) / $baseline * 100, 1);
        }
    }

    wp_send_json_success($result);
});

// Refresh goal progress (update current_value for all active goals)
add_action('wp_ajax_seom_refresh_goal_progress', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_page_metrics';
    $goals_table = $wpdb->prefix . 'seom_goals';
    $settings = seom_get_settings();
    $ghost_threshold = intval($settings['ghost_threshold']);

    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");
    if (!$latest_date) wp_send_json_error('No data.');

    $metrics = $wpdb->get_row($wpdb->prepare("
        SELECT
            SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
            SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
            SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions
        FROM {$table} m
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE m.date_collected = %s
        AND p.post_status = 'publish' AND p.post_type IN ('product','post','page')
    ", $ghost_threshold, $latest_date));

    // Override site-wide aggregate metrics with true GSC totals
    $site_totals = seom_get_site_totals();
    if ($site_totals['date']) {
        $metrics->total_clicks = intval($site_totals['clicks']);
        $metrics->total_impressions = intval($site_totals['impressions']);
        $metrics->avg_ctr = floatval($site_totals['ctr']);
        $metrics->avg_position = floatval($site_totals['position']);
    }

    // Additional metrics
    $cooldown_days = intval($settings['cooldown_days']);
    // Total published pages/posts/products (current count for goal progress)
    $metrics->new_content_30d = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type IN ('post','product','page') AND post_status = 'publish'
    ");
    $metrics->stale_pages = (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'last_page_refresh'
        WHERE p.post_type IN ('post','product') AND p.post_status = 'publish'
        AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        AND (pm.meta_value IS NULL OR pm.meta_value < DATE_SUB(CURDATE(), INTERVAL %d DAY))
    ", $cooldown_days));
    $metrics->refreshed_this_month = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history
        WHERE refresh_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    ");

    $metric_values = (array) $metrics;

    // Update all active goals
    $active = $wpdb->get_results("SELECT * FROM {$goals_table} WHERE status = 'active'");
    $updated = 0;
    $today = date('Y-m-d');

    foreach ($active as $goal) {
        $current = isset($metric_values[$goal->metric]) ? floatval($metric_values[$goal->metric]) : null;
        if ($current === null) continue;

        $update = ['current_value' => $current];

        // Check if deadline passed
        if ($today > $goal->deadline) {
            // Evaluate if goal was met
            if ($goal->target_type === 'percent') {
                $change_needed = $goal->baseline_value * ($goal->target_value / 100);
                $target_num = ($goal->direction === 'reduce')
                    ? $goal->baseline_value - $change_needed
                    : $goal->baseline_value + $change_needed;
            } else {
                $target_num = $goal->target_value;
            }

            $met = ($goal->direction === 'reduce') ? ($current <= $target_num) : ($current >= $target_num);
            $update['status'] = $met ? 'completed' : 'missed';
        }

        $wpdb->update($goals_table, $update, ['id' => $goal->id]);
        $updated++;
    }

    wp_send_json_success(['updated' => $updated]);
});

// Get capacity analysis for goals
add_action('wp_ajax_seom_get_capacity', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    wp_send_json_success(SEOM_Analyzer::calculate_capacity());
});

// AI auto-create monthly goals
add_action('wp_ajax_seom_auto_create_goals', function () {
    check_ajax_referer('seom_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    @set_time_limit(60);

    global $wpdb;
    $goals_table = $wpdb->prefix . 'seom_goals';
    $table = $wpdb->prefix . 'seom_page_metrics';
    $settings = seom_get_settings();
    $ghost_threshold = intval($settings['ghost_threshold']);
    $daily_limit = intval($settings['daily_limit']);

    // Get current metrics
    $latest_date = $wpdb->get_var("SELECT MAX(date_collected) FROM {$table}");
    if (!$latest_date) wp_send_json_error('No GSC data. Run collection first.');

    $m = $wpdb->get_row($wpdb->prepare("
        SELECT
            SUM(CASE WHEN m.impressions <= %d AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as ghost_pages,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(CASE WHEN m.avg_position > 0 THEN m.avg_position END) as avg_position,
            AVG(CASE WHEN m.impressions > 0 THEN m.ctr END) as avg_ctr,
            SUM(CASE WHEN m.avg_position > 0 AND m.avg_position <= 10 AND m.impressions > 0 THEN 1 ELSE 0 END) as page1_pages,
            SUM(CASE WHEN m.avg_position > 10 AND m.avg_position <= 20 AND m.impressions > 0 THEN 1 ELSE 0 END) as page2_pages,
            SUM(CASE WHEN m.impressions > 0 THEN 1 ELSE 0 END) as pages_with_impressions,
            COUNT(DISTINCT m.post_id) as total_pages
        FROM {$table} m
        JOIN {$wpdb->posts} p ON m.post_id = p.ID
        WHERE m.date_collected = %s
        AND p.post_status = 'publish' AND p.post_type IN ('product','post','page')
    ", $ghost_threshold, $latest_date));

    // Override with true GSC site-wide totals
    $st = seom_get_site_totals();
    if ($st['date']) {
        $m->total_clicks = intval($st['clicks']);
        $m->total_impressions = intval($st['impressions']);
        $m->avg_ctr = floatval($st['ctr']);
        $m->avg_position = floatval($st['position']);
    }

    // Check which metrics already have active goals this month
    $now = new \DateTime();
    $month_end = new \DateTime('last day of this month');
    $existing = $wpdb->get_col("SELECT metric FROM {$goals_table} WHERE status = 'active' AND deadline >= CURDATE()");

    // Get last month's goals for context
    $last_month_goals = $wpdb->get_results("
        SELECT metric, direction, target_value, target_type, baseline_value, current_value, status
        FROM {$goals_table}
        WHERE deadline < CURDATE() AND deadline >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
        ORDER BY deadline DESC
    ");
    $history_context = '';
    foreach ($last_month_goals as $lg) {
        $met = $lg->status === 'completed' ? 'MET' : ($lg->status === 'missed' ? 'MISSED' : $lg->status);
        $history_context .= "- {$lg->metric}: {$lg->direction} by {$lg->target_value}" . ($lg->target_type === 'percent' ? '%' : '')
            . " (baseline {$lg->baseline_value}, ended at {$lg->current_value}) — {$met}\n";
    }

    $site_name = get_bloginfo('name');
    // Content production metrics
    $cooldown_d = intval($settings['cooldown_days']);
    $m->new_content_30d = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','product','page') AND post_status = 'publish'");
    $m->stale_pages = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'last_page_refresh' WHERE p.post_type IN ('post','product') AND p.post_status = 'publish' AND p.post_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND (pm.meta_value IS NULL OR pm.meta_value < DATE_SUB(CURDATE(), INTERVAL %d DAY))", $cooldown_d));
    $m->refreshed_this_month = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seom_refresh_history WHERE refresh_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");

    $metrics_summary = "Current metrics for {$site_name}:\n"
        . "- Ghost Pages: {$m->ghost_pages}\n"
        . "- Total Clicks (28d): {$m->total_clicks}\n"
        . "- Total Impressions (28d): {$m->total_impressions}\n"
        . "- Avg Position: " . round($m->avg_position, 1) . "\n"
        . "- Avg CTR: " . round($m->avg_ctr, 4) . "%\n"
        . "- Pages on Page 1: {$m->page1_pages}\n"
        . "- Pages on Page 2: {$m->page2_pages}\n"
        . "- Pages With Impressions: {$m->pages_with_impressions}\n"
        . "- Total Pages: {$m->total_pages}\n"
        . "- Total Published Content: {$m->new_content_30d}\n"
        . "- Stale Pages (not refreshed 90+ days): {$m->stale_pages}\n"
        . "- Pages Refreshed This Month: {$m->refreshed_this_month}\n"
        . "- Daily Refresh Capacity: {$daily_limit}\n"
        . "- Days Left in Month: " . $now->diff($month_end)->days . "\n";

    if ($existing) $metrics_summary .= "\nAlready have active goals for: " . implode(', ', $existing) . " — do NOT create duplicates.\n";
    if ($history_context) $metrics_summary .= "\nPrior month goal results:\n{$history_context}\n";

    // User priority context
    $user_priority = sanitize_textarea_field($_POST['user_priority'] ?? '');
    $priority_context = '';
    if (!empty($user_priority)) {
        $priority_context = "\n\nUSER PRIORITY:\nThe user has specified this as their main focus: \"{$user_priority}\"\nWeight your goal suggestions heavily toward this priority.\n";
    }

    $prompt = "You are an SEO strategist. Based on the current metrics below, recommend 3-5 realistic monthly goals.\n\n"
        . $metrics_summary
        . $priority_context . "\n"
        . "RULES:\n"
        . "- Each goal should be achievable within the remaining days this month\n"
        . "- Goals should prioritize the biggest opportunities for improvement\n"
        . "- If prior goals were MISSED, suggest a more conservative version\n"
        . "- If prior goals were MET, suggest a stretch version or new area\n"
        . "- Do NOT suggest goals for metrics that already have active goals\n"
        . "- Use percentage targets (target_type: 'percent')\n"
        . "- Include a mix of priorities (1=Critical, 2=High, 3=Medium, 4=Low, 5=Backlog)\n"
        . "- IMPORTANT: For avg_position, ghost_pages, stale_pages, and page2_pages, the direction MUST be 'reduce' (lower number = better for SEO)\n"
        . "- For total_clicks, total_impressions, avg_ctr, page1_pages, pages_with_impressions, new_content_30d, refreshed_this_month, direction MUST be 'increase'\n"
        . "- SEO changes take 2-4 weeks to show results — be realistic\n\n"
        . "Return a JSON object with a \"goals\" array:\n"
        . "{\"goals\": [{\"metric\": \"ghost_pages\", \"direction\": \"reduce\", \"target_value\": 10, \"target_type\": \"percent\", \"priority\": 2, \"notes\": \"reason\"}]}\n"
        . "Valid metrics: ghost_pages, total_clicks, total_impressions, avg_position, avg_ctr, page1_pages, page2_pages, pages_with_impressions, new_content_30d, stale_pages, refreshed_this_month";

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) wp_send_json_error('API key not configured.');
    $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => 'Generate monthly SEO goals based on the metrics and priorities above. Return JSON with a "goals" array.'],
        ],
        'temperature' => 0.4,
    ];
    if (strpos($model, 'gpt-4') !== false) $body['response_format'] = ['type' => 'json_object'];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
        'body'    => json_encode($body),
        'timeout' => 45,
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'API error: ' . $response->get_error_message(), 'prompt_context' => $metrics_summary . $priority_context]);
    }

    $resp_body = wp_remote_retrieve_body($response);
    $data = json_decode($resp_body, true);
    $raw = trim($data['choices'][0]['message']['content'] ?? '');
    $raw = preg_replace('/^```[a-z]*\s*/m', '', $raw);
    $raw = preg_replace('/\s*```\s*$/m', '', $raw);

    $goals = json_decode($raw, true);
    // Handle {"goals": [...]} wrapper
    if (isset($goals['goals'])) $goals = $goals['goals'];
    // Handle direct array
    if (is_array($goals) && isset($goals[0]['metric'])) { /* already an array */ }
    elseif (!is_array($goals) || empty($goals)) {
        // Try regex fallback
        if (preg_match_all('/\{[^{}]*"metric"\s*:\s*"[^"]+?"[^{}]*\}/s', $raw, $matches)) {
            $goals = [];
            foreach ($matches[0] as $match) {
                $g = json_decode($match, true);
                if ($g && isset($g['metric'])) $goals[] = $g;
            }
        }
        if (empty($goals)) {
            wp_send_json_error([
                'message' => 'AI returned unparseable response.',
                'raw_response' => substr($raw, 0, 1000),
                'prompt_context' => $metrics_summary . $priority_context,
            ]);
        }
    }

    $metric_map = (array) $m;
    $month_start = date('Y-m-01');
    $month_deadline = $month_end->format('Y-m-d');
    $created = 0;

    foreach ($goals as $g) {
        $metric = sanitize_text_field($g['metric'] ?? '');
        if (empty($metric) || !isset($metric_map[$metric])) continue;
        if (in_array($metric, $existing)) continue; // skip duplicates

        $baseline = floatval($metric_map[$metric]);
        $current = $baseline; // current = baseline for current snapshot

        $wpdb->insert($goals_table, [
            'metric'         => $metric,
            'direction'      => sanitize_text_field($g['direction'] ?? 'reduce'),
            'target_value'   => floatval($g['target_value'] ?? 5),
            'target_type'    => sanitize_text_field($g['target_type'] ?? 'percent'),
            'baseline_value' => $baseline,
            'current_value'  => $current,
            'start_date'     => $month_start,
            'deadline'       => $month_deadline,
            'priority'       => max(1, min(5, intval($g['priority'] ?? 3))),
            'created_at'     => current_time('mysql'),
            'status'         => 'active',
            'ai_assessment'  => '',
            'notes'          => sanitize_text_field($g['notes'] ?? 'Auto-created by AI'),
        ]);
        $existing[] = $metric;
        $created++;
    }

    wp_send_json_success(['created' => $created, 'suggested' => count($goals), 'prompt_context' => $metrics_summary . $priority_context]);
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
        'seom_daily_goal_email',
    ];

    // Schedules for each hook
    $hook_schedules = [
        'seom_daily_collect'       => ['recurrence' => 'daily', 'time' => '01:00'],
        'seom_daily_analyze'       => ['recurrence' => 'daily', 'time' => '02:00'],
        'seom_daily_process'       => ['recurrence' => 'daily', 'time' => '06:00'],
        'seom_daily_keywords'      => ['recurrence' => 'daily', 'time' => '01:30'],
        'seom_weekly_backfill'     => ['recurrence' => 'weekly', 'time' => '03:00'],
        'seom_weekly_autocomplete' => ['recurrence' => 'weekly', 'time' => '04:00'],
        'seom_daily_goal_email'    => ['recurrence' => 'daily', 'time' => '08:00'],
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
            // Schedule the cron event for "now" — the actual work runs in a
            // separate PHP process triggered by spawn_cron() AFTER we flush the
            // JSON response. We never run the cron callback inline in the AJAX
            // request because tasks like seom_daily_collect can take 5+ minutes
            // (multiple GSC API calls + DB inserts) and would hit the gateway
            // timeout. Same pattern as seom_queue_refresh_now.
            wp_clear_scheduled_hook($hook);
            wp_schedule_single_event(time(), $hook);

            // Build response and flush it to the browser BEFORE firing cron
            $payload = [
                'success' => true,
                'data'    => [
                    'queued'  => true,
                    'hook'    => $hook,
                    'message' => 'Triggered in the background. Check the freshness banner on the Indexed Pages tab in a few minutes — and your error log if it doesn\'t complete.',
                ],
            ];
            @header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            echo wp_json_encode($payload);

            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } else {
                @ob_end_flush();
                @flush();
            }

            // Browser already has the response — kick off cron in the background
            @ignore_user_abort(true);
            @set_time_limit(15);

            if (function_exists('spawn_cron')) {
                @spawn_cron();
            }
            @wp_remote_post(site_url('/wp-cron.php?doing_wp_cron=' . microtime(true)), [
                'timeout'   => 0.5,
                'blocking'  => false,
                'sslverify' => false,
            ]);
            exit;

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
