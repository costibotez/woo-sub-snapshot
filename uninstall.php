<?php
/**
 * Removes all plugin data on uninstall.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('woo_sub_snapshot_email');
delete_option('woo_sub_snapshot_ct_plan_id');
delete_option('woo_sub_snapshot_ct_exclude_subscribers');
delete_option('woo_sub_snapshot_membership_label');
delete_option('woo_sub_snapshot_cadence');
delete_option('woo_sub_snapshot_db_version');

wp_clear_scheduled_hook('woo_sub_snapshot_monthly_email');
wp_clear_scheduled_hook('woo_sub_snapshot_record');

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wss_snapshots");

$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_wss\\_report\\_%'
     OR option_name LIKE '\\_transient\\_timeout\\_wss\\_report\\_%'"
);
