<?php
/**
 * Removes all plugin data on uninstall.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('woo_sub_snapshot_email');
delete_option('woo_sub_snapshot_ct_plan_id');
delete_option('woo_sub_snapshot_ct_exclude_subscribers');

wp_clear_scheduled_hook('woo_sub_snapshot_monthly_email');

global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_wss\\_report\\_%'
     OR option_name LIKE '\\_transient\\_timeout\\_wss\\_report\\_%'"
);
