<?php
/**
 * ITU Online Child Theme - Functions
 *
 * @package ITU_Theme
 */

defined('ABSPATH') || exit;

/**
 * Enqueue parent + child styles.
 */
add_action('wp_enqueue_scripts', function () {
    // Parent theme
    wp_enqueue_style(
        'twentytwentyfive-style',
        get_template_directory_uri() . '/style.css',
        [],
        wp_get_theme('twentytwentyfive')->get('Version')
    );

    // Child theme
    wp_enqueue_style(
        'itu-theme-style',
        get_stylesheet_uri(),
        ['twentytwentyfive-style'],
        wp_get_theme()->get('Version')
    );

    // Certs carousel interaction
    wp_enqueue_script(
        'itu-certs-carousel',
        get_stylesheet_directory_uri() . '/assets/js/certs-carousel.js',
        [],
        wp_get_theme()->get('Version'),
        true
    );

    // Topbar search toggle
    wp_enqueue_script(
        'itu-topbar-search',
        get_stylesheet_directory_uri() . '/assets/js/topbar-search.js',
        [],
        wp_get_theme()->get('Version'),
        true
    );

    // Category course carousel arrows
    wp_enqueue_script(
        'itu-cat-carousel',
        get_stylesheet_directory_uri() . '/assets/js/cat-carousel.js',
        [],
        wp_get_theme()->get('Version'),
        true
    );

    // Course catalog filter
    wp_enqueue_script(
        'itu-course-catalog',
        get_stylesheet_directory_uri() . '/assets/js/course-catalog.js',
        [],
        wp_get_theme()->get('Version'),
        true
    );
    wp_localize_script('itu-course-catalog', 'ituCatalog', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'restUrl' => rest_url('itu/v1/carousel'),
    ]);

    // Spotlight hover interaction
    wp_enqueue_script(
        'itu-spotlight',
        get_stylesheet_directory_uri() . '/assets/js/spotlight.js',
        [],
        wp_get_theme()->get('Version'),
        true
    );
});

/**
 * Theme setup.
 */
add_action('after_setup_theme', function () {
    // WooCommerce support
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');

    // Core WordPress features
    add_theme_support('responsive-embeds');
    add_theme_support('custom-logo', [
        'height'      => 60,
        'width'       => 200,
        'flex-height' => true,
        'flex-width'  => true,
    ]);

    // Load child theme stylesheet in the Site Editor
    add_editor_style('style.css');
});

/**
 * Override VTS plugin inline styles (loads last to win specificity wars).
 */
add_action('wp_head', function () {
    echo '<style>
/* VTS — scoped overrides with #teams-contact for max specificity */
#teams-contact .vts-plans,
#teams-contact .vts-plans .vts-plans__left,
#teams-contact .vts-plans .vts-includes__list li,
#teams-contact .vts-plans .vts-inputlabel,
#teams-contact .vts-plans .vts-total,
#teams-contact .vts-plans .vts-total span,
#teams-contact .vts-plans .vts-note,
#teams-contact .vts-plans .vts-price__per{color:#111!important}
#teams-contact .vts-plans{background:none!important;padding:0!important;border-radius:0!important}
#teams-contact .vts-plans__inner{background:#fff!important;border:1px solid #e0e0e0!important;overflow:hidden!important;display:grid!important;grid-template-columns:1.6fr 0.8fr!important;gap:0!important;align-items:stretch!important;box-shadow:0 4px 24px rgba(0,0,0,0.06)!important}
#teams-contact .vts-plans__left{padding:32px!important}
#teams-contact .vts-switch{display:inline-flex!important;width:auto!important;margin:0 0 24px!important}
#teams-contact .vts-switch__track{background:none!important;border:1px solid #e0e0e0!important;border-radius:999px!important;display:inline-flex!important;position:relative!important}
#teams-contact .vts-switch__label{color:#9e9e9e!important;padding:10px 24px!important;font-size:.8125rem!important;font-weight:600!important;position:relative!important;z-index:2!important;text-align:center!important}
#teams-contact .vts-switch__thumb{background:#111!important;border-radius:999px!important;top:0!important;left:0!important;width:50%!important;height:100%!important;z-index:1!important;position:absolute!important}
#teams-contact .vts-switch__input:not(:checked)+.vts-switch__track .vts-switch__label--left{color:#fff!important}
#teams-contact .vts-switch__input:checked+.vts-switch__track .vts-switch__label--right{color:#fff!important}
#teams-contact .vts-includes{display:grid!important;grid-template-columns:1fr 1fr!important;gap:4px 30px!important}
#teams-contact .vts-includes__list{list-style:none!important;margin:0!important;padding:0!important}
#teams-contact .vts-includes__list li{list-style:none!important;position:relative!important;padding:6px 0 6px 20px!important;margin:0!important;line-height:1.4!important;font-size:.8125rem!important;color:#686868!important;border-bottom:none!important}
#teams-contact .vts-includes__list li::marker{content:""!important;display:none!important}
#teams-contact .vts-includes__list li::before{content:"✓"!important;position:absolute!important;left:0!important;top:6px!important;width:auto!important;height:auto!important;border-radius:0!important;background:none!important;color:#C026D3!important;font-weight:700!important;font-size:.75rem!important;-webkit-mask:none!important;mask:none!important}
#teams-contact .vts-card{background:#f5f5f5!important;border:none!important;border-left:1px solid #e0e0e0!important;border-radius:0!important;padding:32px!important;max-width:none!important}
#teams-contact .vts-price__number{font-size:2.5rem!important;font-weight:800!important;color:#111!important;letter-spacing:-0.02em!important;display:flex!important;align-items:baseline!important;gap:4px!important}
#teams-contact .vts-price__per{color:#9e9e9e!important;font-size:.8125rem!important;opacity:1!important}
#teams-contact .vts-savings{color:#C026D3!important;background:rgba(192,38,211,.06)!important;border:1px solid rgba(192,38,211,.2)!important;border-radius:6px!important;font-size:.75rem!important;margin:8px 0 16px!important;padding:6px 10px!important;display:block!important;text-align:center!important}
#teams-contact .vts-inputlabel{color:#9e9e9e!important;font-weight:600!important;font-size:.6875rem!important;text-transform:uppercase!important;letter-spacing:.5px!important;display:block!important;margin:12px 0 6px!important}
#teams-contact .vts-input{background:#fff!important;color:#111!important;border:1px solid #e0e0e0!important;border-radius:6px!important;padding:12px 16px!important;width:100%!important;outline:none!important}
#teams-contact .vts-input:focus{border-color:#C026D3!important}
#teams-contact .vts-total{color:#111!important;display:flex!important;justify-content:space-between!important;align-items:center!important;margin:16px 0!important}
#teams-contact .vts-total__amount{font-weight:800!important;font-size:1.5rem!important;color:#111!important}
#teams-contact .vts-cta{background:#C026D3!important;color:#fff!important;border:0!important;border-radius:0!important;border-top-right-radius:12px!important;box-shadow:none!important;-webkit-appearance:none!important;padding:.875rem 2rem!important;font-weight:600!important;letter-spacing:.5px!important;text-transform:uppercase!important;font-size:.8125rem!important;width:100%!important;cursor:pointer!important}
#teams-contact .vts-cta:hover{background:#111!important;color:#fff!important}
#teams-contact .vts-note{color:#9e9e9e!important;font-size:.8125rem!important}
@media(max-width:960px){#teams-contact .vts-plans__inner{grid-template-columns:1fr!important}#teams-contact .vts-card{border-left:none!important;border-top:1px solid #e0e0e0!important}}
</style>';
}, 999);

/**
 * Inject promo cards into blog archive grids via JS.
 */
add_action('wp_footer', function () {
    if (!is_archive() && !is_home()) return;
    $promos = itu_get_promo_cards();
    ?>
    <script>
    (function() {
        var grid = document.querySelector('.itu-blog-grid');
        if (!grid) return;
        var items = grid.querySelectorAll(':scope > li');
        if (items.length < 4) return;

        var promos = <?php echo json_encode(array_values($promos)); ?>;

        function makePromoLi(p) {
            return '<li class="wp-block-post itu-promo-li">'
                + '<div class="wp-block-group itu-blog-card itu-promo-card">'
                + '<span class="itu-promo-card__eyebrow">' + p.eyebrow + '</span>'
                + '<h3 class="itu-promo-card__title">' + p.title + '</h3>'
                + '<p class="itu-promo-card__desc">' + p.description + '</p>'
                + '<a href="' + p.cta_url + '" class="itu-promo-card__cta">' + p.cta_text + '</a>'
                + '</div></li>';
        }

        // Insert both promos: after position 4 and position 8 (making 10 blogs + 2 promos = 12 = 3x4 grid)
        var inserted = 0;
        var refreshedItems = grid.querySelectorAll(':scope > li');
        if (refreshedItems.length >= 5) {
            refreshedItems[4].insertAdjacentHTML('afterend', makePromoLi(promos[0]));
            inserted++;
        }
        refreshedItems = grid.querySelectorAll(':scope > li');
        if (refreshedItems.length >= 10) {
            refreshedItems[9].insertAdjacentHTML('afterend', makePromoLi(promos[1 % promos.length]));
            inserted++;
        }
    })();
    </script>
    <?php
}, 50);

/**
 * VTS seat count validation — minimum 2 users required.
 */
add_action('wp_footer', function () {
    ?>
    <script>
    window.addEventListener('load', function() {
        setTimeout(function() {
            var seatsInput = document.getElementById('vts-seat-count');
            var origBtn = document.getElementById('vts-add-to-cart');
            var noteEl = document.querySelector('.vts-note');
            if (!seatsInput || !origBtn) return;

            // Clone button to strip ALL plugin event listeners
            var addBtn = origBtn.cloneNode(true);
            origBtn.parentNode.replaceChild(addBtn, origBtn);

            function validateSeats() {
                var qty = parseInt(seatsInput.value || '0', 10);
                if (isNaN(qty) || qty < 2) {
                    addBtn.disabled = true;
                    addBtn.style.opacity = '0.5';
                    addBtn.style.cursor = 'not-allowed';
                    if (noteEl) {
                        noteEl.textContent = 'A minimum of 2 users is required for team plans.';
                        noteEl.style.color = '#C026D3';
                        noteEl.style.fontWeight = '600';
                        noteEl.style.fontSize = '0.8125rem';
                    }
                } else {
                    addBtn.disabled = false;
                    addBtn.style.opacity = '1';
                    addBtn.style.cursor = 'pointer';
                    if (noteEl) {
                        noteEl.textContent = '';
                    }
                }
            }

            seatsInput.addEventListener('input', validateSeats);
            seatsInput.addEventListener('change', validateSeats);
            seatsInput.addEventListener('keyup', validateSeats);

            addBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var qty = parseInt(seatsInput.value || '0', 10);
                if (isNaN(qty) || qty < 2) {
                    validateSeats();
                    return;
                }
                var sec = document.querySelector('.vts-plans');
                if (!sec) return;
                var toggle = document.getElementById('vts-billing-toggle');
                var currentMode = toggle && toggle.checked ? 'monthly' : 'annual';
                var productId = currentMode === 'monthly' ? sec.dataset.monthlyId : sec.dataset.annualId;
                var url = new URL(window.location.origin + '/');
                url.searchParams.set('add-to-cart', productId);
                url.searchParams.set('quantity', qty);
                url.searchParams.set('vts_plan_mode', currentMode);
                url.searchParams.set('vts_seat_count', qty);
                url.searchParams.set('vts_to_checkout', '1');
                if (noteEl) noteEl.textContent = 'Adding to cart…';
                window.location.href = url.toString();
            });

            // Also intercept any navigation attempt as a final safety net
            window.addEventListener('beforeunload', function(e) {
                var qty = parseInt(seatsInput.value || '0', 10);
                if (document.activeElement === addBtn && (isNaN(qty) || qty < 2)) {
                    e.preventDefault();
                }
            });

            validateSeats();
        }, 500); // Wait for plugin script to finish
    });
    </script>
    <?php
}, 997);

/**
 * Replace site-title block with logo image on WooCommerce block pages (cart, checkout, account).
 */
add_filter('render_block', function ($html, $block) {
    if (empty($block['blockName']) || $block['blockName'] !== 'core/site-title') return $html;
    if (! function_exists('is_cart')) return $html;
    if (! is_cart() && ! is_checkout() && ! is_account_page()) return $html;

    return '<div class="wp-block-site-title"><a href="/">'
        . '<img src="/wp-content/uploads/2026/03/itu-logo-flat-white.png" alt="ITU Online IT Training" style="height:55px;width:auto;filter:invert(1);margin-top:30px;" />'
        . '</a></div>';
}, 10, 2);

/**
 * Render cart count on page load and ensure WooCommerce cart fragments JS is enqueued.
 */
add_action('wp_enqueue_scripts', function () {
    if (function_exists('WC') && ! is_cart() && ! is_checkout()) {
        wp_enqueue_script('wc-cart-fragments');
    }
});

add_action('wp_footer', function () {
    if (! function_exists('WC') || ! WC()->cart) return;
    $count = WC()->cart->get_cart_contents_count();
    ?>
    <script>
    (function() {
        var serverCount = <?php echo (int) $count; ?>;

        function updateCartBadge(count) {
            document.querySelectorAll('.itu-cart-count').forEach(function(el) {
                el.textContent = count > 0 ? count : '';
            });
        }

        // Server-rendered count is authoritative on page load — override any stale sessionStorage
        updateCartBadge(serverCount);

        // Clear stale WC fragment cache so it doesn't restore old count
        try {
            var wcKey = Object.keys(sessionStorage).find(function(k) { return k.indexOf('wc_fragments_') === 0; });
            if (wcKey) {
                var frags = JSON.parse(sessionStorage.getItem(wcKey));
                if (frags && frags['span.itu-cart-count']) {
                    frags['span.itu-cart-count'] = '<span class="itu-cart-count" id="itu-cart-count">' + (serverCount > 0 ? serverCount : '') + '</span>';
                    sessionStorage.setItem(wcKey, JSON.stringify(frags));
                }
            }
        } catch(e) {}

        // Listen for all WooCommerce cart change events
        if (typeof jQuery !== 'undefined') {
            function refreshCount() {
                if (typeof wc_cart_fragments_params === 'undefined') return;
                jQuery.ajax({
                    url: wc_cart_fragments_params.wc_ajax_url.toString().replace('%%endpoint%%', 'get_refreshed_fragments'),
                    type: 'POST',
                    success: function(data) {
                        if (data && data.fragments && data.fragments['span.itu-cart-count']) {
                            var temp = document.createElement('div');
                            temp.innerHTML = data.fragments['span.itu-cart-count'];
                            var newCount = parseInt(temp.textContent.trim()) || 0;
                            updateCartBadge(newCount);
                        }
                    }
                });
            }

            jQuery(document.body).on(
                'added_to_cart removed_from_cart updated_cart_totals updated_wc_div wc_cart_emptied',
                refreshCount
            );

            // Also watch for fragment restoration that might overwrite our server count
            jQuery(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function() {
                // After fragments load, re-assert server count if page just loaded
                setTimeout(function() {
                    var el = document.querySelector('.itu-cart-count');
                    if (el) {
                        var displayed = parseInt(el.textContent) || 0;
                        // If fragments restored a stale value different from server, fix it
                        if (displayed !== serverCount && document.readyState === 'complete') {
                            // Only override on initial load, not after user actions
                        }
                    }
                }, 100);
            });
        }
    })();
    </script>
    <?php
}, 50);

/**
 * Output individual training plan prices as JS for the pricing page.
 */
add_action('wp_footer', function () {
    if (! function_exists('wc_get_product')) return;

    $plans = [
        'monthly'  => 1015871,
        'annual'   => 30490,
        'lifetime' => 6740,
        'quarterly' => 1200244,
    ];

    $data = [];
    foreach ($plans as $key => $pid) {
        $product = wc_get_product($pid);
        if (! $product) continue;
        $regular = (float) $product->get_regular_price();
        $sale    = (float) $product->get_sale_price();
        $display = $sale ? $sale : $regular;
        $data[$key] = [
            'id'      => $pid,
            'regular' => $regular,
            'sale'    => $sale ?: null,
            'display' => $display,
            'url'     => esc_url(add_query_arg('add-to-cart', $pid, wc_get_cart_url())),
        ];
    }
    ?>
    <script>
    var ituPlanPrices = <?php echo json_encode($data); ?>;
    (function() {
        if (typeof ituPlanPrices === 'undefined') return;

        function fmt(v) { return '$' + parseFloat(v).toFixed(2); }
        function fmtInt(v) { return '$' + Math.round(parseFloat(v)); }

        // Map plan keys to DOM data-plan attributes
        var planMap = {
            'monthly':   { monthly: true },
            'annual':    { monthly: true, months: 12 },
            'lifetime':  { monthly: false },
            'quarterly': { monthly: true, months: 3 }
        };

        document.querySelectorAll('[data-plan]').forEach(function(card) {
            var key = card.dataset.plan;
            var plan = ituPlanPrices[key];
            if (!plan) return;

            var amountEl = card.querySelector('.itu-plan-card__amount');
            var origEl = card.querySelector('.itu-plan-card__original');
            var billingEl = card.querySelector('.itu-plan-card__billing');
            var ctaEl = card.querySelector('.itu-button');
            var pm = planMap[key];

            if (pm && pm.monthly && pm.months) {
                // Show as monthly rate
                var displayMonthly = plan.display / pm.months;
                if (amountEl) amountEl.textContent = fmt(displayMonthly);
                if (plan.sale && origEl) {
                    var regMonthly = plan.regular / pm.months;
                    origEl.textContent = fmt(regMonthly);
                    origEl.style.display = 'inline';
                }
                if (billingEl) {
                    var label = key === 'annual' ? 'Billed annually at ' + fmt(plan.display) : 'Billed every 90 days at ' + fmt(plan.display);
                    billingEl.textContent = label;
                }
            } else if (pm && pm.monthly) {
                // Monthly — show as-is
                if (amountEl) amountEl.textContent = fmt(plan.display);
                if (plan.sale && origEl) {
                    origEl.textContent = fmt(plan.regular);
                    origEl.style.display = 'inline';
                }
            } else {
                // Lifetime — one-time
                if (amountEl) amountEl.textContent = fmt(plan.display);
                if (plan.sale && origEl) {
                    origEl.textContent = fmt(plan.regular);
                    origEl.style.display = 'inline';
                }
            }

            // Set CTA link to product
            if (ctaEl && plan.url) {
                ctaEl.href = plan.url;
            }
        });

        // Calculate annual vs monthly savings percentage
        if (ituPlanPrices.annual && ituPlanPrices.monthly) {
            var annualPerMonth = ituPlanPrices.annual.display / 12;
            var monthlyPrice = ituPlanPrices.monthly.display;
            if (monthlyPrice > 0) {
                var savePct = Math.round((1 - annualPerMonth / monthlyPrice) * 100);
                document.querySelectorAll('.itu-plan-savings').forEach(function(el) {
                    el.textContent = 'Save ' + savePct + '%';
                });
            }
        }
    })();

    // Dynamic category list for plan drawer
    <?php
    $cats = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'parent'     => 0,
        'exclude'    => [get_option('default_product_cat')],
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    $cat_list = [];
    if (! empty($cats) && ! is_wp_error($cats)) {
        foreach ($cats as $cat) {
            if ($cat->count <= 0) continue;
            if (get_term_meta($cat->term_id, 'itu_catalog_hidden', true) === '1') continue;
            $cat_list[] = ['name' => $cat->name, 'slug' => $cat->slug];
        }
    }
    ?>
    var ituDrawerCategories = <?php echo json_encode($cat_list); ?>;

    // All-access library stats
    <?php
    $aa_sku = 'all-access-1-year';
    $aa_hours = itu_get_course_stat($aa_sku, 'video_hours', 'ec_get_course_video_hours', 'total_hours');
    $aa_questions = itu_get_course_stat($aa_sku, 'question_count', 'ec_get_course_prep_question_count', 'content_test_question_count');
    ?>
    var ituLibraryStats = {
        hours: <?php echo $aa_hours ? json_encode(intval($aa_hours)) : 'null'; ?>,
        questions: <?php echo $aa_questions ? json_encode(intval($aa_questions)) : 'null'; ?>
    };
    (function() {
        if (!ituLibraryStats.hours && !ituLibraryStats.questions) return;
        var hrs = ituLibraryStats.hours ? ituLibraryStats.hours.toLocaleString() : '';
        var qs = ituLibraryStats.questions ? ituLibraryStats.questions.toLocaleString() : '';
        // Update data-stat spans
        document.querySelectorAll('[data-stat="hours"]').forEach(function(el) { if (hrs) el.textContent = hrs; });
        document.querySelectorAll('[data-stat="questions"]').forEach(function(el) { if (qs) el.textContent = qs; });
        // Also update VTS plugin list items by text content (fallback if data-stat not in plugin HTML)
        document.querySelectorAll('.vts-includes__list li').forEach(function(li) {
            var text = li.textContent;
            if (hrs && text.match(/3[,.]?000/)) {
                li.innerHTML = li.innerHTML.replace(/3[,.]?000/, hrs);
            }
            if (qs && text.match(/22[,.]?000/)) {
                li.innerHTML = li.innerHTML.replace(/22[,.]?000/, qs);
            }
        });
    })();
    </script>
    <?php
}, 998);

/**
 * Output VTS annual product pricing as JS variables for the ROI calculator.
 */
add_action('wp_footer', function () {
    if (! function_exists('wc_get_product')) return;
    $opts = get_option('vts_team_licensing_options', []);
    $pid  = ! empty($opts['annual_product_id']) ? absint($opts['annual_product_id']) : 0;
    if (! $pid) return;
    $product = wc_get_product($pid);
    if (! $product) return;
    $regular = (float) $product->get_regular_price();
    $sale    = (float) $product->get_sale_price();
    $display = $sale ? $sale : $regular;
    ?>
    <script>
    (function() {
        var priceEl = document.getElementById('g-price');
        var saleEl = document.getElementById('g-price-sale');
        var origEl = document.getElementById('g-price-orig');
        var badgeEl = document.getElementById('g-price-badge');
        if (!priceEl || !saleEl) return;
        var display = <?php echo $display; ?>;
        var regular = <?php echo $regular; ?>;
        priceEl.value = display;
        saleEl.textContent = '$' + display.toFixed(2);
        if (regular > display) {
            origEl.textContent = '$' + regular.toFixed(2);
            origEl.style.display = 'inline';
            var pct = Math.round((1 - display / regular) * 100);
            badgeEl.textContent = 'Save ' + pct + '%';
            badgeEl.style.display = 'inline';
        }
        if (typeof recalcAll === 'function') recalcAll();
    })();
    </script>
    <?php
}, 999);

/**
 * REST endpoint: get products by category slug for the plan drawer.
 */
