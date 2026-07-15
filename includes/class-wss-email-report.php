<?php
/**
 * Scheduled email with the report attached as CSV. Cadence (weekly/monthly)
 * and recipients are configurable, plus an on-demand test send.
 */

if (!defined('ABSPATH')) exit;

class WSS_Email_Report {

    const EVENT    = 'woo_sub_snapshot_monthly_email';
    const INTERVAL = 'wss_monthly';

    public function __construct() {
        add_action(self::EVENT, array($this, 'send'));
        add_action('admin_post_woo_sub_snapshot_test_email', array($this, 'handle_test_email'));
    }

    public static function register_cron_interval($schedules) {
        $schedules[self::INTERVAL] = array(
            'interval' => MONTH_IN_SECONDS,
            'display'  => __('Once a month', 'woo-sub-snapshot'),
        );
        return $schedules;
    }

    public static function get_cadence() {
        $cadence = get_option('woo_sub_snapshot_cadence', 'monthly');
        return in_array($cadence, array('weekly', 'monthly'), true) ? $cadence : 'monthly';
    }

    /**
     * Recipients as an array of valid emails. Falls back to the legacy single
     * option for backward compatibility.
     */
    public static function get_recipients() {
        $raw = get_option('woo_sub_snapshot_email', '');
        $emails = array();
        foreach (preg_split('/[,\s]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $candidate) {
            $email = sanitize_email($candidate);
            if (is_email($email)) {
                $emails[] = $email;
            }
        }
        return array_values(array_unique($emails));
    }

    private static function schedule_args() {
        if ('weekly' === self::get_cadence()) {
            return array(strtotime('next monday 06:00'), 'weekly');
        }
        return array(strtotime('first day of next month 00:00'), self::INTERVAL);
    }

    public static function schedule_event() {
        if (!wp_next_scheduled(self::EVENT)) {
            list($timestamp, $recurrence) = self::schedule_args();
            wp_schedule_event($timestamp, $recurrence, self::EVENT);
        }
    }

    /**
     * Reschedule from scratch — used when the cadence setting changes.
     */
    public static function reschedule_event() {
        self::clear_event();
        list($timestamp, $recurrence) = self::schedule_args();
        wp_schedule_event($timestamp, $recurrence, self::EVENT);
    }

    public static function clear_event() {
        wp_clear_scheduled_hook(self::EVENT);
    }

    public function send() {
        $recipients = self::get_recipients();
        if (empty($recipients)) {
            return;
        }
        $this->dispatch($recipients);
    }

    public function handle_test_email() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'woo-sub-snapshot'));
        }
        check_admin_referer('woo_sub_snapshot_test_email');

        $recipients = self::get_recipients();
        $notice = empty($recipients) ? 'test_no_recipient' : ($this->dispatch($recipients) ? 'test_sent' : 'test_failed');

        wp_safe_redirect(add_query_arg('wss_notice', $notice, admin_url('admin.php?page=woo-sub-snapshot')));
        exit;
    }

    /**
     * Build the report, write a throwaway CSV, and mail it. Returns wp_mail's
     * result so the test-send can report success/failure.
     */
    private function dispatch($recipients) {
        $rows = WSS_Report_Data::get_report_rows(
            WSS_Report_Data::default_start_date(),
            WSS_Report_Data::default_end_date()
        );

        // Written to the system temp dir with a random name (never the public
        // uploads dir) and removed as soon as the mail is handed off.
        $file = trailingslashit(get_temp_dir()) . 'subscription-report-' . gmdate('Y-m') . '-' . wp_generate_password(12, false) . '.csv';
        $fp = fopen($file, 'w');
        if (!$fp) {
            return false;
        }
        WSS_CSV_Exporter::write_rows($fp, $rows);
        fclose($fp);

        $subject = __('Active Subscriptions Report', 'woo-sub-snapshot');
        $body    = $this->build_html_body($rows);
        $headers = array('Content-Type: text/html; charset=UTF-8');

        $sent = wp_mail($recipients, $subject, $body, $headers, array($file));

        if (file_exists($file)) {
            unlink($file);
        }
        return $sent;
    }

    private function build_html_body($rows) {
        $cols = array(
            'month'          => __('Month', 'woo-sub-snapshot'),
            'active'         => __('Active', 'woo-sub-snapshot'),
            'pending_cancel' => __('Pending Cancel', 'woo-sub-snapshot'),
            'ct_club'        => __('CT Club', 'woo-sub-snapshot'),
            'new_subs'       => __('New', 'woo-sub-snapshot'),
            'cancellations'  => __('Cancelled', 'woo-sub-snapshot'),
            'churn_rate'     => __('Churn %', 'woo-sub-snapshot'),
            'mrr'            => __('MRR', 'woo-sub-snapshot'),
        );

        $table = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;">';
        $table .= '<thead><tr>';
        foreach ($cols as $label) {
            $table .= '<th>' . esc_html($label) . '</th>';
        }
        $table .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $table .= '<tr>';
            foreach ($cols as $key => $label) {
                $value = $row[$key];
                if ('mrr' === $key) {
                    $value = self::format_money($value);
                } elseif ('churn_rate' === $key) {
                    $value = $value . '%';
                }
                $table .= '<td>' . esc_html($value) . '</td>';
            }
            $table .= '</tr>';
        }
        $table .= '</tbody></table>';

        return '<p>' . esc_html__('Hi,', 'woo-sub-snapshot') . '</p>'
            . '<p>' . esc_html__('Here is your subscriptions report showing active and pending-cancel WooCommerce subscriptions, CT Club members, growth, churn, and estimated MRR. The full report is attached as CSV.', 'woo-sub-snapshot') . '</p>'
            . $table
            . '<p>' . esc_html__('Best,', 'woo-sub-snapshot') . '<br>' . esc_html(get_bloginfo('name')) . ' ' . esc_html__('(Automated email)', 'woo-sub-snapshot') . '</p>';
    }

    public static function format_money($amount) {
        if (function_exists('get_woocommerce_currency_symbol')) {
            return html_entity_decode(get_woocommerce_currency_symbol()) . number_format((float) $amount, 2);
        }
        return number_format((float) $amount, 2);
    }
}
