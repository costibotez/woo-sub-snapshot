<?php
/**
 * Read-only REST endpoint and WP-CLI command for the report, enabling
 * external dashboards, Google Sheets pulls, and scripted exports.
 */

if (!defined('ABSPATH')) exit;

class WSS_Api {

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route('wss/v1', '/report', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_report'),
            'permission_callback' => array($this, 'permission_check'),
            'args'                => array(
                'start' => array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'end' => array(
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }

    public function permission_check() {
        return current_user_can('manage_woocommerce');
    }

    public function get_report($request) {
        $start = WSS_Report_Data::sanitize_date($request->get_param('start'), WSS_Report_Data::default_start_date());
        $end   = WSS_Report_Data::sanitize_date($request->get_param('end'), WSS_Report_Data::default_end_date());

        return rest_ensure_response(array(
            'start' => $start,
            'end'   => $end,
            'rows'  => WSS_Report_Data::get_report_rows($start, $end),
        ));
    }

    /**
     * Registers `wp wss report [--start=] [--end=] [--format=table|csv|json]`.
     */
    public static function register_cli() {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }
        WP_CLI::add_command('wss report', array(__CLASS__, 'cli_report'));
    }

    public static function cli_report($args, $assoc_args) {
        $start  = WSS_Report_Data::sanitize_date(isset($assoc_args['start']) ? $assoc_args['start'] : '', WSS_Report_Data::default_start_date());
        $end    = WSS_Report_Data::sanitize_date(isset($assoc_args['end']) ? $assoc_args['end'] : '', WSS_Report_Data::default_end_date());
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        $rows    = WSS_Report_Data::get_report_rows($start, $end);
        $columns = array_merge(array('month'), WSS_Report_Data::metric_keys());

        WP_CLI\Utils\format_items($format, $rows, $columns);
    }
}
