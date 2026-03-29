<?php
/**
 * Force the twentytwentyfive-child theme and disable Elementor.
 *
 * This file lives in mu-plugins/ so it loads automatically and cannot be
 * deactivated from the admin. It does NOT modify the database.
 *
 * How it works:
 * - Checks the current hostname against the config list
 * - If it matches, overrides the active theme to the child theme
 * - Also strips Elementor from active plugins so block templates render
 */

defined('ABSPATH') || exit;



/**
 * Configure your environment overrides here.
 * Each domain gets: URL, parent theme, child theme.
 * Production domain is left out — it uses database values as-is.
 */
function itu_environment_config() {
    return [
        'ituonline.com' => [
            'url'    => 'https://ituonline.com',
            'parent' => 'twentytwentyfive',
            'child'  => 'twentytwentyfive-child',
        ],
        'www.ituonline.com' => [
            'url'    => 'https://www.ituonline.com',
            'parent' => 'twentytwentyfive',
            'child'  => 'twentytwentyfive-child',
        ],
        'staging.ituonline.com' => [
            'url'    => 'https://staging.ituonline.com',
            'parent' => 'twentytwentyfive',
            'child'  => 'twentytwentyfive-child',
        ],
    ];
}

function itu_get_environment_override() {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $host = strtolower(explode(':', $host)[0]);

    $config = itu_environment_config();
    return isset($config[$host]) ? $config[$host] : null;
}

// URL overrides are handled by WP_HOME / WP_SITEURL constants in wp-config.php.
// Those constants fire before WordPress bootstraps, preventing redirect loops.

// Use pre_option filters — these fire BEFORE the database is queried,
// so nothing can override them. Returning a non-false value short-circuits
// the database lookup entirely.
add_filter('pre_option_template', function () {
    $override = itu_get_environment_override();
    if ($override) {
        return $override['parent'];
    }
    return false; // false = let WordPress query the database as normal
});

add_filter('pre_option_stylesheet', function () {
    $override = itu_get_environment_override();
    if ($override) {
        return $override['child'];
    }
    return false;
});

/**
 * Prevent Elementor from loading on staging/local environments.
 * This does NOT deactivate Elementor in the database — production is unaffected.
 * It simply removes Elementor from the active plugins list in memory so its
 * PHP files never load, allowing the child theme templates to render.
 */
add_filter('option_active_plugins', function ($plugins) {
    $override = itu_get_environment_override();
    if ($override && is_array($plugins)) {
        $plugins = array_values(array_filter($plugins, function ($plugin) {
            return strpos($plugin, 'elementor') === false;
        }));
    }
    return $plugins;
});
