<?php
/**
 * Background processing scheduler — Action Scheduler or WP-Cron fallback.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns cron schedule management, auto-optimize triggers, and batch enqueueing.
 */
class TIMU_IC_Scheduler {

	const DAILY_PROCESSED_OPTION = 'timu_ic_daily_processed';
	const DAILY_DATE_OPTION      = 'timu_ic_daily_processed_date';
	const AS_GROUP               = 'timu_image_support';
	const AS_BATCH_HOOK          = 'timu_ic_as_batch';

	/**
	 * Wire up hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'sync_auto_optimize_schedule' ), 25 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_auto_optimize_on_admin_access' ), 40 );
		add_action( TIMU_IC::CRON_HOOK, array( __CLASS__, 'run_auto_optimize_cron' ) );
		add_action( TIMU_IC::CRON_DAILY_RESET_HOOK, array( __CLASS__, 'reset_daily_counter' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );

		if ( self::has_action_scheduler() ) {
			add_action( self::AS_BATCH_HOOK, array( __CLASS__, 'handle_as_batch' ), 10, 2 );
		}
	}

	/**
	 * Register the 15-minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 *
	 * @return array
	 */
	public static function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['fifteen_minutes'] ) ) {
			$schedules['fifteen_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 Minutes', 'thisismyurl-image-support' ),
			);
		}

		return $schedules;
	}

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public static function has_action_scheduler() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_get_scheduled_actions' );
	}

	/**
	 * Keep the auto-optimize WP-Cron event in sync with settings.
	 *
	 * @return void
	 */
	public static function sync_auto_optimize_schedule() {
		$options         = TIMU_IC_Options::get();
		$should_schedule = ! empty( $options['auto_optimize_enabled'] ) && ! empty( $options['auto_optimize_cron'] );

		if ( ! wp_next_scheduled( TIMU_IC::CRON_DAILY_RESET_HOOK ) ) {
			wp_schedule_event( strtotime( 'midnight' ) + DAY_IN_SECONDS, 'daily', TIMU_IC::CRON_DAILY_RESET_HOOK );
		}

		if ( ! $should_schedule ) {
			$ts = wp_next_scheduled( TIMU_IC::CRON_HOOK );
			if ( $ts ) {
				wp_unschedule_event( (int) $ts, TIMU_IC::CRON_HOOK );
			}
			return;
		}

		$interval = isset( $options['auto_optimize_interval'] ) ? $options['auto_optimize_interval'] : 'hourly';
		$event    = wp_get_scheduled_event( TIMU_IC::CRON_HOOK );

		if ( $event && isset( $event->schedule ) && $event->schedule !== $interval ) {
			wp_unschedule_event( (int) $event->timestamp, TIMU_IC::CRON_HOOK );
			$event = false;
		}

		if ( ! $event ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, TIMU_IC::CRON_HOOK );
		}
	}

	/**
	 * Fire a small batch during wp-admin page loads.
	 *
	 * @return void
	 */
	public static function maybe_auto_optimize_on_admin_access() {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$options = TIMU_IC_Options::get();
		if ( empty( $options['auto_optimize_enabled'] ) || empty( $options['auto_optimize_admin'] ) ) {
			return;
		}

		if ( get_transient( TIMU_IC::ADMIN_TICK_LOCK ) ) {
			return;
		}

		set_transient( TIMU_IC::ADMIN_TICK_LOCK, 1, MINUTE_IN_SECONDS * 5 );
		self::run_auto_optimize_batch( 'admin' );
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function run_auto_optimize_cron() {
		self::run_auto_optimize_batch( 'cron' );
	}

	/**
	 * Reset the daily processed counter (runs at midnight via daily cron).
	 *
	 * @return void
	 */
	public static function reset_daily_counter() {
		delete_option( self::DAILY_PROCESSED_OPTION );
		update_option( self::DAILY_DATE_OPTION, gmdate( 'Y-m-d' ), false );
	}

	/**
	 * Run a background optimization batch, respecting the daily cap.
	 *
	 * @param string $context 'cron', 'admin', or 'as'.
	 *
	 * @return void
	 */
	private static function run_auto_optimize_batch( $context ) {
		if ( ! TIMU_IC::has_supported_image_engine() ) {
			return;
		}

		$options     = TIMU_IC_Options::get();
		$daily_cap   = isset( $options['cron_daily_cap'] ) ? (int) $options['cron_daily_cap'] : 50;
		$today       = gmdate( 'Y-m-d' );
		$stored_date = (string) get_option( self::DAILY_DATE_OPTION, '' );
		$daily_count = (int) get_option( self::DAILY_PROCESSED_OPTION, 0 );

		if ( $stored_date !== $today ) {
			$daily_count = 0;
			update_option( self::DAILY_DATE_OPTION, $today, false );
			update_option( self::DAILY_PROCESSED_OPTION, 0, false );
		}

		if ( $daily_cap > 0 && $daily_count >= $daily_cap ) {
			return;
		}

		if ( self::is_busy() ) {
			return;
		}

		$limit = isset( $options['auto_optimize_batch'] ) ? (int) $options['auto_optimize_batch'] : 3;
		$limit = min( 25, max( 1, $limit ) );
		if ( $daily_cap > 0 ) {
			$limit = min( $limit, $daily_cap - $daily_count );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'post_mime_type' => TIMU_IC::get_enabled_source_mimes(),
			)
		);

		if ( empty( $query->posts ) ) {
			return;
		}

		$processed = 0;
		foreach ( $query->posts as $post ) {
			if ( $processed >= $limit ) {
				break;
			}

			$file = get_attached_file( $post->ID );
			$mime = get_post_mime_type( $post->ID );
			if ( ! TIMU_IC_File_Ops::needs_processing( (int) $post->ID, (string) $file, (string) $mime ) ) {
				continue;
			}

			$result = TIMU_IC_File_Ops::process_attachment_for_cleanup( (int) $post->ID );
			if ( true === $result ) {
				$processed++;
			}
		}

		if ( $processed > 0 ) {
			update_option( self::DAILY_PROCESSED_OPTION, $daily_count + $processed, false );
			TIMU_IC::increment_stat( 'auto_runs', 1 );
			TIMU_IC::increment_stat( 'auto_processed', $processed );
			TIMU_IC::increment_stat( 'last_auto_context_' . sanitize_key( $context ), 1 );
		}
	}

	/**
	 * Enqueue a batch job via Action Scheduler or a transient queue.
	 *
	 * @param int[]  $ids    Attachment IDs.
	 * @param string $action 'cleanup' or 'restore'.
	 *
	 * @return void
	 */
	public static function enqueue_batch( $ids, $action = 'cleanup' ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		if ( self::has_action_scheduler() ) {
			as_enqueue_async_action(
				self::AS_BATCH_HOOK,
				array(
					'ids'    => $ids,
					'action' => sanitize_key( $action ),
				),
				self::AS_GROUP
			);
			return;
		}

		$queue   = (array) get_transient( 'timu_ic_batch_queue' );
		$queue[] = array(
			'ids'    => $ids,
			'action' => sanitize_key( $action ),
		);
		set_transient( 'timu_ic_batch_queue', $queue, DAY_IN_SECONDS );
	}

	/**
	 * Action Scheduler callback to process a batch.
	 *
	 * @param int[]  $ids    Attachment IDs.
	 * @param string $action 'cleanup' or 'restore'.
	 *
	 * @return void
	 */
	public static function handle_as_batch( $ids, $action = 'cleanup' ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		foreach ( $ids as $id ) {
			if ( 'restore' === $action ) {
				TIMU_IC_File_Ops::restore_image( $id );
			} else {
				TIMU_IC_File_Ops::process_attachment_for_cleanup( $id );
			}
		}
	}

	/**
	 * Whether the queue is busy (>5 pending AS actions).
	 *
	 * @return bool
	 */
	public static function is_busy() {
		if ( ! self::has_action_scheduler() ) {
			return false;
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::AS_BATCH_HOOK,
				'group'    => self::AS_GROUP,
				'status'   => 'pending',
				'per_page' => 6,
			),
			'ids'
		);

		return count( (array) $pending ) > 5;
	}

	/**
	 * Queue status summary.
	 *
	 * @return array Array with engine, pending, running, completed counts.
	 */
	public static function get_queue_status() {
		if ( ! self::has_action_scheduler() ) {
			return array(
				'engine'    => 'wp-cron',
				'pending'   => 0,
				'running'   => 0,
				'completed' => 0,
			);
		}

		$counts = array( 'engine' => 'action-scheduler' );
		foreach ( array( 'pending', 'in-progress', 'complete' ) as $status ) {
			$label           = 'in-progress' === $status ? 'running' : str_replace( 'complete', 'completed', $status );
			$actions         = as_get_scheduled_actions(
				array(
					'hook'     => self::AS_BATCH_HOOK,
					'group'    => self::AS_GROUP,
					'status'   => $status,
					'per_page' => -1,
				),
				'ids'
			);
			$counts[ $label ] = count( (array) $actions );
		}

		return $counts;
	}
}