add_action('rest_api_init', function () {
    register_rest_route('itu/v1', '/category-products', [
        'methods'  => 'GET',
        'callback' => function ($request) {
            $slug = sanitize_title($request->get_param('slug'));
            if (! $slug) return new WP_REST_Response([], 200);

            $term = get_term_by('slug', $slug, 'product_cat');
            if (! $term) return new WP_REST_Response([], 200);

            // Check transient cache
            $cache_key = 'itu_drawer_cat_' . $slug;
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return new WP_REST_Response($cached, 200);
            }

            $args = [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'tax_query'      => [[
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $term->term_id,
                ]],
            ];
            $query = new WP_Query($args);
            $products = array_map('wc_get_product', wp_list_pluck($query->posts, 'ID'));
            $products = array_filter($products);

            $results = [];
            foreach ($products as $product) {
                $img_id = $product->get_image_id();
                $results[] = [
                    'name'      => $product->get_name(),
                    'permalink' => $product->get_permalink(),
                    'image'     => $img_id ? wp_get_attachment_image_url($img_id, 'woocommerce_thumbnail') : wc_placeholder_img_src(),
                ];
            }

            set_transient($cache_key, $results, 30 * DAY_IN_SECONDS);
            return new WP_REST_Response($results, 200);
        },
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Auto-assign custom block templates by page slug.
 *
 * In block themes, custom templates are not matched by slug automatically —
 * they must be assigned in the editor (which writes to the DB). Since we share
 * the production database, we use this filter to assign templates in code so
 * production (Elementor) pages are not affected.
 *
 * We intercept the _wp_page_template meta lookup so WordPress believes the
 * template was assigned via the editor, without ever writing to the database.
 */
add_filter('get_post_metadata', function ($value, $post_id, $meta_key, $single) {
    if ($meta_key !== '_wp_page_template') {
        return $value;
    }

    // Map page slugs to custom template file names (registered in theme.json customTemplates)
    $slug_to_template = [
        'team-training'             => 'page-team-training',
        'certifications'            => 'page-certifications',
        'it-courses'                => 'page-it-courses',
        'reseller'                  => 'page-reseller',
        'resources'                 => 'page-resources',
        'it-certification-training' => 'page-it-certification-training',
        'about-us'                  => 'page-about-us',
        'privacy-policy'            => 'page-privacy-policy',
    ];

    $post = get_post($post_id);
    if (! $post) return $value;

    // Pages — match by slug
    if ($post->post_type === 'page' && isset($slug_to_template[$post->post_name])) {
        $template_slug = $slug_to_template[$post->post_name];
        return $single ? $template_slug : [$template_slug];
    }

    // Posts — match by category
    if ($post->post_type === 'post') {
        if (has_category('practice-tests', $post_id)) {
            $template_slug = 'single-practice-test';
            return $single ? $template_slug : [$template_slug];
        }
    }

    return $value;
}, 10, 4);

/**
 * Allow SVG uploads in the media library.
 */
add_filter('upload_mimes', function ($mimes) {
    $mimes['svg'] = 'image/svg+xml';
    $mimes['svgz'] = 'image/svg+xml';
    return $mimes;
});

add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if ($ext === 'svg') {
        $data['type'] = 'image/svg+xml';
        $data['ext'] = 'svg';
    }
    return $data;
}, 10, 4);

/**
 * Register block pattern category for ITU.
 */
add_action('init', function () {
    register_block_pattern_category('itu-patterns', [
        'label'       => __('ITU Patterns', 'itu-theme'),
        'description' => __('Custom patterns for the ITU Online theme.', 'itu-theme'),
    ]);
});

/**
 * Register custom block styles.
 */
add_action('init', function () {
    // Outline button with accent hover
    register_block_style('core/button', [
        'name'         => 'itu-outline',
        'label'        => __('ITU Outline', 'itu-theme'),
        'inline_style' => '
            .wp-block-button.is-style-itu-outline .wp-block-button__link {
                background: transparent;
                color: var(--wp--preset--color--contrast);
                border: 1px solid var(--wp--preset--color--contrast);
            }
            .wp-block-button.is-style-itu-outline .wp-block-button__link:hover {
                background: var(--wp--preset--color--accent-1);
                border-color: var(--wp--preset--color--accent-1);
                color: var(--wp--preset--color--base);
            }
        ',
    ]);

    // Accent underline heading
    register_block_style('core/heading', [
        'name'         => 'itu-accent-underline',
        'label'        => __('Accent Underline', 'itu-theme'),
        'inline_style' => '
            .is-style-itu-accent-underline {
                padding-bottom: 1rem;
                border-bottom: 3px solid var(--wp--preset--color--accent-1);
                display: inline-block;
            }
        ',
    ]);
});

/**
 * Get a single course stat from the LMS database (cached via transients).
 */
function itu_get_course_stat($sku, $transient_key, $procedure, $field) {
    $transient_name = $transient_key . '_' . $sku;
    $row = get_transient($transient_name);
    if ($row === false) {
        global $lmsdb;
        if (!$lmsdb) return null;
        $query = 'CALL ' . $procedure . '("' . esc_sql($sku) . '")';
        $results = $lmsdb->get_results($query);
        if (empty($results)) return null;
        $row = $results[0];
        set_transient($transient_name, $row, 7 * DAY_IN_SECONDS);
    }
    return isset($row->$field) ? $row->$field : null;
}

/**
 * Get enrollment count for a course by SKU from ec_staging.lms_enrollment_counts.
 */
function itu_get_enrollment_count($sku) {
    if (empty($sku)) return null;
    $transient_name = 'itu_enrolled_' . $sku;
    $count = get_transient($transient_name);
    if ($count !== false) return (int) $count;

    global $wpdb;

    $result = $wpdb->get_var($wpdb->prepare(
        "SELECT enrolled_count FROM lms_enrollment_counts WHERE sku = %s AND active = 1 LIMIT 1",
        $sku
    ));

    if ($result === null) return null;
    $count = (int) $result;
    set_transient($transient_name, $count, DAY_IN_SECONDS);
    return $count;
}

/**
 * Ensure wpautop runs on post content — classic editor content needs this
 * in block themes where wpautop may be skipped.
 */
/**
 * Apply wpautop only to classic (freeform) blocks via render_block filter.
 * Do NOT add wpautop to the_content — it mangles shortcode output.
 */
add_filter('render_block', function ($block_content, $block) {
    if ($block['blockName'] === 'core/freeform' && !empty(trim($block_content))) {
        $block_content = wpautop($block_content);
    }
    return $block_content;
}, 5, 2);

// Ensure wpautop is NOT on the_content (block themes + shortcodes don't mix with it)
remove_filter('the_content', 'wpautop');

// Strip <br> and <p></p> from any rendered content containing our card/carousel classes
add_filter('the_content', function ($content) {
    if (strpos($content, 'itu-certs__card') !== false || strpos($content, 'itu-plans-bar') !== false || strpos($content, 'itu-cat-row') !== false) {
        $content = str_replace(['<br />', '<br/>', '<br>'], '', $content);
        $content = str_replace(['<p></p>', '<p> </p>'], '', $content);
        $content = preg_replace('/<p>\s*<\/p>/', '', $content);
    }
    return $content;
}, 999);

/**
 * Add page-slug body class for page-specific styling.
 */
add_action('wp_footer', function () {
    if (!is_page()) return;
    $slug = get_post_field('post_name', get_the_ID());
    if ($slug) {
        echo '<script>document.body.classList.add("itu-page-' . esc_js($slug) . '");</script>';
    }
}, 1);

/**
 * Slack integration — sends lead notifications to a Slack channel via webhook.
 * Configure webhook URL at Tools → ITU Slack.
 */
define('ITU_SLACK_OPTION', 'itu_slack_webhook_url');

function itu_send_slack_notification($type, $data) {
    $webhook_url = get_option(ITU_SLACK_OPTION, '');
    if (empty($webhook_url)) return;

    $emoji = $type === 'team' ? '🏢' : ($type === 'reseller' ? '🤝' : '📩');
    $title = $type === 'team' ? 'New Team Training Inquiry' : ($type === 'reseller' ? 'New Reseller Inquiry' : 'New Inquiry');

    $fields = [];
    if (!empty($data['name']))      $fields[] = ['title' => 'Name', 'value' => $data['name'], 'short' => true];
    if (!empty($data['company']))   $fields[] = ['title' => 'Company', 'value' => $data['company'], 'short' => true];
    if (!empty($data['email']))     $fields[] = ['title' => 'Email', 'value' => $data['email'], 'short' => true];
    if (!empty($data['phone']))     $fields[] = ['title' => 'Phone', 'value' => $data['phone'], 'short' => true];
    if (!empty($data['team_size'])) $fields[] = ['title' => 'Team Size', 'value' => $data['team_size'], 'short' => true];
    if (!empty($data['source']))    $fields[] = ['title' => 'Source', 'value' => $data['source'], 'short' => true];
    if (!empty($data['country']))   $fields[] = ['title' => 'Country', 'value' => $data['country'], 'short' => true];
    if (!empty($data['years']))     $fields[] = ['title' => 'Years in Business', 'value' => $data['years'], 'short' => true];
    if (!empty($data['sales']))     $fields[] = ['title' => 'Est. Annual Sales', 'value' => $data['sales'], 'short' => true];
    if (!empty($data['comments']))  $fields[] = ['title' => 'Comments', 'value' => $data['comments'], 'short' => false];

    $payload = [
        'text' => $emoji . ' ' . $title,
        'attachments' => [[
            'color'  => '#C026D3',
            'fields' => $fields,
            'footer' => 'ITU Online • ' . current_time('F j, Y g:i A'),
        ]],
    ];

    wp_remote_post($webhook_url, [
        'body'    => json_encode($payload),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 10,
    ]);
}

// Slack admin settings
add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'ITU Slack',
        'ITU Slack',
        'manage_options',
        'itu-slack',
        'itu_slack_admin_page'
    );
});

function itu_slack_admin_page() {
    $saved = false;
    if (isset($_POST['itu_slack_save']) && check_admin_referer('itu_slack_nonce')) {
        update_option(ITU_SLACK_OPTION, esc_url_raw($_POST['slack_webhook']));
        $saved = true;
    }
    $webhook = get_option(ITU_SLACK_OPTION, '');
    ?>
    <div class="wrap">
        <h1>ITU Slack Integration</h1>
        <p>Send lead notifications to a Slack channel. Create an <a href="https://api.slack.com/messaging/webhooks" target="_blank">Incoming Webhook</a> in your Slack workspace and paste the URL below.</p>

        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible"><p><strong>Webhook URL saved.</strong></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('itu_slack_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>Webhook URL</th>
                    <td>
                        <input type="url" name="slack_webhook" value="<?php echo esc_attr($webhook); ?>" class="regular-text" style="width:100%;max-width:600px" placeholder="https://hooks.slack.com/services/..." />
                        <p class="description">Leave empty to disable Slack notifications.</p>
                    </td>
                </tr>
                <tr>
                    <th>Notifications sent for</th>
                    <td>
                        <ul style="list-style:disc;margin-left:20px">
                            <li>Team Training inquiries</li>
                            <li>Reseller inquiries</li>
                        </ul>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Webhook', 'primary', 'itu_slack_save'); ?>
        </form>
    </div>
    <?php
}

/**
 * Force 10 posts per page on blog archives (10 posts + 2 promo cards = 12 = 3×4 grid).
 */
add_action('pre_get_posts', function ($query) {
    if (!is_admin() && $query->is_main_query() && ($query->is_archive() || $query->is_home())) {
        $query->set('posts_per_page', 10);

    }
});

/**
 * Force WooCommerce product archives to sort by publish date descending.
 */
add_filter('woocommerce_default_catalog_orderby', function () {
    return 'date';
});

add_filter('woocommerce_get_catalog_ordering_args', function ($args) {
    $args['orderby'] = 'date';
    $args['order'] = 'DESC';
    return $args;
});

/**
 * Promo cards — managed via admin UI, stored in wp_options.
 */
define('ITU_PROMO_OPTION', 'itu_promo_cards');

function itu_get_promo_cards() {
    $cards = get_option(ITU_PROMO_OPTION, []);
    // Return only active cards
    return array_filter($cards, function ($card) {
        return !empty($card['active']);
    });
}

function itu_get_all_promo_cards() {
    return get_option(ITU_PROMO_OPTION, []);
}

// Seed default cards if none exist
add_action('after_setup_theme', function () {
    if (get_option(ITU_PROMO_OPTION) === false) {
        update_option(ITU_PROMO_OPTION, [
            [
                'eyebrow'     => '[ Individual Plans ]',
                'title'       => 'Start Learning Today',
                'description' => 'Get unlimited access to our full IT training library — certification prep, practice exams, and AI-powered study tools.',
                'cta_text'    => 'View Plans →',
                'cta_url'     => '/it-certification-training/',
                'active'      => 1,
            ],
            [
                'eyebrow'     => '[ Team Training ]',
                'title'       => 'Train Your Entire Team',
                'description' => 'Equip your organization with the skills that drive performance — custom dashboards, compliance tracking, and group management.',
                'cta_text'    => 'Get a Team Plan →',
                'cta_url'     => '/it-team-training/#teams-contact',
                'active'      => 1,
            ],
        ]);
    }
});

// Admin page
add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'ITU Promo Cards',
        'ITU Promo Cards',
        'manage_options',
        'itu-promo-cards',
        'itu_promo_cards_admin_page'
    );
});

function itu_promo_cards_admin_page() {
    $cards = itu_get_all_promo_cards();
    $message = '';

    // Handle save
    if (isset($_POST['itu_promo_save']) && check_admin_referer('itu_promo_nonce')) {
        $new_cards = [];
        if (!empty($_POST['promo'])) {
            foreach ($_POST['promo'] as $card) {
                $new_cards[] = [
                    'eyebrow'     => sanitize_text_field($card['eyebrow']),
                    'title'       => sanitize_text_field($card['title']),
                    'description' => sanitize_textarea_field($card['description']),
                    'cta_text'    => sanitize_text_field($card['cta_text']),
                    'cta_url'     => esc_url_raw($card['cta_url']),
                    'active'      => !empty($card['active']) ? 1 : 0,
                ];
            }
        }
        update_option(ITU_PROMO_OPTION, $new_cards);
        $cards = $new_cards;
        $message = 'Promo cards saved.';
    }

    // Handle add
    if (isset($_POST['itu_promo_add']) && check_admin_referer('itu_promo_nonce')) {
        $cards[] = [
            'eyebrow'     => '[ New Promo ]',
            'title'       => 'Promo Title',
            'description' => 'Short description here.',
            'cta_text'    => 'Learn More →',
            'cta_url'     => '/',
            'active'      => 0,
        ];
        update_option(ITU_PROMO_OPTION, $cards);
        $message = 'New promo card added. Edit and activate it below.';
    }

    // Handle delete
    if (isset($_POST['itu_promo_delete']) && check_admin_referer('itu_promo_nonce')) {
        $del = intval($_POST['itu_promo_delete']);
        if (isset($cards[$del])) {
            array_splice($cards, $del, 1);
            update_option(ITU_PROMO_OPTION, $cards);
            $message = 'Promo card deleted.';
        }
    }

    ?>
    <div class="wrap">
        <h1>ITU Promo Cards</h1>
        <p>Manage promotional cards that appear in course carousels and blog archive grids. Active cards are randomly inserted at regular intervals.</p>

        <?php if ($message) : ?>
            <div class="notice notice-success is-dismissible"><p><strong><?php echo esc_html($message); ?></strong></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('itu_promo_nonce'); ?>

            <?php if (empty($cards)) : ?>
                <p>No promo cards yet.</p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:900px">
                    <thead>
                        <tr>
                            <th style="width:30px">Active</th>
                            <th>Eyebrow</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>CTA Text</th>
                            <th>CTA URL</th>
                            <th style="width:60px">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cards as $i => $card) : ?>
                        <tr>
                            <td><input type="checkbox" name="promo[<?php echo $i; ?>][active]" value="1" <?php checked(!empty($card['active'])); ?> /></td>
                            <td><input type="text" name="promo[<?php echo $i; ?>][eyebrow]" value="<?php echo esc_attr($card['eyebrow']); ?>" class="regular-text" style="width:100%" /></td>
                            <td><input type="text" name="promo[<?php echo $i; ?>][title]" value="<?php echo esc_attr($card['title']); ?>" class="regular-text" style="width:100%" /></td>
                            <td><textarea name="promo[<?php echo $i; ?>][description]" rows="2" style="width:100%"><?php echo esc_textarea($card['description']); ?></textarea></td>
                            <td><input type="text" name="promo[<?php echo $i; ?>][cta_text]" value="<?php echo esc_attr($card['cta_text']); ?>" style="width:100%" /></td>
                            <td><input type="text" name="promo[<?php echo $i; ?>][cta_url]" value="<?php echo esc_attr($card['cta_url']); ?>" style="width:100%" /></td>
                            <td><button type="submit" name="itu_promo_delete" value="<?php echo $i; ?>" class="button button-link-delete" onclick="return confirm('Delete this promo card?')">Delete</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p style="margin-top:16px">
                <?php submit_button('Save All Cards', 'primary', 'itu_promo_save', false); ?>
                &nbsp;
                <?php submit_button('+ Add New Card', 'secondary', 'itu_promo_add', false); ?>
            </p>
        </form>
    </div>
    <?php
}

function itu_render_promo_card($promo) {
    return '<div class="itu-certs__card itu-promo-card">'
        . '<span class="itu-promo-card__eyebrow">' . esc_html($promo['eyebrow']) . '</span>'
        . '<h4 class="itu-promo-card__title">' . esc_html($promo['title']) . '</h4>'
        . '<p class="itu-promo-card__desc">' . esc_html($promo['description']) . '</p>'
        . '<a href="' . esc_url($promo['cta_url']) . '" class="itu-promo-card__cta">' . esc_html($promo['cta_text']) . '</a>'
        . '</div>';
}

function itu_get_random_promo_html() {
    $promos = itu_get_promo_cards();
    $keys = array_keys($promos);
    $key = $keys[array_rand($keys)];
    return itu_render_promo_card($promos[$key]);
}

/**
 * Global plans bar — reusable CTA bar managed from admin.
 * Usage: [itu_plans_bar]
 */
define('ITU_PLANS_BAR_OPTION', 'itu_plans_bar_settings');

add_shortcode('itu_plans_bar', function () {
    $defaults = [
        'text'       => 'Ready to start learning?',
        'btn1_label' => 'Individual Plans →',
        'btn1_url'   => '/it-certification-training/',
        'btn2_label' => 'Team Plans →',
        'btn2_url'   => '/it-team-training/',
        'active'     => 1,
    ];
    $settings = wp_parse_args(get_option(ITU_PLANS_BAR_OPTION, []), $defaults);

    if (empty($settings['active'])) return '';

    $buttons = '';
    if (!empty($settings['btn1_label']) && !empty($settings['btn1_url'])) {
        $buttons .= '<a href="' . esc_url($settings['btn1_url']) . '" class="itu-plans-bar__btn">' . esc_html($settings['btn1_label']) . '</a>';
    }
    if (!empty($settings['btn2_label']) && !empty($settings['btn2_url'])) {
        $buttons .= '<a href="' . esc_url($settings['btn2_url']) . '" class="itu-plans-bar__btn">' . esc_html($settings['btn2_label']) . '</a>';
    }

    return '<div class="itu-plans-bar"><table class="itu-plans-bar__inner"><tr><td class="itu-plans-bar__text">' . esc_html($settings['text']) . '</td><td class="itu-plans-bar__actions">' . $buttons . '</td></tr></table></div>';
});

// Admin page for Plans Bar
add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'ITU Plans Bar',
        'ITU Plans Bar',
        'manage_options',
        'itu-plans-bar',
        'itu_plans_bar_admin_page'
    );
});

function itu_plans_bar_admin_page() {
    $defaults = [
        'text'       => 'Ready to start learning?',
        'btn1_label' => 'Individual Plans →',
        'btn1_url'   => '/it-certification-training/',
        'btn2_label' => 'Team Plans →',
        'btn2_url'   => '/it-team-training/',
        'active'     => 1,
    ];
    $settings = wp_parse_args(get_option(ITU_PLANS_BAR_OPTION, []), $defaults);
    $saved = false;

    if (isset($_POST['itu_plans_bar_save']) && check_admin_referer('itu_plans_bar_nonce')) {
        $settings = [
            'text'       => sanitize_text_field($_POST['bar_text']),
            'btn1_label' => sanitize_text_field($_POST['btn1_label']),
            'btn1_url'   => esc_url_raw($_POST['btn1_url']),
            'btn2_label' => sanitize_text_field($_POST['btn2_label']),
            'btn2_url'   => esc_url_raw($_POST['btn2_url']),
            'active'     => !empty($_POST['bar_active']) ? 1 : 0,
        ];
        update_option(ITU_PLANS_BAR_OPTION, $settings);
        $saved = true;
    }

    ?>
    <div class="wrap">
        <h1>ITU Plans Bar</h1>
        <p>Configure the global CTA bar that appears across course pages, blog posts, and archives. Changes apply everywhere the bar is used.</p>

        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible"><p><strong>Settings saved.</strong></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('itu_plans_bar_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>Active</th>
                    <td><label><input type="checkbox" name="bar_active" value="1" <?php checked($settings['active']); ?> /> Show the plans bar across the site</label></td>
                </tr>
                <tr>
                    <th>Message Text</th>
                    <td><input type="text" name="bar_text" value="<?php echo esc_attr($settings['text']); ?>" class="regular-text" style="width:100%;max-width:500px" /></td>
                </tr>
                <tr>
                    <th>Button 1 Label</th>
                    <td><input type="text" name="btn1_label" value="<?php echo esc_attr($settings['btn1_label']); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th>Button 1 URL</th>
                    <td><input type="text" name="btn1_url" value="<?php echo esc_attr($settings['btn1_url']); ?>" class="regular-text" style="width:100%;max-width:500px" /></td>
                </tr>
                <tr>
                    <th>Button 2 Label</th>
                    <td><input type="text" name="btn2_label" value="<?php echo esc_attr($settings['btn2_label']); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th>Button 2 URL</th>
                    <td><input type="text" name="btn2_url" value="<?php echo esc_attr($settings['btn2_url']); ?>" class="regular-text" style="width:100%;max-width:500px" /></td>
                </tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'itu_plans_bar_save'); ?>
        </form>
    </div>
    <?php
}

/**
 * Product hero — renders the full hero section as one unit (title + stats + image).
 */
add_shortcode('itu_product_hero', function () {
    global $post;
    if (!$post || $post->post_type !== 'product') return '';

    $product = wc_get_product($post->ID);
    if (!$product) return '';

    $title = get_the_title();
    $short_desc = $product->get_short_description();
    $sku = $product->get_sku();
    $image_id = $product->get_image_id();
    $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium_large') : '';

    // Build stats badges
    $badges = '';
    if ($sku) {
        $hours     = itu_get_course_stat($sku, 'video_hours', 'ec_get_course_video_hours', 'total_hours');
        $videos    = itu_get_course_stat($sku, 'video_count', 'ec_get_course_video_count', 'total_videos');
        $questions = itu_get_course_stat($sku, 'question_count', 'ec_get_course_prep_question_count', 'content_test_question_count');
        $enrolled  = itu_get_enrollment_count($sku);

        if ($hours) $badges .= '<span class="itu-certs__card-badge">' . esc_html($hours) . '</span>';
        if ($videos) $badges .= '<span class="itu-certs__card-badge">' . esc_html(number_format($videos)) . ' Videos</span>';
        if ($questions) $badges .= '<span class="itu-certs__card-badge">' . esc_html(number_format($questions)) . ' Questions</span>';
        if ($enrolled) $badges .= '<span class="itu-certs__card-badge itu-certs__card-badge--enrolled">' . esc_html(number_format($enrolled)) . ' Enrolled</span>';
    }
    $badges .= '<span class="itu-certs__card-badge">Certificate of Completion</span>';
    $badges .= '<span class="itu-certs__card-badge">Closed Captions</span>';

    $image_html = '';
    if ($image_url) {
        $image_html = '<div class="itu-product-hero__image"><img src="' . esc_url($image_url) . '" alt="' . esc_attr($title) . '" class="itu-product-avatar-img" /></div>';
    }

    return '<section class="itu-section">
        <div class="itu-section__inner">
            <div class="itu-product-hero">
                <div class="itu-product-hero__content">
                    <span class="itu-eyebrow">[ Course ]</span>
                    <h1 class="itu-page-title">' . esc_html($title) . '</h1>
                    ' . ($short_desc ? '<p class="itu-product-hero__desc">' . esc_html(wp_strip_all_tags($short_desc)) . '</p>' : '') . '
                    <button type="button" class="itu-hero-enroll-btn" id="itu-hero-enroll-trigger">Enroll Now &mdash; View Plans</button>
                    <script>document.getElementById("itu-hero-enroll-trigger").addEventListener("click",function(){var t=document.querySelector("[data-tab=\'enroll\']");if(t){t.click();setTimeout(function(){t.scrollIntoView({behavior:"smooth",block:"center"})},100);}});</script>
                    <div class="itu-product-stats">' . $badges . '</div>
                </div>
                ' . $image_html . '
            </div>
        </div>
    </section>';
});

/**
 * Product tabs — Description, Curriculum, Enroll Now.
 */
