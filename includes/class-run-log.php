<?php
/**
 * Session-level run log for the destructive cleanup pipeline.
 *
 * Per-item safety (backup file, per-post revision, merge sidecar, vault
 * snapshot) already exists in the engine. This class is the thin layer that
 * ties those per-item records together into a batch "run" so an operator can
 * reverse a whole cleanup pass as one unit instead of restoring attachments
 * one at a time.
 *
 * It records only the references needed to reverse each operation; the actual
 * reversal reuses the engine's existing restore primitives
 * (TIMU_IC_File_Ops::restore_image() for rename/downscale, the duplicates
 * backup copy for un-merge). The run log never holds the only copy of anything.
 *
 * Storage is off-disk in a single autoload=no option, bounded to the last N
 * runs and an undo window so it never grows without limit.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records and reads bounded cleanup-run sessions.
 */
class TIMU_IC_Run_Log {

	/**
	 * Option storing the bounded list of runs. Autoload=no.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'thisismyurl_image_support_runs';

	/**
	 * The run currently open for recording, if any.
	 *
	 * Set by begin() and cleared by end(). While a run is open, record_item()
	 * appends to it. A null value means no batch is in progress, so individual
	 * upload-time optimisations are not folded into an undoable run.
	 *
	 * @var string|null
	 */
	private static $active_run_id = null;

	/**
	 * Default number of runs to retain.
	 *
	 * @var int
	 */
	const DEFAULT_KEEP = 10;

	/**
	 * Default undo window in seconds (7 days).
	 *
	 * @var int
	 */
	const DEFAULT_WINDOW = 604800;

	/**
	 * Number of runs to retain. Filterable.
	 *
	 * @return int
	 */
	public static function keep_count() {
		return (int) apply_filters( 'thisismyurl_image_support_run_log_keep', self::DEFAULT_KEEP );
	}

	/**
	 * Undo window in seconds. Filterable.
	 *
	 * @return int
	 */
	public static function window_seconds() {
		return (int) apply_filters( 'thisismyurl_image_support_run_log_window', self::DEFAULT_WINDOW );
	}

	/**
	 * Open a new run for recording and make it the active run.
	 *
	 * @param string $source Human-readable origin of the run, e.g. "optimize-batch".
	 *
	 * @return string The new run ID.
	 */
	public static function begin( $source = 'optimize-batch' ) {
		$run_id              = uniqid( 'run_', true );
		self::$active_run_id = $run_id;

		$runs = self::all();

		$runs[ $run_id ] = array(
			'run_id'     => $run_id,
			'source'     => sanitize_key( $source ),
			'started_at' => time(),
			'ended_at'   => 0,
			'undone_at'  => 0,
			'items'      => array(),
		);

		self::save( $runs );

		return $run_id;
	}

	/**
	 * Close the active run and prune the store.
	 *
	 * @return void
	 */
	public static function end() {
		$run_id = self::$active_run_id;
		if ( null === $run_id ) {
			return;
		}

		$runs = self::all();
		if ( isset( $runs[ $run_id ] ) ) {
			$runs[ $run_id ]['ended_at'] = time();
			self::save( self::prune( $runs ) );
		}

		self::$active_run_id = null;
	}

	/**
	 * Whether a run is currently open for recording.
	 *
	 * @return bool
	 */
	public static function is_recording() {
		return null !== self::$active_run_id;
	}

	/**
	 * Record one reversible operation against the active run.
	 *
	 * No-ops when no run is open, so upload-time and ad-hoc single-item
	 * optimisations never accidentally enter a batch's undo set.
	 *
	 * @param int    $attachment_id Attachment the operation acted on.
	 * @param string $operation     One of 'rename', 'downscale', 'merge', 'relink'.
	 * @param array  $payload       Operation-specific reversal references.
	 *
	 * @return void
	 */
	public static function record_item( $attachment_id, $operation, array $payload = array() ) {
		$run_id = self::$active_run_id;
		if ( null === $run_id ) {
			return;
		}

		$runs = self::all();
		if ( ! isset( $runs[ $run_id ] ) ) {
			return;
		}

		$runs[ $run_id ]['items'][] = array(
			'attachment_id' => (int) $attachment_id,
			'operation'     => sanitize_key( $operation ),
			'payload'       => $payload,
			'recorded_at'   => time(),
		);

		self::save( $runs );
	}

