<?php
/**
 * Plugin Name: Woo Subscription Snapshot
 * Plugin URI: https://github.com/costibotez/woo-sub-snapshot
 * Description: Reports on WooCommerce subscriptions (and, optionally, a WooCommerce Memberships plan) with trend charts, churn/MRR metrics, immutable monthly snapshots, CSV export, scheduled email, and a REST/CLI API.
 * Version: 2.1.0
 * Author: Costin Botez
 * Author URI: https://nomad-developer.co.uk
 * Text Domain: woo-sub-snapshot
 * Requires at least: 5.8
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) exit;

define('WSS_VERSION', '2.1.0');
define('WSS_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once WSS_PLUGIN_DIR . 'includes/class-wss-report-data.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-snapshots.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-chart.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-csv-exporter.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-admin-page.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-email-report.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-api.php';

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Registered unconditionally so both the activation hook and cron runs can
// resolve the custom interval.
add_filter('cron_schedules', array('WSS_Email_Report', 'register_cron_interval'));

register_activation_hook(__FILE__, 'wss_activate');
register_deactivation_hook(__FILE__, 'wss_deactivate');

function wss_activate() {
    WSS_Email_Report::schedule_event();
    WSS_Snapshots::install();
}

function wss_deactivate() {
    WSS_Email_Report::clear_event();
    WSS_Snapshots::clear_event();
}

add_action('plugins_loaded', 'wss_init');
function wss_init() {
    if (!class_exists('WC_Subscriptions') && !function_exists('wcs_get_subscriptions')) {
        add_action('admin_notices', 'wss_missing_dependency_notice');
        return;
    }

    load_plugin_textdomain('woo-sub-snapshot', false, dirname(plugin_basename(__FILE__)) . '/languages');

    new WSS_Admin_Page();
    new WSS_CSV_Exporter();
    new WSS_Email_Report();
    new WSS_Snapshots();
    new WSS_Api();

    // Upgrades that don't fire the activation hook still need the table,
    // cron interval, and events in place.
    WSS_Email_Report::schedule_event();
    WSS_Snapshots::install();
}

add_action('cli_init', array('WSS_Api', 'register_cli'));

function wss_missing_dependency_notice() {
    echo '<div class="notice notice-error"><p>' . esc_html__('Woo Subscription Snapshot requires WooCommerce Subscriptions to be installed and active.', 'woo-sub-snapshot') . '</p></div>';
}
