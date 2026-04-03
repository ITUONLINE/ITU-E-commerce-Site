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
                <a href="?page=seo-monitor&tab=gaps" class="nav-tab <?php echo $active_tab === 'gaps' ? 'nav-tab-active' : ''; ?>">Keyword Gaps</a>
                <a href="?page=seo-monitor&tab=goals" class="nav-tab <?php echo $active_tab === 'goals' ? 'nav-tab-active' : ''; ?>">Goals</a>
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
                    case 'gaps':     self::render_keyword_gaps($nonce); break;
                    case 'goals':    self::render_goals($nonce); break;
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
            .seom-badge-f { background: #fdf4ff; color: #9333ea; }
            .seom-badge-m { background: #f1f5f9; color: #475569; }
            .seom-badge-page1 { background: #dcfce7; color: #166534; }
            .seom-badge-new { background: #dbeafe; color: #1e40af; }
            .seom-badge-never { background: #f1f5f9; color: #64748b; }

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
                <h3 style="margin:0;">Recent Refresh Results <small style="color:#94a3b8;font-weight:normal;">(last 14 days, top 30 by performance)</small></h3>
                <a href="?page=seo-monitor&tab=tracker" class="button">View All in Performance Tracker &rarr;</a>
            </div>
            <table class="seom-table" id="seom-recent-table">
                <thead>
                    <tr><th>Page</th><th>Was</th><th>Now</th><th>Type</th><th>Days Ago</th><th>Clicks Before</th><th>Clicks Now</th><th>Change</th><th>Pos Before</th><th>Pos Now</th><th>Trend</th></tr>
                </thead>
                <tbody id="seom-recent-body">
                    <tr><td colspan="11" style="color:#999;">No recent refreshes. Process some pages and collect GSC data to see changes.</td></tr>
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
                        var labels = {A:'Ghost Pages',B:'CTR Fix',C:'Near Wins',D:'Declining',E:'Visible/Ignored',F:'Buried Potential',M:'Manual'};
                        d.categories.forEach(function(c) {
                            html += '<span class="seom-badge seom-badge-' + c.category.toLowerCase() + '" style="font-size:13px;padding:6px 12px;">'
                                + (labels[c.category] || c.category) + ': ' + c.cnt + '</span>';
                        });
                        html += '</div>';
                        $('#seom-category-breakdown').html(html);
                    }

                    // Recent changes (last 14 days — near real-time feedback)
                    var recentCatLabels = {A:'Ghost',B:'CTR Fix',C:'Near Win',D:'Declining',E:'Visible/Ignored',F:'Buried',M:'Manual'};

                    // Derive current status from current metrics
                    function currentStatus(clicks, impressions, position) {
                        clicks = parseInt(clicks) || 0;
                        impressions = parseInt(impressions) || 0;
                        position = parseFloat(position) || 0;
                        if (impressions === 0) return {label: 'Ghost', cls: 'a'};
                        if (position > 0 && position <= 10) return {label: 'Page 1', cls: 'page1'};
                        if (position > 10 && position <= 20) return {label: 'Page 2', cls: 'c'};
                        if (position > 20) return {label: 'Page ' + Math.ceil(position/10), cls: 'f'};
                        return {label: '-', cls: ''};
                    }

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

                            var cat = r.category || '?';
                            var catBadge = '<span class="seom-badge seom-badge-' + cat.toLowerCase() + '">' + (recentCatLabels[cat] || cat) + '</span>';
                            var nowStatus = currentStatus(r.clicks_now, r.impressions_now, r.position_now);
                            var nowBadge = '<span class="seom-badge seom-badge-' + nowStatus.cls + '">' + nowStatus.label + '</span>';

                            tbody.append('<tr>' +
                                '<td><a href="/?p=' + r.post_id + '" target="_blank">' + r.post_title + '</a></td>' +
                                '<td>' + catBadge + '</td>' +
                                '<td>' + nowBadge + '</td>' +
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
            <button type="button" class="button seom-type-btn" data-type="all">All</button>
            <button type="button" class="button seom-type-btn active" data-type="product">Products</button>
            <button type="button" class="button seom-type-btn" data-type="post">Blog Posts</button>
            <button type="button" class="button seom-type-btn" data-type="page">Pages</button>
            <span style="margin:0 8px; color:#ccc;">|</span>
            <strong>Filter:</strong>
            <button type="button" class="button seom-filter-btn active" data-filter="all">All</button>
            <button type="button" class="button seom-filter-btn" data-filter="underperforming">Underperforming</button>
            <button type="button" class="button seom-filter-btn" data-filter="ghost">Ghost</button>
            <button type="button" class="button seom-filter-btn" data-filter="new" style="color:#1e40af;">New</button>
            <button type="button" class="button seom-filter-btn" data-filter="page2">Near Wins (Page 2)</button>
            <button type="button" class="button seom-filter-btn" data-filter="buried">Buried (Page 3+)</button>
            <button type="button" class="button seom-filter-btn" data-filter="low_ctr">Low CTR</button>
            <button type="button" class="button seom-filter-btn" data-filter="page1">Page 1</button>
            <button type="button" class="button seom-filter-btn" data-filter="limited">Limited Visibility</button>
            <span style="margin:0 4px; color:#e2e8f0;">|</span>
            <button type="button" class="button seom-filter-btn" data-filter="top_performers" style="color:#059669;">Top Performers</button>
            <button type="button" class="button seom-filter-btn" data-filter="stars" style="color:#d97706;">Stars</button>
            <span style="margin:0 8px; color:#ccc;">|</span>
            <strong>Date Range:</strong>
            <select id="seom-date-range" style="padding:4px 8px; border:1px solid #c3c4c7; border-radius:4px; font-size:13px;">
                <option value="1">Last 1 Day</option>
                <option value="7">Last 7 Days</option>
                <option value="28" selected>Last 28 Days</option>
                <option value="90">Last 3 Months</option>
                <option value="180">Last 6 Months</option>
                <option value="365">Last 12 Months</option>
            </select>
        </div>
        <div id="seom-filter-desc" style="font-size:12px; color:#64748b; margin:-8px 0 16px; padding-left:2px;"></div>
        <div id="seom-date-warning" style="display:none; font-size:12px; color:#b45309; background:#fef3c7; padding:8px 14px; border-radius:6px; margin-bottom:12px;"></div>

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
                <h3>New Without Impressions</h3>
                <div class="seom-card-value" id="si-new" style="color:#1e40af;">-</div>
                <div class="seom-card-sub">Published &lt; 14 days, no impressions yet</div>
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

        <div id="seom-indexed-bulk-bar" style="display:none; margin-bottom:12px; padding:10px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; display:flex; gap:10px; align-items:center;">
            <select id="seom-indexed-bulk-action" style="padding:5px 10px; border:1px solid #c3c4c7; border-radius:4px;">
                <option value="">Bulk Actions</option>
                <option value="full">Add to Queue — Full Refresh</option>
                <option value="meta_only">Add to Queue — Meta Only</option>
            </select>
            <button type="button" class="button" id="seom-indexed-bulk-apply">Apply</button>
            <span id="seom-indexed-bulk-status" style="font-size:12px; margin-left:8px;"></span>
        </div>

        <table class="seom-table" id="seom-indexed-table" style="display:none;">
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" id="seom-idx-check-all" /></th>
                    <th class="seom-sortable" data-sort="post_title" style="cursor:pointer;">Page</th>
                    <th style="width:75px;">Was</th>
                    <th class="seom-sortable" data-sort="avg_position" style="cursor:pointer;width:65px;">Now</th>
                    <th class="seom-sortable" data-sort="clicks" style="cursor:pointer;width:55px;">Clicks</th>
                    <th style="width:45px;">&Delta;</th>
                    <th class="seom-sortable" data-sort="impressions" style="cursor:pointer;width:65px;">Impr</th>
                    <th style="width:45px;">&Delta;</th>
                    <th class="seom-sortable" data-sort="avg_position" style="cursor:pointer;width:55px;">Pos</th>
                    <th style="width:45px;">&Delta;</th>
                    <th class="seom-sortable" data-sort="ctr" style="cursor:pointer;width:50px;">CTR</th>
                    <th class="seom-sortable" data-sort="post_date" style="cursor:pointer;width:80px;">Published</th>
                    <th class="seom-sortable" data-sort="last_refresh" style="cursor:pointer;width:80px;">Refreshed</th>
                    <th style="width:150px;">Actions</th>
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
            var currentDateRange = 28;

            function loadIndexed(page) {
                currentPage = page || 1;
                currentDateRange = parseInt($('#seom-date-range').val()) || 28;
                $('#seom-indexed-loading').show().text('Loading...');
                $('#seom-indexed-table').hide();
                $('#seom-indexed-pagination').empty();

                $.ajax({ url: ajaxurl, method: 'POST', timeout: 60000, data: {
                    action: 'seom_get_indexed', nonce: seom_nonce,
                    post_type: currentType, sort: currentSort, order: currentOrder,
                    filter: currentFilter, page: currentPage, date_range: currentDateRange
                }, success: function(resp) {
                    $('#seom-indexed-loading').hide();
                    if (!resp.success) return;

                    var d = resp.data;
                    var s = d.summary;

                    // Summary cards — update values and labels based on active filter
                    var filterLabels = {
                        all: '', underperforming: 'Underperforming', ghost: 'Ghost', 'new': 'New',
                        page1: 'Page 1', page2: 'Page 2 (Near Wins)', buried: 'Buried (Page 3+)',
                        low_ctr: 'Low CTR', limited: 'Limited Visibility',
                        top_performers: 'Top Performers',
                        stars: 'Stars'
                    };
                    var label = filterLabels[currentFilter] || '';
                    var suffix = label ? ' (' + label + ')' : '';

                    $('#si-total').text(s.total_pages);
                    $('#si-total').closest('.seom-card').find('h3').text('Total Pages' + suffix);
                    $('#si-with-impressions').text(s.pages_with_impressions);
                    $('#si-ghost').text(s.ghost_pages);
                    $('#si-new').text(s.new_pages || 0);
                    $('#si-clicks').text(parseInt(s.total_clicks || 0).toLocaleString());
                    $('#si-impressions').text(parseInt(s.total_impressions || 0).toLocaleString());
                    $('#si-avg-pos').text(parseFloat(s.avg_position || 0).toFixed(1));
                    $('#seom-indexed-summary').show();

                    // Date range warning
                    if (d.date_warning) {
                        $('#seom-date-warning').html(d.date_warning).show();
                    } else {
                        $('#seom-date-warning').hide();
                    }

                    // Update card headers to reflect date range
                    var rangeLabel = {1:'1d',7:'7d',28:'28d',90:'3mo',180:'6mo',365:'12mo'};
                    var rl = rangeLabel[currentDateRange] || currentDateRange + 'd';
                    $('#si-clicks').closest('.seom-card').find('h3').text('Total Clicks (' + rl + ')');
                    $('#si-impressions').closest('.seom-card').find('h3').text('Total Impressions (' + rl + ')');

                    // Table
                    var tbody = $('#seom-indexed-body').empty();
                    if (!d.rows.length) {
                        tbody.append('<tr><td colspan="14" style="color:#94a3b8;">No data matching this filter. Try a different filter or run data collection.</td></tr>');
                        $('#seom-indexed-table').show();
                        return;
                    }

                    var fourteenDaysAgo = new Date();
                    fourteenDaysAgo.setDate(fourteenDaysAgo.getDate() - 14);

                    var idxCatLabels = {A:'Ghost',B:'CTR Fix',C:'Near Win',D:'Declining',E:'Visible/Ignored',F:'Buried',M:'Manual',NEW:'New',NEVER:'Never Refreshed'};
                    var idxCatCls = {A:'a',B:'b',C:'c',D:'d',E:'e',F:'f',M:'m',NEW:'new',NEVER:'never'};

                    d.rows.forEach(function(r) {
                        var pos = parseFloat(r.avg_position);
                        var imp = parseInt(r.impressions);
                        var clicks = parseInt(r.clicks);

                        // Current status (Now column)
                        var nowLabel, nowCls;
                        if (imp === 0) {
                            var isNew = r.post_date && (new Date(r.post_date) >= fourteenDaysAgo);
                            nowLabel = isNew ? 'New' : 'Ghost';
                            nowCls = isNew ? 'new' : 'a';
                        } else if (pos > 0 && pos <= 10) { nowLabel = 'Page 1'; nowCls = 'page1'; }
                        else if (pos > 10 && pos <= 20) { nowLabel = 'Page 2'; nowCls = 'c'; }
                        else { nowLabel = 'Page ' + Math.ceil(pos / 10); nowCls = 'f'; }
                        var nowBadge = '<span class="seom-badge seom-badge-' + nowCls + '">' + nowLabel + '</span>';

                        // Was column (last refresh category or New/Never Refreshed)
                        var wasCat = r.was_category || 'NEVER';
                        var wasLabel = idxCatLabels[wasCat] || wasCat;
                        var wasCls = idxCatCls[wasCat] || 'm';
                        var wasBadge = '<span class="seom-badge seom-badge-' + wasCls + '">' + wasLabel + '</span>';

                        // Delta columns
                        var clicksPrior = r.clicks_prior;
                        var impPrior = r.impressions_prior;
                        var posPrior = r.position_prior;

                        var clickDelta = '', impDelta = '', posDelta = '';
                        if (clicksPrior !== null) {
                            var cd = clicks - clicksPrior;
                            var cc = cd > 0 ? 'seom-change-positive' : (cd < 0 ? 'seom-change-negative' : '');
                            clickDelta = '<span class="' + cc + '">' + (cd > 0 ? '+' : '') + cd + '</span>';
                        }
                        if (impPrior !== null) {
                            var id = imp - impPrior;
                            var ic = id > 0 ? 'seom-change-positive' : (id < 0 ? 'seom-change-negative' : '');
                            impDelta = '<span class="' + ic + '">' + (id > 0 ? '+' : '') + id.toLocaleString() + '</span>';
                        }
                        if (posPrior !== null && pos > 0) {
                            var pd = pos - posPrior;
                            var pc = pd < -0.1 ? 'seom-change-positive' : (pd > 0.1 ? 'seom-change-negative' : '');
                            posDelta = '<span class="' + pc + '">' + (pd > 0 ? '+' : '') + pd.toFixed(1) + '</span>';
                        }

                        var editUrl = '<?php echo admin_url('post.php?action=edit&post='); ?>' + r.post_id;
                        var viewUrl = r.url;
                        var published = r.post_date ? r.post_date.substring(0, 10) : '-';
                        var lastRefresh = r.last_refresh ? r.last_refresh.substring(0, 10) : '<span style="color:#999;">Never</span>';

                        var actions = '';
                        var thirtyDaysAgo = new Date();
                        thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                        var refreshedRecently = r.last_refresh && (new Date(r.last_refresh) >= thirtyDaysAgo);

                        if (currentType === 'page') {
                            actions = '<span style="color:#94a3b8;font-size:11px;">Metrics only</span>';
                        } else if (r.in_queue) {
                            actions = '<span style="color:#b45309;font-size:12px;">In Queue</span>';
                        } else if (refreshedRecently) {
                            var daysAgo = Math.floor((new Date() - new Date(r.last_refresh)) / 86400000);
                            actions = '<span style="color:#94a3b8;font-size:11px;">Cooldown (' + (30 - daysAgo) + 'd)</span>';
                        } else {
                            actions = '<button class="button button-small seom-queue-btn" data-id="' + r.post_id + '" data-type="full" title="Full content refresh">Refresh</button> '
                                + '<button class="button button-small seom-queue-btn" data-id="' + r.post_id + '" data-type="meta_only" title="Meta description + keyword only">Meta Only</button>';
                        }

                        // Checkbox — only for pages eligible for refresh (not page type, not in queue, not on cooldown)
                        var canSelect = (currentType !== 'page' && !r.in_queue && !refreshedRecently);
                        var checkbox = canSelect
                            ? '<input type="checkbox" class="seom-idx-cb" value="' + r.post_id + '" />'
                            : '';

                        tbody.append('<tr>' +
                            '<td>' + checkbox + '</td>' +
                            '<td><a href="' + editUrl + '" target="_blank">' + r.post_title + '</a>' +
                                '<br><small style="color:#94a3b8;"><a href="' + viewUrl + '" target="_blank" style="color:#94a3b8;">' + (viewUrl || '') + '</a></small>' +
                            '</td>' +
                            '<td>' + wasBadge + '</td>' +
                            '<td>' + nowBadge + '</td>' +
                            '<td>' + clicks.toLocaleString() + '</td>' +
                            '<td style="font-size:12px;">' + clickDelta + '</td>' +
                            '<td>' + imp.toLocaleString() + '</td>' +
                            '<td style="font-size:12px;">' + impDelta + '</td>' +
                            '<td>' + (pos > 0 ? pos.toFixed(1) : '-') + '</td>' +
                            '<td style="font-size:12px;">' + posDelta + '</td>' +
                            '<td>' + parseFloat(r.ctr).toFixed(1) + '%</td>' +
                            '<td style="font-size:12px;">' + published + '</td>' +
                            '<td style="font-size:12px;">' + lastRefresh + '</td>' +
                            '<td>' + actions + '</td>' +
                        '</tr>');
                    });

                    $('#seom-indexed-table').show();

                    // Show bulk bar if not viewing pages-only
                    $('#seom-indexed-bulk-bar').toggle(currentType !== 'page');
                    $('#seom-idx-check-all').prop('checked', false);

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
                }, error: function() {
                    $('#seom-indexed-loading').hide().after('<div style="color:#dc2626;margin:8px 0;">Failed to load data. If using a non-default date range, the GSC API call may have timed out — try again.</div>');
                }});
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
                ghost: 'Pages with zero impressions in the last 28 days that are older than 14 days — Google is not showing these in any search results.',
                'new': 'Pages published within the last 14 days. These are still being indexed by Google — give them time before evaluating performance.',
                page2: 'Ranking position 11–20 with 50+ impressions. These are close to breaking into page 1 — highest ROI for content refresh.',
                low_ctr: 'CTR below 50% of the expected benchmark for the page\'s ranking position (e.g., Position 1 should be ~30% CTR, Position 5 should be ~7%). Title and meta description need improvement.',
                page1: 'Ranking on page 1 (position 1–10) with impressions. Monitor for changes.',
                limited: 'Has some visibility but not enough to matter: fewer than 100 impressions, or ranking position 30+ (page 3 and beyond).',
                top_performers: 'Pages whose CTR meets at least 60% of the expected benchmark for their position. Position 1 needs 18%+ CTR, Position 5 needs 4.2%+, Position 10 needs 1.8%+. These are protected from automated refresh.',
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

            // Date range change
            $('#seom-date-range').change(function() {
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

            // Select all (only selects visible checkboxes — skips cooldown/in-queue rows)
            $(document).on('change', '#seom-idx-check-all', function() {
                $('.seom-idx-cb').prop('checked', $(this).prop('checked'));
            });

            // Bulk apply — add selected to queue
            $('#seom-indexed-bulk-apply').click(function() {
                var action = $('#seom-indexed-bulk-action').val();
                if (!action) { alert('Select a bulk action.'); return; }

                var ids = [];
                $('.seom-idx-cb:checked').each(function() { ids.push(parseInt($(this).val())); });
                if (!ids.length) { alert('Select at least one page.'); return; }

                if (!confirm('Add ' + ids.length + ' page(s) to the refresh queue as "' + (action === 'full' ? 'Full Refresh' : 'Meta Only') + '"?')) return;

                var statusEl = $('#seom-indexed-bulk-status');
                statusEl.html('<span style="color:#b45309;">Adding 0/' + ids.length + '...</span>');
                var completed = 0, failed = 0;

                function addNext(idx) {
                    if (idx >= ids.length) {
                        statusEl.html('<span style="color:#059669;">' + completed + ' added to queue' + (failed ? ', ' + failed + ' failed' : '') + '.</span>');
                        setTimeout(function() { loadIndexed(currentPage); }, 1500);
                        return;
                    }
                    $.post(ajaxurl, {
                        action: 'seom_add_to_queue', nonce: seom_nonce,
                        post_id: ids[idx], refresh_type: action, priority: 50
                    }, function(resp) {
                        if (resp.success) completed++; else failed++;
                        statusEl.html('<span style="color:#b45309;">Adding ' + (idx + 1) + '/' + ids.length + '...</span>');
                        addNext(idx + 1);
                    }).fail(function() { failed++; addNext(idx + 1); });
                }
                addNext(0);
            });

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

        <div style="margin-bottom:8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
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
        <div style="margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <strong>Trend:</strong>
            <button type="button" class="button seom-tracker-trend active" data-trend="all">All</button>
            <button type="button" class="button seom-tracker-trend" data-trend="strong" style="color:#059669;">Strong</button>
            <button type="button" class="button seom-tracker-trend" data-trend="clicks_up" style="color:#059669;">Clicks Up</button>
            <button type="button" class="button seom-tracker-trend" data-trend="ranking_up" style="color:#059669;">Ranking Up</button>
            <button type="button" class="button seom-tracker-trend" data-trend="declining" style="color:#dc2626;">Declining</button>
            <button type="button" class="button seom-tracker-trend" data-trend="clicks_down" style="color:#dc2626;">Clicks Down</button>
            <button type="button" class="button seom-tracker-trend" data-trend="ranking_down" style="color:#dc2626;">Ranking Down</button>
            <button type="button" class="button seom-tracker-trend" data-trend="too_early" style="color:#d97706;">Too Early</button>
            <button type="button" class="button seom-tracker-trend" data-trend="flat">Flat</button>
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
                <div class="seom-card">
                    <h3>Impression Trend</h3>
                    <div class="seom-card-value" id="st-imp-trend">-</div>
                    <div class="seom-card-sub" id="st-imp-trend-detail"></div>
                </div>
            </div>

            <table class="seom-table" id="seom-tracker-table">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th>Was</th>
                        <th>Now</th>
                        <th>Type</th>
                        <th>Refreshed</th>
                        <th>Days</th>
                        <th>Clicks Before</th>
                        <th>Clicks Now</th>
                        <th>Click &Delta;</th>
                        <th>Impr Before</th>
                        <th>Impr Now</th>
                        <th>Impr &Delta;</th>
                        <th>Pos Before</th>
                        <th>Pos Now</th>
                        <th>Pos &Delta;</th>
                        <th>Trend</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="seom-tracker-body"></tbody>
            </table>

            <div id="seom-tracker-pagination" style="margin-top:12px;"></div>
        </div>

        <!-- All Pages Performance Trends removed — merged into Indexed Pages tab -->

        <script>
        jQuery(document).ready(function($) {
            var trackerDays = 14;
            var trackerType = 'all';
            var trackerTrend = 'all';
            var trackerPage = 1;

            function getTrend(clickDiff, posDiff, daysSince) {
                var isPosImproved = posDiff < -0.3;
                var isPosDeclined = posDiff > 0.3;

                if (clickDiff > 0 && isPosImproved) return { key: 'strong', html: '<span class="seom-change-positive" style="font-weight:700;">&#9650;&#9650; Strong</span>' };
                if (clickDiff > 0) return { key: 'clicks_up', html: '<span class="seom-change-positive">&#9650; Clicks Up</span>' };
                if (isPosImproved && clickDiff >= 0) return { key: 'ranking_up', html: '<span class="seom-change-positive">&#9650; Ranking Up</span>' };
                if (clickDiff < 0 && isPosDeclined) return { key: 'declining', html: '<span class="seom-change-negative" style="font-weight:700;">&#9660;&#9660; Declining</span>' };
                if (clickDiff < 0) return { key: 'clicks_down', html: '<span class="seom-change-negative">&#9660; Clicks Down</span>' };
                if (isPosDeclined) return { key: 'ranking_down', html: '<span class="seom-change-negative">&#9660; Ranking Down</span>' };
                if (daysSince < 5) return { key: 'too_early', html: '<span style="color:#d97706;">&#9203; Too early</span>' };
                return { key: 'flat', html: '<span style="color:#94a3b8;">&#9654; Flat</span>' };
            }

            var trackerCache = null; // Cache all rows for client-side trend filtering

            function loadTracker(page) {
                trackerPage = page || 1;
                $('#seom-tracker-loading').show().text('Loading...');
                $('#seom-tracker-content').hide();
                $('#seom-tracker-pagination').empty();

                // If we have cached data and only the trend filter changed, reuse it
                if (trackerCache && trackerCache.type === trackerType && trackerCache.days === trackerDays) {
                    renderTracker(trackerCache.data, trackerPage);
                    return;
                }

                $.post(ajaxurl, {
                    action: 'seom_get_tracker', nonce: seom_nonce,
                    page: 1, per_page: 9999, days: trackerDays, post_type: trackerType
                }, function(resp) {
                    if (!resp.success) { $('#seom-tracker-loading').hide(); return; }
                    trackerCache = { type: trackerType, days: trackerDays, data: resp.data };
                    renderTracker(resp.data, 1);
                });
            }

            var catLabels = {A:'Ghost',B:'CTR Fix',C:'Near Win',D:'Declining',E:'Visible/Ignored',F:'Buried',M:'Manual'};

            function renderTracker(d, page) {
                $('#seom-tracker-loading').hide();
                $('#seom-tracker-content').show();

                // Summary
                $('#st-total').text(d.summary.total);
                $('#st-improving').text(d.summary.improving);
                $('#st-declining').text(d.summary.declining);

                var before = d.summary.total_clicks_before || 0;
                var now = d.summary.total_clicks_now || 0;
                var diff = now - before;
                var pct = before > 0 ? Math.round((diff / before) * 100) : 0;
                var trendText = (diff > 0 ? '+' : '') + diff;
                var trendClass = diff > 0 ? 'seom-change-positive' : (diff < 0 ? 'seom-change-negative' : '');
                var trendIcon = diff > 0 ? '&#9650; ' : (diff < 0 ? '&#9660; ' : '');
                $('#st-trend').html('<span class="' + trendClass + '">' + trendIcon + trendText + '</span>');
                $('#st-trend-detail').text(before + ' before → ' + now + ' now (' + (pct > 0 ? '+' : '') + pct + '%)');

                var impBefore = d.summary.total_impressions_before || 0;
                var impNow = d.summary.total_impressions_now || 0;
                var impDiff = impNow - impBefore;
                var impPct = impBefore > 0 ? Math.round((impDiff / impBefore) * 100) : 0;
                var impTrendText = (impDiff > 0 ? '+' : '') + impDiff.toLocaleString();
                var impTrendClass = impDiff > 0 ? 'seom-change-positive' : (impDiff < 0 ? 'seom-change-negative' : '');
                var impTrendIcon = impDiff > 0 ? '&#9650; ' : (impDiff < 0 ? '&#9660; ' : '');
                $('#st-imp-trend').html('<span class="' + impTrendClass + '">' + impTrendIcon + impTrendText + '</span>');
                $('#st-imp-trend-detail').text(impBefore.toLocaleString() + ' before → ' + impNow.toLocaleString() + ' now (' + (impPct > 0 ? '+' : '') + impPct + '%)');

                $('#st-flat').text(d.summary.flat);

                // Filter rows by trend
                var filtered = [];
                d.rows.forEach(function(r) {
                    var clickDiff = parseInt(r.click_change || 0);
                    var posDiff = parseFloat(r.position_change || 0);
                    var trend = getTrend(clickDiff, posDiff, parseInt(r.days_since));
                    r._trend = trend;
                    if (trackerTrend === 'all' || trend.key === trackerTrend) {
                        filtered.push(r);
                    }
                });

                // Client-side pagination
                var perPage = 25;
                var totalFiltered = filtered.length;
                var totalPages = Math.ceil(totalFiltered / perPage);
                page = Math.min(page, totalPages || 1);
                var startIdx = (page - 1) * perPage;
                var pageRows = filtered.slice(startIdx, startIdx + perPage);

                var tbody = $('#seom-tracker-body').empty();
                if (!pageRows.length) {
                    tbody.html('<tr><td colspan="17" style="color:#94a3b8;">No matching refreshes found.</td></tr>');
                    $('#seom-tracker-pagination').empty();
                    return;
                }

                // Derive current status from metrics
                function trackerCurrentStatus(clicks, impressions, position) {
                    clicks = parseInt(clicks) || 0;
                    impressions = parseInt(impressions) || 0;
                    position = parseFloat(position) || 0;
                    if (impressions === 0) return {label: 'Ghost', cls: 'a'};
                    if (position > 0 && position <= 10) return {label: 'Page 1', cls: 'page1'};
                    if (position > 10 && position <= 20) return {label: 'Page 2', cls: 'c'};
                    if (position > 20) return {label: 'Page ' + Math.ceil(position/10), cls: 'f'};
                    return {label: '-', cls: ''};
                }

                pageRows.forEach(function(r) {
                    var clickDiff = parseInt(r.click_change || 0);
                    var posDiff = parseFloat(r.position_change || 0);
                    var clickClass = clickDiff > 0 ? 'seom-change-positive' : (clickDiff < 0 ? 'seom-change-negative' : '');
                    var posClass = posDiff < -0.1 ? 'seom-change-positive' : (posDiff > 0.1 ? 'seom-change-negative' : '');
                    var posStr = posDiff !== 0 ? (posDiff > 0 ? '+' : '') + posDiff.toFixed(1) : '0.0';
                    var editUrl = '<?php echo admin_url("post.php?action=edit&post="); ?>' + r.post_id;

                    var tCat = r.category || '?';
                    var tWasBadge = '<span class="seom-badge seom-badge-' + tCat.toLowerCase() + '">' + (catLabels[tCat] || tCat) + '</span>';
                    var tNowStatus = trackerCurrentStatus(r.clicks_now, r.impressions_now, r.position_now);
                    var tNowBadge = '<span class="seom-badge seom-badge-' + tNowStatus.cls + '">' + tNowStatus.label + '</span>';
                    var impBefore = parseInt(r.impressions_before || 0);
                    var impNow = parseInt(r.impressions_now || 0);
                    var impDiff = impNow - impBefore;
                    var impClass = impDiff > 0 ? 'seom-change-positive' : (impDiff < 0 ? 'seom-change-negative' : '');

                    tbody.append('<tr>' +
                        '<td><a href="' + editUrl + '" target="_blank">' + r.post_title + '</a>' +
                            '<br><small style="color:#94a3b8;">' + (r.post_type || '') + '</small></td>' +
                        '<td>' + tWasBadge + '</td>' +
                        '<td>' + tNowBadge + '</td>' +
                        '<td>' + (r.refresh_type || 'full') + '</td>' +
                        '<td>' + (r.refresh_date || '').substring(0, 10) + '</td>' +
                        '<td>' + r.days_since + '</td>' +
                        '<td>' + (r.clicks_before || 0) + '</td>' +
                        '<td>' + (r.clicks_now || 0) + '</td>' +
                        '<td class="' + clickClass + '">' + (clickDiff > 0 ? '+' : '') + clickDiff + '</td>' +
                        '<td>' + impBefore.toLocaleString() + '</td>' +
                        '<td>' + impNow.toLocaleString() + '</td>' +
                        '<td class="' + impClass + '">' + (impDiff > 0 ? '+' : '') + impDiff.toLocaleString() + '</td>' +
                        '<td>' + parseFloat(r.position_before || 0).toFixed(1) + '</td>' +
                        '<td class="' + posClass + '">' + parseFloat(r.position_now || 0).toFixed(1) + '</td>' +
                        '<td class="' + posClass + '">' + posStr + '</td>' +
                        '<td>' + r._trend.html + '</td>' +
                        '<td>' +
                            '<a href="' + editUrl + '" class="button button-small" target="_blank">Edit</a> ' +
                            '<a href="/?p=' + r.post_id + '" class="button button-small" target="_blank">View</a>' +
                        '</td>' +
                    '</tr>');
                });

                // Pagination
                var pag = $('#seom-tracker-pagination').empty();
                if (totalPages > 1) {
                    for (var i = 1; i <= totalPages; i++) {
                        var cls = i === page ? 'button button-primary' : 'button';
                        pag.append('<button class="' + cls + ' seom-tracker-page" data-page="' + i + '">' + i + '</button> ');
                    }
                }
                pag.append('<span style="margin-left:12px;color:#64748b;font-size:13px;">' + totalFiltered + ' of ' + d.rows.length + ' refreshes</span>');
            }

            // Period toggle
            $('.seom-tracker-type').click(function() {
                $('.seom-tracker-type').removeClass('active');
                $(this).addClass('active');
                trackerType = $(this).data('type');
                trackerCache = null; // Invalidate — need fresh data
                loadTracker(1);
            });

            $('.seom-tracker-days').click(function() {
                $('.seom-tracker-days').removeClass('active');
                $(this).addClass('active');
                trackerDays = parseInt($(this).data('days'));
                trackerCache = null; // Invalidate — need fresh data
                loadTracker(1);
            });

            $('.seom-tracker-trend').click(function() {
                $('.seom-tracker-trend').removeClass('active');
                $(this).addClass('active');
                trackerTrend = $(this).data('trend');
                // No cache invalidation — reuses cached data, just re-filters
                loadTracker(1);
            });

            // Pagination
            $(document).on('click', '.seom-tracker-page', function() {
                loadTracker(parseInt($(this).data('page')));
            });

            loadTracker(1);
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
                <option value="process_now">Process Now</option>
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
            var catLabels = {A:'Ghost',B:'CTR Fix',C:'Near Win',D:'Declining',E:'Visible/Ignored',F:'Buried',M:'Manual'};
            var catDescriptions = {
                A: 'Zero impressions — Google is not showing this page',
                B: 'Ranks on page 1 but low click-through rate',
                C: 'Ranking on page 2 — close to breaking into page 1',
                D: 'Traffic has declined significantly vs prior period',
                E: 'High impressions but almost no clicks',
                F: 'Page 3+ with impressions — Google sees relevance but ranks poorly',
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

            var seomStepLabels = {
                1: 'Initializing...',
                2: 'Generating content...',
                3: 'Meta description...',
                4: 'FAQ & Schema...',
                5: 'SEO keyword & title...',
                6: 'RankMath & SEO title...',
                7: 'Finalizing...'
            };

            function runSeomSteps(postId, msgEl, callback) {
                var currentStep = 1;
                var totalSteps = 7;

                function runStep() {
                    var label = seomStepLabels[currentStep] || ('Step ' + currentStep + '...');
                    msgEl.html('<span style="color:#b45309;">Step ' + currentStep + '/' + totalSteps + ': ' + label + '</span>');

                    $.ajax({ url: ajaxurl, method: 'POST', timeout: 180000,
                        data: { action: 'seom_process_step', nonce: seom_nonce, post_id: postId, step: currentStep },
                        success: function(resp) {
                            if (resp.success) {
                                if (resp.data.complete) {
                                    callback(true, resp.data);
                                } else {
                                    if (resp.data.total_steps) totalSteps = resp.data.total_steps;
                                    currentStep++;
                                    runStep();
                                }
                            } else {
                                msgEl.html('<span style="color:#dc2626;">&#10007; ' + (resp.data || 'Unknown error') + '</span>');
                                callback(false);
                            }
                        },
                        error: function(xhr, status) {
                            var msg = status === 'timeout' ? 'Timed out' : 'HTTP ' + (xhr.status || '?');
                            msgEl.html('<span style="color:#dc2626;">&#10007; ' + msg + ' at step ' + currentStep + ': ' + label + '</span>');
                            callback(false);
                        }
                    });
                }
                runStep();
            }

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
                $('.seom-process-btn').not(btn).prop('disabled', true);
                btn.after('<br><small class="seom-process-msg" style="color:#b45309;">Initializing...</small>');
                var msgEl = row.find('.seom-process-msg');

                runSeomSteps(postId, msgEl, function(success, data) {
                    isProcessing = false;
                    $('.seom-process-btn').not(btn).prop('disabled', false);
                    if (success) {
                        if (data.dry_run) {
                            row.css('opacity', '1');
                            btn.prop('disabled', false).text('Process');
                            msgEl.html('<span style="color:#b45309;font-weight:600;">DRY RUN — No changes made.</span>');
                        } else {
                            row.css('opacity', '0.4');
                            btn.text('Done');
                            msgEl.html('<span style="color:#16a34a;">&#10003; Completed!</span>');
                        }
                    } else {
                        row.css('opacity', '1');
                        btn.prop('disabled', false).text('Retry');
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

                // Process Now — sequential processing bypassing daily limits
                if (action === 'process_now') {
                    if (isProcessing) {
                        alert('A refresh is already running. Please wait for it to finish.');
                        return;
                    }
                    if (!confirm('Process ' + ids.length + ' item(s) now? This bypasses daily limits and may take 1-2 minutes per item.')) return;

                    isProcessing = true;
                    $('.seom-process-btn, .seom-skip-btn').prop('disabled', true);
                    $('#seom-bulk-apply, #seom-clear-queue').prop('disabled', true);

                    // Gather post IDs from checked rows
                    var postIds = [];
                    $('.seom-queue-cb:checked').each(function() {
                        var row = $(this).closest('tr');
                        var postId = row.find('.seom-process-btn').data('post-id');
                        if (postId) postIds.push({ postId: postId, row: row });
                    });

                    var completed = 0, failed = 0, total = postIds.length;
                    $('#seom-bulk-status').html('<span style="color:#b45309;">Processing 0/' + total + '... please keep this tab open.</span>');

                    function processNext(index) {
                        if (index >= total) {
                            isProcessing = false;
                            $('.seom-process-btn, .seom-skip-btn').prop('disabled', false);
                            $('#seom-bulk-apply, #seom-clear-queue').prop('disabled', false);
                            $('#seom-bulk-status').html('<span style="color:#059669;">Bulk process complete: ' + completed + ' succeeded, ' + failed + ' failed out of ' + total + '.</span>');
                            setTimeout(function() { loadQueue(); }, 2000);
                            return;
                        }

                        var item = postIds[index];
                        item.row.css('opacity', '0.7');
                        var btn = item.row.find('.seom-process-btn').text('Processing...');
                        if (!item.row.find('.seom-process-msg').length) {
                            btn.after('<br><small class="seom-process-msg"></small>');
                        }
                        var msgEl = item.row.find('.seom-process-msg');
                        $('#seom-bulk-status').html('<span style="color:#b45309;">Processing ' + (index + 1) + '/' + total + '...</span>');

                        runSeomSteps(item.postId, msgEl, function(success, data) {
                            if (success) {
                                completed++;
                                item.row.css('opacity', '0.4');
                                btn.text('Done');
                                msgEl.html('<span style="color:#16a34a;">&#10003; Completed</span>');
                            } else {
                                failed++;
                                item.row.css('opacity', '1');
                                btn.text('Failed');
                            }
                            processNext(index + 1);
                        });
                    }

                    processNext(0);
                    return;
                }

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
            var catLabels = {A:'Ghost',B:'CTR',C:'Near Win',D:'Decline',E:'Visible',F:'Buried',M:'Manual'};

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
                <div class="seom-card">
                    <h3>Lost Keywords</h3>
                    <div class="seom-card-value seom-change-negative" id="sk-lost">-</div>
                    <div class="seom-card-sub">No longer ranking</div>
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
                <button type="button" class="button seom-kw-filter" data-filter="lost" style="color:#dc2626;">Lost Keywords</button>
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
                        if (s.lost !== undefined) $('#sk-lost').text(s.lost);
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

            // Collect keywords — batched
            $('#seom-collect-kw').click(function() {
                var btn = $(this).prop('disabled', true).text('Collecting...');
                $('#seom-kw-action-status').text('Fetching keyword data from GSC...');

                function runBatch(page) {
                    $.ajax({
                        url: ajaxurl, method: 'POST', timeout: 180000,
                        data: { action: 'seom_collect_keywords', nonce: seom_nonce, batch_page: page },
                        success: function(resp) {
                            if (!resp.success) {
                                btn.prop('disabled', false).text('Collect Keywords from GSC');
                                $('#seom-kw-action-status').html('<span style="color:#dc2626;">Error: ' + (resp.data || 'Unknown') + '</span>');
                                return;
                            }
                            var d = resp.data;
                            if (d.phase === 'gsc_fetched') {
                                $('#seom-kw-action-status').text('GSC data fetched (' + d.total_queries + ' queries). Processing batch 1/' + d.total_batches + '...');
                                runBatch(1);
                            } else if (d.phase === 'processing') {
                                var pct = Math.round((d.batch * 200 / d.total) * 100);
                                $('#seom-kw-action-status').text('Processing... batch ' + d.batch + ' (' + Math.min(pct, 99) + '%)');
                                runBatch(d.batch + 1);
                            } else if (d.phase === 'complete') {
                                btn.prop('disabled', false).text('Collect Keywords from GSC');
                                $('#seom-kw-action-status').html('<span style="color:#059669;">' + d.keywords_collected + ' keywords processed. ' + d.content_gaps + ' content gaps, ' + d.rising + ' rising, ' + (d.lost || 0) + ' lost.</span>');
                                loadKeywords(1);
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('Collect Keywords from GSC');
                            $('#seom-kw-action-status').text('Server error/timeout. Try again.');
                        }
                    });
                }

                runBatch(0);
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

    // ─── Keyword Gaps Tab ────────────────────────────────────────────────────

    private static function render_keyword_gaps($nonce) {
        ?>
        <h2>Keyword Gaps</h2>
        <p style="color:#64748b;">Import competitive keyword gap data from SEMrush/Ahrefs. Auto-tag keywords into topical categories for strategic blog content planning.</p>

        <!-- Import Section -->
        <div style="margin-bottom:24px;padding:20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
            <h3 style="margin-top:0;">Import Keywords</h3>
            <p style="color:#64748b;font-size:13px;">Paste CSV data from your SEMrush/Ahrefs keyword gap export. Must include a "Keyword" column. Volume, Difficulty, CPC, and Intent columns are auto-detected.</p>
            <textarea id="seom-gap-csv" rows="6" style="width:100%;font-family:monospace;font-size:12px;padding:12px;border:1px solid #e2e8f0;border-radius:6px;" placeholder="Paste CSV data here..."></textarea>
            <div style="display:flex;gap:8px;align-items:center;margin-top:8px;">
                <select id="seom-gap-source" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;">
                    <option value="semrush">SEMrush</option>
                    <option value="ahrefs">Ahrefs</option>
                    <option value="other">Other</option>
                </select>
                <button type="button" class="button button-primary" id="seom-gap-import">Import Keywords</button>
                <button type="button" class="button" id="seom-gap-autotag">Auto-Tag Untagged (AI)</button>
                <button type="button" class="button" id="seom-gap-consolidate" style="color:#7c3aed;">Consolidate Tags (AI)</button>
                <button type="button" class="button" id="seom-gap-cleanup" style="color:#94a3b8;">Clean Non-English</button>
                <span id="seom-gap-import-status" style="font-size:13px;margin-left:8px;"></span>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="seom-cards" id="seom-gap-summary" style="margin-bottom:16px;">
            <div class="seom-card"><h3>Total Keywords</h3><div class="seom-card-value" id="sg-total">-</div></div>
            <div class="seom-card"><h3>Untagged</h3><div class="seom-card-value" style="color:#d97706;" id="sg-untagged">-</div></div>
            <div class="seom-card"><h3>Categories</h3><div class="seom-card-value" id="sg-categories">-</div></div>
            <div class="seom-card"><h3>Avg Volume</h3><div class="seom-card-value" id="sg-avg-vol">-</div></div>
        </div>

        <!-- Filters -->
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
            <strong>Tag:</strong>
            <select id="seom-gap-tag-filter" style="padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;min-width:200px;">
                <option value="all">All Tags</option>
                <option value="untagged">Untagged</option>
            </select>
            <strong>Filter:</strong>
            <select id="seom-gap-filter" style="padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;">
                <option value="all">All</option>
                <option value="high_volume">High Volume (1000+)</option>
                <option value="low_difficulty">Low Difficulty (≤30)</option>
                <option value="quick_wins">Quick Wins (Vol 500+ & KD ≤40)</option>
                <option value="not_ranking">Not Ranking</option>
                <option value="on_cooldown">On Cooldown (Recently Used)</option>
                <option value="available">Available (Not on Cooldown)</option>
            </select>
            <input type="text" id="seom-gap-search" placeholder="Search keywords..." style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;width:200px;" />
        </div>

        <!-- Bulk Actions -->
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
            <select id="seom-gap-bulk-action" style="padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;">
                <option value="">Bulk Actions</option>
                <option value="tag">Set Tag</option>
                <option value="delete">Delete Selected</option>
            </select>
            <input type="text" id="seom-gap-bulk-tag" placeholder="Tag name..." style="padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;width:180px;display:none;" />
            <button type="button" class="button" id="seom-gap-bulk-apply">Apply</button>
            <span id="seom-gap-bulk-status" style="font-size:12px;margin-left:8px;"></span>
        </div>

        <!-- Table -->
        <div id="seom-gap-loading">Loading...</div>
        <table class="seom-table" id="seom-gap-table" style="display:none;">
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" id="seom-gap-check-all" /></th>
                    <th class="seom-gap-sort" data-col="keyword" style="cursor:pointer;">Keyword</th>
                    <th class="seom-gap-sort" data-col="search_volume" style="cursor:pointer;width:90px;">Volume</th>
                    <th class="seom-gap-sort" data-col="keyword_difficulty" style="cursor:pointer;width:60px;">KD</th>
                    <th style="width:60px;">CPC</th>
                    <th style="width:120px;">Intent</th>
                    <th class="seom-gap-sort" data-col="your_position" style="cursor:pointer;width:60px;">You</th>
                    <th style="width:60px;">Comp 1</th>
                    <th style="width:60px;">Comp 2</th>
                    <th class="seom-gap-sort" data-col="tag" style="cursor:pointer;width:150px;">Tag</th>
                </tr>
            </thead>
            <tbody id="seom-gap-body"></tbody>
        </table>
        <div id="seom-gap-pagination" style="margin-top:12px;"></div>

        <script>
        jQuery(document).ready(function($) {
            var gapSort = 'search_volume', gapOrder = 'DESC', gapPage = 1;

            function loadGaps(page) {
                gapPage = page || 1;
                $('#seom-gap-loading').show();
                $('#seom-gap-table').hide();

                $.post(ajaxurl, {
                    action: 'seom_get_keyword_gaps', nonce: seom_nonce,
                    page: gapPage, tag: $('#seom-gap-tag-filter').val(),
                    filter: $('#seom-gap-filter').val(), search: $('#seom-gap-search').val(),
                    sort: gapSort, order: gapOrder
                }, function(resp) {
                    $('#seom-gap-loading').hide();
                    if (!resp.success) return;
                    var d = resp.data;

                    // Summary
                    if (d.summary) {
                        $('#sg-total').text(parseInt(d.summary.total_keywords || 0).toLocaleString());
                        $('#sg-untagged').text(parseInt(d.summary.untagged || 0).toLocaleString());
                        $('#sg-categories').text(parseInt(d.summary.tag_count || 0));
                        $('#sg-avg-vol').text(Math.round(d.summary.avg_volume || 0).toLocaleString());
                    }

                    // Tag filter dropdown
                    var tagSelect = $('#seom-gap-tag-filter');
                    var currentTag = tagSelect.val();
                    tagSelect.find('option:gt(1)').remove();
                    (d.tags || []).forEach(function(t) {
                        tagSelect.append('<option value="' + t + '">' + t + '</option>');
                    });
                    tagSelect.val(currentTag);

                    // Table
                    var tbody = $('#seom-gap-body').empty();
                    if (!d.rows.length) {
                        tbody.html('<tr><td colspan="10" style="color:#94a3b8;">No keywords found. Import a CSV to get started.</td></tr>');
                        $('#seom-gap-table').show();
                        return;
                    }

                    d.rows.forEach(function(r) {
                        var vol = parseInt(r.search_volume);
                        var kd = parseInt(r.keyword_difficulty);
                        var kdClass = kd <= 30 ? 'color:#059669' : (kd <= 50 ? 'color:#d97706' : 'color:#dc2626');
                        var yourPos = parseInt(r.your_position);
                        var posLabel = yourPos === 0 ? '<span style="color:#dc2626;">—</span>' : yourPos;
                        var tagBadge = r.tag ? '<span class="seom-badge" style="background:#eff6ff;color:#2563eb;font-size:11px;">' + r.tag + '</span>' : '<span style="color:#d97706;font-size:11px;">untagged</span>';
                        var cooldownBadge = '';
                        if (r.last_used_at) {
                            cooldownBadge = '<br><span style="font-size:10px;color:#94a3b8;">Used ' + r.last_used_at + '</span>';
                        }
                        if (r.linked_post) {
                            var lp = r.linked_post;
                            var typeLabel = lp.type === 'product' ? 'Product' : 'Post';
                            cooldownBadge += '<br><span style="font-size:10px;">'
                                + '<a href="' + lp.url + '" target="_blank" style="color:#2563eb;" title="' + typeLabel + ': ' + lp.title + '">' + lp.title.substring(0, 50) + (lp.title.length > 50 ? '...' : '') + '</a> '
                                + '<a href="' + lp.edit + '" target="_blank" style="color:#94a3b8;font-size:9px;">[edit]</a>'
                                + '</span>';
                        }

                        tbody.append('<tr data-id="' + r.id + '">' +
                            '<td><input type="checkbox" class="seom-gap-cb" value="' + r.id + '" /></td>' +
                            '<td><strong>' + r.keyword + '</strong>' + cooldownBadge + '</td>' +
                            '<td>' + vol.toLocaleString() + '</td>' +
                            '<td style="' + kdClass + ';font-weight:600;">' + kd + '</td>' +
                            '<td>$' + parseFloat(r.cpc || 0).toFixed(2) + '</td>' +
                            '<td style="font-size:11px;">' + (r.intent || '-') + '</td>' +
                            '<td>' + posLabel + '</td>' +
                            '<td>' + (parseInt(r.competitor_1_position) || '-') + '</td>' +
                            '<td>' + (parseInt(r.competitor_2_position) || '-') + '</td>' +
                            '<td>' + tagBadge + '</td>' +
                        '</tr>');
                    });

                    $('#seom-gap-table').show();

                    // Pagination
                    var pag = $('#seom-gap-pagination').empty();
                    if (d.pages > 1) {
                        for (var i = 1; i <= Math.min(d.pages, 20); i++) {
                            pag.append('<button class="' + (i === d.page ? 'button button-primary' : 'button') + ' seom-gap-page" data-page="' + i + '">' + i + '</button> ');
                        }
                    }
                    pag.append('<span style="margin-left:12px;color:#64748b;font-size:13px;">' + d.total + ' keyword(s)</span>');
                });
            }

            // Import — batched
            $('#seom-gap-import').click(function() {
                var csv = $('#seom-gap-csv').val().trim();
                if (!csv) { alert('Paste CSV data first.'); return; }
                var btn = $(this).prop('disabled', true).text('Importing...');
                var src = $('#seom-gap-source').val();
                $('#seom-gap-import-status').html('<span style="color:#b45309;">Parsing CSV...</span>');

                function runImportPhase(phase, csvData) {
                    var postData = { action: 'seom_import_keyword_gaps', nonce: seom_nonce, phase: phase, source: src };
                    if (csvData) postData.csv_data = csvData;

                    $.ajax({ url: ajaxurl, method: 'POST', timeout: 120000, data: postData,
                        success: function(resp) {
                            if (!resp.success) {
                                btn.prop('disabled', false).text('Import Keywords');
                                $('#seom-gap-import-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                                return;
                            }
                            var d = resp.data;
                            if (d.phase === 'parsed') {
                                $('#seom-gap-import-status').html('<span style="color:#b45309;">Parsed ' + d.total + ' keywords (' + d.skipped + ' skipped). Importing batch 1/' + d.batches + '...</span>');
                                runImportPhase(1);
                            } else if (d.phase === 'importing') {
                                var pct = Math.round((d.processed / d.total) * 100);
                                $('#seom-gap-import-status').html('<span style="color:#b45309;">Importing... ' + d.processed + '/' + d.total + ' (' + pct + '%)</span>');
                                runImportPhase(d.batch + 1);
                            } else if (d.phase === 'complete') {
                                btn.prop('disabled', false).text('Import Keywords');
                                var msg = d.imported + ' keywords imported.';
                                if (d.skipped) msg += ' ' + d.skipped + ' skipped.';
                                if (d.restored) msg += ' ' + d.restored + ' usage records restored.';
                                $('#seom-gap-import-status').html('<span style="color:#059669;">' + msg + '</span>');
                                $('#seom-gap-csv').val('');
                                loadGaps(1);
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('Import Keywords');
                            $('#seom-gap-import-status').html('<span style="color:#dc2626;">Server error/timeout. Try again.</span>');
                        }
                    });
                }

                runImportPhase(0, csv);
            });

            // Auto-tag
            $('#seom-gap-autotag').click(function() {
                var btn = $(this).prop('disabled', true).text('Tagging...');
                $('#seom-gap-import-status').html('<span style="color:#b45309;">AI is categorizing keywords...</span>');
                var totalTagged = 0;

                function tagBatch() {
                    $.post(ajaxurl, {
                        action: 'seom_autotag_gaps', nonce: seom_nonce, batch: 40
                    }, function(resp) {
                        if (resp.success) {
                            totalTagged += resp.data.tagged;
                            $('#seom-gap-import-status').html('<span style="color:#b45309;">' + totalTagged + ' tagged so far. ' + resp.data.remaining + ' remaining...</span>');
                            if (resp.data.remaining > 0) {
                                tagBatch();
                            } else {
                                btn.prop('disabled', false).text('Auto-Tag Untagged (AI)');
                                $('#seom-gap-import-status').html('<span style="color:#059669;">Done! ' + totalTagged + ' keywords tagged.</span>');
                                loadGaps(1);
                            }
                        } else {
                            btn.prop('disabled', false).text('Auto-Tag Untagged (AI)');
                            $('#seom-gap-import-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + ' (' + totalTagged + ' tagged before error)</span>');
                        }
                    });
                }
                tagBatch();
            });

            // Clean non-English keywords
            $('#seom-gap-cleanup').click(function() {
                if (!confirm('Remove all non-English keywords from the gap table?')) return;
                var btn = $(this).prop('disabled', true);
                $.post(ajaxurl, { action: 'seom_cleanup_gap_keywords', nonce: seom_nonce }, function(resp) {
                    btn.prop('disabled', false);
                    if (resp.success) {
                        $('#seom-gap-import-status').html('<span style="color:#059669;">Removed ' + resp.data.deleted + ' non-English keywords.</span>');
                        loadGaps(1);
                    }
                });
            });

            // Consolidate tags
            $('#seom-gap-consolidate').click(function() {
                var target = prompt('Consolidate into how many categories?', '35');
                if (!target) return;
                var btn = $(this).prop('disabled', true).text('Consolidating...');
                var statusEl = $('#seom-gap-import-status');
                statusEl.html('<span style="color:#b45309;">Step 1: Deduplicating tags programmatically...</span>');

                function consolidateCall(postData, onSuccess) {
                    postData.action = 'seom_consolidate_gap_tags';
                    postData.nonce = seom_nonce;
                    $.ajax({ url: ajaxurl, method: 'POST', timeout: 120000, data: postData,
                        success: function(resp) {
                            if (!resp.success) {
                                btn.prop('disabled', false).text('Consolidate Tags (AI)');
                                statusEl.html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                                return;
                            }
                            onSuccess(resp.data);
                        },
                        error: function() {
                            btn.prop('disabled', false).text('Consolidate Tags (AI)');
                            statusEl.html('<span style="color:#dc2626;">Server error/timeout. Try again.</span>');
                        }
                    });
                }

                // Step 1: Dedup
                consolidateCall({ phase: 'dedup', target: parseInt(target) }, function(d) {
                    if (d.phase === 'complete') {
                        btn.prop('disabled', false).text('Consolidate Tags (AI)');
                        statusEl.html('<span style="color:#059669;">Done! Dedup alone brought it to ' + d.new_count + ' categories.</span>');
                        loadGaps(1);
                        return;
                    }
                    // phase === 'deduped' — move to AI batches
                    statusEl.html('<span style="color:#b45309;">Deduped ' + d.deduped + ' tags (' + d.remaining + ' remain). Step 2: AI consolidation batch 1/' + d.ai_batches + '...</span>');
                    runAiBatch(0, d.ai_batches, d.remaining);
                });

                // Step 2: AI batches
                function runAiBatch(batchNum, totalBatches, totalTags) {
                    consolidateCall({ phase: 'ai_batch', batch: batchNum }, function(d) {
                        if (d.phase === 'ai_done') {
                            statusEl.html('<span style="color:#b45309;">AI found ' + d.changes + ' merges. Step 3: Applying batch 1/' + d.apply_batches + '...</span>');
                            runApply(0, d.apply_batches, d.changes);
                            return;
                        }
                        // phase === 'ai_batch' — more batches to go
                        var pct = Math.round((d.processed / totalTags) * 100);
                        statusEl.html('<span style="color:#b45309;">AI consolidation: ' + d.processed + '/' + totalTags + ' tags (' + pct + '%) — ' + d.changes_so_far + ' merges found...</span>');
                        runAiBatch(batchNum + 1, totalBatches, totalTags);
                    });
                }

                // Step 3: Apply changes
                function runApply(batchNum, totalBatches, totalChanges) {
                    consolidateCall({ phase: 'apply', batch: batchNum }, function(d) {
                        if (d.phase === 'complete') {
                            btn.prop('disabled', false).text('Consolidate Tags (AI)');
                            statusEl.html('<span style="color:#059669;">Done! ' + d.merged + ' tags merged. ' + d.new_count + ' categories remaining.</span>');
                            loadGaps(1);
                            return;
                        }
                        // phase === 'applying' — more batches
                        var pct = Math.round((d.processed / d.total) * 100);
                        statusEl.html('<span style="color:#b45309;">Applying merges: ' + d.processed + '/' + d.total + ' (' + pct + '%)...</span>');
                        runApply(batchNum + 1, totalBatches, totalChanges);
                    });
                }
            });

            // Sorting
            $(document).on('click', '.seom-gap-sort', function() {
                var col = $(this).data('col');
                if (gapSort === col) { gapOrder = gapOrder === 'DESC' ? 'ASC' : 'DESC'; }
                else { gapSort = col; gapOrder = col === 'keyword' ? 'ASC' : 'DESC'; }
                loadGaps(1);
            });

            // Filters
            $('#seom-gap-tag-filter, #seom-gap-filter').change(function() { loadGaps(1); });
            var gapSearchTimer;
            $('#seom-gap-search').on('input', function() {
                clearTimeout(gapSearchTimer);
                gapSearchTimer = setTimeout(function() { loadGaps(1); }, 400);
            });

            // Pagination
            $(document).on('click', '.seom-gap-page', function() { loadGaps($(this).data('page')); });

            // Select all
            $(document).on('change', '#seom-gap-check-all', function() {
                $('.seom-gap-cb').prop('checked', $(this).prop('checked'));
            });

            // Bulk action dropdown — show/hide tag input
            $('#seom-gap-bulk-action').change(function() {
                $('#seom-gap-bulk-tag').toggle($(this).val() === 'tag');
            });

            // Bulk apply
            $('#seom-gap-bulk-apply').click(function() {
                var action = $('#seom-gap-bulk-action').val();
                if (!action) { alert('Select a bulk action.'); return; }

                var ids = [];
                $('.seom-gap-cb:checked').each(function() { ids.push(parseInt($(this).val())); });
                if (!ids.length) { alert('Select at least one keyword.'); return; }

                if (action === 'tag') {
                    var tag = $('#seom-gap-bulk-tag').val().trim();
                    if (!tag) { alert('Enter a tag name.'); return; }
                    $.post(ajaxurl, {
                        action: 'seom_update_gap_tag', nonce: seom_nonce,
                        'ids[]': ids, tag: tag
                    }, function(resp) {
                        if (resp.success) {
                            $('#seom-gap-bulk-status').html('<span style="color:#059669;">' + resp.data.updated + ' keywords tagged.</span>');
                            loadGaps(gapPage);
                        }
                    });
                } else if (action === 'delete') {
                    if (!confirm('Delete ' + ids.length + ' keyword(s)?')) return;
                    $.post(ajaxurl, {
                        action: 'seom_delete_keyword_gaps', nonce: seom_nonce,
                        'ids[]': ids
                    }, function(resp) {
                        if (resp.success) {
                            $('#seom-gap-bulk-status').html('<span style="color:#059669;">' + resp.data.deleted + ' deleted.</span>');
                            loadGaps(1);
                        }
                    });
                }
            });

            loadGaps(1);
        });
        </script>
        <?php
    }

    // ─── Goals Tab ──────────────────────────────────────────────────────────────

    private static function render_goals($nonce) {
        ?>
        <h2>SEO Goals</h2>
        <p style="color:#64748b;">Set measurable monthly SEO goals, get AI feasibility checks, and track progress against your targets.</p>

        <!-- Goals list first -->
        <div style="margin-bottom:16px; display:flex; gap:8px; align-items:center;">
            <button type="button" class="button button-primary" id="seom-goal-add-btn">+ Add New Goal</button>
            <button type="button" class="button" id="seom-goals-refresh">Refresh Progress</button>
            <button type="button" class="button" id="seom-goals-ai-toggle" style="color:#7c3aed;">Auto-Create Monthly Goals (AI)</button>
            <span id="seom-goals-refresh-status" style="font-size:12px;"></span>
        </div>

        <!-- AI Goal Creator Panel -->
        <div id="seom-ai-goal-panel" style="display:none; max-width:700px; margin-bottom:20px; background:#fff; border:1px solid #e2e8f0; border-left:4px solid #7c3aed; border-radius:10px; padding:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <h3 style="margin:0; color:#7c3aed;">AI Goal Advisor</h3>
                <button type="button" class="button button-small" id="seom-ai-goal-close">&times; Close</button>
            </div>
            <p style="font-size:13px; color:#64748b; margin:0 0 12px;">AI will analyze your current metrics, prior goal performance, and your stated priorities to suggest realistic monthly goals.</p>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-weight:600; margin-bottom:4px; font-size:13px;">What's your priority this month? (optional)</label>
                <textarea id="seom-ai-goal-priority" rows="2" style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px; font-size:13px;" placeholder="e.g., Focus on reducing ghost pages and improving CTR on our product pages. We're launching 3 new certification courses next week."></textarea>
            </div>

            <div style="display:flex; gap:8px; align-items:center;">
                <button type="button" class="button button-primary" id="seom-goals-auto-create">Generate Goals</button>
                <button type="button" class="button" id="seom-ai-goal-show-context">Show AI Context</button>
            </div>

            <div id="seom-ai-goal-debug" style="display:none; margin-top:12px; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; font-size:12px; max-height:300px; overflow-y:auto;">
                <strong>Prompt context sent to AI:</strong>
                <pre id="seom-ai-goal-debug-text" style="white-space:pre-wrap; margin:8px 0 0; font-size:11px; color:#475569;"></pre>
            </div>

            <div id="seom-ai-goal-response" style="display:none; margin-top:12px; padding:12px; background:#fef3c7; border:1px solid #fcd34d; border-radius:6px; font-size:12px; max-height:200px; overflow-y:auto;">
                <strong>AI raw response:</strong>
                <pre id="seom-ai-goal-response-text" style="white-space:pre-wrap; margin:8px 0 0; font-size:11px; color:#92400e;"></pre>
            </div>
        </div>

        <!-- Goal Creator — hidden by default, shown above goals list -->
        <div id="seom-goal-form-wrapper" style="display:none; margin-bottom:20px;">
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h3 style="margin:0;">Create New Goal</h3>
                    <button type="button" class="button button-small" id="seom-goal-form-close">&times; Close</button>
                </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:13px;">Metric</label>
                        <select id="seom-goal-metric" style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px;">
                            <option value="">Select a metric...</option>
                            <optgroup label="SEO Performance">
                                <option value="ghost_pages">Ghost Pages</option>
                                <option value="total_clicks">Total Clicks (28d)</option>
                                <option value="total_impressions">Total Impressions (28d)</option>
                                <option value="avg_position">Average Position</option>
                                <option value="avg_ctr">Average CTR (%)</option>
                                <option value="page1_pages">Pages on Page 1</option>
                                <option value="page2_pages">Pages on Page 2 (Near Wins)</option>
                                <option value="pages_with_impressions">Pages With Impressions</option>
                            </optgroup>
                            <optgroup label="Content Production">
                                <option value="new_content_30d">New Content Created (30d)</option>
                                <option value="stale_pages">Stale Pages (not refreshed in 90+ days)</option>
                                <option value="refreshed_this_month">Pages Refreshed This Month</option>
                            </optgroup>
                        </select>
                    </div>

                    <div style="display:flex; gap:12px; margin-bottom:14px;">
                        <div style="flex:1;">
                            <label style="display:block; font-weight:600; margin-bottom:4px; font-size:13px;">Direction</label>
                            <select id="seom-goal-direction" style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px;">
                                <option value="reduce">Reduce</option>
                                <option value="increase">Increase</option>
                            </select>
                        </div>
                        <div style="flex:1;">
                            <label style="display:block; font-weight:600; margin-bottom:4px; font-size:13px;">Target Type</label>
                            <select id="seom-goal-type" style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px;">
                                <option value="percent">Percentage (%)</option>
                                <option value="absolute">Absolute Number</option>
                            </select>
                        </div>
                    </div>

                    <div style="display:flex; gap:12px; margin-bottom:14px;">
                        <div style="flex:1;">
                            <label style="display:block; font-weight:600; margin-bottom:4px; font-size:13px;">Current Baseline</label>
                            <input type="text" id="seom-goal-baseline" readonly style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#f8fafc; color:#64748b;" />
                        </div>
                        <div style="flex:1;">
                            <label style="display:block; font-weight:600; margin-bottom:4px; font-size:13px;">Target Value</label>
                            <input type="number" id="seom-goal-target" step="0.1" min="0" style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px;" placeholder="e.g., 30" />
                        </div>
                    </div>

                    <div style="display:flex; gap:12px; margin-bottom:14px;">
                        <div style="flex:1;">
                            <label style="display:block; font-weight:600; margin-bottom:4px; font-size:13px;">Start Date</label>
                            <input type="date" id="seom-goal-start" style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px;" />
                            <div id="seom-goal-start-info" style="font-size:11px; color:#64748b; margin-top:2px;"></div>
                        </div>
                        <div style="flex:1;">
                            <label style="display:block; font-weight:600; margin-bottom:4px; font-size:13px;">Deadline</label>
                            <input type="date" id="seom-goal-deadline" style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px;" />
                        </div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:13px;">Priority</label>
                        <div style="display:flex; gap:6px;" id="seom-goal-priority-btns">
                            <button type="button" class="button seom-priority-btn" data-val="1" style="flex:1;font-size:12px;color:#dc2626;">1 — Critical</button>
                            <button type="button" class="button seom-priority-btn" data-val="2" style="flex:1;font-size:12px;color:#d97706;">2 — High</button>
                            <button type="button" class="button seom-priority-btn active" data-val="3" style="flex:1;font-size:12px;color:#2563eb;background:#dbeafe;border-color:#2563eb;">3 — Medium</button>
                            <button type="button" class="button seom-priority-btn" data-val="4" style="flex:1;font-size:12px;color:#64748b;">4 — Low</button>
                            <button type="button" class="button seom-priority-btn" data-val="5" style="flex:1;font-size:12px;color:#94a3b8;">5 — Backlog</button>
                        </div>
                        <input type="hidden" id="seom-goal-priority" value="3" />
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:13px;">Notes (optional)</label>
                        <textarea id="seom-goal-notes" rows="2" style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px;" placeholder="Why this goal matters..."></textarea>
                    </div>

                    <div style="display:flex; gap:8px;">
                        <button type="button" class="button" id="seom-goal-check" style="color:#7c3aed;">Check Feasibility (AI)</button>
                        <button type="button" class="button button-primary" id="seom-goal-create" disabled>Create Goal</button>
                    </div>

                    <div id="seom-goal-ai-result" style="display:none; margin-top:16px; padding:16px; border-radius:8px; border:1px solid #e2e8f0;"></div>
                    <div id="seom-goal-status" style="margin-top:8px; font-size:13px;"></div>
                </div>
            </div>

        <div id="seom-goals-loading" style="color:#64748b;">Loading goals...</div>
        <div id="seom-goals-list" style="margin-bottom:24px;"></div>

        <script>
        jQuery(document).ready(function($) {
            var siteMetrics = null;
            var aiAssessment = '';

            // Show/hide form
            $('#seom-goal-add-btn').click(function() {
                $('#seom-goal-form-wrapper').slideToggle(200);
                loadMetrics(); // ensure metrics are loaded
            });


            // Helper: last day of a given month
            function lastDayOfMonth(year, month) {
                return new Date(year, month + 1, 0); // day 0 of next month = last day of this month
            }

            var metricLabels = {
                ghost_pages: 'Ghost Pages',
                total_clicks: 'Total Clicks (28d)',
                total_impressions: 'Total Impressions (28d)',
                avg_position: 'Average Position',
                avg_ctr: 'Average CTR (%)',
                page1_pages: 'Pages on Page 1',
                page2_pages: 'Pages on Page 2',
                pages_with_impressions: 'Pages With Impressions',
                new_content_30d: 'New Content Created (30d)',
                stale_pages: 'Stale Pages (90+ days)',
                refreshed_this_month: 'Pages Refreshed This Month'
            };

            // Auto-set direction based on metric
            var metricDirections = {
                ghost_pages: 'reduce', total_clicks: 'increase', total_impressions: 'increase',
                avg_position: 'reduce', avg_ctr: 'increase', page1_pages: 'increase',
                page2_pages: 'reduce', pages_with_impressions: 'increase',
                new_content_30d: 'increase', stale_pages: 'reduce', refreshed_this_month: 'increase'
            };

            var availableDates = [];

            // Load site metrics for baselines (optionally for a specific date)
            function loadMetrics(asOfDate, callback) {
                var postData = { action: 'seom_get_goal_metrics', nonce: seom_nonce };
                if (asOfDate) postData.as_of_date = asOfDate;

                $.post(ajaxurl, postData, function(resp) {
                    if (resp.success) {
                        siteMetrics = resp.data;
                        availableDates = resp.data.available_dates || [];

                        // Show which date the baseline is from
                        var dateInfo = 'Data from: ' + resp.data.collected_date;
                        if (availableDates.length) dateInfo += ' (' + availableDates.length + ' collection dates available: ' + availableDates[0] + ' to ' + availableDates[availableDates.length - 1] + ')';
                        $('#seom-goal-start-info').text(dateInfo);

                        // Set defaults on first load
                        if (!$('#seom-goal-start').val()) {
                            // Default start to 1st of current month (or earliest available)
                            var now = new Date();
                            var monthStart = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().substring(0, 10);
                            // Use earliest available date if it's before month start
                            var earliest = availableDates.length ? availableDates[0] : monthStart;
                            if (earliest < monthStart) earliest = monthStart;
                            $('#seom-goal-start').val(earliest);
                            // Deadline = last day of current month
                            var eom = lastDayOfMonth(now.getFullYear(), now.getMonth());
                            $('#seom-goal-deadline').val(eom.toISOString().substring(0, 10));
                            // Reload with the start date
                            loadMetrics(earliest);
                        }

                        // Re-populate baseline if metric is selected
                        var metric = $('#seom-goal-metric').val();
                        if (metric) {
                            var val = parseFloat(siteMetrics.metrics[metric]) || 0;
                            if (metric === 'avg_ctr') val = val.toFixed(2);
                            else if (metric === 'avg_position') val = val.toFixed(1);
                            else val = Math.round(val);
                            $('#seom-goal-baseline').val(val);
                        }

                        if (callback) callback();
                    }
                });
            }

            // Reload baseline when start date changes
            $('#seom-goal-start').change(function() {
                var startDate = $(this).val();
                if (!startDate) return;
                $('#seom-goal-start-info').text('Loading...');
                loadMetrics(startDate);
                // Reset AI check
                $('#seom-goal-ai-result').hide();
                $('#seom-goal-create').prop('disabled', true);
                aiAssessment = '';
            });

            // Priority buttons
            $(document).on('click', '.seom-priority-btn', function() {
                var colors = {1:'#dc2626',2:'#d97706',3:'#2563eb',4:'#64748b',5:'#94a3b8'};
                var bgs = {1:'#fee2e2',2:'#fef3c7',3:'#dbeafe',4:'#f1f5f9',5:'#f8fafc'};
                // Reset all
                $('.seom-priority-btn').removeClass('active').css({'background':'','border-color':''});
                // Activate selected
                var val = $(this).data('val');
                $(this).addClass('active').css({'background':bgs[val],'border-color':colors[val]});
                $('#seom-goal-priority').val(val);
            });

            // Update baseline when metric changes — always fetch for the selected start date
            $('#seom-goal-metric').change(function() {
                var metric = $(this).val();
                if (!metric) { $('#seom-goal-baseline').val(''); return; }
                $('#seom-goal-direction').val(metricDirections[metric] || 'reduce');
                // Reset AI check
                $('#seom-goal-ai-result').hide();
                $('#seom-goal-create').prop('disabled', true);
                aiAssessment = '';

                var startDate = $('#seom-goal-start').val();
                $('#seom-goal-baseline').val('Loading...');
                loadMetrics(startDate || null, function() {
                    var val = parseFloat(siteMetrics.metrics[metric]) || 0;
                    if (metric === 'avg_ctr') val = val.toFixed(2);
                    else if (metric === 'avg_position') val = val.toFixed(1);
                    else val = Math.round(val);
                    $('#seom-goal-baseline').val(val);
                });
            });

            // AI feasibility check
            $('#seom-goal-check').click(function() {
                var metric = $('#seom-goal-metric').val();
                var target = parseFloat($('#seom-goal-target').val());
                var deadline = $('#seom-goal-deadline').val();
                if (!metric) { alert('Select a metric.'); return; }
                if (!target || target <= 0) { alert('Enter a target value.'); return; }
                if (!deadline) { alert('Set a deadline.'); return; }

                var baseline = parseFloat($('#seom-goal-baseline').val()) || 0;
                var daysUntil = Math.ceil((new Date(deadline) - new Date()) / 86400000);
                if (daysUntil < 1) { alert('Deadline must be in the future.'); return; }

                var btn = $(this).prop('disabled', true).text('Checking...');
                $('#seom-goal-ai-result').hide();

                $.ajax({ url: ajaxurl, method: 'POST', timeout: 45000, data: {
                    action: 'seom_check_goal_feasibility', nonce: seom_nonce,
                    metric: metric,
                    direction: $('#seom-goal-direction').val(),
                    target_value: target,
                    target_type: $('#seom-goal-type').val(),
                    baseline_value: baseline,
                    days: daysUntil,
                    daily_limit: siteMetrics ? siteMetrics.daily_limit : 20
                }, success: function(resp) {
                    btn.prop('disabled', false).text('Check Feasibility (AI)');
                    if (!resp.success) {
                        $('#seom-goal-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                        return;
                    }
                    var r = resp.data;
                    aiAssessment = JSON.stringify(r);

                    var colors = {
                        achievable: { bg: '#ecfdf5', border: '#059669', text: '#065f46', icon: '&#10003;' },
                        stretch:    { bg: '#fef3c7', border: '#d97706', text: '#92400e', icon: '&#9888;' },
                        unlikely:   { bg: '#fee2e2', border: '#dc2626', text: '#991b1b', icon: '&#9888;' },
                        unrealistic:{ bg: '#fee2e2', border: '#991b1b', text: '#7f1d1d', icon: '&#10007;' }
                    };
                    var c = colors[r.feasibility] || colors.stretch;

                    var html = '<div style="background:' + c.bg + '; border-color:' + c.border + '; color:' + c.text + '; padding:14px; border-radius:8px; border:1px solid;">';
                    html += '<div style="font-size:16px; font-weight:700; margin-bottom:6px;">' + c.icon + ' ' + (r.feasibility || '').toUpperCase() + ' <span style="font-weight:400; font-size:13px;">(confidence: ' + r.confidence + '%)</span></div>';
                    html += '<p style="margin:0 0 8px; font-size:13px;">' + (r.reasoning || '') + '</p>';
                    html += '<p style="margin:0 0 8px; font-size:13px;"><strong>Recommendation:</strong> ' + (r.recommendation || '') + '</p>';
                    if (r.suggested_target) {
                        var typeLabel = $('#seom-goal-type').val() === 'percent' ? '%' : '';
                        html += '<p style="margin:0; font-size:13px;"><strong>Suggested:</strong> ' + r.suggested_target + typeLabel + ' over ' + r.suggested_days + ' days</p>';
                    }
                    html += '</div>';

                    $('#seom-goal-ai-result').html(html).show();
                    $('#seom-goal-create').prop('disabled', false);
                }, error: function() {
                    btn.prop('disabled', false).text('Check Feasibility (AI)');
                    $('#seom-goal-status').html('<span style="color:#dc2626;">AI check timed out. You can still create the goal.</span>');
                    $('#seom-goal-create').prop('disabled', false);
                }});
            });

            // Create goal
            $('#seom-goal-create').click(function() {
                var isEdit = !!editingGoalId;
                var btn = $(this).prop('disabled', true).text(isEdit ? 'Updating...' : 'Creating...');

                var formData = {
                    nonce: seom_nonce,
                    metric: $('#seom-goal-metric').val(),
                    direction: $('#seom-goal-direction').val(),
                    target_value: $('#seom-goal-target').val(),
                    target_type: $('#seom-goal-type').val(),
                    baseline_value: $('#seom-goal-baseline').val(),
                    start_date: $('#seom-goal-start').val(),
                    deadline: $('#seom-goal-deadline').val(),
                    priority: $('#seom-goal-priority').val(),
                    notes: $('#seom-goal-notes').val(),
                };

                if (isEdit) {
                    formData.action = 'seom_update_goal';
                    formData.id = editingGoalId;
                    formData.mode = 'full';
                } else {
                    formData.action = 'seom_create_goal';
                    formData.ai_assessment = aiAssessment;
                }

                $.post(ajaxurl, formData, function(resp) {
                    btn.prop('disabled', false).text(isEdit ? 'Update Goal' : 'Create Goal');
                    if (resp.success) {
                        $('#seom-goal-status').html('<span style="color:#059669;">' + (isEdit ? 'Goal updated!' : 'Goal created!') + '</span>');
                        // Reset form
                        editingGoalId = null;
                        $('#seom-goal-metric').val('');
                        $('#seom-goal-baseline').val('');
                        $('#seom-goal-target').val('');
                        $('#seom-goal-notes').val('');
                        $('#seom-goal-ai-result').hide();
                        $('#seom-goal-form-wrapper').slideUp(200);
                        btn.text('Create Goal').prop('disabled', true);
                        aiAssessment = '';
                        loadGoals();
                    } else {
                        $('#seom-goal-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                    }
                });
            });

            // Load goals list
            function loadGoals() {
                $('#seom-goals-loading').show();
                $('#seom-goals-list').empty();

                $.post(ajaxurl, { action: 'seom_get_goals', nonce: seom_nonce }, function(resp) {
                    $('#seom-goals-loading').hide();
                    if (!resp.success || !resp.data.length) {
                        $('#seom-goals-list').html('<div style="color:#94a3b8; padding:24px; text-align:center; background:#f8fafc; border-radius:8px;">No goals set yet. Create one to start tracking your SEO progress.</div>');
                        return;
                    }

                    resp.data.forEach(function(g) {
                        var baseline = parseFloat(g.baseline_value);
                        var current = parseFloat(g.current_value);
                        var target = parseFloat(g.target_value);
                        var deadline = g.deadline;
                        var daysLeft = Math.ceil((new Date(deadline) - new Date()) / 86400000);

                        // Calculate target number and progress
                        var targetNum;
                        if (g.target_type === 'percent') {
                            var change = baseline * (target / 100);
                            targetNum = (g.direction === 'reduce') ? baseline - change : baseline + change;
                        } else {
                            targetNum = target;
                        }

                        var totalChange = Math.abs(targetNum - baseline);
                        var actualChange = (g.direction === 'reduce') ? baseline - current : current - baseline;
                        var progress = totalChange > 0 ? Math.min(100, Math.max(0, Math.round((actualChange / totalChange) * 100))) : 0;

                        var statusColors = {
                            active: { bg: '#dbeafe', text: '#1e40af', label: 'Active' },
                            completed: { bg: '#dcfce7', text: '#166534', label: 'Completed' },
                            missed: { bg: '#fee2e2', text: '#991b1b', label: 'Missed' },
                            cancelled: { bg: '#f1f5f9', text: '#64748b', label: 'Cancelled' }
                        };
                        var sc = statusColors[g.status] || statusColors.active;

                        var progressColor = progress >= 75 ? '#059669' : (progress >= 40 ? '#d97706' : '#dc2626');
                        if (g.status === 'completed') progressColor = '#059669';
                        if (g.status === 'missed') progressColor = '#dc2626';

                        var dirLabel = g.direction === 'reduce' ? '&#9660;' : '&#9650;';
                        var typeLabel = g.target_type === 'percent' ? target + '%' : target;
                        var metricName = metricLabels[g.metric] || g.metric;
                        var pri = parseInt(g.priority) || 3;
                        var priLabels = {1:'Critical',2:'High',3:'Medium',4:'Low',5:'Backlog'};
                        var priColors = {1:'#dc2626',2:'#d97706',3:'#2563eb',4:'#64748b',5:'#94a3b8'};
                        var priBgs = {1:'#fee2e2',2:'#fef3c7',3:'#dbeafe',4:'#f1f5f9',5:'#f8fafc'};
                        var priBorderColors = {1:'#fca5a5',2:'#fcd34d',3:'#93c5fd',4:'#e2e8f0',5:'#e2e8f0'};

                        var card = '<div style="background:#fff; border:1px solid #e2e8f0; border-left:4px solid ' + priColors[pri] + '; border-radius:10px; padding:20px; margin-bottom:16px;">';
                        card += '<div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:12px;">';
                        card += '<div>';
                        card += '<h4 style="margin:0 0 4px; font-size:16px;">' + dirLabel + ' ' + (g.direction === 'reduce' ? 'Reduce' : 'Increase') + ' ' + metricName + ' by ' + typeLabel + '</h4>';
                        var startDate = g.start_date || g.created_at.substring(0, 10);
                        var totalDays = Math.ceil((new Date(deadline) - new Date(startDate)) / 86400000);
                        var daysElapsed = Math.ceil((new Date() - new Date(startDate)) / 86400000);
                        card += '<div style="font-size:12px; color:#64748b;">Baseline: ' + baseline + ' &rarr; Target: ' + Math.round(targetNum * 10) / 10 + ' &bull; ' + startDate + ' to ' + deadline + ' (' + totalDays + ' days)';
                        if (g.status === 'active') card += ' &bull; <strong>' + (daysLeft > 0 ? daysLeft + ' days left' : 'Overdue') + '</strong>';
                        card += '</div>';
                        if (g.notes) card += '<div style="font-size:12px; color:#94a3b8; margin-top:4px; font-style:italic;">' + g.notes + '</div>';
                        card += '</div>';
                        card += '<div style="display:flex; gap:6px; align-items:center;">';
                        card += '<span class="seom-badge" style="background:' + priBgs[pri] + '; color:' + priColors[pri] + '; border:1px solid ' + priBorderColors[pri] + ';">P' + pri + ' ' + priLabels[pri] + '</span> ';
                        card += '<span class="seom-badge" style="background:' + sc.bg + '; color:' + sc.text + ';">' + sc.label + '</span>';
                        if (g.status === 'active') {
                            card += '<button class="button button-small seom-goal-edit" data-goal=\'' + JSON.stringify(g).replace(/'/g, '&#39;') + '\' title="Edit goal" style="color:#2563eb;">&#9998;</button> ';
                            card += '<button class="button button-small seom-goal-cancel" data-id="' + g.id + '" title="Cancel goal">&#10007;</button>';
                        }
                        card += ' <button class="button button-small seom-goal-delete" data-id="' + g.id + '" title="Delete goal" style="color:#dc2626;">&#128465;</button>';
                        card += '</div></div>';

                        // Determine if moving in right or wrong direction
                        var isMovingRight = (g.direction === 'reduce') ? (current < baseline) : (current > baseline);
                        var isMovingWrong = (g.direction === 'reduce') ? (current > baseline) : (current < baseline);
                        var changeSinceBaseline = current - baseline;
                        var changePct = baseline !== 0 ? Math.round(Math.abs(changeSinceBaseline) / baseline * 100) : 0;

                        // Direction indicator
                        var dirIndicator = '';
                        if (g.status === 'active') {
                            if (isMovingWrong && Math.abs(changeSinceBaseline) > 0) {
                                dirIndicator = '<div style="background:#fee2e2; border:1px solid #fca5a5; border-radius:6px; padding:8px 14px; margin-bottom:10px; display:flex; align-items:center; gap:8px;">'
                                    + '<span style="font-size:18px;">&#9888;</span>'
                                    + '<span style="color:#991b1b; font-size:13px; font-weight:600;">Moving wrong direction — '
                                    + (g.direction === 'reduce' ? 'increased' : 'decreased') + ' by ' + Math.abs(Math.round(changeSinceBaseline * 10) / 10)
                                    + ' (' + changePct + '%) since baseline</span></div>';
                            } else if (progress >= 100) {
                                dirIndicator = '<div style="background:#dcfce7; border:1px solid #86efac; border-radius:6px; padding:8px 14px; margin-bottom:10px; display:flex; align-items:center; gap:8px;">'
                                    + '<span style="font-size:18px;">&#10003;</span>'
                                    + '<span style="color:#166534; font-size:13px; font-weight:600;">Goal reached! Target met ahead of deadline.</span></div>';
                            } else if (isMovingRight) {
                                dirIndicator = '<div style="background:#ecfdf5; border:1px solid #6ee7b7; border-radius:6px; padding:8px 14px; margin-bottom:10px; display:flex; align-items:center; gap:8px;">'
                                    + '<span style="font-size:18px;">&#9650;</span>'
                                    + '<span style="color:#065f46; font-size:13px;">On track — '
                                    + (g.direction === 'reduce' ? 'reduced' : 'increased') + ' by ' + Math.abs(Math.round(changeSinceBaseline * 10) / 10)
                                    + ' (' + changePct + '%) so far</span></div>';
                            }
                        }
                        card += dirIndicator;

                        // Progress bar
                        card += '<div style="display:flex; align-items:center; gap:12px;">';
                        card += '<div style="flex:1; background:#e2e8f0; border-radius:6px; height:12px; overflow:hidden;">';
                        card += '<div style="width:' + Math.max(0, progress) + '%; background:' + progressColor + '; height:100%; border-radius:6px; transition:width 0.3s;"></div>';
                        card += '</div>';
                        card += '<span style="font-size:14px; font-weight:700; color:' + progressColor + '; min-width:45px;">' + progress + '%</span>';
                        card += '</div>';

                        // Current value with change from baseline
                        card += '<div style="display:flex; justify-content:space-between; margin-top:8px; font-size:12px; color:#64748b;">';
                        var changeLabel = changeSinceBaseline !== 0
                            ? ' <span style="color:' + (isMovingRight ? '#059669' : '#dc2626') + ';">(' + (changeSinceBaseline > 0 ? '+' : '') + Math.round(changeSinceBaseline * 10) / 10 + ')</span>'
                            : ' <span style="color:#94a3b8;">(no change)</span>';
                        card += '<span>Current: <strong style="color:#1e293b;">' + (Math.round(current * 10) / 10) + '</strong>' + changeLabel + '</span>';
                        card += '<span>Target: <strong style="color:#1e293b;">' + (Math.round(targetNum * 10) / 10) + '</strong></span>';
                        card += '</div>';

                        // AI assessment (collapsed)
                        if (g.ai_assessment) {
                            try {
                                var ai = JSON.parse(g.ai_assessment);
                                card += '<details style="margin-top:10px; font-size:12px;"><summary style="cursor:pointer; color:#7c3aed;">AI Assessment: ' + (ai.feasibility || '').toUpperCase() + ' (' + ai.confidence + '% confidence)</summary>';
                                card += '<div style="margin-top:6px; padding:10px; background:#f8fafc; border-radius:6px;">';
                                card += '<p style="margin:0 0 4px;">' + (ai.reasoning || '') + '</p>';
                                card += '<p style="margin:0; color:#475569;"><strong>Recommendation:</strong> ' + (ai.recommendation || '') + '</p>';
                                card += '</div></details>';
                            } catch(e) {}
                        }

                        card += '</div>';
                        $('#seom-goals-list').append(card);
                    });
                });
            }

            // Refresh progress
            $('#seom-goals-refresh').click(function() {
                var btn = $(this).prop('disabled', true).text('Refreshing...');
                $.post(ajaxurl, { action: 'seom_refresh_goal_progress', nonce: seom_nonce }, function(resp) {
                    btn.prop('disabled', false).text('Refresh Progress');
                    if (resp.success) {
                        $('#seom-goals-refresh-status').html('<span style="color:#059669;">' + resp.data.updated + ' goal(s) updated.</span>');
                        loadGoals();
                    } else {
                        $('#seom-goals-refresh-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                    }
                });
            });

            // Edit goal — populate form with existing goal data
            var editingGoalId = null;
            $(document).on('click', '.seom-goal-edit', function() {
                var g = $(this).data('goal');
                if (typeof g === 'string') g = JSON.parse(g);
                editingGoalId = g.id;

                // Show form and populate
                $('#seom-goal-form-wrapper').slideDown(200);
                $('html, body').animate({ scrollTop: $('#seom-goal-form-wrapper').offset().top - 50 }, 300);

                $('#seom-goal-metric').val(g.metric);
                $('#seom-goal-direction').val(g.direction);
                $('#seom-goal-type').val(g.target_type);
                $('#seom-goal-baseline').val(parseFloat(g.baseline_value));
                $('#seom-goal-target').val(parseFloat(g.target_value));
                $('#seom-goal-start').val(g.start_date || '');
                $('#seom-goal-deadline').val(g.deadline);
                $('#seom-goal-notes').val(g.notes || '');
                $('#seom-goal-priority').val(g.priority || 3);

                // Highlight the right priority button
                $('.seom-priority-btn').removeClass('active').css({'background':'','border-color':''});
                var pri = parseInt(g.priority) || 3;
                var colors = {1:'#dc2626',2:'#d97706',3:'#2563eb',4:'#64748b',5:'#94a3b8'};
                var bgs = {1:'#fee2e2',2:'#fef3c7',3:'#dbeafe',4:'#f1f5f9',5:'#f8fafc'};
                $('.seom-priority-btn[data-val="' + pri + '"]').addClass('active').css({'background':bgs[pri],'border-color':colors[pri]});

                // Change button text
                $('#seom-goal-create').prop('disabled', false).text('Update Goal');
                $('#seom-goal-ai-result').hide();
                $('#seom-goal-status').empty();
            });

            // Reset form to create mode when closing
            $('#seom-goal-form-close').click(function() {
                $('#seom-goal-form-wrapper').slideUp(200);
                editingGoalId = null;
                $('#seom-goal-create').text('Create Goal').prop('disabled', true);
                $('#seom-goal-metric').val('');
                $('#seom-goal-baseline').val('');
                $('#seom-goal-target').val('');
                $('#seom-goal-notes').val('');
                $('#seom-goal-ai-result').hide();
                aiAssessment = '';
            });

            // Cancel goal
            $(document).on('click', '.seom-goal-cancel', function() {
                if (!confirm('Cancel this goal?')) return;
                var id = $(this).data('id');
                $.post(ajaxurl, { action: 'seom_update_goal', nonce: seom_nonce, id: id, status: 'cancelled' }, function() { loadGoals(); });
            });

            // Delete goal
            $(document).on('click', '.seom-goal-delete', function() {
                if (!confirm('Permanently delete this goal?')) return;
                var id = $(this).data('id');
                $.post(ajaxurl, { action: 'seom_delete_goal', nonce: seom_nonce, id: id }, function() { loadGoals(); });
            });

            // AI goal panel toggle
            $('#seom-goals-ai-toggle').click(function() { $('#seom-ai-goal-panel').slideToggle(200); });
            $('#seom-ai-goal-close').click(function() { $('#seom-ai-goal-panel').slideUp(200); });

            // Show/hide context debug
            $('#seom-ai-goal-show-context').click(function() {
                var panel = $('#seom-ai-goal-debug');
                if (panel.is(':visible')) { panel.slideUp(200); return; }
                // Fetch context preview
                $.post(ajaxurl, { action: 'seom_get_goal_metrics', nonce: seom_nonce }, function(resp) {
                    if (!resp.success) return;
                    var m = resp.data.metrics;
                    var ctx = 'Current metrics:\n';
                    ctx += '- Ghost Pages: ' + m.ghost_pages + '\n';
                    ctx += '- Total Clicks (28d): ' + m.total_clicks + '\n';
                    ctx += '- Total Impressions (28d): ' + m.total_impressions + '\n';
                    ctx += '- Avg Position: ' + parseFloat(m.avg_position).toFixed(1) + '\n';
                    ctx += '- Avg CTR: ' + parseFloat(m.avg_ctr).toFixed(2) + '%\n';
                    ctx += '- Pages on Page 1: ' + m.page1_pages + '\n';
                    ctx += '- Pages on Page 2: ' + m.page2_pages + '\n';
                    ctx += '- Pages With Impressions: ' + m.pages_with_impressions + '\n';
                    ctx += '- Total Pages: ' + m.total_pages + '\n';
                    ctx += '- Daily Refresh Capacity: ' + resp.data.daily_limit + '\n';
                    var priority = $('#seom-ai-goal-priority').val();
                    if (priority) ctx += '\nUser Priority: ' + priority + '\n';
                    $('#seom-ai-goal-debug-text').text(ctx);
                    panel.slideDown(200);
                });
            });

            // AI auto-create monthly goals
            $('#seom-goals-auto-create').click(function() {
                var btn = $(this).prop('disabled', true).text('Analyzing...');
                $('#seom-ai-goal-response').hide();
                $('#seom-goals-refresh-status').html('<span style="color:#b45309;">AI is analyzing your metrics and prior goal performance...</span>');

                $.ajax({ url: ajaxurl, method: 'POST', timeout: 60000, data: {
                    action: 'seom_auto_create_goals', nonce: seom_nonce,
                    user_priority: $('#seom-ai-goal-priority').val()
                }, success: function(resp) {
                    btn.prop('disabled', false).text('Generate Goals');
                    if (resp.success) {
                        $('#seom-goals-refresh-status').html('<span style="color:#059669;">' + resp.data.created + ' goals created (AI suggested ' + resp.data.suggested + ').</span>');
                        $('#seom-ai-goal-panel').slideUp(200);
                        loadGoals();
                    } else {
                        var errData = resp.data;
                        var msg = (typeof errData === 'string') ? errData : (errData.message || 'Error');
                        $('#seom-goals-refresh-status').html('<span style="color:#dc2626;">' + msg + '</span>');
                        // Show debug info
                        if (errData.raw_response) {
                            $('#seom-ai-goal-response-text').text(errData.raw_response);
                            $('#seom-ai-goal-response').slideDown(200);
                        }
                        if (errData.prompt_context) {
                            $('#seom-ai-goal-debug-text').text(errData.prompt_context);
                            $('#seom-ai-goal-debug').slideDown(200);
                        }
                    }
                }, error: function() {
                    btn.prop('disabled', false).text('Generate Goals');
                    $('#seom-goals-refresh-status').html('<span style="color:#dc2626;">Timed out. Try again.</span>');
                }});
            });

            // After creating a goal, hide the form and reload
            var origCreateHandler = $('#seom-goal-create').data('events');
            // Patch the create success to close form
            $(document).ajaxComplete(function(e, xhr, settings) {
                if (settings.data && settings.data.indexOf && settings.data.indexOf('seom_create_goal') !== -1) {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.success) $('#seom-goal-form-wrapper').slideUp(200);
                    } catch(e) {}
                }
            });

            loadMetrics();
            loadGoals();
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
                        <input type="text" name="gsc_property_url" value="<?php echo esc_attr($settings['gsc_property_url']); ?>" placeholder="https://www.example.com" style="min-width:350px;" />
                        <p class="description">Exactly as it appears in Google Search Console.<br>URL-prefix property: <code>https://www.example.com</code> &nbsp;|&nbsp; Domain property: <code>sc-domain:example.com</code></p>
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
                    <th><label>Buried Potential: Min Impressions</label></th>
                    <td>
                        <input type="number" name="buried_min_impressions" value="<?php echo intval($settings['buried_min_impressions']); ?>" min="0" style="width:80px;" />
                        <p class="description">Min impressions for page 3+ pages to qualify as "Buried Potential" (Category F).</p>
                    </td>
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

            <h2 style="margin-top:24px; padding-bottom:8px; border-bottom:2px solid #e2e8f0;">Keyword Gap Settings</h2>
            <table class="form-table" style="max-width:800px;">
                <tr>
                    <th><label>Keyword Cooldown (days)</label></th>
                    <td>
                        <input type="number" name="gap_keyword_cooldown" value="<?php echo intval($settings['gap_keyword_cooldown']); ?>" min="1" style="width:80px;" />
                        <p class="description">Days before a used keyword becomes available again for new content.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Seed Categories</label></th>
                    <td>
                        <textarea name="gap_seed_categories" rows="10" style="width:100%;max-width:500px;font-family:monospace;font-size:12px;" placeholder="AWS Certifications&#10;Azure Certifications&#10;Cisco Networking&#10;CompTIA Security+&#10;Linux Administration&#10;..."><?php echo esc_textarea($settings['gap_seed_categories']); ?></textarea>
                        <p class="description">One category per line. These are the foundation categories for auto-tagging. AI will use these first and only create new categories if a keyword doesn't fit any existing one.</p>
                    </td>
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
                    'seom_daily_goal_email'    => ['label' => 'Goal Progress Email', 'desc' => 'Sends daily email with active goal progress and status'],
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
