<?php
/**
 * Single source of truth for report queries and month bucketing.
 */

if (!defined('ABSPATH')) exit;

class WSS_Report_Data {

    const CACHE_TTL = HOUR_IN_SECONDS;

    /** Average days per month, used to normalise recurring totals to MRR. */
    const DAYS_PER_MONTH = 30.436875;

    public static function get_plan_id() {
        return (int) get_option('woo_sub_snapshot_ct_plan_id', 13981);
    }

    public static function exclude_subscribers() {
        return 'yes' === get_option('woo_sub_snapshot_ct_exclude_subscribers', 'yes');
    }

    /**
     * The metric columns a report row exposes, beyond 'month'.
     */
    public static function metric_keys() {
        return array('active', 'pending_cancel', 'ct_club', 'new_subs', 'cancellations', 'net_change', 'churn_rate', 'mrr');
    }

    /**
     * Validate a Y-m-d date string, falling back to $default.
     */
    public static function sanitize_date($value, $default) {
        $value = is_string($value) ? trim($value) : '';
        $parsed = DateTime::createFromFormat('Y-m-d', $value);
        if ($parsed && $parsed->format('Y-m-d') === $value) {
            return $value;
        }
        return $default;
    }

    public static function default_start_date() {
        return date('Y-m-01', strtotime('-11 months'));
    }

    public static function default_end_date() {
        return date('Y-m-t');
    }

    /**
     * Returns one row per month in the range, each with the keys in
     * metric_keys() plus 'month' (Y-m). Closed past months are served from
     * immutable snapshots when available; open months are computed live.
     */
    public static function get_report_rows($start_date, $end_date) {
        $plan_id = self::get_plan_id();
        $exclude = self::exclude_subscribers();

        $cache_key = 'wss_report_' . md5(implode('|', array($start_date, $end_date, $plan_id, (int) $exclude)));
        $cached = get_transient($cache_key);
        if (false !== $cached) {
            return $cached;
        }

        $rows = self::compute_report_rows($start_date, $end_date);

        // Overlay immutable snapshots for closed months so history stays
        // stable regardless of later status changes.
        $snapshots = WSS_Snapshots::get_map();
        if ($snapshots) {
            foreach ($rows as $i => $row) {
                if (isset($snapshots[$row['month']])) {
                    $rows[$i] = $snapshots[$row['month']];
                }
            }
        }

        set_transient($cache_key, $rows, self::CACHE_TTL);

        return $rows;
    }

    /**
     * Compute report rows live from the current subscription/membership data,
     * ignoring any stored snapshots. Used by the report view and by the
     * snapshot recorder when it closes a month.
     */
    public static function compute_report_rows($start_date, $end_date) {
        $plan_id = self::get_plan_id();
        $exclude = self::exclude_subscribers();

        $months           = self::get_month_range($start_date, $end_date);
        $subscriptions    = self::get_subscriptions();
        $memberships      = $plan_id ? self::get_memberships($plan_id) : array();
        $subscribed_users = ($exclude && $memberships) ? self::get_subscribed_user_ids() : array();

        $rows = array();
        $prev_active = null;
        foreach ($months as $month) {
            $start_ts = strtotime($month . '-01 00:00:00');
            $end_ts   = strtotime(date('Y-m-t 23:59:59', $start_ts));

            $active = 0;
            $pending_cancel = 0;
            $new_subs = 0;
            $cancellations = 0;
            $mrr = 0.0;
            foreach ($subscriptions as $sub) {
                if ($sub['start'] >= $start_ts && $sub['start'] <= $end_ts) {
                    $new_subs++;
                }
                if (null !== $sub['cancelled'] && $sub['cancelled'] >= $start_ts && $sub['cancelled'] <= $end_ts) {
                    $cancellations++;
                }
                if ($sub['start'] > $end_ts || $sub['end'] < $start_ts) {
                    continue;
                }
                if ('wc-pending-cancel' === $sub['status']) {
                    $pending_cancel++;
                } else {
                    $active++;
                    $mrr += $sub['mrr'];
                }
            }

            $ct_club = 0;
            foreach ($memberships as $membership) {
                if ($membership['start'] > $end_ts) {
                    continue;
                }
                if (null !== $membership['end'] && $membership['end'] < $start_ts) {
                    continue;
                }
                if ($exclude && isset($subscribed_users[$membership['user_id']])) {
                    continue;
                }
                $ct_club++;
            }

            $base = (null === $prev_active) ? $active : $prev_active;
            $churn_rate = $base > 0 ? round(($cancellations / $base) * 100, 1) : 0.0;

            $rows[] = array(
                'month'          => $month,
                'active'         => $active,
                'pending_cancel' => $pending_cancel,
                'ct_club'        => $ct_club,
                'new_subs'       => $new_subs,
                'cancellations'  => $cancellations,
                'net_change'     => $new_subs - $cancellations,
                'churn_rate'     => $churn_rate,
                'mrr'            => round($mrr, 2),
            );

            $prev_active = $active;
        }

        return $rows;
    }

