<?php
/**
 * Admin menu, settings form, charts, and report table rendering.
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
        $cadence  = WSS_Email_Report::get_cadence();
        $rows     = WSS_Report_Data::get_report_rows($start_filter, $end_filter);

        echo '<div class="wrap"><h1>' . esc_html__('Subscription Reports', 'woo-sub-snapshot') . '</h1>';

        $this->render_notice();
        $this->render_settings_form($email, $plan_id, $exclude, $cadence);
        $this->render_toolbar($start_filter, $end_filter);
        $this->render_charts($rows);
        $this->render_table($rows);

        echo '</div>';
    }

    private function render_settings_form($email, $plan_id, $exclude, $cadence) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="woo_sub_snapshot_save_settings">';
        wp_nonce_field('woo_sub_snapshot_save_settings');
        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="report_email">' . esc_html__('Report Recipient Email(s)', 'woo-sub-snapshot') . '</label></th>';
        echo '<td><input type="text" id="report_email" name="report_email" value="' . esc_attr($email) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('One or more addresses, separated by commas.', 'woo-sub-snapshot') . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Email Cadence', 'woo-sub-snapshot') . '</th><td>';
        echo '<label style="margin-right:14px;"><input type="radio" name="cadence" value="monthly"' . checked($cadence, 'monthly', false) . '> ' . esc_html__('Monthly', 'woo-sub-snapshot') . '</label>';
        echo '<label><input type="radio" name="cadence" value="weekly"' . checked($cadence, 'weekly', false) . '> ' . esc_html__('Weekly', 'woo-sub-snapshot') . '</label>';
        echo '</td></tr>';

        $this->render_membership_settings($plan_id, $exclude);

        echo '</tbody></table>';
        submit_button(__('Save Settings', 'woo-sub-snapshot'));
        echo '</form>';

        // Secondary actions: test email + snapshot backfill.
        echo '<p>';
        echo '<a href="' . esc_url($this->action_url('woo_sub_snapshot_test_email', 'woo_sub_snapshot_test_email')) . '" class="button">' . esc_html__('Send Test Email Now', 'woo-sub-snapshot') . '</a> ';
        echo '<a href="' . esc_url($this->action_url('woo_sub_snapshot_backfill', 'woo_sub_snapshot_backfill')) . '" class="button">' . esc_html__('Backfill Monthly Snapshots', 'woo-sub-snapshot') . '</a>';
        echo '</p><hr>';
    }

    /**
     * The optional WooCommerce Memberships column. Hidden entirely on sites
     * without Memberships installed, so the plugin works standalone.
     */
    private function render_membership_settings($plan_id, $exclude) {
        echo '<tr><th scope="row">' . esc_html__('Membership Reporting', 'woo-sub-snapshot') . '</th><td>';

        if (!WSS_Report_Data::memberships_available()) {
            echo '<p class="description">' . esc_html__('WooCommerce Memberships is not active, so the membership column is disabled. Install and activate it to report on a membership plan.', 'woo-sub-snapshot') . '</p>';
            echo '</td></tr>';
            return;
        }

        $plans = WSS_Report_Data::get_membership_plans();
        $label = WSS_Report_Data::membership_label();

        echo '<label for="ct_plan_id" class="screen-reader-text">' . esc_html__('Membership plan', 'woo-sub-snapshot') . '</label>';
        echo '<select id="ct_plan_id" name="ct_plan_id">';
        echo '<option value="0"' . selected($plan_id, 0, false) . '>' . esc_html__('— Disabled —', 'woo-sub-snapshot') . '</option>';
        foreach ($plans as $id => $title) {
            echo '<option value="' . esc_attr($id) . '"' . selected($plan_id, $id, false) . '>' . esc_html($title . ' (#' . $id . ')') . '</option>';
        }
        // Preserve a previously-saved plan that no longer resolves to a plan post.
        if ($plan_id > 0 && !isset($plans[$plan_id])) {
            echo '<option value="' . esc_attr($plan_id) . '" selected>' . esc_html(sprintf(__('Plan #%d (not found)', 'woo-sub-snapshot'), $plan_id)) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Membership plan to count in the report. Choose “Disabled” to hide the column.', 'woo-sub-snapshot') . '</p>';

        echo '<p><label for="membership_label">' . esc_html__('Column label:', 'woo-sub-snapshot') . ' </label>';
        echo '<input type="text" id="membership_label" name="membership_label" value="' . esc_attr($label) . '" class="regular-text" placeholder="' . esc_attr__('Members', 'woo-sub-snapshot') . '" /></p>';

        echo '<p><label><input type="checkbox" name="ct_exclude_subscribers" value="yes"' . checked($exclude, true, false) . ' /> ';
        echo esc_html__('Exclude members who also hold a live subscription', 'woo-sub-snapshot') . '</label></p>';

        echo '</td></tr>';
    }

    /**
     * A nonce-protected admin-post GET link (used for the button actions).
     */
    private function action_url($action, $nonce_action) {
        return wp_nonce_url(admin_url('admin-post.php?action=' . $action), $nonce_action);
    }

    private function render_toolbar($start_filter, $end_filter) {
        echo '<form method="get"><input type="hidden" name="page" value="woo-sub-snapshot" />';
        echo '<label for="start_date">' . esc_html__('Start Date:', 'woo-sub-snapshot') . ' </label><input type="date" id="start_date" name="start_date" value="' . esc_attr($start_filter) . '" /> ';
        echo '<label for="end_date">' . esc_html__('End Date:', 'woo-sub-snapshot') . ' </label><input type="date" id="end_date" name="end_date" value="' . esc_attr($end_filter) . '" />';
        submit_button(__('Filter', 'woo-sub-snapshot'), 'secondary', '', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
        echo '<input type="hidden" name="action" value="woo_sub_snapshot_export">';
        wp_nonce_field('woo_sub_snapshot_export');
        echo '<input type="hidden" name="start_date" value="' . esc_attr($start_filter) . '">';
        echo '<input type="hidden" name="end_date" value="' . esc_attr($end_filter) . '">';
        submit_button(__('Export CSV', 'woo-sub-snapshot'), 'secondary', '', false);
        echo '</form>';
    }

    private function render_charts($rows) {
        if (count($rows) < 2) {
            return;
        }
        $labels = wp_list_pluck($rows, 'month');

        $series = array(
            array('label' => __('Active', 'woo-sub-snapshot'),         'color' => '#2271b1', 'data' => wp_list_pluck($rows, 'active')),
            array('label' => __('Pending Cancel', 'woo-sub-snapshot'), 'color' => '#d63638', 'data' => wp_list_pluck($rows, 'pending_cancel')),
        );
        if (WSS_Report_Data::membership_enabled()) {
            $series[] = array('label' => WSS_Report_Data::membership_label(), 'color' => '#00a32a', 'data' => wp_list_pluck($rows, 'ct_club'));
        }

        echo '<h2>' . esc_html__('Subscription Trends', 'woo-sub-snapshot') . '</h2>';
        echo WSS_Chart::render_line($labels, $series, array('title' => __('Subscription Trends', 'woo-sub-snapshot')));

        echo '<h2>' . esc_html__('Estimated MRR', 'woo-sub-snapshot') . '</h2>';
        echo WSS_Chart::render_line($labels, array(
            array('label' => __('MRR', 'woo-sub-snapshot'), 'color' => '#8c5e00', 'data' => wp_list_pluck($rows, 'mrr')),
        ), array('title' => __('Estimated MRR', 'woo-sub-snapshot')));
    }

    private function render_table($rows) {
        $show_members = WSS_Report_Data::membership_enabled();

        $headings = array(
            __('Month', 'woo-sub-snapshot'),
            __('Active', 'woo-sub-snapshot'),
            __('Pending Cancel', 'woo-sub-snapshot'),
        );
        if ($show_members) {
            $headings[] = WSS_Report_Data::membership_label();
        }
        $headings = array_merge($headings, array(
            __('New', 'woo-sub-snapshot'),
            __('Cancelled', 'woo-sub-snapshot'),
            __('Net', 'woo-sub-snapshot'),
            __('Churn %', 'woo-sub-snapshot'),
            __('Est. MRR', 'woo-sub-snapshot'),
        ));

        echo '<h2>' . esc_html__('Monthly Detail', 'woo-sub-snapshot') . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr>';
        foreach ($headings as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        $prev_active = null;
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row['month']) . '</td>';
            echo '<td>' . esc_html($row['active']) . ' ' . $this->delta($prev_active, $row['active']) . '</td>';
            echo '<td>' . esc_html($row['pending_cancel']) . '</td>';
            if ($show_members) {
                echo '<td>' . esc_html($row['ct_club']) . '</td>';
            }
            echo '<td>' . esc_html($row['new_subs']) . '</td>';
            echo '<td>' . esc_html($row['cancellations']) . '</td>';
            echo '<td>' . $this->net($row['net_change']) . '</td>';
            echo '<td>' . esc_html($row['churn_rate']) . '%</td>';
            echo '<td>' . esc_html(WSS_Email_Report::format_money($row['mrr'])) . '</td>';
            echo '</tr>';
            $prev_active = $row['active'];
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('"Pending Cancel" reflects current status. MRR is estimated from recurring totals normalised to a monthly figure. Closed months are served from immutable snapshots once recorded.', 'woo-sub-snapshot') . '</p>';
    }

    private function delta($prev, $current) {
        if (null === $prev) {
            return '';
        }
        $diff = $current - $prev;
        if (0 === $diff) {
            return '<span style="color:#767676;">±0</span>';
        }
        $color = $diff > 0 ? '#00a32a' : '#d63638';
        $sign  = $diff > 0 ? '▲ +' : '▼ ';
        return '<span style="color:' . esc_attr($color) . ';font-size:11px;">' . esc_html($sign . $diff) . '</span>';
    }

    private function net($value) {
        $color = $value > 0 ? '#00a32a' : ($value < 0 ? '#d63638' : '#767676');
        $sign  = $value > 0 ? '+' : '';
        return '<span style="color:' . esc_attr($color) . ';">' . esc_html($sign . $value) . '</span>';
    }

    public function save_settings() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'woo-sub-snapshot'));
        }
        check_admin_referer('woo_sub_snapshot_save_settings');

        $notice = 'saved';

        if (isset($_POST['report_email'])) {
            $raw     = sanitize_text_field(wp_unslash($_POST['report_email']));
            $valid   = array();
            $invalid = false;
            foreach (preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $candidate) {
                $email = sanitize_email($candidate);
                if (is_email($email)) {
                    $valid[] = $email;
                } else {
                    $invalid = true;
                }
            }
            update_option('woo_sub_snapshot_email', implode(', ', array_unique($valid)));
            if ($invalid) {
                $notice = 'invalid_email';
            }
        }

        $plan_id = isset($_POST['ct_plan_id']) ? absint($_POST['ct_plan_id']) : 0;
        update_option('woo_sub_snapshot_ct_plan_id', $plan_id);
        update_option('woo_sub_snapshot_ct_exclude_subscribers', isset($_POST['ct_exclude_subscribers']) ? 'yes' : 'no');

        if (isset($_POST['membership_label'])) {
            update_option('woo_sub_snapshot_membership_label', sanitize_text_field(wp_unslash($_POST['membership_label'])));
        }

        $cadence     = isset($_POST['cadence']) && 'weekly' === $_POST['cadence'] ? 'weekly' : 'monthly';
        $old_cadence = WSS_Email_Report::get_cadence();
        update_option('woo_sub_snapshot_cadence', $cadence);
        if ($cadence !== $old_cadence) {
            WSS_Email_Report::reschedule_event();
        }

        wp_safe_redirect(add_query_arg('wss_notice', $notice, admin_url('admin.php?page=woo-sub-snapshot')));
        exit;
    }

    private function render_notice() {
        if (!isset($_GET['wss_notice'])) {
            return;
        }
        $notice = sanitize_key(wp_unslash($_GET['wss_notice']));

        $messages = array(
            'saved'             => array('success', __('Settings saved.', 'woo-sub-snapshot')),
            'invalid_email'     => array('warning', __('Some email addresses were invalid and skipped. Valid addresses and other settings were saved.', 'woo-sub-snapshot')),
            'test_sent'         => array('success', __('Test email sent.', 'woo-sub-snapshot')),
            'test_failed'       => array('error', __('Test email could not be sent. Check your mail configuration.', 'woo-sub-snapshot')),
            'test_no_recipient' => array('warning', __('Add at least one recipient email before sending a test.', 'woo-sub-snapshot')),
        );

        if ('backfilled' === $notice) {
            $count = isset($_GET['wss_count']) ? absint($_GET['wss_count']) : 0;
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(_n('%d monthly snapshot recorded.', '%d monthly snapshots recorded.', $count, 'woo-sub-snapshot'), $count))
            );
            return;
        }

        if (isset($messages[$notice])) {
            list($type, $text) = $messages[$notice];
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
        }
    }
}