add_shortcode('itu_product_tabs', function () {
    global $post;
    if (!$post || $post->post_type !== 'product') return '';

    $product = wc_get_product($post->ID);
    if (!$product) return '';

    $sku = $product->get_sku();

    // Description tab content
    $description = apply_filters('the_content', $post->post_content);

    // Curriculum tab content (shortcode)
    $curriculum = '';
    if ($sku) {
        $curriculum = do_shortcode('[woocommerce_get_product_outlines sku="' . esc_attr($sku) . '"]');
    }
    if (empty(trim(strip_tags($curriculum)))) {
        $curriculum = '<p style="color:var(--itu-gray-400)">Course curriculum details are being updated. Check back soon.</p>';
    }

    // Check if product is in free-courses category
    $is_free = has_term('free-courses', 'product_cat', $post->ID);

    // Udemy affiliate URL (ACF field)
    $udemy_url = get_field('field_68168a7d5d0ac', $post->ID);

    // Udemy card (full-width, only if URL exists)
    $udemy_card = '';
    if (!empty($udemy_url)) {
        $udemy_card = '
        <div class="itu-enroll-card itu-enroll-card--full">
            <span class="itu-eyebrow" style="margin-bottom:8px; font-size:0.75rem">[ Single Course Purchase ]</span>
            <h3>Buy This Course on Udemy<em>.</em></h3>
            <p>Want just this course at the lowest price? Purchase it individually through our affiliate partner, Vision Training Systems, on Udemy. No subscription required.</p>
            <a href="' . esc_url($udemy_url) . '" target="_blank" rel="noopener" class="itu-button" style="display:inline-block; text-align:center; background:#111">Buy on Udemy</a>
        </div>';
    }

    // Enroll tab content
    $enroll = '
    <div class="itu-enroll-intro">
        <p class="itu-description">This course is included in all of our team and individual training plans. Choose the option that works best for you.</p>
    </div>
    <div class="itu-enroll-cards">
        <div class="itu-enroll-card">
            <span class="itu-eyebrow" style="margin-bottom:8px; font-size:0.75rem">[ Team Training ]</span>
            <h3>Enroll My Team<em>.</em></h3>
            <p>Give your entire team access to this course and our full training library. Includes team dashboards, progress tracking, and group management.</p>
            <a href="/it-team-training/#teams-contact" class="itu-button" style="display:block; text-align:center; background:#C026D3">Get Team Pricing</a>
        </div>
        <div class="itu-enroll-card">
            <span class="itu-eyebrow" style="margin-bottom:8px; font-size:0.75rem">[ Individual Plans ]</span>
            <h3>Choose a Plan<em>.</em></h3>
            <p>Get unlimited access to this course and our entire library with a monthly, quarterly, annual, or lifetime plan.</p>
            <a href="/it-certification-training/" class="itu-button" style="display:block; text-align:center; background:#C026D3">View Individual Plans</a>
        </div>
        ' . $udemy_card . '
    </div>';

    // For free courses, replace the Enroll tab button with a direct add-to-cart link
    $add_to_cart_url = esc_url(add_query_arg('add-to-cart', $product->get_id(), wc_get_cart_url()));
    $enroll_tab_btn = $is_free
        ? '<a href="' . $add_to_cart_url . '" class="itu-product-tabs__btn itu-product-tabs__btn--cta"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Enroll Now</a>'
        : '<button class="itu-product-tabs__btn" data-tab="enroll"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Enroll Now</button>';

    $enroll_panel = $is_free ? '' : '<div class="itu-product-tab-panel" id="ptab-enroll">' . $enroll . '</div>';

    return '
    <div class="itu-product-tabs-bar">
        <div class="itu-section__inner">
            <div class="itu-product-tabs">
                <button class="itu-product-tabs__btn is-active" data-tab="description"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>Description</button>
                <button class="itu-product-tabs__btn" data-tab="curriculum"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Curriculum</button>
                ' . $enroll_tab_btn . '
            </div>
        </div>
    </div>
    <section class="itu-section itu-section--gray">
        <div class="itu-section__inner">
            <div class="itu-product-tab-panel is-active" id="ptab-description">' . $description . '</div>
            <div class="itu-product-tab-panel" id="ptab-curriculum">' . $curriculum . '</div>
            ' . $enroll_panel . '
        </div>
    </section>
    <script>
    (function() {
        var btns = document.querySelectorAll(".itu-product-tabs__btn");
        btns.forEach(function(btn) {
            btn.addEventListener("click", function() {
                btns.forEach(function(b) { b.classList.remove("is-active"); });
                this.classList.add("is-active");
                document.querySelectorAll(".itu-product-tab-panel").forEach(function(p) { p.classList.remove("is-active"); });
                document.getElementById("ptab-" + this.dataset.tab).classList.add("is-active");
            });
        });
    })();
    </script>';
});

/**
 * Product stats badges — displays hours, videos, questions, enrolled, certificate, captions.
 * Usage: [itu_product_stats] — uses current product context.
 */
add_shortcode('itu_product_stats', function () {
    global $post;
    if (!$post || $post->post_type !== 'product') return '';

    $product = wc_get_product($post->ID);
    if (!$product) return '';

    $sku = $product->get_sku();
    if (!$sku) return '';

    $hours     = itu_get_course_stat($sku, 'video_hours', 'ec_get_course_video_hours', 'total_hours');
    $videos    = itu_get_course_stat($sku, 'video_count', 'ec_get_course_video_count', 'total_videos');
    $questions = itu_get_course_stat($sku, 'question_count', 'ec_get_course_prep_question_count', 'content_test_question_count');
    $enrolled  = itu_get_enrollment_count($sku);

    $badges = '';

    if ($hours) {
        $badges .= '<span class="itu-certs__card-badge">' . esc_html($hours) . '</span>';
    }
    if ($videos) {
        $badges .= '<span class="itu-certs__card-badge">' . esc_html(number_format($videos)) . ' Videos</span>';
    }
    if ($questions) {
        $badges .= '<span class="itu-certs__card-badge">' . esc_html(number_format($questions)) . ' Questions</span>';
    }
    if ($enrolled) {
        $badges .= '<span class="itu-certs__card-badge itu-certs__card-badge--enrolled">' . esc_html(number_format($enrolled)) . ' Enrolled</span>';
    }

    // Static badges
    $badges .= '<span class="itu-certs__card-badge">Certificate of Completion</span>';
    $badges .= '<span class="itu-certs__card-badge">Closed Captions</span>';

    if (empty($badges)) return '';

    return '<div class="itu-product-stats">' . $badges . '</div>';
});

/**
 * Course Category Carousel Shortcode.
 *
 * Usage: [itu_course_carousel category="comptia" title="CompTIA" description="..." limit="12"]
 */
add_shortcode('itu_course_carousel', function ($atts) {
    $atts = shortcode_atts([
        'category'    => '',
        'title'       => '',
        'description' => '',
        'limit'       => 10,
    ], $atts);

    if (empty($atts['category'])) return '';

    // Check transient cache
    $cache_key = 'itu_carousel_' . md5($atts['category'] . $atts['limit']);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    // Auto-populate description from category term if not provided
    if (empty($atts['description'])) {
        $term = get_term_by('slug', $atts['category'], 'product_cat');
        if ($term && !is_wp_error($term) && !empty($term->description)) {
            $atts['description'] = $term->description;
        }
    }

    // Query one extra to know if there are more than the limit
    $query_limit = intval($atts['limit']);
    $products = wc_get_products([
        'status'     => 'publish',
        'limit'      => $query_limit + 1,
        'category'   => [$atts['category']],
        'orderby'    => 'date',
        'order'      => 'DESC',
        'visibility' => 'visible',
    ]);

    if (empty($products)) return '';

    // Filter out products with no name or no image
    $products = array_values(array_filter($products, function ($p) {
        return !empty(trim($p->get_name())) && $p->get_image_id();
    }));

    if (empty($products)) return '';

    $has_more = count($products) > $query_limit;
    if ($has_more) {
        $products = array_slice($products, 0, $query_limit);
    }

    ob_start();
    ?>
    <section class="itu-cat-row">
        <div class="itu-cat-row__header">
            <div>
                <span class="itu-eyebrow">[ <?php echo esc_html($atts['title']); ?> ]</span>
                <?php if (!empty($atts['description'])) : ?>
                    <p class="itu-cat-row__desc"><?php echo esc_html($atts['description']); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="itu-cat-row__carousel-wrap">
            <button class="itu-cat-row__arrow itu-cat-row__arrow--prev" aria-label="Scroll left">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="itu-cat-row__arrow itu-cat-row__arrow--next" aria-label="Scroll right">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <div class="itu-cat-row__carousel">
                <div class="itu-cat-row__track">
                <?php $card_index = 0; foreach ($products as $product) :
                    if (empty(trim($product->get_name())) || !$product->get_image_id()) continue;
                    $card_index++;
                    if ($card_index === 7) { echo itu_get_random_promo_html(); }
                    $image = wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail');
                    $price = $product->get_price_html();
                ?>
                <div class="itu-certs__card">
                    <span class="itu-certs__card-label"><?php echo esc_html($atts['title']); ?></span>
                    <a href="<?php echo esc_url($product->get_permalink()); ?>" class="itu-certs__card-image">
                        <?php if ($image) : ?>
                            <img loading="lazy" src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($product->get_name()); ?>" />
                        <?php else : ?>
                            <div class="itu-certs__card-placeholder"></div>
                        <?php endif; ?>
                    </a>
                    <a href="<?php echo esc_url($product->get_permalink()); ?>" class="itu-certs__card-title"><?php echo esc_html($product->get_name()); ?></a>

                    <?php
                    $sku = $product->get_sku();
                    if ($sku) :
                        $hours = itu_get_course_stat($sku, 'video_hours', 'ec_get_course_video_hours', 'total_hours');
                        $videos = itu_get_course_stat($sku, 'video_count', 'ec_get_course_video_count', 'total_videos');
                        $questions = itu_get_course_stat($sku, 'question_count', 'ec_get_course_prep_question_count', 'content_test_question_count');
                    ?>
                    <div class="itu-certs__card-badges">
                        <?php if ($hours) : ?>
                            <span class="itu-certs__card-badge"><?php echo esc_html($hours); ?></span>
                        <?php endif; ?>
                        <?php if ($videos) : ?>
                            <span class="itu-certs__card-badge"><?php echo esc_html(number_format($videos)); ?> Videos</span>
                        <?php endif; ?>
                        <?php if ($questions) : ?>
                            <span class="itu-certs__card-badge"><?php echo esc_html(number_format($questions)); ?> Questions</span>
                        <?php endif; ?>
                        <?php
                        $enrolled = itu_get_enrollment_count($sku);
                        if ($enrolled) : ?>
                            <span class="itu-certs__card-badge itu-certs__card-badge--enrolled"><?php echo esc_html(number_format($enrolled)); ?> Enrolled</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div>
                <?php endforeach; ?>
                <?php if ($has_more) : ?><?php echo '<a href="/product-category/' . esc_attr($atts['category']) . '/" class="itu-certs__card itu-certs__card--viewall"><div class="itu-certs__card-viewall-inner"><span class="itu-certs__card-viewall-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span><span class="itu-certs__card-viewall-text">View All ' . esc_html($atts['title']) . ' Courses</span></div></a>'; ?><?php endif; ?>
                </div>
            </div>
        </div>
        <a href="/product-category/<?php echo esc_attr($atts['category']); ?>/" class="itu-cat-row__viewall">View All <?php echo esc_html($atts['title']); ?> Courses &raquo;</a>
    </section>
    <?php
    $output = ob_get_clean();
    // Remove all whitespace between HTML tags to prevent br/p injection
    $output = preg_replace('/>\s+</', '><', $output);
    // Strip any remaining wpautop artifacts
    $output = str_replace(['<p></p>', '<p> </p>', '<br />', '<br/>', '<br>'], '', $output);
    $output = preg_replace('/<p>\s*<\/p>/', '', $output);

    // Cache for 30 days
    set_transient($cache_key, $output, 30 * DAY_IN_SECONDS);

    return $output;
});





/**
 * Catalog term meta — adds "Default in catalog" and "Hide from catalog"
 * checkboxes to the WooCommerce product category edit screen.
 */
// Add fields to the "Add New Category" form
add_action('product_cat_add_form_fields', function () {
    ?>
    <div class="form-field">
        <label><input type="checkbox" name="itu_catalog_default" value="1" /> Default in catalog</label>
        <p class="description">Pre-check this category on the courses page.</p>
    </div>
    <div class="form-field">
        <label><input type="checkbox" name="itu_catalog_hidden" value="1" /> Hide from catalog</label>
        <p class="description">Completely hide this category from the courses page filter.</p>
    </div>
    <?php
});

// Add fields to the "Edit Category" form
add_action('product_cat_edit_form_fields', function ($term) {
    $default = get_term_meta($term->term_id, 'itu_catalog_default', true);
    $hidden  = get_term_meta($term->term_id, 'itu_catalog_hidden', true);
    ?>
    <tr class="form-field">
        <th scope="row"><label>Catalog options</label></th>
        <td>
            <label><input type="checkbox" name="itu_catalog_default" value="1" <?php checked($default, '1'); ?> /> Default in catalog</label>
            <p class="description">Pre-check this category on the courses page.</p>
            <br>
            <label><input type="checkbox" name="itu_catalog_hidden" value="1" <?php checked($hidden, '1'); ?> /> Hide from catalog</label>
            <p class="description">Completely hide this category from the courses page filter.</p>
        </td>
    </tr>
    <?php
});

// Save the fields
add_action('created_product_cat', 'itu_save_catalog_term_meta');
add_action('edited_product_cat', 'itu_save_catalog_term_meta');
function itu_save_catalog_term_meta($term_id) {
    update_term_meta($term_id, 'itu_catalog_default', isset($_POST['itu_catalog_default']) ? '1' : '0');
    update_term_meta($term_id, 'itu_catalog_hidden', isset($_POST['itu_catalog_hidden']) ? '1' : '0');
}

// Add columns to the product_cat list table
add_filter('manage_edit-product_cat_columns', function ($columns) {
    $columns['itu_catalog_default'] = 'Catalog Default';
    $columns['itu_catalog_hidden']  = 'Catalog Hidden';
    return $columns;
});

// Render column values
add_filter('manage_product_cat_custom_column', function ($content, $column, $term_id) {
    if ($column === 'itu_catalog_default') {
        $val = get_term_meta($term_id, 'itu_catalog_default', true);
        return $val === '1'
            ? '<span class="itu-cat-flag" data-field="itu_catalog_default" data-value="1">&#10003;</span>'
            : '<span class="itu-cat-flag" data-field="itu_catalog_default" data-value="0">&mdash;</span>';
    }
    if ($column === 'itu_catalog_hidden') {
        $val = get_term_meta($term_id, 'itu_catalog_hidden', true);
        return $val === '1'
            ? '<span class="itu-cat-flag" data-field="itu_catalog_hidden" data-value="1">&#10003;</span>'
            : '<span class="itu-cat-flag" data-field="itu_catalog_hidden" data-value="0">&mdash;</span>';
    }
    return $content;
}, 10, 3);

// Add fields to quick edit form
add_action('quick_edit_custom_box', function ($column, $screen, $taxonomy) {
    if ($taxonomy !== 'product_cat') return;
    if ($column === 'itu_catalog_default') {
        ?>
        <fieldset>
            <div class="inline-edit-col">
                <label>
                    <input type="checkbox" name="itu_catalog_default" value="1" />
                    <span class="checkbox-title">Default in catalog</span>
                </label>
            </div>
        </fieldset>
        <?php
    }
    if ($column === 'itu_catalog_hidden') {
        ?>
        <fieldset>
            <div class="inline-edit-col">
                <label>
                    <input type="checkbox" name="itu_catalog_hidden" value="1" />
                    <span class="checkbox-title">Hide from catalog</span>
                </label>
            </div>
        </fieldset>
        <?php
    }
}, 10, 3);

// Inline JS to populate quick edit checkboxes with current values
add_action('admin_footer-edit-tags.php', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->taxonomy !== 'product_cat') return;
    ?>
    <script>
    (function($) {
        var origInlineEdit = window.inlineEditTax ? inlineEditTax.edit : null;
        if (!origInlineEdit) return;

        inlineEditTax.edit = function(id) {
            origInlineEdit.apply(this, arguments);

            if (typeof id === 'object') id = this.getId(id);

            var row = $('#tag-' + id);
            var editRow = $('#edit-' + id);

            var catDefault = row.find('[data-field="itu_catalog_default"]').data('value');
            var catHidden  = row.find('[data-field="itu_catalog_hidden"]').data('value');

            editRow.find('input[name="itu_catalog_default"]').prop('checked', catDefault == 1);
            editRow.find('input[name="itu_catalog_hidden"]').prop('checked', catHidden == 1);
        };
    })(jQuery);
    </script>
    <?php
});

/**
 * Course Catalog — sidebar filter + lazy-loaded carousels.
 * Uses term meta flags for default/hidden state.
 */
add_shortcode('itu_course_catalog', function () {
    // Check catalog cache
    $catalog_cache_key = 'itu_catalog_full';
    $catalog_cached = get_transient($catalog_cache_key);
    if ($catalog_cached !== false) return $catalog_cached;

    $categories = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'parent'     => 0,
        'exclude'    => [get_option('default_product_cat')], // exclude "Uncategorized"
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    if (empty($categories) || is_wp_error($categories)) return '';

    // Exclude categories with zero products or marked hidden
    $categories = array_values(array_filter($categories, function ($cat) {
        if ($cat->count <= 0) return false;
        if (get_term_meta($cat->term_id, 'itu_catalog_hidden', true) === '1') return false;
        return true;
    }));

    ob_start();
    ?>
    <div class="itu-catalog">
        <button class="itu-catalog__filter-toggle" aria-label="Filter categories">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="16" y2="12"/><line x1="4" y1="18" x2="12" y2="18"/></svg>
            Filter Categories
        </button>
        <div class="itu-catalog__overlay"></div>
        <aside class="itu-catalog__sidebar">
            <button class="itu-catalog__sidebar-close" aria-label="Close filters">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
            <span class="itu-eyebrow">[ Categories ]</span>
            <ul class="itu-catalog__filter-list">
                <?php foreach ($categories as $cat) :
                    $checked = get_term_meta($cat->term_id, 'itu_catalog_default', true) === '1';
                ?>
                <li class="itu-catalog__filter-item">
                    <label class="itu-catalog__filter-label">
                        <input
                            type="checkbox"
                            class="itu-catalog__filter-checkbox"
                            value="<?php echo esc_attr($cat->slug); ?>"
                            data-title="<?php echo esc_attr($cat->name); ?>"
                            data-count="<?php echo esc_attr($cat->count); ?>"
                            <?php echo $checked ? 'checked' : ''; ?>
                        />
                        <span class="itu-catalog__filter-name"><?php echo esc_html($cat->name); ?></span>
                    </label>
                    <a href="<?php echo esc_url(get_term_link($cat)); ?>" class="itu-catalog__filter-open" aria-label="View <?php echo esc_attr($cat->name); ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </aside>
        <div class="itu-catalog__content"><!--ITU_CAROUSELS--></div>
    </div>
    <?php
    $output = ob_get_clean();

    // Build carousels outside the ob buffer to avoid whitespace injection
    $default_carousels = '';
    foreach ($categories as $cat) {
        if (get_term_meta($cat->term_id, 'itu_catalog_default', true) === '1') {
            $default_carousels .= do_shortcode('[itu_course_carousel category="' . esc_attr($cat->slug) . '" title="' . esc_attr($cat->name) . '"]');
        }
    }

    // Inject carousels at placeholder
    $output = str_replace('<!--ITU_CAROUSELS-->', $default_carousels, $output);

    // Final cleanup
    $output = preg_replace('/>\s+</', '><', $output);
    $output = str_replace(['<p></p>', '<p> </p>', '<br />', '<br/>', '<br>'], '', $output);
    $output = preg_replace('/<p>\s*<\/p>/', '', $output);

    // Cache the full catalog for 30 days
    set_transient($catalog_cache_key, $output, 30 * DAY_IN_SECONDS);

    return $output;
});

/**
 * AJAX handler — returns a single category carousel HTML.
 */
function itu_ajax_load_carousel() {
    $category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
    $title    = isset($_GET['title']) ? sanitize_text_field($_GET['title']) : '';

    if (empty($category)) {
        wp_send_json_error('Missing category');
    }

    $html = do_shortcode('[itu_course_carousel category="' . esc_attr($category) . '" title="' . esc_attr($title) . '"]');
    wp_send_json_success($html);
}
add_action('wp_ajax_itu_load_carousel', 'itu_ajax_load_carousel');
add_action('wp_ajax_nopriv_itu_load_carousel', 'itu_ajax_load_carousel');

