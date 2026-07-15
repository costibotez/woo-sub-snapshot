<?php
/**
 * Monthly scheduled email with the report attached as CSV.
 */

if (!defined('ABSPATH')) exit;

class WSS_Email_Report {

    const EVENT    = 'woo_sub_snapshot_monthly_email';
    const INTERVAL = 'wss_monthly';

    public function __construct() {
        add_action(self::EVENT, array($this, 'send'));
    }

    public static function register_cron_interval($schedules) {
        $schedules[self::INTERVAL] = array(
            'interval' => MONTH_IN_SECONDS,
            'display'  => __('Once a month', 'woo-sub-snapshot'),
        );
        return $schedules;
    }

    public static function schedule_event() {
        if (!wp_next_scheduled(self::EVENT)) {
            wp_schedule_event(strtotime('first day of next month 00:00'), self::INTERVAL, self::EVENT);
        }
    }

    public static function clear_event() {
        wp_clear_scheduled_hook(self::EVENT);
    }

    public function send() {
        // MONTH_IN_SECONDS is a fixed 30 days, so re-anchor each run to the
        // first of the next month to prevent drift.
        self::clear_event();
        wp_schedule_event(strtotime('first day of next month 00:00'), self::INTERVAL, self::EVENT);

        $email = get_option('woo_sub_snapshot_email', '');
        if (!$email || !is_email($email)) {
            return;
        }

        $rows = WSS_Report_Data::get_report_rows(
            WSS_Report_Data::default_start_date(),
            WSS_Report_Data::default_end_date()
        );

        // Written to the system temp dir with a random name (never the public
        // uploads dir) and removed as soon as the mail is handed off.
        $file = trailingslashit(get_temp_dir()) . 'subscription-report-' . gmdate('Y-m') . '-' . wp_generate_password(12, false) . '.csv';
        $fp = fopen($file, 'w');
        if (!$fp) {
            return;
        }
        WSS_CSV_Exporter::write_rows($fp, $rows);
        fclose($fp);

        $subject = __('Monthly Active Subscriptions Report', 'woo-sub-snapshot');
        $body    = $this->build_html_body($rows);
        $headers = array('Content-Type: text/html; charset=UTF-8');

        wp_mail($email, $subject, $body, $headers, array($file));

        if (file_exists($file)) {
            unlink($file);
        }
    }

    private function build_html_body($rows) {
        $table = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;">';
        $table .= '<thead><tr>'
            . '<th>' . esc_html__('Month', 'woo-sub-snapshot') . '</th>'
            . '<th>' . esc_html__('Active Subscriptions', 'woo-sub-snapshot') . '</th>'
            . '<th>' . esc_html__('Pending Cancel', 'woo-sub-snapshot') . '</th>'
            . '<th>' . esc_html__('Active CT Club Members', 'woo-sub-snapshot') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $table .= '<tr><td>' . esc_html($row['month']) . '</td><td>' . esc_html($row['active']) . '</td><td>' . esc_html($row['pending_cancel']) . '</td><td>' . esc_html($row['ct_club']) . '</td></tr>';
        }
        $table .= '</tbody></table>';

        return '<p>' . esc_html__('Hi,', 'woo-sub-snapshot') . '</p>'
            . '<p>' . esc_html__('Here is your monthly report showing active and pending-cancel WooCommerce subscriptions along with active CT Club members. The full report is attached as CSV.', 'woo-sub-snapshot') . '</p>'
            . $table
            . '<p>' . esc_html__('Best,', 'woo-sub-snapshot') . '<br>' . esc_html(get_bloginfo('name')) . ' ' . esc_html__('(Automated email)', 'woo-sub-snapshot') . '</p>';
    }
}
