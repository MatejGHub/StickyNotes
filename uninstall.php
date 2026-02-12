<?php
/**
 * Uninstall Script
 * 
 * Runs when the plugin is deleted via the WordPress admin.
 * This file cleans up all plugin data from the database.
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete database tables
global $wpdb;
$table_name = $wpdb->prefix . 'sticky_notes';
$wpdb->query("DROP TABLE IF EXISTS $table_name");
$links_table = $wpdb->prefix . 'sticky_shared_links';
$wpdb->query("DROP TABLE IF EXISTS $links_table");

// Delete plugin options
delete_option('sticky_comment_max_notes');
delete_option('sticky_comment_settings_min_cap');
delete_option('sticky_comment_settings_admin_user');
delete_option('sticky_comment_palette');
delete_option('sticky_comment_throttle_limit');
delete_option('sticky_comment_db_version');
delete_option('sticky_comments_hidden_columns');

// Clear any cached data
wp_cache_flush(); 