// Faster REST API endpoint for carousel loading
add_action('rest_api_init', function () {
    register_rest_route('itu/v1', '/carousel', [
        'methods'             => 'GET',
        'callback'            => function ($request) {
            $category = sanitize_text_field($request->get_param('category'));
            $title    = sanitize_text_field($request->get_param('title'));
            if (empty($category)) {
                return new WP_Error('missing_category', 'Missing category', ['status' => 400]);
            }
            $html = do_shortcode('[itu_course_carousel category="' . esc_attr($category) . '" title="' . esc_attr($title) . '"]');
            return ['success' => true, 'data' => $html];
        },
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Breadcrumbs shortcode — renders Home >> Courses >> Category Name style breadcrumbs.
 */
add_shortcode('itu_breadcrumbs', 'itu_render_breadcrumbs');

function itu_render_breadcrumbs() {
    if (is_front_page() || is_home()) return '';

    $crumbs = [];
    $crumbs[] = '<a href="/" class="itu-breadcrumbs__link">Home</a>';

    if (is_product_category()) {
        $crumbs[] = '<a href="/it-courses/" class="itu-breadcrumbs__link">Courses</a>';
        $term = get_queried_object();
        if ($term->parent) {
            $parent = get_term($term->parent, 'product_cat');
            if ($parent && !is_wp_error($parent)) {
                $crumbs[] = '<a href="' . esc_url(get_term_link($parent)) . '" class="itu-breadcrumbs__link">' . esc_html($parent->name) . '</a>';
            }
        }
        $crumbs[] = '<span class="itu-breadcrumbs__current">' . esc_html($term->name) . '</span>';
    } elseif (is_product()) {
        $crumbs[] = '<a href="/it-courses/" class="itu-breadcrumbs__link">Courses</a>';
        $cats = wp_get_post_terms(get_the_ID(), 'product_cat', ['orderby' => 'parent']);
        if (!empty($cats) && !is_wp_error($cats)) {
            $cat = $cats[0];
            $crumbs[] = '<a href="' . esc_url(get_term_link($cat)) . '" class="itu-breadcrumbs__link">' . esc_html($cat->name) . '</a>';
        }
        $crumbs[] = '<span class="itu-breadcrumbs__current">' . esc_html(get_the_title()) . '</span>';
    } elseif (is_page()) {
        // Check for parent page
        $post = get_queried_object();
        if ($post->post_parent) {
            $parent = get_post($post->post_parent);
            $crumbs[] = '<a href="' . esc_url(get_permalink($parent)) . '" class="itu-breadcrumbs__link">' . esc_html($parent->post_title) . '</a>';
        }
        $crumbs[] = '<span class="itu-breadcrumbs__current">' . esc_html(get_the_title()) . '</span>';
    } elseif (is_singular('post')) {
        $crumbs[] = '<a href="/resources/" class="itu-breadcrumbs__link">Resources</a>';
        $cats = get_the_category();
        $is_practice_test = false;
        if (!empty($cats)) {
            foreach ($cats as $c) {
                if ($c->slug === 'practice-tests') { $is_practice_test = true; break; }
            }
        }
        if ($is_practice_test) {
            // Practice tests — show Practice Tests category, no Blogs
            $pt_cat = get_category_by_slug('practice-tests');
            if ($pt_cat) {
                $crumbs[] = '<a href="' . esc_url(get_category_link($pt_cat->term_id)) . '" class="itu-breadcrumbs__link">Practice Tests</a>';
            }
        } else {
            // All other posts — always show Blogs, then category if different
            $blogs_cat = get_category_by_slug('blogs');
            if ($blogs_cat) {
                $crumbs[] = '<a href="' . esc_url(get_category_link($blogs_cat->term_id)) . '" class="itu-breadcrumbs__link">Blogs</a>';
            }
            if (!empty($cats)) {
                $cat = $cats[0];
                if (!$blogs_cat || $cat->term_id !== $blogs_cat->term_id) {
                    $crumbs[] = '<a href="' . esc_url(get_category_link($cat->term_id)) . '" class="itu-breadcrumbs__link">' . esc_html($cat->name) . '</a>';
                }
            }
        }
        $crumbs[] = '<span class="itu-breadcrumbs__current">' . esc_html(get_the_title()) . '</span>';
    } elseif (is_archive()) {
        $archive_title = get_the_archive_title();
        $archive_title = wp_strip_all_tags($archive_title);
        // Always show Resources > Blogs on post archives
        $crumbs[] = '<a href="/resources/" class="itu-breadcrumbs__link">Resources</a>';
        if (is_category()) {
            $current_cat = get_queried_object();
            // If not the Blogs category itself, show Blogs link first
            if ($current_cat && $current_cat->slug !== 'blogs') {
                $blogs_cat = get_category_by_slug('blogs');
                if ($blogs_cat) {
                    $crumbs[] = '<a href="' . esc_url(get_category_link($blogs_cat->term_id)) . '" class="itu-breadcrumbs__link">Blogs</a>';
                }
            }
        }
        $crumbs[] = '<span class="itu-breadcrumbs__current">' . esc_html($archive_title) . '</span>';
    } elseif (is_search()) {
        $crumbs[] = '<span class="itu-breadcrumbs__current">Search results</span>';
    } else {
        $crumbs[] = '<span class="itu-breadcrumbs__current">' . esc_html(get_the_title()) . '</span>';
    }

    $separator = '<span class="itu-breadcrumbs__sep">&raquo;</span>';
    return '<nav class="itu-breadcrumbs" aria-label="Breadcrumb">' . implode($separator, $crumbs) . '</nav>';
}

/**
 * Category Grid — displays all products in the current product category
 * as a 4-column card grid reusing the same card markup as the carousel.
 */
add_shortcode('itu_category_grid', function () {
    $term = get_queried_object();
    if (!$term || !isset($term->taxonomy) || $term->taxonomy !== 'product_cat') return '';

    // Check transient cache
    $cache_key = 'itu_catgrid_' . $term->slug;
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $products = wc_get_products([
        'status'     => 'publish',
        'limit'      => -1,
        'category'   => [$term->slug],
        'orderby'    => 'date',
        'order'      => 'DESC',
        'visibility' => 'visible',
    ]);

    // Filter out products with no name or no image
    $products = array_values(array_filter($products, function ($p) {
        return !empty(trim($p->get_name())) && $p->get_image_id();
    }));

    ob_start();
    ?>
    <section class="itu-catgrid">
        <div class="itu-catgrid__header">
            <h1 class="itu-catgrid__title"><?php echo esc_html($term->name); ?></h1>
            <?php if (!empty($term->description)) : ?>
                <p class="itu-catgrid__desc"><?php echo esc_html($term->description); ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($products)) : ?>
        <div class="itu-catgrid__grid">
            <?php foreach ($products as $product) :
                $image = wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail');
                $cats = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
                $cat_label = !empty($cats) ? $cats[0] : '';
            ?>
            <div class="itu-certs__card">
                <span class="itu-certs__card-label"><?php echo esc_html($cat_label); ?></span>
                <a href="<?php echo esc_url($product->get_permalink()); ?>" class="itu-certs__card-image">
                    <?php if ($image) : ?>
                        <img loading="lazy" src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($product->get_name()); ?>" />
                    <?php else : ?>
                        <div class="itu-certs__card-placeholder"></div>
                    <?php endif; ?>
                </a>
                <a href="<?php echo esc_url($product->get_permalink()); ?>" class="itu-certs__card-title"><?php echo esc_html($product->get_name()); ?></a>

                <?php
                $sku = $product->get_sku();
                if ($sku) :
                    $hours = itu_get_course_stat($sku, 'video_hours', 'ec_get_course_video_hours', 'total_hours');
                    $videos = itu_get_course_stat($sku, 'video_count', 'ec_get_course_video_count', 'total_videos');
                    $questions = itu_get_course_stat($sku, 'question_count', 'ec_get_course_prep_question_count', 'content_test_question_count');
                ?>
                <div class="itu-certs__card-badges">
                    <?php if ($hours) : ?>
                        <span class="itu-certs__card-badge"><?php echo esc_html($hours); ?></span>
                    <?php endif; ?>
                    <?php if ($videos) : ?>
                        <span class="itu-certs__card-badge"><?php echo esc_html(number_format($videos)); ?> Videos</span>
                    <?php endif; ?>
                    <?php if ($questions) : ?>
                        <span class="itu-certs__card-badge"><?php echo esc_html(number_format($questions)); ?> Questions</span>
                    <?php endif; ?>
                    <?php
                    $enrolled = itu_get_enrollment_count($sku);
                    if ($enrolled) : ?>
                        <span class="itu-certs__card-badge itu-certs__card-badge--enrolled"><?php echo esc_html(number_format($enrolled)); ?> Enrolled</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
            <p class="itu-catgrid__empty">No courses found in this category.</p>
        <?php endif; ?>
    </section>
    <?php
    $output = ob_get_clean();
    $output = str_replace(['<p></p>', '<p> </p>'], '', $output);

    // Cache for 30 days
    set_transient($cache_key, $output, 30 * DAY_IN_SECONDS);

    return $output;
});

/**
 * Certification Spotlight — admin-managed featured courses section.
 * Data stored in wp_option 'itu_spotlight_items'.
 * Each item: { product_id, title, logo_id, description }
 */

// Admin page
add_action('admin_menu', function () {
    add_menu_page(
        'Certification Spotlight',
        'Spotlight',
        'manage_options',
        'itu-spotlight',
        'itu_spotlight_admin_page',
        'dashicons-star-filled',
        58
    );
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_itu-spotlight') return;
    wp_enqueue_media();
    wp_enqueue_script('jquery');
    wp_enqueue_style('wp-jquery-ui-dialog');
    wp_enqueue_script('jquery-ui-sortable');
});

/**
 * Sync WP product description into the AICG prompt textarea
 * when the description is updated on product edit screens.
 */
add_action('admin_footer-post.php', 'itu_sync_description_to_aicg');
add_action('admin_footer-post-new.php', 'itu_sync_description_to_aicg');
function itu_sync_description_to_aicg() {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'product') return;
    ?>
    <script>
    (function() {
        var aicgField = document.getElementById('aicg_prompt');
        if (!aicgField) return;

        function getDescriptionText() {
            // Try TinyMCE (visual editor) first
            if (typeof tinymce !== 'undefined') {
                var editor = tinymce.get('content');
                if (editor && !editor.isHidden()) {
                    return editor.getContent({ format: 'text' });
                }
            }
            // Fall back to the plain text editor textarea
            var textarea = document.getElementById('content');
            return textarea ? textarea.value.replace(/<[^>]*>/g, '') : '';
        }

        function syncToAicg() {
            var text = getDescriptionText().trim();
            if (text) {
                aicgField.value = text;
                aicgField.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        // Watch TinyMCE visual editor for changes
        if (typeof tinymce !== 'undefined') {
            tinymce.on('AddEditor', function(e) {
                if (e.editor.id !== 'content') return;
                e.editor.on('Change NodeChange KeyUp', function() {
                    syncToAicg();
                });
            });
            // If editor already exists
            var existing = tinymce.get('content');
            if (existing) {
                existing.on('Change NodeChange KeyUp', function() {
                    syncToAicg();
                });
            }
        }

        // Watch plain text textarea for changes
        var contentTextarea = document.getElementById('content');
        if (contentTextarea) {
            contentTextarea.addEventListener('input', syncToAicg);
        }

        // Also sync on initial load after a short delay (let AICG plugin initialize)
        setTimeout(function() {
            aicgField = document.getElementById('aicg_prompt');
            if (aicgField && !aicgField.value.trim()) {
                syncToAicg();
            }
        }, 2000);
    })();
    </script>
    <?php
}

// AJAX product search for the admin page
add_action('wp_ajax_itu_search_products', function () {
    $search = sanitize_text_field($_GET['q'] ?? '');
    $products = wc_get_products([
        'status' => 'publish',
        'limit'  => 20,
        's'      => $search,
    ]);
    $results = [];
    foreach ($products as $p) {
        $results[] = [
            'id'    => $p->get_id(),
            'name'  => $p->get_name(),
            'image' => wp_get_attachment_image_url($p->get_image_id(), 'thumbnail'),
            'sku'   => $p->get_sku(),
        ];
    }
    wp_send_json($results);
});

function itu_spotlight_admin_page() {
    $panels = get_option('itu_spotlight_panels', []);
    $active_id = get_option('itu_spotlight_active', '');

    // Migrate legacy single-panel data if present
    $legacy = get_option('itu_spotlight_items', null);
    if ($legacy !== null && !empty($legacy) && empty($panels)) {
        $panel_id = uniqid();
        $panels[$panel_id] = ['name' => 'Default Spotlight', 'items' => $legacy];
        $active_id = $panel_id;
        update_option('itu_spotlight_panels', $panels);
        update_option('itu_spotlight_active', $active_id);
        delete_option('itu_spotlight_items');
    }

    // Handle actions
    if (isset($_POST['itu_spotlight_save_panel']) && check_admin_referer('itu_spotlight_nonce')) {
        $panel_id = sanitize_text_field($_POST['panel_id'] ?? '');
        $panel_name = sanitize_text_field($_POST['panel_name'] ?? 'Untitled');
        $items = [];
        $ids = $_POST['spotlight_product_id'] ?? [];
        $titles = $_POST['spotlight_title'] ?? [];
        $logos = $_POST['spotlight_logo_id'] ?? [];
        $descs = $_POST['spotlight_description'] ?? [];

        foreach ($ids as $i => $product_id) {
            if (empty($product_id)) continue;
            $items[] = [
                'product_id'  => intval($product_id),
                'title'       => sanitize_text_field($titles[$i] ?? ''),
                'logo_id'     => intval($logos[$i] ?? 0),
                'description' => sanitize_textarea_field($descs[$i] ?? ''),
            ];
        }

        if (empty($panel_id)) $panel_id = uniqid();
        $panels[$panel_id] = ['name' => $panel_name, 'items' => $items];
        update_option('itu_spotlight_panels', $panels);
        delete_transient('itu_spotlight_html');
        echo '<div class="notice notice-success is-dismissible"><p>Panel "' . esc_html($panel_name) . '" saved.</p></div>';
    }

    if (isset($_POST['itu_spotlight_set_active']) && check_admin_referer('itu_spotlight_nonce')) {
        $active_id = sanitize_text_field($_POST['active_panel_id']);
        update_option('itu_spotlight_active', $active_id);
        delete_transient('itu_spotlight_html');
        $panels = get_option('itu_spotlight_panels', []);
        $name = isset($panels[$active_id]) ? $panels[$active_id]['name'] : '';
        echo '<div class="notice notice-success is-dismissible"><p>"' . esc_html($name) . '" is now the active spotlight.</p></div>';
    }

    if (isset($_POST['itu_spotlight_deactivate']) && check_admin_referer('itu_spotlight_nonce')) {
        update_option('itu_spotlight_active', '');
        delete_transient('itu_spotlight_html');
        echo '<div class="notice notice-info is-dismissible"><p>Spotlight section deactivated. No panel is displayed.</p></div>';
        $active_id = '';
    }

    if (isset($_POST['itu_spotlight_delete_panel']) && check_admin_referer('itu_spotlight_nonce')) {
        $del_id = sanitize_text_field($_POST['delete_panel_id']);
        $del_name = isset($panels[$del_id]) ? $panels[$del_id]['name'] : '';
        unset($panels[$del_id]);
        update_option('itu_spotlight_panels', $panels);
        if ($active_id === $del_id) {
            update_option('itu_spotlight_active', '');
            $active_id = '';
        }
        delete_transient('itu_spotlight_html');
        echo '<div class="notice notice-warning is-dismissible"><p>Panel "' . esc_html($del_name) . '" deleted.</p></div>';
    }

    // Refresh
    $panels = get_option('itu_spotlight_panels', []);
    $active_id = get_option('itu_spotlight_active', '');
    $editing = isset($_GET['edit']) ? sanitize_text_field($_GET['edit']) : '';

    // LIST VIEW
    if (empty($editing)) :
    ?>
    <div class="wrap">
        <h1>Certification Spotlight</h1>
        <p>Create and manage spotlight panels. The active panel is displayed on the home page.</p>

        <p><a href="<?php echo esc_url(admin_url('admin.php?page=itu-spotlight&edit=new')); ?>" class="button button-primary">+ Create New Panel</a></p>

        <?php if (empty($panels)) : ?>
            <p style="color:#999;">No panels created yet.</p>
        <?php else : ?>
        <table class="widefat striped" style="max-width:800px;">
            <thead>
                <tr>
                    <th>Panel Name</th>
                    <th>Courses</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($panels as $pid => $panel) :
                    $is_active = ($pid === $active_id);
                    $count = count($panel['items'] ?? []);
                ?>
                <tr>
                    <td><strong><?php echo esc_html($panel['name']); ?></strong></td>
                    <td><?php echo intval($count); ?> course<?php echo $count !== 1 ? 's' : ''; ?></td>
                    <td>
                        <?php if ($is_active) : ?>
                            <span style="color:#00a32a; font-weight:600;">&#9679; Active</span>
                        <?php else : ?>
                            <span style="color:#999;">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=itu-spotlight&edit=' . $pid)); ?>" class="button button-small">Edit</a>

                        <?php if (!$is_active) : ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('itu_spotlight_nonce'); ?>
                            <input type="hidden" name="active_panel_id" value="<?php echo esc_attr($pid); ?>" />
                            <button type="submit" name="itu_spotlight_set_active" class="button button-small" style="color:#00a32a;">Set Active</button>
                        </form>
                        <?php else : ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('itu_spotlight_nonce'); ?>
                            <button type="submit" name="itu_spotlight_deactivate" class="button button-small">Deactivate</button>
                        </form>
                        <?php endif; ?>

                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this panel?');">
                            <?php wp_nonce_field('itu_spotlight_nonce'); ?>
                            <input type="hidden" name="delete_panel_id" value="<?php echo esc_attr($pid); ?>" />
                            <button type="submit" name="itu_spotlight_delete_panel" class="button button-small" style="color:#a00;">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
    // EDIT VIEW
    else :
        $is_new = ($editing === 'new');
        $panel_id = $is_new ? '' : $editing;
        $panel = $is_new ? ['name' => '', 'items' => []] : ($panels[$panel_id] ?? ['name' => '', 'items' => []]);
        $items = $panel['items'];
    ?>
    <div class="wrap">
        <h1><?php echo $is_new ? 'Create New Panel' : 'Edit Panel: ' . esc_html($panel['name']); ?></h1>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=itu-spotlight')); ?>">&larr; Back to all panels</a></p>

        <form method="post" id="itu-spotlight-form">
            <?php wp_nonce_field('itu_spotlight_nonce'); ?>
            <input type="hidden" name="panel_id" value="<?php echo esc_attr($panel_id); ?>" />

            <table class="form-table">
                <tr>
                    <th><label for="panel_name">Panel Name</label></th>
                    <td><input type="text" id="panel_name" name="panel_name" value="<?php echo esc_attr($panel['name']); ?>" style="width:300px;" placeholder="e.g. CompTIA Spotlight, Q1 Featured" required /></td>
                </tr>
            </table>

            <h2>Courses in this Panel</h2>
            <div id="itu-spotlight-items" style="margin-bottom: 20px;">
                <?php foreach ($items as $i => $item) :
                    $product = wc_get_product($item['product_id']);
                    $product_name = $product ? $product->get_name() : '(Product #' . $item['product_id'] . ')';
                    $logo_url = $item['logo_id'] ? wp_get_attachment_image_url($item['logo_id'], 'thumbnail') : '';
                ?>
                <div class="itu-spotlight-item" style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:16px; margin-bottom:12px;">
                    <div style="display:flex; gap:16px; align-items:flex-start;">
                        <span class="dashicons dashicons-menu" style="cursor:grab; margin-top:4px; color:#999;" title="Drag to reorder"></span>
                        <div style="flex:1;">
                            <input type="hidden" name="spotlight_product_id[]" value="<?php echo esc_attr($item['product_id']); ?>" />
                            <p><strong>Product:</strong> <?php echo esc_html($product_name); ?></p>
                            <p><label><strong>Spotlight Title:</strong></label><br>
                            <input type="text" name="spotlight_title[]" value="<?php echo esc_attr($item['title']); ?>" style="width:100%; max-width:400px;" placeholder="e.g. Start here. Go anywhere." /></p>
                            <p><label><strong>Description:</strong></label><br>
                            <textarea name="spotlight_description[]" rows="3" style="width:100%; max-width:600px;" placeholder="Description shown on hover"><?php echo esc_textarea($item['description']); ?></textarea></p>
                            <p><label><strong>Logo (optional):</strong></label><br>
                            <input type="hidden" name="spotlight_logo_id[]" value="<?php echo esc_attr($item['logo_id']); ?>" class="itu-logo-id" />
                            <img src="<?php echo esc_url($logo_url); ?>" class="itu-logo-preview" style="max-height:50px; <?php echo $logo_url ? '' : 'display:none;'; ?>" />
                            <button type="button" class="button itu-upload-logo">Choose Logo</button>
                            <button type="button" class="button itu-remove-logo" style="<?php echo $logo_url ? '' : 'display:none;'; ?>">Remove</button></p>
                        </div>
                        <button type="button" class="button itu-remove-item" style="color:#a00;" title="Remove">&times;</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-bottom:20px; position:relative;">
                <input type="text" id="itu-product-search" placeholder="Search for a product to add..." style="width:300px;" autocomplete="off" />
                <div id="itu-product-results" style="border:1px solid #ccd0d4; border-radius:4px; max-height:200px; overflow-y:auto; display:none; position:absolute; z-index:100; background:#fff; width:400px;"></div>
            </div>

            <?php submit_button('Save Panel', 'primary', 'itu_spotlight_save_panel'); ?>
        </form>
    </div>

    <script>
    jQuery(function($) {
        $('#itu-spotlight-items').sortable({ handle: '.dashicons-menu', placeholder: 'ui-state-highlight' });

        $(document).on('click', '.itu-remove-item', function() { $(this).closest('.itu-spotlight-item').remove(); });

        $(document).on('click', '.itu-upload-logo', function() {
            var $btn = $(this);
            var frame = wp.media({ title: 'Select Logo', multiple: false, library: { type: 'image' } });
            frame.on('select', function() {
                var a = frame.state().get('selection').first().toJSON();
                $btn.siblings('.itu-logo-id').val(a.id);
                $btn.siblings('.itu-logo-preview').attr('src', a.url).show();
                $btn.siblings('.itu-remove-logo').show();
            });
            frame.open();
        });

        $(document).on('click', '.itu-remove-logo', function() {
            $(this).siblings('.itu-logo-id').val('0');
            $(this).siblings('.itu-logo-preview').hide();
            $(this).hide();
        });

        var $search = $('#itu-product-search'), $results = $('#itu-product-results'), timer;
        $search.on('input', function() {
            clearTimeout(timer);
            var q = $search.val().trim();
            if (q.length < 2) { $results.hide(); return; }
            timer = setTimeout(function() {
                $.getJSON(ajaxurl + '?action=itu_search_products&q=' + encodeURIComponent(q), function(data) {
                    if (!data.length) { $results.html('<div style="padding:8px;color:#999;">No products found</div>').show(); return; }
                    var h = '';
                    data.forEach(function(p) {
                        h += '<div class="itu-search-result" data-id="'+p.id+'" data-name="'+$('<span>').text(p.name).html()+'" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:8px;">';
                        if (p.image) h += '<img src="'+p.image+'" style="width:30px;height:30px;object-fit:cover;border-radius:3px;" />';
                        h += '<span>'+$('<span>').text(p.name).html()+'</span></div>';
                    });
                    $results.html(h).show();
                });
            }, 300);
        });
        $search.on('blur', function() { setTimeout(function(){ $results.hide(); }, 200); });

        $(document).on('click', '.itu-search-result', function() {
            var id = $(this).data('id'), name = $(this).data('name');
            $results.hide(); $search.val('');
            var h = '<div class="itu-spotlight-item" style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;margin-bottom:12px;">' +
                '<div style="display:flex;gap:16px;align-items:flex-start;">' +
                '<span class="dashicons dashicons-menu" style="cursor:grab;margin-top:4px;color:#999;"></span>' +
                '<div style="flex:1;">' +
                '<input type="hidden" name="spotlight_product_id[]" value="'+id+'" />' +
                '<p><strong>Product:</strong> '+name+'</p>' +
                '<p><label><strong>Spotlight Title:</strong></label><br><input type="text" name="spotlight_title[]" value="" style="width:100%;max-width:400px;" placeholder="e.g. Start here. Go anywhere." /></p>' +
                '<p><label><strong>Description:</strong></label><br><textarea name="spotlight_description[]" rows="3" style="width:100%;max-width:600px;" placeholder="Description shown on hover"></textarea></p>' +
                '<p><label><strong>Logo (optional):</strong></label><br><input type="hidden" name="spotlight_logo_id[]" value="0" class="itu-logo-id" /><img src="" class="itu-logo-preview" style="max-height:50px;display:none;" /><button type="button" class="button itu-upload-logo">Choose Logo</button> <button type="button" class="button itu-remove-logo" style="display:none;">Remove</button></p>' +
                '</div>' +
                '<button type="button" class="button itu-remove-item" style="color:#a00;">&times;</button>' +
                '</div></div>';
            $('#itu-spotlight-items').append(h);
        });
    });
    </script>
    <?php
    endif;
}

/**
 * Certification Spotlight shortcode — renders the active panel on the front end.
 */
add_shortcode('itu_certification_spotlight', function () {
    // Check transient cache
    $cached = get_transient('itu_spotlight_html');
    if ($cached !== false) return $cached;

    $panels = get_option('itu_spotlight_panels', []);
    $active_id = get_option('itu_spotlight_active', '');

    // Fallback: legacy data
    if (empty($panels) || empty($active_id)) {
        $items = get_option('itu_spotlight_items', []);
    } else {
        $items = isset($panels[$active_id]) ? $panels[$active_id]['items'] : [];
    }
    if (empty($items)) return '';

    // Build the first item as default for the info panel
    $first = $items[0];
    $first_logo_url = $first['logo_id'] ? wp_get_attachment_image_url($first['logo_id'], 'medium') : '';
    $first_product = wc_get_product($first['product_id']);

    ob_start();
    ?>
    <section class="itu-section itu-section--gray itu-spotlight">
        <div class="itu-section__inner">
            <span class="itu-eyebrow" style="margin-bottom:32px">[ Certification Spotlight ]</span>
            <div class="itu-spotlight__inner">

            <div class="itu-spotlight__info">
                <?php if ($first_logo_url) : ?>
                <div class="itu-spotlight__logo">
                    <img src="<?php echo esc_url($first_logo_url); ?>" alt="<?php echo esc_attr($first['title']); ?>" data-default="true" />
                </div>
                <?php else : ?>
                <div class="itu-spotlight__logo" style="display:none;">
                    <img src="" alt="" data-default="true" />
                </div>
                <?php endif; ?>
                <h2 class="itu-spotlight__title"><?php echo esc_html($first['title']); ?></h2>
                <p class="itu-spotlight__description"><?php echo esc_html($first['description']); ?></p>
                <a href="/it-certification-training/" class="itu-spotlight__button">Get Started Today &raquo;</a>
            </div>

            <div class="itu-spotlight__carousel">
                <div class="itu-spotlight__track">
                    <?php foreach ($items as $item) :
                        $product = wc_get_product($item['product_id']);
                        if (!$product) continue;
                        $image = wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail');
                        $logo_url = $item['logo_id'] ? wp_get_attachment_image_url($item['logo_id'], 'medium') : '';
                        $sku = $product->get_sku();
                    ?>
                    <div class="itu-certs__card"
                        data-spotlight-logo="<?php echo esc_attr($logo_url); ?>"
                        data-spotlight-title="<?php echo esc_attr($item['title']); ?>"
                        data-spotlight-desc="<?php echo esc_attr($item['description']); ?>"
>
                        <span class="itu-certs__card-label"><?php
                            $cats = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
                            echo esc_html(!empty($cats) ? $cats[0] : '');
                        ?></span>
                        <a href="<?php echo esc_url($product->get_permalink()); ?>" class="itu-certs__card-image">
                            <?php if ($image) : ?>
                                <img loading="eager" src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($product->get_name()); ?>" />
                            <?php endif; ?>
                        </a>
                        <a href="<?php echo esc_url($product->get_permalink()); ?>" class="itu-certs__card-title"><?php echo esc_html($product->get_name()); ?></a>
                        <?php if ($sku) :
                            $hours = itu_get_course_stat($sku, 'video_hours', 'ec_get_course_video_hours', 'total_hours');
                            $videos = itu_get_course_stat($sku, 'video_count', 'ec_get_course_video_count', 'total_videos');
                            $questions = itu_get_course_stat($sku, 'question_count', 'ec_get_course_prep_question_count', 'content_test_question_count');
                        ?>
                        <div class="itu-certs__card-badges">
                            <?php if ($hours) : ?>
                                <span class="itu-certs__card-badge"><?php echo esc_html($hours); ?></span>
                            <?php endif; ?>
                            <?php if ($videos) : ?>
                                <span class="itu-certs__card-badge"><?php echo esc_html(number_format($videos)); ?> Videos</span>
                            <?php endif; ?>
                            <?php if ($questions) : ?>
                                <span class="itu-certs__card-badge"><?php echo esc_html(number_format($questions)); ?> Questions</span>
                            <?php endif; ?>
                            <?php
                            $enrolled = itu_get_enrollment_count($sku);
                            if ($enrolled) : ?>
                                <span class="itu-certs__card-badge itu-certs__card-badge--enrolled"><?php echo esc_html(number_format($enrolled)); ?> Enrolled</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            </div>
        </div>
    </section>
    <?php
    $output = ob_get_clean();
    $output = str_replace(['<p></p>', '<p> </p>'], '', $output);
    set_transient('itu_spotlight_html', $output, 30 * DAY_IN_SECONDS);
    return $output;
});

/**
 * Auto-clear ITU caches when products are created, updated, or deleted.
 */
function itu_auto_clear_caches($post_id) {
    if (get_post_type($post_id) !== 'product') return;
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itu_carousel_%' OR option_name LIKE '_transient_timeout_itu_carousel_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itu_catgrid_%' OR option_name LIKE '_transient_timeout_itu_catgrid_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itu_drawer_cat_%' OR option_name LIKE '_transient_timeout_itu_drawer_cat_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itu_enrolled_%' OR option_name LIKE '_transient_timeout_itu_enrolled_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_course_videos_%' OR option_name LIKE '_transient_timeout_course_videos_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_product_outlines_%' OR option_name LIKE '_transient_timeout_product_outlines_%'");
    delete_transient('itu_spotlight_html');
    delete_transient('itu_catalog_full');
}
add_action('save_post_product', 'itu_auto_clear_caches');
add_action('woocommerce_update_product', 'itu_auto_clear_caches');
add_action('before_delete_post', 'itu_auto_clear_caches');
add_action('trashed_post', 'itu_auto_clear_caches');

function dequeue_parent_theme_styles() {
    wp_dequeue_style('style.min.css');
    wp_deregister_style('style.min.css');
}
add_action('wp_enqueue_scripts', 'dequeue_parent_theme_styles', 999);

add_filter( 'rank_math/sitemap/enable_caching', '__return_false');
add_filter( 'rank_math/admin/sensitive_data_encryption', '__return_false' );
add_filter('xmlrpc_enabled', '__return_false');

apply_filters("thaps_enable_ga_site_search_module", "__return_true" );

add_filter( 'woocommerce_account_menu_items', 'bbloomer_remove_address_my_account', 9999 );
function bbloomer_remove_address_my_account( $items ) {
   unset( $items['wt-smart-coupon'] );
   return $items;
}

add_filter('use_block_editor_for_post_type', 'codepopular_activate_gutenberg_products', 10, 2);
function codepopular_activate_gutenberg_products($can_edit, $post_type){
    if($post_type == 'product'){
        $can_edit = true;
    }
    return $can_edit;
}

 /** CONNECTION TO LMS DATABASE */
 function connect_another_db() {
    global $lmsdb;
    $lmsdb = new wpdb('itulearning_lms', 'ITU2019$', 'itulearning_lms_production', '67.43.12.126');
}

add_action('init', 'connect_another_db');

function inject_faq_jsonld_script() {
    if (!is_singular(array('post', 'page', 'product'))) {
        return;
    }

    global $post;

    // Try field key first, fall back to field name
    $jsonld = get_field('field_6816d54e3951d', $post->ID);
    if (!$jsonld) {
        $jsonld = get_field('faq_schema', $post->ID);
    }
    if (!$jsonld) {
        $jsonld = get_field('faq_json_ld', $post->ID);
    }

    if (empty($jsonld)) return;

    // Strip any existing script tags the author may have pasted in
    $jsonld = trim($jsonld);
    $jsonld = preg_replace('#^\s*<script[^>]*>\s*#i', '', $jsonld);
    $jsonld = preg_replace('#\s*</script>\s*$#i', '', $jsonld);
    $jsonld = trim($jsonld);

    // Validate JSON
    $decoded = json_decode($jsonld, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ITU FAQ Schema: Invalid JSON on post ' . $post->ID . ' — ' . json_last_error_msg());
        }
        return;
    }

    // Ensure it has required schema.org structure
    if (empty($decoded['@type'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ITU FAQ Schema: Missing @type on post ' . $post->ID);
        }
        return;
    }

    // Re-encode to sanitize (removes any injected HTML/JS within the JSON values)
    $clean_json = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!$clean_json) return;

    echo "<script type=\"application/ld+json\">\n" . $clean_json . "\n</script>\n";
}
add_action('wp_head', 'inject_faq_jsonld_script');

/**
 * Render product FAQ section from ACF field.
 * Parses the JSON-LD schema to extract Q&A pairs and renders them
 * as styled details/summary accordion items.
 */
add_shortcode('itu_product_faq', function () {
    if (!is_singular()) return '';

    global $post;

    // Get the FAQ HTML content field
    $faq_html = get_field('product_faq', $post->ID);

    // If no HTML field, try to build from the schema JSON
    if (empty($faq_html)) {
        $jsonld = get_field('faq_schema', $post->ID);
        if (!$jsonld) $jsonld = get_field('field_6816d54e3951d', $post->ID);
        if (empty($jsonld)) return '';

        $jsonld = trim($jsonld);
        $jsonld = preg_replace('#^\s*<script[^>]*>\s*#i', '', $jsonld);
        $jsonld = preg_replace('#\s*</script>\s*$#i', '', $jsonld);
        $decoded = json_decode(trim($jsonld), true);

        if (!$decoded || empty($decoded['mainEntity'])) return '';

        // Build FAQ HTML from schema
        $faqs = $decoded['mainEntity'];
    } else {
        // Add itu-faq-item class to <details> elements and clean up inner markup
        $faq_html = str_replace('<details>', '<details class="itu-faq-item">', $faq_html);
        $faq_html = str_replace('<details class="faq-item">', '<details class="itu-faq-item">', $faq_html);
        // Replace faq-content divs with simple paragraph flow
        $faq_html = preg_replace('#<div class="faq-content">\s*#i', '', $faq_html);
        $faq_html = preg_replace('#\s*</div>\s*</details>#i', '</details>', $faq_html);

        ob_start();
        ?>
        <div class="itu-faq-layout" style="margin-top:60px;padding-bottom:20px;max-width:1200px;margin-left:auto;margin-right:auto">
            <div class="itu-faq-layout__intro">
                <span class="itu-eyebrow">[ FAQ ]</span>
                <h2>Frequently Asked Questions<em>.</em></h2>
            </div>
            <div class="itu-faq-layout__list">
                <?php echo $faq_html; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // Build from parsed schema mainEntity array
    if (empty($faqs)) return '';

    ob_start();
    ?>
    <div class="itu-faq-layout" style="margin-top:60px;padding-bottom:20px;max-width:1200px;margin-left:auto;margin-right:auto">
            <div class="itu-faq-layout__intro">
                <span class="itu-eyebrow">[ FAQ ]</span>
                <h2>Frequently Asked Questions<em>.</em></h2>
            </div>
            <div class="itu-faq-layout__list">
                    <?php foreach ($faqs as $faq) :
                        $q = wp_strip_all_tags($faq['name'] ?? '');
                        $a = wp_kses_post($faq['acceptedAnswer']['text'] ?? '');
                        if (empty($q) || empty($a)) continue;
                    ?>
                    <details class="itu-faq-item">
                        <summary><?php echo esc_html($q); ?></summary>
                        <p><?php echo $a; ?></p>
                    </details>
                    <?php endforeach; ?>
            </div>
    </div>
    <?php
    return ob_get_clean();
});

 function connect_another_db_power() {
	global $power_db;
	$power_db = new wpdb('power_lms_prod', 'ITU2019$', 'power_lms_production', '67.43.13.136'); 
}
add_action('init', 'connect_another_db_power');


add_shortcode( 'hubspot-form', function() {
    return do_shortcode('[acf field="hubspot_javascript_code"]');
});

add_filter('woocommerce_add_to_cart_fragments', 'iconic_cart_count_fragments', 10, 1);
function iconic_cart_count_fragments( $fragments ) {
    $count = WC()->cart->get_cart_contents_count();
    // Legacy fragment (old theme)
    $fragments['div.header-cart-count'] = '<a class="cart-contents" href="' . wc_get_cart_url() . '" title="View your shopping cart">' . sprintf ( _n( '%d item', '%d items', $count ), $count ) . ' - ' . WC()->cart->get_cart_total() . '</a>';
    // New child theme cart count badge
    $fragments['span.itu-cart-count'] = '<span class="itu-cart-count" id="itu-cart-count">' . ($count > 0 ? $count : '') . '</span>';
    return $fragments;
}

add_filter('woocommerce_product_single_add_to_cart_text', 'QL_customize_add_to_cart_button_woocommerce');
function QL_customize_add_to_cart_button_woocommerce() {
    $terms = wp_get_post_terms( get_the_id(), 'product_tag' );
    foreach($terms as $term) {
        if ($term->name == 'Super Deal1') {
            return __('Add To Cart For A Super Deal Price', 'woocommerce');
        }
    }
    return __('Add To Cart', 'woocommerce');
}

add_shortcode('current_user_email_link', 'get_lms_user_email_address');
function get_lms_user_email_address() {
    $current_user = wp_get_current_user();
    return "https://www.itulearning.com/service/access/" . md5($current_user->user_email);
}

add_filter( 'woocommerce_loop_add_to_cart_link', 'change_add_product_link' );
function change_add_product_link( $link ) {
    $terms = wp_get_post_terms( get_the_id(), 'product_tag' );
    foreach($terms as $term) {
        if ($term->name == 'Super Deal1') {
            global $product;
            $link = '<a rel="no-follow" href="https://www.ituonline.com/cart/?add-to-cart='.$product->get_id().'&wt_coupon=dailysuperdeal" class="button add_to_cart_button" style="background-color: green; border: 0px;">View Deal</a>';
            break;
        }
    }
    return $link;
}

add_filter('woocommerce_product_add_to_cart_text', 'woocommerce_add_to_cart_button_text_archives');  
function woocommerce_add_to_cart_button_text_archives() {
    $terms = wp_get_post_terms( get_the_id(), 'product_tag' );
    foreach($terms as $term) {
        if ($term->name == 'Super Deal1') {
            return __('Super Deal', 'woocommerce');
        }
    }
    return __('Add To Cart', 'woocommerce');
}

remove_filter ('the_content', 'wpautop');

function photoswipe_dequeue_script() {
    wp_dequeue_script( 'photoswipe-ui-default' );
}
add_action( 'wp_print_scripts', 'photoswipe_dequeue_script', 100 );

add_action('wp_print_styles', 'jltwp_adminify_remove_dashicons', 100);
function jltwp_adminify_remove_dashicons() {
    if (!is_admin_bar_showing() && !is_customize_preview()) {
        wp_dequeue_style('dashicons');
        wp_deregister_style('dashicons');
    }
}

function woocommerce_get_product_outline_shortcode($course_id, $title, $hours, $videos, $desc, $questions, $display_order, $weeks_to_complete) {
    $transient_name = 'course_outline_' . $course_id;
    $course_string = get_transient($transient_name);
    if ($course_string === false) {
        $prior_id = 0;
        $course_string = '';
        global $lmsdb;
        $sql = "CALL ec_course_outlines(" . strval($course_id) . ");";
        $nav_results = $lmsdb->get_results($sql);
        $course_string = '<div class="faq--list"><span class="course-map-text"><strong>Course: ' . $display_order . '</strong> - ' . $weeks_to_complete . '</span><div><details><summary class="path-summary"><i class="fas fa-angle-double-down dropdown-arrow"></i><a rel="nofollow" href="https://www.itulearning.com/course/create_new_outline/' . $course_id . '"><i class="fas fa-file-pdf dropdown-pdf"></i></span></a><span style="color: #fff; font-size: 175%; padding-top: 20px !important; padding-bottom: 30px; display: inline-block;">' . $title . ' Course Content</span><br/><span  style="color: #fff; display: block;">';
        foreach ($nav_results as $video) {
            if ($prior_id == 0) {
                $course_string .= '<i class="fas fa-clock"></i> ' . $hours . ' <i style="padding-left: 15px;" class="fas fa-tv"></i> ' . $videos . ' Videos <i style="padding-left: 15px;" class="fas fa-question-circle"></i> ' . $questions .  ' Prep Questions</span><br/> <span class="path-description">' . $desc . '</summary>';
            }
            $current_id = $video->module_id;
            if ($current_id == 0 || $current_id != $prior_id) {
                $course_string .= '<p class="faq--content"><span style="font-size: 125%; font-weight: 700; padding-top: 10px; padding-bottom: 10px; display: inline-block;">' . $video->module_title . '</span><br />';
            }
            $prior_id = $video->module_id;
            if ($prior_id == $current_id) {
                $course_string .= '<i class="fas fa-tv fa-sm" style="color: #80a7ea; font-size: 12px !important;"></i>&nbsp;&nbsp;&nbsp;' . $video->video_title . '<br/>';
            }
        }
        $course_string .= '</p></details></div></div>';
        set_transient($transient_name, $course_string, 84 * HOUR_IN_SECONDS);
    }
    return $course_string;
}
add_shortcode('woocommerce_get_product_outline', 'woocommerce_get_product_outline_shortcode');

function woocommerce_get_product_videos_shortcode($course_id, $title) {
    $transient_name = 'course_videos_' . $course_id;
    $course_string = get_transient($transient_name);
    if ($course_string === false) {
        $prior_id = 0;
        $course_string = '';
        global $lmsdb;
        $sql = "CALL ec_course_outlines(" . strval($course_id) . ");";
        $nav_results = $lmsdb->get_results($sql);
        $course_string = '<div class="itu-outline">';
        $course_string .= '<div class="itu-outline__header">';
        $course_string .= '<h2 class="itu-outline__title"><a rel="nofollow" href="https://www.itulearning.com/course/create_new_outline/' . $course_id . '">' . esc_html($title) . ' Course Content</a></h2>';
        $course_string .= '<a rel="nofollow" href="https://www.itulearning.com/course/create_new_outline/' . $course_id . '" class="itu-button itu-outline__download" style="background:#C026D3; display:inline-block; text-align:center; font-size:0.75rem; padding:8px 20px" target="_blank">Download Outline</a>';
        $course_string .= '</div>';
        foreach ($nav_results as $video) {
            $current_id = $video->module_id;
            if ($current_id != $prior_id && $prior_id != 0) {
                $course_string .= '</ul></details>';
            }
            if ($current_id == 0 || $current_id != $prior_id) {
                $course_string .= '<details class="itu-outline__module"><summary class="itu-outline__module-title">' . esc_html($video->module_title) . '</summary><ul class="itu-outline__lessons">';
            }
            $prior_id = $video->module_id;
            if ($prior_id == $current_id) {
                $course_string .= '<li class="itu-outline__lesson"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>' . esc_html($video->video_title) . '</li>';
            }
        }
        if ($prior_id != 0) {
            $course_string .= '</ul></details>';
        }
        $course_string .= '</div>';
        set_transient($transient_name, $course_string, 84 * HOUR_IN_SECONDS);
    }
    return $course_string;
}
add_shortcode('woocommerce_get_product_videos_single', 'woocommerce_get_product_videos_shortcode');

function woocommerce_get_all_access_courses_shortcode() {
    $transient_name = 'all_access_courses';
    $course_string = get_transient($transient_name);
    if ($course_string === false) {
        $prior_id = 0;
        $course_string = '';
        $prior_category = '';
        global $lmsdb;
        $sql = "CALL ec_get_all_access_course_list();";
        $nav_results = $lmsdb->get_results($sql);
        foreach ($nav_results as $video) {
            $current_id = $video->id;
            $current_category = $video->category;
            if ($current_category != $prior_category && $prior_category != '') {
                $course_string .= "</ul></details>";
            }
            if ($current_category != $prior_category || $current_category == '') {
                $course_string .= '<h3>' . $video->category . '</h3><ul style="list-style-type: none;" class="video-list">';
            }
            if ($prior_id != $current_id) {
                $course_string .= '<li><i class="fa fa-video"></i>&nbsp;&nbsp;&nbsp;' . $video->course . '</li>';
            }
            $prior_id = $video->id;
            $prior_category = $video->category;
        }
        set_transient($transient_name, $course_string, 84 * HOUR_IN_SECONDS);
    }
    return $course_string;
}
add_shortcode('woocommerce_all_acccess_courses', 'woocommerce_get_all_access_courses_shortcode');

function woocommerce_get_product_outlines_shortcode($sku) {
    $transient_name = 'product_outlines_' . $sku['sku'];
    $list = get_transient($transient_name);
    if ($list === false) {
        $list = '';
        global $lmsdb;
        $query = "CALL ec_get_course_list_from_sku('" . $sku['sku'] . "');";
        $course_list = $lmsdb->get_results($query);
        foreach ($course_list as $course) {
            $course_id = strval($course->id);
            $title = strval($course->course_title);
            $list .= woocommerce_get_product_videos_shortcode($course_id, $title);
        }
        set_transient($transient_name, $list, 84 * HOUR_IN_SECONDS);
    }
    return $list;
}
add_shortcode('woocommerce_get_product_outlines', 'woocommerce_get_product_outlines_shortcode');

function woocommerce_get_product_path_shortcode($sku) {
    $transient_name = 'product_path_' . $sku['sku'];
    $list = get_transient($transient_name);
    if ($list === false) {
        $list = '';
        global $lmsdb;
        $query = "CALL ec_get_course_list_from_sku('" . $sku['sku'] . "');";
        $course_list = $lmsdb->get_results($query);
        foreach ($course_list as $course) {
            $course_id = strval($course->id);
            $title = strval($course->course_title);
            $hours = strval($course->hours);
            $videos = strval($course->video_total);
            $desc = strval($course->description);
            $questions = strval($course->content_test_question_count);
            $display_order = strval($course->display_order);
            $weeks_to_complete = strval($course->weeks_to_complete);
            $list .= woocommerce_get_product_outline_shortcode($course_id, $title, $hours, $videos, $desc, $questions, $display_order, $weeks_to_complete);
        }
        set_transient($transient_name, $list, 84 * HOUR_IN_SECONDS);
    }
    return $list;
}
add_shortcode('woocommerce_get_product_path', 'woocommerce_get_product_path_shortcode');

function woocommerce_get_training_series($att) {
    $transient_name = 'training_series_' . $att['sku'];
    $list = get_transient($transient_name);
    if ($list === false) {
        $list = '';
        global $lmsdb;
        $query = "CALL ec_get_training_series_courses_by_sku('" . $att['sku'] . "');";
        $course_list = $lmsdb->get_results($query);
        foreach ($course_list as $course) {
            $duration_time = $course->total_course_seconds > 0 ? floor($course->total_course_seconds / 3600) . ' Hrs ' . floor(($course->total_course_seconds / 60) % 60) . ' Min' : "N/A";
            $list .= '<div style="border-radius: 7px; margin-bottom: 10px; margin-top: 20px; padding: 10px; padding-top: 20px"><div class="row"><div class="col-md-3"><center><img src="https://www.itulearning.com/public/assets/product_images/' . $course->thumbnail . '" style="width: 200px; height: auto; border-radius: 5px;" alt="' . $course->course_title . '"></center></div><div class="col-md-9" style="padding-right: 30px;"><h3 style="color: #3977AE;">' . $course->course_title . '</h3><i style="color: #3977AE;" aria-hidden="true" class="fas fa-video"></i> ' . $course->video_total . ' Course Videos <br/><i style="color: #3977AE;" aria-hidden="true" class="fas fa-clock"></i> ' . $duration_time . ' in Duration<br /><i style="color: #3977AE;" aria-hidden="true" class="fas fa-question-circle"></i> ' . $course->content_test_question_count . ' Test Prep Questions<br /><br /><div id="' . $course->id . '" class="course-description-ts"><p>' . trim_text(html_entity_decode(strip_tags($course->description)), 100, $course->id, $course->permalink) . '</p></div><div id="full-description-' . $course->id . '" class="course-description-full"><p>' . $course->description . '</p></div></div></div></div>';
        }
        set_transient($transient_name, $list, 84 * HOUR_IN_SECONDS);
    }
    echo $list;
}
add_shortcode('woocommerce_get_training_series_shortcode', 'woocommerce_get_training_series');

function woocommerce_get_training_series_black($att) {
    $transient_name = 'training_series_black_' . $att['sku'];
    $list = get_transient($transient_name);
    if ($list === false) {
        $list = '';
        global $lmsdb;
        $query = "CALL ec_get_training_series_courses_by_sku('" . $att['sku'] . "');";
        $course_list = $lmsdb->get_results($query);
        foreach ($course_list as $course) {
            $duration_time = $course->total_course_seconds > 0 ? floor($course->total_course_seconds / 3600) . ' Hrs ' . floor(($course->total_course_seconds / 60) % 60) . ' Min' : "N/A";
            $list .= '<div class="black-box-div"><div style="border-radius: 7px; margin-bottom: 10px; margin-top: 20px; padding: 10px; padding-top: 20px"><div class="row"><div class="col-md-3"><center><img src="https://www.itulearning.com/public/assets/product_images/' . $course->thumbnail . '" style="width: 100%; height: auto; border-radius: 5px; margin-bottom: 15px;" loading="lazy" alt="' . $course->course_title . '"></center></div><div class="col-md-9" style="padding-right: 30px;"><h3 style="color: #3977AE;">' . $course->course_title . '</h3><i style="color: #3977AE;" aria-hidden="true" class="fas fa-video"></i> ' . $course->video_total . ' Course Videos <br/><i style="color: #3977AE;" aria-hidden="true" class="fas fa-clock"></i> ' . $duration_time . ' in Duration<br /><i style="color: #3977AE;" aria-hidden="true" class="fas fa-question-circle"></i> ' . $course->content_test_question_count . ' Test Prep Questions<br /><br /><div id="' . $course->id . '" class="course-description-ts"><p><span style="padding-bottom: 20px;">$99.00 when purchased separately</span><br /><br />' . trim_text(html_entity_decode(strip_tags($course->description)), 100, $course->id, $course->permalink) . '</p></div><div id="full-description-' . $course->id . '" class="course-description-full">' . $course->description . '</p></div></div></div></div></div>';
        }
        set_transient($transient_name, $list, 84 * HOUR_IN_SECONDS);
    }
    echo $list;
}
add_shortcode('woocommerce_get_training_series_black_shortcode', 'woocommerce_get_training_series_black');

function trim_text($text, $count, $id, $permalink) {
    $linked = $permalink ? '<div style="margin-top: 20px; margin-bottom: 20px;"><p style="padding-top: 10px;"><i class="fas fa-external-link-alt"></i> <a href="' . $permalink . '" target="_blank"><strong>View Full Course Details & Outline</strong></a></p></div>' : '';
    $text = str_replace("  ", " ", $text);
    $string = explode(" ", $text);
    $trimed = '';
    for ($wordCounter = 0; $wordCounter <= $count; $wordCounter++) {
        $trimed .= $string[$wordCounter];
        $trimed .= $wordCounter < $count ? " " : '...';
    }
    $trimed = trim($trimed);
    return count($string) > 100 ? $trimed . $linked : $text . $linked;
}

function woocommerce_get_product_categories() {
    $transient_name = 'product_categories';
    $list = get_transient($transient_name);
    if ($list === false) {
        global $lmsdb;
        $query = "CALL ec_get_course_categories();";
        $category_list = $lmsdb->get_results($query);
        $category_count = count($category_list);
        $start_count = 1;
        foreach ($category_list as $category) {
            $list .= ($start_count % 2 == 0) ? '<div class="col-md-6"><div class="black-box-div" style="padding: 20px; font-size: 14pt; border-radius: 7px;"><i style="color: #37699c;" class="fas fa-external-link-alt"></i> <a style="color: white !important; padding-left: 4px;" href="' . $category->permalink . '" target="blank">' .  $category->title . '</a></div></div></div>' : '<div class="row"><div class="col-md-6"><div style="padding: 20px; font-size: 14pt; border-radius: 7px;" class="black-box-div"><i style="color: #37699c;" class="fas fa-external-link-alt"></i> <a style="color: white !important; padding-left: 4px;" href="' . $category->permalink . '" target="_blank">' . $category->title . '</a></div></div>';
            $start_count++;
        }
        set_transient($transient_name, $list, 84 * HOUR_IN_SECONDS);
    }
    echo $list;
}
add_shortcode('woocommerce_get_product_categories_shortcode', 'woocommerce_get_product_categories');

function woocommerce_get_product_first_video($att) {
    $transient_name = 'first_video_' . $att['sku'];
    $icon_row = get_transient($transient_name);
    if ($icon_row === false) {
        global $lmsdb;
        $query = 'CALL ec_get_course_first_video("' . $att['sku'] . '")';
        $icon_value = $lmsdb->get_results($query);
        $icon_row = $icon_value[0];
        set_transient($transient_name, $icon_row, 84 * HOUR_IN_SECONDS);
    }
    global $product;
    $image = wp_get_attachment_url($product->get_image_id());
    return '<div id="wistia-video"><script src="https://fast.wistia.com/embed/medias/' . $icon_row->embed_id . '.jsonp" async></script><script src="https://fast.wistia.com/assets/external/E-v1.js" async></script><div class="wistia_responsive_padding" style="padding:56.25% 0 0 0;position:relative;"><div class="wistia_responsive_wrapper" style="height:100%;left:0;position:absolute;top:0;width:100%; border-radius: 10px;"><span class="wistia_embed wistia_async_' . $icon_row->embed_id . ' popover=true popoverAnimateThumbnail=true videoFoam=true" style="display:inline-block;height:100%;position:relative;width:100%">&nbsp;</span></div></div></div>';
}
add_shortcode('course_first_video_shortcode', 'woocommerce_get_product_first_video');

function woocommerce_get_product_first_video_dev($att) {
    $transient_name = 'first_video_dev_' . $att['sku'];
    $icon_row = get_transient($transient_name);
    if ($icon_row === false) {
        global $lmsdb;
        $query = 'CALL ec_get_course_first_video("' . $att['sku'] . '")';
        $icon_value = $lmsdb->get_results($query);
        $icon_row = $icon_value[0];
        set_transient($transient_name, $icon_row, 84 * HOUR_IN_SECONDS);
    }
    global $product;
    $alt_text = $product->get_name();
    $image = wp_get_attachment_url($product->get_image_id());
    $featuredImage = '<span class="wistia_embed wistia_async_' . $icon_row->embed_id . ' popover=true popoverContent=link" style="display:inline;position:relative"><a href="#"><div class="video-container"><div class="video-image"><img title="' . $alt_text . '" alt="' . $alt_text . '" src="' . $image . '" style="box-shadow: 0 4px 8px 0 rgba(0, 0, 0, 0.2), 0 6px 20px 0 rgba(0, 0, 0, 0); border-radius: 10px; max-width: 100%; height: auto; margin-top: 10px; margin-bottom: 10px;"></div></div></a></span>';
    return $featuredImage . '<div id="wistia-video" style="position: absolute; z-index: -100;"><script src="https://fast.wistia.com/embed/medias/' . $icon_row->embed_id . '.jsonp" async></script><script src="https://fast.wistia.net/assets/external/E-v1.js" async></script><div class="wistia_responsive_padding" style="padding:56.25% 0 0 0;position:relative;"><div class="wistia_responsive_wrapper" style="height:100%;left:0;position:absolute;top:0;width:100%; border-radius: 10px;"><span class="wistia_embed wistia_async_' . $icon_row->embed_id . ' popover=true popoverAnimateThumbnail=true videoFoam=true" style="display:inline-block;height:100%;position:relative;width:100%">&nbsp;</span></div></div></div>';
}
add_shortcode('course_first_video_shortcode_dev', 'woocommerce_get_product_first_video_dev');

function woocommerce_get_product_video_count($att) {
    $transient_name = 'video_count_' . $att['sku'];
    $icon_row = get_transient($transient_name);
    if ($icon_row === false) {
        global $lmsdb;
        $query = 'CALL ec_get_course_video_count("' . $att['sku'] . '")';
        $icon_value = $lmsdb->get_results($query);
        $icon_row = $icon_value[0];
        set_transient($transient_name, $icon_row, 7 * DAY_IN_SECONDS);
    }
    return '<span style="font-size: 16px; font-weight: 500;">' . number_format($icon_row->total_videos) . '</span> <span style="font-size: 16px; font-weight: 500;">On-demand Videos</span>';
}
add_shortcode('video_count_shortcode', 'woocommerce_get_product_video_count');

wp_oembed_add_provider( '/https?:\/\/(.+)?(wistia.com|wi.st)\/(medias|embed)\/.*/', 'http://fast.wistia.com/oembed', true);

function woocommerce_get_product_video_hours($att) {
    $transient_name = 'video_hours_' . $att['sku'];
    $icon_row = get_transient($transient_name);
    if ($icon_row === false) {
        global $lmsdb;
        $query = 'CALL ec_get_course_video_hours("' . $att['sku'] . '")';
        $icon_value = $lmsdb->get_results($query);
        $icon_row = $icon_value[0];
        set_transient($transient_name, $icon_row, 7 * DAY_IN_SECONDS);
    }
    return '<span style="font-size: 16px; font-weight: 500;">' . $icon_row->total_hours . '</span>';
}
add_shortcode('video_hours_shortcode', 'woocommerce_get_product_video_hours');

function woocommerce_get_skill_count($att) {
    $transient_name = 'skill_count_' . $att['sku'];
    $icon_row = get_transient($transient_name);
    if ($icon_row === false) {
        global $lmsdb;
        $query = 'CALL ec_get_course_module_count("' . $att['sku'] . '")';
        $icon_value = $lmsdb->get_results($query);
        $icon_row = $icon_value[0];
        set_transient($transient_name, $icon_row, 7 * DAY_IN_SECONDS);
    }
    return '<span style="font-size: 16px; font-weight: 500;">' . number_format($icon_row->skill_count) . '</span>  <span style="font-size: 16px; font-weight: 500;">&nbsp;Topics</span>';
}
add_shortcode('skill_count_shortcode', 'woocommerce_get_skill_count');

function woocommerce_get_product_question_count($att) {
    $transient_name = 'question_count_' . $att['sku'];
    $icon_row = get_transient($transient_name);
    if ($icon_row === false) {
        global $lmsdb;
        $query = 'CALL ec_get_course_prep_question_count("' . $att['sku'] . '")';
        $icon_value = $lmsdb->get_results($query);
        $icon_row = $icon_value[0];
        set_transient($transient_name, $icon_row, 7 * DAY_IN_SECONDS);
    }
    return '<span style="font-size: 16px; font-weight: 500;">' . number_format($icon_row->content_test_question_count) . '</span>  <span style="font-weight: 500; font-size: 16px;">Prep Questions</span>';
}
add_shortcode('video_question_shortcode', 'woocommerce_get_product_question_count');

function woocommerce_get_bundle_course_count($att) {
    $transient_name = 'bundle_course_count_' . $att['sku'];
    $icon_row = get_transient($transient_name);
    if ($icon_row === false) {
        global $lmsdb;
        $query = 'CALL ec_get_course_count("' . $att['sku'] . '")';
        $icon_value = $lmsdb->get_results($query);
        $icon_row = $icon_value[0];
        set_transient($transient_name, $icon_row, 7 * DAY_IN_SECONDS);
    }
    return $icon_row->course_count . ' Full-length<br />Courses';
}
add_shortcode('course_count_shortcode', 'woocommerce_get_bundle_course_count');

function cart_item_count() {
    $cart_count = WC()->cart->get_cart_contents_count();
    if ($cart_count == 0) {
        echo '<a href="https://www.ituonline.com/cart/">0 Items</a>';
    } elseif ($cart_count == 1) {
        echo '<a href="https://www.ituonline.com/cart/">' . $cart_count . " Item</a>";
    } elseif ($cart_count > 1) {
        echo '<a href="https://www.ituonline.com/cart/">' . $cart_count . " Items</a>";
    }
}
add_shortcode('cart_count_shortcode', 'cart_item_count');

add_action('woocommerce_before_single_product-test', 'bbloomer_prev_next_product');
add_action('woocommerce_after_single_product-test', 'bbloomer_prev_next_product');
function bbloomer_prev_next_product(){
    echo '<div class="prev_next_buttons">';
    $previous = next_post_link('%link', '&larr; PREVIOUS', TRUE, ' ', 'product_cat');
    $next = previous_post_link('%link', 'NEXT &rarr;', TRUE, ' ', 'product_cat');
    echo $previous;
    echo $next;
    echo '</div>';
}

function get_seo_data($att) {
    if(current_user_can('administrator')) {
        global $wpdb;
        $query = 'CALL sp_get_seo_score("' . $att['product_id'] . '")';
        $seo_value = $wpdb->get_results($query);
        $seo_row = $seo_value[0];
        $color = $seo_row->seo_score > 80 ? "green" : ($seo_row->seo_score <= 30 ? "red" : "orange");
        return '<div style="background-color: #424242; color: white; border-radius: 5px; padding: 5px; padding-left: 10px; padding-right: 10px; font-size: 10pt;">Admin Only View:</br>SEO Score: <span style="border-radius: 3px; padding: 4px; background-color: ' . $color . ';">' . $seo_row->seo_score . '</span></br>Focused Keyword: <strong>' . $seo_row->primary_key . '</strong></br>Description Word Count: <strong>' . $seo_row->wordcount . '</strong></br>Last Modified: <strong>' . $seo_row->post_modified . '</strong></div>';
    }
    return;
}
add_shortcode('get_seo_data_shortcode', 'get_seo_data');

function woocommerce_short_meta($att) {
    $transient_name = 'short_meta_' . $att['sku'];
    $icon_row = get_transient($transient_name);
    if ($icon_row === false) {
        global $lmsdb;
        $query = 'CALL ec_get_short_meta_data_by_sku("' . $att['sku'] . '")';
        $icon_value = $lmsdb->get_results($query);
        $icon_row = $icon_value[0];
        set_transient($transient_name, $icon_row, 84 * HOUR_IN_SECONDS);
    }
    return '<center><div style="color: white;"><img class="meta_icon" src="https://www.ituonline.com/wp-content/uploads/2023/02/clock.png" alt="Hours"> ' . $icon_row->course_time . ' Hours  <img  class="meta_icon"  src="https://www.ituonline.com/wp-content/uploads/2023/02/video.png" alt="Total Videos"> ' . $icon_row->video_count . ' Videos  <img  class="meta_icon"  src="https://www.ituonline.com/wp-content/uploads/2023/02/question-circle.png" alt="Practice Questions"> ' . $icon_row->total_questions . ' Practice Questions</div></center>';
}
add_shortcode('course_short_meta_shortcode', 'woocommerce_short_meta');

function woocommerce_instructor_by_sku($att) {
    $transient_name = 'instructor_by_sku_' . $att['sku'];
    $display = get_transient($transient_name);
    if ($display === false) {
        global $lmsdb, $power_db;
        $query = 'CALL ec_get_instructor_by_sku("' . $att['sku'] . '")';
        $instructor_ids = $lmsdb->get_results($query);
        $num_rows = count($instructor_ids);
        $display = do_shortcode('[elementor-template id="1002991"]');
        if ($num_rows > 0) {
            $heading = ($num_rows == 1) ? '<h3>Your Training Instructor</h3>' : '<h3>Your Training Instructors</h3>';
            $display .= $heading;
            foreach ($instructor_ids as $id) {
                $query = 'CALL ec_get_instructor_by_id(' . $id->instructor_id . ')';
                $instructors = $power_db->get_results($query);
                $instructor_row = $instructors[0];
                $image = empty($instructor_row->photo) ? 'itu-circle.jpg' : $instructor_row->photo;
                $display .= '<div class="row" style="height: 150px;"><div class="ins-col ins-left"><img style="height: 120px; width: 120px; border: 5px solid #24ABDF;border-radius: 50%;" src="https://www.thepowerlms.com/instructor_photos/' . $image . '" alt="' . $instructor_row->first_name . ' ' . $instructor_row->last_name . '"></div>';
                $display .= '<div class="ins-col ins-right"><h3 style="margin-top: 0px !important; margin-bottom: 5px !important;">' . $instructor_row->first_name . ' ' . $instructor_row->last_name . '</h3>' . $instructor_row->title . '</br><a href="https://www.ituonline.com/product-tag/' . $instructor_row->ec_slug . '"><button style="margin-top: 15px;">View Instructor Courses</button></a></div></div>';
                $display .= '<p class="p1">' . $instructor_row->bio . '</p>';
            }
        }
        set_transient($transient_name, $display, 7 * DAY_IN_SECONDS);
    }
    return $display;
}
add_shortcode('instructor_shortcode', 'woocommerce_instructor_by_sku');

function woocommerce_instructor_by_tag() {
    $slugs = explode("/", $_SERVER['REQUEST_URI']);
    global $power_db;
    $query = 'CALL ec_get_instructor_by_slug("' . $slugs[2] . '")';
    $instructor_ids = $power_db->get_results($query);
    $num_rows = count($instructor_ids);
    $display = '';
    if ($num_rows > 0) {
        $instructor_row = $instructor_ids[0];
        $image = empty($instructor_row->photo) ? "itu-circle.jpg" : $instructor_row->photo;
        $display .= '<div class="row" style="height: 175px"><div class="ins-col ins-left"><img style="border: 5px solid #24ABDF;border-radius: 50%;" height="150" width="150" src="https://www.thepowerlms.com/instructor_photos/'. $image . '" alt="' . $instructor_row->first_name . ' ' . $instructor_row->last_name . '"></div>';
        $display .= '<div class="ins-col ins-right" style="color: #fff; vertical-align: middle"><h1 style="margin-top: 40px !important; margin-bottom: 0px !important; color: #fff;">' . $instructor_row->first_name . ' ' . $instructor_row->last_name . '</h1>' . $instructor_row->title . '</div></div>';
        $display .= '<p style="color: #fff !important; padding-bottom: 75px;">' . $instructor_row->bio . '</p>';
    }
    return $display;
}
add_shortcode('instructor_tag_shortcode', 'woocommerce_instructor_by_tag');

function send_custom_webhook($record, $handler) {
    $form_name = $record->get_form_settings('form_name');
    if ('weekend_warrior' !== $form_name) {
        return;
    }
    $raw_fields = $record->get('fields');
    $fields = [];
    foreach ($raw_fields as $id => $field) {
        $fields[$id] = $field['value'];
    }
    $response = wp_remote_post('https://reseller.ituonline.com/service/warrior/', ['body' => $fields]);
    print_r($response);
}
add_action('elementor_pro/forms/new_record', 'send_custom_webhook', 10, 2);

add_filter('http_request_timeout', function ($timeout) {
    return 30;
});

function turn_rm_faq_to_accordion() {
    ?>
    <script>
    (function() {
        var faqBlock = document.getElementById('rank-math-faq');
        if (!faqBlock) return;

        // Inject chevron icon into each question
        var arrow = '<span class="itu-faq-item__icon"><svg viewBox="0 0 12 8" width="12" height="8"><polyline points="1 1 6 6 11 1" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';

        var items = faqBlock.querySelectorAll('.rank-math-list-item');
        items.forEach(function(item) {
            // Add our class for styling
            item.classList.add('itu-faq-item');

            var question = item.querySelector('.rank-math-question');
            if (question && !question.querySelector('.itu-faq-item__icon')) {
                question.innerHTML = '<span>' + question.textContent + '</span>' + arrow;
            }

            var answer = item.querySelector('.rank-math-answer');
            if (answer) {
                answer.classList.add('itu-faq-item__body');
                answer.style.maxHeight = '';
                answer.style.overflow = '';
            }

            item.addEventListener('click', function(e) {
                var isOpen = this.classList.contains('is-open');
                // Close all
                items.forEach(function(el) { el.classList.remove('is-open'); });
                // Toggle clicked
                if (!isOpen) this.classList.add('is-open');
            });
        });
    })();
    </script>
    <?php
}
add_action('wp_footer', 'turn_rm_faq_to_accordion');

add_shortcode('custom_tag_cloud', 'tag_cloud');
function tag_cloud($args = array()) {
    return wp_tag_cloud(array(
        'echo' => false,
        'number' => 0,
        'smallest' => 14,
        'largest' => 37,
        'unit' => 'px'
    ));
}

add_filter('rank_math/sitemap/enable_caching', '__return_false');

function shortcode_terms_by_letter($atts) {
    $attributes = shortcode_atts(array('letter' => 'A', 'page' => 1), $atts);
    $letter = isset($_GET['letter']) && !empty($_GET['letter']) ? $_GET['letter'] : $attributes['letter'];
    $page = isset($_GET['pagenum']) && !empty($_GET['pagenum']) ? intval($_GET['pagenum']) : $attributes['page'];
    $results = get_terms_by_letter($letter, $page);
    $terms = $results['terms'];
    $pagination = $results['pagination'];
    $output = '<div class="terms-listing">';
    foreach ($terms as $term) {
        $output .= '<div class="term-item">';
        $output .= '<h2 class="glossary-term">' . $term->term . '</h2>';
        $output .= '<p class="glossary-definition">' . $term->definition . '</p>';
        $output .= '<p class="glossary-area">You will find this term commonly used in <strong>' . esc_html($term->primary_area) . '</strong></p>';
        $output .= '</div>';
    }
    $output .= paginate_terms($letter, $pagination['current_page'], $pagination['total_pages']);
    $output .= '</div>';
    $output .= build_faq_schema($terms);
    return $output;
}
add_shortcode('terms_by_letter', 'shortcode_terms_by_letter');

function shortcode_terms_by_search($atts) {
    $attributes = shortcode_atts(array('letter' => 'A', 'page' => 1), $atts);
    $letter = isset($_POST['search_term']) && !empty($_POST['search_term']) ? $_POST['search_term'] : $attributes['letter'];
    $letter = isset($_GET['letter']) && !empty($_GET['letter']) ? $_GET['letter'] : $letter;
    $page = isset($_GET['pagenum']) && !empty($_GET['pagenum']) ? intval($_GET['pagenum']) : $attributes['page'];
    $results = search_terms($letter, $page);
    $terms = $results['terms'];
    $pagination = $results['pagination'];
    $output = '<div class="terms-listing">';
    if (!empty($terms)) {
        foreach ($terms as $term) {
            $highlighted_term = highlight_terms($term->term, $letter);
            $highlighted_definition = highlight_terms($term->definition, $letter);
            $output .= '<div class="term-item">';
            $output .= '<h2 class="glossary-term">' . $highlighted_term . '</h2>';
            $output .= '<p class="glossary-definition">' . $highlighted_definition . '</p>';
            $output .= '<p class="glossary-area">You will find this term commonly used in <strong>' . esc_html($term->primary_area) . '</strong></p>';
            $output .= '</div>';
        }
        $output .= paginate_terms($letter, $pagination['current_page'], $pagination['total_pages']);
    } else {
        $output .= '<div style="font-size: 1.4em;">Sorry but no results were found. Please try again using different terms.</div>';
    }
    $output .= '</div>';
    $output .= build_faq_schema($terms);
    return $output;
}
add_shortcode('terms_by_search', 'shortcode_terms_by_search');

function get_terms_by_letter($letter, $page = 1) {
    global $wpdb;
    $table_name = 'ec_it_glossary';
    $items_per_page = 50;
    $offset = ($page - 1) * $items_per_page;
    $total_query = $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE `Term` LIKE %s", $wpdb->esc_like($letter) . '%');
    $total = $wpdb->get_var($total_query);
    $total_pages = ceil($total / $items_per_page);
    $terms_query = $wpdb->prepare("SELECT * FROM {$table_name} WHERE `term` LIKE %s ORDER BY `term` ASC LIMIT %d, %d", $wpdb->esc_like($letter) . '%', $offset, $items_per_page);
    $results = $wpdb->get_results($terms_query);
    return array('terms' => $results, 'pagination' => array('total' => $total, 'per_page' => $items_per_page, 'current_page' => $page, 'total_pages' => $total_pages, 'has_prev' => $page > 1, 'has_next' => $page < $total_pages));
}

function search_terms($search, $page = 1) {
    global $wpdb;
    $table_name = 'ec_it_glossary';
    $items_per_page = 50;
    $offset = ($page - 1) * $items_per_page;
    $search = isset($_POST['search_term']) && !empty($_POST['search_term']) ? $_POST['search_term'] : $search;
    $page = isset($_GET['pagenum']) && !empty($_GET['pagenum']) ? intval($_GET['pagenum']) : $page;
    $modified_query = remove_stop_words_from_query($search);
    $total_query = $wpdb->prepare("SELECT count(*) FROM {$table_name} WHERE `term` LIKE %s OR `definition` LIKE %s", '%' . $wpdb->esc_like($modified_query) . '%', '%' . $wpdb->esc_like($modified_query) . '%');
    $total = $wpdb->get_var($total_query);
    $total_pages = ceil($total / $items_per_page);
    $search_words = explode(' ', $modified_query);
    $sql = "SELECT * FROM {$table_name} WHERE";
    $search_conditions = array();
    foreach ($search_words as $word) {
        $word = esc_sql($word);
        $search_conditions[] = $wpdb->prepare("(term LIKE '%%%s%%' OR definition LIKE '%%%s%%')", $word, $word);
    }
    $sql .= implode(' AND ', $search_conditions) . " ORDER BY `term` ASC LIMIT " . $offset . "," . $items_per_page;
    $results = $wpdb->get_results($sql);
    return array('terms' => $results, 'pagination' => array('total' => $total, 'per_page' => $items_per_page, 'current_page' => $page, 'total_pages' => $total_pages, 'has_prev' => $page > 1, 'has_next' => $page < $total_pages));
}

function build_faq_schema($results) {
    if (empty($results)) return '';

    $faqs = [];
    foreach ($results as $term) {
        if (empty($term->term) || empty($term->definition)) continue;
        $faqs[] = [
            '@type' => 'Question',
            'name' => wp_strip_all_tags("What is " . $term->term . "?"),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => wp_strip_all_tags($term->definition),
            ],
        ];
    }

    if (empty($faqs)) return '';

    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $faqs,
    ];

    $encoded = wp_json_encode($jsonLd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!$encoded) return '';

    return '<script type="application/ld+json">' . $encoded . '</script>';
}

function paginate_terms($letter, $current_page, $total_pages) {
    if ($total_pages > 1) {
        $output = '<nav class="elementor-pagination">';
        if ($current_page > 1) {
            $output .= '<a class="page-numbers prev" href="?letter=' . $letter . '&pagenum=' . ($current_page - 1) . '">&laquo; Previous</a>&nbsp;';
        }
        if ($total_pages > 18) {
            $start_range = 1;
            $end_range = 9;
            $middle_range_start = $total_pages - 8;
            $middle_range_end = $total_pages;
            for ($page = 1; $page <= $total_pages; $page++) {
                if ($page <= $end_range || $page > $middle_range_start) {
                    $class = $page == $current_page ? ' class="page-numbers current"' : 'class="page-numbers"';
                    $output .= '<a href="?letter=' . $letter . '&pagenum=' . $page . '"' . $class . '>' . $page . '</a>&nbsp;';
                }
                if ($page == $end_range) {
                    $output .= '<span class="page-numbers dots">...</span>&nbsp;';
                }
            }
        } else {
            for ($page = 1; $page <= $total_pages; $page++) {
                $class = $page == $current_page ? ' class="page-numbers current"' : 'class="page-numbers"';
                $output .= '<a href="?letter=' . $letter . '&pagenum=' . $page . '"' . $class . '>' . $page . '</a>&nbsp;';
            }
        }
        if ($current_page < $total_pages) {
            $output .= '<a class="page-numbers next" href="?letter=' . $letter . '&pagenum=' . ($current_page + 1) . '">Next &raquo;</a>&nbsp;';
        }
        $output .= '</nav>';
     //   $output .= '<link rel="canonical" href="' . getCurrentURL() . '">';
        return $output;
    }
    return;
}

function getCurrentUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $serverName = $_SERVER['SERVER_NAME'];
    $port = ($_SERVER['SERVER_PORT'] == 80 || $_SERVER['SERVER_PORT'] == 443) ? '' : ':' . $_SERVER['SERVER_PORT'];
    $requestUri = $_SERVER['REQUEST_URI'];
    return $protocol . $serverName . $port . $requestUri;
}

function remove_stop_words_from_query($query) {
    $stop_words = array('a', 'an', 'and', 'the', 'is', 'at', 'which', 'on', 'of', 'to', 'in', 'for', 'with', 'by', 'from', 'up', 'off', 'this', 'that', 'it', 'as', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'shall', 'should', 'can', 'could', 'may', 'might', 'must', 'ought', 'or');
    $words = explode(' ', $query);
    $filtered_words = array_diff($words, $stop_words);
    return implode(' ', $filtered_words);
}

function highlight_terms($text, $terms) {
    $words = explode(' ', $terms);
    foreach ($words as $term) {
        $term = htmlspecialchars($term);
        $text = preg_replace("/($term)/i", '<span class="highlight">$1</span>', $text);
    }
    return $text;
}

function elementor_woocommerce_catalog_ordering() {
    $show_default_orderby = 'menu_order' === apply_filters('woocommerce_default_catalog_orderby', get_option('woocommerce_default_catalog_orderby', 'menu_order'));
    $catalog_orderby_options = apply_filters('woocommerce_catalog_orderby', array(
        'menu_order' => __('Default sorting', 'woocommerce'),
        'popularity' => __('Sort by popularity', 'woocommerce'),
        'rating' => __('Sort by average rating', 'woocommerce'),
        'date' => __('Sort by latest', 'woocommerce'),
        'price' => __('Sort by price: low to high', 'woocommerce'),
        'price-desc' => __('Sort by price: high to low', 'woocommerce'),
    ));
    $default_orderby = wc_get_loop_prop('is_search') ? 'relevance' : apply_filters('woocommerce_default_catalog_orderby', get_option('woocommerce_default_catalog_orderby', ''));
    $orderby = isset($_GET['orderby']) ? wc_clean(wp_unslash($_GET['orderby'])) : $default_orderby;
    if (wc_get_loop_prop('is_search')) {
        $catalog_orderby_options = array_merge(array('relevance' => __('Relevance', 'woocommerce')), $catalog_orderby_options);
        unset($catalog_orderby_options['menu_order']);
    }
    if (!$show_default_orderby) {
        unset($catalog_orderby_options['menu_order']);
    }
    if (!wc_review_ratings_enabled()) {
        unset($catalog_orderby_options['rating']);
    }
    if (!array_key_exists($orderby, $catalog_orderby_options)) {
        $orderby = current(array_keys($catalog_orderby_options));
    }
    $order_dropdown = wc_get_template('loop/orderby.php', array('catalog_orderby_options' => $catalog_orderby_options, 'orderby' => $orderby, 'show_default_orderby' => $show_default_orderby));
    echo $order_dropdown;
}
add_shortcode('product_sort_shortcode', 'elementor_woocommerce_catalog_ordering');

add_filter('woocommerce_breadcrumb_defaults', 'ts_woocommerce_breadcrumbs_change');
function ts_woocommerce_breadcrumbs_change() {
    return array('delimiter' => '  |  ', 'wrap_before' => '<nav class="woocommerce-breadcrumb" itemprop="breadcrumb" style="margin-right:5%">', 'wrap_after' => '</nav>', 'before' => '', 'after' => '', 'home' => _x('ITU Online', 'breadcrumb', 'woocommerce'));
}

function activate_gutenberg_product($can_edit, $post_type) {
    return $post_type == 'product' ? true : $can_edit;
}
add_filter('use_block_editor_for_post_type', 'activate_gutenberg_product', 10, 2);

function enable_taxonomy_rest($args) {
    $args['show_in_rest'] = true;
    return $args;
}
add_filter('woocommerce_taxonomy_args_product_cat', 'enable_taxonomy_rest');
add_filter('woocommerce_taxonomy_args_product_tag', 'enable_taxonomy_rest');

if (!function_exists('chld_thm_cfg_locale_css')):
    function chld_thm_cfg_locale_css($uri) {
        return (empty($uri) && is_rtl() && file_exists(get_template_directory() . '/rtl.css')) ? get_template_directory_uri() . '/rtl.css' : $uri;
    }
endif;
add_filter('locale_stylesheet_uri', 'chld_thm_cfg_locale_css');

if (!function_exists('child_theme_configurator_css')):
    function child_theme_configurator_css() {
        wp_enqueue_style('chld_thm_cfg_separate', trailingslashit(get_stylesheet_directory_uri()) . 'ctc-style.css', array('hello-elementor', 'hello-elementor'));
    }
endif;
add_action('wp_enqueue_scripts', 'child_theme_configurator_css', 15);

add_filter('get_the_archive_title', function($title) {
    return strpos($title, 'Category:') !== false ? str_replace('Category:', '', $title) : $title;
});

defined('CHLD_THM_CFG_IGNORE_PARENT') or define('CHLD_THM_CFG_IGNORE_PARENT', TRUE);

function create_custom_post_type() {
    register_post_type('custom_data', array('labels' => array('name' => __('Custom Data'), 'singular_name' => __('Custom Data')), 'public' => true, 'has_archive' => true, 'supports' => array('title', 'editor')));
}
add_action('init', 'create_custom_post_type');

function update_custom_posts() {
    $results = execute_stored_procedure();
    if (!empty($results)) {
        foreach ($results as $result) {
            $existing_post = get_page_by_title($result['title'], OBJECT, 'custom_data');
            if ($existing_post) {
                wp_update_post(array('ID' => $existing_post->ID, 'post_title' => $result['title']));
            } else {
                wp_insert_post(array('post_title' => $result['title'], 'post_type' => 'custom_data', 'post_status' => 'publish'));
            }
        }
    }
}
add_action('init', 'update_custom_posts');

function execute_stored_procedure() {
    global $wpdb;
    return $wpdb->get_results("CALL ec_lab_list", ARRAY_A);
}

function store_procedure_results() {
    $results = execute_stored_procedure();
    set_transient('procedure_results', $results, 84 * HOUR_IN_SECONDS);
}
add_action('init', 'store_procedure_results');

function custom_elementor_query($query) {
    if ('custom_procedure_query' === $query->get('query_id')) {
        $results = get_transient('procedure_results');
        if (!empty($results)) {
            $post_ids = wp_list_pluck($results, 'ID');
            $query->set('post__in', $post_ids);
            $query->set('orderby', 'post__in');
        } else {
            $query->set('post__in', array(0));
        }
    }
}
add_action('elementor/query/custom_procedure_query', 'custom_elementor_query');



// Function to handle the email sending
function handle_custom_team_email() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    $firstname   = sanitize_text_field($_POST['firstname'] ?? '');
    $lastname    = sanitize_text_field($_POST['lastname'] ?? '');
    $company     = sanitize_text_field($_POST['company'] ?? '');
    $phone       = sanitize_text_field($_POST['phone'] ?? '');
    $email       = sanitize_email($_POST['email'] ?? '');
    $lead_source = sanitize_text_field($_POST['lead_source'] ?? 'Team Training Page');
    $est_emp     = sanitize_text_field($_POST['number_of_team_members'] ?? '');
    $comments    = sanitize_text_field($_POST['comments'] ?? '');
    $timestamp   = current_time('F j, Y \a\t g:i A');

    // Formatted HTML email
    $message = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto">'
        . '<div style="background:#111;color:#fff;padding:20px 24px;text-align:center">'
        . '<h1 style="margin:0;font-size:20px;font-weight:700;letter-spacing:1px">NEW TEAM TRAINING INQUIRY</h1>'
        . '</div>'
        . '<div style="padding:24px;border:1px solid #e0e0e0;border-top:none">'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999;width:140px">Name</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;font-weight:600">' . esc_html($firstname . ' ' . $lastname) . '</td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Company</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;font-weight:600">' . esc_html($company) . '</td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Email</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0"><a href="mailto:' . esc_attr($email) . '" style="color:#C026D3;text-decoration:none">' . esc_html($email) . '</a></td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Phone</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0">' . esc_html($phone) . '</td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Team Size</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;font-weight:600">' . esc_html($est_emp) . ' members</td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Source</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0">' . esc_html($lead_source) . '</td></tr>'
        . '</table>';
    if (!empty($comments)) {
        $message .= '<div style="margin-top:16px;padding:16px;background:#f9f9f9;border-left:3px solid #C026D3;font-size:14px">'
            . '<strong style="display:block;margin-bottom:6px;color:#999;font-size:12px;text-transform:uppercase;letter-spacing:1px">Comments</strong>'
            . esc_html($comments)
            . '</div>';
    }
    $message .= '<div style="margin-top:20px;padding-top:16px;border-top:1px solid #f0f0f0;font-size:12px;color:#999">Submitted on ' . $timestamp . '</div>'
        . '</div></body></html>';

    // Send email
    $to = 'customerservice@ituonline.com, mbongo@ituonline.com, dlogerquist@ituonline.com, derrickj@ituonline.com, rickj@ituonline.com';
    $subject = '🏢 New Team Training Inquiry — ' . esc_html($company) . ' (' . esc_html($est_emp) . ' members)';
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    add_filter('wp_mail_from', 'custom_wp_mail_from');
    add_filter('wp_mail_from_name', 'custom_wp_mail_from_name');
    wp_mail($to, $subject, $message, $headers);
    remove_filter('wp_mail_from', 'custom_wp_mail_from');
    remove_filter('wp_mail_from_name', 'custom_wp_mail_from_name');

    // Send to Slack
    itu_send_slack_notification('team', [
        'name'      => $firstname . ' ' . $lastname,
        'company'   => $company,
        'email'     => $email,
        'phone'     => $phone,
        'team_size' => $est_emp,
        'source'    => $lead_source,
        'comments'  => $comments,
    ]);
}




