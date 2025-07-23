<?php
/**
 * Plugin Name: Woo Subscription Snapshot
 * Plugin URI: https://github.com/costibotez/woo-sub-snapshot
 * Description: Provides a monthly snapshot of active WooCommerce subscriptions (including pending cancellations) and CT Club memberships, with CSV export and email.
 * Version: 1.6.0
 * Author: Costin Botez
 * Author URI: https://nomad-developer.co.uk
*/

if (!defined('ABSPATH')) exit;

class Woo_Sub_Snapshot {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_page'));
        add_action('admin_post_woo_sub_snapshot_export', array($this, 'handle_csv_export'));
        add_action('admin_post_woo_sub_snapshot_save_email', array($this, 'save_email_setting'));
        add_action('woo_sub_snapshot_monthly_email', array($this, 'send_monthly_email_report'));

        if (!wp_next_scheduled('woo_sub_snapshot_monthly_email')) {
            wp_schedule_event(strtotime('first day of next month 00:00'), 'monthly', 'woo_sub_snapshot_monthly_email');
        }
    }

    public function add_admin_page() {
        add_menu_page(
            'Subscription Reports',
            'Subscription Reports',
            'manage_woocommerce',
            'woo-sub-snapshot',
            array($this, 'render_admin_page'),
            'dashicons-chart-line'
        );
    }

    public function render_admin_page() {
        $start_filter = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-01', strtotime('-11 months'));
        $end_filter = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-t');

        $email = get_option('woo_sub_snapshot_email', '');

        echo '<div class="wrap"><h1>Monthly Active Subscriptions</h1>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="woo_sub_snapshot_save_email">';
        echo '<label for="report_email">Report Recipient Email: </label>';
        echo '<input type="email" name="report_email" value="' . esc_attr($email) . '" required />';
        submit_button("Save Email");
        echo '</form><hr>';

        echo '<form method="get"><input type="hidden" name="page" value="woo-sub-snapshot" />';
        echo '<label for="start_date">Start Date: </label><input type="date" name="start_date" value="' . esc_attr($start_filter) . '" />';
        echo '<label for="end_date">End Date: </label><input type="date" name="end_date" value="' . esc_attr($end_filter) . '" />';
        submit_button("Filter");
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="woo_sub_snapshot_export">';
        echo '<input type="hidden" name="start_date" value="' . esc_attr($start_filter) . '">';
        echo '<input type="hidden" name="end_date" value="' . esc_attr($end_filter) . '">';
        submit_button("Export CSV");
        echo '</form>';

        echo '<table class="widefat fixed striped"><thead><tr><th>Month</th><th>Active Subscriptions</th><th>Pending Cancel</th><th>Active CT Club Members</th><th>Total Amount</th><th>New Subs</th><th>Renewals</th><th>Cancellations</th><th>Ended</th><th>Signup Total</th><th>Renewal Total</th></tr></thead><tbody>';
        $months = $this->get_month_range($start_filter, $end_filter);
        foreach ($months as $month) {
            $stats = $this->get_subscription_stats($month);
            $ct_club = $this->get_ct_club_member_count($month);
            echo '<tr><td>' . esc_html($month) . '</td><td>' . esc_html($stats['active']) . '</td><td>' . esc_html($stats['pending_cancel']) . '</td><td>' . esc_html($ct_club) . '</td><td>' . esc_html(number_format($stats['subscription_total'], 2)) . '</td><td>' . esc_html($stats['new_subscriptions']) . '</td><td>' . esc_html($stats['renewals']) . '</td><td>' . esc_html($stats['cancellations']) . '</td><td>' . esc_html($stats['ended']) . '</td><td>' . esc_html(number_format($stats['signup_total'], 2)) . '</td><td>' . esc_html(number_format($stats['renewal_total'], 2)) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function save_email_setting() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        if (isset($_POST['report_email']) && is_email($_POST['report_email'])) {
            update_option('woo_sub_snapshot_email', sanitize_email($_POST['report_email']));
        }

        wp_redirect(admin_url('admin.php?page=woo-sub-snapshot'));
        exit;
    }

    private function get_month_range($start_date, $end_date) {
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('first day of next month');

        $months = array();
        while ($start < $end) {
            $months[] = $start->format('Y-m');
            $start->modify('+1 month');
        }
        return $months;
    }

    private function get_subscription_counts($month) {
        global $wpdb;

        $start_ts = strtotime(date('Y-m-01 00:00:00', strtotime($month)));
        $end_ts   = strtotime(date('Y-m-t 23:59:59', strtotime($month)));

        $subscription_ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'shop_subscription'
            AND post_status IN ('wc-active', 'wc-pending-cancel')
        ");

        $active = 0;
        $pending_cancel = 0;

        foreach ($subscription_ids as $id) {
            $subscription = wcs_get_subscription($id);
            if (!$subscription) continue;

            $start_time     = $subscription->get_time('start');
            $next_payment   = $subscription->get_time('next_payment');
            $end_time       = $subscription->get_time('end');

            $access_end = $next_payment ? $next_payment : $end_time;
            if (!$access_end) {
                $access_end = $end_ts; // treat open-ended subscriptions as active for the whole period
            }

            if ($start_time <= $end_ts && $access_end >= $start_ts) {
                if ($subscription->has_status('active')) {
                    $active++;
                }
                if ($subscription->has_status('pending-cancel')) {
                    $pending_cancel++;
                }
            }
        }

        return array('active' => $active, 'pending_cancel' => $pending_cancel);
    }

    private function get_ct_club_member_count($month) {
        global $wpdb;

        $start_ts = strtotime(date('Y-m-01 00:00:00', strtotime($month)));
        $end_ts   = strtotime(date('Y-m-t 23:59:59', strtotime($month)));

        $memberships = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_author, post_date FROM {$wpdb->posts} WHERE post_type = 'wc_user_membership' AND post_status = 'wcm-active' AND post_parent = %d",
            13981
        ));

        $count = 0;
        foreach ($memberships as $membership) {
            $start_time = strtotime($membership->post_date);
            $end_time = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
                $membership->ID,
                '_end_date'
            ));
            $end_time = $end_time ? strtotime($end_time) : null;

            if ($start_time > $end_ts) {
                continue;
            }
            if (!is_null($end_time) && $end_time < $start_ts) {
                continue;
            }

            $has_sub = $wpdb->get_var($wpdb->prepare(
                "SELECT s.ID FROM {$wpdb->posts} s JOIN {$wpdb->postmeta} smeta ON smeta.post_id = s.ID WHERE s.post_type = 'shop_subscription' AND s.post_status IN ('wc-active','wc-pending-cancel','wc-on-hold') AND smeta.meta_key = '_user_id' AND smeta.meta_value = %d LIMIT 1",
                $membership->post_author
            ));

            if (!$has_sub) {
                $count++;
            }
        }


        return $count;
    }

    private function get_subscription_stats($month) {
        global $wpdb;

        $start_ts = strtotime(date('Y-m-01 00:00:00', strtotime($month)));
        $end_ts   = strtotime(date('Y-m-t 23:59:59', strtotime($month)));

        $subscription_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_subscription'");

        $stats = array(
            'active'            => 0,
            'pending_cancel'    => 0,
            'new_subscriptions' => 0,
            'renewals'          => 0,
            'cancellations'     => 0,
            'ended'             => 0,
            'signup_total'      => 0,
            'renewal_total'     => 0,
        );

        foreach ($subscription_ids as $id) {
            $subscription = wcs_get_subscription($id);
            if (!$subscription) continue;

            $start_time     = $subscription->get_time('start');
            $next_payment   = $subscription->get_time('next_payment');
            $end_time       = $subscription->get_time('end');
            $cancel_time    = $subscription->get_time('cancelled');

            $access_end = $next_payment ? $next_payment : $end_time;
            if (!$access_end) {
                $access_end = $end_ts;
            }

            if ($start_time <= $end_ts && $access_end >= $start_ts) {
                if ($subscription->has_status('active')) {
                    $stats['active']++;
                }
                if ($subscription->has_status('pending-cancel')) {
                    $stats['pending_cancel']++;
                }
            }

            if ($start_time >= $start_ts && $start_time <= $end_ts) {
                $stats['new_subscriptions']++;
                $parent_order = $subscription->get_parent();
                if ($parent_order && is_object($parent_order)) {
                    $stats['signup_total'] += floatval($parent_order->get_total());
                }
            }

            if ($cancel_time && $cancel_time >= $start_ts && $cancel_time <= $end_ts) {
                $stats['cancellations']++;
            }

            if ($end_time && $end_time >= $start_ts && $end_time <= $end_ts) {
                $stats['ended']++;
            }

            $renewal_orders = $subscription->get_related_orders('renewal');
            if (!empty($renewal_orders)) {
                foreach ($renewal_orders as $order_id) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $order_ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
                        if ($order_ts >= $start_ts && $order_ts <= $end_ts) {
                            $stats['renewals']++;
                            $stats['renewal_total'] += floatval($order->get_total());
                        }
                    }
                }
            }
        }

        $stats['subscription_total'] = $stats['signup_total'] + $stats['renewal_total'];

        return $stats;
    }

    public function handle_csv_export() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-01', strtotime('-11 months'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-t');

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename=subscription-report.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('Month', 'Active Subscriptions', 'Pending Cancel', 'Active CT Club Members', 'Total Amount', 'New Subs', 'Renewals', 'Cancellations', 'Ended', 'Signup Total', 'Renewal Total'));

        foreach ($this->get_month_range($start_date, $end_date) as $month) {
            $stats = $this->get_subscription_stats($month);
            $ct_club = $this->get_ct_club_member_count($month);
            fputcsv($output, array(
                $month,
                $stats['active'],
                $stats['pending_cancel'],
                $ct_club,
                number_format($stats['subscription_total'], 2),
                $stats['new_subscriptions'],
                $stats['renewals'],
                $stats['cancellations'],
                $stats['ended'],
                number_format($stats['signup_total'], 2),
                number_format($stats['renewal_total'], 2)
            ));
        }

        fclose($output);
        exit;
    }

    public function send_monthly_email_report() {
        $email = get_option('woo_sub_snapshot_email', '');
        if (!$email || !is_email($email)) return;

        $upload_dir = wp_upload_dir();
        $file = trailingslashit($upload_dir['basedir']) . 'subscription-report.csv';
        $fp = fopen($file, 'w');
        fputcsv($fp, array('Month', 'Active Subscriptions', 'Pending Cancel', 'Active CT Club Members', 'Total Amount', 'New Subs', 'Renewals', 'Cancellations', 'Ended', 'Signup Total', 'Renewal Total'));

        foreach ($this->get_month_range(date('Y-m-01', strtotime('-11 months')), date('Y-m-t')) as $month) {
            $stats = $this->get_subscription_stats($month);
            $ct_club = $this->get_ct_club_member_count($month);
            fputcsv($fp, array(
                $month,
                $stats['active'],
                $stats['pending_cancel'],
                $ct_club,
                number_format($stats['subscription_total'], 2),
                $stats['new_subscriptions'],
                $stats['renewals'],
                $stats['cancellations'],
                $stats['ended'],
                number_format($stats['signup_total'], 2),
                number_format($stats['renewal_total'], 2)
            ));
        }
        fclose($fp);

        $subject = 'Monthly Active Subscriptions Report';
        $body = 'Hi,

Attached is your monthly report showing active and pending-cancel WooCommerce subscriptions along with Active CT Club members.

Best,
Costin (Automated email)';
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($email, $subject, $body, $headers, array($file));
    }
}

new Woo_Sub_Snapshot();
?>
