<?php
/**
 * Dashboard
 *
 * Renders the admin interface with Overview, Queue, History, and Settings tabs.
 */

if (!defined('ABSPATH')) exit;

class SEOM_Dashboard {

    public static function render() {
        $settings = seom_get_settings();
        $nonce = wp_create_nonce('seom_nonce');
        $active_tab = sanitize_text_field($_GET['tab'] ?? 'overview');
        ?>
        <div class="wrap">
            <h1>SEO Monitor</h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=seo-monitor&tab=overview" class="nav-tab <?php echo $active_tab === 'overview' ? 'nav-tab-active' : ''; ?>">Overview</a>
                <a href="?page=seo-monitor&tab=indexed" class="nav-tab <?php echo $active_tab === 'indexed' ? 'nav-tab-active' : ''; ?>">Indexed Pages</a>
                <a href="?page=seo-monitor&tab=tracker" class="nav-tab <?php echo $active_tab === 'tracker' ? 'nav-tab-active' : ''; ?>">Performance Tracker</a>
                <a href="?page=seo-monitor&tab=queue" class="nav-tab <?php echo $active_tab === 'queue' ? 'nav-tab-active' : ''; ?>">Queue</a>
                <a href="?page=seo-monitor&tab=history" class="nav-tab <?php echo $active_tab === 'history' ? 'nav-tab-active' : ''; ?>">History</a>
                <a href="?page=seo-monitor&tab=keywords" class="nav-tab <?php echo $active_tab === 'keywords' ? 'nav-tab-active' : ''; ?>">Keywords</a>
                <a href="?page=seo-monitor&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
            </nav>

            <div class="seom-tab-content" style="margin-top:16px;">
                <?php
                switch ($active_tab) {
                    case 'indexed':  self::render_indexed($nonce); break;
                    case 'tracker':  self::render_tracker($nonce); break;
                    case 'queue':    self::render_queue($nonce); break;
                    case 'history':  self::render_history($nonce); break;
                    case 'keywords': self::render_keywords($nonce); break;
                    case 'settings': self::render_settings($nonce, $settings); break;
                    default:         self::render_overview($nonce, $settings);
                }
                ?>
            </div>
        </div>

        <style>
            /* ─── SEO Monitor Modern Theme ─── */

            /* Page header */
            .wrap > h1 {
                font-size: 26px; font-weight: 700; color: #1e293b;
                padding-bottom: 12px; margin-bottom: 0;
            }

            /* Tab navigation */
            .nav-tab-wrapper {
                border-bottom: 2px solid #e2e8f0; margin-bottom: 0; padding: 0;
            }
            .nav-tab {
                border: none; border-bottom: 2px solid transparent; background: none;
                color: #64748b; font-weight: 500; font-size: 13px; padding: 10px 18px;
                margin-bottom: -2px; transition: all 0.15s ease;
            }
            .nav-tab:hover { color: #1e293b; background: none; border-bottom-color: #cbd5e1; }
            .nav-tab-active, .nav-tab-active:hover {
                color: #2563eb; border-bottom-color: #2563eb; background: none; font-weight: 600;
            }
            .seom-tab-content { margin-top: 24px; }

            /* Cards */
            .seom-cards { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 28px; }
            .seom-card {
                background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
                padding: 20px 24px; min-width: 150px; flex: 1;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
                transition: box-shadow 0.15s ease;
            }
            .seom-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.07); }
            .seom-card h3 {
                margin: 0 0 6px; font-size: 11px; color: #94a3b8;
                text-transform: uppercase; letter-spacing: 0.8px; font-weight: 600;
            }
            .seom-card .seom-card-value { font-size: 30px; font-weight: 800; color: #1e293b; line-height: 1.2; }
            .seom-card .seom-card-sub { font-size: 12px; color: #94a3b8; margin-top: 4px; }

            /* Badges */
            .seom-badge {
                display: inline-block; padding: 3px 10px; border-radius: 12px;
                font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;
            }
            .seom-badge-a { background: #fef2f2; color: #dc2626; }
            .seom-badge-b { background: #fffbeb; color: #d97706; }
            .seom-badge-c { background: #ecfdf5; color: #059669; }
            .seom-badge-d { background: #fef2f2; color: #dc2626; }
            .seom-badge-e { background: #eef2ff; color: #4f46e5; }
            .seom-badge-m { background: #f1f5f9; color: #475569; }

            /* Status indicators */
            .seom-status-enabled { color: #059669; font-weight: 600; }
            .seom-status-disabled { color: #dc2626; font-weight: 600; }
            .seom-status-dryrun { color: #d97706; font-weight: 600; }

            /* Action bar */
            .seom-actions { margin: 16px 0; display: flex; gap: 8px; flex-wrap: wrap; }

            /* Tables */
            table.seom-table {
                border-collapse: separate; border-spacing: 0; width: 100%;
                background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
                overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }
            .seom-table th {
                padding: 10px 14px; text-align: left;
                background: #f8fafc; font-size: 11px; font-weight: 600;
                text-transform: uppercase; letter-spacing: 0.5px; color: #64748b;
                border-bottom: 2px solid #e2e8f0;
            }
            .seom-table td {
                padding: 10px 14px; text-align: left;
                border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 13px;
            }
            .seom-table tr:last-child td { border-bottom: none; }
            .seom-table tr:hover td { background: #f8fafc; }
            .seom-table a { color: #2563eb; text-decoration: none; }
            .seom-table a:hover { text-decoration: underline; }

            /* Change indicators */
            .seom-change-positive { color: #059669; font-weight: 600; }
            .seom-change-negative { color: #dc2626; font-weight: 600; }

            /* Position colors */
            .seom-pos-good { color: #059669; font-weight: 700; }
            .seom-pos-near { color: #d97706; font-weight: 700; }
            .seom-pos-far { color: #94a3b8; }
            .seom-pos-ghost { color: #cbd5e1; font-style: italic; }

            /* Buttons — modernize WP defaults inside our plugin */
            .seom-tab-content .button {
                border-radius: 6px; font-size: 13px; font-weight: 500;
                padding: 4px 12px; transition: all 0.15s ease;
            }
            .seom-tab-content .button-primary {
                background: #2563eb; border-color: #2563eb; color: #fff;
                box-shadow: 0 1px 2px rgba(37,99,235,0.2);
            }
            .seom-tab-content .button-primary:hover {
                background: #1d4ed8; border-color: #1d4ed8;
                box-shadow: 0 2px 6px rgba(37,99,235,0.3);
            }
            .seom-tab-content .button:not(.button-primary) {
                background: #fff; border-color: #e2e8f0; color: #475569;
            }
            .seom-tab-content .button:not(.button-primary):hover {
                background: #f8fafc; border-color: #cbd5e1; color: #1e293b;
            }
            .seom-tab-content .button-small { padding: 2px 10px; font-size: 12px; }

            /* Filter/type buttons */
            .seom-type-btn, .seom-filter-btn {
                border-radius: 20px !important; padding: 4px 14px !important; font-size: 12px !important;
            }
            .seom-type-btn.active, .seom-filter-btn.active {
                background: #2563eb !important; color: #fff !important;
                border-color: #2563eb !important; box-shadow: 0 1px 3px rgba(37,99,235,0.3);
            }

            /* Settings form */
            .seom-form-table { border-radius: 10px !important; }
            .seom-form-table th {
                text-align: left; padding: 14px 16px 14px 0; vertical-align: top; width: 220px;
                font-size: 13px; color: #475569; font-weight: 500;
            }
            .seom-form-table td { padding: 10px 0; }
            .seom-form-table input[type="text"],
            .seom-form-table input[type="number"],
            .seom-form-table input[type="email"],
            .seom-form-table textarea {
                border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 10px;
                transition: border-color 0.15s ease;
            }
            .seom-form-table input:focus,
            .seom-form-table textarea:focus {
                border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.1); outline: none;
            }
            .seom-form-table input[type="text"],
            .seom-form-table input[type="email"] { width: 350px; max-width: 100%; }
            .seom-form-table .description { color: #94a3b8; font-size: 12px; margin-top: 4px; }
            .seom-form-table h3 { color: #1e293b; font-size: 15px; margin: 8px 0 0; }

            /* Progress bar */
            .seom-progress {
                margin: 16px 0; padding: 14px 18px;
                background: #fff; border: 1px solid #e2e8f0; border-left: 4px solid #2563eb;
                border-radius: 0 8px 8px 0; display: none;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }

            /* Section headers in overview */
            .seom-tab-content h2 { font-size: 18px; color: #1e293b; font-weight: 700; margin-bottom: 16px; }
            .seom-tab-content h3 { font-size: 15px; color: #334155; font-weight: 600; margin-bottom: 12px; }

            /* Queue detail text */
            .seom-queue-detail { font-size: 11px; color: #94a3b8; margin-top: 3px; line-height: 1.5; }

            /* Sortable columns */
            .seom-sortable, .seom-q-sort { cursor: pointer; user-select: none; }
            .seom-sortable:hover, .seom-q-sort:hover { color: #2563eb; }
            .seom-sortable.sorted-asc::after, .seom-q-sort.sorted-asc::after { content: ' ▲'; font-size: 9px; }
            .seom-sortable.sorted-desc::after, .seom-q-sort.sorted-desc::after { content: ' ▼'; font-size: 9px; }

        </style>

        <script>
        var seom_nonce = '<?php echo $nonce; ?>';
        </script>
        <?php
    }

    // ─── Overview Tab ─────────────────────────────────────────────────────────

    private static function render_overview($nonce, $settings) {
        ?>
        <div id="seom-overview-loading">Loading overview data...</div>
        <div id="seom-overview-content" style="display:none;">
            <div class="seom-cards">
                <div class="seom-card">
                    <h3>System Status</h3>
                    <div id="seom-system-status"></div>
                </div>
                <div class="seom-card">
                    <h3>Pages Monitored</h3>
                    <div class="seom-card-value" id="seom-monitored">-</div>
                </div>
                <div class="seom-card">
                    <h3>In Queue</h3>
                    <div class="seom-card-value" id="seom-in-queue">-</div>
                </div>
                <div class="seom-card">
                    <h3>Refreshed This Month</h3>
                    <div class="seom-card-value" id="seom-refreshed-month">-</div>
                </div>
                <div class="seom-card">
                    <h3>Today</h3>
                    <div class="seom-card-value" id="seom-today-count">-</div>
                    <div class="seom-card-sub" id="seom-today-limit"></div>
                </div>
            </div>

            <div class="seom-cards" style="margin-bottom: 8px;">
                <div class="seom-card">
                    <h3>Last Data Collection</h3>
                    <div id="seom-last-collect" style="font-weight:600;">-</div>
                </div>
                <div class="seom-card">
                    <h3>Last Analysis</h3>
                    <div id="seom-last-analyze" style="font-weight:600;">-</div>
                </div>
            </div>

            <div class="seom-actions">
                <button type="button" class="button" id="seom-run-collect">Collect GSC Data Now</button>
                <button type="button" class="button" id="seom-run-analyze">Run Analysis Now</button>
            </div>
            <div id="seom-action-status" class="seom-progress"></div>

            <div id="seom-category-breakdown"></div>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:24px;">
                <h3 style="margin:0;">Recent Refresh Results <small style="color:#94a3b8;font-weight:normal;">(last 14 days, top 30)</small></h3>
                <a href="?page=seo-monitor&tab=tracker" class="button">View All in Performance Tracker &rarr;</a>
            </div>
            <table class="seom-table" id="seom-recent-table">
                <thead>
                    <tr><th>Page</th><th>Type</th><th>Days Ago</th><th>Clicks Before</th><th>Clicks Now</th><th>Change</th><th>Pos Before</th><th>Pos Now</th><th>Trend</th></tr>
                </thead>
                <tbody id="seom-recent-body">
                    <tr><td colspan="9" style="color:#999;">No recent refreshes. Process some pages and collect GSC data to see changes.</td></tr>
                </tbody>
            </table>

            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:32px;">
                <div style="flex:1;min-width:400px;">
                    <h3>Top Improvements (30d)</h3>
                    <table class="seom-table" id="seom-improvements-table">
                        <thead>
                            <tr><th>Page</th><th>Clicks Before</th><th>Clicks 30d</th><th>Change</th></tr>
                        </thead>
                        <tbody id="seom-improvements-body">
                            <tr><td colspan="4" style="color:#999;">Pending 30-day backfill data.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div style="flex:1;min-width:400px;">
                    <h3>Declines After Refresh (30d)</h3>
                    <table class="seom-table" id="seom-declines-table">
                        <thead>
                            <tr><th>Page</th><th>Clicks Before</th><th>Clicks 30d</th><th>Change</th></tr>
                        </thead>
                        <tbody id="seom-declines-body">
                            <tr><td colspan="4" style="color:#999;">No declines detected.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            function loadOverview() {
                $.post(ajaxurl, { action: 'seom_get_overview', nonce: seom_nonce }, function(resp) {
                    if (!resp.success) { $('#seom-overview-loading').text('Error loading overview.'); return; }
                    var d = resp.data;
                    $('#seom-overview-loading').hide();
                    $('#seom-overview-content').show();

                    $('#seom-monitored').text(d.monitored);
                    $('#seom-in-queue').text(d.in_queue);
                    $('#seom-refreshed-month').text(d.refreshed_month);
                    $('#seom-today-count').text(d.today_count);
                    $('#seom-today-limit').text('of ' + d.daily_limit + ' daily limit');
                    $('#seom-last-collect').text(d.last_collect);
                    $('#seom-last-analyze').text(d.last_analyze);

                    // Status
                    if (!d.enabled) {
                        $('#seom-system-status').html('<span class="seom-status-disabled">Disabled</span>');
                    } else if (d.dry_run) {
                        $('#seom-system-status').html('<span class="seom-status-dryrun">Dry Run Mode</span><br><small>Analysis only, no content changes</small>');
                    } else {
                        $('#seom-system-status').html('<span class="seom-status-enabled">Active</span>');
                    }

                    // Category breakdown
                    if (d.categories && d.categories.length > 0) {
                        var html = '<h3>Queue by Category</h3><div style="display:flex;gap:12px;flex-wrap:wrap;">';
                        var labels = {A:'Ghost Pages',B:'CTR Fix',C:'Near Wins',D:'Declining',E:'Visible/Ignored',M:'Manual'};
                        d.categories.forEach(function(c) {
                            html += '<span class="seom-badge seom-badge-' + c.category.toLowerCase() + '" style="font-size:13px;padding:6px 12px;">'
                                + (labels[c.category] || c.category) + ': ' + c.cnt + '</span>';
                        });
                        html += '</div>';
                        $('#seom-category-breakdown').html(html);
                    }

                    // Recent changes (last 14 days — near real-time feedback)
                    if (d.recent_changes && d.recent_changes.length > 0) {
                        var tbody = $('#seom-recent-body').empty();
                        d.recent_changes.forEach(function(r) {
                            var clickDiff = parseInt(r.click_change || 0);
                            var clickClass = clickDiff > 0 ? 'seom-change-positive' : (clickDiff < 0 ? 'seom-change-negative' : '');
                            var posDiff = parseFloat(r.position_change || 0);
                            var posClass = posDiff < -0.1 ? 'seom-change-positive' : (posDiff > 0.1 ? 'seom-change-negative' : '');
                            var isPosImproved = posDiff < -0.3;

                            var trend = '';
                            if (clickDiff > 0 && isPosImproved) trend = '<span class="seom-change-positive" style="font-weight:700;">&#9650;&#9650; Strong</span>';
                            else if (clickDiff > 0) trend = '<span class="seom-change-positive">&#9650; Clicks Up</span>';
                            else if (isPosImproved && clickDiff >= 0) trend = '<span class="seom-change-positive">&#9650; Ranking Up</span>';
                            else if (clickDiff < 0 && posDiff > 0.3) trend = '<span class="seom-change-negative" style="font-weight:700;">&#9660;&#9660; Declining</span>';
                            else if (clickDiff < 0) trend = '<span class="seom-change-negative">&#9660; Clicks Down</span>';
                            else if (posDiff > 0.3) trend = '<span class="seom-change-negative">&#9660; Ranking Down</span>';
                            else if (parseInt(r.days_since) < 5) trend = '<span style="color:#b45309;">&#9203; Too early</span>';
                            else trend = '<span style="color:#999;">&#9654; Flat</span>';

                            tbody.append('<tr>' +
                                '<td><a href="/?p=' + r.post_id + '" target="_blank">' + r.post_title + '</a></td>' +
                                '<td>' + r.refresh_type + '</td>' +
                                '<td>' + r.days_since + 'd</td>' +
                                '<td>' + (r.clicks_before || 0) + '</td>' +
                                '<td>' + (r.clicks_now || 0) + '</td>' +
                                '<td class="' + clickClass + '">' + (clickDiff > 0 ? '+' : '') + clickDiff + '</td>' +
                                '<td>' + parseFloat(r.position_before || 0).toFixed(1) + '</td>' +
                                '<td class="' + posClass + '">' + parseFloat(r.position_now || 0).toFixed(1) + '</td>' +
                                '<td>' + trend + '</td>' +
                            '</tr>');
                        });
                    }

                    // 30-day improvements
                    function render30dTable(rows, tbodyId) {
                        if (!rows || !rows.length) return;
                        var tbody = $(tbodyId).empty();
                        rows.forEach(function(r) {
                            var diff = parseInt(r.click_change || 0);
                            var cls = diff > 0 ? 'seom-change-positive' : (diff < 0 ? 'seom-change-negative' : '');
                            tbody.append('<tr>' +
                                '<td>' + r.post_title + '</td>' +
                                '<td>' + (r.clicks_before || 0) + '</td>' +
                                '<td>' + (r.clicks_after_30d || 0) + '</td>' +
                                '<td class="' + cls + '">' + (diff > 0 ? '+' : '') + diff + '</td>' +
                            '</tr>');
                        });
                    }
                    render30dTable(d.improvements, '#seom-improvements-body');
                    render30dTable(d.declines, '#seom-declines-body');
                });
            }

            loadOverview();

            $('#seom-run-collect').click(function() {
                var btn = $(this).prop('disabled', true).text('Collecting...');
                var $status = $('#seom-action-status').show();
                $status.text('Phase 1: Fetching data from Google Search Console...');

                function runBatch(batchPage, totalPosts, pagesInGsc) {
                    $.post(ajaxurl, { action: 'seom_run_collect', nonce: seom_nonce, batch_page: batchPage }, function(resp) {
                        if (!resp.success) {
                            btn.prop('disabled', false).text('Collect GSC Data Now');
                            $status.text('Error: ' + (resp.data || 'Unknown'));
                            return;
                        }

                        var d = resp.data;

                        if (d.phase === 'gsc_fetched') {
                            // Phase 1 done, start saving batches
                            var serpInfo = d.serp_pages_found ? d.serp_pages_found + ' with SERP features' : (d.serp_error ? 'SERP error: ' + d.serp_error : 'No SERP data found');
                            $status.text('Phase 1 complete: ' + d.pages_in_gsc + ' pages in GSC, ' + serpInfo + '. Saving metrics for ' + d.total_posts + ' posts...');
                            runBatch(1, d.total_posts, d.pages_in_gsc);
                        } else if (d.phase === 'saving') {
                            $status.text('Saving... ' + d.processed + ' of ' + d.total_posts + ' posts processed.');
                            if (d.has_more) {
                                runBatch(batchPage + 1, d.total_posts, pagesInGsc);
                            } else {
                                btn.prop('disabled', false).text('Collect GSC Data Now');
                                var unmatched = pagesInGsc - d.total_posts;
                                var extra = unmatched > 0 ? ' (' + unmatched + ' GSC URLs are archives/tags/feeds not tracked)' : '';
                                $status.text('Done! Tracked ' + d.total_posts + ' posts/products/pages. ' + pagesInGsc + ' total URLs in GSC.' + extra);
                                loadOverview();
                            }
                        }
                    }).fail(function() {
                        btn.prop('disabled', false).text('Collect GSC Data Now');
                        $status.text('Server error on batch ' + batchPage + '. Try again.');
                    });
                }

                runBatch(0, 0, 0);
            });

            $('#seom-run-analyze').click(function() {
                var btn = $(this).prop('disabled', true).text('Analyzing...');
                $('#seom-action-status').show().text('Running priority analysis...');
                $.post(ajaxurl, { action: 'seom_run_analyze', nonce: seom_nonce }, function(resp) {
                    btn.prop('disabled', false).text('Run Analysis Now');
                    if (resp.success) {
                        $('#seom-action-status').text('Done! Scored ' + resp.data.scored + ' pages, queued ' + resp.data.queued + '.');
                        loadOverview();
                    } else {
                        $('#seom-action-status').text('Error: ' + (resp.data || 'Unknown'));
                    }
                }).fail(function() { btn.prop('disabled', false).text('Run Analysis Now'); $('#seom-action-status').text('Server error.'); });
            });
        });
        </script>
        <?php
    }

    // ─── Queue Tab ────────────────────────────────────────────────────────────

    // ─── Indexed Pages Tab ──────────────────────────────────────────────────

    private static function render_indexed($nonce) {
        ?>
        <h2>Indexed Pages</h2>

        <div style="margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <strong>Type:</strong>
            <button type="button" class="button seom-type-btn active" data-type="product">Products</button>
            <button type="button" class="button seom-type-btn" data-type="post">Blog Posts</button>
            <button type="button" class="button seom-type-btn" data-type="page">Pages</button>
            <span style="margin:0 8px; color:#ccc;">|</span>
            <strong>Filter:</strong>
            <button type="button" class="button seom-filter-btn active" data-filter="all">All</button>
            <button type="button" class="button seom-filter-btn" data-filter="underperforming">Underperforming</button>
            <button type="button" class="button seom-filter-btn" data-filter="ghost">Ghost</button>
            <button type="button" class="button seom-filter-btn" data-filter="page2">Near Wins (Page 2)</button>
            <button type="button" class="button seom-filter-btn" data-filter="low_ctr">Low CTR</button>
            <button type="button" class="button seom-filter-btn" data-filter="page1">Page 1</button>
            <button type="button" class="button seom-filter-btn" data-filter="limited">Limited Visibility</button>
            <span style="margin:0 4px; color:#e2e8f0;">|</span>
            <button type="button" class="button seom-filter-btn" data-filter="top_performers" style="color:#059669;">Top Performers</button>
            <button type="button" class="button seom-filter-btn" data-filter="stars" style="color:#d97706;">Stars</button>
        </div>
        <div id="seom-filter-desc" style="font-size:12px; color:#64748b; margin:-8px 0 16px; padding-left:2px;"></div>

        <div class="seom-cards" id="seom-indexed-summary" style="display:none;">
            <div class="seom-card">
                <h3>Total Pages</h3>
                <div class="seom-card-value" id="si-total">-</div>
            </div>
            <div class="seom-card">
                <h3>With Impressions</h3>
                <div class="seom-card-value" id="si-with-impressions">-</div>
            </div>
            <div class="seom-card">
                <h3>Ghost Pages</h3>
                <div class="seom-card-value" id="si-ghost">-</div>
                <div class="seom-card-sub">Zero impressions</div>
            </div>
            <div class="seom-card">
                <h3>Total Clicks (28d)</h3>
                <div class="seom-card-value" id="si-clicks">-</div>
            </div>
            <div class="seom-card">
                <h3>Total Impressions (28d)</h3>
                <div class="seom-card-value" id="si-impressions">-</div>
            </div>
            <div class="seom-card">
                <h3>Avg Position</h3>
                <div class="seom-card-value" id="si-avg-pos">-</div>
            </div>
        </div>

        <div id="seom-indexed-loading">Select a type above to view indexed pages.</div>

        <table class="seom-table" id="seom-indexed-table" style="display:none;">
            <thead>
                <tr>
                    <th class="seom-sortable" data-sort="post_title" style="cursor:pointer;">Page</th>
                    <th class="seom-sortable" data-sort="clicks" style="cursor:pointer;width:80px;">Clicks</th>
                    <th class="seom-sortable" data-sort="impressions" style="cursor:pointer;width:100px;">Impressions</th>
                    <th class="seom-sortable" data-sort="ctr" style="cursor:pointer;width:60px;">CTR</th>
                    <th class="seom-sortable" data-sort="avg_position" style="cursor:pointer;width:80px;">Position</th>
                    <th style="width:80px;">Status</th>
                    <th style="width:90px;">Last Refresh</th>
                    <th style="width:170px;">Actions</th>
                </tr>
            </thead>
            <tbody id="seom-indexed-body"></tbody>
        </table>

        <div id="seom-indexed-pagination" style="margin-top:12px;"></div>

        <style>
            .seom-type-btn.active, .seom-filter-btn.active { background: #2271b1; color: #fff; border-color: #2271b1; }
            .seom-sortable:hover { color: #2271b1; }
            .seom-sortable.sorted-asc::after { content: ' ▲'; font-size: 10px; }
            .seom-sortable.sorted-desc::after { content: ' ▼'; font-size: 10px; }
            .seom-pos-good { color: #16a34a; font-weight: 600; }
            .seom-pos-near { color: #b45309; font-weight: 600; }
            .seom-pos-far { color: #dc2626; }
            .seom-pos-ghost { color: #999; font-style: italic; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var currentType = 'product';
            var currentSort = 'clicks';
            var currentOrder = 'DESC';
            var currentFilter = 'all';
            var currentPage = 1;

            function loadIndexed(page) {
                currentPage = page || 1;
                $('#seom-indexed-loading').show().text('Loading...');
                $('#seom-indexed-table').hide();
                $('#seom-indexed-pagination').empty();

                $.post(ajaxurl, {
                    action: 'seom_get_indexed', nonce: seom_nonce,
                    post_type: currentType, sort: currentSort, order: currentOrder,
                    filter: currentFilter, page: currentPage
                }, function(resp) {
                    $('#seom-indexed-loading').hide();
                    if (!resp.success) return;

                    var d = resp.data;
                    var s = d.summary;

                    // Summary cards — update values and labels based on active filter
                    var filterLabels = {
                        all: '', underperforming: 'Underperforming', ghost: 'Ghost',
                        page1: 'Page 1', page2: 'Page 2 (Near Wins)', low_ctr: 'Low CTR',
                        limited: 'Limited Visibility',
                        top_performers: 'Top Performers',
                        stars: 'Stars'
                    };
                    var label = filterLabels[currentFilter] || '';
                    var suffix = label ? ' (' + label + ')' : '';

                    $('#si-total').text(s.total_pages);
                    $('#si-total').closest('.seom-card').find('h3').text('Total Pages' + suffix);
                    $('#si-with-impressions').text(s.pages_with_impressions);
                    $('#si-ghost').text(s.ghost_pages);
                    $('#si-clicks').text(parseInt(s.total_clicks || 0).toLocaleString());
                    $('#si-impressions').text(parseInt(s.total_impressions || 0).toLocaleString());
                    $('#si-avg-pos').text(parseFloat(s.avg_position || 0).toFixed(1));
                    $('#seom-indexed-summary').show();

                    // Table
                    var tbody = $('#seom-indexed-body').empty();
                    if (!d.rows.length) {
                        tbody.append('<tr><td colspan="8" style="color:#94a3b8;">No data matching this filter. Try a different filter or run data collection.</td></tr>');
                        $('#seom-indexed-table').show();
                        return;
                    }

                    d.rows.forEach(function(r) {
                        var pos = parseFloat(r.avg_position);
                        var imp = parseInt(r.impressions);
                        var posClass = 'seom-pos-far';
                        var status = '';

                        if (imp === 0 && !r.date_collected) {
                            posClass = 'seom-pos-ghost';
                            status = '<span class="seom-badge seom-badge-a" style="background:#fee2e2;color:#991b1b;">Not Tracked</span>';
                        } else if (imp === 0) {
                            posClass = 'seom-pos-ghost';
                            status = '<span class="seom-badge seom-badge-a">Ghost</span>';
                        } else if (pos > 0 && pos <= 10) {
                            posClass = 'seom-pos-good';
                            status = '<span style="color:#16a34a;font-weight:600;">Page 1</span>';
                        } else if (pos > 10 && pos <= 20) {
                            posClass = 'seom-pos-near';
                            status = '<span style="color:#b45309;font-weight:600;">Page 2</span>';
                        } else if (pos > 20) {
                            status = '<span style="color:#999;">Page ' + Math.ceil(pos / 10) + '</span>';
                        }

                        var editUrl = '<?php echo admin_url('post.php?action=edit&post='); ?>' + r.post_id;
                        var viewUrl = r.url;
                        var lastRefresh = r.last_refresh ? r.last_refresh.substring(0, 10) : '<span style="color:#999;">Never</span>';

                        var actions = '';
                        if (currentType === 'page') {
                            actions = '<span style="color:#94a3b8;font-size:11px;">Metrics only</span>';
                        } else if (r.in_queue) {
                            actions = '<span style="color:#b45309;font-size:12px;">In Queue</span>';
                        } else {
                            actions = '<button class="button button-small seom-queue-btn" data-id="' + r.post_id + '" data-type="full" title="Full content refresh">Refresh</button> '
                                + '<button class="button button-small seom-queue-btn" data-id="' + r.post_id + '" data-type="meta_only" title="Meta description + keyword only">Meta Only</button>';
                        }

                        tbody.append('<tr>' +
                            '<td><a href="' + editUrl + '" target="_blank">' + r.post_title + '</a>' +
                                '<br><small style="color:#94a3b8;"><a href="' + viewUrl + '" target="_blank" style="color:#94a3b8;">' + viewUrl + '</a></small>' +
                            '</td>' +
                            '<td>' + parseInt(r.clicks).toLocaleString() + '</td>' +
                            '<td>' + parseInt(r.impressions).toLocaleString() + '</td>' +
                            '<td>' + parseFloat(r.ctr).toFixed(1) + '%</td>' +
                            '<td class="' + posClass + '">' + (pos > 0 ? pos.toFixed(1) : '-') + '</td>' +
                            '<td>' + status + '</td>' +
                            '<td>' + lastRefresh + '</td>' +
                            '<td>' + actions + '</td>' +
                        '</tr>');
                    });

                    $('#seom-indexed-table').show();

                    // Sort indicators
                    $('.seom-sortable').removeClass('sorted-asc sorted-desc');
                    $('[data-sort="' + currentSort + '"]').addClass(currentOrder === 'ASC' ? 'sorted-asc' : 'sorted-desc');

                    // Pagination
                    var totalPages = Math.ceil(d.total / 50);
                    var pag = $('#seom-indexed-pagination').empty();
                    if (totalPages > 1) {
                        for (var i = 1; i <= Math.min(totalPages, 20); i++) {
                            var cls = i === d.page ? 'button button-primary' : 'button';
                            pag.append('<button class="' + cls + ' seom-idx-page" data-page="' + i + '">' + i + '</button> ');
                        }
                        if (totalPages > 20) pag.append('<span>... ' + totalPages + ' pages</span>');
                    }
                });
            }

            // Type toggle
            $('.seom-type-btn').click(function() {
                $('.seom-type-btn').removeClass('active');
                $(this).addClass('active');
                currentType = $(this).data('type');
                currentSort = 'clicks';
                currentOrder = 'DESC';
                loadIndexed(1);
            });

            // Sortable columns
            $(document).on('click', '.seom-sortable', function() {
                var col = $(this).data('sort');
                if (currentSort === col) {
                    currentOrder = currentOrder === 'DESC' ? 'ASC' : 'DESC';
                } else {
                    currentSort = col;
                    currentOrder = col === 'post_title' ? 'ASC' : 'DESC';
                }
                loadIndexed(1);
            });

            // Filter toggle
            var filterDescriptions = {
                all: 'Showing all tracked pages.',
                underperforming: 'All problem categories combined: Ghost + Near Wins + Low CTR + high impressions with no clicks.',
                ghost: 'Pages with zero impressions in the last 28 days — Google is not showing these in any search results.',
                page2: 'Ranking position 11–20 with 50+ impressions. These are close to breaking into page 1 — highest ROI for content refresh.',
                low_ctr: 'Ranking on page 1 (position 1–10) with 100+ impressions but click-through rate below 1.5%. Title and meta description need improvement.',
                page1: 'Ranking on page 1 (position 1–10) with impressions. These are performing well — monitor for changes.',
                limited: 'Has some visibility but not enough to matter: fewer than 100 impressions, or ranking position 30+ (page 3 and beyond).',
                top_performers: 'Pages driving real traffic: 5+ clicks in the last 28 days. These are protected from automated refresh — if it\'s working, don\'t fix it.',
                stars: 'Your highest-traffic pages: 15+ clicks and 200+ impressions. These are the pages carrying your site — monitor closely and protect at all costs.'
            };

            function updateFilterDesc() {
                $('#seom-filter-desc').text(filterDescriptions[currentFilter] || '');
            }
            updateFilterDesc();

            $('.seom-filter-btn').click(function() {
                $('.seom-filter-btn').removeClass('active');
                $(this).addClass('active');
                currentFilter = $(this).data('filter');
                updateFilterDesc();
                loadIndexed(1);
            });

            // Add to queue
            $(document).on('click', '.seom-queue-btn', function() {
                var btn = $(this);
                var postId = btn.data('id');
                var refreshType = btn.data('type');
                btn.prop('disabled', true).text('...');

                $.post(ajaxurl, {
                    action: 'seom_add_to_queue', nonce: seom_nonce,
                    post_id: postId, refresh_type: refreshType, priority: 99
                }, function(resp) {
                    if (resp.success) {
                        btn.closest('td').html('<span style="color:#16a34a;font-size:12px;">Queued!</span>');
                    } else {
                        btn.prop('disabled', false).text(refreshType === 'full' ? 'Refresh' : 'Meta Only');
                        alert(resp.data || 'Error');
                    }
                });
            });

            // Pagination
            $(document).on('click', '.seom-idx-page', function() { loadIndexed($(this).data('page')); });

            // Auto-load products
            loadIndexed(1);
        });
        </script>
        <?php
    }

    // ─── Performance Tracker Tab ─────────────────────────────────────────────

    private static function render_tracker($nonce) {
        ?>
        <h2>Performance Tracker</h2>
        <p>Track how recently refreshed pages are performing compared to before the refresh. Updates every time you collect GSC data.</p>

        <div style="margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <strong>Type:</strong>
            <button type="button" class="button seom-tracker-type active" data-type="all">All</button>
            <button type="button" class="button seom-tracker-type" data-type="product">Products</button>
            <button type="button" class="button seom-tracker-type" data-type="post">Blog Posts</button>
            <button type="button" class="button seom-tracker-type" data-type="page">Pages</button>
            <span style="margin:0 4px; color:#e2e8f0;">|</span>
            <strong>Period:</strong>
            <button type="button" class="button seom-tracker-days active" data-days="14">14 Days</button>
            <button type="button" class="button seom-tracker-days" data-days="30">30 Days</button>
            <button type="button" class="button seom-tracker-days" data-days="60">60 Days</button>
            <button type="button" class="button seom-tracker-days" data-days="90">90 Days</button>
        </div>

        <div id="seom-tracker-loading">Loading...</div>

        <div id="seom-tracker-content" style="display:none;">
            <div class="seom-cards">
                <div class="seom-card">
                    <h3>Total Refreshed</h3>
                    <div class="seom-card-value" id="st-total">-</div>
                </div>
                <div class="seom-card">
                    <h3>Improving</h3>
                    <div class="seom-card-value seom-change-positive" id="st-improving">-</div>
                </div>
                <div class="seom-card">
                    <h3>Declining</h3>
                    <div class="seom-card-value seom-change-negative" id="st-declining">-</div>
                </div>
                <div class="seom-card">
                    <h3>No Change Yet</h3>
                    <div class="seom-card-value" id="st-flat">-</div>
                </div>
                <div class="seom-card">
                    <h3>Click Trend</h3>
                    <div class="seom-card-value" id="st-trend">-</div>
                    <div class="seom-card-sub" id="st-trend-detail"></div>
                </div>
            </div>

            <table class="seom-table" id="seom-tracker-table">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th>Type</th>
                        <th>Refreshed</th>
                        <th>Days</th>
                        <th>Clicks Before</th>
                        <th>Clicks Now</th>
                        <th>Click Change</th>
                        <th>Pos Before</th>
                        <th>Pos Now</th>
                        <th>Pos Change</th>
                        <th>Trend</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="seom-tracker-body"></tbody>
            </table>

            <div id="seom-tracker-pagination" style="margin-top:12px;"></div>
        </div>

        <!-- All Pages Performance Trends -->
        <div style="margin-top:40px; border-top:2px solid #e2e8f0; padding-top:24px;">
            <h2>All Pages — Performance Trends</h2>
            <p style="color:#64748b;">Compares the latest GSC collection vs the previous collection for ALL pages — not just refreshed ones. Shows natural traffic changes.</p>

            <div style="margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <strong>Type:</strong>
                <button type="button" class="button seom-trends-type active" data-type="all">All</button>
                <button type="button" class="button seom-trends-type" data-type="product">Products</button>
                <button type="button" class="button seom-trends-type" data-type="post">Blog Posts</button>
                <button type="button" class="button seom-trends-type" data-type="page">Pages</button>
                <span style="margin:0 4px; color:#e2e8f0;">|</span>
                <strong>Show:</strong>
                <button type="button" class="button seom-trends-filter active" data-filter="all">All</button>
                <button type="button" class="button seom-trends-filter" data-filter="improving">Improving</button>
                <button type="button" class="button seom-trends-filter" data-filter="declining">Declining</button>
                <button type="button" class="button seom-trends-filter" data-filter="new_traffic">New Traffic</button>
                <button type="button" class="button seom-trends-filter" data-filter="lost_traffic">Lost Traffic</button>
            </div>

            <div id="seom-trends-loading" style="color:#64748b;">Loading trends...</div>

            <div id="seom-trends-content" style="display:none;">
                <div class="seom-cards">
                    <div class="seom-card">
                        <h3>Pages Tracked</h3>
                        <div class="seom-card-value" id="spt-total">-</div>
                    </div>
                    <div class="seom-card">
                        <h3>Gaining Clicks</h3>
                        <div class="seom-card-value seom-change-positive" id="spt-improving">-</div>
                    </div>
                    <div class="seom-card">
                        <h3>Losing Clicks</h3>
                        <div class="seom-card-value seom-change-negative" id="spt-declining">-</div>
                    </div>
                    <div class="seom-card">
                        <h3>No Change</h3>
                        <div class="seom-card-value" id="spt-flat">-</div>
                    </div>
                    <div class="seom-card">
                        <h3>Click Trend</h3>
                        <div class="seom-card-value" id="spt-trend">-</div>
                        <div class="seom-card-sub" id="spt-trend-detail"></div>
                    </div>
                </div>

                <div id="spt-date-range" style="font-size:12px; color:#94a3b8; margin-bottom:8px;"></div>

                <table class="seom-table" id="seom-trends-table">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th style="width:50px;">Type</th>
                            <th style="width:80px;">Clicks Now</th>
                            <th style="width:80px;">Clicks Prev</th>
                            <th style="width:80px;">Change</th>
                            <th style="width:80px;">Pos Now</th>
                            <th style="width:80px;">Pos Prev</th>
                            <th style="width:80px;">Pos Change</th>
                            <th style="width:80px;">Trend</th>
                        </tr>
                    </thead>
                    <tbody id="seom-trends-body"></tbody>
                </table>
                <div id="seom-trends-pagination" style="margin-top:12px;"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var trackerDays = 14;
            var trackerType = 'all';
            var trackerPage = 1;

            function getTrend(clickDiff, posDiff, daysSince) {
                var isPosImproved = posDiff < -0.3;
                var isPosDeclined = posDiff > 0.3;

                if (clickDiff > 0 && isPosImproved) return '<span class="seom-change-positive" style="font-weight:700;">&#9650;&#9650; Strong</span>';
                if (clickDiff > 0) return '<span class="seom-change-positive">&#9650; Clicks Up</span>';
                if (isPosImproved && clickDiff >= 0) return '<span class="seom-change-positive">&#9650; Ranking Up</span>';
                if (clickDiff < 0 && isPosDeclined) return '<span class="seom-change-negative" style="font-weight:700;">&#9660;&#9660; Declining</span>';
                if (clickDiff < 0) return '<span class="seom-change-negative">&#9660; Clicks Down</span>';
                if (isPosDeclined) return '<span class="seom-change-negative">&#9660; Ranking Down</span>';
                if (daysSince < 5) return '<span style="color:#d97706;">&#9203; Too early</span>';
                return '<span style="color:#94a3b8;">&#9654; Flat</span>';
            }

            function loadTracker(page) {
                trackerPage = page || 1;
                $('#seom-tracker-loading').show().text('Loading...');
                $('#seom-tracker-content').hide();
                $('#seom-tracker-pagination').empty();

                $.post(ajaxurl, {
                    action: 'seom_get_tracker', nonce: seom_nonce,
                    page: trackerPage, days: trackerDays, post_type: trackerType
                }, function(resp) {
                    $('#seom-tracker-loading').hide();
                    $('#seom-tracker-content').show();

                    if (!resp.success) return;
                    var d = resp.data;

                    // Summary
                    $('#st-total').text(d.summary.total);
                    $('#st-improving').text(d.summary.improving);
                    $('#st-declining').text(d.summary.declining);

                    // Click trend
                    var before = d.summary.total_clicks_before || 0;
                    var now = d.summary.total_clicks_now || 0;
                    var diff = now - before;
                    var pct = before > 0 ? Math.round((diff / before) * 100) : 0;
                    var trendText = (diff > 0 ? '+' : '') + diff;
                    var trendClass = diff > 0 ? 'seom-change-positive' : (diff < 0 ? 'seom-change-negative' : '');
                    var trendIcon = diff > 0 ? '&#9650; ' : (diff < 0 ? '&#9660; ' : '');
                    $('#st-trend').html('<span class="' + trendClass + '">' + trendIcon + trendText + '</span>');
                    $('#st-trend-detail').text(before + ' before → ' + now + ' now (' + (pct > 0 ? '+' : '') + pct + '%)');
                    $('#st-flat').text(d.summary.flat);

                    // Table
                    var tbody = $('#seom-tracker-body').empty();
                    if (!d.rows.length) {
                        tbody.html('<tr><td colspan="12" style="color:#94a3b8;">No refreshes in this period, or no GSC data collected yet.</td></tr>');
                        return;
                    }

                    d.rows.forEach(function(r) {
                        var clickDiff = parseInt(r.click_change || 0);
                        var posDiff = parseFloat(r.position_change || 0);
                        var clickClass = clickDiff > 0 ? 'seom-change-positive' : (clickDiff < 0 ? 'seom-change-negative' : '');
                        var posClass = posDiff < -0.1 ? 'seom-change-positive' : (posDiff > 0.1 ? 'seom-change-negative' : '');
                        var posStr = posDiff !== 0 ? (posDiff > 0 ? '+' : '') + posDiff.toFixed(1) : '0.0';
                        var editUrl = '<?php echo admin_url("post.php?action=edit&post="); ?>' + r.post_id;

                        tbody.append('<tr>' +
                            '<td><a href="' + editUrl + '" target="_blank">' + r.post_title + '</a>' +
                                '<br><small style="color:#94a3b8;">' + (r.post_type || '') + '</small></td>' +
                            '<td>' + (r.refresh_type || 'full') + '</td>' +
                            '<td>' + (r.refresh_date || '').substring(0, 10) + '</td>' +
                            '<td>' + r.days_since + '</td>' +
                            '<td>' + (r.clicks_before || 0) + '</td>' +
                            '<td>' + (r.clicks_now || 0) + '</td>' +
                            '<td class="' + clickClass + '">' + (clickDiff > 0 ? '+' : '') + clickDiff + '</td>' +
                            '<td>' + parseFloat(r.position_before || 0).toFixed(1) + '</td>' +
                            '<td class="' + posClass + '">' + parseFloat(r.position_now || 0).toFixed(1) + '</td>' +
                            '<td class="' + posClass + '">' + posStr + '</td>' +
                            '<td>' + getTrend(clickDiff, posDiff, parseInt(r.days_since)) + '</td>' +
                            '<td>' +
                                '<a href="' + editUrl + '" class="button button-small" target="_blank">Edit</a> ' +
                                '<a href="/?p=' + r.post_id + '" class="button button-small" target="_blank">View</a>' +
                            '</td>' +
                        '</tr>');
                    });

                    // Pagination
                    if (d.pages > 1) {
                        var pag = $('#seom-tracker-pagination');
                        for (var i = 1; i <= d.pages; i++) {
                            var cls = i === d.page ? 'button button-primary' : 'button';
                            pag.append('<button class="' + cls + ' seom-tracker-page" data-page="' + i + '">' + i + '</button> ');
                        }
                    }
                });
            }

            // Period toggle
            $('.seom-tracker-type').click(function() {
                $('.seom-tracker-type').removeClass('active');
                $(this).addClass('active');
                trackerType = $(this).data('type');
                loadTracker(1);
            });

            $('.seom-tracker-days').click(function() {
                $('.seom-tracker-days').removeClass('active');
                $(this).addClass('active');
                trackerDays = parseInt($(this).data('days'));
                loadTracker(1);
            });

            // Pagination
            $(document).on('click', '.seom-tracker-page', function() {
                loadTracker(parseInt($(this).data('page')));
            });

            loadTracker(1);

            // ═══ All Pages Trends ═══
            var trendsType = 'all', trendsFilter = 'all', trendsPage = 1;

            function loadTrends(pg) {
                trendsPage = pg || 1;
                $('#seom-trends-loading').show().text('Loading trends...');
                $('#seom-trends-content').hide();
                $('#seom-trends-pagination').empty();

                $.post(ajaxurl, {
                    action: 'seom_get_page_trends', nonce: seom_nonce,
                    post_type: trendsType, filter: trendsFilter, page: trendsPage
                }, function(resp) {
                    $('#seom-trends-loading').hide();
                    $('#seom-trends-content').show();

                    if (!resp.success) return;
                    var d = resp.data;
                    var s = d.summary;

                    $('#spt-total').text(s.total);
                    $('#spt-improving').text(s.improving);
                    $('#spt-declining').text(s.declining);
                    $('#spt-flat').text(s.flat);

                    // Click trend
                    var diff = s.total_clicks_now - s.total_clicks_prev;
                    var pct = s.total_clicks_prev > 0 ? Math.round((diff / s.total_clicks_prev) * 100) : 0;
                    var trendClass = diff > 0 ? 'seom-change-positive' : (diff < 0 ? 'seom-change-negative' : '');
                    var trendIcon = diff > 0 ? '&#9650; ' : (diff < 0 ? '&#9660; ' : '');
                    $('#spt-trend').html('<span class="' + trendClass + '">' + trendIcon + (diff > 0 ? '+' : '') + diff + '</span>');
                    $('#spt-trend-detail').text(s.total_clicks_prev + ' prev → ' + s.total_clicks_now + ' now (' + (pct > 0 ? '+' : '') + pct + '%)');
                    $('#spt-date-range').text('Comparing: ' + (d.current_date || '') + ' vs ' + (d.prev_date || ''));

                    var tbody = $('#seom-trends-body').empty();
                    if (!d.rows.length) {
                        tbody.html('<tr><td colspan="9" style="color:#94a3b8;">' + (d.message || 'No data.') + '</td></tr>');
                        return;
                    }

                    d.rows.forEach(function(r) {
                        var cd = parseInt(r.click_change) || 0;
                        var pd = parseFloat(r.position_change) || 0;
                        var clickClass = cd > 0 ? 'seom-change-positive' : (cd < 0 ? 'seom-change-negative' : '');
                        var posClass = pd < -0.1 ? 'seom-change-positive' : (pd > 0.1 ? 'seom-change-negative' : '');
                        var posStr = pd !== 0 ? (pd > 0 ? '+' : '') + pd.toFixed(1) : '0.0';

                        var trend = '';
                        if (cd > 0 && pd < -0.3) trend = '<span class="seom-change-positive" style="font-weight:700;">&#9650;&#9650;</span>';
                        else if (cd > 0) trend = '<span class="seom-change-positive">&#9650;</span>';
                        else if (cd < 0 && pd > 0.3) trend = '<span class="seom-change-negative" style="font-weight:700;">&#9660;&#9660;</span>';
                        else if (cd < 0) trend = '<span class="seom-change-negative">&#9660;</span>';
                        else trend = '<span style="color:#94a3b8;">—</span>';

                        var typeLabel = {product:'Product',post:'Post',page:'Page'}[r.post_type] || r.post_type;
                        var editUrl = '<?php echo admin_url("post.php?action=edit&post="); ?>' + r.post_id;

                        tbody.append('<tr>' +
                            '<td><a href="' + editUrl + '" target="_blank">' + r.post_title + '</a></td>' +
                            '<td style="font-size:11px;color:#94a3b8;">' + typeLabel + '</td>' +
                            '<td>' + parseInt(r.cur_clicks) + '</td>' +
                            '<td>' + parseInt(r.prev_clicks) + '</td>' +
                            '<td class="' + clickClass + '">' + (cd > 0 ? '+' : '') + cd + '</td>' +
                            '<td>' + parseFloat(r.cur_position || 0).toFixed(1) + '</td>' +
                            '<td>' + parseFloat(r.prev_position || 0).toFixed(1) + '</td>' +
                            '<td class="' + posClass + '">' + posStr + '</td>' +
                            '<td>' + trend + '</td>' +
                        '</tr>');
                    });

                    if (d.pages > 1) {
                        var pag = $('#seom-trends-pagination');
                        for (var i = 1; i <= Math.min(d.pages, 20); i++) {
                            pag.append('<button class="' + (i === d.page ? 'button button-primary' : 'button') + ' seom-trends-page" data-page="' + i + '">' + i + '</button> ');
                        }
                    }
                });
            }

            $('.seom-trends-type').click(function() {
                $('.seom-trends-type').removeClass('active');
                $(this).addClass('active');
                trendsType = $(this).data('type');
                loadTrends(1);
            });

            $('.seom-trends-filter').click(function() {
                $('.seom-trends-filter').removeClass('active');
                $(this).addClass('active');
                trendsFilter = $(this).data('filter');
                loadTrends(1);
            });

            $(document).on('click', '.seom-trends-page', function() { loadTrends(parseInt($(this).data('page'))); });

            loadTrends(1);
        });
        </script>
        <?php
    }

    // ─── Queue Tab ────────────────────────────────────────────────────────────

    private static function render_queue($nonce) {
        ?>
        <h2>Refresh Queue</h2>

        <div id="seom-queue-bulk" style="display:none; margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <select id="seom-bulk-action" style="padding:4px 8px; border-radius:6px; border:1px solid #e2e8f0;">
                <option value="">Bulk Actions</option>
                <option value="prioritize">Prioritize Selected</option>
                <option value="skip">Skip Selected</option>
                <option value="delete">Delete Selected</option>
            </select>
            <button type="button" class="button" id="seom-bulk-apply">Apply</button>
            <span style="margin:0 4px; color:#e2e8f0;">|</span>
            <button type="button" class="button" id="seom-clear-queue" style="color:#dc2626;">Clear Entire Queue</button>
            <span id="seom-bulk-status" style="font-size:12px; margin-left:8px;"></span>
        </div>

        <div id="seom-queue-loading">Loading queue...</div>

        <table class="seom-table" id="seom-queue-table" style="display:none;">
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" id="seom-queue-check-all" /></th>
                    <th class="seom-q-sort" data-col="title" style="cursor:pointer;">Page</th>
                    <th>Type</th>
                    <th class="seom-q-sort" data-col="category" style="cursor:pointer;width:90px;">Category</th>
                    <th class="seom-q-sort" data-col="priority" style="cursor:pointer;width:70px;">Priority</th>
                    <th class="seom-q-sort" data-col="clicks" style="cursor:pointer;width:70px;">Clicks</th>
                    <th class="seom-q-sort" data-col="impressions" style="cursor:pointer;width:90px;">Impr.</th>
                    <th class="seom-q-sort" data-col="ctr" style="cursor:pointer;width:60px;">CTR</th>
                    <th class="seom-q-sort" data-col="position" style="cursor:pointer;width:65px;">Pos</th>
                    <th style="width:100px;">Top Query</th>
                    <th style="width:85px;">Refresh</th>
                    <th style="width:70px;">Content</th>
                    <th style="width:210px;">Actions</th>
                </tr>
            </thead>
            <tbody id="seom-queue-body"></tbody>
        </table>

        <div id="seom-queue-empty" style="display:none; color:#666; padding:20px;">
            Queue is empty. Run analysis to populate it.
        </div>

        <style>
            .seom-q-sort:hover { color: #2271b1; }
            .seom-q-sort.sorted-asc::after { content: ' ▲'; font-size: 10px; }
            .seom-q-sort.sorted-desc::after { content: ' ▼'; font-size: 10px; }
            .seom-queue-detail { font-size: 11px; color: #666; margin-top: 2px; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var catLabels = {A:'Ghost',B:'CTR Fix',C:'Near Win',D:'Declining',E:'Visible/Ignored',M:'Manual'};
            var catDescriptions = {
                A: 'Zero impressions — Google is not showing this page',
                B: 'Ranks on page 1 but low click-through rate',
                C: 'Ranking on page 2 — close to breaking into page 1',
                D: 'Traffic has declined significantly vs prior period',
                E: 'High impressions but almost no clicks',
                M: 'Manually added to queue'
            };
            var queueData = [];
            var qSortCol = 'priority';
            var qSortDir = 'desc';

            function renderQueue() {
                // Sort the data
                var sorted = queueData.slice().sort(function(a, b) {
                    var valA, valB;
                    switch (qSortCol) {
                        case 'title': valA = a.post_title.toLowerCase(); valB = b.post_title.toLowerCase(); break;
                        case 'category': valA = a.category; valB = b.category; break;
                        case 'priority': valA = parseFloat(a.priority_score) || 0; valB = parseFloat(b.priority_score) || 0; break;
                        case 'clicks': valA = parseInt(a.clicks) || 0; valB = parseInt(b.clicks) || 0; break;
                        case 'impressions': valA = parseInt(a.impressions) || 0; valB = parseInt(b.impressions) || 0; break;
                        case 'ctr': valA = parseFloat(a.ctr) || 0; valB = parseFloat(b.ctr) || 0; break;
                        case 'position': valA = parseFloat(a.avg_position) || 999; valB = parseFloat(b.avg_position) || 999; break;
                        default: valA = 0; valB = 0;
                    }
                    if (valA < valB) return qSortDir === 'asc' ? -1 : 1;
                    if (valA > valB) return qSortDir === 'asc' ? 1 : -1;
                    return 0;
                });

                var tbody = $('#seom-queue-body').empty();
                sorted.forEach(function(item) {
                    var cat = item.category || '?';
                    var clicks = parseInt(item.clicks) || 0;
                    var impressions = parseInt(item.impressions) || 0;
                    var ctr = parseFloat(item.ctr) || 0;
                    var pos = parseFloat(item.avg_position) || 0;
                    var lastRefresh = item.last_refresh ? item.last_refresh.substring(0, 10) : 'Never';

                    // Parse top query
                    var topQuery = '-';
                    if (item.top_queries) {
                        try {
                            var queries = JSON.parse(item.top_queries);
                            if (queries.length > 0) topQuery = '<span title="' + queries.map(function(q){ return q.query; }).join(', ') + '">' + queries[0].query + '</span>';
                        } catch(e) {}
                    }

                    // Position coloring
                    var posClass = '';
                    if (pos > 0 && pos <= 10) posClass = 'seom-pos-good';
                    else if (pos > 10 && pos <= 20) posClass = 'seom-pos-near';
                    else if (pos > 20) posClass = 'seom-pos-far';
                    else posClass = 'seom-pos-ghost';

                    // Content status
                    var contentStatus = '';
                    if (!item.has_description) contentStatus += '<span style="color:#dc2626;" title="No description">No Desc</span> ';
                    if (!item.has_excerpt) contentStatus += '<span style="color:#dc2626;" title="No meta description">No Meta</span>';
                    if (item.has_description && item.has_excerpt) contentStatus = '<span style="color:#16a34a;">OK</span>';

                    var prioritized = parseFloat(item.priority_score) >= 999 ? ' <span style="color:#d97706;font-size:10px;font-weight:600;">PRIORITY</span>' : '';
                    tbody.append('<tr data-id="' + item.id + '" data-post-id="' + item.post_id + '">' +
                        '<td><input type="checkbox" class="seom-queue-cb" value="' + item.id + '" /></td>' +
                        '<td>' +
                            '<strong>' + item.post_title + '</strong>' +
                            '<div class="seom-queue-detail">' + item.post_type + ' &middot; <span class="seom-badge seom-badge-' + cat.toLowerCase() + '">' + (catLabels[cat] || cat) + '</span> ' + (catDescriptions[cat] || '') + '</div>' +
                        '</td>' +
                        '<td>' + item.refresh_type + '</td>' +
                        '<td><span class="seom-badge seom-badge-' + cat.toLowerCase() + '">' + (catLabels[cat] || cat) + '</span></td>' +
                        '<td>' + parseFloat(item.priority_score).toFixed(1) + prioritized + '</td>' +
                        '<td>' + clicks.toLocaleString() + '</td>' +
                        '<td>' + impressions.toLocaleString() + '</td>' +
                        '<td>' + ctr.toFixed(1) + '%</td>' +
                        '<td class="' + posClass + '">' + (pos > 0 ? pos.toFixed(1) : '-') + '</td>' +
                        '<td style="font-size:12px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + topQuery + '</td>' +
                        '<td style="font-size:12px;">' + lastRefresh + '</td>' +
                        '<td>' + contentStatus + '</td>' +
                        '<td>' +
                            '<button class="button button-small seom-process-btn" data-post-id="' + item.post_id + '">Process</button> ' +
                            '<button class="button button-small seom-skip-btn" data-id="' + item.id + '">Skip</button> ' +
                            '<a href="<?php echo admin_url('post.php?action=edit&post='); ?>' + item.post_id + '" class="button button-small" target="_blank">Edit</a> ' +
                            '<a href="' + (item.url || '/?p=' + item.post_id) + '" class="button button-small" target="_blank">View</a>' +
                        '</td>' +
                    '</tr>');
                });

                // Update sort indicators
                $('.seom-q-sort').removeClass('sorted-asc sorted-desc');
                $('[data-col="' + qSortCol + '"]').addClass(qSortDir === 'asc' ? 'sorted-asc' : 'sorted-desc');
            }

            function loadQueue() {
                $.post(ajaxurl, { action: 'seom_get_queue', nonce: seom_nonce }, function(resp) {
                    $('#seom-queue-loading').hide();
                    if (!resp.success || !resp.data.length) {
                        $('#seom-queue-empty').show();
                        $('#seom-queue-table').hide();
                        return;
                    }

                    queueData = resp.data;
                    renderQueue();
                    $('#seom-queue-table').show();
                    $('#seom-queue-bulk').css('display', 'flex');
                    $('#seom-queue-empty').hide();
                });
            }

            // Column sorting
            $(document).on('click', '.seom-q-sort', function() {
                var col = $(this).data('col');
                if (qSortCol === col) {
                    qSortDir = qSortDir === 'desc' ? 'asc' : 'desc';
                } else {
                    qSortCol = col;
                    qSortDir = col === 'title' ? 'asc' : 'desc';
                }
                renderQueue();
            });

            loadQueue();

            var isProcessing = false;

            $(document).on('click', '.seom-process-btn', function() {
                if (isProcessing) {
                    alert('Another refresh is already running. Please wait for it to finish.');
                    return;
                }

                isProcessing = true;
                var btn = $(this).prop('disabled', true).text('Processing...');
                var postId = btn.data('post-id');
                var row = btn.closest('tr');
                row.css('opacity', '0.7');

                // Disable all other process buttons while this one runs
                $('.seom-process-btn').not(btn).prop('disabled', true);
                btn.after('<br><small class="seom-process-msg" style="color:#b45309;">AI refresh running... this may take 1-2 minutes. Other refreshes are paused.</small>');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: { action: 'seom_process_one', nonce: seom_nonce, post_id: postId },
                    timeout: 600000,
                    success: function(resp) {
                        if (resp.success) {
                            if (resp.data.dry_run) {
                                row.css('opacity', '1');
                                btn.prop('disabled', false).text('Process Now');
                                row.find('.seom-process-msg').html('<span style="color:#b45309;font-weight:600;">DRY RUN — No changes made. Disable dry run in Settings to process for real.</span>');
                            } else {
                                row.css('opacity', '0.5');
                                btn.text('Done');
                                row.find('.seom-process-msg').html('<span style="color:#16a34a;">Completed! Check History tab.</span>');
                            }
                        } else {
                            row.css('opacity', '1');
                            btn.prop('disabled', false).text('Retry');
                            row.find('.seom-process-msg').html('<span style="color:#dc2626;">Failed: ' + (resp.data || 'Unknown error') + '</span>');
                        }
                    },
                    error: function(xhr, status) {
                        row.css('opacity', '1');
                        btn.prop('disabled', false).text('Retry');
                        var msg = status === 'timeout' ? 'Request timed out. The process may still be running server-side.' : 'Server error (504 gateway timeout — try one at a time).';
                        row.find('.seom-process-msg').html('<span style="color:#dc2626;">' + msg + '</span>');
                    },
                    complete: function() {
                        isProcessing = false;
                        $('.seom-process-btn').not(btn).prop('disabled', false);
                    }
                });
            });

            $(document).on('click', '.seom-skip-btn', function() {
                var btn = $(this);
                var id = btn.data('id');
                $.post(ajaxurl, { action: 'seom_skip_item', nonce: seom_nonce, queue_id: id }, function(resp) {
                    if (resp.success) btn.closest('tr').fadeOut();
                });
            });

            // Select all checkbox
            $(document).on('change', '#seom-queue-check-all', function() {
                $('.seom-queue-cb').prop('checked', $(this).prop('checked'));
            });

            // Bulk apply
            $('#seom-bulk-apply').click(function() {
                var action = $('#seom-bulk-action').val();
                if (!action) { alert('Select a bulk action.'); return; }

                var ids = [];
                $('.seom-queue-cb:checked').each(function() { ids.push(parseInt($(this).val())); });
                if (!ids.length) { alert('Select at least one item.'); return; }

                var labels = { prioritize: 'prioritize', skip: 'skip', 'delete': 'delete' };
                if (!confirm(labels[action] + ' ' + ids.length + ' item(s)?')) return;

                $.post(ajaxurl, {
                    action: 'seom_queue_bulk', nonce: seom_nonce,
                    bulk_action: action, 'ids[]': ids
                }, function(resp) {
                    if (resp.success) {
                        $('#seom-bulk-status').html('<span style="color:#059669;">' + resp.data.affected + ' items updated.</span>');
                        loadQueue();
                        setTimeout(function() { $('#seom-bulk-status').text(''); }, 3000);
                    } else {
                        $('#seom-bulk-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                    }
                });
            });

            // Clear entire queue
            $('#seom-clear-queue').click(function() {
                if (!confirm('Remove ALL pending items from the queue? This cannot be undone.')) return;
                $.post(ajaxurl, {
                    action: 'seom_queue_bulk', nonce: seom_nonce, bulk_action: 'clear'
                }, function(resp) {
                    if (resp.success) {
                        $('#seom-bulk-status').html('<span style="color:#059669;">Queue cleared. ' + resp.data.affected + ' items removed.</span>');
                        loadQueue();
                    }
                });
            });
        });
        </script>
        <?php
    }

    // ─── History Tab ──────────────────────────────────────────────────────────

    private static function render_history($nonce) {
        ?>
        <h2>Refresh History</h2>
        <div id="seom-history-loading">Loading history...</div>

        <table class="seom-table" id="seom-history-table" style="display:none;">
            <thead>
                <tr>
                    <th>Page</th>
                    <th>Date</th>
                    <th>Cat</th>
                    <th>Type</th>
                    <th>Clicks Before</th>
                    <th>Clicks 30d</th>
                    <th>Position Before</th>
                    <th>Position 30d</th>
                    <th>CTR Before</th>
                    <th>CTR 30d</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="seom-history-body"></tbody>
        </table>

        <div id="seom-history-empty" style="display:none; color:#666; padding:20px;">
            No refresh history yet.
        </div>

        <div id="seom-history-pagination" style="margin-top:12px;"></div>

        <script>
        jQuery(document).ready(function($) {
            var catLabels = {A:'Ghost',B:'CTR',C:'Near Win',D:'Decline',E:'Visible',M:'Manual'};

            function loadHistory(page) {
                $.post(ajaxurl, { action: 'seom_get_history', nonce: seom_nonce, page: page || 1 }, function(resp) {
                    $('#seom-history-loading').hide();
                    if (!resp.success || !resp.data.rows.length) {
                        $('#seom-history-empty').show();
                        $('#seom-history-table').hide();
                        return;
                    }

                    var tbody = $('#seom-history-body').empty();
                    resp.data.rows.forEach(function(r) {
                        function fmtChange(before, after, invert) {
                            if (after === null) return '<span style="color:#999;">Pending</span>';
                            var diff = parseFloat(after) - parseFloat(before);
                            var cls = invert ? (diff < 0 ? 'seom-change-positive' : diff > 0 ? 'seom-change-negative' : '')
                                             : (diff > 0 ? 'seom-change-positive' : diff < 0 ? 'seom-change-negative' : '');
                            return '<span class="' + cls + '">' + parseFloat(after).toFixed(1) + '</span>';
                        }

                        tbody.append('<tr>' +
                            '<td>' + r.post_title + '</td>' +
                            '<td>' + r.refresh_date.substring(0, 10) + '</td>' +
                            '<td><span class="seom-badge seom-badge-' + r.category.toLowerCase() + '">' + (catLabels[r.category] || r.category) + '</span></td>' +
                            '<td>' + r.refresh_type + '</td>' +
                            '<td>' + (r.clicks_before || 0) + '</td>' +
                            '<td>' + fmtChange(r.clicks_before, r.clicks_after_30d, false) + '</td>' +
                            '<td>' + (parseFloat(r.position_before) || 0).toFixed(1) + '</td>' +
                            '<td>' + fmtChange(r.position_before, r.position_after_30d, true) + '</td>' +
                            '<td>' + (parseFloat(r.ctr_before) || 0).toFixed(1) + '%</td>' +
                            '<td>' + fmtChange(r.ctr_before, r.ctr_after_30d, false) + '</td>' +
                            '<td>' +
                                '<a href="<?php echo admin_url('post.php?action=edit&post='); ?>' + r.post_id + '" class="button button-small" target="_blank">Edit</a> ' +
                                '<a href="' + (r.url || '/?p=' + r.post_id) + '" class="button button-small" target="_blank">View</a>' +
                            '</td>' +
                        '</tr>');
                    });

                    $('#seom-history-table').show();
                    $('#seom-history-empty').hide();

                    // Pagination
                    var totalPages = Math.ceil(resp.data.total / 50);
                    var pag = $('#seom-history-pagination').empty();
                    if (totalPages > 1) {
                        for (var i = 1; i <= totalPages; i++) {
                            var cls = i === resp.data.page ? 'button button-primary' : 'button';
                            pag.append('<button class="' + cls + ' seom-history-page" data-page="' + i + '">' + i + '</button> ');
                        }
                    }
                });
            }

            loadHistory(1);
            $(document).on('click', '.seom-history-page', function() { loadHistory($(this).data('page')); });
        });
        </script>
        <?php
    }

    // ─── Settings Tab ─────────────────────────────────────────────────────────

    // ─── Keywords Tab ───────────────────────────────────────────────────────

    private static function render_keywords($nonce) {
        ?>
        <h2>Keyword Intelligence</h2>
        <p>Data-driven keyword insights from Google Search Console. These keywords are automatically injected into content generation prompts.</p>

        <div style="display:flex; gap:8px; align-items:center; margin-bottom:16px; flex-wrap:wrap;">
            <button type="button" class="button button-primary" id="seom-collect-kw">Collect Keywords from GSC</button>
            <button type="button" class="button" id="seom-expand-kw">Expand with Autocomplete</button>
            <span id="seom-kw-action-status" style="color:#64748b; font-size:12px; margin-left:8px;"></span>
        </div>

        <div id="seom-kw-loading">Loading keyword data...</div>

        <div id="seom-kw-content" style="display:none;">
            <div class="seom-cards">
                <div class="seom-card">
                    <h3>Total Keywords</h3>
                    <div class="seom-card-value" id="sk-total">-</div>
                </div>
                <div class="seom-card">
                    <h3>Rising</h3>
                    <div class="seom-card-value seom-change-positive" id="sk-rising">-</div>
                </div>
                <div class="seom-card">
                    <h3>Declining</h3>
                    <div class="seom-card-value seom-change-negative" id="sk-declining">-</div>
                </div>
                <div class="seom-card">
                    <h3>Content Gaps</h3>
                    <div class="seom-card-value" id="sk-gaps" style="color:#7c3aed;">-</div>
                    <div class="seom-card-sub">Keywords with no dedicated page</div>
                </div>
                <div class="seom-card">
                    <h3>Page 2 Keywords</h3>
                    <div class="seom-card-value" id="sk-page2" style="color:#d97706;">-</div>
                    <div class="seom-card-sub">Position 11-20</div>
                </div>
            </div>

            <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap;">
                <strong>Filter:</strong>
                <button type="button" class="button seom-kw-filter active" data-filter="all">All</button>
                <button type="button" class="button seom-kw-filter" data-filter="rising">Rising</button>
                <button type="button" class="button seom-kw-filter" data-filter="declining">Declining</button>
                <button type="button" class="button seom-kw-filter" data-filter="gaps">Content Gaps</button>
                <button type="button" class="button seom-kw-filter" data-filter="page2">Page 2</button>
                <button type="button" class="button seom-kw-filter" data-filter="top">Page 1</button>
            </div>
            <div id="seom-kw-filter-desc" style="font-size:12px; color:#64748b; margin-bottom:16px;"></div>

            <table class="seom-table" id="seom-kw-table">
                <thead>
                    <tr>
                        <th class="seom-kw-sort" data-col="keyword" style="cursor:pointer;">Keyword</th>
                        <th class="seom-kw-sort" data-col="impressions" style="cursor:pointer;width:90px;">Impressions</th>
                        <th class="seom-kw-sort" data-col="clicks" style="cursor:pointer;width:70px;">Clicks</th>
                        <th class="seom-kw-sort" data-col="ctr" style="cursor:pointer;width:60px;">CTR</th>
                        <th class="seom-kw-sort" data-col="avg_position" style="cursor:pointer;width:70px;">Position</th>
                        <th class="seom-kw-sort" data-col="trend_pct" style="cursor:pointer;width:80px;">Trend</th>
                        <th class="seom-kw-sort" data-col="opportunity_score" style="cursor:pointer;width:80px;">Opp Score</th>
                        <th style="width:180px;">Mapped Page</th>
                        <th style="width:150px;">Suggestions</th>
                    </tr>
                </thead>
                <tbody id="seom-kw-body"></tbody>
            </table>
            <div id="seom-kw-pagination" style="margin-top:12px;"></div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var kwFilter = 'all', kwSort = 'opportunity_score', kwOrder = 'DESC', kwPage = 1;

            var kwFilterDescs = {
                all: 'All keywords your site ranks for in Google Search Console.',
                rising: 'Keywords with impression growth >10% vs previous 28-day period — trending upward.',
                declining: 'Keywords with impression decline >10% — losing visibility.',
                gaps: 'Keywords with significant impressions but no dedicated page targeting them — new content opportunities.',
                page2: 'Keywords ranking position 11-20 — close to page 1, highest ROI for optimization.',
                top: 'Keywords already ranking on page 1 (position 1-10) — protect and monitor these.'
            };

            function loadKeywords(page) {
                kwPage = page || 1;
                $('#seom-kw-loading').show().text('Loading...');
                $('#seom-kw-content').hide();
                $('#seom-kw-pagination').empty();

                $.post(ajaxurl, {
                    action: 'seom_get_keywords', nonce: seom_nonce,
                    filter: kwFilter, sort: kwSort, order: kwOrder, page: kwPage
                }, function(resp) {
                    $('#seom-kw-loading').hide();
                    $('#seom-kw-content').show();

                    if (!resp.success) return;
                    var d = resp.data;
                    var s = d.summary;

                    if (s) {
                        $('#sk-total').text(s.total_keywords);
                        $('#sk-rising').text(s.rising);
                        $('#sk-declining').text(s.declining);
                        $('#sk-gaps').text(s.content_gaps);
                        $('#sk-page2').text(s.page2_keywords);
                    }

                    $('#seom-kw-filter-desc').text(kwFilterDescs[kwFilter] || '');

                    var tbody = $('#seom-kw-body').empty();
                    if (!d.rows || !d.rows.length) {
                        tbody.html('<tr><td colspan="9" style="color:#94a3b8;">No keyword data. Click "Collect Keywords from GSC" to start.</td></tr>');
                        return;
                    }

                    d.rows.forEach(function(r) {
                        var pos = parseFloat(r.avg_position) || 0;
                        var posClass = pos <= 10 ? 'seom-pos-good' : (pos <= 20 ? 'seom-pos-near' : 'seom-pos-far');
                        var trend = parseFloat(r.trend_pct) || 0;
                        var trendClass = trend > 10 ? 'seom-change-positive' : (trend < -10 ? 'seom-change-negative' : '');
                        var trendIcon = trend > 10 ? '&#9650; ' : (trend < -10 ? '&#9660; ' : '');
                        var opp = parseFloat(r.opportunity_score) || 0;
                        var oppColor = opp >= 60 ? '#059669' : (opp >= 30 ? '#d97706' : '#94a3b8');

                        var mapped = r.mapped_title
                            ? '<a href="<?php echo admin_url("post.php?action=edit&post="); ?>' + r.mapped_post_id + '" target="_blank" style="font-size:12px;">' + r.mapped_title.substring(0, 40) + '</a>'
                            : (parseInt(r.is_content_gap) ? '<span style="color:#7c3aed;font-size:11px;font-weight:600;">CONTENT GAP</span>' : '<span style="color:#94a3b8;font-size:11px;">—</span>');

                        var sugs = '';
                        if (r.suggestions && r.suggestions.length) {
                            sugs = '<span style="font-size:11px;color:#64748b;" title="' + r.suggestions.join(', ') + '">' + r.suggestions.slice(0, 2).join(', ') + (r.suggestions.length > 2 ? '...' : '') + '</span>';
                        }

                        tbody.append('<tr>' +
                            '<td><strong>' + r.keyword + '</strong></td>' +
                            '<td>' + parseInt(r.impressions).toLocaleString() + '</td>' +
                            '<td>' + parseInt(r.clicks).toLocaleString() + '</td>' +
                            '<td>' + parseFloat(r.ctr).toFixed(1) + '%</td>' +
                            '<td class="' + posClass + '">' + (pos > 0 ? pos.toFixed(1) : '-') + '</td>' +
                            '<td class="' + trendClass + '">' + trendIcon + trend.toFixed(0) + '%</td>' +
                            '<td><span style="color:' + oppColor + ';font-weight:700;">' + opp.toFixed(0) + '</span></td>' +
                            '<td>' + mapped + '</td>' +
                            '<td>' + sugs + '</td>' +
                        '</tr>');
                    });

                    // Sort indicators
                    $('.seom-kw-sort').removeClass('sorted-asc sorted-desc');
                    $('[data-col="' + kwSort + '"]').addClass(kwOrder === 'ASC' ? 'sorted-asc' : 'sorted-desc');

                    // Pagination
                    if (d.pages > 1) {
                        var pag = $('#seom-kw-pagination');
                        for (var i = 1; i <= Math.min(d.pages, 20); i++) {
                            pag.append('<button class="' + (i === d.page ? 'button button-primary' : 'button') + ' seom-kw-page" data-page="' + i + '">' + i + '</button> ');
                        }
                    }
                });
            }

            // Filter buttons
            $('.seom-kw-filter').click(function() {
                $('.seom-kw-filter').removeClass('active');
                $(this).addClass('active');
                kwFilter = $(this).data('filter');
                loadKeywords(1);
            });

            // Sort columns
            $(document).on('click', '.seom-kw-sort', function() {
                var col = $(this).data('col');
                if (kwSort === col) { kwOrder = kwOrder === 'DESC' ? 'ASC' : 'DESC'; }
                else { kwSort = col; kwOrder = col === 'keyword' ? 'ASC' : 'DESC'; }
                loadKeywords(1);
            });

            // Pagination
            $(document).on('click', '.seom-kw-page', function() { loadKeywords(parseInt($(this).data('page'))); });

            // Collect keywords
            $('#seom-collect-kw').click(function() {
                var btn = $(this).prop('disabled', true).text('Collecting...');
                $('#seom-kw-action-status').text('Fetching keyword data from GSC (this may take a minute)...');
                $.ajax({
                    url: ajaxurl, method: 'POST', timeout: 300000,
                    data: { action: 'seom_collect_keywords', nonce: seom_nonce },
                    success: function(resp) {
                        btn.prop('disabled', false).text('Collect Keywords from GSC');
                        if (resp.success) {
                            $('#seom-kw-action-status').html('<span style="color:#059669;">' + resp.data.keywords_collected + ' keywords collected, ' + resp.data.content_gaps + ' content gaps, ' + resp.data.rising + ' rising.</span>');
                            loadKeywords(1);
                        } else {
                            $('#seom-kw-action-status').html('<span style="color:#dc2626;">Error: ' + (resp.data || 'Unknown') + '</span>');
                        }
                    },
                    error: function() { btn.prop('disabled', false).text('Collect Keywords from GSC'); $('#seom-kw-action-status').text('Server error/timeout.'); }
                });
            });

            // Expand with autocomplete
            $('#seom-expand-kw').click(function() {
                var btn = $(this).prop('disabled', true).text('Expanding...');
                $('#seom-kw-action-status').text('Fetching Google autocomplete suggestions for top keywords...');
                $.ajax({
                    url: ajaxurl, method: 'POST', timeout: 300000,
                    data: { action: 'seom_expand_keywords', nonce: seom_nonce, limit: 50 },
                    success: function(resp) {
                        btn.prop('disabled', false).text('Expand with Autocomplete');
                        if (resp.success) {
                            var msg = resp.data.expanded + ' suggestions from ' + resp.data.seeds_with_results + '/' + resp.data.seeds + ' seed keywords.';
                            if (resp.data.debug_error) msg += ' Debug: ' + resp.data.debug_error;
                            var color = resp.data.expanded > 0 ? '#059669' : '#d97706';
                            $('#seom-kw-action-status').html('<span style="color:' + color + ';">' + msg + '</span>');
                            loadKeywords(kwPage);
                        } else {
                            $('#seom-kw-action-status').html('<span style="color:#dc2626;">Error: ' + (resp.data || 'Unknown') + '</span>');
                        }
                    },
                    error: function() { btn.prop('disabled', false).text('Expand with Autocomplete'); $('#seom-kw-action-status').text('Server error/timeout.'); }
                });
            });

            loadKeywords(1);
        });
        </script>
        <?php
    }

    // ─── Settings Tab ─────────────────────────────────────────────────────────

    private static function render_settings($nonce, $settings) {
        ?>
        <h2>Settings</h2>

        <form id="seom-settings-form">
            <table class="seom-form-table widefat" style="max-width:800px; padding:16px 20px;">
                <tr>
                    <th colspan="2"><h3 style="margin:0;">Google Search Console</h3></th>
                </tr>
                <tr>
                    <th><label>Service Account JSON</label></th>
                    <td>
                        <textarea name="gsc_credentials_json" rows="6" style="width:100%;max-width:600px;font-family:monospace;font-size:12px;"><?php
                            $json = $settings['gsc_credentials_json'];
                            if ($json) {
                                // Mask the private key for display
                                $parsed = json_decode($json, true);
                                if ($parsed && !empty($parsed['private_key'])) {
                                    $parsed['private_key'] = '-----REDACTED-----';
                                    echo esc_textarea(json_encode($parsed, JSON_PRETTY_PRINT));
                                } else {
                                    echo esc_textarea($json);
                                }
                            }
                        ?></textarea>
                        <p class="description">Paste the full contents of your Google service account JSON key file. The private key is redacted after saving.</p>
                        <?php if (!empty($settings['gsc_credentials_json'])) : ?>
                            <p style="color:#16a34a;font-weight:600;margin-top:4px;">Credentials saved. Paste new JSON to replace.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label>GSC Property URL</label></th>
                    <td>
                        <input type="text" name="gsc_property_url" value="<?php echo esc_attr($settings['gsc_property_url']); ?>" placeholder="https://www.ituonline.com" />
                        <p class="description">Exactly as it appears in Google Search Console.</p>
                        <button type="button" class="button" id="seom-test-gsc" style="margin-top:8px;">Test Connection</button>
                        <span id="seom-test-result" style="margin-left:8px;"></span>
                    </td>
                </tr>

                <tr><th colspan="2"><h3 style="margin:0;">Processing</h3></th></tr>
                <tr>
                    <th><label>System Enabled</label></th>
                    <td><label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?> /> Enable automated processing</label></td>
                </tr>
                <tr>
                    <th><label>Dry Run Mode</label></th>
                    <td>
                        <label><input type="checkbox" name="dry_run" value="1" <?php checked($settings['dry_run']); ?> /> Analysis only — no content changes</label>
                        <p class="description">Recommended when first setting up. Builds queue without executing refreshes.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Daily Limit</label></th>
                    <td><input type="number" name="daily_limit" value="<?php echo intval($settings['daily_limit']); ?>" min="1" max="100" style="width:80px;" /></td>
                </tr>
                <tr>
                    <th><label>Cooldown (days)</label></th>
                    <td>
                        <input type="number" name="cooldown_days" value="<?php echo intval($settings['cooldown_days']); ?>" min="14" max="365" style="width:80px;" />
                        <p class="description">Days to wait after a refresh before re-evaluating a page.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Post Types</label></th>
                    <td>
                        <label><input type="checkbox" name="process_post_types[]" value="product" <?php checked(in_array('product', $settings['process_post_types'])); ?> /> Products</label><br>
                        <label><input type="checkbox" name="process_post_types[]" value="post" <?php checked(in_array('post', $settings['process_post_types'])); ?> /> Blog Posts</label>
                        <?php if (!SEOM_Blog_Refresher::is_available()) : ?>
                            <span style="color:#b45309;"> (Blog Writer API key not configured in <a href="<?php echo admin_url('admin.php?page=ai-blog-writer'); ?>">Settings</a>)</span>
                        <?php endif; ?><br>
                        <label><input type="checkbox" name="process_post_types[]" value="page" <?php checked(in_array('page', $settings['process_post_types'])); ?> /> Pages</label>
                        <span style="color:#64748b; font-size:12px;"> (tracked for GSC metrics only — no automated refresh)</span>
                    </td>
                </tr>

                <tr><th colspan="2"><h3 style="margin:0;">Exclusions</h3></th></tr>
                <tr>
                    <th><label>Exclude Post IDs</label></th>
                    <td>
                        <input type="text" name="exclude_post_ids" value="<?php echo esc_attr($settings['exclude_post_ids']); ?>" placeholder="123, 456, 789" />
                        <p class="description">Comma-separated list of post/product IDs that should never be refreshed.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Exclude Categories</label></th>
                    <td>
                        <input type="text" name="exclude_categories" value="<?php echo esc_attr($settings['exclude_categories']); ?>" placeholder="practice-tests, free-courses" />
                        <p class="description">Comma-separated category slugs (post categories and product categories). Pages in these categories are skipped.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Auto-Exclude</label></th>
                    <td>
                        <p class="description" style="margin:0;">Posts containing shortcodes (other than <code>[itu_*]</code>) are automatically excluded to protect embedded functionality like practice tests, forms, etc.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Notification Email</label></th>
                    <td><input type="email" name="notify_email" value="<?php echo esc_attr($settings['notify_email']); ?>" /></td>
                </tr>

                <tr><th colspan="2"><h3 style="margin:0;">Thresholds</h3></th></tr>
                <tr>
                    <th><label>Ghost Page: Max Impressions</label></th>
                    <td>
                        <input type="number" name="ghost_threshold" value="<?php echo intval($settings['ghost_threshold']); ?>" min="0" style="width:80px;" />
                        <p class="description">Pages with impressions at or below this are flagged as "ghost pages" (Category A).</p>
                    </td>
                </tr>
                <tr>
                    <th><label>CTR Fix: Min Impressions</label></th>
                    <td><input type="number" name="ctr_fix_min_impressions" value="<?php echo intval($settings['ctr_fix_min_impressions']); ?>" min="0" style="width:80px;" /></td>
                </tr>
                <tr>
                    <th><label>CTR Fix: Max CTR (%)</label></th>
                    <td><input type="number" name="ctr_fix_max_ctr" value="<?php echo floatval($settings['ctr_fix_max_ctr']); ?>" step="0.1" min="0" style="width:80px;" /></td>
                </tr>
                <tr>
                    <th><label>Near Win: Position Range</label></th>
                    <td>
                        <input type="number" name="near_win_min_pos" value="<?php echo floatval($settings['near_win_min_pos']); ?>" step="1" min="1" style="width:60px;" />
                        to
                        <input type="number" name="near_win_max_pos" value="<?php echo floatval($settings['near_win_max_pos']); ?>" step="1" min="1" style="width:60px;" />
                    </td>
                </tr>
                <tr>
                    <th><label>Near Win: Min Impressions</label></th>
                    <td><input type="number" name="near_win_min_impressions" value="<?php echo intval($settings['near_win_min_impressions']); ?>" min="0" style="width:80px;" /></td>
                </tr>
                <tr>
                    <th><label>Decline Threshold (%)</label></th>
                    <td>
                        <input type="number" name="decline_threshold_pct" value="<?php echo floatval($settings['decline_threshold_pct']); ?>" step="1" min="0" style="width:80px;" />
                        <p class="description">Click decrease percentage to flag as "declining" (Category D).</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Visible/Ignored: Min Impressions</label></th>
                    <td><input type="number" name="visible_min_impressions" value="<?php echo intval($settings['visible_min_impressions']); ?>" min="0" style="width:80px;" /></td>
                </tr>
                <tr>
                    <th><label>Visible/Ignored: Max Clicks</label></th>
                    <td><input type="number" name="visible_max_clicks" value="<?php echo intval($settings['visible_max_clicks']); ?>" min="0" style="width:80px;" /></td>
                </tr>
            </table>

            <p style="margin-top:16px;">
                <button type="submit" class="button button-primary">Save Settings</button>
                <span id="seom-save-status" style="margin-left:12px;"></span>
            </p>
        </form>

        <h2 style="margin-top:32px;">Scheduled Tasks (Cron)</h2>
        <p style="color:#64748b; font-size:13px;">These tasks run automatically via WordPress cron. Times shown are when the next run is scheduled.</p>

        <table class="seom-table" style="max-width:800px;" id="seom-cron-table">
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Schedule</th>
                    <th>Next Run</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $cron_jobs = [
                    'seom_daily_collect'     => ['label' => 'Collect GSC Data', 'desc' => 'Fetches page metrics from Google Search Console'],
                    'seom_daily_analyze'     => ['label' => 'Run Analysis', 'desc' => 'Scores pages and populates the refresh queue'],
                    'seom_daily_process'     => ['label' => 'Process Queue', 'desc' => 'Starts processing queued page refreshes'],
                    'seom_daily_keywords'    => ['label' => 'Collect Keywords', 'desc' => 'Fetches site-wide keyword data from GSC'],
                    'seom_weekly_backfill'   => ['label' => 'Backfill History', 'desc' => 'Updates 30d/60d after-metrics for refreshed pages'],
                    'seom_weekly_autocomplete' => ['label' => 'Autocomplete Expand', 'desc' => 'Fetches Google autocomplete suggestions for top keywords'],
                ];

                foreach ($cron_jobs as $hook => $info) {
                    $next = wp_next_scheduled($hook);
                    $schedule = wp_get_schedule($hook);
                    $schedule_label = $schedule ?: 'Not scheduled';
                    $next_label = $next ? date('Y-m-d H:i:s', $next) . ' (' . human_time_diff($next) . ($next > time() ? ' from now' : ' ago') . ')' : 'Not scheduled';
                    $status = $next ? ($next > time() ? '<span style="color:#059669;">Scheduled</span>' : '<span style="color:#d97706;">Overdue</span>') : '<span style="color:#dc2626;">Inactive</span>';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($info['label']); ?></strong>
                            <br><small style="color:#94a3b8;"><?php echo esc_html($info['desc']); ?></small>
                        </td>
                        <td><?php echo esc_html(ucfirst($schedule_label)); ?></td>
                        <td style="font-size:12px;"><?php echo $next_label; ?></td>
                        <td><?php echo $status; ?></td>
                        <td>
                            <button type="button" class="button button-small seom-cron-run" data-hook="<?php echo esc_attr($hook); ?>">Run Now</button>
                            <?php if ($next) : ?>
                                <button type="button" class="button button-small seom-cron-clear" data-hook="<?php echo esc_attr($hook); ?>" style="color:#dc2626;">Disable</button>
                            <?php else : ?>
                                <button type="button" class="button button-small seom-cron-enable" data-hook="<?php echo esc_attr($hook); ?>">Enable</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>

        <p style="margin-top:12px;">
            <button type="button" class="button" id="seom-reschedule-all">Re-schedule All Tasks</button>
            <span id="seom-cron-status" style="margin-left:12px; font-size:12px;"></span>
        </p>

        <script>
        jQuery(document).ready(function($) {
            $('#seom-test-gsc').click(function() {
                var btn = $(this).prop('disabled', true);
                $('#seom-test-result').text('Testing...');
                $.post(ajaxurl, { action: 'seom_test_gsc', nonce: seom_nonce }, function(resp) {
                    btn.prop('disabled', false);
                    if (resp.success) {
                        $('#seom-test-result').html('<span style="color:#16a34a;font-weight:600;">Connected! Property: ' + resp.data.property + ' (' + resp.data.level + ')</span>');
                        if (resp.data.debug) {
                            console.log('GSC Debug:', resp.data.debug);
                        }
                    } else {
                        var errMsg = resp.data || 'Failed';
                        // Try to format JSON debug output
                        try {
                            var parsed = JSON.parse(errMsg);
                            errMsg = '<pre style="background:#fef2f2;padding:12px;border:1px solid #fca5a5;border-radius:4px;font-size:12px;max-height:400px;overflow:auto;white-space:pre-wrap;">' + JSON.stringify(parsed, null, 2) + '</pre>';
                        } catch(e) {}
                        $('#seom-test-result').html('<div style="color:#dc2626;margin-top:8px;">' + errMsg + '</div>');
                    }
                }).fail(function() { btn.prop('disabled', false); $('#seom-test-result').text('Server error.'); });
            });

            $('#seom-settings-form').submit(function(e) {
                e.preventDefault();
                var data = $(this).serializeArray();
                data.push({ name: 'action', value: 'seom_save_settings' });
                data.push({ name: 'nonce', value: seom_nonce });

                // Handle unchecked checkboxes
                if (!$('input[name="enabled"]').is(':checked')) data.push({ name: 'enabled', value: '0' });
                if (!$('input[name="dry_run"]').is(':checked')) data.push({ name: 'dry_run', value: '0' });

                $('#seom-save-status').text('Saving...');
                $.post(ajaxurl, data, function(resp) {
                    $('#seom-save-status').html(resp.success
                        ? '<span style="color:#16a34a;">Saved!</span>'
                        : '<span style="color:#dc2626;">Error</span>');
                    setTimeout(function() { $('#seom-save-status').text(''); }, 3000);
                });
            });

            // Cron management
            $(document).on('click', '.seom-cron-run', function() {
                var btn = $(this).prop('disabled', true).text('Running...');
                var hook = btn.data('hook');
                $.post(ajaxurl, { action: 'seom_cron_action', nonce: seom_nonce, hook: hook, cron_action: 'run' }, function(resp) {
                    btn.prop('disabled', false).text('Run Now');
                    $('#seom-cron-status').html(resp.success
                        ? '<span style="color:#059669;">' + hook + ' executed.</span>'
                        : '<span style="color:#dc2626;">Error: ' + (resp.data || 'Unknown') + '</span>');
                });
            });

            $(document).on('click', '.seom-cron-clear', function() {
                var btn = $(this);
                var hook = btn.data('hook');
                if (!confirm('Disable ' + hook + '? It will not run until re-enabled.')) return;
                $.post(ajaxurl, { action: 'seom_cron_action', nonce: seom_nonce, hook: hook, cron_action: 'disable' }, function(resp) {
                    if (resp.success) {
                        btn.text('Enable').removeClass('seom-cron-clear').addClass('seom-cron-enable').css('color', '');
                        btn.closest('tr').find('td:eq(2)').text('Disabled');
                        btn.closest('tr').find('td:eq(3)').html('<span style="color:#dc2626;">Inactive</span>');
                        $('#seom-cron-status').html('<span style="color:#059669;">' + hook + ' disabled.</span>');
                    }
                });
            });

            $(document).on('click', '.seom-cron-enable', function() {
                var btn = $(this);
                var hook = btn.data('hook');
                $.post(ajaxurl, { action: 'seom_cron_action', nonce: seom_nonce, hook: hook, cron_action: 'enable' }, function(resp) {
                    if (resp.success) {
                        btn.text('Disable').removeClass('seom-cron-enable').addClass('seom-cron-clear').css('color', '#dc2626');
                        btn.closest('tr').find('td:eq(3)').html('<span style="color:#059669;">Scheduled</span>');
                        $('#seom-cron-status').html('<span style="color:#059669;">' + hook + ' enabled.</span>');
                        setTimeout(function() { location.reload(); }, 1000);
                    }
                });
            });

            $('#seom-reschedule-all').click(function() {
                var btn = $(this).prop('disabled', true).text('Rescheduling...');
                $.post(ajaxurl, { action: 'seom_cron_action', nonce: seom_nonce, cron_action: 'reschedule_all' }, function(resp) {
                    btn.prop('disabled', false).text('Re-schedule All Tasks');
                    if (resp.success) {
                        $('#seom-cron-status').html('<span style="color:#059669;">All tasks rescheduled.</span>');
                        setTimeout(function() { location.reload(); }, 1000);
                    }
                });
            });
        });
        </script>
        <?php
    }
}