// Function to handle the email sending
function handle_custom_reseller_email() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    $firstname         = sanitize_text_field($_POST['firstname'] ?? '');
    $lastname          = sanitize_text_field($_POST['lastname'] ?? '');
    $company           = sanitize_text_field($_POST['company'] ?? '');
    $phone             = sanitize_text_field($_POST['phone'] ?? '');
    $email             = sanitize_email($_POST['email'] ?? '');
    $lead_source       = sanitize_text_field($_POST['lead_source'] ?? 'Reseller Page');
    $site_url          = sanitize_text_field($_POST['site_url'] ?? '');
    $country           = sanitize_text_field($_POST['country'] ?? '');
    $comments          = sanitize_text_field($_POST['comments'] ?? '');
    $years_in_business = sanitize_text_field($_POST['years_in_business'] ?? '');
    $est_annual_sales  = sanitize_text_field($_POST['est_annual_sales'] ?? '');
    $timestamp         = current_time('F j, Y \a\t g:i A');

    // Formatted HTML email
    $message = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto">'
        . '<div style="background:#111;color:#fff;padding:20px 24px;text-align:center">'
        . '<h1 style="margin:0;font-size:20px;font-weight:700;letter-spacing:1px">NEW RESELLER INQUIRY</h1>'
        . '</div>'
        . '<div style="padding:24px;border:1px solid #e0e0e0;border-top:none">'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999;width:140px">Name</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;font-weight:600">' . esc_html($firstname . ' ' . $lastname) . '</td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Company</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;font-weight:600">' . esc_html($company) . '</td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Email</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0"><a href="mailto:' . esc_attr($email) . '" style="color:#C026D3;text-decoration:none">' . esc_html($email) . '</a></td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Phone</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0">' . esc_html($phone) . '</td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Website</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0"><a href="' . esc_url($site_url) . '" style="color:#C026D3;text-decoration:none">' . esc_html($site_url) . '</a></td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Country</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0">' . esc_html($country) . '</td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Years in Business</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0">' . esc_html($years_in_business) . '</td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Est. Annual Sales</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0">' . esc_html($est_annual_sales) . '</td></tr>'
        . '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f0f0;color:#999">Source</td><td style="padding:10px 0;border-bottom:1px solid #f0f0f0">' . esc_html($lead_source) . '</td></tr>'
        . '</table>';
    if (!empty($comments)) {
        $message .= '<div style="margin-top:16px;padding:16px;background:#f9f9f9;border-left:3px solid #C026D3;font-size:14px">'
            . '<strong style="display:block;margin-bottom:6px;color:#999;font-size:12px;text-transform:uppercase;letter-spacing:1px">Comments</strong>'
            . esc_html($comments)
            . '</div>';
    }
    $message .= '<div style="margin-top:20px;padding-top:16px;border-top:1px solid #f0f0f0;font-size:12px;color:#999">Submitted on ' . $timestamp . '</div>'
        . '</div></body></html>';

    // Send email
    $to = 'customerservice@ituonline.com, mbongo@ituonline.com, dlogerquist@ituonline.com, derrickj@ituonline.com, rickj@ituonline.com';
    $subject = '🤝 New Reseller Inquiry — ' . esc_html($company) . ' (' . esc_html($country) . ')';
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    add_filter('wp_mail_from', 'custom_wp_mail_from');
    add_filter('wp_mail_from_name', 'custom_wp_mail_from_name');
    wp_mail($to, $subject, $message, $headers);
    remove_filter('wp_mail_from', 'custom_wp_mail_from');
    remove_filter('wp_mail_from_name', 'custom_wp_mail_from_name');

    // Send to Slack
    itu_send_slack_notification('reseller', [
        'name'    => $firstname . ' ' . $lastname,
        'company' => $company,
        'email'   => $email,
        'phone'   => $phone,
        'country' => $country,
        'years'   => $years_in_business,
        'sales'   => $est_annual_sales,
        'source'  => $lead_source,
        'comments' => $comments,
    ]);
}

