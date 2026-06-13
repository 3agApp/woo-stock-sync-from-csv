<?php
/**
 * Woo Stock Sync from CSV Uninstall
 *
 * Fires when the plugin is deleted.
 * Cleans up all plugin data from the database.
 *
 * @package Woo_Stock_Sync_CSV
 * @since 1.1.4
 */

// Exit if not called from WordPress uninstaller
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Verify this is a valid uninstall request
if (!current_user_can('activate_plugins')) {
    return;
}

/**
 * Deactivate license from 3AG License API
 * This frees up the activation slot for use on another domain
 */
function wssc_uninstall_deactivate_license() {
    $license_key = get_option('wssc_license_key');
    
    if (empty($license_key)) {
        return;
    }
    
    // Get the domain
    $site_url = site_url();
    $parsed = wp_parse_url($site_url);
    $domain = isset($parsed['host']) ? $parsed['host'] : '';
    $domain = preg_replace('/^www\./', '', $domain);
    $domain = preg_replace('/:\d+$/', '', $domain);
    
    if (empty($domain)) {
        return;
    }
    
    // Make API request to deactivate
    $response = wp_remote_post('https://3ag.app/api/v3/licenses/deactivate', [
        'timeout' => 15,
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        'body' => wp_json_encode([
            'license_key'  => $license_key,
            'product_slug' => 'woo-stock-sync-from-csv',
            'domain'       => $domain,
        ]),
    ]);
    
    // We don't need to check the response - best effort deactivation
    // The license will still be deleted locally regardless
}

/**
 * Clean up all plugin data
 */
function wssc_uninstall_cleanup() {
    global $wpdb;
    
    // First, deactivate the license from the API
    wssc_uninstall_deactivate_license();
    
    // Delete all plugin options
    $options_to_delete = [
        // Core settings
        'wssc_csv_url',
        'wssc_sku_column',
        'wssc_quantity_column',
        'wssc_schedule_interval',
        'wssc_enabled',
        'wssc_disable_ssl',
        'wssc_missing_sku_action',
        // License
        'wssc_license_key',
        'wssc_license_status',
        'wssc_license_data',
        'wssc_license_last_check',
        'wssc_sync_disabled_by_license',
        // Sync tracking
        'wssc_last_sync_time',
        'wssc_last_sync_stats',
        'wssc_last_scheduled',
        'wssc_privatized_products',
        'wssc_watchdog_last_check',
        // Fallback single-run sync lock (used when MySQL GET_LOCK is unavailable)
        'wssc_sync_lock',
        // Database
        'wssc_db_version',
    ];
    
    foreach ($options_to_delete as $option) {
        delete_option($option);
    }
    
    // Delete transients
    $transients_to_delete = [
        'wssc_update_data',
        'wssc_sync_running',
        'wssc_last_manual_sync',
    ];
    
    foreach ($transients_to_delete as $transient) {
        delete_transient($transient);
    }
    
    // Unschedule all cron events
    $cron_hooks = [
        'wssc_sync_event',
        'wssc_watchdog_check',
        'wssc_license_check',
        'wssc_update_check',
    ];
    
    foreach ($cron_hooks as $hook) {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
        // Clear all events for this hook
        wp_clear_scheduled_hook($hook);
    }
    
    // Drop the logs table
    $table_name = $wpdb->prefix . 'wssc_logs';
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    
    // Clean up any remaining meta data (if we ever store product meta)
    // $wpdb->delete($wpdb->postmeta, ['meta_key' => '_wssc_original_visibility']);
    
    // Clear any cached data
    wp_cache_flush();
}

// Run cleanup
wssc_uninstall_cleanup();
