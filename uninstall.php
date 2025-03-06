<?php
/**
 * Uninstall Script for Event QR Code Registration & Check-In Plugin
 *
 * This file removes all plugin data when uninstalled.
 */

// Exit if uninstall not called from WordPress.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Define table name
$table_name = $wpdb->prefix . 'event_qr_codes';

// Remove table from database
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Delete plugin options (if any)
delete_option('event_qr_settings');
delete_option('event_qr_version');

// Clear any scheduled events related to this plugin
wp_clear_scheduled_hook('event_qr_cleanup');

// Remove any stored transients (optional)
delete_transient('event_qr_cache');
