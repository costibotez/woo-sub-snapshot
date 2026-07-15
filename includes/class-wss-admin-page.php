<?php
/**
 * Admin menu, settings form, and report table rendering.
 */

if (!defined('ABSPATH')) exit;

class WSS_Admin_Page {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_page'));
        add_action('admin_post_woo_sub_snapshot_save_settings', array($this, 'save_settings'));
    }

    public function add_admin_page() {
        add_menu_page(
            __('Subscription Reports', 'woo-sub-snapshot'),
            __('Subscription Reports', 'woo-sub-snapshot'),
            'manage_woocommerce',
            'woo-sub-snapshot',
            array($this, 'render_admin_page'),
            'dashicons-chart-line'
        );
    }

    public function render_admin_page() {
        $start_filter = WSS_Report_Data::sanitize_date(
            isset($_GET['start_date']) ? sanitize_text_field(wp_unslash($_GET['start_date'])) : '',
            WSS_Report_Data::default_start_date()
        );
        $end_filter = WSS_Report_Data::sanitize_date(
            isset($_GET['end_date']) ? sanitize_text_field(wp_unslash($_GET['end_date'])) : '',
            WSS_Report_Data::default_end_date()
        );

        $email    = get_option('woo_sub_snapshot_email', '');
        $plan_id  = WSS_Report_Data::get_plan_id();
        $exclude  = WSS_Report_Data::exclude_subscribers();

        echo '<div class="wrap"><h1>' . esc_html__('Monthly Active Subscriptions', 'woo-sub-snapshot') . '</h1>';

        $this->render_notice();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="woo_sub_snapshot_save_settings">';
        wp_nonce_field('woo_sub_snapshot_save_settings');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="report_email">' . esc_html__('Report Recipient Email', 'woo-sub-snapshot') . '</label></th>';
        echo '<td><input type="email" id="report_email" name="report_email" value="' . esc_attr($email) . '" class="regular-text" required /></td></tr>';
        echo '<tr><th scope="row"><label for="ct_plan_id">' . esc_html__('CT Club Membership Plan ID', 'woo-sub-snapshot') . '</label></th>';
        echo '<td><input type="number" id="ct_plan_id" name="ct_plan_id" value="' . esc_attr($plan_id) . '" min="0" class="small-text" />';
        echo '<p class="description">' . esc_html__('Post ID of the Membership Plan to count. Set to 0 to disable the CT Club column.', 'woo-sub-snapshot') . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('CT Club Counting', 'woo-sub-snapshot') . '</th>';
        echo '<td><label><input type="checkbox" name="ct_exclude_subscribers" value="yes"' . checked($exclude, true, false) . ' /> ';
        echo esc_html__('Exclude members who also hold a live subscription', 'woo-sub-snapshot') . '</label></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save Settings', 'woo-sub-snapshot'));
        echo '</form><hr>';

        echo '<form method="get"><input type="hidden" name="page" value="woo-sub-snapshot" />';
        echo '<label for="start_date">' . esc_html__('Start Date:', 'woo-sub-snapshot') . ' </label><input type="date" id="start_date" name="start_date" value="' . esc_attr($start_filter) . '" /> ';
        echo '<label for="end_date">' . esc_html__('End Date:', 'woo-sub-snapshot') . ' </label><input type="date" id="end_date" name="end_date" value="' . esc_attr($end_filter) . '" />';
        submit_button(__('Filter', 'woo-sub-snapshot'));
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="woo_sub_snapshot_export">';
        wp_nonce_field('woo_sub_snapshot_export');
        echo '<input type="hidden" name="start_date" value="' . esc_attr($start_filter) . '">';
        echo '<input type="hidden" name="end_date" value="' . esc_attr($end_filter) . '">';
        submit_button(__('Export CSV', 'woo-sub-snapshot'));
        echo '</form>';

        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Month', 'woo-sub-snapshot') . '</th>';
        echo '<th>' . esc_html__('Active Subscriptions', 'woo-sub-snapshot') . '</th>';
        echo '<th>' . esc_html__('Pending Cancel', 'woo-sub-snapshot') . '</th>';
        echo '<th>' . esc_html__('Active CT Club Members', 'woo-sub-snapshot') . '</th>';
        echo '</tr></thead><tbody>';

        foreach (WSS_Report_Data::get_report_rows($start_filter, $end_filter) as $row) {
            echo '<tr><td>' . esc_html($row['month']) . '</td><td>' . esc_html($row['active']) . '</td><td>' . esc_html($row['pending_cancel']) . '</td><td>' . esc_html($row['ct_club']) . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Note: "Pending Cancel" reflects the current subscription status, so historical months show subscriptions that are pending cancellation today.', 'woo-sub-snapshot') . '</p>';
        echo '</div>';
    }

    public function save_settings() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'woo-sub-snapshot'));
        }
        check_admin_referer('woo_sub_snapshot_save_settings');

        $notice = 'saved';

        if (isset($_POST['report_email'])) {
            $email = sanitize_email(wp_unslash($_POST['report_email']));
            if (is_email($email)) {
                update_option('woo_sub_snapshot_email', $email);
            } else {
                $notice = 'invalid_email';
            }
        }

        $plan_id = isset($_POST['ct_plan_id']) ? absint($_POST['ct_plan_id']) : 0;
        update_option('woo_sub_snapshot_ct_plan_id', $plan_id);
        update_option('woo_sub_snapshot_ct_exclude_subscribers', isset($_POST['ct_exclude_subscribers']) ? 'yes' : 'no');

        wp_safe_redirect(add_query_arg('wss_notice', $notice, admin_url('admin.php?page=woo-sub-snapshot')));
        exit;
    }

    private function render_notice() {
        if (!isset($_GET['wss_notice'])) {
            return;
        }
        $notice = sanitize_key(wp_unslash($_GET['wss_notice']));

        if ('saved' === $notice) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'woo-sub-snapshot') . '</p></div>';
        } elseif ('invalid_email' === $notice) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('The recipient email address is not valid and was not saved. Other settings were saved.', 'woo-sub-snapshot') . '</p></div>';
        }
    }
}
