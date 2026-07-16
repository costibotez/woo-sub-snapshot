<?php
/**
 * Immutable monthly snapshots.
 *
 * Once a month closes, its numbers are written to a custom table so that
 * later status changes (a subscription cancelled today) can't rewrite the
 * past, and so reports render without recomputing history every time.
 */

if (!defined('ABSPATH')) exit;

class WSS_Snapshots {

    const EVENT       = 'woo_sub_snapshot_record';
    const DB_VERSION  = '1';
    const OPT_VERSION = 'woo_sub_snapshot_db_version';

    /** Cached month => row map for the current request. */
    private static $map = null;

    public function __construct() {
        add_action(self::EVENT, array($this, 'record_last_month'));
        add_action('admin_post_woo_sub_snapshot_backfill', array($this, 'handle_backfill'));
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'wss_snapshots';
    }

    /**
     * Create/upgrade the table and schedule the recorder. Runs on activation
     * and is also checked on load so upgrades pick it up.
     */
    public static function install() {
        global $wpdb;

        if (get_option(self::OPT_VERSION) === self::DB_VERSION) {
            self::maybe_schedule();
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            month CHAR(7) NOT NULL,
            active INT UNSIGNED NOT NULL DEFAULT 0,
            pending_cancel INT UNSIGNED NOT NULL DEFAULT 0,
            ct_club INT UNSIGNED NOT NULL DEFAULT 0,
            new_subs INT UNSIGNED NOT NULL DEFAULT 0,
            cancellations INT UNSIGNED NOT NULL DEFAULT 0,
            net_change INT NOT NULL DEFAULT 0,
            churn_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
            mrr DECIMAL(14,2) NOT NULL DEFAULT 0,
            recorded_at DATETIME NOT NULL,
            PRIMARY KEY (month)
        ) {$charset};";

        dbDelta($sql);
        update_option(self::OPT_VERSION, self::DB_VERSION);

        self::maybe_schedule();
    }

    public static function maybe_schedule() {
        if (!wp_next_scheduled(self::EVENT)) {
            // A day into the new month, so the closing month is fully settled.
            wp_schedule_event(strtotime('first day of next month 02:00'), 'daily', self::EVENT);
        }
    }

    public static function clear_event() {
        wp_clear_scheduled_hook(self::EVENT);
    }

    /**
     * month => report-row map of every stored snapshot.
     */
    public static function get_map() {
        if (null !== self::$map) {
            return self::$map;
        }
        global $wpdb;

        self::$map = array();
        if (!self::table_exists()) {
            return self::$map;
        }

        $rows = $wpdb->get_results('SELECT * FROM ' . self::table_name(), ARRAY_A);
        foreach ((array) $rows as $row) {
            self::$map[$row['month']] = array(
                'month'          => $row['month'],
                'active'         => (int) $row['active'],
                'pending_cancel' => (int) $row['pending_cancel'],
                'ct_club'        => (int) $row['ct_club'],
                'new_subs'       => (int) $row['new_subs'],
                'cancellations'  => (int) $row['cancellations'],
                'net_change'     => (int) $row['net_change'],
                'churn_rate'     => (float) $row['churn_rate'],
                'mrr'            => (float) $row['mrr'],
            );
        }
        return self::$map;
    }

    /**
     * Daily cron: records the just-closed month if not already stored.
     */
    public function record_last_month() {
        $month = date('Y-m', strtotime('first day of last month'));
        if (self::has($month)) {
            return;
        }
        $this->record_month($month);
    }

    /**
     * Compute and store a single month. Existing rows are left untouched so
     * snapshots stay immutable; use $force to overwrite (manual backfill).
     */
    public static function record_month($month, $force = false) {
        if (!self::table_exists()) {
            return false;
        }
        if (!$force && self::has($month)) {
            return false;
        }

        $first = $month . '-01';
        $last  = date('Y-m-t', strtotime($first));
        $rows  = WSS_Report_Data::compute_report_rows($first, $last);
        if (empty($rows)) {
            return false;
        }
        $row = $rows[0];

        global $wpdb;
        $wpdb->replace(
            self::table_name(),
            array(
                'month'          => $row['month'],
                'active'         => $row['active'],
                'pending_cancel' => $row['pending_cancel'],
                'ct_club'        => $row['ct_club'],
                'new_subs'       => $row['new_subs'],
                'cancellations'  => $row['cancellations'],
                'net_change'     => $row['net_change'],
                'churn_rate'     => $row['churn_rate'],
                'mrr'            => $row['mrr'],
                'recorded_at'    => current_time('mysql'),
            ),
            array('%s', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%f', '%s')
        );
        self::$map = null; // invalidate request cache
        return true;
    }

    public static function has($month) {
        global $wpdb;
        if (!self::table_exists()) {
            return false;
        }
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . self::table_name() . ' WHERE month = %s',
            $month
        ));
    }

    /**
     * Admin action: record every closed month in a range that isn't stored.
     */
    public function handle_backfill() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'woo-sub-snapshot'));
        }
        check_admin_referer('woo_sub_snapshot_backfill');

        $current_month = date('Y-m');
        $count = 0;
        foreach (WSS_Report_Data::get_month_range(WSS_Report_Data::default_start_date(), WSS_Report_Data::default_end_date()) as $month) {
            if ($month >= $current_month) {
                continue; // only close finished months
            }
            if (self::record_month($month)) {
                $count++;
            }
        }

        wp_safe_redirect(add_query_arg(array('wss_notice' => 'backfilled', 'wss_count' => $count), admin_url('admin.php?page=woo-sub-snapshot')));
        exit;
    }

    private static function table_exists() {
        global $wpdb;
        static $exists = null;
        if (null !== $exists) {
            return $exists;
        }
        $table  = self::table_name();
        $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        return $exists;
    }
}
