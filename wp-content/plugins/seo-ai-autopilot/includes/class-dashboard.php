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
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
        <script>
        var seom_nonce = '<?php echo $nonce; ?>';

        // Shared windowed pagination renderer used by every paginated table.
        // Returns the HTML string for: « First ‹ Prev 1 … 4 5 6 7 8 … 71 Next › Last »
        // The button click handler is added by each tab against its own data-* class.
        // Args: current page, total pages, button class (e.g. 'seom-tracker-page'),
        // optional suffix HTML (status text rendered after the buttons).
        window.seomBuildPagination = function(currentPage, totalPages, btnClass, suffixHtml) {
            currentPage = Math.max(1, parseInt(currentPage) || 1);
            totalPages  = Math.max(1, parseInt(totalPages)  || 1);
            if (totalPages <= 1) return suffixHtml || '';

            var html = '';
            var btn = function(p, label, disabled, active) {
                if (disabled) return '<button class="button" disabled>' + label + '</button> ';
                var cls = active ? 'button button-primary' : 'button';
                return '<button class="' + cls + ' ' + btnClass + '" data-page="' + p + '">' + label + '</button> ';
            };
            var ellipsis = '<span style="margin:0 4px;color:#94a3b8;">…</span>';

            html += btn(1, '« First', currentPage === 1);
            html += btn(currentPage - 1, '‹ Prev', currentPage === 1);

            var start = Math.max(1, currentPage - 2);
            var end   = Math.min(totalPages, currentPage + 2);
            if (start > 1) {
                html += btn(1, '1', false, currentPage === 1);
                if (start > 2) html += ellipsis;
            }
            for (var i = start; i <= end; i++) {
                html += btn(i, String(i), false, i === currentPage);
            }
            if (end < totalPages) {
                if (end < totalPages - 1) html += ellipsis;
                html += btn(totalPages, String(totalPages), false, currentPage === totalPages);
            }

            html += btn(currentPage + 1, 'Next ›', currentPage === totalPages);
            html += btn(totalPages, 'Last »', currentPage === totalPages);

            if (suffixHtml) html += suffixHtml;
            return html;
        };
        </script>
        <div class="wrap">
            <h1>SEO AI AutoPilot</h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=seo-ai-autopilot&tab=overview" class="nav-tab <?php echo $active_tab === 'overview' ? 'nav-tab-active' : ''; ?>">Overview</a>
                <a href="?page=seo-ai-autopilot&tab=indexed" class="nav-tab <?php echo $active_tab === 'indexed' ? 'nav-tab-active' : ''; ?>">Indexed Pages</a>
                <a href="?page=seo-ai-autopilot&tab=tracker" class="nav-tab <?php echo $active_tab === 'tracker' ? 'nav-tab-active' : ''; ?>">Performance Tracker</a>
                <a href="?page=seo-ai-autopilot&tab=queue" class="nav-tab <?php echo $active_tab === 'queue' ? 'nav-tab-active' : ''; ?>">Queue</a>
                <a href="?page=seo-ai-autopilot&tab=history" class="nav-tab <?php echo $active_tab === 'history' ? 'nav-tab-active' : ''; ?>">History</a>
                <a href="?page=seo-ai-autopilot&tab=keywords" class="nav-tab <?php echo $active_tab === 'keywords' ? 'nav-tab-active' : ''; ?>">Keywords</a>
                <a href="?page=seo-ai-autopilot&tab=gaps" class="nav-tab <?php echo $active_tab === 'gaps' ? 'nav-tab-active' : ''; ?>">Keyword Gaps</a>
                <a href="?page=seo-ai-autopilot&tab=goals" class="nav-tab <?php echo $active_tab === 'goals' ? 'nav-tab-active' : ''; ?>">Goals</a>
                <a href="?page=seo-ai-autopilot&tab=glossary" class="nav-tab <?php echo $active_tab === 'glossary' ? 'nav-tab-active' : ''; ?>">Glossary</a>
                <a href="?page=seo-ai-autopilot&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
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
                    case 'glossary': self::render_glossary($nonce); break;
                    case 'settings': self::render_settings($nonce, $settings); break;
                    default:         self::render_overview($nonce, $settings);
                }
                ?>
            </div>
        </div>

        <style>
            /* ─── SEO AI AutoPilot Modern Theme ─── */

            /* Light gray page background so white cards stand out */
            #wpbody-content { background: #f1f5f9; }
            .wrap { background: #f1f5f9; padding: 0 20px 20px; }

            /* Page header */
            .wrap > h1 {
                font-size: 26px; font-weight: 700; color: #1e293b;
                padding-bottom: 12px; margin-bottom: 0;
            }

            /* Tab navigation */
            .nav-tab-wrapper {
                border-bottom: 2px solid #e2e8f0; margin-bottom: 0; padding: 0; display: flex; gap: 4px;
            }
            .nav-tab {
                border: 1px solid #e2e8f0; border-bottom: 2px solid #e2e8f0;
                border-radius: 6px 6px 0 0;
                background: #f8fafc; color: #64748b; font-weight: 500; font-size: 13px;
                padding: 9px 18px; margin-bottom: -2px; transition: all 0.15s ease;
                text-decoration: none;
            }
            .nav-tab:hover { color: #1e293b; background: #f1f5f9; border-color: #cbd5e1; border-bottom-color: #cbd5e1; }
            .nav-tab-active, .nav-tab-active:hover {
                color: #2563eb; background: transparent; font-weight: 600;
                border: 2px solid #2563eb; border-bottom-color: transparent;
            }
            .seom-tab-content { margin-top: 0; }

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
            .seom-section-header {
                background: #1e293b;
                color: #fff;
                padding: 10px 18px;
                border-radius: 8px;
                margin: 20px 0 12px;
                font-size: 16px;
                font-weight: 700;
            }
            .seom-section-header:first-child { margin-top: 0; }
            .seom-section-header small { font-weight: 400; color: #94a3b8; font-size: 13px; }
            .seom-section-header a { font-size: 13px; font-weight: 500; text-decoration: none; color: #93c5fd; float: right; margin-top: 2px; }
            .seom-section-header a:hover { color: #fff; }
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
        // seom_nonce defined globally in render() above
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

            <!-- Monthly Goals Progress -->
            <div id="seom-overview-goals" style="display:none; margin-bottom:20px;">
                <div class="seom-section-header">Monthly Goals <a href="?page=seo-ai-autopilot&tab=goals">Manage &rarr;</a></div>
                <div id="seom-overview-goals-cards" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:10px;"></div>
            </div>

            <div class="seom-section-header">System Status</div>
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

            <div class="seom-section-header">Recent Refresh Results <small>(last 14 days, top 30 by performance)</small> <a href="?page=seo-ai-autopilot&tab=tracker">Performance Tracker &rarr;</a></div>
            <table class="seom-table" id="seom-recent-table">
                <thead>
                    <tr><th>Page</th><th>Was</th><th>Now</th><th>Type</th><th>Days Ago</th><th>Clicks Before</th><th>Clicks Now</th><th>Change</th><th>Pos Before</th><th>Pos Now</th><th>Trend</th></tr>
                </thead>
                <tbody id="seom-recent-body">
                    <tr><td colspan="11" style="color:#999;">No recent refreshes. Process some pages and collect GSC data to see changes.</td></tr>
                </tbody>
            </table>

            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:8px;">
                <div style="flex:1;min-width:400px;">
                    <div class="seom-section-header" style="margin-top:0;">Top Improvements (30d)</div>
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
                    <div class="seom-section-header" style="margin-top:0;">Declines After Refresh (30d)</div>
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

                    // Goals mini-cards
                    if (d.active_goals && d.active_goals.length > 0) {
                        var goalMetricLabels = {
                            ghost_pages:'Ghost Pages', total_clicks:'Clicks (28d)', total_impressions:'Impressions (28d)',
                            avg_position:'Avg Position', avg_ctr:'Avg CTR', page1_pages:'Page 1 Pages',
                            page2_pages:'Page 2 Pages', pages_with_impressions:'With Impressions',
                            new_content_30d:'Total Published Content', stale_pages:'Stale Pages', refreshed_this_month:'Refreshed (MTD)'
                        };
                        var priColors = {1:'#dc2626',2:'#d97706',3:'#2563eb',4:'#64748b',5:'#94a3b8'};
                        var gc = $('#seom-overview-goals-cards').empty();

                        d.active_goals.forEach(function(g) {
                            var baseline = parseFloat(g.baseline_value);
                            var current = parseFloat(g.current_value);
                            var target = parseFloat(g.target_value);
                            var targetNum = (g.target_type === 'percent')
                                ? (g.direction === 'reduce' ? baseline - baseline * target / 100 : baseline + baseline * target / 100)
                                : target;
                            var totalChange = Math.abs(targetNum - baseline);
                            var actualChange = (g.direction === 'reduce') ? baseline - current : current - baseline;
                            var progress = totalChange > 0 ? Math.min(100, Math.max(0, Math.round(actualChange / totalChange * 100))) : 0;

                            var _precisionMetrics = {avg_ctr:1, avg_position:1};
                            var changeVal = _precisionMetrics[g.metric] ? parseFloat((current - baseline).toFixed(4)) : Math.round(current - baseline);
                            // Is the change moving in the right direction for this goal?
                            // For lower-is-better metrics (position, ghosts, stale, page2), decrease is always good
                            var _lowerBetter = {avg_position:1, ghost_pages:1, stale_pages:1, page2_pages:1};
                            var isGoodChange = _lowerBetter[g.metric] ? (changeVal < 0) : (g.direction === 'reduce' ? changeVal < 0 : changeVal > 0);
                            var isBadChange = _lowerBetter[g.metric] ? (changeVal > 0) : (g.direction === 'reduce' ? changeVal > 0 : changeVal < 0);

                            var daysLeft = Math.max(0, Math.ceil((new Date(g.deadline) - new Date()) / 86400000));
                            var pri = parseInt(g.priority) || 3;
                            var _start = g.start_date || g.created_at.substring(0, 10);
                            var _totalD = Math.max(1, Math.ceil((new Date(g.deadline) - new Date(_start)) / 86400000));
                            var _elapsedD = Math.max(0, Math.ceil((new Date() - new Date(_start)) / 86400000));
                            var _timePct = Math.min(100, (_elapsedD / _totalD) * 100);
                            var progColor;
                            if (isBadChange) { progColor = '#dc2626'; }
                            else if (progress >= 100) { progColor = '#059669'; }
                            else if (progress >= _timePct - 10) { progColor = '#059669'; }
                            else if (progress >= _timePct - 30) { progColor = '#d97706'; }
                            else { progColor = '#dc2626'; }
                            var label = goalMetricLabels[g.metric] || g.metric;
                            var typeLabel = g.target_type === 'percent' ? target + '%' : target;

                            // Change indicator: arrow based on ACTUAL direction of change, color based on good/bad
                            var changeHtml;
                            var fmtVal = _precisionMetrics[g.metric] ? parseFloat(changeVal).toFixed(2) : changeVal;
                            if (changeVal === 0) {
                                changeHtml = '<span style="font-size:12px; color:#94a3b8;">--</span>';
                            } else {
                                var actualArrow = changeVal > 0 ? '&#9650;' : '&#9660;';
                                var changeColor = isGoodChange ? '#059669' : '#dc2626';
                                changeHtml = '<span style="font-size:12px; color:' + changeColor + ';">' + actualArrow + ' ' + (changeVal > 0 ? '+' : '') + fmtVal + '</span>';
                            }

                            var displayCurrent = _precisionMetrics[g.metric] ? parseFloat(current).toFixed(2) : Math.round(current);
                            var displayBaseline = _precisionMetrics[g.metric] ? parseFloat(baseline).toFixed(2) : Math.round(baseline);
                            var displayTarget = _precisionMetrics[g.metric] ? parseFloat(targetNum).toFixed(2) : Math.round(targetNum);
                            var card = '<div style="background:#fff; border:1px solid #e2e8f0; border-top:3px solid ' + priColors[pri] + '; border-radius:8px; padding:12px 16px;">';
                            card += '<div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:4px;">' + label + '</div>';
                            card += '<div style="display:flex; align-items:baseline; gap:6px;">';
                            card += '<span style="font-size:22px; font-weight:700; color:#0f172a;">' + displayCurrent + '</span>';
                            card += changeHtml;
                            card += '</div>';
                            // Baseline → Target
                            card += '<div style="font-size:10px; color:#94a3b8; margin-top:2px;">Baseline: ' + displayBaseline + ' &rarr; Target: ' + displayTarget + '</div>';
                            // Mini progress bar
                            card += '<div style="background:#e2e8f0; border-radius:3px; height:6px; margin:6px 0 4px; overflow:hidden;">';
                            card += '<div style="width:' + Math.max(0, progress) + '%; background:' + progColor + '; height:100%; border-radius:3px;"></div></div>';
                            card += '<div style="display:flex; justify-content:space-between; font-size:10px; color:#94a3b8;">';
                            card += '<span>' + progress + '% of ' + typeLabel + '</span>';
                            card += '<span>' + daysLeft + 'd left</span>';
                            card += '</div></div>';
                            gc.append(card);
                        });

                        $('#seom-overview-goals').show();
                    }

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
                        var html = '<div class="seom-section-header">Queue by Category</div><div style="display:flex;gap:12px;flex-wrap:wrap;">';
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
            <button type="button" class="button seom-type-btn active" data-type="all">All</button>
            <button type="button" class="button seom-type-btn" data-type="product">Products</button>
            <button type="button" class="button seom-type-btn" data-type="post">Blog Posts</button>
            <button type="button" class="button seom-type-btn" data-type="page">Pages</button>
            <button type="button" class="button seom-type-btn" data-type="other">Other</button>
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
            <button type="button" class="button seom-filter-btn" data-filter="lost" style="color:#dc2626;">Lost</button>
            <button type="button" class="button seom-filter-btn" data-filter="stale_year" style="color:#d97706;">Stale Year</button>
            <button type="button" class="button seom-filter-btn" data-filter="top_performers" style="color:#059669;">Top Performers</button>
            <button type="button" class="button seom-filter-btn" data-filter="stars" style="color:#d97706;">Stars</button>
            <span style="margin:0 8px; color:#ccc;">|</span>
            <strong>Date Range:</strong>
            <select id="seom-date-range" style="padding:4px 8px; border:1px solid #c3c4c7; border-radius:4px; font-size:13px;">
                <option value="7">Last 7 Days</option>
                <option value="14">Last 14 Days</option>
                <option value="28" selected>Last 28 Days</option>
                <option value="60">Last 60 Days</option>
                <option value="90">Last 90 Days</option>
                <option value="180">Last 180 Days</option>
                <option value="custom">Custom...</option>
            </select>
            <span id="seom-idx-custom" style="display:none; align-items:center; gap:6px; flex-wrap:wrap;">
                <input type="date" id="seom-idx-from" style="padding:3px 6px; border:1px solid #c3c4c7; border-radius:4px; font-size:13px;">
                <span style="color:#94a3b8;">→</span>
                <input type="date" id="seom-idx-to" style="padding:3px 6px; border:1px solid #c3c4c7; border-radius:4px; font-size:13px;">
                <button type="button" class="button button-primary" id="seom-idx-apply">Apply</button>
                <span id="seom-idx-available" style="font-size:11px; color:#64748b;"></span>
            </span>
        </div>
        <div id="seom-filter-desc" style="font-size:12px; color:#64748b; margin:-8px 0 16px; padding-left:2px;"></div>
        <div id="seom-date-warning" style="display:none; font-size:12px; color:#b45309; background:#fef3c7; padding:8px 14px; border-radius:6px; margin-bottom:12px;"></div>

        <div id="seom-data-freshness" style="display:none; margin-bottom:12px; padding:10px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #2563eb; border-radius:6px; font-size:12px; color:#475569; line-height:1.5;">
            <strong>Data freshness:</strong>
            <span id="seom-freshness-snapshot">—</span>
            &middot;
            <span id="seom-freshness-daily">—</span>
        </div>

        <div class="seom-cards" id="seom-indexed-summary" style="display:none; transition:opacity 0.15s ease;">
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
            <?php $gsc_tip = 'GSC metrics reflect the most recent data collection snapshot. Due to Google&#39;s 2-3 day processing delay, the most recent days may contain partial data. Totals typically represent 95-97% of the final GSC values for the same period.'; ?>
            <div class="seom-card">
                <h3>Total Clicks (<span id="si-clicks-label">28d</span>) <span title="<?php echo $gsc_tip; ?>" style="cursor:help;color:#94a3b8;font-size:12px;">&#9432;</span></h3>
                <div class="seom-card-value" id="si-clicks">-</div>
            </div>
            <div class="seom-card">
                <h3>Total Impressions (<span id="si-imp-label">28d</span>) <span title="<?php echo $gsc_tip; ?>" style="cursor:help;color:#94a3b8;font-size:12px;">&#9432;</span></h3>
                <div class="seom-card-value" id="si-impressions">-</div>
            </div>
            <div class="seom-card">
                <h3>Avg Position <span title="<?php echo $gsc_tip; ?>" style="cursor:help;color:#94a3b8;font-size:12px;">&#9432;</span></h3>
                <div class="seom-card-value" id="si-avg-pos">-</div>
            </div>
        </div>

        <!-- Trend Chart -->
        <div id="seom-trend-container" style="display:none; margin-bottom:16px; padding:20px; background:#fff; border:1px solid #e2e8f0; border-radius:10px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; gap:12px; flex-wrap:wrap;">
                <h4 id="seom-trend-title" style="margin:0; font-size:14px; color:#334155;">Daily Trends <span style="font-size:11px; color:#94a3b8; font-weight:normal;">(set range above)</span></h4>
                <div style="display:flex; gap:6px; align-items:center;">
                    <span style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.3px;">Group by:</span>
                    <button class="button button-small seom-trend-group active" data-group="daily">Daily</button>
                    <button class="button button-small seom-trend-group" data-group="weekly">Weekly</button>
                    <button class="button button-small seom-trend-group" data-group="monthly">Monthly</button>
                    <button class="button button-small seom-trend-group" data-group="quarterly">Quarterly</button>
                </div>
            </div>
            <div style="position:relative; height:300px;">
                <canvas id="seom-trend-chart"></canvas>
            </div>
            <div id="seom-trend-summary"></div>
        </div>

        <!-- Featured Product Picker Modal -->
        <div id="seom-featured-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:100001; overflow:auto;">
            <div style="max-width:560px; margin:80px auto; background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3); overflow:hidden;">
                <div style="padding:20px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h3 style="margin:0; font-size:16px;">Associate Featured Product</h3>
                        <span style="font-size:12px; color:#64748b;" id="seom-featured-modal-post"></span>
                    </div>
                    <button class="button button-small" id="seom-featured-modal-close" style="font-size:16px; line-height:1; padding:4px 10px;">&times;</button>
                </div>
                <div style="padding:20px 24px;">
                    <div id="seom-featured-current" style="display:none; margin-bottom:14px; padding:10px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; font-size:13px; color:#065f46;"></div>
                    <p style="margin:0 0 10px; font-size:12px; color:#64748b;">Search for a WooCommerce product to associate with this blog post. The <code>[itu_featured_product]</code> shortcode will be injected after the first paragraph and near the bottom of the content on the next refresh.</p>
                    <input type="hidden" id="seom-featured-post-id" value="" />
                    <input type="text" id="seom-featured-search" placeholder="Search products by title…" style="width:100%; padding:8px 12px; border:1px solid #c3c4c7; border-radius:6px; font-size:14px;" autocomplete="off" />
                    <div id="seom-featured-results" style="display:none; margin-top:6px; max-height:260px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px; background:#fff;"></div>
                    <div style="margin-top:16px; display:flex; gap:8px; justify-content:flex-end;">
                        <button type="button" class="button" id="seom-featured-clear" style="color:#dc2626;">Clear Association</button>
                        <button type="button" class="button" id="seom-featured-cancel">Cancel</button>
                    </div>
                    <div id="seom-featured-status" style="margin-top:10px; font-size:12px; text-align:right;"></div>
                </div>
            </div>
        </div>

        <!-- Research Viewer Modal -->
        <div id="seom-research-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:100000; overflow:auto;">
            <div style="max-width:800px; margin:60px auto; background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3); overflow:hidden;">
                <div style="padding:20px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h3 style="margin:0; font-size:16px;" id="seom-research-modal-title">Competitive Research</h3>
                        <span style="font-size:12px; color:#64748b;" id="seom-research-modal-date"></span>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <button class="button button-small seom-research-btn" id="seom-research-refresh-btn" data-id="" style="color:#7c3aed;">Refresh Research</button>
                        <button class="button button-small" id="seom-research-modal-close" style="font-size:16px; line-height:1; padding:4px 10px;">&times;</button>
                    </div>
                </div>
                <div id="seom-research-modal-body" style="padding:24px; font-size:14px; line-height:1.7; white-space:pre-wrap; max-height:70vh; overflow:auto;"></div>
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
                    <th style="width:280px;">SEO Title / Meta</th>
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
                    <th class="seom-sortable" data-sort="last_refresh" style="cursor:pointer;width:80px;">Last Refreshed</th>
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
            .seom-trk-sort.sorted-asc::after  { content: ' ▲'; font-size: 10px; }
            .seom-trk-sort.sorted-desc::after { content: ' ▼'; font-size: 10px; }
            .seom-pos-good { color: #16a34a; font-weight: 600; }
            .seom-pos-near { color: #b45309; font-weight: 600; }
            .seom-pos-far { color: #dc2626; }
            .seom-pos-ghost { color: #999; font-style: italic; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var currentType = 'all';
            var currentSort = 'clicks';
            var currentOrder = 'DESC';
            var currentFilter = 'all';
            var currentPage = 1;
            var currentDateRange = 28;
            var currentDateFrom = null; // populated when user picks Custom...
            var currentDateTo = null;

            // Track in-flight requests so the metric cards stay dimmed until BOTH
            // loadIndexed and loadTrends finish (whichever populates them last wins).
            var summaryLoadingCount = 0;
            function summaryLoadingStart() {
                summaryLoadingCount++;
                $('#seom-indexed-summary').css('opacity', '0.4');
            }
            function summaryLoadingEnd() {
                summaryLoadingCount = Math.max(0, summaryLoadingCount - 1);
                if (summaryLoadingCount === 0) {
                    $('#seom-indexed-summary').css('opacity', '1');
                }
            }

            function loadIndexed(page) {
                currentPage = page || 1;
                var sel = $('#seom-date-range').val();
                var isCustom = (sel === 'custom');
                if (!isCustom) currentDateRange = parseInt(sel) || 28;
                $('#seom-indexed-loading').show().text('Loading...');
                $('#seom-indexed-table').hide();
                $('#seom-indexed-pagination').empty();
                summaryLoadingStart();

                var payload = {
                    action: 'seom_get_indexed', nonce: seom_nonce,
                    post_type: currentType, sort: currentSort, order: currentOrder,
                    filter: currentFilter, page: currentPage, date_range: currentDateRange
                };
                if (isCustom && currentDateFrom && currentDateTo) {
                    payload.date_from = currentDateFrom;
                    payload.date_to   = currentDateTo;
                }

                $.ajax({ url: ajaxurl, method: 'POST', timeout: 60000, data: payload, complete: function() {
                    summaryLoadingEnd();
                }, success: function(resp) {
                    $('#seom-indexed-loading').hide();
                    if (!resp.success) return;

                    var d = resp.data;
                    var s = d.summary;

                    // Populate the custom date inputs' min/max + a one-line
                    // "available data" hint so the user knows how far back they can dig.
                    if (d.available && d.available.date_from && d.available.date_to) {
                        $('#seom-idx-from').attr({min: d.available.date_from, max: d.available.date_to});
                        $('#seom-idx-to').attr({min: d.available.date_from, max: d.available.date_to});
                        var spanDays = Math.floor((new Date(d.available.date_to) - new Date(d.available.date_from)) / 86400000) + 1;
                        $('#seom-idx-available').text('Available: ' + d.available.date_from + ' → ' + d.available.date_to + ' (' + spanDays + ' days)');
                        if (!$('#seom-idx-from').val()) $('#seom-idx-from').val(d.available.date_from);
                        if (!$('#seom-idx-to').val())   $('#seom-idx-to').val(d.available.date_to);
                    }

                    // ── Data freshness banner ──
                    // Tells the user EXACTLY which dates the cards reflect.
                    //
                    // Architecture (post-reconciliation removal):
                    //  - Cards: 28-day rolling snapshot from GSC's per-page query.
                    //    Includes yesterday + day-before-yesterday's partial data —
                    //    the FRESHEST view of the trailing 28 days. Refreshed
                    //    every night by the daily collection cron at 01:00.
                    //  - Chart: per-day per-page rows from GSC's single-day queries.
                    //    Each day is finalized data from when it was T-3, so the
                    //    newest day in the chart is always ~3 days behind today.
                    //
                    //  → Cards SHOULD be slightly higher than the chart sum, by
                    //    the amount of the most-recent 2 days of traffic.
                    //  → If they match exactly, daily collection or snapshot is broken.
                    if (d.data_freshness) {
                        var f = d.data_freshness;
                        var snapshotMsg = f.snapshot_date
                            ? 'Cards: 28-day rolling snapshot collected <strong>' + f.snapshot_date + '</strong> (includes most recent partial-day data from GSC)'
                            : 'Cards: <span style="color:#dc2626;">no snapshot data yet — run data collection</span>';
                        var dailyMsg = f.daily_max_date
                            ? 'Chart: finalized daily data through <strong>' + f.daily_max_date + '</strong> (Google T-3 lag — most recent 2-3 days not yet finalized)'
                            : 'Chart: <span style="color:#dc2626;">no daily data yet</span>';

                        // Stale daily data warning — Google's T-3 lag means daily_max_date
                        // should always be ~3 days ago. If it's older than 5 days the cron is broken.
                        if (f.daily_max_date) {
                            var daysOld = Math.floor((new Date() - new Date(f.daily_max_date)) / 86400000);
                            if (daysOld > 5) {
                                dailyMsg += ' <span style="color:#dc2626;font-weight:600;">⚠ ' + daysOld + ' days old — daily collection cron may be broken</span>';
                            }
                        }

                        $('#seom-freshness-snapshot').html(snapshotMsg);
                        $('#seom-freshness-daily').html(dailyMsg);
                        $('#seom-data-freshness').show();
                    }

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

                    $('#si-total').closest('.seom-card').find('h3').text('Total Pages' + suffix);

                    // loadTrends() owns the three period-total cards (Clicks, Impressions, Avg Position)
                    // so it can show period totals + prior-period delta. loadIndexed handles the
                    // count cards (Total Pages, With Impressions, Ghost, New) below.
                    var sp = d.summary_prior || null;

                    function countDelta(now, before, invert) {
                        now = parseInt(now || 0);
                        if (before == null) return '';
                        before = parseInt(before || 0);
                        var diff = now - before;
                        if (diff === 0) return ' <span style="font-size:11px;color:#94a3b8;">(0)</span>';
                        var good = invert ? (diff < 0) : (diff > 0);
                        var c = good ? '#059669' : '#dc2626';
                        var arrow = diff > 0 ? '&#9650; ' : '&#9660; ';
                        var pct = before ? ' (' + (diff > 0 ? '+' : '-') + Math.abs(Math.round((diff / before) * 100)) + '%)' : '';
                        return ' <span style="font-size:11px;color:'+c+';font-weight:600;">' + arrow + Math.abs(diff).toLocaleString() + pct + '</span>';
                    }

                    // Direction depends on filter — for "bad" filter buckets (Low CTR, Ghost,
                    // Underperforming, Buried, Near Wins, Limited, Lost, Stale Year), fewer
                    // pages is the desired outcome so a decrease is green.
                    var lowerIsBetterFilters = {
                        ghost:1, underperforming:1, buried:1, page2:1, low_ctr:1,
                        limited:1, lost:1, stale_year:1
                    };
                    var totalPagesInvert = !!lowerIsBetterFilters[currentFilter];

                    $('#si-total').html(parseInt(s.total_pages || 0).toLocaleString() + countDelta(s.total_pages, sp ? sp.total_pages : null, totalPagesInvert));
                    $('#si-with-impressions').html(parseInt(s.pages_with_impressions || 0).toLocaleString() + countDelta(s.pages_with_impressions, sp ? sp.pages_with_impressions : null, false));
                    $('#si-ghost').html(parseInt(s.ghost_pages || 0).toLocaleString() + countDelta(s.ghost_pages, sp ? sp.ghost_pages : null, true));
                    $('#si-new').html(parseInt(s.new_pages || 0).toLocaleString() + countDelta(s.new_pages, sp ? sp.new_pages : null, true));

                    // Avg Position card.
                    //
                    // Source priority:
                    //   gsc_aggregate → GSC's authoritative no-dimension site-wide
                    //                   value from the nightly cron. Matches GSC web
                    //                   dashboard exactly. Used on the default 28-day
                    //                   All/All view.
                    //   computed     → Per-page impression-weighted average from the
                    //                  snapshot or daily-temp-table. Drifts ~2-3
                    //                  positions higher than GSC because it includes
                    //                  the long tail of low-impression pages that
                    //                  GSC's site-wide aggregate excludes. Shown with
                    //                  an "(approx)" label so the user knows.
                    //
                    // Lower position is better, so the delta is INVERTED (a decrease
                    // from 18.9 → 18.1 shows in green, not red).
                    function posDelta(now, before) {
                        if (before == null || !before) return ' <span style="font-size:11px;color:#94a3b8;">(—)</span>';
                        var d = parseFloat((now - before).toFixed(1));
                        if (d === 0) return ' <span style="font-size:11px;color:#94a3b8;">(0)</span>';
                        var good = d < 0; // lower position is better
                        var c = good ? '#059669' : '#dc2626';
                        var arrow = d > 0 ? '&#9650; ' : '&#9660; ';
                        var pct = before ? ' (' + (d > 0 ? '+' : '-') + Math.abs(Math.round((d / before) * 100)) + '%)' : '';
                        return ' <span style="font-size:11px;color:'+c+';font-weight:600;">' + arrow + Math.abs(d).toFixed(1) + pct + '</span>';
                    }
                    var posNow = parseFloat(s.avg_position) || 0;
                    var posPrev = sp ? (parseFloat(sp.avg_position) || 0) : null;
                    var posLabel = posNow.toFixed(1) + posDelta(posNow, posPrev);
                    if (s.avg_position_source && s.avg_position_source !== 'gsc_aggregate') {
                        posLabel += ' <span style="font-size:10px;color:#94a3b8;font-style:italic;" title="Approximate — computed from per-page rows because a filter or post type is applied (or the nightly site-totals cache for this range hasn\'t populated yet). The unfiltered All / All view matches GSC exactly.">(approx)</span>';
                    }
                    $('#si-avg-pos').html(posLabel);

                    $('#seom-indexed-summary').show();

                    // Date range warning
                    if (d.date_warning) {
                        $('#seom-date-warning').html(d.date_warning).show();
                    } else {
                        $('#seom-date-warning').hide();
                    }

                    // Update card headers to reflect date range
                    var rangeLabel = {1:'1d',7:'7d',14:'14d',28:'28d',60:'60d',90:'90d',180:'180d',365:'12mo'};
                    var rl = rangeLabel[currentDateRange] || currentDateRange + 'd';
                    $('#si-clicks-label').text(rl);
                    $('#si-imp-label').text(rl);

                    // Table
                    var tbody = $('#seom-indexed-body').empty();
                    if (!d.rows.length) {
                        tbody.append('<tr><td colspan="15" style="color:#94a3b8;">No data matching this filter. Try a different filter or run data collection.</td></tr>');
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
                        var fullCooldownDays = d.cooldown_days || 90;
                        var metaCooldownDays = 30;
                        // Use meta cooldown (30d) for meta_only refreshes, full cooldown for everything else
                        var isMetaOnly = (r.last_refresh_type === 'meta_only');
                        var cooldownDays = isMetaOnly ? metaCooldownDays : fullCooldownDays;
                        var cooldownDate = new Date();
                        cooldownDate.setDate(cooldownDate.getDate() - cooldownDays);
                        var refreshedRecently = r.last_refresh && (new Date(r.last_refresh) >= cooldownDate);

                        var isPage = (r.post_type === 'page');
                        var isOther = (r.post_type === 'other');
                        if (isOther) {
                            // Other (untracked) URLs — no WordPress post to refresh, metrics only
                            actions = '<span style="color:#94a3b8;font-size:11px;">Tracked (no WP post)</span>';
                        } else if (isPage) {
                            // Pages — allow meta-only refresh (title, description, keyword) but not full content
                            actions = '<button class="button button-small seom-queue-btn" data-id="' + r.post_id + '" data-type="meta_only" title="Update meta description, focus keyword, and SEO title">Meta Only</button>';
                        } else if (r.in_queue) {
                            actions = '<span style="color:#b45309;font-size:12px;">In Queue</span>';
                        } else if (refreshedRecently) {
                            var daysAgo = Math.floor((new Date() - new Date(r.last_refresh)) / 86400000);
                            var typeLabel = isMetaOnly ? 'Meta' : 'Full';
                            actions = '<span class="seom-cooldown-label" data-id="' + r.post_id + '" style="color:#94a3b8;font-size:11px;cursor:pointer;border-bottom:1px dashed #cbd5e1;" title="' + typeLabel + ' refresh ' + daysAgo + 'd ago. Cooldown: ' + cooldownDays + 'd (' + typeLabel.toLowerCase() + '). Click to override.">Cooldown (' + (cooldownDays - daysAgo) + 'd)</span>'
                                + '<span class="seom-cooldown-override" data-id="' + r.post_id + '" style="display:none;">'
                                + '<button class="button button-small seom-queue-btn" data-id="' + r.post_id + '" data-type="full" title="Override cooldown — full refresh" style="color:#dc2626;">Refresh</button> '
                                + '<button class="button button-small seom-queue-btn" data-id="' + r.post_id + '" data-type="meta_only" title="Override cooldown — meta only" style="color:#dc2626;">Meta</button> '
                                + '<span class="seom-cooldown-cancel" style="color:#94a3b8;font-size:11px;cursor:pointer;margin-left:2px;" title="Cancel">&times;</span>'
                                + '</span>';
                        } else {
                            actions = '<button class="button button-small seom-queue-btn" data-id="' + r.post_id + '" data-type="full" title="Full content refresh">Refresh</button> '
                                + '<button class="button button-small seom-queue-btn" data-id="' + r.post_id + '" data-type="meta_only" title="Meta description + keyword only">Meta Only</button>';
                        }

                        // Research button (available for any WordPress post — not untracked URLs)
                        if (!isOther) {
                            if (r.research_date) {
                                var rDate = r.research_date.substring(0, 10);
                                actions += ' <button class="button button-small seom-view-research" data-id="' + r.post_id + '" title="View research collected ' + rDate + '. Click to view, right-click to refresh." style="color:#7c3aed;font-size:11px;">Research ' + rDate + '</button>';
                            } else {
                                actions += ' <button class="button button-small seom-research-btn" data-id="' + r.post_id + '" title="Collect competitive research via web search" style="color:#7c3aed;">Research</button>';
                            }
                        }

                        // Featured Product button — only shown for blog posts.
                        // Opens a modal to search/attach a WooCommerce product;
                        // the shortcode is re-injected on every full refresh.
                        if (r.post_type === 'post') {
                            var fpLabel = r.featured_product_title
                                ? 'Featured: ' + (r.featured_product_title.length > 18 ? r.featured_product_title.substring(0, 18) + '…' : r.featured_product_title)
                                : 'Featured Product';
                            var fpStyle = r.featured_product_title ? 'color:#059669;font-weight:600;' : 'color:#C026D3;';
                            actions += ' <button class="button button-small seom-featured-btn" data-id="' + r.post_id + '" title="Associate a WooCommerce product with this post — inserts a Featured Product block during refresh" style="' + fpStyle + '">' + fpLabel + '</button>';
                        }

                        // Checkbox — available for all WordPress posts including pages (not untracked URLs)
                        var canSelect = (!isOther && !r.in_queue && !(refreshedRecently && !isPage));
                        var checkbox = canSelect
                            ? '<input type="checkbox" class="seom-idx-cb" value="' + r.post_id + '" />'
                            : '';

                        // For "other" (untracked URLs): show URL as link + type badge instead of edit link
                        var titleCell;
                        if (isOther) {
                            var shortUrl = (r.url || r.post_title || '').replace(/^https?:\/\//, '');
                            var fullUrl = r.url || r.post_title || '';
                            var typeBadge = r.url_type ? ' <span class="seom-badge" style="font-size:10px;">' + r.url_type + '</span>' : '';
                            titleCell = '<a href="' + fullUrl + '" target="_blank" style="color:#1e293b;">' + shortUrl + '</a>' + typeBadge;
                        } else {
                            titleCell = '<a href="' + editUrl + '" target="_blank">' + r.post_title + '</a>'
                                + '<br><small style="color:#94a3b8;"><a href="' + viewUrl + '" target="_blank" style="color:#94a3b8;">' + (viewUrl || '') + '</a></small>';
                        }

                        // SEO Title + Meta Description cell — pulled from rank_math_* meta on the backend.
                        // When meta is empty, RankMath defaults to post title / excerpt at render time,
                        // so we show the effective value with a CUSTOM/DEFAULT badge.
                        var escAttr = function(s) { return (s || '').replace(/"/g,'&quot;'); };

                        // ── SEO Title row ──
                        var seoTitle = r.seo_title || '';
                        var hasCustomTitle = !!seoTitle;
                        var effectiveTitle = hasCustomTitle ? seoTitle : (r.post_title || '');
                        var titleRow = '';
                        if (effectiveTitle) {
                            var titleLen = effectiveTitle.length;
                            var titleLenColor = titleLen <= 60 ? '#16a34a' : (titleLen <= 70 ? '#d97706' : '#dc2626');
                            var titleBadge = hasCustomTitle
                                ? '<span style="font-size:9px;background:#dcfce7;color:#166534;padding:1px 5px;border-radius:3px;font-weight:600;" title="Custom SEO title set in RankMath meta">CUSTOM</span>'
                                : '<span style="font-size:9px;background:#fef3c7;color:#92400e;padding:1px 5px;border-radius:3px;font-weight:600;" title="No custom rank_math_title meta — RankMath falls back to the post title.">DEFAULT</span>';
                            titleRow = '<div style="font-size:12px;color:#1e293b;line-height:1.3;" title="' + escAttr(effectiveTitle) + '">' + effectiveTitle + '</div>'
                                + '<div style="font-size:10px;color:#94a3b8;margin-top:2px;">'
                                +    titleBadge + ' <span style="color:' + titleLenColor + ';">' + titleLen + ' chars</span>'
                                + '</div>';
                        } else {
                            titleRow = '<div><span style="font-size:11px;color:#cbd5e1;font-style:italic;">— title not set —</span></div>';
                        }

                        // ── Meta Description row ──
                        var metaDesc = r.meta_description || '';
                        var hasCustomMeta = !!metaDesc;
                        var metaRow = '';
                        if (hasCustomMeta) {
                            var metaLen = metaDesc.length;
                            // Google snippet sweet spot: 120–158 chars
                            var metaLenColor = (metaLen >= 120 && metaLen <= 158) ? '#16a34a'
                                             : (metaLen < 120 ? '#d97706' : '#dc2626');
                            // Truncate display to two lines via CSS line-clamp; full text in title attr
                            metaRow = '<div style="font-size:11px;color:#475569;line-height:1.35;margin-top:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;" title="' + escAttr(metaDesc) + '">' + metaDesc + '</div>'
                                + '<div style="font-size:10px;color:#94a3b8;margin-top:2px;">'
                                +    '<span style="font-size:9px;background:#dcfce7;color:#166534;padding:1px 5px;border-radius:3px;font-weight:600;" title="Custom meta description set in RankMath meta">META</span>'
                                +    ' <span style="color:' + metaLenColor + ';">' + metaLen + ' chars</span>'
                                + '</div>';
                        } else {
                            metaRow = '<div style="font-size:11px;color:#cbd5e1;font-style:italic;margin-top:6px;">— meta description not set —</div>';
                        }

                        var seoTitleCell = titleRow + metaRow;

                        tbody.append('<tr>' +
                            '<td>' + checkbox + '</td>' +
                            '<td>' + titleCell + '</td>' +
                            '<td>' + seoTitleCell + '</td>' +
                            '<td>' + wasBadge + '</td>' +
                            '<td>' + nowBadge + '</td>' +
                            '<td>' + clicks.toLocaleString() + '</td>' +
                            '<td style="font-size:12px;">' + clickDelta + '</td>' +
                            '<td>' + imp.toLocaleString() + '</td>' +
                            '<td style="font-size:12px;">' + impDelta + '</td>' +
                            '<td>' + (pos > 0 ? pos.toFixed(1) : '-') + '</td>' +
                            '<td style="font-size:12px;">' + posDelta + '</td>' +
                            '<td>' + parseFloat(r.ctr).toFixed(2) + '%</td>' +
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
                    var statusIdx = '<span style="margin-left:12px;color:#64748b;font-size:13px;">Page ' + d.page + ' of ' + totalPages + ' &middot; ' + (d.total || 0).toLocaleString() + ' pages</span>';
                    $('#seom-indexed-pagination').html(window.seomBuildPagination(d.page, totalPages, 'seom-idx-page', statusIdx));
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
                loadTrends();
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
                lost: 'Pages no longer in the index — they previously had 10+ impressions in an earlier collection but now have zero. Unlike ghosts (which may never have had visibility), these pages lost their search presence.',
                stale_year: 'Pages with an outdated year in the title (e.g., "2025" when current year is ' + new Date().getFullYear() + '). These titles hurt CTR and signal stale content to Google. Years are automatically updated when a refresh runs.',
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
                loadTrends();
            });

            // Date range change — handled below in the unified handler that drives both
            // loadIndexed() and loadTrends() so the cards, table, and chart stay in sync.

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

            // Cooldown override — click cooldown label to reveal override buttons
            $(document).on('click', '.seom-cooldown-label', function() {
                var id = $(this).data('id');
                $(this).hide();
                $('.seom-cooldown-override[data-id="' + id + '"]').show();
            });
            // Cancel override — hide buttons, show label again
            $(document).on('click', '.seom-cooldown-cancel', function() {
                var override = $(this).closest('.seom-cooldown-override');
                var id = override.data('id');
                override.hide();
                $('.seom-cooldown-label[data-id="' + id + '"]').show();
            });

            // View existing research
            $(document).on('click', '.seom-view-research', function() {
                var btn = $(this);
                var postId = btn.data('id');
                $('#seom-research-modal-body').text('Loading...');
                $('#seom-research-modal-title').text('Loading...');
                $('#seom-research-modal-date').text('');
                $('#seom-research-refresh-btn').data('id', postId);
                $('#seom-research-modal').fadeIn(200);

                $.post(ajaxurl, { action: 'seom_get_research', nonce: seom_nonce, post_id: postId }, function(resp) {
                    if (resp.success) {
                        $('#seom-research-modal-title').text(resp.data.title);
                        $('#seom-research-modal-date').text('Research collected: ' + (resp.data.date || 'Unknown'));
                        $('#seom-research-modal-body').text(resp.data.research);
                    } else {
                        $('#seom-research-modal-body').text(resp.data || 'No research available.');
                    }
                });
            });

            // Close research modal
            $('#seom-research-modal-close, #seom-research-modal').click(function(e) {
                if (e.target === this) $('#seom-research-modal').fadeOut(200);
            });

            // ── Featured Product modal ──
            var fpSearchTimer = null;
            function fpRunSearch(term) {
                $.post(ajaxurl, { action: 'seom_search_products', nonce: seom_nonce, term: term }, function(resp) {
                    if (!resp.success) return;
                    var $list = $('#seom-featured-results').empty();
                    if (!resp.data.products.length) {
                        $list.append('<div style="padding:10px 14px;color:#94a3b8;font-size:13px;">No products found.</div>');
                    } else {
                        resp.data.products.forEach(function(p) {
                            var safe = $('<div>').text(p.title).html();
                            $list.append('<div class="seom-fp-row" data-id="' + p.id + '" data-title="' + safe.replace(/"/g, '&quot;') + '" style="padding:8px 14px; cursor:pointer; border-bottom:1px solid #f1f5f9; font-size:13px;">' + safe + '</div>');
                        });
                    }
                    $list.show();
                });
            }
            $('#seom-featured-search').on('input focus', function() {
                clearTimeout(fpSearchTimer);
                var term = $(this).val();
                fpSearchTimer = setTimeout(function() { fpRunSearch(term); }, 250);
            });
            $(document).on('mouseover', '.seom-fp-row', function() { $(this).css('background', '#f8fafc'); });
            $(document).on('mouseout', '.seom-fp-row', function() { $(this).css('background', ''); });

            // Open the modal when the row button is clicked
            $(document).on('click', '.seom-featured-btn', function() {
                var postId = $(this).data('id');
                $('#seom-featured-post-id').val(postId);
                $('#seom-featured-search').val('');
                $('#seom-featured-results').hide().empty();
                $('#seom-featured-status').empty();
                $('#seom-featured-current').hide().empty();
                $('#seom-featured-modal-post').text('Post ID: ' + postId);
                $('#seom-featured-modal').fadeIn(200);

                // Load current association
                $.post(ajaxurl, { action: 'seom_get_post_featured_product', nonce: seom_nonce, post_id: postId }, function(resp) {
                    if (resp.success && resp.data.product) {
                        $('#seom-featured-current').show().html('<strong>Currently featured:</strong> ' + $('<div>').text(resp.data.product.title).html() + ' <span style="color:#94a3b8;font-size:11px;">(ID ' + resp.data.product.id + ')</span>');
                    }
                });

                // Prime the search results with the most-recent products
                setTimeout(function() { fpRunSearch(''); }, 50);
            });

            // Pick a product
            $(document).on('click', '.seom-fp-row', function() {
                var pid = $(this).data('id');
                var title = $(this).data('title');
                var postId = $('#seom-featured-post-id').val();
                $('#seom-featured-status').html('<span style="color:#b45309;">Saving…</span>');
                $.post(ajaxurl, { action: 'seom_set_post_featured_product', nonce: seom_nonce, post_id: postId, product_id: pid }, function(resp) {
                    if (resp.success) {
                        $('#seom-featured-status').html('<span style="color:#059669;">Associated with: ' + title + '. Next refresh will insert the Featured Product block.</span>');
                        // Update the row button label without a full reload
                        var label = title.length > 18 ? title.substring(0, 18) + '…' : title;
                        $('.seom-featured-btn[data-id="' + postId + '"]').text('Featured: ' + label).css({color:'#059669', 'font-weight':'600'});
                        setTimeout(function() { $('#seom-featured-modal').fadeOut(200); }, 900);
                    } else {
                        $('#seom-featured-status').html('<span style="color:#dc2626;">Error: ' + (resp.data || 'Save failed') + '</span>');
                    }
                });
            });

            // Clear the association
            $('#seom-featured-clear').click(function() {
                var postId = $('#seom-featured-post-id').val();
                if (!confirm('Clear the featured product association for this post? The shortcode will be removed on the next refresh.')) return;
                $('#seom-featured-status').html('<span style="color:#b45309;">Clearing…</span>');
                $.post(ajaxurl, { action: 'seom_set_post_featured_product', nonce: seom_nonce, post_id: postId, product_id: 0 }, function(resp) {
                    if (resp.success) {
                        $('#seom-featured-status').html('<span style="color:#059669;">Cleared.</span>');
                        $('.seom-featured-btn[data-id="' + postId + '"]').text('Featured Product').css({color:'#C026D3', 'font-weight':'normal'});
                        setTimeout(function() { $('#seom-featured-modal').fadeOut(200); }, 600);
                    } else {
                        $('#seom-featured-status').html('<span style="color:#dc2626;">Error: ' + (resp.data || 'Clear failed') + '</span>');
                    }
                });
            });

            // Close featured modal
            $('#seom-featured-modal-close, #seom-featured-cancel').click(function() {
                $('#seom-featured-modal').fadeOut(200);
            });
            $('#seom-featured-modal').click(function(e) {
                if (e.target === this) $(this).fadeOut(200);
            });

            // Collect new research (from "Research" button or modal refresh button)
            $(document).on('click', '.seom-research-btn', function() {
                var btn = $(this);
                var postId = btn.data('id');
                var origText = btn.text();
                btn.prop('disabled', true).text('Researching...');
                $.ajax({ url: ajaxurl, method: 'POST', timeout: 90000, data: {
                    action: 'seom_collect_research', nonce: seom_nonce, post_id: postId
                }, success: function(resp) {
                    btn.prop('disabled', false);
                    if (resp.success) {
                        var newDate = (resp.data.date || '').substring(0, 10);
                        // If this was the modal refresh button, update modal content
                        if (btn.attr('id') === 'seom-research-refresh-btn') {
                            btn.text('Refresh Research');
                            $('#seom-research-modal-date').text('Research collected: ' + resp.data.date);
                            $('#seom-research-modal-body').text(resp.data.research);
                        } else {
                            // Replace the button with a view button showing the new date
                            btn.removeClass('seom-research-btn').addClass('seom-view-research')
                                .text('Research ' + newDate).css('font-size', '11px');
                        }
                    } else {
                        btn.text(origText);
                        alert(resp.data || 'Research failed');
                    }
                }, error: function() {
                    btn.prop('disabled', false).text(origText);
                    alert('Research request timed out. Try again.');
                }});
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

            // ── Trend Chart ──
            var trendChart = null;
            var trendDays = 28;
            var trendGroup = 'daily'; // daily | weekly | monthly | quarterly

            function loadTrends(days) {
                // If no explicit days passed, sync to the top date range dropdown
                var sel = $('#seom-date-range').val();
                var isCustom = (sel === 'custom');
                if (!isCustom) trendDays = parseInt(days || sel, 10) || 28;
                $('#seom-trend-chart').css('opacity', '0.3');
                summaryLoadingStart();
                $('#seom-trend-summary').html('<div style="text-align:center;padding:12px;color:#94a3b8;"><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>Loading...</div>');
                var trendPayload = { action: 'seom_get_trends', nonce: seom_nonce, days: trendDays, post_type: currentType, filter: currentFilter, group: trendGroup };
                if (isCustom && currentDateFrom && currentDateTo) {
                    trendPayload.date_from = currentDateFrom;
                    trendPayload.date_to   = currentDateTo;
                }
                $.post(ajaxurl, trendPayload, function(resp) {
                    $('#seom-trend-chart').css('opacity', '1');
                    summaryLoadingEnd();
                    if (!resp.success) return;

                    // Backwards-compat: response can be an array (old shape) or {current, prior} (new shape)
                    var payload = resp.data;
                    if (Array.isArray(payload)) payload = { current: payload, prior: [] };
                    var data = payload.current || [];
                    var prior = payload.prior || [];
                    if (!data.length) return;

                    var labels = data.map(function(r) { return r.date_collected; });

                    // Use GSC totals when available, fall back to tracked totals
                    var useGscChart = (currentType === 'all' && currentFilter === 'all');
                    var pickClicks = function(r) { return parseInt(useGscChart ? (r.gsc_total_clicks || r.total_clicks) : r.total_clicks) || 0; };
                    var pickImpressions = function(r) { return parseInt(useGscChart ? (r.gsc_total_impressions || r.total_impressions) : r.total_impressions) || 0; };

                    var clicks = data.map(pickClicks);
                    var impressions = data.map(pickImpressions);

                    // Align prior period to current period by index (day-of-period).
                    // prior[i] corresponds to current[i] — i.e., the same position in the prior window.
                    var priorImpressions = data.map(function(_, i) { return prior[i] ? pickImpressions(prior[i]) : null; });
                    var priorClicks      = data.map(function(_, i) { return prior[i] ? pickClicks(prior[i]) : null; });
                    var priorDates       = data.map(function(_, i) { return prior[i] ? prior[i].date_collected : null; });

                    var ctx = document.getElementById('seom-trend-chart').getContext('2d');
                    if (trendChart) trendChart.destroy();

                    trendChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'Impressions',
                                    data: impressions,
                                    borderColor: '#3b82f6',
                                    backgroundColor: 'rgba(59,130,246,0.05)',
                                    borderWidth: 2,
                                    fill: true,
                                    tension: 0.3,
                                    yAxisID: 'y',
                                    pointRadius: data.length > 60 ? 0 : 2,
                                },
                                {
                                    label: 'Impressions (prior)',
                                    data: priorImpressions,
                                    borderColor: '#93c5fd',
                                    borderWidth: 1.5,
                                    borderDash: [5, 4],
                                    fill: false,
                                    tension: 0.3,
                                    yAxisID: 'y',
                                    pointRadius: 0,
                                    spanGaps: true,
                                },
                                {
                                    label: 'Clicks',
                                    data: clicks,
                                    borderColor: '#059669',
                                    borderWidth: 2,
                                    tension: 0.3,
                                    yAxisID: 'yClicks',
                                    pointRadius: data.length > 60 ? 0 : 2,
                                },
                                {
                                    label: 'Clicks (prior)',
                                    data: priorClicks,
                                    borderColor: '#86efac',
                                    borderWidth: 1.5,
                                    borderDash: [5, 4],
                                    fill: false,
                                    tension: 0.3,
                                    yAxisID: 'yClicks',
                                    pointRadius: 0,
                                    spanGaps: true,
                                },
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { usePointStyle: true, padding: 16, font: { size: 12 } }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(ctx) {
                                            var val = ctx.raw == null ? '—' : ctx.raw.toLocaleString();
                                            return ctx.dataset.label + ': ' + val;
                                        },
                                        afterBody: function(items) {
                                            if (!items.length) return '';
                                            var i = items[0].dataIndex;
                                            var pd = priorDates[i];
                                            return pd ? 'Prior period date: ' + pd : '';
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { display: false },
                                    ticks: { font: { size: 10 }, maxTicksLimit: 15 }
                                },
                                y: {
                                    type: 'linear',
                                    position: 'left',
                                    title: { display: true, text: 'Impressions', font: { size: 11 }, color: '#3b82f6' },
                                    grid: { color: 'rgba(0,0,0,0.04)' },
                                    ticks: { font: { size: 10 }, color: '#3b82f6' },
                                    beginAtZero: true,
                                },
                                yClicks: {
                                    type: 'linear',
                                    position: 'right',
                                    title: { display: true, text: 'Clicks', font: { size: 11 }, color: '#059669' },
                                    grid: { display: false },
                                    ticks: { font: { size: 10 }, color: '#059669' },
                                    beginAtZero: true,
                                },
                            }
                        }
                    });

                    // Summary bar — compare current period totals to prior period totals
                    if (data.length >= 1) {
                        var first = data[0], last = data[data.length - 1];

                        // Sum totals across the entire period (not just first/last)
                        var sumImp = function(arr) { return arr.reduce(function(s, r) { return s + pickImpressions(r); }, 0); };
                        var sumClk = function(arr) { return arr.reduce(function(s, r) { return s + pickClicks(r); }, 0); };

                        var impNow  = sumImp(data);
                        var impPrev = sumImp(prior);
                        var clkNow  = sumClk(data);
                        var clkPrev = sumClk(prior);

                        function fmtDelta(now, before, invert) {
                            if (!before) return '<span style="color:#94a3b8;font-weight:600;">(—)</span>';
                            var d = now - before;
                            if (typeof d === 'number' && d % 1 !== 0) d = parseFloat(d.toFixed(1));
                            var good = invert ? (d < 0) : (d > 0);
                            var c = d === 0 ? '#94a3b8' : (good ? '#059669' : '#dc2626');
                            var s = d > 0 ? '+' : '';
                            var formatted = typeof d === 'number' && d % 1 !== 0 ? d.toFixed(1) : d.toLocaleString();
                            var pct = before ? ' (' + (d > 0 ? '+' : '') + Math.round((d / before) * 100) + '%)' : '';
                            return '<span style="color:'+c+';font-weight:600;">('+s+formatted+pct+')</span>';
                        }

                        var priorRangeLabel = '';
                        if (prior.length) {
                            priorRangeLabel = ' &middot; vs prior ' + prior[0].date_collected + ' &rarr; ' + prior[prior.length - 1].date_collected;
                        }

                        $('#seom-trend-summary').html(
                            '<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:12px;padding:12px 16px;background:#f8fafc;border-radius:8px;font-size:13px;color:#475569;">'
                            + '<div>Impressions: <strong>' + impNow.toLocaleString() + '</strong> ' + fmtDelta(impNow, impPrev, false) + '</div>'
                            + '<div>Clicks: <strong>' + clkNow.toLocaleString() + '</strong> ' + fmtDelta(clkNow, clkPrev, false) + '</div>'
                            + '<div style="color:#94a3b8;">' + first.date_collected + ' &rarr; ' + last.date_collected + priorRangeLabel + '</div>'
                            + '</div>'
                        );

                        // ── Sync the three "metric" summary cards (Clicks, Impressions, Avg Position)
                        // with the same period totals + prior-period delta. This keeps the cards
                        // and the chart in sync, both driven by the top date range dropdown.
                        var totalImpForPosition = data.reduce(function(s, r) { return s + pickImpressions(r); }, 0);
                        var posWeightedSum = data.reduce(function(s, r) {
                            var p = parseFloat(r.avg_position) || 0;
                            var i = pickImpressions(r);
                            return s + (p > 0 && i > 0 ? p * i : 0);
                        }, 0);
                        var posWeightDenom = data.reduce(function(s, r) {
                            var p = parseFloat(r.avg_position) || 0;
                            var i = pickImpressions(r);
                            return s + (p > 0 && i > 0 ? i : 0);
                        }, 0);
                        var posNow = posWeightDenom > 0 ? (posWeightedSum / posWeightDenom) : 0;

                        var priorPosWeightedSum = prior.reduce(function(s, r) {
                            var p = parseFloat(r.avg_position) || 0;
                            var i = pickImpressions(r);
                            return s + (p > 0 && i > 0 ? p * i : 0);
                        }, 0);
                        var priorPosWeightDenom = prior.reduce(function(s, r) {
                            var p = parseFloat(r.avg_position) || 0;
                            var i = pickImpressions(r);
                            return s + (p > 0 && i > 0 ? i : 0);
                        }, 0);
                        var posPrev = priorPosWeightDenom > 0 ? (priorPosWeightedSum / priorPosWeightDenom) : 0;

                        function cardDelta(now, before, invert, decimals) {
                            if (!before) return ' <span style="font-size:11px;color:#94a3b8;">(—)</span>';
                            var d = now - before;
                            var good = invert ? (d < 0) : (d > 0);
                            var c = d === 0 ? '#94a3b8' : (good ? '#059669' : '#dc2626');
                            var arrow = d === 0 ? '' : (d > 0 ? '&#9650; ' : '&#9660; ');
                            var absD = Math.abs(d);
                            var formatted = decimals ? absD.toFixed(decimals) : Math.round(absD).toLocaleString();
                            var pct = before ? ' (' + (d > 0 ? '+' : (d < 0 ? '-' : '')) + Math.abs(Math.round((d / before) * 100)) + '%)' : '';
                            return ' <span style="font-size:11px;color:'+c+';font-weight:600;">' + arrow + formatted + pct + '</span>';
                        }

                        // Always update these three cards with the period totals + prior delta —
                        // loadTrends queries are filter-aware (current type/filter passed to backend),
                        // so the values match whatever filter the user has active.
                        $('#si-clicks').html(clkNow.toLocaleString() + cardDelta(clkNow, clkPrev, false, 0));
                        $('#si-impressions').html(impNow.toLocaleString() + cardDelta(impNow, impPrev, false, 0));

                        // NOTE: avg_position card is intentionally NOT updated here.
                        //
                        // loadIndexed() owns the avg_position card because it has access to
                        // the per-page snapshot in seom_page_metrics, which lets the backend
                        // compute a true row-level impression-weighted average that matches
                        // what GSC reports.
                        //
                        // This JS path could only compute it from chart bucket aggregates
                        // (one number per day, week, month, etc.) — averaging those gives a
                        // drifted value because GSC's per-day position averages are themselves
                        // aggregated and weighting them by day-total impressions doesn't
                        // produce the same answer as per-row weighting across the whole window.
                        //
                        // Leave the avg_position card to whatever loadIndexed() set.
                    }

                    $('#seom-trend-container').show();
                }).fail(function() {
                    // Network/server error — release the loading counter so cards aren't stuck dimmed
                    $('#seom-trend-chart').css('opacity', '1');
                    summaryLoadingEnd();
                });
            }

            // Date range dropdown drives BOTH the indexed pages query and the trend chart
            $('#seom-date-range').off('change').on('change', function() {
                if ($(this).val() === 'custom') {
                    // Reveal the custom inputs and wait for Apply — don't requery yet.
                    // The available bounds were set on the most recent loadIndexed.
                    $('#seom-idx-custom').css('display', 'inline-flex');
                    return;
                }
                $('#seom-idx-custom').hide();
                currentDateFrom = null;
                currentDateTo = null;
                loadIndexed(1);
                loadTrends();
            });

            // Custom date Apply button — validate, store, and refire both queries.
            $('#seom-idx-apply').on('click', function() {
                var f = $('#seom-idx-from').val();
                var t = $('#seom-idx-to').val();
                if (!f || !t) { alert('Pick both a start and end date.'); return; }
                if (f > t) { var tmp = f; f = t; t = tmp; }
                currentDateFrom = f;
                currentDateTo = t;
                loadIndexed(1);
                loadTrends();
            });

            // Group-by toggle for the trend chart
            $('.seom-trend-group').click(function() {
                $('.seom-trend-group').removeClass('active');
                $(this).addClass('active');
                trendGroup = $(this).data('group');
                var titleMap = { daily: 'Daily Trends', weekly: 'Weekly Trends', monthly: 'Monthly Trends', quarterly: 'Quarterly Trends' };
                $('#seom-trend-title').html((titleMap[trendGroup] || 'Trends') + ' <span style="font-size:11px; color:#94a3b8; font-weight:normal;">(set range above)</span>');
                loadTrends();
            });

            // Auto-load
            loadIndexed(1);
            loadTrends();
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
            <button type="button" class="button seom-tracker-days" data-days="7">7 Days</button>
            <button type="button" class="button seom-tracker-days active" data-days="14">14 Days</button>
            <button type="button" class="button seom-tracker-days" data-days="30">30 Days</button>
            <button type="button" class="button seom-tracker-days" data-days="60">60 Days</button>
            <button type="button" class="button seom-tracker-days" data-days="90">90 Days</button>
            <button type="button" class="button seom-tracker-custom-toggle">Custom...</button>
            <span id="seom-tracker-custom" style="display:none; align-items:center; gap:6px; flex-wrap:wrap;">
                <input type="date" id="seom-tracker-from" style="padding:3px 6px; border:1px solid #c3c4c7; border-radius:4px; font-size:13px;">
                <span style="color:#94a3b8;">→</span>
                <input type="date" id="seom-tracker-to" style="padding:3px 6px; border:1px solid #c3c4c7; border-radius:4px; font-size:13px;">
                <button type="button" class="button button-primary" id="seom-tracker-apply">Apply</button>
            </span>
            <span id="seom-tracker-available" style="font-size:11px; color:#64748b; margin-left:4px;"></span>
        </div>
        <div style="margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <strong>Trend:</strong>
            <button type="button" class="button seom-tracker-trend active" data-trend="all">All</button>
            <button type="button" class="button seom-tracker-trend" data-trend="strong" style="color:#059669;">Strong</button>
            <button type="button" class="button seom-tracker-trend" data-trend="clicks_up" style="color:#059669;">Clicks Up</button>
            <button type="button" class="button seom-tracker-trend" data-trend="impressions_up" style="color:#059669;">Impressions Up</button>
            <button type="button" class="button seom-tracker-trend" data-trend="ranking_up" style="color:#059669;">Ranking Up</button>
            <button type="button" class="button seom-tracker-trend" data-trend="declining" style="color:#dc2626;">Declining</button>
            <button type="button" class="button seom-tracker-trend" data-trend="clicks_down" style="color:#dc2626;">Clicks Down</button>
            <button type="button" class="button seom-tracker-trend" data-trend="impressions_down" style="color:#dc2626;">Impressions Down</button>
            <button type="button" class="button seom-tracker-trend" data-trend="ranking_down" style="color:#dc2626;">Ranking Down</button>
            <button type="button" class="button seom-tracker-trend" data-trend="too_early" style="color:#d97706;">Too Early</button>
            <button type="button" class="button seom-tracker-trend" data-trend="flat">Flat</button>
        </div>
        <div id="seom-tracker-trend-desc" style="font-size:12px; color:#64748b; margin:-4px 0 12px; padding-left:2px;"></div>

        <!-- Trend Charts -->
        <div id="seom-tracker-trend-container" style="display:none; margin-bottom:16px;">
            <h4 style="margin:0 0 12px; font-size:14px; color:#334155;">Refresh Performance</h4>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div style="padding:16px; background:#fff; border:1px solid #e2e8f0; border-radius:10px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin:0 0 8px; gap:8px; flex-wrap:wrap;">
                        <h5 style="margin:0; font-size:13px; color:#64748b;">Refreshed Pages Performance</h5>
                        <div style="display:flex; gap:4px;">
                            <button type="button" class="button button-small seom-tracker-group active" data-group="daily">Daily</button>
                            <button type="button" class="button button-small seom-tracker-group" data-group="weekly">Weekly</button>
                            <button type="button" class="button button-small seom-tracker-group" data-group="monthly">Monthly</button>
                            <button type="button" class="button button-small seom-tracker-group" data-group="quarterly">Quarterly</button>
                        </div>
                    </div>
                    <div style="position:relative; height:280px;">
                        <canvas id="seom-tracker-trend-chart"></canvas>
                    </div>
                    <div id="seom-tracker-perf-summary"></div>
                </div>
                <div style="padding:16px; background:#fff; border:1px solid #e2e8f0; border-radius:10px;">
                    <h5 style="margin:0 0 8px; font-size:13px; color:#64748b;">Refresh Activity</h5>
                    <div style="position:relative; height:280px;">
                        <canvas id="seom-tracker-activity-chart"></canvas>
                    </div>
                </div>
            </div>
            <div id="seom-tracker-refresh-summary"></div>
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
                        <th class="seom-trk-sort" data-sort="post_title" style="cursor:pointer;">Page</th>
                        <th class="seom-trk-sort" data-sort="category" style="cursor:pointer;">Was</th>
                        <th>Now</th>
                        <th class="seom-trk-sort" data-sort="refresh_type" style="cursor:pointer;">Type</th>
                        <th class="seom-trk-sort" data-sort="refresh_date" style="cursor:pointer;">Refreshed</th>
                        <th class="seom-trk-sort" data-sort="days_since" style="cursor:pointer;">Days</th>
                        <th class="seom-trk-sort" data-sort="clicks_before" style="cursor:pointer;">Clicks Before</th>
                        <th class="seom-trk-sort" data-sort="clicks_now" style="cursor:pointer;">Clicks Now</th>
                        <th class="seom-trk-sort" data-sort="click_change" style="cursor:pointer;">Click &Delta;</th>
                        <th class="seom-trk-sort" data-sort="impressions_before" style="cursor:pointer;">Impr Before</th>
                        <th class="seom-trk-sort" data-sort="impressions_now" style="cursor:pointer;">Impr Now</th>
                        <th class="seom-trk-sort" data-sort="impressions_change" style="cursor:pointer;">Impr &Delta;</th>
                        <th class="seom-trk-sort" data-sort="position_before" style="cursor:pointer;">Pos Before</th>
                        <th class="seom-trk-sort" data-sort="position_now" style="cursor:pointer;">Pos Now</th>
                        <th class="seom-trk-sort" data-sort="position_change" style="cursor:pointer;">Pos &Delta;</th>
                        <th class="seom-trk-sort" data-sort="trend" style="cursor:pointer;">Trend</th>
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
            var trackerCustom = false;
            var trackerFrom = null;
            var trackerTo = null;
            var trackerGroup = 'daily';
            var trackerSort = 'refresh_date';
            var trackerSortDir = 'desc';

            function getTrend(clickDiff, posDiff, daysSince, posBefore, posNow, impDiff) {
                posBefore = parseFloat(posBefore || 0);
                posNow    = parseFloat(posNow    || 0);
                impDiff   = parseFloat(impDiff   || 0);

                // Position improvement / decline only count when BOTH the prior
                // and current values are real (> 0). Same rules as the PHP
                // helper seom_classify_refresh_trend() — must stay in sync.
                var bothRanked    = posBefore > 0 && posNow > 0;
                var isPosImproved = bothRanked && (posNow < posBefore - 0.3);
                var isPosDeclined = bothRanked && (posNow > posBefore + 0.3);
                var newlyRanked   = posBefore <= 0 && posNow > 0;
                var lostRanking   = posBefore > 0 && posNow <= 0;

                // Strong = ALL THREE up. Declining = ALL THREE down. Hard buckets first.
                if (clickDiff > 0 && impDiff > 0 && isPosImproved) return { key: 'strong', html: '<span class="seom-change-positive" style="font-weight:700;">&#9650;&#9650; Strong</span>' };
                if (clickDiff < 0 && impDiff < 0 && (isPosDeclined || lostRanking)) return { key: 'declining', html: '<span class="seom-change-negative" style="font-weight:700;">&#9660;&#9660; Declining</span>' };

                // Single-axis movements: clicks first (highest signal), then impressions, then position.
                if (clickDiff > 0) return { key: 'clicks_up', html: '<span class="seom-change-positive">&#9650; Clicks Up</span>' };
                if (clickDiff < 0) return { key: 'clicks_down', html: '<span class="seom-change-negative">&#9660; Clicks Down</span>' };
                if (impDiff > 0) return { key: 'impressions_up', html: '<span class="seom-change-positive">&#9650; Impressions Up</span>' };
                if (impDiff < 0) return { key: 'impressions_down', html: '<span class="seom-change-negative">&#9660; Impressions Down</span>' };
                if (isPosImproved || newlyRanked) return { key: 'ranking_up', html: '<span class="seom-change-positive">&#9650; Ranking Up</span>' };
                if (isPosDeclined || lostRanking) return { key: 'ranking_down', html: '<span class="seom-change-negative">&#9660; Ranking Down</span>' };

                if (daysSince < 5) return { key: 'too_early', html: '<span style="color:#d97706;">&#9203; Too early</span>' };
                return { key: 'flat', html: '<span style="color:#94a3b8;">&#9654; Flat</span>' };
            }

            var trackerCache = null; // Cache all rows for client-side trend filtering

            function loadTracker(page) {
                trackerPage = page || 1;
                $('#seom-tracker-loading').show().text('Loading...');
                $('#seom-tracker-content').hide();
                $('#seom-tracker-pagination').empty();

                // Cache key includes the trend filter — server now filters
                // by trend, so each trend selection is its own row set.
                var cacheKey = trackerType + '|' + trackerDays + '|' + trackerTrend + '|' + (trackerCustom ? (trackerFrom + ':' + trackerTo) : '');
                if (trackerCache && trackerCache.key === cacheKey) {
                    renderTracker(trackerCache.data, trackerPage);
                    return;
                }

                var trkPayload = {
                    action: 'seom_get_tracker', nonce: seom_nonce,
                    page: 1, per_page: 9999, days: trackerDays, post_type: trackerType,
                    trend: trackerTrend
                };
                if (trackerCustom && trackerFrom && trackerTo) {
                    trkPayload.date_from = trackerFrom;
                    trkPayload.date_to   = trackerTo;
                }

                $.post(ajaxurl, trkPayload, function(resp) {
                    if (!resp.success) { $('#seom-tracker-loading').hide(); return; }
                    trackerCache = { key: cacheKey, data: resp.data };

                    // Populate the custom date inputs' min/max + a hint for the user.
                    if (resp.data.available && resp.data.available.date_from && resp.data.available.date_to) {
                        var av = resp.data.available;
                        $('#seom-tracker-from').attr({min: av.date_from, max: av.date_to});
                        $('#seom-tracker-to').attr({min: av.date_from, max: av.date_to});
                        var spanDays = Math.floor((new Date(av.date_to) - new Date(av.date_from)) / 86400000) + 1;
                        $('#seom-tracker-available').text('Available: ' + av.date_from + ' → ' + av.date_to + ' (' + spanDays + ' days, ' + av.total + ' refreshes)');
                        if (!$('#seom-tracker-from').val()) $('#seom-tracker-from').val(av.date_from);
                        if (!$('#seom-tracker-to').val())   $('#seom-tracker-to').val(av.date_to);
                    }

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
                $('#st-trend').html('<span class="' + trendClass + '">' + trendIcon + trendText.toLocaleString() + '</span>');
                $('#st-trend-detail').text(before.toLocaleString() + ' prior → ' + now.toLocaleString() + ' now (' + (pct > 0 ? '+' : '') + pct + '%)');

                var impBefore = d.summary.total_impressions_before || 0;
                var impNow = d.summary.total_impressions_now || 0;
                var impDiff = impNow - impBefore;
                var impPct = impBefore > 0 ? Math.round((impDiff / impBefore) * 100) : 0;
                var impTrendText = (impDiff > 0 ? '+' : '') + impDiff.toLocaleString();
                var impTrendClass = impDiff > 0 ? 'seom-change-positive' : (impDiff < 0 ? 'seom-change-negative' : '');
                var impTrendIcon = impDiff > 0 ? '&#9650; ' : (impDiff < 0 ? '&#9660; ' : '');
                $('#st-imp-trend').html('<span class="' + impTrendClass + '">' + impTrendIcon + impTrendText + '</span>');
                $('#st-imp-trend-detail').text(impBefore.toLocaleString() + ' prior → ' + impNow.toLocaleString() + ' now (' + (impPct > 0 ? '+' : '') + impPct + '%)');

                // Tooltip-friendly window detail showing the exact dates compared.
                if (d.summary.window_current_start) {
                    var windowLabel = 'Refreshed pages (' + (d.summary.distinct_refreshed_pages || 0) + ') · '
                        + d.summary.window_current_start + ' → ' + d.summary.window_current_end
                        + ' vs ' + d.summary.window_prior_start + ' → ' + d.summary.window_prior_end;
                    $('#st-trend').closest('.seom-card').attr('title', 'Clicks ' + windowLabel);
                    $('#st-imp-trend').closest('.seom-card').attr('title', 'Impressions ' + windowLabel);
                }

                $('#st-flat').text(d.summary.flat);

                // Server already filtered by trend — just attach the display
                // metadata (badge HTML, derived sort fields) and use d.rows
                // directly. trend_key comes from the backend classifier so the
                // table label always matches what the cards counted.
                var filtered = [];
                d.rows.forEach(function(r) {
                    var clickDiff = parseInt(r.click_change || 0);
                    var posDiff = parseFloat(r.position_change || 0);
                    var impDiff = (parseInt(r.impressions_now || 0) - parseInt(r.impressions_before || 0));
                    r._impressions_change = impDiff;
                    r._trend = getTrend(clickDiff, posDiff, parseInt(r.days_since), r.position_before, r.position_now, impDiff);
                    filtered.push(r);
                });

                // ── Client-side sorting ──
                // Numeric columns sort by their numeric value; everything else
                // falls back to a case-insensitive string compare. Trend sorts
                // by the classified trend.key so users can group like rows.
                var numericCols = {
                    days_since:1, clicks_before:1, clicks_now:1, click_change:1,
                    impressions_before:1, impressions_now:1, impressions_change:1,
                    position_before:1, position_now:1, position_change:1
                };
                var sortKey = trackerSort;
                var sortDir = trackerSortDir === 'asc' ? 1 : -1;
                filtered.sort(function(a, b) {
                    var av, bv;
                    if (sortKey === 'trend')      { av = a._trend.key; bv = b._trend.key; }
                    else if (sortKey === 'impressions_change') { av = a._impressions_change; bv = b._impressions_change; }
                    else if (sortKey === 'clicks_now')         { av = parseInt(a.clicks_now || 0); bv = parseInt(b.clicks_now || 0); }
                    else if (sortKey === 'impressions_now')    { av = parseInt(a.impressions_now || 0); bv = parseInt(b.impressions_now || 0); }
                    else if (sortKey === 'position_now')       { av = parseFloat(a.position_now || 0); bv = parseFloat(b.position_now || 0); }
                    else if (numericCols[sortKey])             { av = parseFloat(a[sortKey] || 0); bv = parseFloat(b[sortKey] || 0); }
                    else                                       { av = (a[sortKey] || '').toString().toLowerCase(); bv = (b[sortKey] || '').toString().toLowerCase(); }
                    if (av < bv) return -1 * sortDir;
                    if (av > bv) return  1 * sortDir;
                    return 0;
                });

                // Sort indicators
                $('.seom-trk-sort').removeClass('sorted-asc sorted-desc');
                $('.seom-trk-sort[data-sort="' + sortKey + '"]').addClass(sortDir > 0 ? 'sorted-asc' : 'sorted-desc');

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
                var statusTxt = '<span style="margin-left:12px;color:#64748b;font-size:13px;">Page ' + page + ' of ' + totalPages + ' &middot; ' + totalFiltered + ' of ' + d.rows.length + ' refreshes</span>';
                $('#seom-tracker-pagination').html(window.seomBuildPagination(page, totalPages, 'seom-tracker-page', statusTxt));
            }

            // Period toggle — reload both table and charts
            $('.seom-tracker-type').click(function() {
                $('.seom-tracker-type').removeClass('active');
                $(this).addClass('active');
                trackerType = $(this).data('type');
                trackerCache = null;
                loadTracker(1);
                loadTrackerTrends();
            });

            $('.seom-tracker-days').click(function() {
                $('.seom-tracker-days').removeClass('active');
                $(this).addClass('active');
                $('.seom-tracker-custom-toggle').removeClass('button-primary');
                $('#seom-tracker-custom').hide();
                trackerDays = parseInt($(this).data('days'));
                trackerCustom = false;
                trackerFrom = null;
                trackerTo = null;
                trackerCache = null;
                // Reset chart grouping to Daily on period change. Carrying
                // Monthly/Quarterly across to a shorter window collapses both
                // current and prior series into one or two buckets, which
                // makes the prior overlay invisible (single-point lines).
                trackerGroup = 'daily';
                $('.seom-tracker-group').removeClass('active');
                $('.seom-tracker-group[data-group="daily"]').addClass('active');
                loadTracker(1);
                loadTrackerTrends();
            });

            $('.seom-tracker-custom-toggle').click(function() {
                // Toggle the custom date strip; data still uses the last known
                // predefined range until the user clicks Apply.
                $('.seom-tracker-days').removeClass('active');
                $(this).addClass('button-primary');
                $('#seom-tracker-custom').css('display', 'inline-flex');
            });

            $('#seom-tracker-apply').on('click', function() {
                var f = $('#seom-tracker-from').val();
                var t = $('#seom-tracker-to').val();
                if (!f || !t) { alert('Pick both a start and end date.'); return; }
                if (f > t) { var tmp = f; f = t; t = tmp; }
                trackerFrom = f;
                trackerTo = t;
                trackerCustom = true;
                trackerCache = null;
                loadTracker(1);
                loadTrackerTrends();
            });

            // Trend filter descriptions — shown beneath the buttons so users
            // know exactly what each bucket means and why their counts may
            // be small. Hard buckets (Strong/Declining) require all three
            // axes to move in the same direction.
            var trendDescriptions = {
                all: 'Showing every refreshed page in the period — no trend filter applied.',
                strong: 'All three metrics improved: clicks UP, impressions UP, and ranking position improved. The hardest bucket to land in — these are unambiguous wins.',
                clicks_up: 'Clicks increased in the current window vs the prior window (not also a Strong move).',
                impressions_up: 'Impressions increased but clicks stayed flat. Google is showing the page more — likely needs a meta/title tweak to convert visibility into clicks.',
                ranking_up: 'Ranking position improved by more than 0.3 (or the page started ranking), with clicks and impressions flat.',
                declining: 'All three metrics worsened: clicks DOWN, impressions DOWN, and ranking position dropped. The hardest loss bucket — these need investigation first.',
                clicks_down: 'Clicks decreased in the current window vs the prior window (not also a Declining move).',
                impressions_down: 'Impressions decreased but clicks stayed flat. Google is showing the page less — usually a sign of dropped queries or losing relevance for some keywords.',
                ranking_down: 'Ranking position dropped by more than 0.3 (or the page lost ranking entirely), with clicks and impressions flat.',
                too_early: 'Refreshed within the last 5 days — not enough time to evaluate impact yet. Wait for Google to crawl and rerank.',
                flat: 'No meaningful change in clicks, impressions, or position since the refresh. Either too early to tell or the refresh had no effect.'
            };
            function updateTrendDesc() {
                $('#seom-tracker-trend-desc').text(trendDescriptions[trackerTrend] || '');
            }
            updateTrendDesc();

            $('.seom-tracker-trend').click(function() {
                $('.seom-tracker-trend').removeClass('active');
                $(this).addClass('active');
                trackerTrend = $(this).data('trend');
                updateTrendDesc();
                loadTracker(1);
                loadTrackerTrends();
            });

            // Group-by toggle for the Refreshed Pages Performance chart
            $(document).on('click', '.seom-tracker-group', function() {
                $('.seom-tracker-group').removeClass('active');
                $(this).addClass('active');
                trackerGroup = $(this).data('group');
                loadTrackerTrends();
            });

            // Column sort — table is fully cached client-side, so no refetch needed.
            // Click toggles asc/desc on the same column; clicking a different column
            // resets to a sensible default (numeric → desc, text/date → asc).
            $(document).on('click', '.seom-trk-sort', function() {
                var col = $(this).data('sort');
                if (trackerSort === col) {
                    trackerSortDir = (trackerSortDir === 'asc') ? 'desc' : 'asc';
                } else {
                    trackerSort = col;
                    var textCols = {post_title:1, refresh_type:1, category:1, trend:1};
                    trackerSortDir = textCols[col] ? 'asc' : 'desc';
                }
                // Re-render from cache without refetching
                if (trackerCache && trackerCache.data) renderTracker(trackerCache.data, 1);
            });

            // Pagination
            $(document).on('click', '.seom-tracker-page', function() {
                loadTracker(parseInt($(this).data('page')));
            });

            // ── Refresh Performance Trend Chart ──
            var trackerTrendChart = null;
            var trackerActivityChart = null;

            function loadTrackerTrends() {
                // Show loading overlay on charts
                $('#seom-tracker-trend-chart, #seom-tracker-activity-chart').css('opacity', '0.3');
                $('#seom-tracker-refresh-summary').html('<div style="text-align:center;padding:12px;color:#94a3b8;"><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>Loading...</div>');
                var rtPayload = {
                    action: 'seom_get_refresh_trends', nonce: seom_nonce,
                    days: trackerDays, post_type: trackerType, trend: trackerTrend,
                    group: trackerGroup
                };
                if (trackerCustom && trackerFrom && trackerTo) {
                    rtPayload.date_from = trackerFrom;
                    rtPayload.date_to   = trackerTo;
                }
                $.post(ajaxurl, rtPayload, function(resp) {
                    $('#seom-tracker-trend-chart, #seom-tracker-activity-chart').css('opacity', '1');
                    if (!resp.success) return;
                    var d = resp.data;
                    // perf is now { current: [...], prior: [...] } — backwards-compat
                    // tolerated below if an older shape ever sneaks in.
                    var perfBlock = d.perf || {};
                    if (Array.isArray(perfBlock)) perfBlock = { current: perfBlock, prior: [] };
                    var perfCur = perfBlock.current || [];
                    var perfPri = perfBlock.prior || [];
                    var daily = d.daily || [];
                    var s = d.summary || {};

                    // === LEFT CHART: Performance trend ===
                    // Mirrors the Indexed Pages chart: clicks + impressions only,
                    // current period as solid lines, prior period as dashed overlay
                    // aligned by index (point i = same position in the prior window).
                    if (perfCur.length) {
                        var perfLabels = perfCur.map(function(r) { return r.day; });
                        var clicks      = perfCur.map(function(r) { return parseInt(r.total_clicks) || 0; });
                        var impressions = perfCur.map(function(r) { return parseInt(r.total_impressions) || 0; });
                        var priorClicks      = perfCur.map(function(_, i) { return perfPri[i] ? (parseInt(perfPri[i].total_clicks) || 0) : null; });
                        var priorImpressions = perfCur.map(function(_, i) { return perfPri[i] ? (parseInt(perfPri[i].total_impressions) || 0) : null; });
                        var priorDates       = perfCur.map(function(_, i) { return perfPri[i] ? perfPri[i].day : null; });

                        var ctx1 = document.getElementById('seom-tracker-trend-chart').getContext('2d');
                        if (trackerTrendChart) trackerTrendChart.destroy();
                        trackerTrendChart = new Chart(ctx1, {
                            type: 'line',
                            data: { labels: perfLabels, datasets: [
                                { label: 'Impressions',         data: impressions,      borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.05)', borderWidth: 2,   fill: true,  tension: 0.3, yAxisID: 'y',       pointRadius: perfCur.length > 60 ? 0 : 3 },
                                { label: 'Impressions (prior)', data: priorImpressions, borderColor: '#93c5fd',                                              borderWidth: 1.5, fill: false, tension: 0.3, yAxisID: 'y',       pointRadius: perfCur.length > 60 ? 0 : 3, borderDash: [5,4], spanGaps: true },
                                { label: 'Clicks',              data: clicks,           borderColor: '#059669',                                              borderWidth: 2,   fill: false, tension: 0.3, yAxisID: 'yClicks', pointRadius: perfCur.length > 60 ? 0 : 3 },
                                { label: 'Clicks (prior)',      data: priorClicks,      borderColor: '#86efac',                                              borderWidth: 1.5, fill: false, tension: 0.3, yAxisID: 'yClicks', pointRadius: perfCur.length > 60 ? 0 : 3, borderDash: [5,4], spanGaps: true },
                            ]},
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false },
                                plugins: {
                                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, font: { size: 11 } } },
                                    tooltip: {
                                        callbacks: {
                                            label: function(c) { var v = c.raw == null ? '—' : c.raw.toLocaleString(); return c.dataset.label + ': ' + v; },
                                            afterBody: function(items) {
                                                if (!items.length) return '';
                                                var pd = priorDates[items[0].dataIndex];
                                                return pd ? 'Prior period date: ' + pd : '';
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    x:       { grid: { display: false }, ticks: { font: { size: 9 }, maxTicksLimit: 10 } },
                                    y:       { type: 'linear', position: 'left',  title: { display: true, text: 'Impressions', font: { size: 10 }, color: '#3b82f6' }, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 9 }, color: '#3b82f6' }, beginAtZero: true },
                                    yClicks: { type: 'linear', position: 'right', title: { display: true, text: 'Clicks',      font: { size: 10 }, color: '#059669' }, grid: { display: false },             ticks: { font: { size: 9 }, color: '#059669' }, beginAtZero: true },
                                }
                            }
                        });

                        // Period totals + prior delta — matches the Indexed Pages summary bar.
                        var sumImp = function(arr) { return arr.reduce(function(t, r) { return t + (parseInt(r.total_impressions) || 0); }, 0); };
                        var sumClk = function(arr) { return arr.reduce(function(t, r) { return t + (parseInt(r.total_clicks) || 0); }, 0); };
                        var impNow = sumImp(perfCur), impPrev = sumImp(perfPri);
                        var clkNow = sumClk(perfCur), clkPrev = sumClk(perfPri);
                        function fmtDelta(now, before) {
                            if (!before) return '<span style="color:#94a3b8;font-weight:600;">(—)</span>';
                            var diff = now - before;
                            var c = diff === 0 ? '#94a3b8' : (diff > 0 ? '#059669' : '#dc2626');
                            var sgn = diff > 0 ? '+' : '';
                            var pct = ' (' + (diff > 0 ? '+' : '') + Math.round((diff / before) * 100) + '%)';
                            return '<span style="color:'+c+';font-weight:600;">('+sgn+diff.toLocaleString()+pct+')</span>';
                        }
                        var first = perfCur[0], last = perfCur[perfCur.length - 1];
                        var priorRangeLabel = perfPri.length ? ' &middot; vs prior ' + perfPri[0].day + ' &rarr; ' + perfPri[perfPri.length - 1].day : '';
                        $('#seom-tracker-perf-summary').html(
                            '<div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px;padding:10px 12px;background:#f8fafc;border-radius:6px;font-size:12px;color:#475569;">'
                            + '<div>Impressions: <strong>' + impNow.toLocaleString() + '</strong> ' + fmtDelta(impNow, impPrev) + '</div>'
                            + '<div>Clicks: <strong>' + clkNow.toLocaleString() + '</strong> ' + fmtDelta(clkNow, clkPrev) + '</div>'
                            + '<div style="color:#94a3b8;">' + first.day + ' &rarr; ' + last.day + priorRangeLabel + '</div>'
                            + '</div>'
                        );
                    } else {
                        // No matching pages — destroy any stale chart and show
                        // a placeholder so the canvas doesn't keep an old render.
                        if (trackerTrendChart) { trackerTrendChart.destroy(); trackerTrendChart = null; }
                        var ctxEmpty1 = document.getElementById('seom-tracker-trend-chart').getContext('2d');
                        ctxEmpty1.clearRect(0, 0, ctxEmpty1.canvas.width, ctxEmpty1.canvas.height);
                        ctxEmpty1.font = '13px sans-serif';
                        ctxEmpty1.fillStyle = '#94a3b8';
                        ctxEmpty1.textAlign = 'center';
                        ctxEmpty1.fillText('No data for this filter combination.', ctxEmpty1.canvas.width / 2, ctxEmpty1.canvas.height / 2);
                        $('#seom-tracker-perf-summary').empty();
                    }

                    // === RIGHT CHART: Daily activity ===
                    if (daily.length) {
                        var actLabels = daily.map(function(r) { return r.day; });
                        var fullR = daily.map(function(r) { return parseInt(r.full_refreshes) || 0; });
                        var metaR = daily.map(function(r) { return parseInt(r.meta_refreshes) || 0; });
                        var ctx2 = document.getElementById('seom-tracker-activity-chart').getContext('2d');
                        if (trackerActivityChart) trackerActivityChart.destroy();
                        trackerActivityChart = new Chart(ctx2, {
                            type: 'bar',
                            data: { labels: actLabels, datasets: [
                                { label: 'Full Refresh', data: fullR, backgroundColor: '#3b82f6', borderRadius: 3 },
                                { label: 'Meta Only', data: metaR, backgroundColor: '#d97706', borderRadius: 3 },
                            ]},
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, font: { size: 11 } } }, tooltip: { callbacks: { footer: function(items) { return 'Total: ' + items.reduce(function(a,i){return a+i.raw;},0); } } } },
                                scales: { x: { stacked: true, grid: { display: false }, ticks: { font: { size: 9 }, maxTicksLimit: 10 } }, y: { stacked: true, title: { display: true, text: 'Pages Refreshed', font: { size: 10 } }, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 9 }, stepSize: 1 } } }
                            }
                        });
                    } else {
                        if (trackerActivityChart) { trackerActivityChart.destroy(); trackerActivityChart = null; }
                        var ctxEmpty2 = document.getElementById('seom-tracker-activity-chart').getContext('2d');
                        ctxEmpty2.clearRect(0, 0, ctxEmpty2.canvas.width, ctxEmpty2.canvas.height);
                        ctxEmpty2.font = '13px sans-serif';
                        ctxEmpty2.fillStyle = '#94a3b8';
                        ctxEmpty2.textAlign = 'center';
                        ctxEmpty2.fillText('No refresh activity for this filter combination.', ctxEmpty2.canvas.width / 2, ctxEmpty2.canvas.height / 2);
                    }

                    // === SUMMARY BAR ===
                    if (s.pages_refreshed !== undefined && s.pages_refreshed > 0) {
                        var clickChange = s.clicks_now - s.clicks_before;
                        var impChange = s.impressions_now - s.impressions_before;
                        var posChange = parseFloat((s.avg_position_now - s.avg_position_before).toFixed(1));
                        function sd(v, inv) { var c = v === 0 ? '#94a3b8' : ((inv ? v < 0 : v > 0) ? '#059669' : '#dc2626'); return '<span style="color:'+c+';font-weight:600;">('+(v>0?'+':'')+v.toLocaleString()+')</span>'; }
                        var trendLabel = trackerTrend !== 'all' ? ' (' + trackerTrend.replace(/_/g,' ') + ')' : ' refreshed';
                        $('#seom-tracker-refresh-summary').html(
                            '<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:12px;padding:12px 16px;background:#f8fafc;border-radius:8px;font-size:13px;color:#475569;">'
                            + '<div><strong>' + s.pages_refreshed + '</strong> pages' + trendLabel + '</div>'
                            + '<div>Clicks: ' + s.clicks_before.toLocaleString() + ' &rarr; <strong>' + s.clicks_now.toLocaleString() + '</strong> ' + sd(clickChange, false) + '</div>'
                            + '<div>Impressions: ' + s.impressions_before.toLocaleString() + ' &rarr; <strong>' + s.impressions_now.toLocaleString() + '</strong> ' + sd(impChange, false) + '</div>'
                            + '<div>Position: ' + s.avg_position_before + ' &rarr; <strong>' + s.avg_position_now + '</strong> ' + sd(posChange, true) + '</div>'
                            + '<div>Improving: <span style="color:#059669;font-weight:700;">' + s.improving + '</span> &middot; Declining: <span style="color:#dc2626;font-weight:700;">' + s.declining + '</span> &middot; Flat: ' + s.flat + '</div>'
                            + '</div>'
                        );
                    } else {
                        $('#seom-tracker-refresh-summary').html(
                            '<div style="margin-top:12px;padding:12px 16px;background:#f8fafc;border-radius:8px;font-size:13px;color:#94a3b8;text-align:center;">'
                            + 'No pages match this filter combination.'
                            + '</div>'
                        );
                    }

                    $('#seom-tracker-trend-container').show();
                });
            }

            loadTracker(1);
            loadTrackerTrends();
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
            <button type="button" class="button button-primary" id="seom-start-queue">Process Queue (Background)</button>
            <button type="button" class="button" id="seom-stop-queue-bg" style="color:#dc2626; display:none;">Stop Processing</button>
            <span style="margin:0 4px; color:#e2e8f0;">|</span>
            <button type="button" class="button" id="seom-clear-queue" style="color:#dc2626;">Clear Entire Queue</button>
            <button type="button" class="button" id="seom-reset-queue" title="Reset stuck processing state and unlock the queue">Reset State</button>
            <span id="seom-bulk-status" style="font-size:12px; margin-left:8px;"></span>
            <div id="seom-queue-progress" style="display:none; margin-top:10px; padding:12px 16px; background:#fff; border:1px solid #e2e8f0; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; font-size:13px;"></div>
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
                    <th style="width:85px;">Last Refreshed</th>
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
                        '<td>' + ctr.toFixed(2) + '%</td>' +
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
                $.ajax({ url: ajaxurl, method: 'POST', timeout: 30000, data: { action: 'seom_get_queue', nonce: seom_nonce },
                    success: function(resp) {
                        $('#seom-queue-loading').hide();
                        if (!resp.success || !resp.data || !resp.data.length) {
                            $('#seom-queue-empty').show();
                            $('#seom-queue-table').hide();
                            // Empty queue — hide the action buttons that don't apply
                            $('#seom-start-queue, #seom-clear-queue, #seom-reset-queue, #seom-bulk-action, #seom-bulk-apply').hide();
                            // Hide the separator pipes too
                            $('#seom-queue-bulk > span').hide();
                            return;
                        }
                        queueData = resp.data;
                        renderQueue();
                        $('#seom-queue-table').show();
                        $('#seom-queue-bulk').css('display', 'flex');
                        $('#seom-queue-empty').hide();
                        // Restore buttons in case they were hidden previously
                        $('#seom-start-queue, #seom-clear-queue, #seom-reset-queue, #seom-bulk-action, #seom-bulk-apply').show();
                        $('#seom-queue-bulk > span').show();
                    },
                    error: function() {
                        $('#seom-queue-loading').text('Failed to load queue. Try refreshing the page.');
                    }
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
            var seomStopRequested = false;
            var queuePollTimer = null;

            var queueRunning = false;
            var statusPollTimer = null;

            // Start background processing — triggers server-side cron chain
            $('#seom-start-queue').click(function() {
                if (queueRunning) return;
                if (!confirm('Start processing the queue in the background? Processing continues even if you leave this page.')) return;
                $(this).prop('disabled', true).text('Starting...');
                $.post(ajaxurl, { action: 'seom_start_queue_processing', nonce: seom_nonce }, function(resp) {
                    if (resp.success) {
                        startStatusPolling();
                    } else {
                        $('#seom-start-queue').prop('disabled', false).text('Process Queue (Background)');
                        alert(resp.data || 'Error starting queue.');
                    }
                });
            });

            // Stop background processing
            $('#seom-stop-queue-bg').click(function() {
                $(this).prop('disabled', true).text('Stopping...');
                $.post(ajaxurl, { action: 'seom_stop_queue', nonce: seom_nonce }, function() {
                    if (statusPollTimer) clearTimeout(statusPollTimer);
                    queueRunning = false;
                    $('.seom-process-btn, .seom-skip-btn').prop('disabled', false);
                    $('#seom-start-queue').prop('disabled', false).text('Process Queue (Background)');
                    $('#seom-stop-queue-bg').hide();
                    $('#seom-queue-progress').css('border-left-color', '#d97706').html('Processing stopped. Remaining items stay in queue.');
                    loadQueue();
                });
            });

            function startStatusPolling() {
                queueRunning = true;
                $('.seom-process-btn, .seom-skip-btn').prop('disabled', true);
                $('#seom-start-queue').prop('disabled', true).text('Processing...');
                $('#seom-stop-queue-bg').show().prop('disabled', false).text('Stop Processing');
                $('#seom-queue-progress').show();
                pollStatus();
            }

            var lastProcessingPostId = null;
            function pollStatus() {
                // Only ever one status poll pending at a time.
                if (statusPollTimer) { clearTimeout(statusPollTimer); statusPollTimer = null; }
                $.post(ajaxurl, { action: 'seom_queue_status', nonce: seom_nonce }, function(resp) {
                    if (!resp.success) return;
                    var s = resp.data;

                    var sourceLabel = s.source === 'cron' ? ' <span style="font-size:11px;color:#7c3aed;">(automated cron run)</span>' : '';
                    var recoveryLabel = (s.recovery_attempts && s.recovery_attempts > 0)
                        ? ' <span style="font-size:11px;color:#d97706;" title="The chain stalled and was auto-recovered ' + s.recovery_attempts + ' time(s) — system is self-healing.">(auto-recovered ' + s.recovery_attempts + 'x)</span>'
                        : '';
                    var html = '<strong>' + s.completed + ' completed</strong>';
                    if (s.failed > 0) html += ', <span style="color:#dc2626;">' + s.failed + ' failed</span>';
                    html += ', <span style="color:#64748b;">' + s.remaining + ' remaining</span>' + sourceLabel + recoveryLabel;

                    // Show what's currently being processed, including which step it's on
                    if (s.current && s.current.post_title) {
                        var stepInfo = '';
                        if (s.current_step && s.current_total) {
                            var stLabel = seomStepLabels[s.current_step] ? (': ' + seomStepLabels[s.current_step]) : '';
                            stepInfo = ' &mdash; <strong>Step ' + s.current_step + '/' + s.current_total + '</strong>' + stLabel;
                        }
                        html += '<br><span style="font-size:12px;color:#2563eb;"><span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span>Now: <strong>' + s.current.post_title + '</strong> (' + s.current.refresh_type + ')' + stepInfo + '</span>';
                    }

                    // ── Row-level visual: highlight the row currently being processed ──
                    var currentPostId = (s.current && s.current.post_id) ? parseInt(s.current.post_id, 10) : null;

                    // Clear any existing row badges/highlights
                    $('#seom-queue-table tr').css('background-color', '');
                    $('.seom-row-status').remove();

                    if (currentPostId) {
                        var $row = $('#seom-queue-table tr[data-post-id="' + currentPostId + '"]');
                        if ($row.length) {
                            $row.css('background-color', '#eff6ff'); // soft blue
                            // Inject a status indicator into the Actions cell
                            var $actions = $row.find('td').last();
                            $actions.prepend('<div class="seom-row-status" style="font-size:11px;color:#2563eb;font-weight:600;margin-bottom:4px;"><span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span>Processing now…</div>');
                        }
                    }

                    // If the currently-processing item changed since the last poll, the previous
                    // one is now completed and should disappear from the table — refresh the queue.
                    if (currentPostId !== lastProcessingPostId && lastProcessingPostId !== null) {
                        loadQueue();
                    }
                    lastProcessingPostId = currentPostId;

                    if (s.is_running && s.remaining > 0) {
                        html += '<br><span style="font-size:11px;color:#94a3b8;">Processing in the background — you can leave this page.</span>';
                        $('#seom-queue-progress').css('border-left-color', '#2563eb').html(html);
                        statusPollTimer = setTimeout(pollStatus, 5000);
                    } else if (s.stopped) {
                        // Stop reason → color
                        var stopColors = {
                            completed:   '#059669',
                            daily_limit: '#d97706',
                            stale:       '#dc2626',
                            error:       '#dc2626',
                            manual:      '#d97706'
                        };
                        var stopColor = stopColors[s.stop_reason] || '#d97706';
                        var stopMsg = s.stop_message || 'Processing stopped.';
                        html += '<br><span style="font-size:12px;color:' + stopColor + ';font-weight:600;">' + stopMsg + '</span>';
                        $('#seom-queue-progress').css('border-left-color', stopColor).html(html);
                        stopPolling();
                    } else {
                        html += '<br><span style="font-size:12px;color:#059669;">Queue processing complete.</span>';
                        $('#seom-queue-progress').css('border-left-color', '#059669').html(html);
                        stopPolling();
                    }
                });
            }

            function stopPolling() {
                queueRunning = false;
                if (statusPollTimer) clearTimeout(statusPollTimer);
                $('.seom-process-btn, .seom-skip-btn').prop('disabled', false);
                $('#seom-start-queue').prop('disabled', false).text('Process Queue (Background)');
                $('#seom-stop-queue-bg').hide();
                loadQueue();
            }

            // On page load, check if processing is active
            $.post(ajaxurl, { action: 'seom_queue_check', nonce: seom_nonce }, function(resp) {
                if (resp.success && resp.data.is_running) {
                    startStatusPolling();
                }
            });

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
                            // Tell the server to mark this queue row as failed so it doesn't
                            // sit in 'processing' forever and the user gets a failure email.
                            $.post(ajaxurl, {
                                action: 'seom_mark_step_failed',
                                nonce: seom_nonce,
                                post_id: postId,
                                step: 'step ' + currentStep + ' (' + label + ')',
                                reason: msg
                            });
                            callback(false);
                        }
                    });
                }
                runStep();
            }

            // Single-row "Process" button on a queue item.
            //
            // Routes through the same priority-100 queue-and-trigger flow used by
            // the admin bar AI Refresh and the bulk Process Now action. There is
            // intentionally NO separate in-browser step runner — every refresh
            // path goes through SEOM_Processor::execute_refresh so behavior is
            // identical regardless of how it was started.
            $(document).on('click', '.seom-process-btn', function() {
                var btn = $(this).prop('disabled', true).text('Queueing…');
                var postId = btn.data('post-id');
                var row = btn.closest('tr');
                row.css('opacity', '0.7');
                btn.after('<br><small class="seom-process-msg" style="color:#b45309;">Bumping priority and starting background worker…</small>');
                var msgEl = row.find('.seom-process-msg');

                $.post(ajaxurl, {
                    action: 'seom_queue_refresh_now',
                    nonce: seom_nonce,
                    post_id: postId,
                    type: 'full',
                }, function(resp) {
                    if (resp.success) {
                        btn.text('Queued');
                        msgEl.html('<span style="color:#16a34a;">&#10003; Queued for priority processing — you\'ll get an email when it completes.</span>');
                        // The row will disappear from the queue list once the worker finishes
                        // and the queue refresh runs. No need to do anything else here.
                    } else {
                        row.css('opacity', '1');
                        btn.prop('disabled', false).text('Retry');
                        msgEl.html('<span style="color:#dc2626;">&#10007; ' + (resp.data || 'Failed to queue') + '</span>');
                    }
                }).fail(function() {
                    row.css('opacity', '1');
                    btn.prop('disabled', false).text('Retry');
                    msgEl.html('<span style="color:#dc2626;">&#10007; Server error while queueing</span>');
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

                // Process Now — bumps every selected row to priority 100 in a
                // single AJAX call, then triggers the background queue processor
                // once. Same code path as the admin bar refresh and the single-row
                // Process button — there is intentionally only ONE refresh pipeline.
                if (action === 'process_now') {
                    if (!confirm('Queue ' + ids.length + ' item(s) for priority processing? They\'ll run in the background and you\'ll get an email when each one completes. You can close this tab.')) return;

                    var postIds = [];
                    $('.seom-queue-cb:checked').each(function() {
                        var row = $(this).closest('tr');
                        var postId = row.find('.seom-process-btn').data('post-id');
                        if (postId) postIds.push({ postId: postId, row: row });
                    });

                    var total = postIds.length;
                    $('#seom-bulk-status').html('<span style="color:#b45309;">Queueing ' + total + ' item(s)…</span>');
                    $('#seom-bulk-apply').prop('disabled', true);

                    // Visually mark every selected row as "queued" right away
                    postIds.forEach(function(item) {
                        item.row.css('opacity', '0.7');
                        var btn = item.row.find('.seom-process-btn').prop('disabled', true).text('Queueing…');
                        if (!item.row.find('.seom-process-msg').length) {
                            btn.after('<br><small class="seom-process-msg"></small>');
                        }
                    });

                    // Fire all the queue inserts in parallel — they're DB-only ops,
                    // each returns in <100ms. Then trigger the cron once when done.
                    var done = 0, queued = 0, failed = 0;
                    postIds.forEach(function(item) {
                        $.post(ajaxurl, {
                            action: 'seom_queue_refresh_now',
                            nonce: seom_nonce,
                            post_id: item.postId,
                            type: 'full',
                        }, function(resp) {
                            done++;
                            var msgEl = item.row.find('.seom-process-msg');
                            if (resp.success) {
                                queued++;
                                item.row.find('.seom-process-btn').text('Queued');
                                msgEl.html('<span style="color:#16a34a;">&#10003; Queued (priority)</span>');
                            } else {
                                failed++;
                                item.row.css('opacity', '1');
                                item.row.find('.seom-process-btn').prop('disabled', false).text('Retry');
                                msgEl.html('<span style="color:#dc2626;">&#10007; ' + (resp.data || 'Failed') + '</span>');
                            }
                            $('#seom-bulk-status').html('<span style="color:#b45309;">Queued ' + done + '/' + total + '…</span>');
                            if (done === total) {
                                $('#seom-bulk-apply').prop('disabled', false);
                                $('#seom-bulk-status').html('<span style="color:#059669;">' + queued + ' item(s) queued for priority processing. ' + (failed ? failed + ' failed. ' : '') + 'You\'ll get an email as each completes.</span>');
                                // Refresh the queue display so the new priority values are visible
                                setTimeout(function() { loadQueue(); }, 1500);
                            }
                        }).fail(function() {
                            done++;
                            failed++;
                            item.row.css('opacity', '1');
                            item.row.find('.seom-process-btn').prop('disabled', false).text('Retry');
                            item.row.find('.seom-process-msg').html('<span style="color:#dc2626;">&#10007; Server error</span>');
                            if (done === total) {
                                $('#seom-bulk-apply').prop('disabled', false);
                                $('#seom-bulk-status').html('<span style="color:#d97706;">' + queued + ' queued, ' + failed + ' failed.</span>');
                                setTimeout(function() { loadQueue(); }, 1500);
                            }
                        });
                    });
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
            $('#seom-reset-queue').click(function() {
                if (!confirm('Reset queue processing state? This clears stuck items and processing locks without deleting queue items.')) return;
                $.post(ajaxurl, { action: 'seom_reset_queue_state', nonce: seom_nonce }, function(resp) {
                    if (resp.success) {
                        $('#seom-bulk-status').html('<span style="color:#059669;">Queue state reset. Stuck items moved back to pending.</span>');
                        $('#seom-queue-progress').hide();
                        $('#seom-start-queue').prop('disabled', false).text('Process Queue (Background)');
                        $('#seom-stop-queue-bg').hide();
                        if (queuePollTimer) clearTimeout(queuePollTimer);
                        loadQueue();
                    }
                });
            });

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
        <!-- GSC Collection History (collapsible) -->
        <div style="margin-bottom:16px;">
            <div id="seom-gsc-history-toggle" style="cursor:pointer; padding:12px 16px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <strong style="font-size:16px;">GSC Collection History</strong>
                    <span style="font-size:12px;color:#94a3b8;margin-left:8px;">True single-day GSC metrics &middot; Deltas show change from prior period</span>
                </div>
                <span style="font-size:11px; color:#64748b;" id="seom-gsc-history-arrow">&#9660; Show</span>
            </div>
            <div id="seom-gsc-history-panel" style="display:none; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 8px 8px; padding:16px; background:#fff;">
                <div style="margin-bottom:12px; display:flex; gap:6px; align-items:center;">
                    <strong style="font-size:13px;">Period:</strong>
                    <button class="button button-small seom-gsc-period active" data-period="daily">Daily</button>
                    <button class="button button-small seom-gsc-period" data-period="weekly">Weekly</button>
                    <button class="button button-small seom-gsc-period" data-period="monthly">Monthly</button>
                    <button class="button button-small seom-gsc-period" data-period="quarterly">Quarterly</button>
                    <button class="button button-small seom-gsc-period" data-period="annual">Annual</button>
                </div>
                <div style="margin-bottom:12px; display:flex; gap:8px; align-items:center;">
                    <input type="date" id="seom-gsc-daily-date" value="<?php echo date('Y-m-d', strtotime('-3 days')); ?>" max="<?php echo date('Y-m-d', strtotime('-2 days')); ?>" style="padding:4px 8px; border:1px solid #e2e8f0; border-radius:4px;" />
                    <button class="button button-small" id="seom-gsc-collect-daily">Collect Daily Data</button>
                    <span id="seom-gsc-daily-status" style="font-size:12px;"></span>
                </div>
                <div id="seom-gsc-history-loading" style="color:#94a3b8;">Loading...</div>
                <table class="seom-table" id="seom-gsc-history-table" style="display:none;">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Pages</th>
                            <th>Impressions</th>
                            <th>Clicks</th>
                            <th>CTR %</th>
                            <th>Avg Position</th>
                        </tr>
                    </thead>
                    <tbody id="seom-gsc-history-body"></tbody>
                </table>
                <div id="seom-gsc-history-paging" style="margin:12px 0; text-align:center; font-size:12px;"></div>
            </div>
        </div>

        <!-- Refresh History -->
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
            // ─── GSC Performance History ──
            var gscPeriod = 'daily';
            var gscPage = 1;

            function loadGscHistory() {
                $('#seom-gsc-history-loading').show();
                $('#seom-gsc-history-table').hide();
                $.post(ajaxurl, { action: 'seom_get_gsc_history', nonce: seom_nonce, period: gscPeriod, page: gscPage }, function(resp) {
                    $('#seom-gsc-history-loading').hide();
                    if (!resp.success || !resp.data.rows.length) {
                        $('#seom-gsc-history-table').hide();
                        return;
                    }

                    var rows = resp.data.rows;
                    var tbody = $('#seom-gsc-history-body').empty();

                    for (var i = 0; i < rows.length; i++) {
                        var r = rows[i];
                        var prev = rows[i + 1] || null; // previous period (rows are DESC)

                        var imp = parseInt(r.impressions) || 0;
                        var clk = parseInt(r.clicks) || 0;
                        var ctr = parseFloat(r.ctr) || 0;
                        var pos = parseFloat(r.avg_position) || 0;
                        var pages = parseInt(r.total_pages) || 0;

                        function delta(curr, prior, invert) {
                            if (prior === null || prior === undefined) return '';
                            var diff = curr - prior;
                            if (diff === 0) return '<span style="color:#d97706;font-size:11px;margin-left:4px;">&#8212;</span>';
                            var good = invert ? (diff < 0) : (diff > 0);
                            var color = good ? '#059669' : '#dc2626';
                            var arrow = diff > 0 ? '&#9650;' : '&#9660;';
                            var formatted = Math.abs(diff);
                            if (formatted > 999) formatted = formatted.toLocaleString();
                            else if (typeof diff === 'number' && diff % 1 !== 0) formatted = Math.abs(diff).toFixed(2);
                            return '<span style="color:' + color + ';font-size:11px;margin-left:4px;">' + arrow + ' ' + formatted + '</span>';
                        }

                        var pImp = prev ? parseInt(prev.impressions) || 0 : null;
                        var pClk = prev ? parseInt(prev.clicks) || 0 : null;
                        var pCtr = prev ? parseFloat(prev.ctr) || 0 : null;
                        var pPos = prev ? parseFloat(prev.avg_position) || 0 : null;
                        var pPages = prev ? parseInt(prev.total_pages) || 0 : null;

                        var src = r.data_source || '';
                        var srcBadge = '';
                        var badgeColors = {
                            'daily':     {bg:'#dcfce7',fg:'#166534'},
                            'weekly':    {bg:'#dbeafe',fg:'#1e40af'},
                            'monthly':   {bg:'#ede9fe',fg:'#5b21b6'},
                            'quarterly': {bg:'#fef3c7',fg:'#92400e'},
                            'annual':    {bg:'#fce7f3',fg:'#9d174d'},
                            '28d':       {bg:'#dbeafe',fg:'#1e40af'}
                        };
                        if (badgeColors[src]) {
                            var bc = badgeColors[src];
                            srcBadge = ' <span style="font-size:9px;background:'+bc.bg+';color:'+bc.fg+';padding:1px 4px;border-radius:3px;">'+src+'</span>';
                        }

                        tbody.append('<tr>' +
                            '<td style="font-weight:600;font-size:13px;">' + r.period_label + srcBadge + '</td>' +
                            '<td>' + pages.toLocaleString() + delta(pages, pPages, false) + '</td>' +
                            '<td>' + imp.toLocaleString() + delta(imp, pImp, false) + '</td>' +
                            '<td>' + clk.toLocaleString() + delta(clk, pClk, false) + '</td>' +
                            '<td>' + ctr.toFixed(2) + '%' + delta(ctr, pCtr, false) + '</td>' +
                            '<td>' + pos.toFixed(1) + delta(pos, pPos, true) + '</td>' +
                        '</tr>');
                    }

                    $('#seom-gsc-history-table').show();

                    // Pagination
                    var totalPages = Math.ceil(resp.data.total / resp.data.per_page);
                    var statusGsc = '<span style="margin-left:12px;color:#64748b;font-size:13px;">Page ' + gscPage + ' of ' + totalPages + ' &middot; ' + (resp.data.total || 0).toLocaleString() + ' rows</span>';
                    $('#seom-gsc-history-paging').html(window.seomBuildPagination(gscPage, totalPages, 'seom-gsc-page', statusGsc));
                });
            }

            $('.seom-gsc-period').click(function() {
                $('.seom-gsc-period').removeClass('active');
                $(this).addClass('active');
                gscPeriod = $(this).data('period');
                gscPage = 1;
                loadGscHistory();
            });

            $(document).on('click', '.seom-gsc-page', function(e) {
                e.preventDefault();
                gscPage = parseInt($(this).data('page'));
                loadGscHistory();
            });

            // Toggle collapsible panel — lazy load on first open
            var gscHistoryLoaded = false;
            $('#seom-gsc-history-toggle').click(function() {
                var panel = $('#seom-gsc-history-panel');
                panel.slideToggle(200);
                var showing = panel.is(':visible');
                $('#seom-gsc-history-arrow').html(showing ? '&#9650; Hide' : '&#9660; Show');
                if (!gscHistoryLoaded) {
                    gscHistoryLoaded = true;
                    loadGscHistory();
                }
            });

            // Manual daily collection
            $('#seom-gsc-collect-daily').click(function() {
                var date = $('#seom-gsc-daily-date').val();
                if (!date) { alert('Select a date.'); return; }
                var btn = $(this).prop('disabled', true).text('Collecting...');
                $('#seom-gsc-daily-status').text('');
                $.post(ajaxurl, { action: 'seom_collect_daily', nonce: seom_nonce, date: date }, function(resp) {
                    btn.prop('disabled', false).text('Collect Daily Data');
                    if (resp.success) {
                        var d = resp.data;
                        $('#seom-gsc-daily-status').html('<span style="color:#059669;">&#10003; ' + d.date + ': ' + d.impressions.toLocaleString() + ' impressions, ' + d.clicks.toLocaleString() + ' clicks, ' + d.position + ' avg pos, ' + (d.pages_saved || 0) + ' pages stored</span>');
                        loadGscHistory();
                    } else {
                        $('#seom-gsc-daily-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                    }
                });
            });

            // ─── Refresh History ──
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
                        function fmtChange(before, after, invert, decimals) {
                            if (after === null) return '<span style="color:#999;">Pending</span>';
                            var dp = decimals !== undefined ? decimals : 1;
                            var diff = parseFloat(after) - parseFloat(before);
                            var cls = invert ? (diff < 0 ? 'seom-change-positive' : diff > 0 ? 'seom-change-negative' : '')
                                             : (diff > 0 ? 'seom-change-positive' : diff < 0 ? 'seom-change-negative' : '');
                            return '<span class="' + cls + '">' + parseFloat(after).toFixed(dp) + '</span>';
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
                            '<td>' + (parseFloat(r.ctr_before) || 0).toFixed(2) + '%</td>' +
                            '<td>' + fmtChange(r.ctr_before, r.ctr_after_30d, false, 2) + '</td>' +
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
                    var statusHist = '<span style="margin-left:12px;color:#64748b;font-size:13px;">Page ' + resp.data.page + ' of ' + totalPages + ' &middot; ' + (resp.data.total || 0).toLocaleString() + ' refreshes</span>';
                    $('#seom-history-pagination').html(window.seomBuildPagination(resp.data.page, totalPages, 'seom-history-page', statusHist));
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
                            '<td>' + parseFloat(r.ctr).toFixed(2) + '%</td>' +
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
                    var statusKw = '<span style="margin-left:12px;color:#64748b;font-size:13px;">Page ' + d.page + ' of ' + d.pages + ' &middot; ' + (d.total || 0).toLocaleString() + ' keywords</span>';
                    $('#seom-kw-pagination').html(window.seomBuildPagination(d.page, d.pages, 'seom-kw-page', statusKw));
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
                <button type="button" class="button" id="seom-gap-tag-cleanup" style="color:#0891b2;">Tag Cleanup</button>
                <button type="button" class="button" id="seom-gap-cleanup" style="color:#94a3b8;">Clean Non-English</button>
                <span id="seom-gap-import-status" style="font-size:13px;margin-left:8px;"></span>
            </div>
        </div>

        <!-- Tag Cleanup Panel -->
        <div id="seom-tag-cleanup-panel" style="display:none;margin:0 0 16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3 style="margin:0;font-size:15px;">Tag Cleanup</h3>
                <div style="display:flex;gap:8px;align-items:center;">
                    <label style="font-size:12px;color:#475569;">Aggressiveness:
                        <select id="seom-tcl-aggr" style="font-size:12px;padding:2px 6px;margin-left:4px;">
                            <option value="2" selected>Aggressive (anchor ≥2 tags)</option>
                            <option value="3">Balanced (anchor ≥3 tags)</option>
                            <option value="5">Conservative (anchor ≥5 tags)</option>
                        </select>
                    </label>
                    <button type="button" class="button button-small" id="seom-tcl-refresh">Rescan</button>
                    <button type="button" class="button button-small" id="seom-tag-cleanup-close">Close</button>
                </div>
            </div>
            <div id="seom-tag-cleanup-summary" style="font-size:12px;color:#475569;margin-bottom:10px;">Loading...</div>

            <div style="display:flex;gap:0;border-bottom:1px solid #e2e8f0;margin-bottom:12px;align-items:flex-end;">
                <button type="button" class="seom-tcl-tab button-link" data-tab="clusters" style="padding:8px 14px;border:0;background:transparent;border-bottom:2px solid #0891b2;color:#0891b2;font-weight:600;cursor:pointer;">Variant Clusters</button>
                <button type="button" class="seom-tcl-tab button-link" data-tab="low" style="padding:8px 14px;border:0;background:transparent;border-bottom:2px solid transparent;color:#64748b;cursor:pointer;">Low-Volume Tags</button>
                <div style="margin-left:auto;padding:4px 0;display:flex;gap:6px;align-items:center;" id="seom-tcl-cluster-actions">
                    <label style="font-size:11px;color:#64748b;"><input type="checkbox" id="seom-tcl-expand-all" /> Expand all</label>
                    <button type="button" class="button button-small button-primary" id="seom-tcl-merge-all" style="background:#0891b2;border-color:#0891b2;">Merge All Visible</button>
                </div>
            </div>

            <div id="seom-tcl-clusters" style="max-height:520px;overflow:auto;">
                <div style="color:#94a3b8;padding:12px;">Scanning for variant clusters...</div>
            </div>
            <div id="seom-tcl-low" style="display:none;max-height:520px;overflow:auto;">
                <div style="color:#94a3b8;padding:12px;">Loading...</div>
            </div>
            <div id="seom-tcl-status" style="font-size:12px;margin-top:8px;min-height:18px;"></div>
        </div>

        <!-- Summary Cards -->
        <div class="seom-cards" id="seom-gap-summary" style="margin-bottom:16px;">
            <div class="seom-card"><h3>Total Keywords</h3><div class="seom-card-value" id="sg-total">-</div></div>
            <div class="seom-card"><h3>Imported Today</h3><div class="seom-card-value" id="sg-today">-</div></div>
            <div class="seom-card"><h3>Untagged</h3><div class="seom-card-value" style="color:#d97706;" id="sg-untagged">-</div></div>
            <div class="seom-card"><h3>Categories</h3><div class="seom-card-value" id="sg-categories">-</div></div>
            <div class="seom-card"><h3>Avg Volume</h3><div class="seom-card-value" id="sg-avg-vol">-</div></div>
        </div>
        <div id="seom-gap-last-import" style="display:none;margin:-8px 0 16px;color:#64748b;font-size:12px;"></div>

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
                        $('#sg-today').text(parseInt(d.summary.imported_today || 0).toLocaleString());
                        $('#sg-untagged').text(parseInt(d.summary.untagged || 0).toLocaleString());
                        $('#sg-categories').text(parseInt(d.summary.tag_count || 0));
                        $('#sg-avg-vol').text(Math.round(d.summary.avg_volume || 0).toLocaleString());
                        if (d.summary.last_import) {
                            var li = d.summary.last_import;
                            $('#seom-gap-last-import').show().html(
                                'Last import: ' + parseInt(li.imported || 0).toLocaleString() + ' rows processed; '
                                + parseInt(li.inserted || 0).toLocaleString() + ' new; '
                                + parseInt(li.updated || 0).toLocaleString() + ' updated; '
                                + parseInt(li.skipped || 0).toLocaleString() + ' skipped.'
                            );
                        } else {
                            $('#seom-gap-last-import').hide().empty();
                        }
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
                    var statusGap = '<span style="margin-left:12px;color:#64748b;font-size:13px;">Page ' + d.page + ' of ' + d.pages + ' &middot; ' + (d.total || 0).toLocaleString() + ' keywords</span>';
                    $('#seom-gap-pagination').html(window.seomBuildPagination(d.page, d.pages, 'seom-gap-page', statusGap));
                });
            }

            // Import — batched
            $('#seom-gap-import').click(function() {
                var csv = $('#seom-gap-csv').val().trim();
                if (!csv) { alert('Paste CSV data first.'); return; }
                var btn = $(this).prop('disabled', true).text('Importing...');
                var src = $('#seom-gap-source').val();
                $('#seom-gap-import-status').html('<span style="color:#b45309;">Parsing CSV...</span>');

                var lines = csv.split(/\r?\n/);
                var header = (lines.shift() || '').trim();
                var dataLines = lines.filter(function(line) { return line.trim() !== ''; });
                var chunkSize = 400;
                var totalChunks = Math.ceil(dataLines.length / chunkSize);

                function importRequest(postData, onSuccess) {
                    $.ajax({ url: ajaxurl, method: 'POST', timeout: 60000, data: postData,
                        success: function(resp) {
                            if (!resp.success) {
                                btn.prop('disabled', false).text('Import Keywords');
                                $('#seom-gap-import-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                                return;
                            }
                            onSuccess(resp.data);
                        },
                        error: function() {
                            btn.prop('disabled', false).text('Import Keywords');
                            $('#seom-gap-import-status').html('<span style="color:#dc2626;">Server error/timeout. Import stopped before completion.</span>');
                        }
                    });
                }

                function runChunk(index) {
                    if (index >= totalChunks) {
                        $('#seom-gap-import-status').html('<span style="color:#b45309;">Finalizing import...</span>');
                        finalizeImport();
                        return;
                    }

                    var chunk = dataLines.slice(index * chunkSize, (index + 1) * chunkSize).join("\n");
                    importRequest({
                        action: 'seom_import_keyword_gaps',
                        nonce: seom_nonce,
                        phase: 'chunk',
                        source: src,
                        csv_data: chunk
                    }, function(d) {
                        var pct = Math.round(((index + 1) / totalChunks) * 100);
                        $('#seom-gap-import-status').html('<span style="color:#b45309;">Importing chunk ' + (index + 1) + '/' + totalChunks + ' (' + pct + '%). ' + d.inserted + ' new, ' + d.updated + ' updated...</span>');
                        runChunk(index + 1);
                    });
                }

                function finalizeImport() {
                    importRequest({ action: 'seom_import_keyword_gaps', nonce: seom_nonce, phase: 'complete', source: src }, function(d) {
                        if (d.phase === 'finalizing') {
                            var total = parseInt(d.total || 0);
                            var processed = parseInt(d.processed || 0);
                            var pct = total ? Math.min(100, Math.round((processed / total) * 100)) : 0;
                            $('#seom-gap-import-status').html('<span style="color:#b45309;">Finalizing import... ' + processed + '/' + total + ' checked (' + pct + '%). ' + d.restored + ' usage records restored...</span>');
                            finalizeImport();
                            return;
                        }

                        btn.prop('disabled', false).text('Import Keywords');
                        var msg = d.imported + ' rows processed. ' + (d.inserted || 0) + ' new keywords added, ' + (d.updated || 0) + ' existing keywords updated.';
                        if (d.skipped) msg += ' ' + d.skipped + ' skipped.';
                        if (d.restored) msg += ' ' + d.restored + ' usage records restored.';
                        $('#seom-gap-import-status').html('<span style="color:#059669;">' + msg + '</span>');
                        $('#seom-gap-csv').val('');
                        loadGaps(1);
                    });
                }

                importRequest({
                    action: 'seom_import_keyword_gaps',
                    nonce: seom_nonce,
                    phase: 'start',
                    source: src,
                    header: header
                }, function() {
                    $('#seom-gap-import-status').html('<span style="color:#b45309;">' + (totalChunks ? 'Importing chunk 1/' + totalChunks + '...' : 'Finalizing import...') + '</span>');
                    runChunk(0);
                });
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

            // ── Tag Cleanup panel ────────────────────────────────────────
            function escapeHtml(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            // Holds the latest cluster payload from the server so handlers
            // can rebuild merge groups without re-walking the DOM.
            var seomClusterCache = [];

            function renderClusters(clusters) {
                seomClusterCache = clusters || [];
                var $box = $('#seom-tcl-clusters').empty();
                if (!clusters.length) {
                    $box.html('<div style="color:#059669;padding:12px;">No variant clusters found. Tag set is clean.</div>');
                    return;
                }
                var expanded = $('#seom-tcl-expand-all').is(':checked');
                clusters.forEach(function(c, i) {
                    var $row = $('<div class="seom-tcl-cluster" data-cluster="' + i + '" style="padding:10px 12px;border-bottom:1px solid #f1f5f9;"></div>');
                    var showVariants = expanded || c.variants.length <= 8;
                    var variantsHtml = c.variants.map(function(v) {
                        return '<label style="display:inline-flex;align-items:center;gap:4px;margin:2px 8px 2px 0;font-size:12px;">'
                            + '<input type="checkbox" class="seom-tcl-variant" data-cluster="' + i + '" data-tag="' + escapeHtml(v.tag) + '" checked /> '
                            + '<span>' + escapeHtml(v.tag) + ' <span style="color:#94a3b8;">(' + v.cnt + ')</span></span>'
                            + '</label>';
                    }).join('');
                    var anchorBadge = c.anchor
                        ? '<span style="font-size:10px;background:#e0f2fe;color:#075985;padding:2px 6px;border-radius:3px;text-transform:lowercase;">anchor: ' + escapeHtml(c.anchor) + '</span>'
                        : '';
                    $row.html(
                        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">'
                        + '<input type="checkbox" class="seom-tcl-include" data-cluster="' + i + '" checked title="Include in Merge All" />'
                        + '<strong style="font-size:13px;">Canonical:</strong> '
                        + '<input type="text" class="seom-tcl-canonical" data-cluster="' + i + '" value="' + escapeHtml(c.canonical) + '" style="flex:1;min-width:160px;padding:4px 8px;font-size:13px;" /> '
                        + anchorBadge
                        + ' <span style="font-size:11px;color:#64748b;">' + c.total + ' keywords / ' + c.variants.length + ' tags</span> '
                        + '<button type="button" class="button button-small seom-tcl-toggle" data-cluster="' + i + '">' + (showVariants ? 'Hide variants' : 'Show ' + c.variants.length + ' variants') + '</button>'
                        + '<button type="button" class="button button-small seom-tcl-merge" data-cluster="' + i + '">Merge</button>'
                        + '</div>'
                        + '<div class="seom-tcl-variants" data-cluster="' + i + '" style="' + (showVariants ? '' : 'display:none;') + '">' + variantsHtml + '</div>'
                    );
                    $box.append($row);
                });
            }

            function renderLowVolume(rows, threshold) {
                var $box = $('#seom-tcl-low').empty();
                if (!rows.length) {
                    $box.html('<div style="color:#059669;padding:12px;">No low-volume tags below threshold.</div>');
                    return;
                }
                var header = '<div style="padding:8px 12px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">'
                    + '<label style="font-size:12px;"><input type="checkbox" id="seom-tcl-low-all" /> Select all (&lt;' + threshold + ' keywords)</label>'
                    + '<button type="button" class="button button-small" id="seom-tcl-low-uncat" style="color:#b91c1c;">Uncategorize Selected</button>'
                    + '</div>';
                var body = rows.map(function(r) {
                    return '<label style="display:flex;align-items:center;gap:8px;padding:6px 12px;border-bottom:1px solid #f1f5f9;font-size:12px;cursor:pointer;">'
                        + '<input type="checkbox" class="seom-tcl-low-cb" data-tag="' + escapeHtml(r.tag) + '" /> '
                        + '<span style="flex:1;">' + escapeHtml(r.tag) + '</span>'
                        + '<span style="color:#94a3b8;">' + r.cnt + ' keyword' + (r.cnt === 1 ? '' : 's') + '</span>'
                        + '</label>';
                }).join('');
                $box.html(header + body);
            }

            function loadTagCleanup() {
                var minFreq = parseInt($('#seom-tcl-aggr').val(), 10) || 2;
                $('#seom-tag-cleanup-summary').text('Scanning...');
                $('#seom-tcl-clusters').html('<div style="color:#94a3b8;padding:12px;">Scanning for variant clusters...</div>');
                $('#seom-tcl-low').html('<div style="color:#94a3b8;padding:12px;">Loading...</div>');
                $.post(ajaxurl, {
                    action: 'seom_get_tag_cleanup', nonce: seom_nonce,
                    low_volume: 5, max_clusters: 200, min_freq: minFreq
                }, function(resp) {
                    if (!resp.success) {
                        $('#seom-tag-cleanup-summary').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                        return;
                    }
                    var d = resp.data;
                    var moreNote = (d.cluster_count > d.cluster_shown)
                        ? ' (showing top ' + d.cluster_shown + ' of ' + d.cluster_count + ')'
                        : '';
                    $('#seom-tag-cleanup-summary').html(
                        '<strong>' + d.total_tags + '</strong> distinct tags. '
                        + '<strong>' + d.cluster_count + '</strong> variant clusters covering <strong>' + d.cluster_tag_count + '</strong> tags' + moreNote + '. '
                        + '<strong>' + d.low_volume_count + '</strong> low-volume tags (&lt;' + d.threshold + ' keywords).'
                    );
                    renderClusters(d.clusters || []);
                    renderLowVolume(d.low_volume || [], d.threshold);
                });
            }

            $('#seom-gap-tag-cleanup').click(function() {
                $('#seom-tag-cleanup-panel').show();
                loadTagCleanup();
            });
            $('#seom-tag-cleanup-close').click(function() { $('#seom-tag-cleanup-panel').hide(); });
            $('#seom-tcl-refresh, #seom-tcl-aggr').on('change click', function() {
                if (this.id === 'seom-tcl-aggr' || this.id === 'seom-tcl-refresh') loadTagCleanup();
            });
            $('#seom-tcl-expand-all').on('change', function() {
                if (seomClusterCache.length) renderClusters(seomClusterCache);
            });
            $(document).on('click', '.seom-tcl-toggle', function() {
                var idx = $(this).data('cluster');
                var $variants = $('.seom-tcl-variants[data-cluster="' + idx + '"]');
                var visible = $variants.is(':visible');
                $variants.toggle(!visible);
                $(this).text(visible ? ('Show ' + seomClusterCache[idx].variants.length + ' variants') : 'Hide variants');
            });

            $(document).on('click', '.seom-tcl-tab', function() {
                var tab = $(this).data('tab');
                $('.seom-tcl-tab').css({borderBottomColor: 'transparent', color: '#64748b', fontWeight: '400'});
                $(this).css({borderBottomColor: '#0891b2', color: '#0891b2', fontWeight: '600'});
                $('#seom-tcl-clusters').toggle(tab === 'clusters');
                $('#seom-tcl-low').toggle(tab === 'low');
            });

            // Merge a single cluster
            $(document).on('click', '.seom-tcl-merge', function() {
                var $btn = $(this).prop('disabled', true).text('Merging...');
                var clusterIdx = $btn.data('cluster');
                var canonical = $('.seom-tcl-canonical[data-cluster="' + clusterIdx + '"]').val();
                var sources = [];
                // If the variants panel is collapsed, no checkboxes are visible
                // — fall back to merging every variant in the cluster.
                var $checked = $('.seom-tcl-variant[data-cluster="' + clusterIdx + '"]:checked');
                if ($checked.length) {
                    $checked.each(function() { sources.push($(this).data('tag')); });
                } else if (seomClusterCache[clusterIdx]) {
                    seomClusterCache[clusterIdx].variants.forEach(function(v) { sources.push(v.tag); });
                }
                if (!canonical || sources.length === 0) {
                    $btn.prop('disabled', false).text('Merge');
                    alert('Pick a canonical name and at least one source variant.');
                    return;
                }
                $.post(ajaxurl, {
                    action: 'seom_merge_gap_tags', nonce: seom_nonce,
                    canonical: canonical, sources: JSON.stringify(sources)
                }, function(resp) {
                    if (resp.success) {
                        $btn.closest('.seom-tcl-cluster').fadeOut(180, function() { $(this).remove(); });
                        $('#seom-tcl-status').html('<span style="color:#059669;">Merged ' + resp.data.merged + ' keywords into &ldquo;' + escapeHtml(canonical) + '&rdquo;.</span>');
                        loadGaps(1);
                    } else {
                        $btn.prop('disabled', false).text('Merge');
                        $('#seom-tcl-status').html('<span style="color:#dc2626;">' + (resp.data || 'Merge failed') + '</span>');
                    }
                });
            });

            // Merge ALL visible clusters in one round-trip. Respects:
            //  - the cluster-level "include" checkbox (default checked)
            //  - any per-variant unchecks the user made while reviewing
            //  - any edits to the canonical text field
            $(document).on('click', '#seom-tcl-merge-all', function() {
                if (!seomClusterCache.length) {
                    alert('Nothing to merge.');
                    return;
                }
                var groups = [];
                $('.seom-tcl-include:checked').each(function() {
                    var idx = $(this).data('cluster');
                    if (!seomClusterCache[idx]) return;
                    var canonical = ($('.seom-tcl-canonical[data-cluster="' + idx + '"]').val() || '').trim();
                    if (!canonical) return;
                    var sources;
                    var $checked = $('.seom-tcl-variant[data-cluster="' + idx + '"]:checked');
                    if ($checked.length) {
                        sources = $checked.map(function() { return $(this).data('tag'); }).get();
                    } else {
                        // Collapsed cluster — use every variant by default.
                        sources = seomClusterCache[idx].variants.map(function(v) { return v.tag; });
                    }
                    if (sources.length) groups.push({ canonical: canonical, sources: sources });
                });
                if (!groups.length) {
                    alert('No clusters selected for merging.');
                    return;
                }
                var totalTags = 0;
                groups.forEach(function(g) { totalTags += g.sources.length; });
                if (!confirm('Merge ' + groups.length + ' clusters (' + totalTags + ' source tags) in one shot? This rewrites the tag column on every matching keyword row.')) return;

                var $btn = $(this).prop('disabled', true).text('Merging ' + groups.length + ' clusters...');
                $('#seom-tcl-status').html('<span style="color:#b45309;">Bulk merging — this may take a moment for big batches...</span>');
                $.ajax({ url: ajaxurl, method: 'POST', timeout: 300000,
                    data: {
                        action: 'seom_bulk_merge_gap_tags',
                        nonce: seom_nonce,
                        groups: JSON.stringify(groups)
                    },
                    success: function(resp) {
                        $btn.prop('disabled', false).text('Merge All Visible');
                        if (resp.success) {
                            $('#seom-tcl-status').html('<span style="color:#059669;">Merged ' + resp.data.clusters_merged + ' clusters (' + resp.data.merged + ' keyword rows updated). ' + resp.data.distinct_tags + ' distinct tags remain.</span>');
                            loadTagCleanup();
                            loadGaps(1);
                        } else {
                            $('#seom-tcl-status').html('<span style="color:#dc2626;">' + (resp.data || 'Bulk merge failed') + '</span>');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('Merge All Visible');
                        $('#seom-tcl-status').html('<span style="color:#dc2626;">Server error/timeout. Try a smaller batch or use individual Merge buttons.</span>');
                    }
                });
            });

            // Low-volume "select all" + bulk uncategorize
            $(document).on('change', '#seom-tcl-low-all', function() {
                $('.seom-tcl-low-cb').prop('checked', $(this).is(':checked'));
            });
            $(document).on('click', '#seom-tcl-low-uncat', function() {
                var tags = [];
                $('.seom-tcl-low-cb:checked').each(function() { tags.push($(this).data('tag')); });
                if (!tags.length) { alert('Select at least one tag.'); return; }
                if (!confirm('Uncategorize ' + tags.length + ' tags? Their keywords will go back to the Untagged pool for re-tagging.')) return;
                var $btn = $(this).prop('disabled', true).text('Working...');
                $.post(ajaxurl, {
                    action: 'seom_uncategorize_gap_tags', nonce: seom_nonce,
                    tags: JSON.stringify(tags)
                }, function(resp) {
                    $btn.prop('disabled', false).text('Uncategorize Selected');
                    if (resp.success) {
                        $('#seom-tcl-status').html('<span style="color:#059669;">Uncategorized ' + resp.data.uncategorized + ' keywords across ' + resp.data.tag_count + ' tags.</span>');
                        loadTagCleanup();
                        loadGaps(1);
                    } else {
                        $('#seom-tcl-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                    }
                });
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
                                <option value="new_content_30d">Total Published Content</option>
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

        <!-- Capacity Analysis Panel -->
        <div id="seom-capacity-panel" style="display:none;">
            <div class="seom-section-header">Queue Capacity Analysis <a href="#" id="seom-capacity-refresh-btn" style="font-size:12px;">Refresh</a></div>
            <div id="seom-capacity-content"></div>
        </div>

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
                new_content_30d: 'Total Published Content',
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

            // Metrics where "reduce" means "improve" — natural language should say "Improve" not "Reduce"
            // and for these, a decrease in value is always good regardless of direction field
            var lowerIsBetter = { avg_position: true, ghost_pages: true, stale_pages: true, page2_pages: true };

            // Get natural language verb for a goal
            function goalVerb(metric, direction) {
                if (lowerIsBetter[metric] && direction === 'reduce') return 'Improve';
                if (direction === 'reduce') return 'Reduce';
                return 'Increase';
            }

            // Is a change in value good for this metric+direction combo?
            function isChangeGood(metric, direction, changeVal) {
                if (changeVal === 0) return null; // no change
                // For lower-is-better metrics, decrease is always good
                if (lowerIsBetter[metric]) return changeVal < 0;
                // For higher-is-better, increase is good if direction is increase
                return direction === 'increase' ? changeVal > 0 : changeVal < 0;
            }

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
                            if (metric === 'avg_ctr') val = val.toFixed(4);
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

                        var verb = goalVerb(g.metric, g.direction);
                        var dirLabel = (verb === 'Reduce') ? '&#9660;' : '&#9650;';
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
                        card += '<h4 style="margin:0 0 4px; font-size:16px;">' + dirLabel + ' ' + verb + ' ' + metricName + ' by ' + typeLabel + '</h4>';
                        var startDate = g.start_date || g.created_at.substring(0, 10);
                        var totalDays = Math.ceil((new Date(deadline) - new Date(startDate)) / 86400000);
                        var daysElapsed = Math.ceil((new Date() - new Date(startDate)) / 86400000);

                        var timePct = totalDays > 0 ? Math.min(100, (daysElapsed / totalDays) * 100) : 100;
                        var _pm = {avg_ctr:1, avg_position:1};
                        var fmtBaseline = _pm[g.metric] ? parseFloat(baseline).toFixed(2) : baseline;
                        var fmtTargetNum = _pm[g.metric] ? parseFloat(targetNum).toFixed(2) : Math.round(targetNum * 10) / 10;
                        card += '<div style="font-size:12px; color:#64748b;">Baseline: ' + fmtBaseline + ' &rarr; Target: ' + fmtTargetNum + ' &bull; ' + startDate + ' to ' + deadline + ' (' + totalDays + ' days)';
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
                        var changeSinceBaseline = current - baseline;
                        var changeGood = isChangeGood(g.metric, g.direction, changeSinceBaseline);
                        var isMovingRight = changeGood === true;
                        var isMovingWrong = changeGood === false;
                        var changePct = baseline !== 0 ? parseFloat((Math.abs(changeSinceBaseline) / baseline * 100).toFixed(2)) : 0;

                        // Progress bar color — compare progress to time elapsed, not absolute %
                        var progressColor;
                        if (g.status === 'completed') { progressColor = '#059669'; }
                        else if (g.status === 'missed') { progressColor = '#dc2626'; }
                        else if (progress >= 100) { progressColor = '#059669'; }
                        else if (isMovingWrong) { progressColor = '#dc2626'; }
                        else if (progress >= timePct - 10) { progressColor = '#059669'; } // on track or ahead
                        else if (progress >= timePct - 30) { progressColor = '#d97706'; } // slightly behind
                        else { progressColor = '#dc2626'; } // significantly behind

                        // Direction indicator
                        // Describe what actually happened in plain English
                        var changeWord = changeSinceBaseline < 0 ? 'decreased' : 'increased';
                        var absChange = _pm[g.metric] ? Math.abs(parseFloat(changeSinceBaseline.toFixed(4))) : Math.abs(Math.round(changeSinceBaseline * 10) / 10);

                        var dirIndicator = '';
                        if (g.status === 'active') {
                            if (isMovingWrong) {
                                dirIndicator = '<div style="background:#fee2e2; border:1px solid #fca5a5; border-radius:6px; padding:8px 14px; margin-bottom:10px; display:flex; align-items:center; gap:8px;">'
                                    + '<span style="font-size:18px;">&#9888;</span>'
                                    + '<span style="color:#991b1b; font-size:13px; font-weight:600;">Moving wrong direction — '
                                    + changeWord + ' by ' + absChange + ' (' + changePct + '%) since baseline</span></div>';
                            } else if (progress >= 100) {
                                dirIndicator = '<div style="background:#dcfce7; border:1px solid #86efac; border-radius:6px; padding:8px 14px; margin-bottom:10px; display:flex; align-items:center; gap:8px;">'
                                    + '<span style="font-size:18px;">&#10003;</span>'
                                    + '<span style="color:#166534; font-size:13px; font-weight:600;">Goal reached! Target met ahead of deadline.</span></div>';
                            } else if (isMovingRight) {
                                dirIndicator = '<div style="background:#ecfdf5; border:1px solid #6ee7b7; border-radius:6px; padding:8px 14px; margin-bottom:10px; display:flex; align-items:center; gap:8px;">'
                                    + '<span style="font-size:18px;">&#9650;</span>'
                                    + '<span style="color:#065f46; font-size:13px;">On track — '
                                    + changeWord + ' by ' + absChange + ' (' + changePct + '%) so far</span></div>';
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
                        var fmtChangeSince = _pm[g.metric] ? parseFloat(changeSinceBaseline).toFixed(2) : Math.round(changeSinceBaseline * 10) / 10;
                        var fmtCurrent = _pm[g.metric] ? parseFloat(current).toFixed(2) : Math.round(current * 10) / 10;
                        var changeLabel = changeSinceBaseline !== 0
                            ? ' <span style="color:' + (isMovingRight ? '#059669' : '#dc2626') + ';">(' + (changeSinceBaseline > 0 ? '+' : '') + fmtChangeSince + ')</span>'
                            : ' <span style="color:#94a3b8;">(no change)</span>';
                        card += '<span>Current: <strong style="color:#1e293b;">' + fmtCurrent + '</strong>' + changeLabel + '</span>';
                        card += '<span>Target: <strong style="color:#1e293b;">' + fmtTargetNum + '</strong></span>';
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
                        loadCapacity();
                    } else {
                        $('#seom-goals-refresh-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                    }
                });
            });

            // Edit goal — populate form with existing goal data
            var editingGoalId = null;
            var editingOriginalTarget = null; // track original target for notes sync
            $(document).on('click', '.seom-goal-edit', function() {
                var g = $(this).data('goal');
                if (typeof g === 'string') g = JSON.parse(g);
                editingGoalId = g.id;
                editingOriginalTarget = parseFloat(g.target_value);

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

            // Auto-sync notes when target value changes during edit (percent goals)
            $('#seom-goal-target').on('change', function() {
                if (!editingGoalId || !editingOriginalTarget) return;
                if ($('#seom-goal-type').val() !== 'percent') return;
                var newVal = parseFloat($(this).val());
                if (isNaN(newVal) || newVal === editingOriginalTarget) return;
                var notes = $('#seom-goal-notes').val();
                var oldStr = editingOriginalTarget + '%';
                var newStr = newVal + '%';
                if (notes.indexOf(oldStr) !== -1) {
                    $('#seom-goal-notes').val(notes.split(oldStr).join(newStr));
                }
                editingOriginalTarget = newVal;
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
                    ctx += '- Avg CTR: ' + parseFloat(m.avg_ctr).toFixed(4) + '%\n';
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

            // Capacity analysis
            function loadCapacity() {
                $('#seom-capacity-content').html('<div style="padding:12px;color:#94a3b8;"><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>Loading capacity analysis...</div>');
                $('#seom-capacity-panel').show();
                $.post(ajaxurl, { action: 'seom_get_capacity', nonce: seom_nonce }, function(resp) {
                    if (!resp.success) {
                        $('#seom-capacity-content').html('<div style="padding:12px;color:#dc2626;">Error loading capacity: ' + (resp.data || 'Unknown error') + '</div>');
                        return;
                    }
                    if (!resp.data.goals || !resp.data.goals.length) {
                        $('#seom-capacity-content').html('<div style="padding:12px;color:#94a3b8;">No active goals. Create goals to see capacity analysis.</div>');
                        return;
                    }
                    var d = resp.data;
                    var html = '';

                    // Summary bar
                    var statusColor = d.sufficient ? '#059669' : '#dc2626';
                    var statusIcon = d.sufficient ? '&#10003;' : '&#9888;';
                    var statusText = d.sufficient
                        ? 'Current daily limit (' + d.current_limit + ') is sufficient for all goals.'
                        : 'Goals require ~' + d.total_daily_needed + ' refreshes/day but limit is ' + d.current_limit + '. Recommend increasing to ' + d.recommended_limit + '.';

                    html += '<div style="background:' + (d.sufficient ? '#ecfdf5' : '#fee2e2') + '; border:1px solid ' + (d.sufficient ? '#6ee7b7' : '#fca5a5') + '; border-radius:8px; padding:12px 18px; margin-bottom:16px; display:flex; align-items:center; gap:10px;">';
                    html += '<span style="font-size:20px;">' + statusIcon + '</span>';
                    html += '<div><strong style="color:' + statusColor + ';">' + statusText + '</strong></div>';
                    html += '</div>';

                    // Per-goal breakdown table
                    html += '<table class="seom-table" style="font-size:13px;">';
                    html += '<thead><tr>';
                    html += '<th>Goal</th><th>Priority</th><th>Progress</th><th>Urgency</th>';
                    html += '<th>Success Rate</th><th>Refreshes Needed</th><th>Daily Rate</th><th>Status</th>';
                    html += '</tr></thead><tbody>';

                    var goalLabels = {
                        ghost_pages:'Ghost Pages', total_clicks:'Clicks', total_impressions:'Impressions',
                        avg_position:'Avg Position', avg_ctr:'CTR', page1_pages:'Page 1',
                        page2_pages:'Page 2', pages_with_impressions:'With Impressions',
                        new_content_30d:'New Content', stale_pages:'Stale Pages', refreshed_this_month:'Refreshed'
                    };
                    var urgencyColors = {on_track:'#059669', slightly_behind:'#d97706', behind:'#dc2626', critical:'#991b1b', completed:'#2563eb'};
                    var urgencyLabels = {on_track:'On Track', slightly_behind:'Slightly Behind', behind:'Behind', critical:'Critical', completed:'Met'};
                    var priLabels = {1:'P1',2:'P2',3:'P3',4:'P4',5:'P5'};

                    d.goals.forEach(function(g) {
                        var label = goalLabels[g.metric] || g.metric;
                        var dir = g.direction === 'reduce' ? '&#9660;' : '&#9650;';
                        var urgColor = urgencyColors[g.urgency_label] || '#64748b';
                        var urgLabel = urgencyLabels[g.urgency_label] || g.urgency_label;
                        var feasible = g.feasible;

                        html += '<tr>';
                        html += '<td><strong>' + dir + ' ' + label + '</strong></td>';
                        html += '<td><span class="seom-badge" style="background:#dbeafe;color:#1e40af;">' + (priLabels[g.priority] || 'P3') + '</span></td>';
                        html += '<td>';
                        html += '<div style="display:flex;align-items:center;gap:6px;">';
                        html += '<div style="background:#e2e8f0;border-radius:3px;height:8px;width:80px;overflow:hidden;">';
                        // Color based on urgency label (which already accounts for progress vs time)
                        var progColor = (g.urgency_label === 'on_track' || g.urgency_label === 'completed') ? '#059669' : (g.urgency_label === 'slightly_behind' ? '#d97706' : '#dc2626');
                        html += '<div style="width:' + Math.max(0, g.progress) + '%;background:' + progColor + ';height:100%;border-radius:3px;"></div></div>';
                        html += '<span>' + Math.round(g.progress) + '%</span></div></td>';
                        html += '<td><span style="color:' + urgColor + ';font-weight:600;">' + urgLabel + '</span></td>';
                        html += '<td>' + Math.round(g.success_rate * 100) + '%</td>';
                        html += '<td>' + g.refreshes_needed + '</td>';
                        html += '<td><strong>' + g.daily_rate_needed + '/day</strong></td>';
                        html += '<td>';
                        if (g.urgency_label === 'completed') {
                            html += '<span style="color:#059669;font-weight:600;">&#10003; Met</span>';
                        } else if (feasible) {
                            html += '<span style="color:#059669;">&#10003; Feasible</span>';
                        } else {
                            html += '<span style="color:#dc2626;font-weight:600;">&#9888; Needs more capacity</span>';
                        }
                        html += '</td></tr>';
                    });

                    html += '</tbody></table>';

                    // Allocation summary
                    html += '<div style="margin-top:16px; padding:14px 18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:13px;">';
                    html += '<strong>How the queue is allocated:</strong> Slots are distributed proportionally to goal priority and urgency. ';
                    html += 'Higher-priority goals and goals falling behind schedule get more daily refresh slots. ';
                    html += 'Each category always gets at least 1 slot if candidates exist.';
                    if (!d.sufficient) {
                        html += '<br><br><strong style="color:#dc2626;">Recommendation:</strong> Increase daily limit from ' + d.current_limit + ' to ' + d.recommended_limit;
                        html += ' in <a href="?page=seo-ai-autopilot&tab=settings">Settings</a>, or enable Goal-Adaptive mode to auto-adjust.';
                    }
                    html += '</div>';

                    $('#seom-capacity-content').html(html);
                }).fail(function(xhr) {
                    $('#seom-capacity-content').html('<div style="padding:12px;color:#dc2626;">Failed to load capacity analysis. Error: ' + xhr.status + ' ' + xhr.statusText + '</div>');
                });
            }

            $(document).on('click', '#seom-capacity-refresh-btn', function(e) { e.preventDefault(); loadCapacity(); });

            loadMetrics();
            loadGoals();
            loadCapacity();
        });
        </script>
        <?php
    }

    // ─── Settings Tab ─────────────────────────────────────────────────────────

    private static function render_settings($nonce, $settings) {
        ?>
        <style>
            .seom-form { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
            .seom-form-section { padding: 0; }
            .seom-form-section-header { background: #1e293b; color: #fff; padding: 12px 24px; font-size: 15px; font-weight: 600; }
            .seom-form-section-desc { padding: 8px 24px 0; font-size: 12px; color: #64748b; }
            .seom-form-row { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; display: flex; gap: 24px; align-items: flex-start; }
            .seom-form-row:last-child { border-bottom: none; }
            .seom-form-row:hover { background: #fafbfc; }
            .seom-form-label { width: 220px; flex-shrink: 0; padding-top: 6px; }
            .seom-form-label label { font-weight: 600; font-size: 14px; color: #1e293b; display: block; }
            .seom-form-label .seom-field-desc { font-size: 12px; color: #94a3b8; margin-top: 2px; line-height: 1.4; }
            .seom-form-field { flex: 1; }
            .seom-form-field input[type="text"],
            .seom-form-field input[type="email"],
            .seom-form-field input[type="number"],
            .seom-form-field textarea {
                width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px;
                font-size: 14px; color: #1e293b; background: #fff; transition: border-color 0.15s;
            }
            .seom-form-field input:focus, .seom-form-field textarea:focus { border-color: #2563eb; outline: none; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
            .seom-form-field input[type="number"] { width: 100px; }
            .seom-form-field .seom-field-help { font-size: 12px; color: #64748b; margin-top: 6px; line-height: 1.4; }
            .seom-form-field .seom-field-inline { display: flex; align-items: center; gap: 8px; }
            .seom-form-field .seom-field-inline span { font-size: 13px; color: #64748b; }
            .seom-form-field .seom-checkbox-group label { display: block; padding: 4px 0; font-size: 14px; }
            .seom-form-field .seom-status-ok { color: #059669; font-weight: 600; font-size: 13px; margin-top: 6px; }
        </style>

        <h2>Settings</h2>

        <?php
            // ── GSC connection state — used by the dual-method UI below ──
            $gsc_auth_method     = get_option('seom_gsc_auth_method', '');
            $oauth_email         = get_option('seom_gsc_oauth_email', '');
            $oauth_connected     = !empty(get_option('seom_gsc_oauth_refresh_token', ''));
            $sa_configured       = !empty($settings['gsc_credentials_json']);
            $oauth_configured    = function_exists('seom_oauth_configured') && seom_oauth_configured();
            $oauth_callback_msg  = '';
            if (isset($_GET['oauth_connected']) && $_GET['oauth_connected'] === '1') {
                $oauth_callback_msg = '<div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:10px 14px;border-radius:6px;margin-bottom:14px;">&#10003; Connected to Google Search Console successfully.</div>';
            } elseif (isset($_GET['oauth_error'])) {
                $oauth_callback_msg = '<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:10px 14px;border-radius:6px;margin-bottom:14px;">&#10007; OAuth error: ' . esc_html(urldecode($_GET['oauth_error'])) . '</div>';
            }
        ?>

        <form id="seom-settings-form">
            <div class="seom-form">
                <!-- GSC Section -->
                <div class="seom-form-section">
                    <div class="seom-form-section-header">Google Search Console</div>
                    <div class="seom-form-section-desc">Choose how the plugin connects to Google Search Console. OAuth is recommended for most users; service account is for technical setups managing multiple sites.</div>

                    <?php if ($oauth_callback_msg) : ?>
                        <div style="padding:14px 24px 0;"><?php echo $oauth_callback_msg; ?></div>
                    <?php endif; ?>

                    <div class="seom-form-row" style="display:block;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

                            <!-- ── Card 1: OAuth (Recommended) ── -->
                            <div style="border:1px solid #e2e8f0;border-radius:10px;padding:18px;background:#fff;<?php echo $gsc_auth_method === 'oauth' ? 'box-shadow:0 0 0 2px #3b82f6;' : ''; ?>">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                    <div style="font-weight:700;font-size:15px;color:#1e293b;">
                                        OAuth 2.0
                                        <span style="font-size:10px;font-weight:600;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;margin-left:6px;">RECOMMENDED</span>
                                    </div>
                                    <?php if ($gsc_auth_method === 'oauth') : ?>
                                        <span style="font-size:10px;font-weight:600;background:#dcfce7;color:#166534;padding:3px 8px;border-radius:10px;">ACTIVE</span>
                                    <?php endif; ?>
                                </div>
                                <p style="margin:0 0 14px;font-size:12px;color:#64748b;line-height:1.5;">Click a button, sign in with Google, done. No JSON files, no service accounts. Token auto-refreshes silently.</p>

                                <?php if (!$oauth_configured) : ?>
                                    <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:10px 12px;border-radius:6px;font-size:12px;line-height:1.5;">
                                        OAuth is not yet configured at the code level. Edit <code>wp-content/plugins/seo-ai-autopilot/config.php</code> to add your GCP Client ID and Client Secret.
                                    </div>
                                <?php elseif ($oauth_connected) : ?>
                                    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:10px 12px;margin-bottom:10px;">
                                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:2px;">Connected as</div>
                                        <div style="font-size:13px;color:#0f172a;font-weight:600;"><?php echo esc_html($oauth_email ?: '(unknown)'); ?></div>
                                    </div>
                                    <div style="margin-bottom:10px;">
                                        <label style="font-size:12px;color:#475569;font-weight:600;display:block;margin-bottom:4px;">GSC Property</label>
                                        <select id="seom-oauth-property" name="gsc_property_url" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;">
                                            <option value="<?php echo esc_attr($settings['gsc_property_url']); ?>">
                                                <?php echo $settings['gsc_property_url'] ? esc_html($settings['gsc_property_url']) : '— select after loading —'; ?>
                                            </option>
                                        </select>
                                        <button type="button" class="button button-small" id="seom-oauth-load-properties" style="margin-top:6px;">Load Properties</button>
                                    </div>
                                    <button type="button" class="button" id="seom-oauth-disconnect" style="color:#dc2626;border-color:#fca5a5;">Disconnect</button>
                                    <?php if ($gsc_auth_method !== 'oauth' && $sa_configured) : ?>
                                        <button type="button" class="button" id="seom-use-oauth" style="margin-left:6px;">Use This Method</button>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <button type="button" class="button button-primary" id="seom-oauth-connect" style="background:#4285f4;border-color:#4285f4;">
                                        <span class="dashicons dashicons-google" style="vertical-align:middle;margin-top:3px;"></span>
                                        Connect to Google Search Console
                                    </button>
                                <?php endif; ?>
                            </div>

                            <!-- ── Card 2: Service Account (Advanced) ── -->
                            <div style="border:1px solid #e2e8f0;border-radius:10px;padding:18px;background:#fff;<?php echo $gsc_auth_method === 'service_account' ? 'box-shadow:0 0 0 2px #3b82f6;' : ''; ?>">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                    <div style="font-weight:700;font-size:15px;color:#1e293b;">
                                        Service Account
                                        <span style="font-size:10px;font-weight:600;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:10px;margin-left:6px;">ADVANCED</span>
                                    </div>
                                    <?php if ($gsc_auth_method === 'service_account') : ?>
                                        <span style="font-size:10px;font-weight:600;background:#dcfce7;color:#166534;padding:3px 8px;border-radius:10px;">ACTIVE</span>
                                    <?php endif; ?>
                                </div>
                                <p style="margin:0 0 14px;font-size:12px;color:#64748b;line-height:1.5;">Server-to-server auth using a Google service account JSON key. Best for agencies managing many sites.</p>

                                <label style="font-size:12px;color:#475569;font-weight:600;display:block;margin-bottom:4px;">Service Account JSON</label>
                                <textarea name="gsc_credentials_json" rows="4" style="font-family:monospace;font-size:11px;width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;"><?php
                                    $json = $settings['gsc_credentials_json'];
                                    if ($json) {
                                        $parsed = json_decode($json, true);
                                        if ($parsed && !empty($parsed['private_key'])) {
                                            $parsed['private_key'] = '-----REDACTED-----';
                                            echo esc_textarea(json_encode($parsed, JSON_PRETTY_PRINT));
                                        } else { echo esc_textarea($json); }
                                    }
                                ?></textarea>
                                <?php if ($sa_configured) : ?>
                                    <div style="font-size:11px;color:#16a34a;margin-top:4px;">&#10003; Credentials saved. Paste new JSON to replace.</div>
                                <?php else : ?>
                                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;">No credentials configured yet.</div>
                                <?php endif; ?>

                                <?php if ($sa_configured && $oauth_connected && $gsc_auth_method !== 'service_account') : ?>
                                    <button type="button" class="button" id="seom-use-service-account" style="margin-top:10px;">Use This Method</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>GSC Property URL</label>
                            <div class="seom-field-desc">For service account or as a fallback. The OAuth dropdown above sets this automatically.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="text" name="gsc_property_url" value="<?php echo esc_attr($settings['gsc_property_url']); ?>" placeholder="https://www.example.com" />
                            <div class="seom-field-help">URL-prefix: <code>https://www.example.com</code> &nbsp;|&nbsp; Domain: <code>sc-domain:example.com</code></div>
                            <div style="margin-top:8px;">
                                <button type="button" class="button" id="seom-test-gsc">Test Connection</button>
                                <span id="seom-test-result" style="margin-left:8px; font-size:13px;"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Processing Section -->
                <div class="seom-form-section">
                    <div class="seom-form-section-header">Processing</div>
                    <div class="seom-form-section-desc">Control how the automated refresh system operates.</div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>System Enabled</label>
                            <div class="seom-field-desc">Master switch for all automated cron processing.</div>
                        </div>
                        <div class="seom-form-field">
                            <label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?> /> Enable automated processing</label>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Dry Run Mode</label>
                            <div class="seom-field-desc">Safe mode for testing. Scores and queues pages but does not modify any content.</div>
                        </div>
                        <div class="seom-form-field">
                            <label><input type="checkbox" name="dry_run" value="1" <?php checked($settings['dry_run']); ?> /> Analysis only — no content changes</label>
                            <div class="seom-field-help">Recommended when first setting up. Disable once you're confident in the queue results.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Daily Refresh Limit</label>
                            <div class="seom-field-desc">Base number of pages to refresh per day.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="number" name="daily_limit" value="<?php echo intval($settings['daily_limit']); ?>" min="1" max="100" />
                            <div class="seom-field-help">This is the baseline daily limit. In adaptive/burst modes, the system may increase this up to the maximum below.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Limit Mode</label>
                            <div class="seom-field-desc">How the daily limit interacts with your goals.</div>
                        </div>
                        <div class="seom-form-field">
                            <?php $limit_mode = $settings['limit_mode'] ?? 'fixed'; ?>
                            <label style="display:block; padding:6px 0;"><input type="radio" name="limit_mode" value="fixed" <?php checked($limit_mode, 'fixed'); ?> /> <strong>Fixed</strong> — Always use the daily limit above. Goals show warnings if capacity is insufficient.</label>
                            <label style="display:block; padding:6px 0;"><input type="radio" name="limit_mode" value="adaptive" <?php checked($limit_mode, 'adaptive'); ?> /> <strong>Goal-Adaptive</strong> — Auto-increase the daily limit (up to the max) when goals need more capacity. Decreases back to base when goals are on track.</label>
                            <label style="display:block; padding:6px 0;"><input type="radio" name="limit_mode" value="burst" <?php checked($limit_mode, 'burst'); ?> /> <strong>Burst on Demand</strong> — Use the base limit normally, but burst to 1.5x (up to the max) when any goal falls behind schedule.</label>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Maximum Daily Limit</label>
                            <div class="seom-field-desc">Hard ceiling for adaptive/burst modes.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="number" name="limit_max" value="<?php echo intval($settings['limit_max'] ?? $settings['daily_limit']); ?>" min="1" max="200" />
                            <div class="seom-field-help">The system will never exceed this number regardless of goal urgency. Factor in API cost — each full refresh uses ~5 API calls. 50 max = ~250 API calls/day.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Cooldown Period (days)</label>
                            <div class="seom-field-desc">Minimum days between refreshes for the same page.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="number" name="cooldown_days" value="<?php echo intval($settings['cooldown_days']); ?>" min="14" max="365" />
                            <div class="seom-field-help">Prevents refreshing the same page too frequently. 60-90 days gives Google time to re-evaluate after changes.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Monitored Post Types</label>
                            <div class="seom-field-desc">Which content types to track and refresh.</div>
                        </div>
                        <div class="seom-form-field">
                            <div class="seom-checkbox-group">
                                <label><input type="checkbox" name="process_post_types[]" value="product" <?php checked(in_array('product', $settings['process_post_types'])); ?> /> Products</label>
                                <label><input type="checkbox" name="process_post_types[]" value="post" <?php checked(in_array('post', $settings['process_post_types'])); ?> /> Blog Posts
                                    <?php if (!SEOM_Blog_Refresher::is_available()) : ?><span style="color:#b45309;"> — Blog Writer API key not configured</span><?php endif; ?>
                                </label>
                                <label><input type="checkbox" name="process_post_types[]" value="page" <?php checked(in_array('page', $settings['process_post_types'])); ?> /> Pages <span style="color:#94a3b8;">(GSC metrics only — no automated refresh)</span></label>
                            </div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Notification Email</label>
                            <div class="seom-field-desc">Receives daily summaries and per-refresh notifications.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="email" name="notify_email" value="<?php echo esc_attr($settings['notify_email']); ?>" />
                        </div>
                    </div>
                </div>

                <!-- Exclusions Section -->
                <div class="seom-form-section">
                    <div class="seom-form-section-header">Exclusions</div>
                    <div class="seom-form-section-desc">Pages matching these rules are tracked in GSC but never auto-refreshed. New content (&lt;90 days old) is also excluded automatically.</div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Exclude Post IDs</label>
                            <div class="seom-field-desc">Individual posts/products to never refresh.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="text" name="exclude_post_ids" value="<?php echo esc_attr($settings['exclude_post_ids']); ?>" placeholder="123, 456, 789" />
                            <div class="seom-field-help">Comma-separated WordPress post IDs.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Exclude Categories</label>
                            <div class="seom-field-desc">Entire categories to skip.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="text" name="exclude_categories" value="<?php echo esc_attr($settings['exclude_categories']); ?>" placeholder="practice-tests, free-courses" />
                            <div class="seom-field-help">Comma-separated category slugs. Works for both post categories and WooCommerce product categories.</div>
                        </div>
                    </div>
                </div>

                <!-- Category Thresholds Section -->
                <div class="seom-form-section">
                    <div class="seom-form-section-header">Category Thresholds</div>
                    <div class="seom-form-section-desc">These values control how pages are classified into performance categories during daily analysis. Categories are evaluated in order: A &rarr; B &rarr; C &rarr; F &rarr; D &rarr; E. First match wins.</div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Cat A — Ghost Pages</label>
                            <div class="seom-field-desc">Max impressions to be classified as a ghost page. Pages with zero visibility in search results.</div>
                        </div>
                        <div class="seom-form-field">
                            <div class="seom-field-inline">
                                <span>Max impressions:</span>
                                <input type="number" name="ghost_threshold" value="<?php echo intval($settings['ghost_threshold']); ?>" min="0" />
                            </div>
                            <div class="seom-field-help">Default: 0. Set to 1-2 to also catch pages with negligible impressions. Refresh type: Full.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Cat B — CTR Fix</label>
                            <div class="seom-field-desc">Pages ranking well but with CTR below 50% of the position benchmark. Needs better title/meta.</div>
                        </div>
                        <div class="seom-form-field">
                            <div class="seom-field-inline">
                                <span>Min impressions:</span>
                                <input type="number" name="ctr_fix_min_impressions" value="<?php echo intval($settings['ctr_fix_min_impressions']); ?>" min="0" />
                            </div>
                            <div class="seom-field-help">Pages must have at least this many impressions AND position &le;15 AND CTR below 50% of expected for their position. Refresh type: Meta-only (escalates to Full if meta fix didn't help).</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Cat C — Near Wins</label>
                            <div class="seom-field-desc">Page 2 rankings close to breaking into page 1. Highest ROI for content refresh.</div>
                        </div>
                        <div class="seom-form-field">
                            <div class="seom-field-inline">
                                <span>Position range:</span>
                                <input type="number" name="near_win_min_pos" value="<?php echo floatval($settings['near_win_min_pos']); ?>" step="1" min="1" style="width:70px;" />
                                <span>to</span>
                                <input type="number" name="near_win_max_pos" value="<?php echo floatval($settings['near_win_max_pos']); ?>" step="1" min="1" style="width:70px;" />
                            </div>
                            <div class="seom-field-inline" style="margin-top:8px;">
                                <span>Min impressions:</span>
                                <input type="number" name="near_win_min_impressions" value="<?php echo intval($settings['near_win_min_impressions']); ?>" min="0" />
                            </div>
                            <div class="seom-field-help">Default: position 11-20 with 50+ impressions. Refresh type: Full.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Cat F — Buried Potential</label>
                            <div class="seom-field-desc">Page 3+ with meaningful impressions. Google considers it relevant but ranks it poorly.</div>
                        </div>
                        <div class="seom-form-field">
                            <div class="seom-field-inline">
                                <span>Min impressions:</span>
                                <input type="number" name="buried_min_impressions" value="<?php echo intval($settings['buried_min_impressions']); ?>" min="0" />
                            </div>
                            <div class="seom-field-help">Pages with position &gt;20 and at least this many impressions. Refresh type: Full.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Cat D — Declining</label>
                            <div class="seom-field-desc">Pages that have lost significant traffic compared to the prior period.</div>
                        </div>
                        <div class="seom-form-field">
                            <div class="seom-field-inline">
                                <span>Click decline threshold:</span>
                                <input type="number" name="decline_threshold_pct" value="<?php echo floatval($settings['decline_threshold_pct']); ?>" step="1" min="0" />
                                <span>%</span>
                            </div>
                            <div class="seom-field-help">If clicks dropped by this percentage vs. prior 28 days, flag as declining. Default: 30%. Refresh type: Full.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Cat E — Visible but Ignored</label>
                            <div class="seom-field-desc">High impressions but almost no clicks. People see it but don't click.</div>
                        </div>
                        <div class="seom-form-field">
                            <div class="seom-field-inline">
                                <span>Min impressions:</span>
                                <input type="number" name="visible_min_impressions" value="<?php echo intval($settings['visible_min_impressions']); ?>" min="0" />
                            </div>
                            <div class="seom-field-inline" style="margin-top:8px;">
                                <span>Max clicks:</span>
                                <input type="number" name="visible_max_clicks" value="<?php echo intval($settings['visible_max_clicks']); ?>" min="0" />
                            </div>
                            <div class="seom-field-help">Pages with high visibility but near-zero engagement. Default: 500+ impressions, &le;10 clicks. Refresh type: Meta-only (escalates to Full).</div>
                        </div>
                    </div>
                </div>

                <!-- Keyword Gap Section -->
                <div class="seom-form-section">
                    <div class="seom-form-section-header">Keyword Gap Settings</div>
                    <div class="seom-form-section-desc">Controls for competitive keyword import, cooldown tracking, and AI auto-tagging.</div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Keyword Cooldown (days)</label>
                            <div class="seom-field-desc">How long before a used keyword becomes available again.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="number" name="gap_keyword_cooldown" value="<?php echo intval($settings['gap_keyword_cooldown']); ?>" min="1" />
                            <div class="seom-field-help">Prevents the same keyword from being targeted in multiple blog posts too close together. Default: 90 days.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Seed Categories</label>
                            <div class="seom-field-desc">Foundation categories for AI auto-tagging of imported keywords.</div>
                        </div>
                        <div class="seom-form-field">
                            <textarea name="gap_seed_categories" rows="8" style="font-family:monospace;font-size:12px;" placeholder="AWS Certifications&#10;Azure Certifications&#10;Cisco Networking&#10;CompTIA Security+&#10;Linux Administration"><?php echo esc_textarea($settings['gap_seed_categories']); ?></textarea>
                            <div class="seom-field-help">One category per line. AI will use these first and only create new categories if a keyword doesn't fit any existing one.</div>
                        </div>
                    </div>
                </div>

                <!-- Competitive Research Section -->
                <div class="seom-form-section">
                    <div class="seom-form-section-header">Competitive Research (AI Web Search)</div>
                    <div class="seom-form-section-desc">When enabled, the refresh pipeline performs a web search step before content generation — analyzing what top-ranking competitors cover for the target keywords. This produces more strategically targeted content at minimal additional cost (~$0.01/refresh).</div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Enable Research</label>
                            <div class="seom-field-desc">Add competitive intelligence to content refreshes.</div>
                        </div>
                        <div class="seom-form-field">
                            <label><input type="checkbox" name="research_enabled" value="1" <?php checked($settings['research_enabled']); ?> /> Enable competitive research before content generation</label>
                            <div class="seom-field-help">Adds a "Step 0" to the refresh pipeline that researches what competitors rank for your target queries. Results are fed into the content, outline, and meta prompts. If disabled or if the API call fails, the pipeline works normally without research.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Research Provider</label>
                            <div class="seom-field-desc">Which API to use for web search.</div>
                        </div>
                        <div class="seom-form-field">
                            <select name="research_provider">
                                <option value="openai" <?php selected($settings['research_provider'], 'openai'); ?>>OpenAI (gpt-4.1-mini with web search) &mdash; uses your existing API key</option>
                                <option value="perplexity" <?php selected($settings['research_provider'], 'perplexity'); ?>>Perplexity Sonar &mdash; purpose-built for search, best quality/cost ratio</option>
                            </select>
                            <div class="seom-field-help">
                                <strong>OpenAI:</strong> Uses gpt-4.1-mini with the Responses API web_search tool. ~$0.03/search. Uses your existing OpenAI API key.<br>
                                <strong>Perplexity:</strong> Purpose-built search AI. ~$0.006/search. Better factuality benchmarks. Requires separate API key from <a href="https://www.perplexity.ai/account/api/keys" target="_blank">perplexity.ai</a>.
                            </div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Perplexity API Key</label>
                            <div class="seom-field-desc">Required only if using Perplexity as research provider.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="password" name="perplexity_api_key" value="<?php echo esc_attr($settings['perplexity_api_key']); ?>" placeholder="pplx-..." style="font-family:monospace;" />
                            <div class="seom-field-help">Get your API key at <a href="https://www.perplexity.ai/account/api/keys" target="_blank">perplexity.ai/account/api/keys</a>. No subscription required &mdash; just add credits ($5-10 lasts months at this usage level).</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Research Model</label>
                            <div class="seom-field-desc">Override the default model for research calls.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="text" name="research_model" value="<?php echo esc_attr($settings['research_model']); ?>" placeholder="Leave blank for default (recommended)" />
                            <div class="seom-field-help">Defaults: OpenAI = gpt-4.1-mini, Perplexity = sonar. Only change if you know what you're doing.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Research Categories</label>
                            <div class="seom-field-desc">Which page categories trigger research.</div>
                        </div>
                        <div class="seom-form-field">
                            <?php
                            $enabled_cats = array_map('trim', explode(',', $settings['research_categories']));
                            $all_cats = ['A' => 'Ghost Pages', 'B' => 'CTR Fix', 'C' => 'Near Wins', 'D' => 'Declining', 'E' => 'Visible/Ignored', 'F' => 'Buried Potential'];
                            ?>
                            <div class="seom-checkbox-group">
                                <?php foreach ($all_cats as $cat_key => $cat_label) : ?>
                                <label><input type="checkbox" class="seom-research-cat" value="<?php echo $cat_key; ?>" <?php checked(in_array($cat_key, $enabled_cats)); ?> /> <?php echo $cat_key; ?> &mdash; <?php echo $cat_label; ?></label>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="research_categories" id="seom-research-categories" value="<?php echo esc_attr($settings['research_categories']); ?>" />
                            <div class="seom-field-help">Research costs ~$0.01-0.03 per call. Skip categories where competitive research adds less value (e.g., Ghost pages have no ranking data to research against). Default: B, C, D, E, F.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Minimum Impressions</label>
                            <div class="seom-field-desc">Only research pages with at least this many impressions.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="number" name="research_min_impressions" value="<?php echo intval($settings['research_min_impressions']); ?>" min="0" />
                            <div class="seom-field-help">0 = research all pages in enabled categories. Set to 50+ to skip low-visibility pages where competitive data is sparse. Pages with more impressions have more search query data for better research.</div>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Minimum Position</label>
                            <div class="seom-field-desc">Only research pages ranking at this position or worse.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="number" name="research_min_position" value="<?php echo intval($settings['research_min_position']); ?>" min="0" />
                            <div class="seom-field-help">0 = research at any position. Set to 5 to only research pages ranking 5th or lower (skip pages already dominating). Research is most valuable for pages that need to understand what competitors are doing better.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Authority / AI Visibility (phase 1a) ── -->
            <div class="seom-form-section">
                <div class="seom-form-section-header">Authority &amp; AI Visibility</div>
                <div class="seom-form-section-desc">Drives the visible publisher byline, the <code>Organization</code> + <code>knowsAbout</code> JSON-LD emitted on every post, the AI-bot robots.txt block, and <code>/llms.txt</code>.</div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="authority_org_name">Organization Name</label>
                        <div class="seom-field-desc">Used as the publisher name in schema and the trailing line of the byline.</div>
                    </div>
                    <div class="seom-form-field">
                        <input type="text" name="authority_org_name" id="authority_org_name" value="<?php echo esc_attr($settings['authority_org_name'] ?? ''); ?>" style="width:100%;max-width:520px;" />
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="authority_org_url">Organization URL</label>
                        <div class="seom-field-desc">Used as the canonical <code>url</code> of the Organization entity.</div>
                    </div>
                    <div class="seom-form-field">
                        <input type="url" name="authority_org_url" id="authority_org_url" value="<?php echo esc_attr($settings['authority_org_url'] ?? home_url('/')); ?>" style="width:100%;max-width:520px;" />
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="authority_founding_date">Founding Date</label>
                        <div class="seom-field-desc">ISO-8601 date. Emitted as <code>foundingDate</code> on the Organization. Also surfaced in <code>/llms.txt</code>.</div>
                    </div>
                    <div class="seom-form-field">
                        <input type="date" name="authority_founding_date" id="authority_founding_date" value="<?php echo esc_attr($settings['authority_founding_date'] ?? ''); ?>" />
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="authority_about_blurb">About Blurb</label>
                        <div class="seom-field-desc">2-3 sentences. Shown under the byline on every post, in <code>/llms.txt</code>, and as Organization <code>description</code>.</div>
                    </div>
                    <div class="seom-form-field">
                        <textarea name="authority_about_blurb" id="authority_about_blurb" rows="3" style="width:100%;max-width:720px;"><?php echo esc_textarea($settings['authority_about_blurb'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="authority_same_as">Organization <code>sameAs</code> URLs</label>
                        <div class="seom-field-desc">One URL per line. LinkedIn company page, About page, Crunchbase, X profile, etc. — anything that disambiguates the Organization entity.</div>
                    </div>
                    <div class="seom-form-field">
                        <textarea name="authority_same_as" id="authority_same_as" rows="5" style="width:100%;max-width:720px;font-family:monospace;font-size:12px;"><?php echo esc_textarea($settings['authority_same_as'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="authority_knows_about"><code>knowsAbout</code> topics</label>
                        <div class="seom-field-desc">One topic per line. Emitted as the Organization's <code>knowsAbout</code> array — AI engines read this to map your business to topical authority areas. Use broad canonical names ("CompTIA Certifications", not "CompTIA Security+ SY0-701").</div>
                    </div>
                    <div class="seom-form-field">
                        <textarea name="authority_knows_about" id="authority_knows_about" rows="8" style="width:100%;max-width:720px;font-family:monospace;font-size:12px;"><?php echo esc_textarea($settings['authority_knows_about'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="authority_byline_map">Sub-team byline map (JSON)</label>
                        <div class="seom-field-desc">JSON object: <code>{ "Team Name": ["tag", "tag", ...] }</code>. The post's gap_category is matched against these tags (case-insensitive substring) to pick the visible byline and the schema's <code>author</code> sub-organization. The team with an empty tag list becomes the catch-all fallback.</div>
                    </div>
                    <div class="seom-form-field">
                        <textarea name="authority_byline_map" id="authority_byline_map" rows="10" style="width:100%;max-width:720px;font-family:monospace;font-size:11px;"><?php
                            $bm = $settings['authority_byline_map'] ?? '';
                            if (is_array($bm)) $bm = wp_json_encode($bm, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            else {
                                $decoded = json_decode((string) $bm, true);
                                if (is_array($decoded)) $bm = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            }
                            echo esc_textarea((string) $bm);
                        ?></textarea>
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="ai_bots_allow">Allow AI crawlers</label>
                        <div class="seom-field-desc">Appends explicit <code>Allow: /</code> directives for GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended, Applebot-Extended, CCBot, and others to <code>robots.txt</code>. Uncheck to fall back to default WP behavior.</div>
                    </div>
                    <div class="seom-form-field">
                        <label><input type="checkbox" name="ai_bots_allow" id="ai_bots_allow" value="1" <?php checked(!empty($settings['ai_bots_allow'])); ?> /> Enabled</label>
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="llms_txt_enabled">Serve <code>/llms.txt</code></label>
                        <div class="seom-field-desc">Generates a markdown manifest at <code>/llms.txt</code> pointing AI engines at your canonical hubs (home, glossary, course catalog, publisher about).</div>
                    </div>
                    <div class="seom-form-field">
                        <label><input type="checkbox" name="llms_txt_enabled" id="llms_txt_enabled" value="1" <?php checked(!empty($settings['llms_txt_enabled'])); ?> /> Enabled</label>
                        <div class="seom-field-help" style="margin-top:6px;"><a href="<?php echo esc_url(home_url('/llms.txt')); ?>" target="_blank" rel="noopener">Preview /llms.txt</a></div>
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="llms_txt_extra_lines">Extra <code>/llms.txt</code> content</label>
                        <div class="seom-field-desc">Free-form markdown appended to the generated manifest (e.g., additional canonical hubs, policy notes).</div>
                    </div>
                    <div class="seom-form-field">
                        <textarea name="llms_txt_extra_lines" id="llms_txt_extra_lines" rows="4" style="width:100%;max-width:720px;font-family:monospace;font-size:12px;"><?php echo esc_textarea($settings['llms_txt_extra_lines'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Glossary integration (phase 3a) -->
                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="glossary_links_enabled">Glossary auto-linking</label>
                        <div class="seom-field-desc">When a new blog or refresh runs, the writer is given the glossary terms most likely to appear in the post (sourced from the <code>ec_it_glossary</code> table). The first natural mention of each is linked to its definition URL. <code>DefinedTermSet</code> schema on the glossary page itself is theme-owned and always on regardless of this toggle.</div>
                    </div>
                    <div class="seom-form-field">
                        <label><input type="checkbox" name="glossary_links_enabled" id="glossary_links_enabled" value="1" <?php checked(!empty($settings['glossary_links_enabled'])); ?> /> Enabled</label>
                        <?php if (function_exists('seom_get_glossary_index')) {
                            $count = count(seom_get_glossary_index());
                            echo '<div class="seom-field-help" style="margin-top:6px;">Index cached: <strong>' . number_format($count) . '</strong> terms loaded. <a href="#" id="seom-glossary-rebuild">Rebuild cache</a></div>';
                        } ?>
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="glossary_max_links_per_post">Max glossary links per post</label>
                        <div class="seom-field-desc">Hard cap passed to the writer. Set to 0-15. Default 5. Too high creates link-farm pages; too low under-uses the moat. Most posts naturally fit 3-7 links.</div>
                    </div>
                    <div class="seom-form-field">
                        <input type="number" name="glossary_max_links_per_post" id="glossary_max_links_per_post" value="<?php echo intval($settings['glossary_max_links_per_post'] ?? 5); ?>" min="0" max="15" />
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label for="glossary_url">Glossary URL override</label>
                        <div class="seom-field-desc">Leave blank to use <code><?php echo esc_html(home_url('/it-glossary/')); ?></code>. Override only if the glossary is moved to a different slug.</div>
                    </div>
                    <div class="seom-form-field">
                        <input type="url" name="glossary_url" id="glossary_url" value="<?php echo esc_attr($settings['glossary_url'] ?? ''); ?>" placeholder="<?php echo esc_attr(home_url('/it-glossary/')); ?>" style="width:100%;max-width:520px;" />
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label>Migrate existing post links</label>
                        <div class="seom-field-desc">Scan every published post for old glossary links (bare <code>#term-…</code> anchors that don't include the letter/pagenum) and rewrite them in-place to the current canonical paginated URL for each term. Run this once after rebuilding the cache so historical posts pick up the fix.</div>
                    </div>
                    <div class="seom-form-field">
                        <button type="button" class="button" id="seom-glossary-migrate-dry">Dry-run (count only)</button>
                        <button type="button" class="button button-primary" id="seom-glossary-migrate-go" style="margin-left:6px;">Run migration</button>
                        <div id="seom-glossary-migrate-status" style="font-size:12px;color:#475569;margin-top:8px;min-height:18px;"></div>
                    </div>
                </div>
            </div>

            <!-- Cybersecurity Promo Tab Section -->
            <div class="seom-form" style="margin-top:24px;">
                <div class="seom-form-section">
                    <div class="seom-form-section-header">Cybersecurity Promo Tab</div>
                    <div class="seom-form-section-desc">A green slide-out tab fixed to the right edge of the site (desktop only, hidden on cart &amp; checkout). Links visitors to a course or landing page.</div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Show Tab</label>
                            <div class="seom-field-desc">Turn the slide-out tab on or off across the site.</div>
                        </div>
                        <div class="seom-form-field">
                            <label><input type="checkbox" name="cyber_tab_enabled" value="1" <?php checked(!empty($settings['cyber_tab_enabled'])); ?> /> Display the promo tab</label>
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Tab Title</label>
                            <div class="seom-field-desc">The text shown on the tab.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="text" name="cyber_tab_title" value="<?php echo esc_attr($settings['cyber_tab_title'] ?? ''); ?>" placeholder="Cybersecurity In Focus - Free Trial" />
                        </div>
                    </div>

                    <div class="seom-form-row">
                        <div class="seom-form-label">
                            <label>Link URL</label>
                            <div class="seom-field-desc">Where the tab sends visitors when clicked.</div>
                        </div>
                        <div class="seom-form-field">
                            <input type="url" name="cyber_tab_url" value="<?php echo esc_attr($settings['cyber_tab_url'] ?? ''); ?>" placeholder="https://www.ituonline.com/courses/free-courses/..." style="width:100%; max-width:600px;" />
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top:20px; padding:16px 24px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; display:flex; align-items:center; gap:12px;">
                <button type="submit" class="button button-primary" style="padding:6px 24px;">Save Settings</button>
                <span id="seom-save-status" style="font-size:13px;"></span>
            </div>
        </form>

        <!-- ── Maintenance / one-shot tools ── -->
        <div class="seom-form" style="margin-top:24px;">
            <div class="seom-form-section">
                <div class="seom-form-section-header">Maintenance</div>
                <div class="seom-form-section-desc">One-shot recovery and backfill tools. Use only when needed.</div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label>Backfill Missing SEO Titles</label>
                        <div class="seom-field-desc">Find posts that have <strong>already been refreshed at least once</strong> but still have no custom <code>rank_math_title</code> meta, and queue them for a meta-only refresh. Recovers from the historical bug where titles longer than 70 characters were silently dropped. Never-refreshed posts are excluded — they'll get a title on their first normal refresh.</div>
                    </div>
                    <div class="seom-form-field">
                        <button type="button" class="button" id="seom-backfill-seo-titles">Find &amp; Queue Missing Titles</button>
                        <span id="seom-backfill-status" style="font-size:12px; margin-left:8px;"></span>
                        <div class="seom-field-help">Skips posts already in the queue. After running, click <strong>Process Queue (Background)</strong> on the Queue tab to apply the fixes.</div>
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label>Surgical TL;DR Insertion</label>
                        <div class="seom-field-desc">
                            <strong>~10× cheaper than a full refresh.</strong> Generates the "Quick Answer" block from each post's existing content and prepends it — no body rewrite. Activates the speakable schema automatically (the renderer scans for <code>.itu-tldr</code> markup).<br><br>
                            Sort by impressions or clicks so you process highest-traffic posts first, pick a subset per day, and pace yourself. Each insertion costs ~$0.003 in AI calls. Skipped automatically if a post already has the block.
                        </div>
                    </div>
                    <div class="seom-form-field">
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
                            <select id="seom-surg-filter" style="padding:4px 8px;">
                                <option value="missing_tldr" selected>Missing TL;DR only</option>
                                <option value="has_tldr">Already has TL;DR</option>
                                <option value="all">All published posts</option>
                            </select>
                            <select id="seom-surg-sort" style="padding:4px 8px;">
                                <option value="impressions_desc" selected>Impressions (high → low)</option>
                                <option value="clicks_desc">Clicks (high → low)</option>
                                <option value="position_asc">Best position (1 → 999)</option>
                                <option value="modified_desc">Recently modified</option>
                                <option value="modified_asc">Oldest first</option>
                            </select>
                            <button type="button" class="button" id="seom-surg-refresh">Refresh list</button>
                            <span style="font-size:12px;color:#64748b;">Total: <strong id="seom-surg-total">—</strong></span>
                        </div>

                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">
                            <label style="font-size:13px;font-weight:500;"><input type="checkbox" id="seom-surg-select-all" style="margin-right:4px;" /> Select all on page</label>
                            <span style="font-size:12px;color:#64748b;">Selected: <strong id="seom-surg-selected-count">0</strong></span>
                            <button type="button" class="button button-primary" id="seom-surg-run-selected" style="margin-left:auto;background:#0891b2;border-color:#0891b2;">Run TL;DR for selected</button>
                        </div>

                        <div id="seom-surg-status" style="font-size:12px;color:#475569;margin-bottom:8px;min-height:18px;"></div>

                        <table class="wp-list-table widefat striped" id="seom-surg-table" style="display:none;">
                            <thead><tr>
                                <th style="width:30px;"><input type="checkbox" id="seom-surg-header-cb" /></th>
                                <th>Title</th>
                                <th style="width:100px;text-align:right;">Impressions</th>
                                <th style="width:80px;text-align:right;">Clicks</th>
                                <th style="width:80px;text-align:right;">Position</th>
                                <th style="width:110px;">Modified</th>
                                <th style="width:90px;text-align:center;">TL;DR</th>
                                <th style="width:130px;">Action</th>
                            </tr></thead>
                            <tbody id="seom-surg-tbody"></tbody>
                        </table>
                        <div id="seom-surg-pagination" style="margin-top:8px;display:flex;gap:4px;flex-wrap:wrap;"></div>
                        <div id="seom-surg-empty" style="display:none;color:#94a3b8;padding:14px;">No posts match this filter.</div>
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label>Backfill: Refresh All Posts Under Current Rules</label>
                        <div class="seom-field-desc">
                            Queue every published post for a <strong>full refresh</strong> so they pick up the writer rules added in phases 1a/1b/2/3a — TL;DR, At-a-Glance, type-specific block (how-to / comparison / concept / career), cluster pillar links, and glossary auto-linking.<br><br>
                            Pick a filter, run <strong>Dry-run</strong> first to see the count, then <strong>Queue</strong> to enqueue. The existing daily limit and Process Queue button on the Queue tab run them at the configured pace.
                        </div>
                    </div>
                    <div class="seom-form-field">
                        <select id="seom-backfill-all-filter" style="padding:4px 8px;">
                            <option value="missing_tldr">Missing the new TL;DR block (best default)</option>
                            <option value="never">Never refreshed</option>
                            <option value="30d">Not refreshed in 30 days</option>
                            <option value="90d">Not refreshed in 90 days</option>
                            <option value="all">All published posts</option>
                        </select>
                        <button type="button" class="button" id="seom-backfill-all-dry" style="margin-left:6px;">Dry-run (count)</button>
                        <button type="button" class="button button-primary" id="seom-backfill-all-go" style="margin-left:6px;">Queue selected</button>
                        <div id="seom-backfill-all-status" style="font-size:12px;color:#475569;margin-top:8px;min-height:18px;"></div>
                    </div>
                </div>

                <div class="seom-form-row">
                    <div class="seom-form-label">
                        <label>Audit SEO Titles With Numbers</label>
                        <div class="seom-field-desc">
                            Finds blog posts whose current SEO title starts with a number (e.g. <em>"5 Ways to Improve Your Security Posture"</em>) and re-runs <strong>only</strong> the SEO title generator under the new accuracy rules — numbers must match a real countable structure in the body or be removed.
                            <br><br>
                            <strong>What this does NOT touch:</strong> the <code>last_page_refresh</code> timestamp, refresh history, focus keyword, meta description, or post body. Only <code>rank_math_title</code> is updated.
                        </div>
                    </div>
                    <div class="seom-form-field">
                        <button type="button" class="button" id="seom-audit-titles-find">Find Candidates</button>
                        <button type="button" class="button button-primary" id="seom-audit-titles-apply" style="margin-left:6px; display:none;">Apply Updates</button>
                        <button type="button" class="button" id="seom-audit-titles-stop" style="margin-left:6px; display:none; color:#dc2626;">Stop</button>
                        <div id="seom-audit-titles-status" style="font-size:12px; margin-top:8px;"></div>
                        <div id="seom-audit-titles-progress" style="display:none; margin-top:8px; font-size:12px; color:#64748b;"></div>
                        <div id="seom-audit-titles-log" style="display:none; margin-top:10px; max-height:300px; overflow-y:auto; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; font-size:11px; font-family:Menlo,Consolas,monospace;"></div>
                    </div>
                </div>
            </div>
        </div>

        <?php
        // Featured Product template — user-editable HTML used by the
        // [itu_featured_product] shortcode. Stored in its own option so
        // refresh-safe shortcode rendering doesn't need to load the whole
        // seom_settings array.
        $fp_template = function_exists('seom_get_featured_product_template')
            ? seom_get_featured_product_template()
            : '';
        ?>
        <div class="seom-section-header" style="margin-top:24px;">Featured Product Template</div>
        <p style="color:#64748b; font-size:13px; margin-bottom:8px;">
            HTML template used when <code>[itu_featured_product id="…"]</code> renders inside a blog post.
            Blog Queue and the "Featured" button on the Indexed Pages tab attach a WooCommerce product to a post;
            during every refresh the shortcode is re-injected into the content.
            Placeholders:
            <code>{title}</code>, <code>{short_description}</code>, <code>{button_url}</code>,
            <code>{button_label}</code>, <code>{button_target}</code>, <code>{permalink}</code>, <code>{udemy_url}</code>.
        </p>
        <p style="color:#64748b; font-size:12px; margin:0 0 8px;">
            Inside the form there is a <strong>Featured Product Template</strong> field bound to
            <code>seom_settings_form</code>; changes are saved with the main Save button below.
        </p>
        <textarea id="seom-featured-product-template" form="seom-settings-form" name="featured_product_template" rows="14" style="width:100%; font-family:Menlo,Consolas,monospace; font-size:12px; padding:12px; border:1px solid #c3c4c7; border-radius:6px;"><?php echo esc_textarea($fp_template); ?></textarea>
        <p style="margin-top:6px;">
            <button type="button" class="button" id="seom-featured-template-reset">Reset to Default</button>
            <span style="margin-left:12px; color:#94a3b8; font-size:12px;">Defaults match the dashed fuchsia Featured Product block that ships with the plugin.</span>
        </p>

        <div class="seom-section-header" style="margin-top:24px;">Scheduled Tasks (Cron)</div>
        <p style="color:#64748b; font-size:13px; margin-bottom:12px;">These tasks run automatically via WordPress cron. Times shown are when the next run is scheduled.</p>

        <table class="seom-table" id="seom-cron-table">
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
            // ── GSC OAuth: Connect ──
            $('#seom-oauth-connect').click(function() {
                var btn = $(this).prop('disabled', true).text('Redirecting…');
                $.post(ajaxurl, { action: 'seom_gsc_oauth_start', nonce: seom_nonce }, function(resp) {
                    if (resp.success && resp.data.authorize_url) {
                        window.location.href = resp.data.authorize_url;
                    } else {
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-google" style="vertical-align:middle;margin-top:3px;"></span> Connect to Google Search Console');
                        alert('Failed to start OAuth: ' + (resp.data || 'Unknown error'));
                    }
                }).fail(function() {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-google" style="vertical-align:middle;margin-top:3px;"></span> Connect to Google Search Console');
                    alert('Server error while starting OAuth.');
                });
            });

            // ── GSC OAuth: Disconnect ──
            $('#seom-oauth-disconnect').click(function() {
                if (!confirm('Disconnect from Google Search Console? The plugin will stop fetching GSC data until you reconnect or switch to a service account.')) return;
                var btn = $(this).prop('disabled', true).text('Disconnecting…');
                $.post(ajaxurl, { action: 'seom_gsc_oauth_disconnect', nonce: seom_nonce }, function(resp) {
                    if (resp.success) {
                        location.reload();
                    } else {
                        btn.prop('disabled', false).text('Disconnect');
                        alert('Failed: ' + (resp.data || 'Unknown error'));
                    }
                });
            });

            // ── GSC OAuth: Load list of accessible properties ──
            $('#seom-oauth-load-properties').click(function() {
                var btn = $(this).prop('disabled', true).text('Loading…');
                $.post(ajaxurl, { action: 'seom_gsc_oauth_list_properties', nonce: seom_nonce }, function(resp) {
                    btn.prop('disabled', false).text('Load Properties');
                    if (!resp.success) {
                        alert('Failed to load properties: ' + (resp.data || 'Unknown error'));
                        return;
                    }
                    var sites = resp.data.sites || [];
                    var $sel = $('#seom-oauth-property');
                    var current = $sel.val();
                    $sel.empty();
                    if (!sites.length) {
                        $sel.append('<option value="">(no properties found)</option>');
                        return;
                    }
                    sites.forEach(function(s) {
                        var opt = $('<option>').val(s.siteUrl).text(s.siteUrl + ' (' + (s.permissionLevel || 'unknown') + ')');
                        if (s.siteUrl === current) opt.prop('selected', true);
                        $sel.append(opt);
                    });
                });
            });

            // ── GSC: explicit "use this method" toggles when both are configured ──
            $('#seom-use-oauth').click(function() {
                var btn = $(this).prop('disabled', true);
                $.post(ajaxurl, { action: 'seom_gsc_set_auth_method', nonce: seom_nonce, method: 'oauth' }, function(resp) {
                    if (resp.success) location.reload();
                    else { btn.prop('disabled', false); alert(resp.data || 'Failed'); }
                });
            });
            $('#seom-use-service-account').click(function() {
                var btn = $(this).prop('disabled', true);
                $.post(ajaxurl, { action: 'seom_gsc_set_auth_method', nonce: seom_nonce, method: 'service_account' }, function(resp) {
                    if (resp.success) location.reload();
                    else { btn.prop('disabled', false); alert(resp.data || 'Failed'); }
                });
            });

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

            // ── Maintenance: Backfill Missing SEO Titles ──
            $('#seom-backfill-seo-titles').click(function() {
                var btn = $(this);
                var statusEl = $('#seom-backfill-status');
                btn.prop('disabled', true);
                statusEl.html('<span style="color:#64748b;">Counting posts with missing SEO titles…</span>');

                // Step 1: dry-run to get the count
                $.post(ajaxurl, { action: 'seom_backfill_seo_titles', nonce: seom_nonce, dry_run: 1 }, function(resp) {
                    if (!resp.success) {
                        statusEl.html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                        btn.prop('disabled', false);
                        return;
                    }
                    var count = resp.data.count;
                    if (count === 0) {
                        statusEl.html('<span style="color:#059669;">All tracked posts already have a custom SEO title set.</span>');
                        btn.prop('disabled', false);
                        return;
                    }
                    if (!confirm('Found ' + count + ' post(s) with no custom SEO title. Queue them all for a meta-only refresh? They will be processed in the background queue.')) {
                        statusEl.html('');
                        btn.prop('disabled', false);
                        return;
                    }
                    // Step 2: actually queue them
                    statusEl.html('<span style="color:#64748b;">Queueing ' + count + ' post(s)…</span>');
                    $.post(ajaxurl, { action: 'seom_backfill_seo_titles', nonce: seom_nonce }, function(resp2) {
                        btn.prop('disabled', false);
                        if (resp2.success) {
                            statusEl.html('<span style="color:#059669;">' + resp2.data.message + '</span>');
                        } else {
                            statusEl.html('<span style="color:#dc2626;">' + (resp2.data || 'Error') + '</span>');
                        }
                    });
                });
            });

            // ── Maintenance: Backfill all posts under current writer rules ──
            // Dry-run shows how many posts match the chosen filter; Queue
            // enqueues them at priority 50, refresh_type='full'. The existing
            // queue processor runs them under the configured daily_limit.
            function seomBackfillAll(applyMode) {
                var filter = $('#seom-backfill-all-filter').val();
                var $status = $('#seom-backfill-all-status');
                var $btn1 = $('#seom-backfill-all-dry').prop('disabled', true);
                var $btn2 = $('#seom-backfill-all-go').prop('disabled', true);
                var payload = { action: 'seom_backfill_all_posts', nonce: seom_nonce, filter: filter };
                if (!applyMode) payload.dry_run = 1;

                $status.html('<span style="color:#64748b;">' + (applyMode ? 'Queueing…' : 'Counting matches…') + '</span>');
                $.post(ajaxurl, payload, function(resp) {
                    $btn1.prop('disabled', false); $btn2.prop('disabled', false);
                    if (!resp.success) {
                        $status.html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                        return;
                    }
                    var d = resp.data;
                    if (d.dry_run) {
                        if (d.count === 0) {
                            $status.html('<span style="color:#059669;">No posts match this filter — nothing to queue.</span>');
                            return;
                        }
                        var preview = (d.sample || []).slice(0, 5).map(function(s) { return '#' + s.ID + ' ' + s.post_title; }).join('  ·  ');
                        $status.html('<span style="color:#b45309;">' + d.count + ' post(s) match. Sample: ' + (preview || '—') + '</span>');
                        return;
                    }
                    $status.html('<span style="color:#059669;">' + d.message + '</span>');
                });
            }

            $('#seom-backfill-all-dry').on('click', function() { seomBackfillAll(false); });
            $('#seom-backfill-all-go').on('click', function() {
                var filter = $('#seom-backfill-all-filter').val();
                if (!confirm('Queue every published post matching "' + filter + '" for a FULL refresh? They will be processed under the configured daily_limit.')) return;
                seomBackfillAll(true);
            });

            // ── Surgical TL;DR insertion ──
            // Stateful: surgState.page tracks the visible page,
            // surgState.selected is the set of selected post_ids ACROSS pages
            // (so selecting items on page 1, paginating to page 2, and clicking
            // Run still processes the page-1 selections). $rows is the most
            // recent server response payload used to look up titles when the
            // bulk run renders progress.
            var surgState = { page: 1, selected: {}, rowsById: {} };

            function escapeHtmlSurg(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }
            function fmtNum(n) {
                n = Number(n) || 0;
                return n.toLocaleString();
            }
            function updateSelectedCount() {
                $('#seom-surg-selected-count').text(Object.keys(surgState.selected).length);
            }
            function renderSurgTable(d) {
                var $body = $('#seom-surg-tbody').empty();
                $('#seom-surg-total').text(d.total != null ? fmtNum(d.total) : '—');
                surgState.rowsById = {};
                if (!d.rows || !d.rows.length) {
                    $('#seom-surg-table').hide();
                    $('#seom-surg-empty').show();
                    $('#seom-surg-pagination').empty();
                    return;
                }
                $('#seom-surg-empty').hide();
                d.rows.forEach(function(r) {
                    surgState.rowsById[r.post_id] = r;
                    var pid = r.post_id;
                    var checked = surgState.selected[pid] ? 'checked' : '';
                    var hasTl = parseInt(r.has_tldr, 10) === 1;
                    var tlBadge = hasTl
                        ? '<span style="color:#059669;font-weight:600;" title="Already inserted">✓</span>'
                        : '<span style="color:#dc2626;" title="Missing TL;DR">✗</span>';
                    var actionBtn = hasTl
                        ? '<button type="button" class="button button-small" disabled title="Already has TL;DR">Done</button>'
                        : '<button type="button" class="button button-small seom-surg-run-one" data-post-id="' + pid + '">Insert TL;DR</button>';
                    var pos = parseFloat(r.avg_position);
                    var posDisplay = pos > 0 ? pos.toFixed(1) : '—';
                    $body.append('<tr data-post-id="' + pid + '">'
                        + '<td>' + (hasTl ? '' : '<input type="checkbox" class="seom-surg-cb" data-post-id="' + pid + '" ' + checked + ' />') + '</td>'
                        + '<td><a href="' + escapeHtmlSurg(r.url) + '" target="_blank">' + escapeHtmlSurg(r.title) + '</a>'
                          + '<div style="font-size:11px;color:#94a3b8;margin-top:2px;">'
                          + '<a href="' + escapeHtmlSurg(r.edit_url || '#') + '" target="_blank">Edit</a>'
                          + (r.surgical_at ? ' · <span title="Surgically inserted at ' + escapeHtmlSurg(r.surgical_at) + '">stamped</span>' : '')
                          + '</div></td>'
                        + '<td style="text-align:right;">' + fmtNum(r.impressions) + '</td>'
                        + '<td style="text-align:right;">' + fmtNum(r.clicks) + '</td>'
                        + '<td style="text-align:right;">' + posDisplay + '</td>'
                        + '<td style="font-size:11px;color:#64748b;">' + escapeHtmlSurg((r.modified || '').substring(0, 10)) + '</td>'
                        + '<td style="text-align:center;">' + tlBadge + '</td>'
                        + '<td>' + actionBtn + '</td>'
                        + '</tr>');
                });
                $('#seom-surg-table').show();

                // Pagination
                var $pag = $('#seom-surg-pagination').empty();
                var pages = d.pages || 1;
                if (pages > 1) {
                    // Compact pagination: first, prev, current ± 2, next, last
                    var current = d.page || 1;
                    var addBtn = function(n, label, isActive) {
                        if (label == null) label = n;
                        var cls = isActive ? 'button button-primary' : 'button';
                        $pag.append('<button type="button" class="' + cls + ' button-small seom-surg-page" data-page="' + n + '">' + label + '</button>');
                    };
                    if (current > 1) addBtn(current - 1, '« Prev', false);
                    var start = Math.max(1, current - 2);
                    var end = Math.min(pages, current + 2);
                    if (start > 1) addBtn(1, '1', false);
                    if (start > 2) $pag.append('<span style="padding:0 4px;color:#94a3b8;">…</span>');
                    for (var p = start; p <= end; p++) addBtn(p, String(p), p === current);
                    if (end < pages - 1) $pag.append('<span style="padding:0 4px;color:#94a3b8;">…</span>');
                    if (end < pages) addBtn(pages, String(pages), false);
                    if (current < pages) addBtn(current + 1, 'Next »', false);
                }

                updateSelectedCount();
            }
            function loadSurgList() {
                $('#seom-surg-status').html('<span style="color:#64748b;">Loading…</span>');
                $.post(ajaxurl, {
                    action: 'seom_surgical_list',
                    nonce: seom_nonce,
                    page: surgState.page,
                    filter: $('#seom-surg-filter').val(),
                    sort: $('#seom-surg-sort').val()
                }, function(resp) {
                    if (!resp.success) {
                        $('#seom-surg-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                        return;
                    }
                    $('#seom-surg-status').empty();
                    renderSurgTable(resp.data);
                });
            }
            $('#seom-surg-refresh').on('click', function() { surgState.page = 1; loadSurgList(); });
            $('#seom-surg-filter, #seom-surg-sort').on('change', function() { surgState.page = 1; loadSurgList(); });
            $(document).on('click', '.seom-surg-page', function() {
                surgState.page = parseInt($(this).data('page'), 10) || 1;
                loadSurgList();
            });

            // Selection
            $(document).on('change', '.seom-surg-cb', function() {
                var pid = String($(this).data('post-id'));
                if ($(this).is(':checked')) surgState.selected[pid] = true;
                else delete surgState.selected[pid];
                updateSelectedCount();
            });
            $('#seom-surg-select-all, #seom-surg-header-cb').on('change', function() {
                var checked = $(this).is(':checked');
                $('.seom-surg-cb').each(function() {
                    var pid = String($(this).data('post-id'));
                    $(this).prop('checked', checked);
                    if (checked) surgState.selected[pid] = true;
                    else delete surgState.selected[pid];
                });
                $('#seom-surg-select-all, #seom-surg-header-cb').not(this).prop('checked', checked);
                updateSelectedCount();
            });

            // Run one at a time. Sequential — avoids hammering the AI endpoint
            // with parallel requests and keeps the per-post error reporting
            // attributable. Each call takes ~3-5s so a batch of 25 = ~2min.
            function surgRunSequence(ids) {
                if (!ids.length) return;
                var total = ids.length;
                var done = 0;
                var inserted = 0;
                var skipped = 0;
                var failed = 0;
                var $status = $('#seom-surg-status');
                $('#seom-surg-run-selected').prop('disabled', true).text('Running 0/' + total + '…');

                var step = function() {
                    if (!ids.length) {
                        $('#seom-surg-run-selected').prop('disabled', false).text('Run TL;DR for selected');
                        $status.html('<span style="color:#059669;">Done. Inserted: ' + inserted + ' · Skipped: ' + skipped + ' · Failed: ' + failed + '</span>');
                        // Clear selection + reload current page so updated rows render
                        surgState.selected = {};
                        loadSurgList();
                        return;
                    }
                    var pid = ids.shift();
                    var row = surgState.rowsById[pid];
                    var titleSnippet = row ? row.title.substring(0, 60) : ('post #' + pid);
                    $status.html('<span style="color:#b45309;">[' + (done + 1) + '/' + total + '] ' + escapeHtmlSurg(titleSnippet) + '…</span>');
                    $.ajax({
                        url: ajaxurl, method: 'POST', timeout: 90000,
                        data: { action: 'seom_surgical_insert_one', nonce: seom_nonce, post_id: pid },
                        success: function(resp) {
                            done++;
                            if (resp.success) {
                                if (resp.data && resp.data.status === 'skipped') skipped++;
                                else inserted++;
                            } else {
                                failed++;
                            }
                            $('#seom-surg-run-selected').text('Running ' + done + '/' + total + '…');
                            step();
                        },
                        error: function() {
                            done++;
                            failed++;
                            $('#seom-surg-run-selected').text('Running ' + done + '/' + total + '…');
                            step();
                        }
                    });
                };
                step();
            }

            $(document).on('click', '.seom-surg-run-one', function() {
                var pid = $(this).data('post-id');
                surgRunSequence([pid]);
            });
            $('#seom-surg-run-selected').on('click', function() {
                var ids = Object.keys(surgState.selected);
                if (!ids.length) { alert('Select at least one post.'); return; }
                if (!confirm('Generate and prepend TL;DR for ' + ids.length + ' post(s)? Each runs one AI call (~3 seconds + ~$0.003).')) return;
                surgRunSequence(ids.slice());
            });

            // Auto-load on first visit to the Settings tab so the table is
            // ready when the user scrolls down to the panel. Cheap query.
            $('.seom-tab[data-tab="settings"]').on('click', function() {
                // Defer so the tab actually shows before we paint the table.
                setTimeout(function() {
                    if ($('#seom-surg-tbody').children().length === 0) loadSurgList();
                }, 80);
            });
            // Also load if we land directly on the settings tab (URL hash, etc.)
            if ($('#seom-panel-settings.active').length) {
                setTimeout(loadSurgList, 200);
            }

            // ── Maintenance: Audit SEO Titles With Numbers ──
            // One-shot tool: finds posts whose rank_math_title starts with a
            // number, then re-runs ONLY step_seo_title against the new
            // accuracy validator. Does not touch last_page_refresh, history,
            // focus keyword, meta description, or post body.
            var auditState = {
                running: false,
                stopped: false,
                total: 0,
                offset: 0,
                updated: 0,
                unchanged: 0,
                failed: 0
            };

            function auditLog(html) {
                var $log = $('#seom-audit-titles-log');
                $log.show().append(html);
                $log[0].scrollTop = $log[0].scrollHeight;
            }
            function auditEsc(s) { return $('<div>').text(s == null ? '' : s).html(); }

            $('#seom-audit-titles-find').click(function() {
                var btn = $(this);
                var statusEl = $('#seom-audit-titles-status');
                btn.prop('disabled', true);
                $('#seom-audit-titles-apply').hide();
                $('#seom-audit-titles-progress').hide();
                $('#seom-audit-titles-log').hide().empty();
                statusEl.html('<span style="color:#64748b;">Searching for SEO titles starting with a number…</span>');

                $.post(ajaxurl, { action: 'seom_seo_title_audit', nonce: seom_nonce, dry_run: 1 }, function(resp) {
                    btn.prop('disabled', false);
                    if (!resp.success) {
                        statusEl.html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                        return;
                    }
                    var total = resp.data.total;
                    if (total === 0) {
                        statusEl.html('<span style="color:#059669;">No SEO titles starting with a number were found.</span>');
                        return;
                    }
                    var sample = resp.data.sample || [];
                    var sampleHtml = '<div style="margin-top:6px;">Sample of first ' + sample.length + ' candidates:</div><ol style="margin:6px 0 6px 18px; padding:0;">';
                    sample.forEach(function(s) {
                        sampleHtml += '<li style="margin:2px 0;"><strong>' + auditEsc(s.current_seo_title) + '</strong> <span style="color:#94a3b8;">(post ID ' + s.post_id + ')</span></li>';
                    });
                    sampleHtml += '</ol>';
                    statusEl.html(
                        '<span style="color:#b45309;font-weight:600;">Found ' + total + ' SEO title(s) starting with a number.</span>'
                        + sampleHtml
                        + '<div style="margin-top:8px;color:#64748b;">Click <strong>Apply Updates</strong> to rewrite all of them through the new validator. Each post takes 3-8 seconds, so the run is batched (5 at a time). You can stop mid-batch.</div>'
                    );
                    auditState.total = total;
                    auditState.offset = 0;
                    $('#seom-audit-titles-apply').show().prop('disabled', false);
                });
            });

            $('#seom-audit-titles-apply').click(function() {
                if (!confirm('Rewrite ' + auditState.total + ' SEO title(s) using the new accuracy validator? This only updates rank_math_title — last refresh dates and other metadata are NOT touched.')) return;
                auditState.running = true;
                auditState.stopped = false;
                auditState.offset = 0;
                auditState.updated = 0;
                auditState.unchanged = 0;
                auditState.failed = 0;
                $('#seom-audit-titles-apply').hide();
                $('#seom-audit-titles-find').prop('disabled', true);
                $('#seom-audit-titles-stop').show();
                $('#seom-audit-titles-progress').show();
                $('#seom-audit-titles-log').show().empty();
                $('#seom-audit-titles-status').html('<span style="color:#b45309;">Processing…</span>');
                runAuditBatch();
            });

            $('#seom-audit-titles-stop').click(function() {
                if (!confirm('Stop the audit after the current batch finishes? Already-updated titles stay as-is.')) return;
                auditState.stopped = true;
                $(this).prop('disabled', true).text('Stopping…');
            });

            function runAuditBatch() {
                if (auditState.stopped) {
                    finishAudit('Stopped by user');
                    return;
                }
                $.post(ajaxurl, {
                    action: 'seom_seo_title_audit',
                    nonce: seom_nonce,
                    apply: 1,
                    offset: auditState.offset,
                    batch: 5
                }, function(resp) {
                    if (!resp.success) {
                        finishAudit('Error: ' + (resp.data || 'unknown'));
                        return;
                    }
                    var d = resp.data;
                    auditState.updated   += d.results.updated.length;
                    auditState.unchanged += d.results.unchanged.length;
                    auditState.failed    += d.results.failed.length;

                    // Append per-row log entries
                    d.results.updated.forEach(function(r) {
                        auditLog(
                            '<div style="padding:4px 0;border-bottom:1px solid #e2e8f0;">'
                            + '<span style="color:#059669;">✓ UPDATED</span> <strong>' + auditEsc(r.title) + '</strong> <span style="color:#94a3b8;">(' + r.post_id + ')</span><br>'
                            + '<span style="color:#94a3b8;">before:</span> ' + auditEsc(r.before) + '<br>'
                            + '<span style="color:#059669;">after:</span> ' + auditEsc(r.after)
                            + '</div>'
                        );
                    });
                    d.results.unchanged.forEach(function(r) {
                        auditLog(
                            '<div style="padding:4px 0;border-bottom:1px solid #e2e8f0;color:#94a3b8;">'
                            + '· UNCHANGED <strong>' + auditEsc(r.title) + '</strong> <span>(' + r.post_id + ')</span> — ' + auditEsc(r.seo)
                            + '</div>'
                        );
                    });
                    d.results.failed.forEach(function(r) {
                        auditLog(
                            '<div style="padding:4px 0;border-bottom:1px solid #e2e8f0;">'
                            + '<span style="color:#dc2626;">✗ FAILED</span> <strong>' + auditEsc(r.title) + '</strong> <span style="color:#94a3b8;">(' + r.post_id + ')</span><br>'
                            + '<span style="color:#94a3b8;">before:</span> ' + auditEsc(r.before) + '<br>'
                            + '<span style="color:#dc2626;">error:</span> ' + auditEsc(r.error)
                            + '</div>'
                        );
                    });

                    var pct = d.total ? Math.round((d.processed / d.total) * 100) : 100;
                    $('#seom-audit-titles-progress').html(
                        '<strong>' + d.processed + ' / ' + d.total + '</strong> processed (' + pct + '%)'
                        + ' &middot; <span style="color:#059669;">' + auditState.updated + ' updated</span>'
                        + ' &middot; <span style="color:#94a3b8;">' + auditState.unchanged + ' unchanged</span>'
                        + ' &middot; <span style="color:#dc2626;">' + auditState.failed + ' failed</span>'
                    );

                    if (d.done) {
                        finishAudit('Complete');
                        return;
                    }
                    if (auditState.stopped) {
                        finishAudit('Stopped by user');
                        return;
                    }
                    auditState.offset = d.next_offset;
                    runAuditBatch();
                }).fail(function() {
                    finishAudit('Network error');
                });
            }

            function finishAudit(label) {
                auditState.running = false;
                $('#seom-audit-titles-find').prop('disabled', false);
                $('#seom-audit-titles-stop').hide().prop('disabled', false).text('Stop');
                $('#seom-audit-titles-status').html(
                    '<span style="color:#059669;font-weight:600;">' + label + '.</span> '
                    + auditState.updated + ' updated, '
                    + auditState.unchanged + ' unchanged, '
                    + auditState.failed + ' failed.'
                );
            }

            // Reset the featured-product template to the plugin default.
            $('#seom-featured-template-reset').click(function() {
                if (!confirm('Replace the current Featured Product template with the plugin default? Unsaved edits will be lost.')) return;
                var defaultTpl = ''
                    + '<div style="margin:32px 0;border:2px dashed #C026D3;background:rgba(192,38,211,0.04);padding:32px 36px;box-sizing:border-box;">\n'
                    + '    <div style="font-family:\'Fira Code\',Menlo,Consolas,monospace;font-size:0.85rem;letter-spacing:2.5px;text-transform:uppercase;color:#C026D3;margin-bottom:14px;font-weight:600;">Featured Product</div>\n'
                    + '    <h2 style="margin:0 0 12px;font-size:1.6rem;line-height:1.3;color:#1e293b;">{title}</h2>\n'
                    + '    <p style="margin:0 0 22px;color:#475569;font-size:1rem;line-height:1.55;">{short_description}</p>\n'
                    + '    <a href="{button_url}" {button_target} style="display:inline-block;padding:12px 26px;font-family:\'Fira Code\',Menlo,Consolas,monospace;font-size:0.9rem;font-weight:600;color:#C026D3;text-decoration:none;border:1.5px solid #C026D3;border-radius:0;border-top-right-radius:14px;background:#fff;">{button_label}</a>\n'
                    + '</div>';
                $('#seom-featured-product-template').val(defaultTpl);
            });

            $('#seom-settings-form').submit(function(e) {
                e.preventDefault();

                // Sync research category checkboxes to hidden field BEFORE serializing
                var cats = [];
                $('.seom-research-cat:checked').each(function() { cats.push($(this).val()); });
                $('#seom-research-categories').val(cats.join(','));

                var data = $(this).serializeArray();
                data.push({ name: 'action', value: 'seom_save_settings' });
                data.push({ name: 'nonce', value: seom_nonce });

                // Handle unchecked checkboxes
                if (!$('input[name="enabled"]').is(':checked')) data.push({ name: 'enabled', value: '0' });
                if (!$('input[name="dry_run"]').is(':checked')) data.push({ name: 'dry_run', value: '0' });
                if (!$('input[name="research_enabled"]').is(':checked')) data.push({ name: 'research_enabled', value: '0' });
                if (!$('input[name="ai_bots_allow"]').is(':checked')) data.push({ name: 'ai_bots_allow', value: '0' });
                if (!$('input[name="llms_txt_enabled"]').is(':checked')) data.push({ name: 'llms_txt_enabled', value: '0' });
                if (!$('input[name="glossary_links_enabled"]').is(':checked')) data.push({ name: 'glossary_links_enabled', value: '0' });

                $('#seom-save-status').text('Saving...');
                $.post(ajaxurl, data, function(resp) {
                    $('#seom-save-status').html(resp.success
                        ? '<span style="color:#16a34a;">Saved!</span>'
                        : '<span style="color:#dc2626;">Error</span>');
                    setTimeout(function() { $('#seom-save-status').text(''); }, 3000);
                });
            });

            // Glossary cache rebuild — single-link handler.
            $(document).on('click', '#seom-glossary-rebuild', function(e) {
                e.preventDefault();
                var $a = $(this).text('Rebuilding...');
                $.post(ajaxurl, { action: 'seom_glossary_rebuild', nonce: seom_nonce }, function(resp) {
                    if (resp.success) {
                        $a.text('Rebuilt — ' + resp.data.count + ' terms loaded');
                        setTimeout(function() { $a.text('Rebuild cache'); }, 4000);
                    } else {
                        $a.text('Error: ' + (resp.data || 'unknown'));
                    }
                });
            });

            // Glossary link migration — batched scan over wp_posts.
            // Walks 50 posts at a time until the server reports has_more=false.
            // Same loop drives the dry-run and the live migration; only the
            // dry_run flag differs in the AJAX payload.
            function runGlossaryMigration(dryRun) {
                var totalScanned = 0, totalPostsUpdated = 0, totalLinks = 0, offset = 0;
                var $status = $('#seom-glossary-migrate-status');
                var $btn1   = $('#seom-glossary-migrate-dry').prop('disabled', true);
                var $btn2   = $('#seom-glossary-migrate-go').prop('disabled', true);
                var label   = dryRun ? 'Dry-run' : 'Migration';
                $status.html('<span style="color:#b45309;">' + label + ' running... scanned 0</span>');

                function step() {
                    $.post(ajaxurl, {
                        action: 'seom_glossary_migrate_links',
                        nonce:  seom_nonce,
                        offset: offset,
                        dry_run: dryRun ? '1' : '0'
                    }, function(resp) {
                        if (!resp.success) {
                            $status.html('<span style="color:#dc2626;">Error: ' + (resp.data || 'unknown') + '</span>');
                            $btn1.prop('disabled', false); $btn2.prop('disabled', false);
                            return;
                        }
                        var d = resp.data;
                        totalScanned      += d.scanned;
                        totalPostsUpdated += d.posts_updated;
                        totalLinks        += d.links_updated;
                        offset             = d.next_offset;
                        $status.html('<span style="color:#b45309;">' + label + ' running... scanned ' + totalScanned + ' / posts to update: ' + totalPostsUpdated + ' / links: ' + totalLinks + '</span>');
                        if (d.has_more) {
                            step();
                        } else {
                            var verb = dryRun ? 'would update' : 'updated';
                            $status.html('<span style="color:#059669;">' + label + ' complete. Scanned ' + totalScanned + ' posts · ' + verb + ' ' + totalPostsUpdated + ' posts (' + totalLinks + ' links).</span>');
                            $btn1.prop('disabled', false); $btn2.prop('disabled', false);
                        }
                    });
                }
                step();
            }

            $('#seom-glossary-migrate-dry').on('click', function() { runGlossaryMigration(true); });
            $('#seom-glossary-migrate-go').on('click', function() {
                if (!confirm('This will rewrite glossary <a href> attributes in every post that contains old links. Continue?')) return;
                runGlossaryMigration(false);
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

    /**
     * Glossary tab — two pipelines, both batched and resumable.
     *
     *   1. Term Definitions  — AI-expand short one-sentence definitions
     *      into 300-500 word structured pieces. Per-row Expand / Revert
     *      actions plus a "process N pending" batch runner.
     *
     *   2. Blog Crosswalk    — scan all published posts for mentions of
     *      each glossary term and persist (term, post, score) triples in
     *      wp_seom_glossary_crosswalk. Per-row Scan action plus a batch
     *      runner that walks the pending list at the user's chosen rate.
     *
     * The table is paginated/filterable/sortable and shows a status
     * badge per row plus a derived "X blogs" count from the crosswalk
     * table. The whole experience is single-page: every action calls
     * one of the seom_glossary_* AJAX endpoints and updates the row in
     * place; no full page reload between operations.
     */
    private static function render_glossary($nonce) {
        $counts            = seom_glossary_status_counts();
        $batch_state       = function_exists('seom_glossary_batch_get_active') ? seom_glossary_batch_get_active() : null;
        $batch_pending     = function_exists('seom_glossary_batch_count_pending') ? seom_glossary_batch_count_pending('expand') : 0;
        $seo_meta_pending  = function_exists('seom_glossary_batch_count_pending') ? seom_glossary_batch_count_pending('seo_meta') : 0;
        ?>
        <h2 style="margin-top:0;">Glossary Manager</h2>
        <p style="color:#64748b;max-width:760px;">
            Manage the IT glossary: expand one-sentence definitions into full structured
            content via AI, and build the blog crosswalk so each term can surface its
            related posts. Both operations are batched — you can process a few rows or a
            few hundred, then come back later to pick up where you left off.
        </p>

        <div class="seom-cards" style="margin-top:20px;">
            <div class="seom-card">
                <h3>Total Terms</h3>
                <div class="seom-card-value" id="seom-gl-total"><?php echo number_format($counts['total']); ?></div>
            </div>
            <div class="seom-card">
                <h3>Expanded</h3>
                <div class="seom-card-value" id="seom-gl-expanded"><?php echo number_format($counts['expanded']); ?></div>
                <div class="seom-card-sub" id="seom-gl-pending-expand"><?php echo number_format($counts['pending_expand']); ?> pending</div>
            </div>
            <div class="seom-card">
                <h3>Scanned</h3>
                <div class="seom-card-value" id="seom-gl-scanned"><?php echo number_format($counts['scanned']); ?></div>
                <div class="seom-card-sub" id="seom-gl-pending-scan"><?php echo number_format($counts['pending_scan']); ?> pending</div>
            </div>
            <div class="seom-card">
                <h3>Crosswalk Rows</h3>
                <div class="seom-card-value" id="seom-gl-crosswalk"><?php echo number_format($counts['crosswalk_rows']); ?></div>
                <div class="seom-card-sub">term-to-blog mappings</div>
            </div>
        </div>

        <div id="seom-gl-batchapi-section" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:20px;">
            <h3 style="margin-top:0;">Bulk Expand (OpenAI Batch API)</h3>
            <p style="color:#64748b;font-size:13px;margin:6px 0 14px;">
                Submit pending terms as one async batch — 50% cost discount, no rate limits,
                turnaround within 24h (usually 1-4h). Better than the in-page runner below for
                hundreds-to-thousands of terms. Only one batch can be in flight at a time.
            </p>

            <div id="seom-gl-batchapi-idle" style="<?php echo $batch_state ? 'display:none;' : ''; ?>">
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
                    <label style="font-size:13px;">Job type:
                        <select id="seom-gl-batchapi-job-type">
                            <option value="expand">Expand pending definitions (<?php echo number_format($batch_pending); ?>)</option>
                            <option value="seo_meta">SEO meta enrichment (<?php echo number_format($seo_meta_pending); ?>)</option>
                        </select>
                    </label>
                    <label style="font-size:13px;">Limit:
                        <input type="number" id="seom-gl-batchapi-limit" min="0" max="50000" value="0" style="width:90px;" title="0 = submit all pending">
                    </label>
                    <button class="button button-primary" id="seom-gl-batchapi-submit">Submit batch</button>
                    <span id="seom-gl-batchapi-submit-status" style="font-size:13px;color:#475569;"></span>
                </div>
                <p style="font-size:11px;color:#94a3b8;margin:4px 0 0;">
                    <strong>Expand</strong>: full long-form content + SEO metadata for terms without expanded content (~$0.85 for ~5,700 terms on gpt-4.1-nano).<br>
                    <strong>SEO meta</strong>: TL;DR + SEO title + meta description + FAQs for already-expanded terms (~$2.50 for ~5,700 terms — cheaper per row but more terms eligible).
                </p>
                <p style="font-size:11px;color:#94a3b8;margin:4px 0 0;">Polling runs automatically every 15 minutes once submitted; results apply automatically on completion.</p>
            </div>

            <div id="seom-gl-batchapi-active" style="<?php echo $batch_state ? '' : 'display:none;'; ?>">
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <tr><td style="padding:4px 0;color:#64748b;width:140px;">Batch ID</td><td><code id="seom-gl-batchapi-id"><?php echo $batch_state ? esc_html($batch_state['batch_id']) : ''; ?></code></td></tr>
                    <tr><td style="padding:4px 0;color:#64748b;">Status</td><td><span id="seom-gl-batchapi-status-badge" class="seom-gl-badge seom-gl-badge-pending"><?php echo $batch_state ? esc_html($batch_state['status']) : ''; ?></span></td></tr>
                    <tr><td style="padding:4px 0;color:#64748b;">Submitted</td><td id="seom-gl-batchapi-submitted-at"><?php echo $batch_state ? esc_html($batch_state['submitted_at']) : ''; ?></td></tr>
                    <tr><td style="padding:4px 0;color:#64748b;">Last polled</td><td id="seom-gl-batchapi-polled-at"><?php echo $batch_state && !empty($batch_state['last_polled_at']) ? esc_html($batch_state['last_polled_at']) : 'never'; ?></td></tr>
                    <tr><td style="padding:4px 0;color:#64748b;">Submitted count</td><td id="seom-gl-batchapi-total"><?php echo $batch_state ? number_format($batch_state['total_count']) : ''; ?></td></tr>
                    <tr><td style="padding:4px 0;color:#64748b;">Provider progress</td><td id="seom-gl-batchapi-provider-progress"><?php
                        if ($batch_state && isset($batch_state['provider_completed'])) {
                            echo number_format($batch_state['provider_completed']) . ' / ' . number_format($batch_state['provider_total'] ?? 0);
                            if (!empty($batch_state['provider_failed'])) echo ' (' . number_format($batch_state['provider_failed']) . ' failed)';
                        } else echo '—';
                    ?></td></tr>
                    <tr><td style="padding:4px 0;color:#64748b;">Applied locally</td><td id="seom-gl-batchapi-applied"><?php
                        if ($batch_state && !empty($batch_state['applied'])) {
                            echo number_format($batch_state['completed_count']) . ' completed';
                            if (!empty($batch_state['failed_count'])) echo ' · ' . number_format($batch_state['failed_count']) . ' failed';
                        } else echo 'not yet';
                    ?></td></tr>
                </table>
                <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button class="button" id="seom-gl-batchapi-refresh">Refresh now</button>
                    <button class="button" id="seom-gl-batchapi-cancel" style="color:#dc2626;">Cancel batch</button>
                    <button class="button" id="seom-gl-batchapi-dismiss" style="<?php echo $batch_state && !empty($batch_state['applied']) ? '' : 'display:none;'; ?>">Dismiss (start new)</button>
                    <span id="seom-gl-batchapi-action-status" style="font-size:13px;color:#475569;"></span>
                </div>
            </div>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:20px;">
            <h3 style="margin-top:0;">In-Page Batch Runner (realtime)</h3>
            <p style="color:#64748b;font-size:13px;margin:6px 0 14px;">
                Process the current filtered list, top-to-bottom. Either pipeline can be
                stopped at any time — partial progress is saved. Best for small jobs
                (5-50 terms) where you want immediate results; for thousands of terms
                use the Batch API section above.
            </p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                <label style="font-size:13px;">Run:
                    <select id="seom-gl-batch-action">
                        <option value="expand">Expand definitions</option>
                        <option value="scan">Scan for crosswalk</option>
                    </select>
                </label>
                <label style="font-size:13px;">For up to:
                    <input type="number" id="seom-gl-batch-count" min="1" max="500" value="25" style="width:80px;">
                    rows
                </label>
                <button class="button button-primary" id="seom-gl-batch-start">Start batch</button>
                <button class="button" id="seom-gl-batch-stop" disabled>Stop</button>
                <span id="seom-gl-batch-status" style="font-size:13px;color:#475569;"></span>
            </div>
            <div id="seom-gl-batch-log" style="margin-top:12px;max-height:200px;overflow-y:auto;font-size:12px;font-family:Menlo,Monaco,Consolas,monospace;color:#334155;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px;display:none;"></div>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:20px;">
            <h3 style="margin-top:0;">Content Gap Topics</h3>
            <p style="color:#64748b;font-size:13px;margin:6px 0 14px;">
                Glossary terms with thin or zero blog coverage. Tier 1 = the term
                also matches a row in your keyword gaps (proven search demand).
                Tier 2 = glossary term only (no matching gap row). Pick a set,
                generate angles, and queue them as topics for the blog writer.
            </p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
                <label style="font-size:13px;">Max coverage:
                    <select id="seom-gl-gap-coverage">
                        <option value="0">0 (zero blogs)</option>
                        <option value="2" selected>≤ 2 blogs</option>
                        <option value="5">≤ 5 blogs</option>
                    </select>
                </label>
                <label style="font-size:13px;">Area:
                    <select id="seom-gl-gap-area">
                        <option value="">All areas</option>
                    </select>
                </label>
                <label style="font-size:13px;">Tier:
                    <select id="seom-gl-gap-tier">
                        <option value="0">Tier 1 + 2 (gap-matched first)</option>
                        <option value="1">Tier 1 only (proven demand)</option>
                        <option value="2">Tier 2 only (glossary fallback)</option>
                    </select>
                </label>
                <label style="font-size:13px;">
                    <input type="checkbox" id="seom-gl-gap-exclude-queued" checked>
                    Hide already queued (180d)
                </label>
                <button class="button" id="seom-gl-gap-refresh">Refresh</button>
                <span id="seom-gl-gap-counts" style="font-size:12px;color:#64748b;"></span>
            </div>

            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:12px;padding-top:10px;border-top:1px solid #f1f5f9;">
                <button class="button button-primary" id="seom-gl-gap-generate" disabled>Generate angles for selected</button>
                <button class="button button-primary" id="seom-gl-gap-queue" disabled>Queue selected as topics</button>
                <label style="font-size:13px;">Featured product:
                    <input type="number" id="seom-gl-gap-product-id" min="0" value="0" style="width:90px;" title="WooCommerce product ID; 0 = none">
                </label>
                <span id="seom-gl-gap-status" style="font-size:13px;color:#475569;"></span>
            </div>

            <table class="widefat striped" id="seom-gl-gap-table">
                <thead>
                    <tr>
                        <th style="width:32px;"><input type="checkbox" id="seom-gl-gap-select-all"></th>
                        <th>Term</th>
                        <th style="width:90px;">Tier</th>
                        <th style="width:120px;">Matched keyword</th>
                        <th style="width:80px;">Volume</th>
                        <th style="width:60px;">Cov.</th>
                        <th style="width:120px;">Area</th>
                        <th style="width:260px;">Suggested angle</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="8" style="text-align:center;color:#64748b;padding:24px;">Loading…</td></tr>
                </tbody>
            </table>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
                <input type="text" id="seom-gl-search" placeholder="Search terms…" style="flex:1;min-width:200px;padding:6px 10px;">
                <select id="seom-gl-filter">
                    <option value="all">All terms</option>
                    <option value="pending_expand">Pending expansion</option>
                    <option value="expanded">Expanded</option>
                    <option value="pending_scan">Pending scan</option>
                    <option value="scanned">Scanned</option>
                </select>
                <select id="seom-gl-sort">
                    <option value="term:asc">Term A→Z</option>
                    <option value="term:desc">Term Z→A</option>
                    <option value="expanded_at:desc">Recently expanded</option>
                    <option value="scanned_at:desc">Recently scanned</option>
                    <option value="crosswalk_count:desc">Most-linked first</option>
                    <option value="crosswalk_count:asc">Least-linked first</option>
                </select>
                <button class="button" id="seom-gl-refresh">Refresh</button>
            </div>

            <table class="widefat striped" id="seom-gl-table" style="margin-top:0;">
                <thead>
                    <tr>
                        <th style="width:34%;">Term</th>
                        <th style="width:14%;">Definition</th>
                        <th style="width:14%;">Crosswalk</th>
                        <th style="width:38%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="4" style="text-align:center;color:#64748b;padding:24px;">Loading…</td></tr>
                </tbody>
            </table>

            <div id="seom-gl-pagination" style="margin-top:12px;"></div>
        </div>

        <style>
        .seom-gl-badge {
            display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px;
            font-weight:600; text-transform:uppercase; letter-spacing:0.3px;
        }
        .seom-gl-badge-expanded { background:#dcfce7; color:#166534; }
        .seom-gl-badge-pending  { background:#fef3c7; color:#92400e; }
        .seom-gl-badge-scanned  { background:#dbeafe; color:#1e40af; }
        .seom-gl-badge-unscanned { background:#f1f5f9; color:#64748b; }
        .seom-gl-row-actions button { margin-right:6px; }
        .seom-gl-log-line { padding:2px 0; }
        .seom-gl-log-ok   { color:#059669; }
        .seom-gl-log-err  { color:#dc2626; }
        .seom-gl-log-skip { color:#94a3b8; }
        </style>

        <script>
        (function ($) {
            'use strict';

            // ── State ────────────────────────────────────────────────
            var state = {
                page: 1,
                perPage: 50,
                filter: 'all',
                search: '',
                sort: 'term',
                order: 'asc',
                lastRows: [],
            };
            var batch = {
                running: false,
                stop: false,
                action: 'expand',
                remaining: 0,
                started: 0,
            };

            // ── Helpers ──────────────────────────────────────────────
            function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

            function badge(row) {
                var bits = [];
                var hasExpanded = !!row.definition_expanded && row.definition_expanded.length > 0;
                if (hasExpanded) {
                    bits.push('<span class="seom-gl-badge seom-gl-badge-expanded">Expanded</span>');
                } else {
                    bits.push('<span class="seom-gl-badge seom-gl-badge-pending">Pending</span>');
                }
                if (row.crosswalk_scanned_at) {
                    bits.push('<span class="seom-gl-badge seom-gl-badge-scanned">Scanned</span>');
                } else {
                    bits.push('<span class="seom-gl-badge seom-gl-badge-unscanned">Unscanned</span>');
                }
                return bits.join(' ');
            }

            function rowHtml(row) {
                var hasExpanded = !!row.definition_expanded && row.definition_expanded.length > 0;
                var hasSeoMeta  = !!row.seo_meta_at;
                var termAttr = esc(row.term); // double-escape: HTML attribute context
                var revertBtn = hasExpanded
                    ? '<button class="button seom-gl-revert" data-term="' + termAttr + '">Revert</button>'
                    : '';
                var seoBtn = hasExpanded
                    ? '<button class="button seom-gl-seo-meta" data-term="' + termAttr + '" title="' + (hasSeoMeta ? 'Regenerate SEO metadata' : 'Generate SEO title, meta description, TL;DR, FAQs') + '">' + (hasSeoMeta ? 'Re-SEO' : 'SEO meta') + '</button>'
                    : '';
                var expandLabel = hasExpanded ? 'Re-expand' : 'Expand';
                var crosswalkLabel = (parseInt(row.crosswalk_count, 10) || 0) + ' blogs';
                if (row.crosswalk_scanned_at) {
                    crosswalkLabel += '<br><span style="font-size:11px;color:#94a3b8;">' + esc(row.crosswalk_scanned_at) + '</span>';
                }
                return '<tr data-term="' + termAttr + '">' +
                    '<td><strong>' + esc(row.term) + '</strong><br>' + badge(row) + '</td>' +
                    '<td>' + (row.definition_expanded_at ? esc(row.definition_expanded_at) : '<span style="color:#94a3b8;">—</span>') + '</td>' +
                    '<td>' + crosswalkLabel + '</td>' +
                    '<td class="seom-gl-row-actions">' +
                        '<button class="button button-primary seom-gl-expand" data-term="' + termAttr + '" data-force="' + (hasExpanded ? '1' : '0') + '">' + expandLabel + '</button>' +
                        seoBtn +
                        '<button class="button seom-gl-scan" data-term="' + termAttr + '">Scan</button>' +
                        revertBtn +
                        '<button class="button-link seom-gl-preview" data-term="' + termAttr + '">View</button>' +
                    '</td>' +
                '</tr>';
            }

            function updateCounts(c) {
                if (!c) return;
                $('#seom-gl-total').text(Number(c.total).toLocaleString());
                $('#seom-gl-expanded').text(Number(c.expanded).toLocaleString());
                $('#seom-gl-pending-expand').text(Number(c.pending_expand).toLocaleString() + ' pending');
                $('#seom-gl-scanned').text(Number(c.scanned).toLocaleString());
                $('#seom-gl-pending-scan').text(Number(c.pending_scan).toLocaleString() + ' pending');
                $('#seom-gl-crosswalk').text(Number(c.crosswalk_rows).toLocaleString());
            }

            function logLine(text, kind) {
                var $log = $('#seom-gl-batch-log');
                $log.show();
                var cls = 'seom-gl-log-line';
                if (kind === 'ok')   cls += ' seom-gl-log-ok';
                if (kind === 'err')  cls += ' seom-gl-log-err';
                if (kind === 'skip') cls += ' seom-gl-log-skip';
                $log.append('<div class="' + cls + '">' + esc(text) + '</div>');
                $log.scrollTop($log[0].scrollHeight);
            }

            // ── Loaders ──────────────────────────────────────────────
            function load() {
                $('#seom-gl-table tbody').html('<tr><td colspan="4" style="text-align:center;color:#64748b;padding:24px;">Loading…</td></tr>');
                $.post(ajaxurl, {
                    action:   'seom_glossary_list',
                    nonce:    seom_nonce,
                    filter:   state.filter,
                    search:   state.search,
                    sort:     state.sort,
                    order:    state.order,
                    page:     state.page,
                    per_page: state.perPage,
                }, function (resp) {
                    if (!resp.success) {
                        $('#seom-gl-table tbody').html('<tr><td colspan="4" style="text-align:center;color:#dc2626;padding:24px;">' + esc(resp.data || 'Load failed') + '</td></tr>');
                        return;
                    }
                    state.lastRows = resp.data.rows || [];
                    updateCounts(resp.data.counts);
                    if (!state.lastRows.length) {
                        $('#seom-gl-table tbody').html('<tr><td colspan="4" style="text-align:center;color:#64748b;padding:24px;">No terms match the current filter.</td></tr>');
                    } else {
                        $('#seom-gl-table tbody').html(state.lastRows.map(rowHtml).join(''));
                    }
                    var pagHtml = window.seomBuildPagination(
                        resp.data.page,
                        resp.data.pages,
                        'seom-gl-page',
                        ' <span style="color:#64748b;margin-left:8px;font-size:12px;">' +
                        Number(resp.data.total).toLocaleString() + ' total</span>'
                    );
                    $('#seom-gl-pagination').html(pagHtml);
                });
            }

            // ── Single-row actions ───────────────────────────────────
            function runExpand(term, force, $row) {
                if ($row) $row.find('.seom-gl-expand').prop('disabled', true).text('Expanding…');
                return $.post(ajaxurl, {
                    action: 'seom_glossary_expand',
                    nonce:  seom_nonce,
                    term:   term,
                    force:  force ? 1 : 0,
                }).then(function (resp) {
                    if (!resp.success) return { ok: false, msg: resp.data || 'Expand failed' };
                    if (resp.data.status === 'skipped') return { ok: true, skipped: true, msg: resp.data.reason };
                    return { ok: true, msg: resp.data.words + ' words' };
                }, function () {
                    return { ok: false, msg: 'Network error' };
                });
            }

            function runScan(term, $row) {
                if ($row) $row.find('.seom-gl-scan').prop('disabled', true).text('Scanning…');
                return $.post(ajaxurl, {
                    action: 'seom_glossary_scan',
                    nonce:  seom_nonce,
                    term:   term,
                }).then(function (resp) {
                    if (!resp.success) return { ok: false, msg: resp.data || 'Scan failed' };
                    return { ok: true, msg: resp.data.matches + ' matches (+' + resp.data.inserted + ' / ~' + resp.data.updated + ' / -' + resp.data.removed + ')' };
                }, function () {
                    return { ok: false, msg: 'Network error' };
                });
            }

            $(document).on('click', '.seom-gl-expand', function () {
                var $btn = $(this);
                var term  = $btn.data('term');
                var force = parseInt($btn.data('force'), 10) === 1;
                if (force && !confirm('Re-expand this term? The current expanded content will be replaced (the original one-sentence definition is preserved).')) return;
                runExpand(term, force, $btn.closest('tr')).then(function (r) {
                    if (!r.ok) { alert(r.msg); }
                    load();
                });
            });

            $(document).on('click', '.seom-gl-scan', function () {
                var $btn = $(this);
                var term = $btn.data('term');
                runScan(term, $btn.closest('tr')).then(function (r) {
                    if (!r.ok) { alert(r.msg); }
                    load();
                });
            });

            $(document).on('click', '.seom-gl-revert', function () {
                var term = $(this).data('term');
                if (!confirm('Revert this term to its original one-sentence definition?')) return;
                $.post(ajaxurl, {
                    action: 'seom_glossary_revert',
                    nonce:  seom_nonce,
                    term:   term,
                }, function (resp) {
                    if (!resp.success) { alert(resp.data || 'Revert failed'); return; }
                    load();
                });
            });

            $(document).on('click', '.seom-gl-seo-meta', function () {
                var $btn = $(this);
                var term = $btn.data('term');
                $btn.prop('disabled', true).text('Working…');
                $.post(ajaxurl, {
                    action: 'seom_glossary_seo_meta',
                    nonce:  seom_nonce,
                    term:   term,
                    force:  1,
                }, function (resp) {
                    if (!resp.success) { alert(resp.data || 'SEO meta failed'); $btn.prop('disabled', false).text('SEO meta'); return; }
                    load();
                });
            });

            $(document).on('click', '.seom-gl-preview', function () {
                var term = $(this).data('term');
                $.post(ajaxurl, {
                    action: 'seom_glossary_preview',
                    nonce:  seom_nonce,
                    term:   term,
                }, function (resp) {
                    if (!resp.success) { alert(resp.data || 'Preview failed'); return; }
                    var d = resp.data;
                    var w = window.open('', '_blank', 'width=720,height=820,scrollbars=yes');
                    var html = '<!DOCTYPE html><html><head><title>' + esc(d.term) + '</title>' +
                               '<style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;padding:24px;max-width:680px;margin:0 auto;color:#1e293b;line-height:1.55;}' +
                               'h1{font-size:22px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;}' +
                               'h2{font-size:14px;text-transform:uppercase;color:#64748b;letter-spacing:0.04em;margin-top:24px;}' +
                               'h3{font-size:15px;}' +
                               '.meta{font-size:12px;color:#94a3b8;}' +
                               '.box{background:#f8fafc;border:1px solid #e2e8f0;padding:12px 14px;border-radius:6px;}</style></head><body>' +
                               '<h1>' + esc(d.term) + '</h1>' +
                               '<p class="meta">slug: ' + esc(d.slug) + ' · words: ' + d.words +
                               (d.expanded_at ? ' · expanded ' + esc(d.expanded_at) : '') +
                               (d.scanned_at ? ' · scanned ' + esc(d.scanned_at) : '') +
                               '</p>' +
                               '<h2>Short definition (accordion)</h2><div class="box">' + esc(d.definition) + '</div>' +
                               (d.expanded ? '<h2>Expanded content (per-term page)</h2><div class="box">' + d.expanded + '</div>' : '') +
                               '</body></html>';
                    w.document.write(html);
                    w.document.close();
                });
            });

            // ── Pagination + filters ─────────────────────────────────
            $(document).on('click', '.seom-gl-page', function () {
                state.page = parseInt($(this).data('page'), 10) || 1;
                load();
            });
            $('#seom-gl-refresh').on('click', function () { load(); });
            $('#seom-gl-filter').on('change', function () { state.filter = $(this).val(); state.page = 1; load(); });
            $('#seom-gl-sort').on('change', function () {
                var v = $(this).val().split(':');
                state.sort  = v[0]; state.order = v[1] || 'asc';
                state.page = 1;
                load();
            });
            var searchTimer;
            $('#seom-gl-search').on('input', function () {
                clearTimeout(searchTimer);
                var v = $(this).val();
                searchTimer = setTimeout(function () { state.search = v; state.page = 1; load(); }, 350);
            });

            // ── Batch runner ─────────────────────────────────────────
            $('#seom-gl-batch-start').on('click', function () {
                if (batch.running) return;
                var count = Math.max(1, Math.min(500, parseInt($('#seom-gl-batch-count').val(), 10) || 0));
                batch.running   = true;
                batch.stop      = false;
                batch.action    = $('#seom-gl-batch-action').val();
                batch.remaining = count;
                batch.started   = count;
                $('#seom-gl-batch-start').prop('disabled', true);
                $('#seom-gl-batch-stop').prop('disabled', false);
                $('#seom-gl-batch-log').empty().show();
                logLine('Starting ' + batch.action + ' batch — up to ' + count + ' rows', 'ok');
                // Force the filter to the matching pending list so we pick the right work.
                if (batch.action === 'expand') {
                    state.filter = 'pending_expand';
                    $('#seom-gl-filter').val('pending_expand');
                } else {
                    state.filter = 'pending_scan';
                    $('#seom-gl-filter').val('pending_scan');
                }
                state.page = 1;
                runBatchTick();
            });

            $('#seom-gl-batch-stop').on('click', function () {
                batch.stop = true;
                logLine('Stop requested — finishing current row…', 'skip');
            });

            // The batch runner pulls the current pending page, processes it row
            // by row, then re-pulls page 1 (because processed rows fall out of
            // the pending filter). Stops when we've hit the requested count,
            // the user hits Stop, or no pending rows remain.
            function runBatchTick() {
                if (!batch.running) return;
                if (batch.stop || batch.remaining <= 0) {
                    finishBatch();
                    return;
                }
                $.post(ajaxurl, {
                    action:   'seom_glossary_list',
                    nonce:    seom_nonce,
                    filter:   state.filter,
                    sort:     'term',
                    order:    'asc',
                    page:     1,
                    per_page: Math.min(50, batch.remaining),
                }, function (resp) {
                    if (!resp.success || !resp.data.rows.length) {
                        logLine('No more pending rows.', 'skip');
                        finishBatch();
                        return;
                    }
                    updateCounts(resp.data.counts);
                    processBatchRows(resp.data.rows.slice(), function () {
                        // After processing the page, re-load both the panel
                        // and continue ticking until we either finish or run
                        // out of pending rows.
                        load();
                        runBatchTick();
                    });
                });
            }

            function processBatchRows(rows, done) {
                if (!rows.length || batch.stop || batch.remaining <= 0) return done();
                var row = rows.shift();
                var runner = (batch.action === 'expand')
                    ? runExpand(row.term, false, null)
                    : runScan(row.term, null);
                runner.then(function (r) {
                    var n = batch.started - batch.remaining + 1;
                    var label = '[' + n + '/' + batch.started + '] ' + row.term;
                    if (r.ok && r.skipped) {
                        logLine(label + ' — skipped (' + r.msg + ')', 'skip');
                    } else if (r.ok) {
                        logLine(label + ' — ' + r.msg, 'ok');
                    } else {
                        logLine(label + ' — ERROR: ' + r.msg, 'err');
                    }
                    batch.remaining--;
                    $('#seom-gl-batch-status').text('Processed ' + (batch.started - batch.remaining) + ' / ' + batch.started);
                    if (batch.stop || batch.remaining <= 0) return done();
                    // Tight throttle so the UI stays responsive and we
                    // don't hammer OpenAI without pause.
                    setTimeout(function () { processBatchRows(rows, done); }, 250);
                });
            }

            function finishBatch() {
                batch.running = false;
                $('#seom-gl-batch-start').prop('disabled', false);
                $('#seom-gl-batch-stop').prop('disabled', true);
                logLine('Done. Processed ' + (batch.started - batch.remaining) + ' of ' + batch.started + ' requested.', 'ok');
                load();
            }

            // ── Content Gap Topics ───────────────────────────────────
            //
            // Self-contained sub-app within this IIFE. State is kept in
            // `gap` and the rows array `gapRows`, which is mutated as the
            // user generates angles (each row gains a `suggested_title`
            // / `primary_keyword` once its angle is back from the AI).
            var gap = {
                coverage: 2,
                area: '',
                tier: 0,
                excludeQueued: true,
                page: 1,
                perPage: 50,
            };
            var gapRows = [];

            function gapBadge(tier) {
                if (tier === 1) return '<span class="seom-gl-badge seom-gl-badge-scanned">Tier 1</span>';
                return '<span class="seom-gl-badge seom-gl-badge-pending">Tier 2</span>';
            }

            function gapRowHtml(r, idx) {
                var termAttr = esc(r.term);
                var cov = parseInt(r.crosswalk_count, 10) || 0;
                var vol = r.kw_volume ? Number(r.kw_volume).toLocaleString() : '<span style="color:#94a3b8;">—</span>';
                var matched = r.kw_match ? esc(r.kw_match) : '<span style="color:#94a3b8;">—</span>';
                var angleCell;
                if (r.suggested_title) {
                    angleCell = '<strong>' + esc(r.suggested_title) + '</strong>' +
                                '<br><span style="font-size:11px;color:#64748b;">kw: ' + esc(r.primary_keyword || '') +
                                ' · ' + esc(r.content_type || '') + '</span>';
                } else {
                    angleCell = '<span style="color:#94a3b8;font-size:12px;">(not generated)</span>';
                }
                return '<tr data-idx="' + idx + '" data-term="' + termAttr + '">' +
                    '<td><input type="checkbox" class="seom-gl-gap-check" data-idx="' + idx + '"></td>' +
                    '<td><strong>' + esc(r.term) + '</strong>' +
                        (r.topic_queued_at ? '<br><span style="font-size:10px;color:#dc2626;">queued ' + esc(r.topic_queued_at) + '</span>' : '') + '</td>' +
                    '<td>' + gapBadge(r.tier) + '</td>' +
                    '<td style="font-size:12px;">' + matched + '</td>' +
                    '<td>' + vol + '</td>' +
                    '<td>' + cov + '</td>' +
                    '<td style="font-size:12px;">' + esc(r.primary_area || '—') + '</td>' +
                    '<td>' + angleCell + '</td>' +
                '</tr>';
            }

            function gapRenderAreas(areas) {
                var $sel = $('#seom-gl-gap-area');
                if ($sel.data('populated')) return;
                $sel.data('populated', true);
                (areas || []).forEach(function (a) {
                    if (!a.primary_area) return;
                    $sel.append('<option value="' + esc(a.primary_area) + '">' +
                        esc(a.primary_area) + ' (' + a.cnt + ')</option>');
                });
            }

            function gapUpdateButtons() {
                var anyChecked = $('.seom-gl-gap-check:checked').length > 0;
                var anyAngled = gapRows.some(function (r) { return r.suggested_title; });
                // Generate: enabled when any rows are checked and not yet angled
                var checkedNeedAngle = $('.seom-gl-gap-check:checked').toArray().some(function (cb) {
                    var idx = parseInt($(cb).data('idx'), 10);
                    return gapRows[idx] && !gapRows[idx].suggested_title;
                });
                // Queue: enabled when any checked row HAS an angle
                var checkedHaveAngle = $('.seom-gl-gap-check:checked').toArray().some(function (cb) {
                    var idx = parseInt($(cb).data('idx'), 10);
                    return gapRows[idx] && gapRows[idx].suggested_title;
                });
                $('#seom-gl-gap-generate').prop('disabled', !(anyChecked && checkedNeedAngle));
                $('#seom-gl-gap-queue').prop('disabled', !(anyChecked && checkedHaveAngle));
            }

            function gapLoad() {
                $('#seom-gl-gap-table tbody').html(
                    '<tr><td colspan="8" style="text-align:center;color:#64748b;padding:24px;">Loading…</td></tr>'
                );
                $.post(ajaxurl, {
                    action:          'seom_glossary_gap_list',
                    nonce:           seom_nonce,
                    coverage_max:    gap.coverage,
                    primary_area:    gap.area,
                    tier:            gap.tier,
                    exclude_queued:  gap.excludeQueued ? 1 : 0,
                    page:            gap.page,
                    per_page:        gap.perPage,
                }, function (resp) {
                    if (!resp.success) {
                        $('#seom-gl-gap-table tbody').html(
                            '<tr><td colspan="8" style="text-align:center;color:#dc2626;padding:24px;">' +
                            esc(resp.data || 'Load failed') + '</td></tr>'
                        );
                        return;
                    }
                    gapRenderAreas(resp.data.areas);
                    gapRows = resp.data.rows || [];
                    var c = resp.data.counts;
                    $('#seom-gl-gap-counts').text(
                        'Pool: ' + c.total + ' · Tier 1: ' + c.tier1 + ' · Tier 2: ' + c.tier2
                    );
                    if (!gapRows.length) {
                        $('#seom-gl-gap-table tbody').html(
                            '<tr><td colspan="8" style="text-align:center;color:#64748b;padding:24px;">No matching gap candidates.</td></tr>'
                        );
                    } else {
                        var html = gapRows.map(function (r, i) { return gapRowHtml(r, i); }).join('');
                        $('#seom-gl-gap-table tbody').html(html);
                    }
                    gapUpdateButtons();
                });
            }

            $('#seom-gl-gap-refresh').on('click', function () { gap.page = 1; gapLoad(); });
            $('#seom-gl-gap-coverage').on('change', function () { gap.coverage = parseInt($(this).val(), 10) || 0; gap.page = 1; gapLoad(); });
            $('#seom-gl-gap-area').on('change', function () { gap.area = $(this).val(); gap.page = 1; gapLoad(); });
            $('#seom-gl-gap-tier').on('change', function () { gap.tier = parseInt($(this).val(), 10) || 0; gap.page = 1; gapLoad(); });
            $('#seom-gl-gap-exclude-queued').on('change', function () { gap.excludeQueued = $(this).is(':checked'); gap.page = 1; gapLoad(); });

            $('#seom-gl-gap-select-all').on('change', function () {
                var checked = $(this).is(':checked');
                $('.seom-gl-gap-check').prop('checked', checked);
                gapUpdateButtons();
            });
            $(document).on('change', '.seom-gl-gap-check', gapUpdateButtons);

            // Generate angles for currently-checked rows that DON'T have an
            // angle yet. Batched at 20 per API call.
            $('#seom-gl-gap-generate').on('click', function () {
                var selected = $('.seom-gl-gap-check:checked').toArray()
                    .map(function (cb) { return parseInt($(cb).data('idx'), 10); })
                    .filter(function (idx) { return gapRows[idx] && !gapRows[idx].suggested_title; });
                if (!selected.length) return;

                var batches = [];
                while (selected.length) batches.push(selected.splice(0, 20));

                $('#seom-gl-gap-generate').prop('disabled', true).text('Generating…');
                $('#seom-gl-gap-status').text('Generating ' + batches.reduce(function(a,b){return a+b.length;}, 0) + ' angles…');

                var done = 0;
                (function nextBatch() {
                    var batch = batches.shift();
                    if (!batch) {
                        $('#seom-gl-gap-generate').prop('disabled', false).text('Generate angles for selected');
                        $('#seom-gl-gap-status').text('Generated ' + done + ' angles.');
                        gapUpdateButtons();
                        return;
                    }
                    var termNames = batch.map(function (idx) { return gapRows[idx].term; });
                    $.post(ajaxurl, {
                        action: 'seom_glossary_gap_angles',
                        nonce:  seom_nonce,
                        terms:  JSON.stringify(termNames),
                    }, function (resp) {
                        if (!resp.success) {
                            alert('Generation failed: ' + (resp.data || 'unknown error'));
                            $('#seom-gl-gap-generate').prop('disabled', false).text('Generate angles for selected');
                            $('#seom-gl-gap-status').text('');
                            return;
                        }
                        (resp.data.angles || []).forEach(function (a) {
                            // Locate the source row by term and merge the angle fields in.
                            var idx = gapRows.findIndex(function (r) { return r.term === a.term; });
                            if (idx >= 0) {
                                gapRows[idx].suggested_title = a.suggested_title;
                                gapRows[idx].primary_keyword = a.primary_keyword;
                                gapRows[idx].content_type    = a.content_type;
                                gapRows[idx].kw_tag          = a.kw_tag;
                                done++;
                                // Re-render just this row in place.
                                $('#seom-gl-gap-table tbody tr[data-idx="' + idx + '"]')
                                    .replaceWith(gapRowHtml(gapRows[idx], idx));
                                $('#seom-gl-gap-table tbody tr[data-idx="' + idx + '"] .seom-gl-gap-check')
                                    .prop('checked', true);
                            }
                        });
                        nextBatch();
                    }).fail(function () {
                        alert('Network error generating angles.');
                        $('#seom-gl-gap-generate').prop('disabled', false).text('Generate angles for selected');
                        $('#seom-gl-gap-status').text('');
                    });
                })();
            });

            // Queue the currently-checked rows that HAVE an angle.
            $('#seom-gl-gap-queue').on('click', function () {
                var picked = $('.seom-gl-gap-check:checked').toArray()
                    .map(function (cb) { return parseInt($(cb).data('idx'), 10); })
                    .map(function (idx) { return gapRows[idx]; })
                    .filter(function (r) { return r && r.suggested_title; });
                if (!picked.length) return;
                if (!confirm('Queue ' + picked.length + ' topic(s) into the blog queue?')) return;

                var productId = parseInt($('#seom-gl-gap-product-id').val(), 10) || 0;
                $('#seom-gl-gap-queue').prop('disabled', true).text('Queuing…');
                $.post(ajaxurl, {
                    action:               'seom_glossary_gap_queue',
                    nonce:                seom_nonce,
                    angles:               JSON.stringify(picked),
                    featured_product_id:  productId,
                }, function (resp) {
                    $('#seom-gl-gap-queue').prop('disabled', false).text('Queue selected as topics');
                    if (!resp.success) {
                        alert('Queue failed: ' + (resp.data || 'unknown error'));
                        return;
                    }
                    $('#seom-gl-gap-status').text(
                        'Queued ' + resp.data.queued + ' topic(s)' +
                        (resp.data.skipped ? ' (skipped ' + resp.data.skipped + ' duplicates)' : '')
                    );
                    gapLoad();
                });
            });

            // ── Batch API panel ──────────────────────────────────────
            //
            // Shows either the "idle" submit form OR the "active" status
            // panel based on the current batch state. The status panel
            // auto-refreshes every 30s when the batch isn't in a terminal
            // state — between auto-refreshes the server-side cron polls
            // OpenAI every 15 min, so the UI is just reflecting state.
            var batchTimer = null;

            function batchTerminalStatus(s) {
                return s === 'completed' || s === 'failed' || s === 'expired' || s === 'cancelled';
            }

            function batchRenderStatus(state) {
                if (!state) {
                    $('#seom-gl-batchapi-idle').show();
                    $('#seom-gl-batchapi-active').hide();
                    if (batchTimer) { clearInterval(batchTimer); batchTimer = null; }
                    return;
                }
                $('#seom-gl-batchapi-idle').hide();
                $('#seom-gl-batchapi-active').show();
                $('#seom-gl-batchapi-id').text(state.batch_id);

                var s = state.status || '';
                var badgeCls = 'seom-gl-badge seom-gl-badge-pending';
                if (s === 'completed') badgeCls = 'seom-gl-badge seom-gl-badge-expanded';
                else if (s === 'failed' || s === 'expired' || s === 'cancelled') badgeCls = 'seom-gl-badge seom-gl-badge-unscanned';
                else if (s === 'in_progress' || s === 'finalizing') badgeCls = 'seom-gl-badge seom-gl-badge-scanned';
                $('#seom-gl-batchapi-status-badge').attr('class', badgeCls).text(s);

                $('#seom-gl-batchapi-submitted-at').text(state.submitted_at || '');
                $('#seom-gl-batchapi-polled-at').text(state.last_polled_at || 'never');
                $('#seom-gl-batchapi-total').text(Number(state.total_count || 0).toLocaleString());

                var prov = '—';
                if (state.provider_total != null) {
                    prov = Number(state.provider_completed || 0).toLocaleString() + ' / ' + Number(state.provider_total || 0).toLocaleString();
                    if (state.provider_failed) prov += ' (' + Number(state.provider_failed).toLocaleString() + ' failed)';
                }
                $('#seom-gl-batchapi-provider-progress').text(prov);

                var applied = 'not yet';
                if (state.applied) {
                    applied = Number(state.completed_count || 0).toLocaleString() + ' completed';
                    if (state.failed_count) applied += ' · ' + Number(state.failed_count).toLocaleString() + ' failed';
                }
                $('#seom-gl-batchapi-applied').text(applied);

                $('#seom-gl-batchapi-dismiss').toggle(!!state.applied || batchTerminalStatus(state.status));
                $('#seom-gl-batchapi-cancel').toggle(!batchTerminalStatus(state.status));

                // Auto-refresh until terminal — every 30s.
                if (!batchTerminalStatus(state.status) && !batchTimer) {
                    batchTimer = setInterval(batchRefresh, 30000);
                }
                if (batchTerminalStatus(state.status) && batchTimer) {
                    clearInterval(batchTimer);
                    batchTimer = null;
                }
            }

            function batchRefresh(forceFetch) {
                var action = forceFetch ? 'seom_glossary_batch_poll' : 'seom_glossary_batch_status';
                $.post(ajaxurl, { action: action, nonce: seom_nonce }, function (resp) {
                    if (!resp.success) {
                        $('#seom-gl-batchapi-action-status').text('Refresh failed: ' + (resp.data || 'unknown error'));
                        return;
                    }
                    $('#seom-gl-batchapi-action-status').text('Refreshed at ' + new Date().toLocaleTimeString());
                    batchRenderStatus(resp.data || null);
                });
            }

            $('#seom-gl-batchapi-submit').on('click', function () {
                var jobType = $('#seom-gl-batchapi-job-type').val() || 'expand';
                var limit   = parseInt($('#seom-gl-batchapi-limit').val(), 10) || 0;
                var label   = jobType === 'seo_meta' ? 'SEO meta enrichment' : 'expansion';
                if (!confirm('Submit a ' + label + ' batch to OpenAI? This kicks off an async job (within 24h).')) return;
                var $btn = $(this).prop('disabled', true).text('Submitting…');
                $('#seom-gl-batchapi-submit-status').text('Uploading JSONL + creating batch (10-30s)…');
                $.post(ajaxurl, {
                    action:   'seom_glossary_batch_submit',
                    nonce:    seom_nonce,
                    limit:    limit,
                    job_type: jobType,
                }, function (resp) {
                    $btn.prop('disabled', false).text('Submit batch');
                    if (!resp.success) {
                        $('#seom-gl-batchapi-submit-status').text('Submit failed: ' + (resp.data || 'unknown error'));
                        return;
                    }
                    $('#seom-gl-batchapi-submit-status').text('Submitted.');
                    batchRenderStatus(resp.data);
                }).fail(function () {
                    $btn.prop('disabled', false).text('Submit batch');
                    $('#seom-gl-batchapi-submit-status').text('Network error.');
                });
            });

            $('#seom-gl-batchapi-refresh').on('click', function () { batchRefresh(true); });

            $('#seom-gl-batchapi-cancel').on('click', function () {
                if (!confirm('Cancel this batch? Already-completed responses inside it will be lost.')) return;
                $.post(ajaxurl, { action: 'seom_glossary_batch_cancel', nonce: seom_nonce }, function (resp) {
                    if (!resp.success) { alert(resp.data || 'Cancel failed'); return; }
                    batchRenderStatus(resp.data);
                });
            });

            $('#seom-gl-batchapi-dismiss').on('click', function () {
                if (!confirm('Dismiss this batch from the dashboard? You can submit a new one after.')) return;
                $.post(ajaxurl, { action: 'seom_glossary_batch_dismiss', nonce: seom_nonce }, function (resp) {
                    if (!resp.success) { alert('Dismiss failed'); return; }
                    batchRenderStatus(null);
                    load(); // refresh main term list (counts may have changed)
                });
            });

            // Hydrate timer if rendered server-side as active.
            <?php
            $bs_status = $batch_state['status'] ?? '';
            $bs_terminal = in_array($bs_status, ['completed', 'failed', 'expired', 'cancelled'], true);
            if ($batch_state && !$bs_terminal) :
            ?>
            batchTimer = setInterval(batchRefresh, 30000);
            <?php endif; ?>

            // ── Init ─────────────────────────────────────────────────
            load();
            gapLoad();
        })(jQuery);
        </script>
        <?php
    }
}
