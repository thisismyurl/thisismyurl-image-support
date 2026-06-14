<?php
/**
 * WP-CLI commands for image cleanup, audit, restore, and merge.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage image cleanup and auditing via WP-CLI.
 *
 * ## EXAMPLES
 *
 *     wp timu-images audit
 *     wp timu-images cleanup --dry-run
 *     wp timu-images restore 123
 *     wp timu-images merge --dry-run
 */
class TIMU_IC_CLI extends WP_CLI_Command {

	/**
	 * Register the command with WP-CLI.
	 *
	 * @return void
	 */
	public static function init() {
		WP_CLI::add_command( 'timu-images', __CLASS__ );
	}

	/**
	 * Show an audit report: orphans, broken attachments, missing alt text.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp timu-images audit
	 *     wp timu-images audit --format=json
	 *
	 * @when after_wp_load
	 * @subcommand audit
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function audit( $args, $assoc_args ) {
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		WP_CLI::log( 'Scanning…' );

		$orphans = TIMU_IC_Audit::get_orphan_images();
		$broken  = TIMU_IC_Audit::get_broken_attachments();
		$no_alt  = TIMU_IC_Audit::get_missing_alt_text();

		$rows = array(
			array( 'metric' => 'Orphan image files',           'count' => count( $orphans ) ),
			array( 'metric' => 'Broken attachment records',    'count' => count( $broken ) ),
			array( 'metric' => 'Attachments missing alt text', 'count' => count( $no_alt ) ),
		);

		\WP_CLI\Utils\format_items( $format, $rows, array( 'metric', 'count' ) );
	}

	/**
	 * Run image cleanup: rename, resize, harden, dedupe.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Preview changes without writing files (default behaviour).
	 *
	 * [--no-dry-run]
	 * : Actually apply changes. Requires interactive confirmation.
	 *
	 * [--batch=<N>]
	 * : Number of attachments to process per run.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format for dry-run results.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp timu-images cleanup --dry-run
	 *     wp timu-images cleanup --no-dry-run --batch=20
	 *
	 * @when after_wp_load
	 * @subcommand cleanup
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function cleanup( $args, $assoc_args ) {
		$dry_run = ! isset( $assoc_args['no-dry-run'] );
		$batch   = max( 1, min( 200, (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 10 ) ) );
		$format  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! $dry_run ) {
			WP_CLI::confirm( 'This will rename, resize, and harden images on disk. Continue?' );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $batch,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'post_mime_type' => TIMU_IC::get_enabled_source_mimes(),
			)
		);

		if ( empty( $query->posts ) ) {
			WP_CLI::success( 'No attachments found.' );
			return;
		}

		$rows = array();
		$ok   = 0;
		$fail = 0;

		foreach ( $query->posts as $post ) {
			$file = get_attached_file( $post->ID );
			$mime = get_post_mime_type( $post->ID );

			if ( ! TIMU_IC_File_Ops::needs_processing( (int) $post->ID, (string) $file, (string) $mime ) ) {
				continue;
			}

			if ( $dry_run ) {
				$plan = TIMU_IC_File_Ops::plan_attachment_changes( $post->ID );
				if ( is_wp_error( $plan ) ) {
					$rows[] = array(
						'id'       => $post->ID,
						'current'  => basename( (string) $file ),
						'proposed' => 'ERROR: ' . $plan->get_error_message(),
						'resize'   => '-',
					);
					$fail++;
				} else {
					$rows[] = array(
						'id'       => $plan['attachment_id'],
						'current'  => $plan['current_filename'],
						'proposed' => $plan['proposed_filename'],
						'resize'   => $plan['needs_resize'],
					);
					$ok++;
				}
			} else {
				$result = TIMU_IC_File_Ops::process_attachment_for_cleanup( (int) $post->ID );
				if ( true === $result ) {
					$ok++;
					WP_CLI::log( sprintf( 'Processed attachment #%d', $post->ID ) );
				} else {
					$fail++;
					$msg = is_wp_error( $result ) ? $result->get_error_message() : 'Unknown error';
					WP_CLI::warning( sprintf( 'Failed #%d: %s', $post->ID, $msg ) );
				}
			}
		}

		if ( $dry_run && ! empty( $rows ) ) {
			\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'current', 'proposed', 'resize' ) );
		}

		WP_CLI::success(
			sprintf(
				'%d processed, %d failed.%s',
				$ok,
				$fail,
				$dry_run ? ' (dry run — no files written)' : ''
			)
		);
	}

	/**
	 * Restore an attachment to its original uploaded file.
	 *
	 * ## OPTIONS
	 *
	 * <attachment-id>
	 * : Attachment post ID to restore.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timu-images restore 123
	 *
	 * @when after_wp_load
	 * @subcommand restore
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args (unused).
	 *
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		if ( ! $id ) {
			WP_CLI::error( 'Please provide an attachment ID.' );
		}

		$result = TIMU_IC_File_Ops::restore_image( $id );
		if ( $result ) {
			WP_CLI::success( sprintf( 'Attachment #%d restored.', $id ) );
		} else {
			WP_CLI::error( sprintf( 'Could not restore #%d. No backup found or file error.', $id ) );
		}
	}

	/**
	 * Find and optionally merge obvious binary duplicate attachments.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Preview what would be merged (default behaviour).
	 *
	 * [--no-dry-run]
	 * : Actually merge duplicates. Requires interactive confirmation.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp timu-images merge --dry-run
	 *     wp timu-images merge --no-dry-run
	 *
	 * @when after_wp_load
	 * @subcommand merge
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function merge( $args, $assoc_args ) {
		global $wpdb;

		$dry_run = ! isset( $assoc_args['no-dry-run'] );
		$format  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! $dry_run ) {
			WP_CLI::confirm( 'This will permanently delete duplicate attachment records. Continue?' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hash_groups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS hash, GROUP_CONCAT(post_id ORDER BY post_id ASC) AS ids, COUNT(*) AS cnt
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = %s
				 GROUP BY meta_value
				 HAVING cnt > 1",
				TIMU_IC::HASH_META_KEY
			)
		);

		if ( empty( $hash_groups ) ) {
			WP_CLI::success( 'No duplicates found.' );
			return;
		}

		$rows = array();
		foreach ( $hash_groups as $row ) {
			$rows[] = array(
				'hash'  => substr( (string) $row->hash, 0, 12 ) . '…',
				'ids'   => $row->ids,
				'count' => $row->cnt,
			);
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'hash', 'ids', 'count' ) );

		if ( $dry_run ) {
			WP_CLI::log(
				sprintf( 'Found %d duplicate group(s). Use --no-dry-run to merge.', count( $hash_groups ) )
			);
			return;
		}

		$merged = 0;
		foreach ( $hash_groups as $row ) {
			$ids = array_filter( array_map( 'absint', explode( ',', (string) $row->ids ) ) );
			foreach ( $ids as $id ) {
				TIMU_IC_File_Ops::process_attachment_for_cleanup( $id );
				$merged++;
			}
		}

		WP_CLI::success( sprintf( 'Merge pass complete. Processed %d attachment(s).', $merged ) );
	}
}
