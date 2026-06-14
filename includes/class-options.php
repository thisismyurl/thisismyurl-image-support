<?php
/**
 * Options accessor and sanitiser.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings storage and validation.
 */
class TIMU_IC_Options {

    /**
     * Plugin defaults.
     *
     * @return array
     */
    public static function defaults() {
        return array(
            'batch_size'                => 10,
            'auto_optimize_batch'       => 3,
            'enabled_extensions'        => array( 'jpg', 'jpeg', 'png', 'gif', 'bmp' ),
            'optimize_on_upload'        => 1,
            'auto_optimize_enabled'     => 0,
            'auto_optimize_admin'       => 1,
            'auto_optimize_cron'        => 1,
            'auto_optimize_interval'    => 'hourly',
            'list_per_page'             => 25,
            'report_bandwidth_cost_gb'  => 0.08,
            'report_monthly_image_hits' => 50000,
            'track_outbound_utms'       => 1,
            'strip_metadata'            => 1,
            'embed_metadata'            => 1,
            'max_dimension'             => 2560,
            'remove_duplicates'         => 1,
            'exclude_paths'              => array(),
            'cron_daily_cap'             => 50,
            'emit_attachment_schema'     => 0,
            'acquire_license_page_url'   => '',
        );
    }

    /**
     * Read effective options merged with defaults.
     *
     * @return array
     */
    public static function get() {
        $saved = get_option( TIMU_IC::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return wp_parse_args( $saved, self::defaults() );
    }

    /**
     * Sanitisation callback for register_setting.
     *
     * @param array $input Raw posted options.
     *
     * @return array
     */
    public static function sanitize( $input ) {
        $defaults = self::defaults();
        $input    = is_array( $input ) ? $input : array();

        $extensions = array_keys( TIMU_IC::get_extension_mime_map() );

        $batch_size = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : $defaults['batch_size'];
        $batch_size = min( 100, max( 1, $batch_size ) );

        $auto_batch = isset( $input['auto_optimize_batch'] ) ? absint( $input['auto_optimize_batch'] ) : $defaults['auto_optimize_batch'];
        $auto_batch = min( 25, max( 1, $auto_batch ) );

        $enabled_extensions = isset( $input['enabled_extensions'] ) ? (array) $input['enabled_extensions'] : $defaults['enabled_extensions'];
        $enabled_extensions = array_values( array_intersect( $extensions, array_map( 'sanitize_key', $enabled_extensions ) ) );
        if ( empty( $enabled_extensions ) ) {
            $enabled_extensions = array( 'jpg' );
        }

        $allowed_intervals = array( 'fifteen_minutes', 'hourly', 'twicedaily', 'daily' );
        $interval          = isset( $input['auto_optimize_interval'] ) ? sanitize_key( (string) $input['auto_optimize_interval'] ) : 'hourly';
        if ( ! in_array( $interval, $allowed_intervals, true ) ) {
            $interval = 'hourly';
        }

        $report_cost_gb = isset( $input['report_bandwidth_cost_gb'] ) ? (float) $input['report_bandwidth_cost_gb'] : (float) $defaults['report_bandwidth_cost_gb'];
        $report_cost_gb = min( 10, max( 0, $report_cost_gb ) );

        $report_hits = isset( $input['report_monthly_image_hits'] ) ? absint( $input['report_monthly_image_hits'] ) : (int) $defaults['report_monthly_image_hits'];
        $report_hits = min( 100000000, max( 0, $report_hits ) );

        $max_dimension = isset( $input['max_dimension'] ) ? absint( $input['max_dimension'] ) : (int) $defaults['max_dimension'];
        $max_dimension = min( 6000, max( 320, $max_dimension ) );

        $exclude_raw = isset( $input['exclude_paths'] ) ? (string) $input['exclude_paths'] : '';
        if ( is_array( $input['exclude_paths'] ?? null ) ) {
            $exclude_raw = implode( "\n", $input['exclude_paths'] );
        }
        $exclude_paths = array_values(
            array_filter(
                array_map( 'sanitize_text_field', array_map( 'trim', explode( "\n", $exclude_raw ) ) )
            )
        );

        $cron_cap = isset( $input['cron_daily_cap'] ) ? absint( $input['cron_daily_cap'] ) : (int) $defaults['cron_daily_cap'];
        $cron_cap = min( 500, max( 1, $cron_cap ) );

        return array(
            'batch_size'                => $batch_size,
            'auto_optimize_batch'       => $auto_batch,
            'enabled_extensions'        => $enabled_extensions,
            'optimize_on_upload'        => isset( $input['optimize_on_upload'] ) ? 1 : 0,
            'auto_optimize_enabled'     => isset( $input['auto_optimize_enabled'] ) ? 1 : 0,
            'auto_optimize_admin'       => isset( $input['auto_optimize_admin'] ) ? 1 : 0,
            'auto_optimize_cron'        => isset( $input['auto_optimize_cron'] ) ? 1 : 0,
            'auto_optimize_interval'    => $interval,
            'list_per_page'             => min( 500, max( 5, isset( $input['list_per_page'] ) ? absint( $input['list_per_page'] ) : 25 ) ),
            'report_bandwidth_cost_gb'  => $report_cost_gb,
            'report_monthly_image_hits' => $report_hits,
            'track_outbound_utms'       => isset( $input['track_outbound_utms'] ) ? 1 : 0,
            'strip_metadata'            => isset( $input['strip_metadata'] ) ? 1 : 0,
            'embed_metadata'            => isset( $input['embed_metadata'] ) ? 1 : 0,
            'max_dimension'             => $max_dimension,
            'remove_duplicates'         => isset( $input['remove_duplicates'] ) ? 1 : 0,
            'exclude_paths'              => $exclude_paths,
            'cron_daily_cap'             => $cron_cap,
            'emit_attachment_schema'     => isset( $input['emit_attachment_schema'] ) ? 1 : 0,
            'acquire_license_page_url'   => isset( $input['acquire_license_page_url'] ) ? esc_url_raw( (string) $input['acquire_license_page_url'] ) : '',
        );
    }

    /**
     * Read configured batch size for AJAX runs.
     *
     * @return int
     */
    public static function get_batch_size() {
        $options = self::get();
        return (int) $options['batch_size'];
    }
}