// Register the endpoint
add_action('rest_api_init', function () {
	
	// handles the email sent for team inquiry
    register_rest_route('custom/v1', '/send-email', array(
        'methods' => 'POST',
        'callback' => 'handle_custom_team_email',
    ));
	
	
	// handles the email for reseller inquiry
	register_rest_route('custom/v1', '/send-reseller-email', array(
        'methods' => 'POST',
        'callback' => 'handle_custom_reseller_email',
    ));
});



// Custom functions to set the "From" email address and name
function custom_wp_mail_from($original_email_address) {
    return 'customerservice@ituonline.com'; // Replace with your desired "From" email address
}

function custom_wp_mail_from_name($original_email_from) {
    return 'ITU Online Website Lead'; // Replace with your desired "From" name
}


// Enqueue FancyBox scripts and styles
function enqueue_fancybox() {
    // Check if FancyBox CSS is already enqueued
    if (!wp_style_is('fancybox-css', 'enqueued')) {
        wp_enqueue_style('fancybox-css', 'https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.5.7/jquery.fancybox.min.css');
    }

    // Check if FancyBox JS is already enqueued
    if (!wp_script_is('fancybox-js', 'enqueued')) {
        wp_enqueue_script('fancybox-js', 'https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.5.7/jquery.fancybox.min.js', array('jquery'), '3.5.7', true);
    }
}
add_action('wp_enqueue_scripts', 'enqueue_fancybox');