    public static function get_month_range($start_date, $end_date) {
        try {
            $start = new DateTime($start_date);
            $end   = new DateTime($end_date);
        } catch (Exception $e) {
            $start = new DateTime(self::default_start_date());
            $end   = new DateTime(self::default_end_date());
        }
        $start->modify('first day of this month');
        $end->modify('first day of next month');

        $months = array();
        while ($start < $end) {
            $months[] = $start->format('Y-m');
            $start->modify('+1 month');
        }
        return $months;
    }

    /**
     * All subscriptions with their activity window and monthly recurring
     * value, fetched in one query. Ended statuses are included so past months
     * count subscriptions that were active then but have since ended.
     */
    private static function get_subscriptions() {
        global $wpdb;

        $results = $wpdb->get_results("
            SELECT p.ID, p.post_status, p.post_date_gmt,
                   ms.meta_value AS schedule_start,
                   mn.meta_value AS schedule_next_payment,
                   me.meta_value AS schedule_end,
                   mc.meta_value AS schedule_cancelled,
                   mt.meta_value AS order_total,
                   mbp.meta_value AS billing_period,
                   mbi.meta_value AS billing_interval
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_schedule_start'
            LEFT JOIN {$wpdb->postmeta} mn ON mn.post_id = p.ID AND mn.meta_key = '_schedule_next_payment'
            LEFT JOIN {$wpdb->postmeta} me ON me.post_id = p.ID AND me.meta_key = '_schedule_end'
            LEFT JOIN {$wpdb->postmeta} mc ON mc.post_id = p.ID AND mc.meta_key = '_schedule_cancelled'
            LEFT JOIN {$wpdb->postmeta} mt ON mt.post_id = p.ID AND mt.meta_key = '_order_total'
            LEFT JOIN {$wpdb->postmeta} mbp ON mbp.post_id = p.ID AND mbp.meta_key = '_billing_period'
            LEFT JOIN {$wpdb->postmeta} mbi ON mbi.post_id = p.ID AND mbi.meta_key = '_billing_interval'
            WHERE p.post_type = 'shop_subscription'
            AND p.post_status IN ('wc-active', 'wc-pending-cancel', 'wc-on-hold', 'wc-cancelled', 'wc-expired')
        ");

        $subscriptions = array();
        foreach ($results as $row) {
            $start        = self::parse_date($row->schedule_start);
            $next_payment = self::parse_date($row->schedule_next_payment);
            $end          = self::parse_date($row->schedule_end);
            $cancelled    = self::parse_date($row->schedule_cancelled);

            if (!$start) {
                $start = self::parse_date($row->post_date_gmt);
            }
            if (!$start) {
                continue;
            }

            $ended = in_array($row->post_status, array('wc-cancelled', 'wc-expired'), true);

            if (!$ended) {
                // Live subscriptions: access runs to the next payment, the
                // scheduled end, or indefinitely for open-ended subscriptions.
                $access_end = $next_payment ? $next_payment : ($end ? $end : PHP_INT_MAX);
            } else {
                // Ended subscriptions with no recorded end can't be placed in
                // a window, so they're skipped rather than counted forever.
                $access_end = $end ? $end : ($cancelled ? $cancelled : ($next_payment ? $next_payment : 0));
                if (!$access_end) {
                    continue;
                }
            }

            // Cancellation event time, for churn/cancellation counts.
            $cancel_ts = null;
            if ($ended) {
                $cancel_ts = $cancelled ? $cancelled : ($end ? $end : null);
            }

            $subscriptions[] = array(
                'status'    => $row->post_status,
                'start'     => $start,
                'end'       => $access_end,
                'cancelled' => $cancel_ts,
                'mrr'       => self::to_monthly((float) $row->order_total, $row->billing_period, (int) $row->billing_interval),
            );
        }

        return $subscriptions;
    }

    /**
     * Normalise a recurring total for one billing cycle to a monthly figure.
     */
    private static function to_monthly($amount, $period, $interval) {
        if ($amount <= 0) {
            return 0.0;
        }
        $interval = $interval > 0 ? $interval : 1;

        switch ($period) {
            case 'day':
                return $amount * (self::DAYS_PER_MONTH / $interval);
            case 'week':
                return $amount * (self::DAYS_PER_MONTH / (7 * $interval));
            case 'year':
                return $amount / (12 * $interval);
            case 'month':
            default:
                return $amount / $interval;
        }
    }

    /**
     * Memberships for the configured plan, one query. Ended statuses are
     * included (with a required end date) so history stays accurate.
     */
    private static function get_memberships($plan_id) {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_author, p.post_status, p.post_date,
                   msd.meta_value AS start_date,
                   med.meta_value AS end_date,
                   mcd.meta_value AS cancelled_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} msd ON msd.post_id = p.ID AND msd.meta_key = '_start_date'
            LEFT JOIN {$wpdb->postmeta} med ON med.post_id = p.ID AND med.meta_key = '_end_date'
            LEFT JOIN {$wpdb->postmeta} mcd ON mcd.post_id = p.ID AND mcd.meta_key = '_cancelled_date'
            WHERE p.post_type = 'wc_user_membership'
            AND p.post_status IN ('wcm-active', 'wcm-expired', 'wcm-cancelled')
            AND p.post_parent = %d
        ", $plan_id));

        $memberships = array();
        foreach ($results as $row) {
            $start = self::parse_date($row->start_date);
            if (!$start) {
                $start = self::parse_date($row->post_date);
            }
            if (!$start) {
                continue;
            }

            $end = self::parse_date($row->end_date);
            if ('wcm-active' === $row->post_status) {
                $end = $end ? $end : null; // open-ended
            } else {
                $end = $end ? $end : self::parse_date($row->cancelled_date);
                if (!$end) {
                    continue;
                }
            }

            $memberships[] = array(
                'user_id' => (int) $row->post_author,
                'start'   => $start,
                'end'     => $end,
            );
        }

        return $memberships;
    }

    /**
     * User IDs that currently hold a live subscription, keyed by ID.
     */
    private static function get_subscribed_user_ids() {
        global $wpdb;

        $user_ids = $wpdb->get_col("
            SELECT DISTINCT pm.meta_value
            FROM {$wpdb->posts} s
            JOIN {$wpdb->postmeta} pm ON pm.post_id = s.ID AND pm.meta_key = '_customer_user'
            WHERE s.post_type = 'shop_subscription'
            AND s.post_status IN ('wc-active', 'wc-pending-cancel', 'wc-on-hold')
        ");

        return array_flip(array_map('intval', $user_ids));
    }

    private static function parse_date($value) {
        if (empty($value) || '0' === $value) {
            return 0;
        }
        $ts = strtotime($value);
        return $ts ? $ts : 0;
    }
}
