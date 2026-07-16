<?php
/**
 * Shared CSV writer used by the admin export and the emailed report.
 */

if (!defined('ABSPATH')) exit;

class WSS_CSV_Exporter {

    public function __construct() {
        add_action('admin_post_woo_sub_snapshot_export', array($this, 'handle_csv_export'));
    }

    public function handle_csv_export() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'woo-sub-snapshot'));
        }
        check_admin_referer('woo_sub_snapshot_export');

        $start_date = WSS_Report_Data::sanitize_date(
            isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '',
            WSS_Report_Data::default_start_date()
        );
        $end_date = WSS_Report_Data::sanitize_date(
            isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '',
            WSS_Report_Data::default_end_date()
        );

        $rows = WSS_Report_Data::get_report_rows($start_date, $end_date);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=subscription-report-' . $start_date . '-to-' . $end_date . '.csv');

        $output = fopen('php://output', 'w');
        self::write_rows($output, $rows);
        fclose($output);
        exit;
    }

    public static function write_rows($handle, $rows) {
        $show_members = WSS_Report_Data::membership_enabled();

        $header = array(
            __('Month', 'woo-sub-snapshot'),
            __('Active Subscriptions', 'woo-sub-snapshot'),
            __('Pending Cancel', 'woo-sub-snapshot'),
        );
        if ($show_members) {
            $header[] = sprintf(__('Active %s', 'woo-sub-snapshot'), WSS_Report_Data::membership_label());
        }
        $header = array_merge($header, array(
            __('New Subscriptions', 'woo-sub-snapshot'),
            __('Cancellations', 'woo-sub-snapshot'),
            __('Net Change', 'woo-sub-snapshot'),
            __('Churn Rate %', 'woo-sub-snapshot'),
            __('Estimated MRR', 'woo-sub-snapshot'),
        ));
        fputcsv($handle, array_map(array(__CLASS__, 'escape_cell'), $header));

        foreach ($rows as $row) {
            $line = array(
                $row['month'],
                $row['active'],
                $row['pending_cancel'],
            );
            if ($show_members) {
                $line[] = $row['ct_club'];
            }
            $line = array_merge($line, array(
                $row['new_subs'],
                $row['cancellations'],
                $row['net_change'],
                $row['churn_rate'],
                $row['mrr'],
            ));
            fputcsv($handle, array_map(array(__CLASS__, 'escape_cell'), $line));
        }
    }

    /**
     * Guard against spreadsheet formula injection for any cell that could
     * ever carry user-influenced text.
     */
    public static function escape_cell($value) {
        $value = (string) $value;
        if (preg_match('/^[=+\-@\t\r]/', $value)) {
            $value = "'" . $value;
        }
        return $value;
    }
}