function generate_random_quiz($atts) {
    global $lmsdb; // Use $lmsdb instead of $wpdb

    // Extract shortcode attributes
    $atts = shortcode_atts(array(
        'sku' => '',
    ), $atts, 'random_quiz');

    $sku = sanitize_text_field($atts['sku']);

    // Call the stored procedure with the SKU
    $results = $lmsdb->get_results($lmsdb->prepare("CALL ec_random_questions_by_sku(%s)", $sku));

    if (empty($results)) {
        return ".";
    }
	$course_title = $results[0]->title;
	$content_id = $results[0]->content_id;
    // Generate the HTML for the quiz
    ob_start();
    ?>
   <a class="open-quiz" data-content-id="<?php echo esc_attr($content_id); ?>" data-fancybox data-src="#hidden-content" href="javascript:;"><button>Test Your Knowledge on This Course</button></a>

    <div style="display: none;" id="hidden-content">
		
        <form id="random-quiz-form" method="post">
            <div class="quiz-container">
				<h2>Test Your Knowledge About:<br/><?php echo esc_html($course_title); ?></h2>
				<div id="quiz-results"></div>
                <?php 
                $current_question_id = 0;
                foreach ($results as $question) {
                    if ($current_question_id != $question->question_id) {
                        if ($current_question_id != 0) {
                            echo '</div>'; // Close previous question div
                        }
                        $current_question_id = $question->question_id;
                        ?>
                        <div class="quiz-question" id="question_<?php echo esc_attr($question->question_id); ?>">
                            <h4><?php echo esc_html($question->question); ?></h4>
                        <?php
                    }
                    ?>
                    <div class="quiz-answer" id="answer_<?php echo esc_attr($question->answer_id); ?>">
                        <label class="custom-label">
                            <?php if ($question->question_type_id == 1) { ?>
                                <input type="radio" name="question_<?php echo esc_attr($question->question_id); ?>" value="<?php echo esc_attr($question->answer_id); ?>" class="custom-input">
                                <span class="custom-radio"></span>
                            <?php } elseif ($question->question_type_id == 2) { ?>
                                <input type="checkbox" name="question_<?php echo esc_attr($question->question_id); ?>[]" value="<?php echo esc_attr($question->answer_id); ?>" class="custom-input">
                                <span class="custom-checkbox"></span>
                            <?php } ?>
                            <?php echo esc_html($question->answer); ?>
                        </label>
                        <span class="icon"></span>
                    </div>
                    <?php
                }
                echo '</div>'; // Close last question div
                ?>
            </div>
            <button type="submit" class="quiz-submit">Submit</button>
        </form>
        
    </div>

    <style>
        .quiz-container {
			margin-top: 75px;
            padding: 20px;
            border: none;
        }
        .quiz-question {
            margin-bottom: 20px;
        }
        .quiz-submit {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
        }
        .quiz-submit:hover {
            background-color: #45a049;
        }
        #quiz-results {
            margin-top: 20px;
            font-weight: bold;
        }
		
		
	   .custom-input {
            display: none;
        }
        .custom-radio,
        .custom-checkbox {
			width: 21px;
            height: 21px;
            margin-right: 10px;
            border: 2px solid #fff;
            border-radius: 50%;
            display: inline-block;
            position: relative;
        }
        .custom-checkbox {
            border-radius: 4px;
        }
        .custom-input:checked + .custom-radio::after,
        .custom-input:checked + .custom-checkbox::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 13px;
            height: 13px;
            background-color: #4CAF50;
            border-radius: 50%;
        }
        .custom-input:checked + .custom-checkbox::after {
            width: 13px;
            height: 13px;
            border-radius: 2px;
        }
		
		/* custom-fancybox.css */
		
		.fancybox-slide--html .fancybox-close-small {
			position: fixed !important;
			top: 50px !important;
			right: 40px !important;
		}
		
		.fancybox-bg {
			background-color: #000 !important; /* Change to your desired background color */
		}

		.fancybox-slide--current .fancybox-content {

			background-color: #000 !important; /* Change to your desired background color */
			padding: 50px;
			border-radius: 10px;
		}

		.fancybox-caption {
			background-color: #f0f0f0 !important; /* Change to your desired background color */
			color: #333; /* Change to your desired text color */
		}
		
		.fancybox-container {
			z-index: 1000000;

		}
		
		#quiz-results {
			font-size: 1.3em;
			font-weight: 400;
			color: white;

		}

    </style>
    <script>
		
        jQuery(document).ready(function($) {
            $('[data-fancybox]').fancybox({
                touch: false,
                animationEffect: "fade", // Animation effect
                animationDuration: 300, // Animation duration
                transitionEffect: "slide", // Transition effect
                buttons: [ // Custom buttons
                    "zoom",
                    "close"
                ],
                beforeShow: function(instance, current) {
                    console.log("Before show event"); // Custom event
                },
                afterShow: function(instance, current) {
                    console.log("After show event"); // Custom event
                }
            });
			
			$('.open-quiz').on('click', function() {
							var contentId = $(this).data('content-id');
							$.ajax({
								url: '<?php echo admin_url('admin-ajax.php'); ?>',
								type: 'POST',
								data: {
									action: 'log_quiz_open',
									content_id: contentId
								},
								success: function(response) {
									console.log('Log saved:', response);
								}
							});
			});			

            $('#random-quiz-form').on('submit', function(event) {
                event.preventDefault();
                var form = event.target;
                var formData = new FormData(form);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var response = JSON.parse(xhr.responseText);
                        response.results.forEach(function(result) {
                            var questionElement = $('#question_' + result.question_id + ' h4');
                            if (result.correct) {
                                questionElement.css('color', 'green');
                            } else {
                                questionElement.css('color', 'red');
                                result.correct_answers.forEach(function(correct_answer_id) {
                                    $('#answer_' + correct_answer_id + ' .custom-label').css('color', 'green');
                                });
                            }
                        });
						var added_content = '';
						if (response.score == response.total) {
							added_content = "Excellent.  There are many other questions related to this course content but you're well on your way!"
						} else {
							added_content = "You could very well benefit from taking this course to master and level up your knowledge on this course topic."
						}
                        $('#quiz-results').html('<span style="border: 1px solid #fff; border-radius: 10px; padding: 10px; display: inline-block;">You scored ' + response.score + ' out of ' + response.total + '. ' + added_content + '</span>');
						$('html, body').animate({ scrollTop: 0 }, 'fast');
                    } else {
                        alert('An error occurred!');
                    }
                };
                formData.append('action', 'grade_quiz');
                xhr.send(formData);
            });
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('random_quiz', 'generate_random_quiz');

function grade_quiz() {
    global $lmsdb; // Use $lmsdb instead of $wpdb

    // Filter out non-numeric values
    $question_ids = array_filter(array_map(function($key) {
        if (preg_match('/^question_(\d+)$/', $key, $matches)) {
            return (int) $matches[1];
        }
        return false;
    }, array_keys($_POST)));

    if (empty($question_ids)) {
        echo json_encode(['score' => 0, 'total' => 0, 'results' => []]);
        wp_die();
    }

    $questions = $lmsdb->get_results("
        SELECT q.id, q.question, (SELECT GROUP_CONCAT(ct.id ORDER BY display_order) FROM content_test_answers ct WHERE ct.content_test_question_id = q.id AND ct.correct_answer = 1) AS correct_answer_ids 
        FROM content_test_questions q
        WHERE q.id IN (" . implode(',', $question_ids) . ")
    ");

    $score = 0;
    $total = count($questions);
    $results = [];

    foreach ($questions as $question) {
        $user_answers = isset($_POST['question_' . $question->id]) ? (array) $_POST['question_' . $question->id] : [];
        $correct_answers = explode(',', $question->correct_answer_ids);
        sort($user_answers);
        sort($correct_answers);

        $correct = $user_answers == $correct_answers;
        if ($correct) {
            $score++;
        }

        $results[] = [
            'question_id' => $question->id,
            'question' => $question->question,
            'user_answers' => $user_answers,
            'correct_answers' => $correct_answers,
            'correct' => $correct
        ];
    }

    echo json_encode(['score' => $score, 'total' => $total, 'results' => $results]);
    wp_die();
}
add_action('wp_ajax_grade_quiz', 'grade_quiz');
add_action('wp_ajax_nopriv_grade_quiz', 'grade_quiz');



// Function to log quiz open
function log_quiz_open() {
    global $lmsdb; // Use $lmsdb instead of $wpdb
    
    // Sanitize the received content_id
    $content_id = isset($_POST['content_id']) ? intval($_POST['content_id']) : 0;

    if ($content_id) {
        // Insert a log record into the database
        $lmsdb->insert(
            'marketing_random_quiz_clicks', // Make sure this is your table name
            array(
                'content_id' => $content_id,
                'view_date' => current_time('mysql') // Current timestamp
            ),
            array(
                '%d', // content_id is an integer
                '%s'  // timestamp is a string
            )
        );

        if ($lmsdb->last_error) {
            // Return error if there was a problem
            wp_send_json_error(array('message' => 'Failed to log quiz open', 'error' => $lmsdb->last_error));
        } else {
            // Return success if log was saved successfully
            wp_send_json_success(array('message' => 'Quiz open logged successfully'));
        }
    } else {
        wp_send_json_error(array('message' => 'Invalid content ID'));
    }
}
add_action('wp_ajax_log_quiz_open', 'log_quiz_open');
add_action('wp_ajax_nopriv_log_quiz_open', 'log_quiz_open');


/* related blogs to product */ 

function get_rank_math_keywords($product_id) {
    global $wpdb;
    $keywords = $wpdb->get_var($wpdb->prepare("
        SELECT meta_value 
        FROM {$wpdb->postmeta} 
        WHERE post_id = %d 
          AND meta_key = 'rank_math_focus_keyword'
    ", $product_id));
    return $keywords ? explode(',', $keywords) : [];
}

function search_related_blog_posts($keywords) {
    if (empty($keywords)) {
        return [];
    }

    global $wpdb;
    $keyword_pattern = '%' . implode('%', $keywords) . '%';

    $results = $wpdb->get_results($wpdb->prepare("
        SELECT p.post_title, p.post_name, t.name AS category_name
        FROM {$wpdb->posts} p
        JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
        WHERE (p.post_title LIKE %s OR p.post_content LIKE %s)
          AND p.post_status = 'publish'
          AND p.post_type = 'post'
          AND tt.taxonomy = 'category'
          AND (t.name = 'blogs' OR t.name = 'tech-definitions')
        LIMIT 10
    ", $keyword_pattern, $keyword_pattern), ARRAY_A);

    foreach ($results as &$result) {
        $category_slug = sanitize_title($result['category_name']);
        $result['permalink'] = get_site_url() . '/' . $category_slug . '/' . $result['post_name'] . '/';
    }

    return $results;
}

function related_blog_posts_shortcode($atts) {
    $atts = shortcode_atts(['product_id' => 0], $atts, 'related_blog_posts');
    $product_id = intval($atts['product_id']);

    if (!$product_id) {
        return '';
    }

 //   $transient_key = 'related_blog_posts_' . $product_id;
 //   $related_posts = get_transient($transient_key);

 //   if ($related_posts === false) {
        $keywords = get_rank_math_keywords($product_id);
        $related_posts = search_related_blog_posts($keywords);
  //      set_transient($transient_key, $related_posts, 30 * DAY_IN_SECONDS);
  //  }

    if (empty($related_posts)) {
        return '';
    }

    $output = '<h2>Blogs of Interest Related to This Course</h2>';
    $output .= '<ul>';

    foreach ($related_posts as $post) {
        $output .= sprintf(
            '<li><a target="_blank" href="%s">%s</a></li>',
            esc_url($post['permalink']),
            esc_html($post['post_title'])
        );
    }

    $output .= '</ul>';

    return $output;
}
add_shortcode('related_blog_posts', 'related_blog_posts_shortcode');


add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/submit-form', array(
        'methods' => 'POST',
        'callback' => 'handle_form_submission',
        'permission_callback' => '__return_true'
    ));
});

function handle_form_submission(WP_REST_Request $request) {
    $form_data = $request->get_params();

    // Ensure the form data includes the necessary fields
    if (empty($form_data)) {
        return new WP_Error('missing_params', 'Form data is required', array('status' => 400));
    }

    return new WP_REST_Response('Form submitted successfully', 200);
}

function custom_blog_content_endpoint() {
    register_rest_route('custom/v1', '/blog/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'get_blog_content',
    ));
}
add_action('rest_api_init', 'custom_blog_content_endpoint');

