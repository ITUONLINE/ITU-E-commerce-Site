<?php
/**
 * SEO Monitor → SEO AI AutoPilot Migration
 *
 * Automatically switches plugin activation from the old slug to the new one.
 * Safe to leave in place permanently — only runs once when it detects the old
 * plugin is active and the new one exists but isn't active.
 *
 * Remove this file after all sites have been migrated.
 */

if (!defined('ABSPATH')) exit;

add_action('admin_init', function () {
    $old_plugin = 'seo-monitor/seo-monitor.php';
    $new_plugin = 'seo-ai-autopilot/seo-ai-autopilot.php';

    $active = get_option('active_plugins', []);

    // Check if old plugin is active and new one is not
    $old_active = in_array($old_plugin, $active);
    $new_active = in_array($new_plugin, $active);

    if ($old_active && !$new_active) {
        // New plugin file must exist
        if (!file_exists(WP_PLUGIN_DIR . '/' . $new_plugin)) return;

        // Swap: deactivate old, activate new
        $active = array_diff($active, [$old_plugin]);
        $active[] = $new_plugin;
        update_option('active_plugins', array_values($active));

        // Log the migration
        update_option('seom_migration_date', current_time('mysql'));

        // Redirect to avoid stale page
        if (!wp_doing_ajax() && !wp_doing_cron()) {
            wp_redirect(admin_url('plugins.php?seom_migrated=1'));
            exit;
        }
    }

    // Show one-time admin notice after migration
    if (isset($_GET['seom_migrated'])) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p><strong>SEO AI AutoPilot:</strong> Plugin successfully migrated from "SEO Monitor" to "SEO AI AutoPilot." All settings, data, and cron jobs are preserved.</p></div>';
        });
    }
}, 1); // Priority 1 — run before other admin_init hooks