	/**
	 * Read every stored run, newest first.
	 *
	 * @return array<string,array> Map of run_id => run record.
	 */
	public static function all() {
		$runs = get_option( self::OPTION_KEY, array() );
		return is_array( $runs ) ? $runs : array();
	}

	/**
	 * Read a single run record.
	 *
	 * @param string $run_id Run ID.
	 *
	 * @return array|null
	 */
	public static function get( $run_id ) {
		$runs = self::all();
		return isset( $runs[ $run_id ] ) ? $runs[ $run_id ] : null;
	}

	/**
	 * Runs that are still inside the undo window and not yet undone, newest first.
	 *
	 * @return array<int,array>
	 */
	public static function undoable() {
		$cutoff = time() - self::window_seconds();
		$out    = array();

		foreach ( self::all() as $run ) {
			if ( ! empty( $run['undone_at'] ) ) {
				continue;
			}
			if ( empty( $run['items'] ) ) {
				continue;
			}
			$started = isset( $run['started_at'] ) ? (int) $run['started_at'] : 0;
			if ( $started < $cutoff ) {
				continue;
			}
			$out[] = $run;
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $b['started_at'] <=> (int) $a['started_at'];
			}
		);

		return $out;
	}

	/**
	 * Mark a run as undone so the UI stops offering it.
	 *
	 * @param string $run_id Run ID.
	 *
	 * @return void
	 */
	public static function mark_undone( $run_id ) {
		$runs = self::all();
		if ( isset( $runs[ $run_id ] ) ) {
			$runs[ $run_id ]['undone_at'] = time();
			self::save( $runs );
		}
	}

	/**
	 * Summarise the operations in a run for the UI panel.
	 *
	 * @param array $run Run record.
	 *
	 * @return array{count:int,by_operation:array<string,int>}
	 */
	public static function summarize( array $run ) {
		$by_operation = array();
		$items        = isset( $run['items'] ) ? (array) $run['items'] : array();

		foreach ( $items as $item ) {
			$op                  = isset( $item['operation'] ) ? (string) $item['operation'] : 'unknown';
			$by_operation[ $op ] = isset( $by_operation[ $op ] ) ? $by_operation[ $op ] + 1 : 1;
		}

		return array(
			'count'        => count( $items ),
			'by_operation' => $by_operation,
		);
	}

	/**
	 * Persist the run store. Autoload=no — this is operator tooling, never
	 * read on a front-end request.
	 *
	 * @param array $runs Run map.
	 *
	 * @return void
	 */
	private static function save( array $runs ) {
		update_option( self::OPTION_KEY, $runs, false );
	}

	/**
	 * Drop runs beyond the retained count or outside the undo window.
	 *
	 * Undone runs and runs older than the window are still pruned by count so a
	 * busy library cannot grow the option unbounded. Always keeps at least the
	 * newest keep_count() runs regardless of age, so a recent run stays
	 * inspectable even if the clock is skewed.
	 *
	 * @param array $runs Run map.
	 *
	 * @return array Pruned run map.
	 */
	private static function prune( array $runs ) {
		uasort(
			$runs,
			static function ( $a, $b ) {
				return (int) $b['started_at'] <=> (int) $a['started_at'];
			}
		);

		$keep    = max( 1, self::keep_count() );
		$cutoff  = time() - self::window_seconds();
		$kept    = array();
		$counter = 0;

		foreach ( $runs as $run_id => $run ) {
			++$counter;
			$started = isset( $run['started_at'] ) ? (int) $run['started_at'] : 0;

			// Always keep the newest N; drop older ones that are also past the window.
			if ( $counter <= $keep || $started >= $cutoff ) {
				$kept[ $run_id ] = $run;
			}
		}

		return $kept;
	}
}
