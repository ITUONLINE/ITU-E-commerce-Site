<?php
/**
 * Course Archive ItemList Schema
 *
 * Emits ItemList JSON-LD (with Course items inside) on WooCommerce
 * course archive pages. Two archive contexts are covered:
 *
 *   - is_shop()              The main /shop/ (or whatever
 *                            woocommerce_shop_page_id is set to)
 *   - is_product_category()  /product-category/{slug}/ taxonomy
 *                            archives — CompTIA, Cisco, etc.
 *
 * Position numbering is PAGE-RELATIVE (1-N per page) — each paginated
 * URL is treated as a self-contained list. Page 2's first item has
 * position=1, not 21. Google handles this correctly because each
 * archive URL is a distinct page with its own canonical, and the
 * sitemap surfaces each page separately.
 *
 * The Course payload per item is intentionally minimal: name, url,
 * description, provider. Matches the Schema.org Course minimum and
 * keeps the head size bounded for archives with 20+ products. Richer
 * fields (offers, image, courseMode) can be added later if SERP
 * previews need them.
 */

if (!defined('ABSPATH')) exit;

/**
 * Build the ItemList JSON-LD body from the products in the current
 * WooCommerce query. Returns the array (NOT encoded) or null when
 * there are no products to list.
 */
function seom_course_archive_build_itemlist() {
    if (!function_exists('WC')) return null;

    global $wp_query;
    if (empty($wp_query->posts) || !is_array($wp_query->posts)) return null;

    $org_name = get_bloginfo('name');
    $org_url  = home_url('/');

    $items = [];
    $position = 1;
    foreach ($wp_query->posts as $p) {
        if (!$p || empty($p->ID)) continue;
        if (function_exists('get_post_type') && get_post_type($p) !== 'product') continue;

        $product = function_exists('wc_get_product') ? wc_get_product($p->ID) : null;
        if (!$product) continue;
        if (!$product->is_visible()) continue;

        $name = trim((string) $product->get_name());
        if ($name === '') continue;

        $url  = (string) $product->get_permalink();
        $desc = trim(wp_strip_all_tags((string) $product->get_short_description()));
        // Fall back to a truncated long description when the short one
        // is empty (most ITU products fill the long description).
        if ($desc === '') {
            $desc = trim(wp_strip_all_tags((string) $product->get_description()));
            $desc = wp_trim_words($desc, 35, '');
        }

        $items[] = [
            '@type'    => 'ListItem',
            'position' => $position++,
            'item'     => [
                '@type'       => 'Course',
                'url'         => $url,
                'name'        => $name,
                'description' => $desc,
                'provider'    => [
                    '@type'  => 'Organization',
                    'name'   => $org_name,
                    'sameAs' => $org_url,
                ],
            ],
        ];
    }

    if (empty($items)) return null;

    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'itemListElement' => $items,
    ];
}

/**
 * Emit the schema in <head>. Priority 5 so it lands before most
 * SEO plugin output but after canonical / meta tags.
 *
 * Bails on:
 *   - non-archive contexts
 *   - non-WooCommerce sites
 *   - empty product lists
 *
 * Pagination: the existing $wp_query handles pagination, so this
 * naturally emits the visible product set per paginated URL.
 */
add_action('wp_head', function () {
    if (!function_exists('is_shop') || !function_exists('is_product_category')) return;
    if (!is_shop() && !is_product_category()) return;

    $itemlist = seom_course_archive_build_itemlist();
    if (!$itemlist) return;

    echo "\n<script type=\"application/ld+json\" data-source=\"seom-course-archive\">"
       . wp_json_encode($itemlist, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
       . "</script>\n";
}, 5);
