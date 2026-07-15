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
        fputcsv($handle, array(
            __('Month', 'woo-sub-snapshot'),
            __('Active Subscriptions', 'woo-sub-snapshot'),
            __('Pending Cancel', 'woo-sub-snapshot'),
            __('Active CT Club Members', 'woo-sub-snapshot'),
            __('New Subscriptions', 'woo-sub-snapshot'),
            __('Cancellations', 'woo-sub-snapshot'),
            __('Net Change', 'woo-sub-snapshot'),
            __('Churn Rate %', 'woo-sub-snapshot'),
            __('Estimated MRR', 'woo-sub-snapshot'),
        ));

        foreach ($rows as $row) {
            fputcsv($handle, array(
                self::escape_cell($row['month']),
                self::escape_cell($row['active']),
                self::escape_cell($row['pending_cancel']),
                self::escape_cell($row['ct_club']),
                self::escape_cell($row['new_subs']),
                self::escape_cell($row['cancellations']),
                self::escape_cell($row['net_change']),
                self::escape_cell($row['churn_rate']),
                self::escape_cell($row['mrr']),
            ));
        }
    }

    /**
     * Guard against spreadsheet formula injection for any cell that could
     * ever carry user-influenced text.
     */
    private static function escape_cell($value) {
        $value = (string) $value;
        if (preg_match('/^[=+\-@\t\r]/', $value)) {
            $value = "'" . $value;
        }
        return $value;
    }
}