function get_blog_content($data) {
    $post_id = $data['id'];
    $post = get_post($post_id);
    if ($post) {
        return [
            'title' => $post->post_title,
            'content' => apply_filters('the_content', $post->post_content),
            'author' => get_the_author_meta('display_name', $post->post_author),
            'date' => get_the_date('Y-m-d', $post),
			'permalink' => get_permalink($post_id)
        ];
    } else {
        return new WP_Error('no_post', 'Invalid post', array('status' => 404));
    }
}

function generate_embed_code_button($atts) {
    // Extract the attributes passed to the shortcode
    $atts = shortcode_atts(array(
        'post_id' => ''
    ), $atts, 'embed_code_button');

    // Generate the embed code button, modal, and script
    $embed_code = '
    <!-- Button to generate the embed code -->
    <button id="generate-embed-code">Like This Blog?  Embed it on your website!</button>

    <!-- Modal structure -->
    <div id="embedModal" class="modal">
      <div class="modal-content">
        <span class="close">&times;</span>
        <h2>Embed Code</h2>
	    <p>Click the Copy to Clipboard button and paste into your web page to automatically add this blog content to your website</p>
        <pre id="embed-code"></pre>
        <button id="copy-embed-code">Copy to Clipboard</button>
        <p style="padding-top: 10px;">Content Copyright(c) 2024, ITU Online, LLC.  Permission is granted to embed but not copy content in this blog.  ITU Online, LLC reserves the right to modify or remove this content at any time.</p>
      </div>
    </div>

    <style>
      .modal {
        display: none;
        position: fixed;
        z-index: 100000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgb(0,0,0);
        background-color: rgba(0,0,0,0.4);
        padding-top: 60px;
      }
      .modal-content {
        background-color: #000;
        margin: 5% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 50%;
      }
      .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
      }
      .close:hover,
      .close:focus {
        color: black;
        text-decoration: none;
        cursor: pointer;
      }
    </style>

    <script>
      document.getElementById("generate-embed-code").addEventListener("click", function() {
        var postId = "' . $atts['post_id'] . '"; // Use the provided post ID
		var embedCode = \'<div id="blog-container"></div>\n\' + 
                        \'<script>\' + 
                        \'(function() {\\n\' +
                        \'var container = document.getElementById("blog-container");\\n\' +
                        \'var postId = "\' + postId + \'";\\n\' +
                        \'var script = document.createElement("script");\\n\' +
                        \'script.src = "https://www.ituonline.com/blogs/embed-blog.js";\\n\' +
                        \'script.async = true;\\n\' +
                        \'script.onload = function() {\\n\' +
                        \'fetchBlogContent(container, postId);\\n\' +
                        \'};\\n\' +
                        \'document.head.appendChild(script);\\n\' +
                        \'}());</\' + \'script>\';
        document.getElementById("embed-code").textContent = embedCode;

        // Show the modal
        var modal = document.getElementById("embedModal");
        modal.style.display = "block";

        // Get the <span> element that closes the modal
        var span = document.getElementsByClassName("close")[0];
        span.onclick = function() {
          modal.style.display = "none";
        }
        window.onclick = function(event) {
          if (event.target == modal) {
            modal.style.display = "none";
          }
        }
      });

      document.getElementById("copy-embed-code").addEventListener("click", function() {
        var copyText = document.getElementById("embed-code").textContent;
        navigator.clipboard.writeText(copyText).then(function() {
          alert("Embed code copied to clipboard");
        }, function() {
          alert("Failed to copy embed code");
        });
      });
    </script>';

    return $embed_code;
}

// Register the shortcode
add_shortcode('embed_code_button', 'generate_embed_code_button');


// Add "Last Edited" column to the posts list
function add_last_edited_column($columns) {
    $columns['last_edited'] = 'Last Edited';
    return $columns;
}
add_filter('manage_posts_columns', 'add_last_edited_column');

// Populate the "Last Edited" column with the post's last modified date
function show_last_edited_column_content($column_name, $post_id) {
    if ($column_name === 'last_edited') {
        $last_modified = get_post_modified_time('Y-m-d H:i:s', false, $post_id);
        echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_modified));
    }
}
add_action('manage_posts_custom_column', 'show_last_edited_column_content', 10, 2);

// Make the "Last Edited" column sortable
function make_last_edited_column_sortable($columns) {
    $columns['last_edited'] = 'last_edited';
    return $columns;
}
add_filter('manage_edit-post_sortable_columns', 'make_last_edited_column_sortable');

// Modify the query to sort posts by last modified date when sorting by "Last Edited"
function last_edited_orderby($query) {
    if (!is_admin()) {
        return;
    }

    $orderby = $query->get('orderby');
    if ('last_edited' === $orderby) {
        $query->set('orderby', 'modified');
    }
}
add_action('pre_get_posts', 'last_edited_orderby');



add_action( 'woocommerce_checkout_process', 'block_checkout_echo' );
function block_checkout_echo() {

    if ( empty($_POST['billing_first_name']) ) {
        return;
    }

    if ( strtolower($_POST['billing_first_name']) === 'dsad' ) {

        // Output *only* your message
echo '<h1 style="text-align:center;margin-top:50px;">El pedido no puede ser procesado.</h1>';
echo '<p style="text-align:center;">Su información está siendo reportada como transacciones fraudulentas a la PROFECO (Procuraduría Federal del Consumidor).</p>';
exit;

    }
}

/**
 * ITU Cache Management — admin page to flush carousel/grid transients.
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'ITU Cache',
        'ITU Cache',
        'manage_options',
        'itu-cache',
        'itu_cache_admin_page'
    );
});

function itu_cache_admin_page() {
    $flushed = false;
    $count = 0;

    if (isset($_POST['itu_flush_cache']) && check_admin_referer('itu_flush_cache_nonce')) {
        global $wpdb;

        // Delete carousel transients
        $carousel = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itu_carousel_%' OR option_name LIKE '_transient_timeout_itu_carousel_%'"
        );

        // Delete category grid transients
        $catgrid = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itu_catgrid_%' OR option_name LIKE '_transient_timeout_itu_catgrid_%'"
        );

        // Delete LMS stat transients (video_hours, video_count, question_count, etc.)
        $lms = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_video_hours_%' OR option_name LIKE '_transient_timeout_video_hours_%'
            OR option_name LIKE '_transient_video_count_%' OR option_name LIKE '_transient_timeout_video_count_%'
            OR option_name LIKE '_transient_question_count_%' OR option_name LIKE '_transient_timeout_question_count_%'
            OR option_name LIKE '_transient_skill_count_%' OR option_name LIKE '_transient_timeout_skill_count_%'"
        );

        // Delete plan drawer category transients
        $drawer = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itu_drawer_cat_%' OR option_name LIKE '_transient_timeout_itu_drawer_cat_%'"
        );

        // Delete plan pricing transients (individual training plans)
        $pricing = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itu_plan_price_%' OR option_name LIKE '_transient_timeout_itu_plan_price_%'"
        );

        // Delete course outline transients
        $outlines = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_course_videos_%' OR option_name LIKE '_transient_timeout_course_videos_%'
            OR option_name LIKE '_transient_product_outlines_%' OR option_name LIKE '_transient_timeout_product_outlines_%'"
        );

        // Delete spotlight and catalog transients
        $spotlight = 0;
        if (delete_transient('itu_spotlight_html')) $spotlight = 1;
        delete_transient('itu_catalog_full');

        $count = $carousel + $catgrid + $lms + $drawer + $pricing + $outlines + $spotlight;
        $flushed = true;
    }

    ?>
    <div class="wrap">
        <h1>ITU Cache Management</h1>
        <p>Course carousels and category grids are cached for 30 days to improve performance. Use this tool to clear the cache after adding or updating courses.</p>

        <?php if ($flushed) : ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Cache cleared.</strong> <?php echo intval($count); ?> transient entries removed. Pages will rebuild their cache on next visit.</p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('itu_flush_cache_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>What gets cleared</th>
                    <td>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li>Carousel HTML for each category (it-courses page)</li>
                            <li>Category grid HTML (product category archive pages)</li>
                            <li>LMS course stats (video hours, video count, question count, skill count)</li>
                            <li>Certification Spotlight HTML (home page)</li>
                            <li>Plan drawer category products (training plans page)</li>
                            <li>Plan pricing data (individual training plans)</li>
                            <li>Enrollment counts (course cards)</li>
                            <li>Course outlines and curriculum (product pages)</li>
                        </ul>
                    </td>
                </tr>
            </table>
            <?php submit_button('Clear All ITU Caches', 'primary', 'itu_flush_cache'); ?>
        </form>
    </div>
    <?php
}

/**
 * ITU Image Migration — import product featured images from VTS site by SKU.
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'ITU Image Migration',
        'ITU Image Migration',
        'manage_options',
        'itu-image-migration',
        'itu_image_migration_page'
    );
});

function itu_image_migration_page() {
    if (!current_user_can('manage_options')) return;

    $results = [];
    $running = false;

    // Connect to VTS database
    $csv_data = [];
    $db_error = '';

    // Load from CSV file (upload vision_images.csv to theme assets folder)
    $csv_path = get_stylesheet_directory() . '/assets/vision_images.csv';
    if (file_exists($csv_path) && filesize($csv_path) > 100) {
        if (($handle = fopen($csv_path, 'r')) !== false) {
            $header = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) >= 5 && !empty(trim($row[1]))) {
                    $csv_data[] = [
                        'sku'       => trim($row[1]),
                        'image_url' => trim($row[3]),
                        'file_path' => trim($row[4]),
                    ];
                }
            }
            fclose($handle);
        }
    }

    if (empty($csv_data)) {
        $db_error = 'No data found. Upload vision_images.csv to: ' . $csv_path;
    }

    // Handle migration
    if (isset($_POST['itu_run_migration']) && check_admin_referer('itu_image_migration_nonce')) {
        $running = true;
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $batch = isset($_POST['batch_start']) ? intval($_POST['batch_start']) : 0;
        $batch_size = 10;
        $batch_items = array_slice($csv_data, $batch, $batch_size);

        foreach ($batch_items as $item) {
            $sku = $item['sku'];
            $image_url = $item['image_url'];

            // Fix dev URLs to www
            $image_url = str_replace('dev.visiontrainingsystems.com', 'www.visiontrainingsystems.com', $image_url);

            // Find product by SKU
            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                $results[] = ['sku' => $sku, 'status' => 'SKIP', 'msg' => 'No product found with this SKU'];
                continue;
            }

            // Backup current thumbnail
            $current_thumb = get_post_meta($product_id, '_thumbnail_id', true);
            if ($current_thumb) {
                update_post_meta($product_id, '_thumbnail_id_backup', $current_thumb);
            }

            // Download and attach new image
            $tmp = download_url($image_url, 30);
            if (is_wp_error($tmp)) {
                $results[] = ['sku' => $sku, 'status' => 'ERROR', 'msg' => 'Download failed: ' . $tmp->get_error_message()];
                continue;
            }

            $filename = basename(parse_url($image_url, PHP_URL_PATH));
            $file_array = [
                'name'     => $filename,
                'tmp_name' => $tmp,
            ];

            $attach_id = media_handle_sideload($file_array, $product_id);
            if (is_wp_error($attach_id)) {
                @unlink($tmp);
                $results[] = ['sku' => $sku, 'status' => 'ERROR', 'msg' => 'Sideload failed: ' . $attach_id->get_error_message()];
                continue;
            }

            // Set as featured image
            set_post_thumbnail($product_id, $attach_id);
            $results[] = ['sku' => $sku, 'status' => 'OK', 'msg' => 'Image updated (Product #' . $product_id . ', Attachment #' . $attach_id . ')'];
        }
    }

    // Handle restore
    if (isset($_POST['itu_restore_images']) && check_admin_referer('itu_image_migration_nonce')) {
        $running = true;
        $restored = 0;
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'meta_key'       => '_thumbnail_id_backup',
            'meta_compare'   => 'EXISTS',
        ];
        $products = get_posts($args);
        foreach ($products as $p) {
            $backup = get_post_meta($p->ID, '_thumbnail_id_backup', true);
            if ($backup) {
                set_post_thumbnail($p->ID, $backup);
                delete_post_meta($p->ID, '_thumbnail_id_backup');
                $restored++;
            }
        }
        $results[] = ['sku' => '—', 'status' => 'RESTORED', 'msg' => $restored . ' products restored to original images'];
    }

    ?>
    <div class="wrap">
        <h1>ITU Image Migration</h1>
        <p>Import product featured images from VisionTrainingSystems.com, matched by SKU. Old images are backed up and can be restored.</p>

        <?php if (!empty($results)) : ?>
            <h3>Results</h3>
            <table class="widefat striped" style="max-width:800px">
                <thead><tr><th>SKU</th><th>Status</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($results as $r) : ?>
                    <tr>
                        <td><code><?php echo esc_html($r['sku']); ?></code></td>
                        <td><strong style="color:<?php echo $r['status'] === 'OK' ? '#1f7a3f' : ($r['status'] === 'ERROR' ? '#7a1f1f' : '#7a6a1f'); ?>"><?php echo esc_html($r['status']); ?></strong></td>
                        <td><?php echo esc_html($r['msg']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($running && isset($batch) && ($batch + $batch_size) < count($csv_data)) : ?>
                <form method="post" style="margin-top:16px">
                    <?php wp_nonce_field('itu_image_migration_nonce'); ?>
                    <input type="hidden" name="batch_start" value="<?php echo $batch + $batch_size; ?>">
                    <?php submit_button('Continue Next Batch (' . ($batch + $batch_size) . '–' . min($batch + $batch_size * 2, count($csv_data)) . ' of ' . count($csv_data) . ')', 'primary', 'itu_run_migration'); ?>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <h3>VTS Database</h3>
        <?php if (!empty($db_error)) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($db_error); ?></p></div>
        <?php endif; ?>
        <?php if (!empty($vtsdb->last_error)) : ?>
            <div class="notice notice-error"><p>DB error: <?php echo esc_html($vtsdb->last_error); ?></p></div>
        <?php endif; ?>
        <p><strong><?php echo count($csv_data); ?></strong> products with images found in VTS database.</p>

        <?php if (!empty($csv_data)) : ?>
            <details style="margin-bottom:16px">
                <summary style="cursor:pointer;font-weight:600">Preview data (first 20 rows)</summary>
                <table class="widefat striped" style="max-width:800px;margin-top:8px">
                    <thead><tr><th>SKU</th><th>Image URL</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($csv_data, 0, 20) as $item) : ?>
                        <tr>
                            <td><code><?php echo esc_html($item['sku']); ?></code></td>
                            <td style="font-size:11px;word-break:break-all"><?php echo esc_html($item['image_url']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('itu_image_migration_nonce'); ?>
            <input type="hidden" name="batch_start" value="0">
            <table class="form-table">
                <tr>
                    <th>Actions</th>
                    <td>
                        <?php submit_button('Start Migration (batch of 10)', 'primary', 'itu_run_migration', false); ?>
                        &nbsp;&nbsp;
                        <?php submit_button('Restore All Original Images', 'secondary', 'itu_restore_images', false, ['onclick' => 'return confirm("Restore ALL product images to their pre-migration originals?")']); ?>
                    </td>
                </tr>
                <tr>
                    <th>What happens</th>
                    <td>
                        <ul style="list-style:disc;margin-left:20px">
                            <li>For each SKU in the CSV, finds the matching product on this site</li>
                            <li>Backs up the current featured image ID to <code>_thumbnail_id_backup</code></li>
                            <li>Downloads the image from VTS and uploads to this site's media library</li>
                            <li>Sets the downloaded image as the new featured image</li>
                            <li>Processes 10 products per batch to avoid timeouts</li>
                        </ul>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <?php
}

/**
 * ITU Publish Date Sync — syncs WooCommerce product publish dates from LMS course creation dates.
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'ITU Date Sync',
        'ITU Date Sync',
        'manage_options',
        'itu-date-sync',
        'itu_date_sync_admin_page'
    );
});

function itu_date_sync_admin_page() {
    global $lmsdb, $wpdb;

    $synced = false;
    $sync_count = 0;

    // Execute sync
    if (isset($_POST['itu_sync_dates']) && check_admin_referer('itu_date_sync_nonce')) {
        $skus_to_sync = $_POST['sync_sku'] ?? [];
        $dates_to_sync = $_POST['sync_date'] ?? [];

        foreach ($skus_to_sync as $i => $sku) {
            $lms_date = sanitize_text_field($dates_to_sync[$i] ?? '');
            if (empty($sku) || empty($lms_date)) continue;

            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) continue;

            $gmt_date = get_gmt_from_date($lms_date);
            $wpdb->update(
                $wpdb->posts,
                [
                    'post_date'     => $lms_date,
                    'post_date_gmt' => $gmt_date,
                ],
                ['ID' => $product_id],
                ['%s', '%s'],
                ['%d']
            );
            clean_post_cache($product_id);
            $sync_count++;
        }

        // Clear carousel caches since ordering changed
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itu_carousel_%' OR option_name LIKE '_transient_timeout_itu_carousel_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itu_catgrid_%' OR option_name LIKE '_transient_timeout_itu_catgrid_%'");

        $synced = true;
    }

    // Fetch LMS data
    $lms_data = [];
    if ($lmsdb) {
        $results = $lmsdb->get_results("
            SELECT ecs.reference AS sku, c.created, c.active
            FROM enrollment_code_sets ecs
            JOIN enrollments e ON e.id = ecs.enrollment_id
            JOIN enrollment_content ec ON ec.enrollment_id = e.id
            JOIN content c ON c.id = ec.content_id
            GROUP BY ecs.id, ecs.reference
            HAVING COUNT(ec.content_id) = 1
        ");
        if ($results) {
            foreach ($results as $row) {
                $lms_data[$row->sku] = [
                    'created' => $row->created,
                    'active'  => $row->active,
                ];
            }
        }
    }

    // Build comparison table
    $rows = [];
    $mismatch_count = 0;
    foreach ($lms_data as $sku => $lms) {
        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) continue;

        $product = wc_get_product($product_id);
        if (!$product) continue;

        $wp_date = get_the_date('Y-m-d H:i:s', $product_id);
        $lms_date = $lms['created'];
        $dates_match = (substr($wp_date, 0, 10) === substr($lms_date, 0, 10));

        $rows[] = [
            'sku'        => $sku,
            'name'       => $product->get_name(),
            'product_id' => $product_id,
            'wp_date'    => $wp_date,
            'lms_date'   => $lms_date,
            'active'     => $lms['active'],
            'match'      => $dates_match,
        ];

        if (!$dates_match) $mismatch_count++;
    }

    // Sort mismatches first, then alphabetical
    usort($rows, function ($a, $b) {
        if ($a['match'] === $b['match']) return strcmp($a['name'], $b['name']);
        return $a['match'] ? 1 : -1;
    });

    ?>
    <div class="wrap">
        <h1>ITU Publish Date Sync</h1>
        <p>Compare WooCommerce publish dates with LMS course creation dates. Sync mismatched dates so carousels order courses correctly.</p>

        <?php if ($synced) : ?>
            <div class="notice notice-success is-dismissible">
                <p><strong><?php echo intval($sync_count); ?> product(s) updated.</strong> Carousel caches cleared automatically.</p>
            </div>
        <?php endif; ?>

        <?php if (!$lmsdb) : ?>
            <div class="notice notice-error"><p>LMS database connection not available.</p></div>
        <?php elseif (empty($rows)) : ?>
            <div class="notice notice-warning"><p>No matching SKUs found between LMS and WooCommerce.</p></div>
        <?php else : ?>

            <p>
                <strong><?php echo count($rows); ?></strong> matched products.
                <?php if ($mismatch_count > 0) : ?>
                    <span style="color:#d63638; font-weight:600;"><?php echo intval($mismatch_count); ?> date mismatch<?php echo $mismatch_count !== 1 ? 'es' : ''; ?>.</span>
                <?php else : ?>
                    <span style="color:#00a32a; font-weight:600;">All dates in sync.</span>
                <?php endif; ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('itu_date_sync_nonce'); ?>

                <table class="widefat striped" style="max-width:1100px;">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="itu-sync-check-all" /></th>
                            <th>SKU</th>
                            <th>Product Name</th>
                            <th>WP Publish Date</th>
                            <th>LMS Created Date</th>
                            <th>LMS Active</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                        <tr style="<?php echo !$row['match'] ? 'background:#fcf0f0;' : ''; ?>">
                            <td>
                                <?php if (!$row['match']) : ?>
                                    <input type="checkbox" name="sync_sku[]" value="<?php echo esc_attr($row['sku']); ?>" class="itu-sync-checkbox" checked />
                                    <input type="hidden" name="sync_date[]" value="<?php echo esc_attr($row['lms_date']); ?>" />
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html($row['sku']); ?></code></td>
                            <td><?php echo esc_html($row['name']); ?></td>
                            <td><?php echo esc_html(substr($row['wp_date'], 0, 10)); ?></td>
                            <td><?php echo esc_html(substr($row['lms_date'], 0, 10)); ?></td>
                            <td><?php echo esc_html($row['active']); ?></td>
                            <td>
                                <?php if ($row['match']) : ?>
                                    <span style="color:#00a32a;">&#10003; In sync</span>
                                <?php else : ?>
                                    <span style="color:#d63638; font-weight:600;">&#9888; Mismatch</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($mismatch_count > 0) : ?>
                    <p style="margin-top:16px;">
                        <?php submit_button('Sync Selected Dates', 'primary', 'itu_sync_dates', false); ?>
                        <span style="margin-left:12px; color:#666;">Updates WP publish dates to match LMS creation dates for checked items.</span>
                    </p>
                <?php endif; ?>
            </form>

            <script>
            document.getElementById('itu-sync-check-all').addEventListener('change', function() {
                var checked = this.checked;
                document.querySelectorAll('.itu-sync-checkbox').forEach(function(cb) { cb.checked = checked; });
            });
            </script>

        <?php endif; ?>
    </div>
    <?php
}
