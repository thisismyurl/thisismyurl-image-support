<?php
/**
 * Admin UI, AJAX handlers, and CSV/JSON export actions.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All wp-admin surfaces: menu, settings, tabs, AJAX, exports.
 */
class TIMU_IC_Admin {

	/**
	 * Wire up all admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_environment_notice' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( TIMU_IC_FILE ),
			array( __CLASS__, 'add_plugin_action_links' )
		);

		// Standard AJAX actions.
		add_action( 'wp_ajax_timu_ic_process_batch', array( __CLASS__, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_timu_ic_restore_single', array( __CLASS__, 'ajax_restore_single' ) );
		add_action( 'wp_ajax_timu_ic_undo_run', array( __CLASS__, 'ajax_undo_run' ) );
		add_action( 'wp_ajax_timu_ic_dry_run_csv', array( __CLASS__, 'ajax_dry_run_csv' ) );
		add_action( 'wp_ajax_timu_ic_strip_exif', array( __CLASS__, 'ajax_strip_exif' ) );
		add_action( 'wp_ajax_timu_ic_reattach_bulk', array( __CLASS__, 'ajax_reattach_bulk' ) );
		add_action( 'wp_ajax_timu_ic_get_exif', array( __CLASS__, 'ajax_get_exif' ) );
		add_action( 'wp_ajax_timu_ic_recompute_score', array( __CLASS__, 'ajax_recompute_score' ) );

		// Metadata curation: alt editing + title/caption/description normalise.
		add_action( 'wp_ajax_timu_ic_save_alt', array( __CLASS__, 'ajax_save_alt' ) );
		add_action( 'wp_ajax_timu_ic_bulk_fill_alt', array( __CLASS__, 'ajax_bulk_fill_alt' ) );
		add_action( 'wp_ajax_timu_ic_normalize_preview', array( __CLASS__, 'ajax_normalize_preview' ) );
		add_action( 'wp_ajax_timu_ic_normalize_apply', array( __CLASS__, 'ajax_normalize_apply' ) );

		// Admin-post handlers for file downloads.
		add_action( 'admin_post_timu_ic_export_orphans_csv', array( __CLASS__, 'handle_export_orphans_csv' ) );
		add_action( 'admin_post_timu_ic_export_broken_csv', array( __CLASS__, 'handle_export_broken_csv' ) );
		add_action( 'admin_post_timu_ic_export_alt_text_csv', array( __CLASS__, 'handle_export_alt_text_csv' ) );
		add_action( 'admin_post_timu_ic_export_alt_text_json', array( __CLASS__, 'handle_export_alt_text_json' ) );
		add_action( 'admin_post_timu_is_vortops_save', array( __CLASS__, 'handle_vortops_save' ) );
		TIMU_Suite_Settings::register_ajax_handlers();

		// Media Library row action for EXIF inspection.
		add_filter( 'media_row_actions', array( __CLASS__, 'add_media_row_actions' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Menu, settings, assets, notices
	// -------------------------------------------------------------------------

	/**
	 * Register the Tools sub-menu page.
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		add_management_page(
			__( 'Image Support', 'thisismyurl-image-support' ),
			__( 'Image Support', 'thisismyurl-image-support' ),
			'manage_options',
			'thisismyurl-image-support',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Register the settings group.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			TIMU_IC::SETTINGS_GROUP,
			TIMU_IC::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'TIMU_IC_Options', 'sanitize' ),
				'default'           => TIMU_IC_Options::defaults(),
			)
		);
	}

	/**
	 * Enqueue admin JS and thickbox on relevant pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		// Thickbox for EXIF modal on Media Library.
		if ( 'upload.php' === $hook_suffix ) {
			add_thickbox();
		}

		if ( 'tools_page_thisismyurl-image-support' !== $hook_suffix ) {
			return;
		}

		add_thickbox();

		wp_enqueue_script(
			'timu-image-support-admin',
			TIMU_IMAGE_SUPPORT_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			TIMU_IMAGE_SUPPORT_VERSION,
			true
		);
	}

	/**
	 * Show an environment notice after activation if no image engine was found.
	 *
	 * @return void
	 */
	public static function maybe_show_environment_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = get_transient( 'timu_ic_activation_status' );
		if ( false !== $status ) {
			delete_transient( 'timu_ic_activation_status' );
		} else {
			$status = get_option( TIMU_IC::ENV_OPTION_KEY, array() );
		}

		if ( empty( $status ) || ! is_array( $status ) ) {
			return;
		}

		if ( empty( $status['has_imagick'] ) && empty( $status['has_gd'] ) ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'Image Support requires GD or Imagick. Neither image engine was detected, so image optimization tasks cannot run.',
				'thisismyurl-image-support'
			);
			echo '</p></div>';
		}
	}

	/**
	 * Add Settings and Donate links in the plugin row.
	 *
	 * @param array $links Existing links.
	 *
	 * @return array
	 */
	public static function add_plugin_action_links( $links ) {
		$settings_url = admin_url( 'tools.php?page=thisismyurl-image-support&tab=settings' );
		$donate_url   = TIMU_IC::get_thisismyurl_link( 'https://thisismyurl.com/donate/', 'plugin_row_donate' );

		$custom_links = array(
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'thisismyurl-image-support' ) . '</a>',
			'<a href="' . esc_url( $donate_url ) . '" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'Sponsor', 'thisismyurl-image-support' ) . '</a>',
		);

		return array_merge( $custom_links, $links );
	}

	/**
	 * Add an "Inspect EXIF" row action on the Media Library screen.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    Attachment post.
	 *
	 * @return array
	 */
	public static function add_media_row_actions( $actions, $post ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		if ( 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			return $actions;
		}

		$exif_url = add_query_arg(
			array(
				'action'        => 'timu_ic_get_exif',
				'attachment_id' => $post->ID,
				'nonce'         => wp_create_nonce( TIMU_IC::AJAX_NONCE_ACTION ),
				'TB_iframe'     => 'true',
				'width'         => 600,
				'height'        => 500,
			),
			admin_url( 'admin-ajax.php' )
		);

		$actions['timu_exif'] = '<a href="' . esc_url( $exif_url ) . '" class="thickbox" title="'
			. esc_attr__( 'Inspect EXIF data', 'thisismyurl-image-support' ) . '">'
			. esc_html__( 'Inspect EXIF', 'thisismyurl-image-support' )
			. '</a>';

		return $actions;
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: process a batch of attachment IDs.
	 *
	 * @return void
	 */
	public static function ajax_process_batch() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$batch_limit = TIMU_IC_Options::get_batch_size();
		$ids         = isset( $_POST['attachment_ids'] ) ? (array) $_POST['attachment_ids'] : array();
		$ids         = array_slice( array_values( array_filter( array_map( 'absint', $ids ) ) ), 0, $batch_limit );

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No attachments were provided for batch processing.', 'thisismyurl-image-support' ) );
		}

		// Run the batch inside a recorded session so the whole pass can be
		// reversed as a unit from the "Recent runs" panel.
		$run = TIMU_IC_File_Ops::run_batch( $ids, 'optimize-batch' );

		wp_send_json_success(
			array(
				'run_id'        => $run['run_id'],
				'processed_ids' => $run['processed'],
				'failed_ids'    => $run['failed'],
				'errors'        => $run['errors'],
			)
		);
	}

	/**
	 * AJAX: reverse a recorded cleanup run as a unit.
	 *
	 * Restorative action — allowed even when destructive ops are currently off,
	 * since it only reverses writes the pipeline already made.
	 *
	 * @return void
	 */
	public static function ajax_undo_run() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		if ( '' === $run_id ) {
			wp_send_json_error( __( 'No cleanup run was specified.', 'thisismyurl-image-support' ) );
		}

		$result = TIMU_IC_File_Ops::undo_run( $run_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: restore a single attachment.
	 *
	 * @return void
	 */
	public static function ajax_restore_single() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'thisismyurl-image-support' ) );
		}

		if ( TIMU_IC_File_Ops::restore_image( $attachment_id ) ) {
			wp_send_json_success();
		}

		wp_send_json_error( __( 'Image could not be restored.', 'thisismyurl-image-support' ) );
	}

	/**
	 * AJAX: dry-run — return proposed changes as JSON for client-side CSV download.
	 *
	 * @return void
	 */
	public static function ajax_dry_run_csv() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$ids = isset( $_POST['attachment_ids'] ) ? (array) $_POST['attachment_ids'] : array();
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No attachments provided.', 'thisismyurl-image-support' ) );
		}

		$rows = array();
		foreach ( $ids as $id ) {
			$plan   = TIMU_IC_File_Ops::plan_attachment_changes( $id );
			$rows[] = is_wp_error( $plan )
				? array(
					'attachment_id'      => $id,
					'current_filename'   => '',
					'proposed_filename'  => 'ERROR: ' . $plan->get_error_message(),
					'current_dimensions' => '',
					'needs_resize'       => '',
				)
				: $plan;
		}

		wp_send_json_success( $rows );
	}

	/**
	 * AJAX: strip identifying EXIF from an attachment (preserves copyright).
	 *
	 * @return void
	 */
	public static function ajax_strip_exif() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'thisismyurl-image-support' ) );
		}

		$result = TIMU_IC_Audit::strip_exif( $attachment_id );
		if ( true === $result ) {
			wp_send_json_success();
		}

		$msg = is_wp_error( $result ) ? $result->get_error_message() : __( 'EXIF strip failed.', 'thisismyurl-image-support' );
		wp_send_json_error( $msg );
	}

	/**
	 * AJAX: bulk re-attach unattached images to their parent posts.
	 *
	 * @return void
	 */
	public static function ajax_reattach_bulk() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$pairs   = isset( $_POST['pairs'] ) ? (array) $_POST['pairs'] : array();
		$dry_run = isset( $_POST['dry_run'] ) ? rest_sanitize_boolean( $_POST['dry_run'] ) : true;

		// Sanitize each pair.
		$clean_pairs = array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$att = isset( $pair['attachment_id'] ) ? absint( $pair['attachment_id'] ) : 0;
			$par = isset( $pair['parent_id'] ) ? absint( $pair['parent_id'] ) : 0;
			if ( $att && $par ) {
				$clean_pairs[] = array(
					'attachment_id' => $att,
					'parent_id'     => $par,
				);
			}
		}

		if ( empty( $clean_pairs ) ) {
			wp_send_json_error( __( 'No valid pairs provided.', 'thisismyurl-image-support' ) );
		}

		$result = TIMU_IC_Audit::reattach_bulk( $clean_pairs, $dry_run );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: return EXIF data as HTML for the thickbox modal.
	 *
	 * @return void
	 */
	public static function ajax_get_exif() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'thisismyurl-image-support' ) );
		}

		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_die( esc_html__( 'Invalid attachment ID.', 'thisismyurl-image-support' ) );
		}

		$exif     = TIMU_IC_Audit::get_exif_data( $attachment_id );
		$has_gps  = TIMU_IC_Audit::exif_has_gps( $exif );
		$title    = get_the_title( $attachment_id );
		$nonce    = wp_create_nonce( TIMU_IC::AJAX_NONCE_ACTION );

		// Thickbox modal output — intentionally minimal HTML, not a full page.
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<title><?php echo esc_html( $title ); ?> — EXIF</title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 13px; padding: 20px; margin: 0; }
				table { border-collapse: collapse; width: 100%; }
				th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
				th { width: 40%; color: #23282d; }
				td { word-break: break-all; }
				.gps-badge { background: #d63638; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 11px; }
				h2 { margin-top: 0; font-size: 15px; }
				.strip-btn { margin-top: 12px; }
				.notice { padding: 8px 12px; border-left: 4px solid #72aee6; background: #f0f6fc; margin-bottom: 12px; }
			</style>
		</head>
		<body>
		<h2>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: attachment title */
					__( 'EXIF/IPTC — %s', 'thisismyurl-image-support' ),
					$title
				)
			);
			?>
			<?php if ( $has_gps ) : ?>
				<span class="gps-badge"><?php esc_html_e( 'GPS', 'thisismyurl-image-support' ); ?></span>
			<?php endif; ?>
		</h2>

		<?php if ( empty( $exif ) ) : ?>
			<p class="notice"><?php esc_html_e( 'No EXIF data found or format not supported.', 'thisismyurl-image-support' ); ?></p>
		<?php else : ?>
			<table>
				<tbody>
					<?php foreach ( $exif as $section => $values ) : ?>
						<?php if ( is_array( $values ) ) : ?>
							<tr><th colspan="2"><strong><?php echo esc_html( $section ); ?></strong></th></tr>
							<?php foreach ( $values as $key => $val ) : ?>
								<tr>
									<th><?php echo esc_html( $key ); ?></th>
									<td><?php echo esc_html( is_array( $val ) ? implode( ', ', $val ) : (string) $val ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<th><?php echo esc_html( $section ); ?></th>
								<td><?php echo esc_html( (string) $values ); ?></td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $has_gps && extension_loaded( 'imagick' ) ) : ?>
			<p class="strip-btn">
				<button id="timu-strip-exif" class="button button-secondary"
					data-id="<?php echo esc_attr( $attachment_id ); ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
					data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<?php esc_html_e( 'Strip GPS / Serial (keep copyright)', 'thisismyurl-image-support' ); ?>
				</button>
				<span id="timu-strip-msg" style="margin-left:8px;"></span>
			</p>
			<script>
			document.getElementById('timu-strip-exif').addEventListener('click', function () {
				var btn = this;
				btn.disabled = true;
				var fd = new FormData();
				fd.append('action', 'timu_ic_strip_exif');
				fd.append('attachment_id', btn.dataset.id);
				fd.append('nonce', btn.dataset.nonce);
				fetch(btn.dataset.ajax, { method: 'POST', body: fd })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						document.getElementById('timu-strip-msg').textContent = res.success ? '<?php echo esc_js( __( 'Done.', 'thisismyurl-image-support' ) ); ?>' : (res.data || '<?php echo esc_js( __( 'Error.', 'thisismyurl-image-support' ) ); ?>');
					})
					.catch(function () {
						document.getElementById('timu-strip-msg').textContent = '<?php echo esc_js( __( 'Request failed.', 'thisismyurl-image-support' ) ); ?>';
					});
			});
			</script>
		<?php endif; ?>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * AJAX: recompute the Library Health Score and return the fresh payload.
	 *
	 * @return void
	 */
	public static function ajax_recompute_score() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		TIMU_IC_Score::flush();
		$score = TIMU_IC_Score::compute();

		wp_send_json_success(
			array(
				'overall'    => (int) $score['overall'],
				'band_label' => (string) $score['band_label'],
				'summary'    => (string) $score['summary'],
			)
		);
	}

	// -------------------------------------------------------------------------
	// AJAX handlers — metadata curation (alt + normalise)
	// -------------------------------------------------------------------------

	/**
	 * AJAX: save alt text on a single attachment.
	 *
	 * @return void
	 */
	public static function ajax_save_alt() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$att_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $att_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'thisismyurl-image-support' ) );
		}

		// Sanitised again inside save_alt_text(); unslash here for the round-trip.
		$alt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';

		$result = TIMU_IC_Curation::save_alt_text( $att_id, $alt );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'attachment_id' => $att_id,
				'alt'           => (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
			)
		);
	}

	/**
	 * AJAX: bulk-fill alt text on a set of attachments from a chosen source.
	 *
	 * @return void
	 */
	public static function ajax_bulk_fill_alt() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$ids      = isset( $_POST['attachment_ids'] ) ? (array) wp_unslash( $_POST['attachment_ids'] ) : array();
		$source   = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'title';
		$template = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : '';

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No attachments were provided.', 'thisismyurl-image-support' ) );
		}

		$result = TIMU_IC_Curation::bulk_fill_alt( $ids, $source, $template );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: preview a normalisation batch (no writes).
	 *
	 * @return void
	 */
	public static function ajax_normalize_preview() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$ids  = isset( $_POST['attachment_ids'] ) ? (array) wp_unslash( $_POST['attachment_ids'] ) : array();
		$opts = self::read_normalize_opts();

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No attachments were provided.', 'thisismyurl-image-support' ) );
		}

		$result = TIMU_IC_Curation::bulk_normalize( $ids, $opts, true );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: apply a normalisation batch (writes attachment fields).
	 *
	 * @return void
	 */
	public static function ajax_normalize_apply() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$ids  = isset( $_POST['attachment_ids'] ) ? (array) wp_unslash( $_POST['attachment_ids'] ) : array();
		$opts = self::read_normalize_opts();

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No attachments were provided.', 'thisismyurl-image-support' ) );
		}

		$result = TIMU_IC_Curation::bulk_normalize( $ids, $opts, false );

		wp_send_json_success( $result );
	}

	/**
	 * Read the normalise opt-ins and templates from the POST body.
	 *
	 * The per-field booleans gate which attachment fields a run touches; the
	 * templates are sanitised here and again inside the curation class.
	 *
	 * @return array Opts array consumed by TIMU_IC_Curation::bulk_normalize().
	 */
	private static function read_normalize_opts() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verified the nonce.
		return array(
			'title'                => ! empty( $_POST['do_title'] ),
			'caption'              => ! empty( $_POST['do_caption'] ),
			'description'          => ! empty( $_POST['do_description'] ),
			'title_template'       => isset( $_POST['title_template'] ) ? sanitize_text_field( wp_unslash( $_POST['title_template'] ) ) : '',
			'caption_template'     => isset( $_POST['caption_template'] ) ? sanitize_text_field( wp_unslash( $_POST['caption_template'] ) ) : '',
			'description_template' => isset( $_POST['description_template'] ) ? sanitize_text_field( wp_unslash( $_POST['description_template'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	// -------------------------------------------------------------------------
	// Admin-post download handlers
	// -------------------------------------------------------------------------

	/**
	 * Stream orphan list as CSV.
	 *
	 * @return void
	 */
	public static function handle_export_orphans_csv() {
		check_admin_referer( 'timu_ic_export_orphans' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'thisismyurl-image-support' ) );
		}

		$orphans    = TIMU_IC_Audit::get_orphan_images();
		$upload_dir = wp_upload_dir();
		$basedir    = trailingslashit( $upload_dir['basedir'] );

		self::stream_csv(
			'timu-orphans-' . gmdate( 'Y-m-d' ) . '.csv',
			array( 'relative_path', 'size_bytes', 'modified' ),
			array_map(
				static function ( $abs ) use ( $basedir ) {
					return array(
						ltrim( str_replace( $basedir, '', $abs ), '/' ),
						file_exists( $abs ) ? filesize( $abs ) : '',
						file_exists( $abs ) ? gmdate( 'Y-m-d H:i:s', filemtime( $abs ) ) : '',
					);
				},
				$orphans
			)
		);
	}

	/**
	 * Stream broken attachment list as CSV.
	 *
	 * @return void
	 */
	public static function handle_export_broken_csv() {
		check_admin_referer( 'timu_ic_export_broken' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'thisismyurl-image-support' ) );
		}

		$broken = TIMU_IC_Audit::get_broken_attachments();

		self::stream_csv(
			'timu-broken-' . gmdate( 'Y-m-d' ) . '.csv',
			array( 'attachment_id', 'title', 'expected_path' ),
			array_map(
				static function ( $post ) {
					return array(
						$post->ID,
						$post->post_title,
						(string) get_attached_file( $post->ID ),
					);
				},
				$broken
			)
		);
	}

	/**
	 * Stream missing alt-text list as CSV.
	 *
	 * @return void
	 */
	public static function handle_export_alt_text_csv() {
		check_admin_referer( 'timu_ic_export_alt_text' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'thisismyurl-image-support' ) );
		}

		$ids = TIMU_IC_Audit::get_missing_alt_text();

		self::stream_csv(
			'timu-missing-alt-' . gmdate( 'Y-m-d' ) . '.csv',
			array( 'attachment_id', 'title', 'filename', 'url' ),
			array_map(
				static function ( $id ) {
					return array(
						$id,
						get_the_title( $id ),
						basename( (string) get_attached_file( $id ) ),
						(string) wp_get_attachment_url( $id ),
					);
				},
				$ids
			)
		);
	}

	/**
	 * Stream missing alt-text list as JSON.
	 *
	 * @return void
	 */
	public static function handle_export_alt_text_json() {
		check_admin_referer( 'timu_ic_export_alt_text' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'thisismyurl-image-support' ) );
		}

		$ids  = TIMU_IC_Audit::get_missing_alt_text();
		$rows = array_map(
			static function ( $id ) {
				return array(
					'attachment_id' => $id,
					'title'         => get_the_title( $id ),
					'filename'      => basename( (string) get_attached_file( $id ) ),
					'url'           => (string) wp_get_attachment_url( $id ),
				);
			},
			$ids
		);

		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="timu-missing-alt-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $rows, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Handle admin-post save for the Vortops API key.
	 *
	 * @return void
	 */
	public static function handle_vortops_save() {
		check_admin_referer( 'timu_is_vortops_save', 'timu_is_vortops_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'thisismyurl-image-support' ) );
		}
		$api_key = isset( $_POST['timu_vortops_api_key'] )
			? sanitize_text_field( wp_unslash( $_POST['timu_vortops_api_key'] ) )
			: '';
		if ( '' !== $api_key ) {
			update_option( TIMU_Vortops_Client::OPTION_KEY, $api_key, false );
		} else {
			delete_option( TIMU_Vortops_Client::OPTION_KEY );
		}
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'thisismyurl-image-support', 'tab' => 'settings', 'vortops-saved' => '1' ),
			admin_url( 'tools.php' )
		) );
		exit;
	}

	/**
	 * Helper: emit CSV headers and rows then exit.
	 *
	 * @param string $filename CSV filename.
	 * @param array  $headers  Column headers.
	 * @param array  $rows     Data rows (each row is a plain array).
	 *
	 * @return void
	 */
	private static function stream_csv( $filename, $headers, $rows ) {
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		// BOM for Excel UTF-8 compatibility.
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, $headers );

		foreach ( $rows as $row ) {
			fputcsv( $out, array_values( (array) $row ) );
		}

		fclose( $out );
		exit;
	}

	// -------------------------------------------------------------------------
	// Admin page rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the full admin page (dispatches to tab renderers).
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'thisismyurl-image-support' ) );
		}

		$allowed_tabs = array( 'health', 'optimize', 'organize', 'settings', 'report', 'audit' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'health';
		if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
			$active_tab = 'health';
		}

		$options         = TIMU_IC_Options::get();
		$base_url        = admin_url( 'tools.php?page=thisismyurl-image-support' );
		$thisismyurl_url = TIMU_IC::get_thisismyurl_link( 'https://thisismyurl.com/', 'plugin_header' );
		$donate_url      = TIMU_IC::get_thisismyurl_link( 'https://thisismyurl.com/donate/', 'plugin_sidebar_donate' );

		// Inline JS data for optimize tab.
		if ( 'optimize' === $active_tab ) {
			$lists       = TIMU_IC_File_Ops::get_media_lists();
			$pending_ids = array_map(
				static function ( $p ) {
					return (int) $p->ID;
				},
				$lists['pending']
			);

			$restorable = array();
			foreach ( $lists['media'] as $p ) {
				if ( get_post_meta( $p->ID, TIMU_IC::ORIGINAL_PATH_KEY, true ) ) {
					$restorable[] = (int) $p->ID;
				}
			}

			wp_add_inline_script(
				'timu-image-support-admin',
				'window.TIMUImageSupportData = ' . wp_json_encode(
					array(
						'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
						'nonce'      => wp_create_nonce( TIMU_IC::AJAX_NONCE_ACTION ),
						'actions'    => array(
							'batch'    => 'timu_ic_process_batch',
							'restore'  => 'timu_ic_restore_single',
							'dryRun'   => 'timu_ic_dry_run_csv',
							'stripExif' => 'timu_ic_strip_exif',
							'reattach' => 'timu_ic_reattach_bulk',
							'undoRun'  => 'timu_ic_undo_run',
						),
						'batchSize'  => TIMU_IC_Options::get_batch_size(),
						'perPage'    => (int) $options['list_per_page'],
						'pendingIds' => $pending_ids,
						'strings'    => array(
							'processing'        => __( 'Processing…', 'thisismyurl-image-support' ),
							'restoring'         => __( 'Restoring…', 'thisismyurl-image-support' ),
							'confirmRestoreAll' => __( 'Restore all images? This cannot be undone.', 'thisismyurl-image-support' ),
							'failedPrefix'      => __( 'Some images failed:', 'thisismyurl-image-support' ),
							'confirmUndoRun'    => __( 'Undo this cleanup run? Every file, name, and content link it changed will be restored.', 'thisismyurl-image-support' ),
							'undoing'           => __( 'Undoing…', 'thisismyurl-image-support' ),
							'undoFailed'        => __( 'Undo failed.', 'thisismyurl-image-support' ),
						),
					)
				) . ';',
				'before'
			);
		}

		// Inline JS data for the Organize tab (create folder + assign selected).
		if ( 'organize' === $active_tab ) {
			wp_add_inline_script(
				'timu-image-support-admin',
				'window.TIMUImageSupportData = ' . wp_json_encode(
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( TIMU_IC::AJAX_NONCE_ACTION ),
						'actions' => array(
							'createFolder' => 'timu_ic_create_folder',
							'assignFolder' => 'timu_ic_assign_folder',
						),
						'strings' => array(
							'working'       => __( 'Working…', 'thisismyurl-image-support' ),
							'requestFailed' => __( 'Request failed.', 'thisismyurl-image-support' ),
							'needName'      => __( 'Enter a folder name first.', 'thisismyurl-image-support' ),
							'needFolder'    => __( 'Choose a folder first.', 'thisismyurl-image-support' ),
							'needSelection' => __( 'Select at least one image first.', 'thisismyurl-image-support' ),
						),
					)
				) . ';',
				'before'
			);
		}

		// Inline JS data for the audit tab's curation tools (alt edit + normalise).
		if ( 'audit' === $active_tab ) {
			wp_add_inline_script(
				'timu-image-support-admin',
				'window.TIMUImageSupportData = ' . wp_json_encode(
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( TIMU_IC::AJAX_NONCE_ACTION ),
						'actions' => array(
							'saveAlt'          => 'timu_ic_save_alt',
							'bulkFillAlt'      => 'timu_ic_bulk_fill_alt',
							'normalizePreview' => 'timu_ic_normalize_preview',
							'normalizeApply'   => 'timu_ic_normalize_apply',
						),
						'strings' => array(
							'saving'        => __( 'Saving…', 'thisismyurl-image-support' ),
							'saved'         => __( 'Saved', 'thisismyurl-image-support' ),
							'saveFailed'    => __( 'Save failed', 'thisismyurl-image-support' ),
							'selectAtLeast' => __( 'Select at least one image first.', 'thisismyurl-image-support' ),
							'working'       => __( 'Working…', 'thisismyurl-image-support' ),
							'requestFailed' => __( 'Request failed.', 'thisismyurl-image-support' ),
							'noChanges'     => __( 'No changes to apply for the selected images.', 'thisismyurl-image-support' ),
						),
					)
				) . ';',
				'before'
			);
		}

		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Image Support', 'thisismyurl-image-support' ); ?>
				<span style="font-size:0.5em;font-weight:normal;vertical-align:middle;margin-left:10px;color:#646970;">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: link to thisismyurl.com */
							__( 'by %s', 'thisismyurl-image-support' ),
							'<a href="' . esc_url( $thisismyurl_url ) . '" target="_blank" rel="noopener noreferrer" style="text-decoration:none;color:inherit;">thisismyurl.com</a>'
						)
					);
					?>
				</span>
			</h1>

			<nav class="nav-tab-wrapper wp-clearfix">
				<?php
				$tabs = array(
					'health'   => __( 'Health', 'thisismyurl-image-support' ),
					'optimize' => __( 'Optimize', 'thisismyurl-image-support' ),
					'organize' => __( 'Organize', 'thisismyurl-image-support' ),
					'audit'    => __( 'Audit', 'thisismyurl-image-support' ),
					'settings' => __( 'Settings', 'thisismyurl-image-support' ),
					'report'   => __( 'Report', 'thisismyurl-image-support' ),
				);
				foreach ( $tabs as $slug => $label ) {
					$url     = add_query_arg( 'tab', $slug, $base_url );
					$is_active = $active_tab === $slug;
					if ( 'optimize' === $slug && 'optimize' === $active_tab && isset( $lists ) && ! empty( $lists['pending'] ) ) {
						$badge = '<span class="awaiting-mod" style="margin-left:4px;">' . esc_html( count( $lists['pending'] ) ) . '</span>';
					} else {
						$badge = '';
					}
					echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $is_active ? ' nav-tab-active' : '' ) . '">'
						. esc_html( $label ) . wp_kses_post( $badge ) . '</a>';
				}
				?>
			</nav>

			<?php
			switch ( $active_tab ) {
				case 'health':
					self::render_health_tab( $base_url );
					break;
				case 'optimize':
					self::render_optimize_tab( $lists, $options, $pending_ids, $restorable, $base_url, $thisismyurl_url, $donate_url );
					break;
				case 'organize':
					self::render_organize_tab( $base_url );
					break;
				case 'audit':
					self::render_audit_tab( $base_url );
					break;
				case 'settings':
					self::render_settings_tab( $options );
					break;
				default:
					self::render_report_tab( $base_url );
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Resolve the "Review" link target for a health factor.
	 *
	 * Each factor points at the closest existing surface that lets the operator
	 * act on it. Where no dedicated view exists yet, the link routes to the
	 * nearest tab and the caller surfaces a short note via the returned label.
	 *
	 * @param string $factor_key Factor key.
	 * @param string $base_url   Plugin admin base URL.
	 *
	 * @return array{url:string,label:string,note:string}
	 */
	private static function health_factor_action( $factor_key, $base_url ) {
		$audit_url = add_query_arg( 'tab', 'audit', $base_url );

		switch ( $factor_key ) {
			case 'missing_alt':
				return array(
					'url'   => $audit_url . '#audit-alt-table',
					'label' => __( 'Review missing alt text', 'thisismyurl-image-support' ),
					'note'  => '',
				);
			case 'gps_privacy':
				return array(
					'url'   => admin_url( 'upload.php' ),
					'label' => __( 'Inspect EXIF in Media Library', 'thisismyurl-image-support' ),
					'note'  => __( 'Use the "Inspect EXIF" row action to strip GPS per image.', 'thisismyurl-image-support' ),
				);
			case 'junk_names':
				return array(
					'url'   => add_query_arg( 'tab', 'optimize', $base_url ),
					'label' => __( 'Review filenames in Optimize', 'thisismyurl-image-support' ),
					'note'  => '',
				);
			case 'oversized':
				return array(
					'url'   => add_query_arg( 'tab', 'optimize', $base_url ),
					'label' => __( 'Downscale in Optimize', 'thisismyurl-image-support' ),
					'note'  => '',
				);
			case 'orphans':
				return array(
					'url'   => $audit_url,
					'label' => __( 'Review in Audit', 'thisismyurl-image-support' ),
					'note'  => __( 'Unused images are attachments referenced nowhere. A dedicated list is planned; the Audit tab covers related cleanup today.', 'thisismyurl-image-support' ),
				);
			case 'duplicates':
				return array(
					'url'   => add_query_arg( 'tab', 'optimize', $base_url ),
					'label' => __( 'Find duplicates in Optimize', 'thisismyurl-image-support' ),
					'note'  => __( 'A dedicated duplicate finder view is planned; Optimize merges duplicates today.', 'thisismyurl-image-support' ),
				);
			default:
				return array(
					'url'   => $audit_url,
					'label' => __( 'Review in Audit', 'thisismyurl-image-support' ),
					'note'  => '',
				);
		}
	}

	/**
	 * Render the Health tab — the Library Health Score dashboard.
	 *
	 * @param string $base_url Plugin admin base URL.
	 *
	 * @return void
	 */
	private static function render_health_tab( $base_url ) {
		$score   = TIMU_IC_Score::get();
		$overall = (int) $score['overall'];
		$nonce   = wp_create_nonce( TIMU_IC::AJAX_NONCE_ACTION );
		?>
		<div id="poststuff" style="padding-top:10px;">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">

					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Library Health Score', 'thisismyurl-image-support' ); ?></span></h2>
						<div class="inside">
							<div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;padding:8px 0;">
								<div style="text-align:center;min-width:140px;" role="img"
									aria-label="<?php echo esc_attr( sprintf( /* translators: 1: score 0-100, 2: band label */ __( 'Library health score %1$d out of 100, rated %2$s.', 'thisismyurl-image-support' ), $overall, $score['band_label'] ) ); ?>">
									<div style="font-size:64px;line-height:1;font-weight:600;color:#1d2327;" aria-hidden="true">
										<?php echo esc_html( number_format_i18n( $overall ) ); ?>
									</div>
									<div style="font-size:13px;color:#646970;margin-top:4px;" aria-hidden="true">
										<?php esc_html_e( 'out of 100', 'thisismyurl-image-support' ); ?>
									</div>
									<p style="margin:10px 0 0;font-size:15px;font-weight:600;">
										<span class="timu-band timu-band-<?php echo esc_attr( $score['band'] ); ?>" style="display:inline-block;padding:3px 12px;border-radius:12px;background:#f0f0f1;color:#1d2327;">
											<?php echo esc_html( $score['band_label'] ); ?>
										</span>
									</p>
								</div>
								<div style="flex:1;min-width:240px;">
									<p style="font-size:15px;margin-top:0;"><?php echo esc_html( $score['summary'] ); ?></p>
									<p style="margin-bottom:0;">
										<button id="timu-recompute-score" class="button button-secondary"
											data-nonce="<?php echo esc_attr( $nonce ); ?>"
											data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
											<?php esc_html_e( 'Recompute', 'thisismyurl-image-support' ); ?>
										</button>
										<span id="timu-recompute-msg" style="margin-left:8px;color:#646970;"></span>
									</p>
									<p class="description" style="margin-bottom:0;">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: image count, 2: human-readable time-ago */
												__( 'Based on %1$s images. Last computed %2$s ago.', 'thisismyurl-image-support' ),
												number_format_i18n( (int) $score['total_images'] ),
												human_time_diff( (int) $score['computed_at'], time() )
											)
										);
										?>
									</p>
								</div>
							</div>
						</div>
					</div>

					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Breakdown', 'thisismyurl-image-support' ); ?></span></h2>
						<div class="inside">
							<table class="widefat striped" style="border:none;box-shadow:none;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Factor', 'thisismyurl-image-support' ); ?></th>
										<th style="width:120px;"><?php esc_html_e( 'Affected', 'thisismyurl-image-support' ); ?></th>
										<th style="width:90px;"><?php esc_html_e( 'Score', 'thisismyurl-image-support' ); ?></th>
										<th style="width:200px;"><?php esc_html_e( 'Action', 'thisismyurl-image-support' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $score['factors'] as $factor ) : ?>
										<?php
										$action  = self::health_factor_action( $factor['key'], $base_url );
										$sampled = ! empty( $factor['sampled'] );
										?>
										<tr>
											<td>
												<strong><?php echo esc_html( $factor['label'] ); ?></strong>
												<p class="description" style="margin:2px 0 0;"><?php echo esc_html( $factor['description'] ); ?></p>
												<?php if ( '' !== $action['note'] ) : ?>
													<p class="description" style="margin:2px 0 0;color:#996800;"><?php echo esc_html( $action['note'] ); ?></p>
												<?php endif; ?>
											</td>
											<td>
												<?php
												echo esc_html(
													sprintf(
														/* translators: 1: affected count, 2: total */
														_n( '%1$s of %2$s', '%1$s of %2$s', (int) $factor['affected'], 'thisismyurl-image-support' ),
														number_format_i18n( (int) $factor['affected'] ),
														number_format_i18n( (int) $factor['total'] )
													)
												);
												?>
												<br>
												<span class="description"><?php echo esc_html( number_format_i18n( (float) $factor['percent'], 1 ) . '%' ); ?><?php echo $sampled ? esc_html__( ' (sampled)', 'thisismyurl-image-support' ) : ''; ?></span>
											</td>
											<td>
												<strong><?php echo esc_html( number_format_i18n( (int) $factor['sub_score'] ) ); ?></strong>
												<span class="description">/100</span>
											</td>
											<td>
												<a href="<?php echo esc_url( $action['url'] ); ?>" class="button button-small">
													<?php echo esc_html( $action['label'] ); ?>
												</a>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>

				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'How the score works', 'thisismyurl-image-support' ); ?></span></h2>
						<div class="inside">
							<p class="description">
								<?php esc_html_e( 'The score is a weighted average of six curation factors. Alt text and GPS privacy carry the most weight, followed by filenames and dimensions, then unused images and duplicates.', 'thisismyurl-image-support' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'It is cached and refreshed daily. Use Recompute after a cleanup run to see the result right away.', 'thisismyurl-image-support' ); ?>
							</p>
						</div>
					</div>
				</div>

			</div>
		</div>

		<script>
		jQuery(function($){
			'use strict';
			$('#timu-recompute-score').on('click', function(){
				var $btn = $(this);
				$btn.prop('disabled', true);
				$('#timu-recompute-msg').text('<?php echo esc_js( __( 'Recomputing…', 'thisismyurl-image-support' ) ); ?>');
				$.post($btn.data('ajax'), {
					action: 'timu_ic_recompute_score',
					nonce: $btn.data('nonce')
				}).done(function(res){
					if (res && res.success && res.data) {
						$('#timu-recompute-msg').text('<?php echo esc_js( __( 'Updated. Reloading…', 'thisismyurl-image-support' ) ); ?>');
						setTimeout(function(){ location.reload(); }, 600);
					} else {
						$('#timu-recompute-msg').text('<?php echo esc_js( __( 'Could not recompute.', 'thisismyurl-image-support' ) ); ?>');
						$btn.prop('disabled', false);
					}
				}).fail(function(){
					$('#timu-recompute-msg').text('<?php echo esc_js( __( 'Request failed.', 'thisismyurl-image-support' ) ); ?>');
					$btn.prop('disabled', false);
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render the "Recent runs" panel with a per-run undo control.
	 *
	 * Shows each undoable cleanup run inside the undo window — when it ran, how
	 * many items it touched, and what those operations were — with an "Undo this
	 * run" button per row. The button's handler lives in assets/js/admin.js.
	 *
	 * @return void
	 */
	private static function render_recent_runs_panel() {
		$runs  = TIMU_IC_Run_Log::undoable();
		$nonce = wp_create_nonce( TIMU_IC::AJAX_NONCE_ACTION );

		$op_labels = array(
			'rename'    => __( 'Renamed', 'thisismyurl-image-support' ),
			'downscale' => __( 'Downscaled', 'thisismyurl-image-support' ),
			'merge'     => __( 'Merged duplicate', 'thisismyurl-image-support' ),
			'relink'    => __( 'Relinked content', 'thisismyurl-image-support' ),
		);
		?>
		<div class="postbox" id="timu-recent-runs">
			<h2 class="hndle"><span><?php esc_html_e( 'Recent runs', 'thisismyurl-image-support' ); ?></span></h2>
			<div class="inside">
				<p class="description">
					<?php esc_html_e( 'Each cleanup batch is recorded so you can reverse the whole pass at once. Undo restores every file, name, and content link the run changed.', 'thisismyurl-image-support' ); ?>
				</p>
				<div id="timu-undo-result"></div>
				<?php if ( empty( $runs ) ) : ?>
					<p><?php esc_html_e( 'No recent runs are available to undo.', 'thisismyurl-image-support' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="border:none;box-shadow:none;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'When', 'thisismyurl-image-support' ); ?></th>
								<th style="width:70px;"><?php esc_html_e( 'Items', 'thisismyurl-image-support' ); ?></th>
								<th><?php esc_html_e( 'What it did', 'thisismyurl-image-support' ); ?></th>
								<th style="width:150px;"><?php esc_html_e( 'Action', 'thisismyurl-image-support' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $runs as $run ) : ?>
								<?php
								$summary = TIMU_IC_Run_Log::summarize( $run );
								$parts   = array();
								foreach ( $summary['by_operation'] as $op => $count ) {
									$label   = isset( $op_labels[ $op ] ) ? $op_labels[ $op ] : ucfirst( $op );
									$parts[] = $label . ' ' . number_format_i18n( $count );
								}
								?>
								<tr id="timu-run-<?php echo esc_attr( $run['run_id'] ); ?>">
									<td>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: human-readable time-ago, e.g. "5 minutes". */
												__( '%s ago', 'thisismyurl-image-support' ),
												human_time_diff( (int) $run['started_at'], time() )
											)
										);
										?>
										<br>
										<span class="description"><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $run['started_at'] ) ); ?></span>
									</td>
									<td><?php echo esc_html( number_format_i18n( (int) $summary['count'] ) ); ?></td>
									<td><?php echo esc_html( implode( ', ', $parts ) ); ?></td>
									<td>
										<button class="button button-secondary timu-undo-run"
											data-run="<?php echo esc_attr( $run['run_id'] ); ?>"
											data-nonce="<?php echo esc_attr( $nonce ); ?>">
											<?php esc_html_e( 'Undo this run', 'thisismyurl-image-support' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Optimize tab.
	 *
	 * @param array  $lists           Pending/managed media lists.
	 * @param array  $options         Plugin options.
	 * @param int[]  $pending_ids     IDs of pending attachments.
	 * @param int[]  $restorable      IDs of restorable attachments.
	 * @param string $base_url        Plugin admin base URL.
	 * @param string $thisismyurl_url thisismyurl.com URL with UTM.
	 * @param string $donate_url      Donate URL with UTM.
	 *
	 * @return void
	 */
	private static function render_optimize_tab( $lists, $options, $pending_ids, $restorable, $base_url, $thisismyurl_url, $donate_url ) {
		?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">

					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Image SEO and Safety Dashboard', 'thisismyurl-image-support' ); ?></span></h2>
						<div class="inside">
							<div style="padding:10px 0;min-height:80px;">
								<div class="fwo-controls" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
									<button id="btn-start" class="button button-primary button-large" <?php disabled( empty( $pending_ids ) ); ?>>
										<?php printf( esc_html__( 'Optimize All %d Images', 'thisismyurl-image-support' ), count( $pending_ids ) ); ?>
									</button>
									<button id="btn-dry-run-csv" class="button button-secondary button-large" <?php disabled( empty( $pending_ids ) ); ?>>
										<?php esc_html_e( 'Dry Run (Export CSV)', 'thisismyurl-image-support' ); ?>
									</button>
									<button id="btn-cancel" class="button button-secondary button-large" style="display:none;color:#d63638;">
										<?php esc_html_e( 'Cancel Batch', 'thisismyurl-image-support' ); ?>
									</button>
								</div>
								<div id="fwo-progress-container" style="display:none;margin-top:20px;background:#f0f0f1;height:30px;position:relative;border-radius:4px;overflow:hidden;border:1px solid #c3c4c7;">
									<div id="fwo-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.2s;"></div>
									<div id="fwo-progress-text" style="position:absolute;width:100%;text-align:center;top:0;line-height:30px;font-weight:bold;color:#fff;mix-blend-mode:difference;">0%</div>
								</div>
							</div>
						</div>
					</div>

					<?php self::render_recent_runs_panel(); ?>

					<div class="postbox">
						<h2 class="hndle">
							<span>
								<?php esc_html_e( 'Pending Optimizations', 'thisismyurl-image-support' ); ?>
								(<span id="p-cnt"><?php echo esc_html( count( $pending_ids ) ); ?></span>)
							</span>
						</h2>
						<div class="inside">
							<table class="widefat striped" id="fwo-pending-table" style="border:none;box-shadow:none;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Preview', 'thisismyurl-image-support' ); ?></th>
										<th><?php esc_html_e( 'ID', 'thisismyurl-image-support' ); ?></th>
										<th><?php esc_html_e( 'File Name', 'thisismyurl-image-support' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php if ( ! empty( $lists['pending'] ) ) : ?>
										<?php foreach ( $lists['pending'] as $post ) : ?>
											<tr id="fwo-row-<?php echo esc_attr( $post->ID ); ?>">
												<td><?php echo wp_kses_post( wp_get_attachment_image( $post->ID, array( 50, 50 ) ) ); ?></td>
												<td>#<?php echo esc_html( $post->ID ); ?></td>
												<td><?php echo esc_html( basename( (string) get_attached_file( $post->ID ) ) ); ?></td>
											</tr>
										<?php endforeach; ?>
									<?php else : ?>
										<tr class="no-images"><td colspan="3"><?php esc_html_e( 'All images optimized!', 'thisismyurl-image-support' ); ?></td></tr>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>

					<div class="postbox">
						<h2 class="hndle">
							<span>
								<?php esc_html_e( 'Managed Media', 'thisismyurl-image-support' ); ?>
								(<span id="m-cnt"><?php echo esc_html( count( $lists['media'] ) ); ?></span>)
							</span>
						</h2>
						<div class="inside">
							<table class="widefat striped" id="fwo-media-table" style="border:none;box-shadow:none;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Preview', 'thisismyurl-image-support' ); ?></th>
										<th><?php esc_html_e( 'ID', 'thisismyurl-image-support' ); ?></th>
										<th><?php esc_html_e( 'File Name', 'thisismyurl-image-support' ); ?></th>
										<th><?php esc_html_e( 'EXIF', 'thisismyurl-image-support' ); ?></th>
										<th><?php esc_html_e( 'Action', 'thisismyurl-image-support' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $lists['media'] as $post ) : ?>
										<?php
										$status   = isset( $post->timu_status ) ? $post->timu_status : '';
										$exif     = TIMU_IC_Audit::get_exif_data( $post->ID );
										$has_gps  = TIMU_IC_Audit::exif_has_gps( $exif );
										$exif_url = add_query_arg(
											array(
												'action'        => 'timu_ic_get_exif',
												'attachment_id' => $post->ID,
												'nonce'         => wp_create_nonce( TIMU_IC::AJAX_NONCE_ACTION ),
												'TB_iframe'     => 'true',
												'width'         => 600,
												'height'        => 500,
											),
											admin_url( 'admin-ajax.php' )
										);
										?>
										<tr id="fwo-media-row-<?php echo esc_attr( $post->ID ); ?>">
											<td><?php echo wp_kses_post( wp_get_attachment_image( $post->ID, array( 50, 50 ) ) ); ?></td>
											<td>#<?php echo esc_html( $post->ID ); ?></td>
											<td><?php echo esc_html( basename( (string) get_attached_file( $post->ID ) ) ); ?></td>
											<td>
												<?php if ( ! empty( $exif ) ) : ?>
													<a href="<?php echo esc_url( $exif_url ); ?>" class="thickbox" title="<?php esc_attr_e( 'Inspect EXIF', 'thisismyurl-image-support' ); ?>">
														<?php if ( $has_gps ) : ?>
															<span style="background:#d63638;color:#fff;padding:1px 5px;border-radius:3px;font-size:11px;">GPS</span>
														<?php else : ?>
															<span style="color:#646970;font-size:11px;"><?php esc_html_e( 'View', 'thisismyurl-image-support' ); ?></span>
														<?php endif; ?>
													</a>
												<?php else : ?>
													<span style="color:#c3c4c7;">—</span>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( 'missing' === $status ) : ?>
													<span style="color:#d63638;"><?php esc_html_e( 'File Missing', 'thisismyurl-image-support' ); ?></span>
												<?php elseif ( get_post_meta( $post->ID, TIMU_IC::ORIGINAL_PATH_KEY, true ) ) : ?>
													<button class="restore-btn button button-small" data-id="<?php echo esc_attr( $post->ID ); ?>">
														<?php esc_html_e( 'Restore', 'thisismyurl-image-support' ); ?>
													</button>
												<?php else : ?>
													<span class="description"><?php esc_html_e( 'Optimized', 'thisismyurl-image-support' ); ?></span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>

				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'About', 'thisismyurl-image-support' ); ?></span></h2>
						<div class="inside">
							<p><?php esc_html_e( 'Cleans file names for SEO readability, hardens image files, and keeps safe backups to support reversible media optimization.', 'thisismyurl-image-support' ); ?></p>
							<?php if ( ! empty( $restorable ) ) : ?>
								<hr />
								<p><strong><?php esc_html_e( 'Bulk Actions', 'thisismyurl-image-support' ); ?></strong></p>
								<button id="btn-restore-all" class="button button-secondary" style="width:100%;text-align:center;" data-ids="<?php echo esc_attr( wp_json_encode( $restorable ) ); ?>">
									<?php esc_html_e( 'Restore All Originals', 'thisismyurl-image-support' ); ?>
								</button>
							<?php endif; ?>
							<hr />
							<p>
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: link to thisismyurl.com */
										__( 'Provided free by %s.', 'thisismyurl-image-support' ),
										'<a href="' . esc_url( $thisismyurl_url ) . '" target="_blank" rel="noopener noreferrer">thisismyurl.com</a>'
									)
								);
								?>
							</p>
							<p>
								<a href="<?php echo esc_url( $donate_url ); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer" style="width:100%;text-align:center;">
									<?php esc_html_e( 'Sponsor development', 'thisismyurl-image-support' ); ?>
								</a>
							</p>
						</div>
					</div>
				</div>

			</div>
		</div>

		<script>
		jQuery(function($){
			'use strict';
			var config  = window.TIMUImageSupportData || {};
			var nonce   = config.nonce   || '';
			var ajaxUrl = config.ajaxUrl || window.ajaxurl;
			var actions = config.actions || {};
			var ids     = Array.isArray(config.pendingIds) ? config.pendingIds.slice() : [];

			$('#btn-dry-run-csv').on('click', function(){
				var $btn = $(this);
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Building report…', 'thisismyurl-image-support' ) ); ?>');
				$.post(ajaxUrl, { action: actions.dryRun || 'timu_ic_dry_run_csv', attachment_ids: ids, nonce: nonce })
					.done(function(res){
						if ( !res || !res.success || !Array.isArray(res.data) ) {
							alert('<?php echo esc_js( __( 'Could not generate dry-run report.', 'thisismyurl-image-support' ) ); ?>');
							return;
						}
						var rows = res.data;
						var header = ['attachment_id','current_filename','proposed_filename','current_dimensions','needs_resize'];
						var csv = [header.join(',')].concat(rows.map(function(r){
							return header.map(function(k){ return '"'+(String(r[k]||'').replace(/"/g,'""'))+'"'; }).join(',');
						})).join('\n');
						var blob = new Blob(['﻿'+csv], {type:'text/csv;charset=utf-8;'});
						var url = URL.createObjectURL(blob);
						var a = document.createElement('a');
						a.href = url;
						a.download = 'timu-dry-run-<?php echo esc_js( gmdate( 'Y-m-d' ) ); ?>.csv';
						document.body.appendChild(a);
						a.click();
						document.body.removeChild(a);
						URL.revokeObjectURL(url);
					})
					.always(function(){
						$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Dry Run (Export CSV)', 'thisismyurl-image-support' ) ); ?>');
					});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render a compact previous/next pager for a paged audit list.
	 *
	 * Keeps the active tab and the other list's page in the URL via
	 * add_query_arg(), and anchors back to the section so a page change lands on
	 * the list the operator was working in rather than the top of the tab.
	 *
	 * @param string $base_url   Plugin admin base URL.
	 * @param string $param      Query var carrying this list's page number.
	 * @param int    $paged      Current page.
	 * @param int    $max_pages  Total pages.
	 * @param string $anchor     Element ID to scroll back to.
	 *
	 * @return void
	 */
	private static function render_pager( $base_url, $param, $paged, $max_pages, $anchor ) {
		if ( $max_pages <= 1 ) {
			return;
		}

		$tab_url  = add_query_arg( 'tab', 'audit', $base_url );
		$prev_url = add_query_arg( $param, max( 1, $paged - 1 ), $tab_url ) . '#' . $anchor;
		$next_url = add_query_arg( $param, min( $max_pages, $paged + 1 ), $tab_url ) . '#' . $anchor;
		?>
		<p class="timu-pager" style="margin-top:10px;">
			<?php if ( $paged > 1 ) : ?>
				<a class="button button-secondary" href="<?php echo esc_url( $prev_url ); ?>">&laquo; <?php esc_html_e( 'Previous', 'thisismyurl-image-support' ); ?></a>
			<?php endif; ?>
			<span style="margin:0 8px;color:#646970;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: current page, 2: total pages */
						__( 'Page %1$d of %2$d', 'thisismyurl-image-support' ),
						$paged,
						$max_pages
					)
				);
				?>
			</span>
			<?php if ( $paged < $max_pages ) : ?>
				<a class="button button-secondary" href="<?php echo esc_url( $next_url ); ?>"><?php esc_html_e( 'Next', 'thisismyurl-image-support' ); ?> &raquo;</a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render the Audit tab.
	 *
	 * @param string $base_url Plugin admin base URL.
	 *
	 * @return void
	 */
	/**
	 * Render the Organize tab — create folders and bulk-assign attachments.
	 *
	 * Part A of media organization. Folders are a hierarchical taxonomy on
	 * attachments (TIMU_IC_Media_Organization::TAXONOMY); this tab is the create +
	 * assign surface, and the Media library carries the folder + completeness
	 * filter dropdowns. The completeness panel here links the expensive criteria
	 * (unused, duplicates, GPS) to the Audit tab rather than running them live.
	 *
	 * @param string $base_url Plugin admin base URL.
	 *
	 * @return void
	 */
	private static function render_organize_tab( $base_url ) {
		$taxonomy   = TIMU_IC_Media_Organization::TAXONOMY;
		$ajax_nonce = wp_create_nonce( TIMU_IC::AJAX_NONCE_ACTION );
		$upload_url = admin_url( 'upload.php' );
		$audit_url  = add_query_arg( 'tab', 'audit', $base_url );

		$folders = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $folders ) ) {
			$folders = array();
		}

		// A small, recent slice of attachments to assign from this screen. The
		// Media library remains the home for large-scale selection via its folder
		// and completeness filters; this list is the in-context quick-assign.
		$recent = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => 40,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$recent_ids = array_map( 'intval', (array) $recent->posts );
		?>
		<div id="poststuff" style="padding-top:10px;">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">

					<!-- Create folder -->
					<div class="postbox" id="organize-create">
						<h2 class="hndle"><span><?php esc_html_e( 'Folders', 'thisismyurl-image-support' ); ?></span></h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'Folders are a private, hierarchical taxonomy on your images. They never appear on the front end — they are a way to organise the Media library and filter it from the list view.', 'thisismyurl-image-support' ); ?></p>

							<div class="timu-bulk-bar" style="display:flex;align-items:flex-end;flex-wrap:wrap;gap:8px;margin:6px 0 12px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;">
								<div>
									<label for="timu-folder-name" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'New folder name', 'thisismyurl-image-support' ); ?></label>
									<input type="text" id="timu-folder-name" class="regular-text" style="width:240px;" placeholder="<?php esc_attr_e( 'e.g. Client logos', 'thisismyurl-image-support' ); ?>">
								</div>
								<div>
									<label for="timu-folder-parent" style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Parent folder', 'thisismyurl-image-support' ); ?></label>
									<select id="timu-folder-parent">
										<option value="0"><?php esc_html_e( '— None (top level) —', 'thisismyurl-image-support' ); ?></option>
										<?php foreach ( $folders as $folder ) : ?>
											<option value="<?php echo esc_attr( $folder->term_id ); ?>"><?php echo esc_html( $folder->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<button id="timu-folder-create" class="button button-primary" data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
									<?php esc_html_e( 'Create folder', 'thisismyurl-image-support' ); ?>
								</button>
								<span id="timu-folder-create-msg" aria-live="polite" style="color:#646970;"></span>
							</div>

							<table class="widefat striped" id="organize-folder-table" style="border:none;box-shadow:none;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Folder', 'thisismyurl-image-support' ); ?></th>
										<th style="width:100px;"><?php esc_html_e( 'Images', 'thisismyurl-image-support' ); ?></th>
										<th style="width:140px;"><?php esc_html_e( 'View', 'thisismyurl-image-support' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $folders ) ) : ?>
										<tr><td colspan="3"><?php esc_html_e( 'No folders yet. Create one above to get started.', 'thisismyurl-image-support' ); ?></td></tr>
									<?php else : ?>
										<?php foreach ( $folders as $folder ) : ?>
											<?php $view_url = add_query_arg( $taxonomy, $folder->slug, $upload_url ); ?>
											<tr data-term-id="<?php echo esc_attr( $folder->term_id ); ?>">
												<td><?php echo esc_html( $folder->name ); ?></td>
												<td><?php echo esc_html( number_format_i18n( $folder->count ) ); ?></td>
												<td><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Show in Media', 'thisismyurl-image-support' ); ?></a></td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>

					<!-- Assign to folder -->
					<div class="postbox" id="organize-assign">
						<h2 class="hndle"><span><?php esc_html_e( 'Assign images to a folder', 'thisismyurl-image-support' ); ?></span></h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'Pick a folder, tick the images below, and assign. This shows your 40 most recent images for a quick start; to organise the whole library at scale, use the Folder and Completeness filters on the Media library list view.', 'thisismyurl-image-support' ); ?></p>

							<?php if ( empty( $recent_ids ) ) : ?>
								<p><?php esc_html_e( 'No images in the library yet.', 'thisismyurl-image-support' ); ?></p>
							<?php else : ?>
								<div class="timu-bulk-bar" style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:6px 0 12px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;">
									<label for="timu-assign-folder"><?php esc_html_e( 'Assign selected to', 'thisismyurl-image-support' ); ?></label>
									<select id="timu-assign-folder">
										<option value="0"><?php esc_html_e( '— Choose a folder —', 'thisismyurl-image-support' ); ?></option>
										<?php foreach ( $folders as $folder ) : ?>
											<option value="<?php echo esc_attr( $folder->term_id ); ?>"><?php echo esc_html( $folder->name ); ?></option>
										<?php endforeach; ?>
									</select>
									<button id="timu-assign-go" class="button button-primary" data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
										<?php esc_html_e( 'Assign selected', 'thisismyurl-image-support' ); ?>
									</button>
									<span id="timu-assign-msg" aria-live="polite" style="color:#646970;"></span>
								</div>

								<p>
									<label><input type="checkbox" id="timu-assign-check-all"> <?php esc_html_e( 'Select all shown', 'thisismyurl-image-support' ); ?></label>
								</p>

								<div style="display:flex;flex-wrap:wrap;gap:10px;">
									<?php foreach ( $recent_ids as $att_id ) : ?>
										<label style="display:block;width:96px;text-align:center;cursor:pointer;">
											<?php echo wp_kses_post( wp_get_attachment_image( $att_id, array( 90, 90 ) ) ); ?><br>
											<input type="checkbox" class="timu-assign-cb" value="<?php echo esc_attr( $att_id ); ?>"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %d: attachment ID */ __( 'Select attachment %d', 'thisismyurl-image-support' ), $att_id ) ); ?>">
											<span style="font-size:11px;color:#646970;">#<?php echo esc_html( $att_id ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<!-- Filter by completeness -->
					<div class="postbox" id="organize-completeness">
						<h2 class="hndle"><span><?php esc_html_e( 'Filter the library by completeness', 'thisismyurl-image-support' ); ?></span></h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'The Media library list view carries a Completeness dropdown so you can show, for example, every image missing alt text. These three checks are fast enough to run live:', 'thisismyurl-image-support' ); ?></p>
							<ul style="list-style:disc;margin-left:20px;">
								<li>
									<a href="<?php echo esc_url( add_query_arg( 'timu_completeness', 'missing_alt', $upload_url ) ); ?>"><?php esc_html_e( 'Missing alt text', 'thisismyurl-image-support' ); ?></a>
								</li>
								<li>
									<a href="<?php echo esc_url( add_query_arg( 'timu_completeness', 'junk_name', $upload_url ) ); ?>"><?php esc_html_e( 'Junk filename', 'thisismyurl-image-support' ); ?></a>
								</li>
								<li>
									<a href="<?php echo esc_url( add_query_arg( 'timu_completeness', 'oversized', $upload_url ) ); ?>"><?php esc_html_e( 'Oversized dimensions', 'thisismyurl-image-support' ); ?></a>
								</li>
							</ul>
							<p class="description"><?php esc_html_e( 'Three other checks are too costly to run on every Media-library page load, so they live in the Audit tab instead:', 'thisismyurl-image-support' ); ?></p>
							<ul style="list-style:disc;margin-left:20px;">
								<li><a href="<?php echo esc_url( $audit_url ); ?>"><?php esc_html_e( 'Unused images (referenced nowhere)', 'thisismyurl-image-support' ); ?></a></li>
								<li><a href="<?php echo esc_url( $audit_url ); ?>"><?php esc_html_e( 'Binary duplicates', 'thisismyurl-image-support' ); ?></a></li>
								<li><a href="<?php echo esc_url( $audit_url ); ?>"><?php esc_html_e( 'GPS in EXIF (privacy)', 'thisismyurl-image-support' ); ?></a></li>
							</ul>
						</div>
					</div>

				</div>
			</div>
		</div>

		<script>
		( function () {
			var data = window.TIMUImageSupportData || {};
			var ajaxUrl = data.ajaxUrl || window.ajaxurl;
			var nonce = data.nonce || '';
			var actions = data.actions || {};
			var strings = data.strings || {};

			function post( action, payload, done ) {
				var fd = new FormData();
				fd.append( 'action', action );
				fd.append( 'nonce', nonce );
				Object.keys( payload ).forEach( function ( key ) {
					var value = payload[ key ];
					if ( Array.isArray( value ) ) {
						value.forEach( function ( v ) { fd.append( key + '[]', v ); } );
					} else {
						fd.append( key, value );
					}
				} );
				fetch( ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( done )
					.catch( function () { done( { success: false, data: strings.requestFailed } ); } );
			}

			var createBtn = document.getElementById( 'timu-folder-create' );
			if ( createBtn ) {
				createBtn.addEventListener( 'click', function () {
					var name = ( document.getElementById( 'timu-folder-name' ).value || '' ).trim();
					var parent = document.getElementById( 'timu-folder-parent' ).value || '0';
					var msg = document.getElementById( 'timu-folder-create-msg' );
					if ( ! name ) { msg.textContent = strings.needName; return; }
					msg.textContent = strings.working;
					createBtn.disabled = true;
					post( actions.createFolder, { name: name, parent: parent }, function ( res ) {
						createBtn.disabled = false;
						if ( res && res.success ) {
							window.location.reload();
						} else {
							msg.textContent = ( res && res.data ) ? res.data : strings.requestFailed;
						}
					} );
				} );
			}

			var checkAll = document.getElementById( 'timu-assign-check-all' );
			if ( checkAll ) {
				checkAll.addEventListener( 'change', function () {
					var boxes = document.querySelectorAll( '.timu-assign-cb' );
					for ( var i = 0; i < boxes.length; i++ ) { boxes[ i ].checked = checkAll.checked; }
				} );
			}

			var assignBtn = document.getElementById( 'timu-assign-go' );
			if ( assignBtn ) {
				assignBtn.addEventListener( 'click', function () {
					var termId = document.getElementById( 'timu-assign-folder' ).value || '0';
					var msg = document.getElementById( 'timu-assign-msg' );
					if ( termId === '0' ) { msg.textContent = strings.needFolder; return; }
					var ids = [];
					var boxes = document.querySelectorAll( '.timu-assign-cb:checked' );
					for ( var i = 0; i < boxes.length; i++ ) { ids.push( boxes[ i ].value ); }
					if ( ! ids.length ) { msg.textContent = strings.needSelection; return; }
					msg.textContent = strings.working;
					assignBtn.disabled = true;
					post( actions.assignFolder, { term_id: termId, attachment_ids: ids }, function ( res ) {
						assignBtn.disabled = false;
						if ( res && res.success ) {
							window.location.reload();
						} else {
							msg.textContent = ( res && res.data ) ? res.data : strings.requestFailed;
						}
					} );
				} );
			}
		} )();
		</script>
		<?php
	}

	private static function render_audit_tab( $base_url ) {
		$orphans        = TIMU_IC_Audit::get_orphan_images();
		$broken         = TIMU_IC_Audit::get_broken_attachments();
		$no_alt_ids     = TIMU_IC_Audit::get_missing_alt_text();
		$inline_orphans = TIMU_IC_Audit::find_inline_orphans();
		$upload_dir     = wp_upload_dir();
		$basedir        = trailingslashit( $upload_dir['basedir'] );
		$queue_status   = TIMU_IC_Scheduler::get_queue_status();

		// Bounded pages for the editable curation lists. The unbounded
		// $no_alt_ids above still drives the section count and the CSV/JSON
		// exports; the editable rows come from a paged read so a large library
		// never materialises every row into the page at once.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$alt_paged  = isset( $_GET['alt_paged'] ) ? max( 1, absint( $_GET['alt_paged'] ) ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$junk_paged = isset( $_GET['junk_paged'] ) ? max( 1, absint( $_GET['junk_paged'] ) ) : 1;
		$alt_page   = TIMU_IC_Curation::get_missing_alt_page( $alt_paged );
		$junk_page  = TIMU_IC_Curation::get_junk_title_page( $junk_paged );

		$orphans_nonce   = wp_create_nonce( 'timu_ic_export_orphans' );
		$broken_nonce    = wp_create_nonce( 'timu_ic_export_broken' );
		$alt_text_nonce  = wp_create_nonce( 'timu_ic_export_alt_text' );
		$ajax_nonce      = wp_create_nonce( TIMU_IC::AJAX_NONCE_ACTION );
		?>
		<div id="poststuff" style="padding-top:10px;">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">

					<!-- Orphan Images -->
					<div class="postbox">
						<h2 class="hndle">
							<span>
								<?php esc_html_e( 'Orphan Image Files', 'thisismyurl-image-support' ); ?>
								<span class="count-badge" style="font-weight:normal;font-size:0.85em;color:#646970;">(<?php echo esc_html( count( $orphans ) ); ?>)</span>
							</span>
						</h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'Files in the uploads directory with no attachment record in the database. Safe to review before deleting.', 'thisismyurl-image-support' ); ?></p>
							<?php if ( ! empty( $orphans ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px;">
									<input type="hidden" name="action" value="timu_ic_export_orphans_csv">
									<?php wp_nonce_field( 'timu_ic_export_orphans' ); ?>
									<button type="submit" class="button button-secondary"><?php esc_html_e( 'Export CSV', 'thisismyurl-image-support' ); ?></button>
								</form>
								<table class="widefat striped" id="audit-orphans-table" style="border:none;box-shadow:none;">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Relative Path', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'Size', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'Modified', 'thisismyurl-image-support' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $orphans as $abs ) : ?>
											<tr>
												<td><?php echo esc_html( ltrim( str_replace( $basedir, '', $abs ), '/' ) ); ?></td>
												<td><?php echo esc_html( file_exists( $abs ) ? size_format( (int) filesize( $abs ) ) : '—' ); ?></td>
												<td><?php echo esc_html( file_exists( $abs ) ? gmdate( 'Y-m-d', (int) filemtime( $abs ) ) : '—' ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php else : ?>
								<p><?php esc_html_e( 'No orphan image files found.', 'thisismyurl-image-support' ); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<!-- Broken Attachments -->
					<div class="postbox">
						<h2 class="hndle">
							<span>
								<?php esc_html_e( 'Broken Attachment Records', 'thisismyurl-image-support' ); ?>
								<span style="font-weight:normal;font-size:0.85em;color:#646970;">(<?php echo esc_html( count( $broken ) ); ?>)</span>
							</span>
						</h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'Attachment posts in the database whose file does not exist on disk.', 'thisismyurl-image-support' ); ?></p>
							<?php if ( ! empty( $broken ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px;">
									<input type="hidden" name="action" value="timu_ic_export_broken_csv">
									<?php wp_nonce_field( 'timu_ic_export_broken' ); ?>
									<button type="submit" class="button button-secondary"><?php esc_html_e( 'Export CSV', 'thisismyurl-image-support' ); ?></button>
								</form>
								<table class="widefat striped" id="audit-broken-table" style="border:none;box-shadow:none;">
									<thead>
										<tr>
											<th><?php esc_html_e( 'ID', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'Title', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'Expected Path', 'thisismyurl-image-support' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $broken as $post ) : ?>
											<tr>
												<td>
													<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>">
														#<?php echo esc_html( $post->ID ); ?>
													</a>
												</td>
												<td><?php echo esc_html( $post->post_title ); ?></td>
												<td style="word-break:break-all;"><?php echo esc_html( (string) get_attached_file( $post->ID ) ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php else : ?>
								<p><?php esc_html_e( 'No broken attachments found.', 'thisismyurl-image-support' ); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<!-- Fix missing alt text -->
					<div class="postbox" id="audit-alt-fix">
						<h2 class="hndle">
							<span>
								<?php esc_html_e( 'Fix missing alt text', 'thisismyurl-image-support' ); ?>
								<span style="font-weight:normal;font-size:0.85em;color:#646970;">(<?php echo esc_html( count( $no_alt_ids ) ); ?>)</span>
							</span>
						</h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'Image attachments with no alt text. Edit a single row inline, or fill the whole page from the title, the filename, or a template. Alt text matters for accessibility and search.', 'thisismyurl-image-support' ); ?></p>
							<?php if ( ! empty( $no_alt_ids ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-bottom:10px;margin-right:6px;">
									<input type="hidden" name="action" value="timu_ic_export_alt_text_csv">
									<?php wp_nonce_field( 'timu_ic_export_alt_text' ); ?>
									<button type="submit" class="button button-secondary"><?php esc_html_e( 'Export CSV', 'thisismyurl-image-support' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-bottom:10px;">
									<input type="hidden" name="action" value="timu_ic_export_alt_text_json">
									<?php wp_nonce_field( 'timu_ic_export_alt_text' ); ?>
									<button type="submit" class="button button-secondary"><?php esc_html_e( 'Export JSON', 'thisismyurl-image-support' ); ?></button>
								</form>

								<div class="timu-bulk-bar" style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:6px 0 12px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;">
									<label for="timu-alt-source"><?php esc_html_e( 'Bulk fill from', 'thisismyurl-image-support' ); ?></label>
									<select id="timu-alt-source">
										<option value="title"><?php esc_html_e( 'Attachment title', 'thisismyurl-image-support' ); ?></option>
										<option value="filename"><?php esc_html_e( 'Humanised filename', 'thisismyurl-image-support' ); ?></option>
										<option value="template"><?php esc_html_e( 'Template', 'thisismyurl-image-support' ); ?></option>
									</select>
									<input type="text" id="timu-alt-template" class="regular-text" style="display:none;flex:1 1 240px;"
										placeholder="{site_name} – {title}"
										aria-label="<?php esc_attr_e( 'Alt text template', 'thisismyurl-image-support' ); ?>">
									<button id="timu-alt-fill-selected" class="button button-secondary"
										data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
										<?php esc_html_e( 'Fill selected', 'thisismyurl-image-support' ); ?>
									</button>
									<button id="timu-alt-fill-all" class="button button-primary"
										data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
										<?php esc_html_e( 'Fill all on this page', 'thisismyurl-image-support' ); ?>
									</button>
									<span class="timu-alt-tokens description" style="flex-basis:100%;margin:0;">
										<?php esc_html_e( 'Template tokens: {title}, {filename}, {site_name}. Bulk fill only writes rows whose alt is still empty.', 'thisismyurl-image-support' ); ?>
									</span>
								</div>
								<div id="timu-alt-result"></div>

								<table class="widefat striped" id="audit-alt-table" style="border:none;box-shadow:none;">
									<thead>
										<tr>
											<th style="width:30px;"><input type="checkbox" id="timu-alt-check-all" aria-label="<?php esc_attr_e( 'Select all rows', 'thisismyurl-image-support' ); ?>"></th>
											<th><?php esc_html_e( 'Preview', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'ID', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'Title / file name', 'thisismyurl-image-support' ); ?></th>
											<th style="width:40%;"><?php esc_html_e( 'Alt text', 'thisismyurl-image-support' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $alt_page['ids'] as $att_id ) : ?>
											<tr id="timu-alt-row-<?php echo esc_attr( $att_id ); ?>">
												<td><input type="checkbox" class="timu-alt-cb" value="<?php echo esc_attr( $att_id ); ?>"></td>
												<td><?php echo wp_kses_post( wp_get_attachment_image( $att_id, array( 40, 40 ) ) ); ?></td>
												<td>
													<a href="<?php echo esc_url( (string) get_edit_post_link( $att_id ) ); ?>">
														#<?php echo esc_html( $att_id ); ?>
													</a>
												</td>
												<td>
													<?php echo esc_html( get_the_title( $att_id ) ); ?><br>
													<span class="description" style="font-size:0.85em;"><?php echo esc_html( wp_basename( (string) get_attached_file( $att_id ) ) ); ?></span>
												</td>
												<td>
													<input type="text" class="timu-alt-input regular-text" style="width:100%;"
														data-id="<?php echo esc_attr( $att_id ); ?>"
														value=""
														aria-label="<?php echo esc_attr( sprintf( /* translators: %d: attachment ID */ __( 'Alt text for attachment %d', 'thisismyurl-image-support' ), $att_id ) ); ?>">
													<span class="timu-alt-status" aria-live="polite" style="margin-left:6px;font-size:0.85em;color:#646970;"></span>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<?php
								self::render_pager( $base_url, 'alt_paged', (int) $alt_page['paged'], (int) $alt_page['max_pages'], 'audit-alt-fix' );
								?>
							<?php else : ?>
								<p><?php esc_html_e( 'All image attachments have alt text.', 'thisismyurl-image-support' ); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<!-- Clean up filenames and titles -->
					<div class="postbox" id="audit-normalize">
						<h2 class="hndle">
							<span>
								<?php esc_html_e( 'Clean up titles, captions, and descriptions', 'thisismyurl-image-support' ); ?>
								<span style="font-weight:normal;font-size:0.85em;color:#646970;">(<?php echo esc_html( (int) $junk_page['total'] ); ?>)</span>
							</span>
						</h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'Attachments whose title still reads as a camera or screenshot default (IMG_4738, DSC01234, a bare number, or a title equal to the raw filename). Derive a clean title from the filename, and optionally set the caption and description from a template. Preview the batch before you apply it. This only edits each image\'s own title, caption, and description — never another post.', 'thisismyurl-image-support' ); ?></p>
							<?php if ( ! empty( $junk_page['rows'] ) ) : ?>
								<div class="timu-bulk-bar" style="display:flex;flex-direction:column;gap:8px;margin:6px 0 12px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;">
									<label>
										<input type="checkbox" id="timu-norm-do-title" checked>
										<?php esc_html_e( 'Set title', 'thisismyurl-image-support' ); ?>
										<input type="text" id="timu-norm-title-template" class="regular-text" style="margin-left:6px;width:320px;"
											placeholder="<?php esc_attr_e( 'Leave blank to use the humanised filename', 'thisismyurl-image-support' ); ?>"
											aria-label="<?php esc_attr_e( 'Title template', 'thisismyurl-image-support' ); ?>">
									</label>
									<label>
										<input type="checkbox" id="timu-norm-do-caption">
										<?php esc_html_e( 'Set caption', 'thisismyurl-image-support' ); ?>
										<input type="text" id="timu-norm-caption-template" class="regular-text" style="margin-left:6px;width:320px;"
											placeholder="{title}"
											aria-label="<?php esc_attr_e( 'Caption template', 'thisismyurl-image-support' ); ?>">
									</label>
									<label>
										<input type="checkbox" id="timu-norm-do-description">
										<?php esc_html_e( 'Set description', 'thisismyurl-image-support' ); ?>
										<input type="text" id="timu-norm-description-template" class="regular-text" style="margin-left:6px;width:320px;"
											placeholder="{title}"
											aria-label="<?php esc_attr_e( 'Description template', 'thisismyurl-image-support' ); ?>">
									</label>
									<span class="description" style="margin:0;">
										<?php esc_html_e( 'Template tokens: {title}, {filename}, {site_name}. Caption and description are only written when their box is checked.', 'thisismyurl-image-support' ); ?>
									</span>
									<div>
										<button id="timu-norm-preview" class="button button-secondary"
											data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
											<?php esc_html_e( 'Preview changes', 'thisismyurl-image-support' ); ?>
										</button>
										<button id="timu-norm-apply" class="button button-primary" disabled
											data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
											<?php esc_html_e( 'Apply to selected', 'thisismyurl-image-support' ); ?>
										</button>
									</div>
								</div>
								<div id="timu-norm-result"></div>

								<table class="widefat striped" id="audit-normalize-table" style="border:none;box-shadow:none;">
									<thead>
										<tr>
											<th style="width:30px;"><input type="checkbox" id="timu-norm-check-all" checked aria-label="<?php esc_attr_e( 'Select all rows', 'thisismyurl-image-support' ); ?>"></th>
											<th><?php esc_html_e( 'Preview', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'ID', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'Current title', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'File name', 'thisismyurl-image-support' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $junk_page['rows'] as $row ) : ?>
											<tr id="timu-norm-row-<?php echo esc_attr( $row['id'] ); ?>">
												<td><input type="checkbox" class="timu-norm-cb" value="<?php echo esc_attr( $row['id'] ); ?>" checked></td>
												<td><?php echo wp_kses_post( wp_get_attachment_image( (int) $row['id'], array( 40, 40 ) ) ); ?></td>
												<td>
													<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row['id'] ) ); ?>">
														#<?php echo esc_html( $row['id'] ); ?>
													</a>
												</td>
												<td class="timu-norm-current-title"><?php echo esc_html( $row['title'] ); ?></td>
												<td><?php echo esc_html( $row['filename'] ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<?php
								self::render_pager( $base_url, 'junk_paged', (int) $junk_page['paged'], (int) $junk_page['max_pages'], 'audit-normalize' );
								?>
							<?php else : ?>
								<p><?php esc_html_e( 'No attachments with junk titles found.', 'thisismyurl-image-support' ); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<!-- Inline Orphans / Bulk Re-attach -->
					<?php if ( ! empty( $inline_orphans ) ) : ?>
					<div class="postbox">
						<h2 class="hndle">
							<span>
								<?php esc_html_e( 'Unattached Images Found in Content', 'thisismyurl-image-support' ); ?>
								<span style="font-weight:normal;font-size:0.85em;color:#646970;">(<?php echo esc_html( count( $inline_orphans ) ); ?>)</span>
							</span>
						</h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'These attachments have no parent post (post_parent = 0) but their URL appears in post content. Re-attaching connects them to their first matching post.', 'thisismyurl-image-support' ); ?></p>
							<p>
								<label>
									<input type="checkbox" id="timu-reattach-dry" checked>
									<?php esc_html_e( 'Dry run (preview only)', 'thisismyurl-image-support' ); ?>
								</label>
								<button id="timu-reattach-go" class="button button-secondary" style="margin-left:8px;"
									data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>"
									data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
									<?php esc_html_e( 'Re-attach Selected', 'thisismyurl-image-support' ); ?>
								</button>
							</p>
							<table class="widefat striped" id="audit-reattach-table" style="border:none;box-shadow:none;">
								<thead>
									<tr>
										<th style="width:30px;"><input type="checkbox" id="timu-reattach-all"></th>
										<th><?php esc_html_e( 'Attachment', 'thisismyurl-image-support' ); ?></th>
										<th><?php esc_html_e( 'Appears In', 'thisismyurl-image-support' ); ?></th>
										<th><?php esc_html_e( 'Proposed Parent', 'thisismyurl-image-support' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $inline_orphans as $item ) :
										$att_id   = (int) $item['attachment_id'];
										$first_id = isset( $item['appears_in'][0] ) ? (int) $item['appears_in'][0] : 0;
										?>
										<tr>
											<td>
												<input type="checkbox" class="timu-reattach-cb"
													data-att="<?php echo esc_attr( $att_id ); ?>"
													data-parent="<?php echo esc_attr( $first_id ); ?>">
											</td>
											<td>
												<?php echo wp_kses_post( wp_get_attachment_image( $att_id, array( 40, 40 ) ) ); ?>
												<a href="<?php echo esc_url( (string) get_edit_post_link( $att_id ) ); ?>">#<?php echo esc_html( $att_id ); ?></a>
											</td>
											<td>
												<?php
												$appears_links = array_map(
													static function ( $pid ) {
														return '<a href="' . esc_url( (string) get_edit_post_link( $pid ) ) . '">#' . esc_html( $pid ) . '</a>';
													},
													array_slice( (array) $item['appears_in'], 0, 5 )
												);
												echo wp_kses_post( implode( ', ', $appears_links ) );
												if ( count( (array) $item['appears_in'] ) > 5 ) {
													echo esc_html( ' + ' . ( count( $item['appears_in'] ) - 5 ) . ' more' );
												}
												?>
											</td>
											<td>
												<?php if ( $first_id ) : ?>
													<a href="<?php echo esc_url( (string) get_edit_post_link( $first_id ) ); ?>">
														<?php echo esc_html( get_the_title( $first_id ) ); ?> (#<?php echo esc_html( $first_id ); ?>)
													</a>
												<?php else : ?>
													—
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<div id="timu-reattach-result" style="margin-top:10px;"></div>
						</div>
					</div>

					<script>
					jQuery(function($){
						'use strict';
						$('#timu-reattach-all').on('change', function(){
							$('.timu-reattach-cb').prop('checked', this.checked);
						});
						$('#timu-reattach-go').on('click', function(){
							var $btn   = $(this);
							var dry    = $('#timu-reattach-dry').is(':checked');
							var pairs  = [];
							$('.timu-reattach-cb:checked').each(function(){
								pairs.push({ attachment_id: parseInt($(this).data('att'),10), parent_id: parseInt($(this).data('parent'),10) });
							});
							if (!pairs.length) {
								alert('<?php echo esc_js( __( 'Select at least one attachment.', 'thisismyurl-image-support' ) ); ?>');
								return;
							}
							$btn.prop('disabled', true);
							$.post($btn.data('ajax'), {
								action: 'timu_ic_reattach_bulk',
								nonce: $btn.data('nonce'),
								dry_run: dry ? '1' : '0',
								pairs: pairs
							}).done(function(res){
								if (res && res.success && res.data) {
									var d = res.data;
									var msg = dry
										? '<?php echo esc_js( __( 'Dry run: would re-attach', 'thisismyurl-image-support' ) ); ?> ' + d.proposed.length + ' <?php echo esc_js( __( 'attachment(s).', 'thisismyurl-image-support' ) ); ?>'
										: '<?php echo esc_js( __( 'Re-attached', 'thisismyurl-image-support' ) ); ?> ' + d.updated.length + ' <?php echo esc_js( __( 'attachment(s).', 'thisismyurl-image-support' ) ); ?>';
									$('#timu-reattach-result').html('<div class="notice notice-success inline"><p>'+msg+'</p></div>');
									if (!dry) { setTimeout(function(){ location.reload(); }, 1200); }
								} else {
									$('#timu-reattach-result').html('<div class="notice notice-error inline"><p><?php echo esc_js( __( 'Request failed.', 'thisismyurl-image-support' ) ); ?></p></div>');
								}
							}).always(function(){ $btn.prop('disabled', false); });
						});
					});
					</script>
					<?php endif; ?>

				</div>

				<!-- Sidebar: queue status -->
				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Background Queue', 'thisismyurl-image-support' ); ?></span></h2>
						<div class="inside">
							<table class="widefat" style="border:none;">
								<tbody>
									<tr>
										<th><?php esc_html_e( 'Engine', 'thisismyurl-image-support' ); ?></th>
										<td><?php echo esc_html( $queue_status['engine'] ?? 'wp-cron' ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Pending', 'thisismyurl-image-support' ); ?></th>
										<td><?php echo esc_html( $queue_status['pending'] ?? 0 ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Running', 'thisismyurl-image-support' ); ?></th>
										<td><?php echo esc_html( $queue_status['running'] ?? 0 ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Completed', 'thisismyurl-image-support' ); ?></th>
										<td><?php echo esc_html( $queue_status['completed'] ?? 0 ); ?></td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the Settings tab.
	 *
	 * @param array $options Current plugin options.
	 *
	 * @return void
	 */
	private static function render_settings_tab( $options ) {
		?>
		<div id="poststuff" style="padding-top:10px;">
			<div id="post-body" class="metabox-holder columns-1">
				<div id="post-body-content">
					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Optimization Settings', 'thisismyurl-image-support' ); ?></span></h2>
						<div class="inside">
							<form method="post" action="options.php">
								<?php settings_fields( TIMU_IC::SETTINGS_GROUP ); ?>
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><label for="timu-batch-size"><?php esc_html_e( 'Batch Size', 'thisismyurl-image-support' ); ?></label></th>
										<td>
											<input id="timu-batch-size" type="number" min="1" max="100"
												name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[batch_size]"
												value="<?php echo esc_attr( $options['batch_size'] ); ?>" class="small-text">
											<p class="description"><?php esc_html_e( 'Images processed per AJAX request. Default: 10.', 'thisismyurl-image-support' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Optimize on Upload', 'thisismyurl-image-support' ); ?></th>
										<td>
											<label>
												<input type="checkbox"
													name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[optimize_on_upload]"
													value="1" <?php checked( ! empty( $options['optimize_on_upload'] ) ); ?>>
												<?php esc_html_e( 'Automatically apply image cleanup and hardening after upload.', 'thisismyurl-image-support' ); ?>
											</label>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Auto Optimize', 'thisismyurl-image-support' ); ?></th>
										<td>
											<fieldset>
												<label style="display:block;margin-bottom:6px;">
													<input type="checkbox"
														name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[auto_optimize_enabled]"
														value="1" <?php checked( ! empty( $options['auto_optimize_enabled'] ) ); ?>>
													<?php esc_html_e( 'Enable automatic background optimization for pending images.', 'thisismyurl-image-support' ); ?>
												</label>
												<label style="display:block;margin-bottom:6px;">
													<input type="checkbox"
														name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[auto_optimize_admin]"
														value="1" <?php checked( ! empty( $options['auto_optimize_admin'] ) ); ?>>
													<?php esc_html_e( 'Run a small optimization batch during wp-admin page visits.', 'thisismyurl-image-support' ); ?>
												</label>
												<label style="display:block;margin-bottom:10px;">
													<input type="checkbox"
														name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[auto_optimize_cron]"
														value="1" <?php checked( ! empty( $options['auto_optimize_cron'] ) ); ?>>
													<?php esc_html_e( 'Run optimization in WP-Cron.', 'thisismyurl-image-support' ); ?>
												</label>
												<p>
													<label for="timu-auto-batch" style="margin-right:8px;">
														<?php esc_html_e( 'Images per auto run:', 'thisismyurl-image-support' ); ?>
													</label>
													<input id="timu-auto-batch" type="number" min="1" max="25"
														name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[auto_optimize_batch]"
														value="<?php echo esc_attr( $options['auto_optimize_batch'] ); ?>" class="small-text">
												</p>
												<p>
													<label for="timu-auto-interval" style="margin-right:8px;">
														<?php esc_html_e( 'WP-Cron interval:', 'thisismyurl-image-support' ); ?>
													</label>
													<select id="timu-auto-interval"
														name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[auto_optimize_interval]">
														<option value="fifteen_minutes" <?php selected( 'fifteen_minutes', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Every 15 minutes', 'thisismyurl-image-support' ); ?></option>
														<option value="hourly" <?php selected( 'hourly', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Hourly', 'thisismyurl-image-support' ); ?></option>
														<option value="twicedaily" <?php selected( 'twicedaily', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Twice Daily', 'thisismyurl-image-support' ); ?></option>
														<option value="daily" <?php selected( 'daily', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Daily', 'thisismyurl-image-support' ); ?></option>
													</select>
												</p>
											</fieldset>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="timu-daily-cap"><?php esc_html_e( 'Daily Cleanup Cap', 'thisismyurl-image-support' ); ?></label></th>
										<td>
											<input id="timu-daily-cap" type="number" min="1" max="500"
												name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[cron_daily_cap]"
												value="<?php echo esc_attr( $options['cron_daily_cap'] ); ?>" class="small-text">
											<p class="description"><?php esc_html_e( 'Maximum attachments processed by the cron per calendar day. Resets at midnight.', 'thisismyurl-image-support' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Source Formats', 'thisismyurl-image-support' ); ?></th>
										<td>
											<?php foreach ( TIMU_IC::get_extension_mime_map() as $extension => $mime ) : ?>
												<label style="display:inline-block;min-width:140px;margin-right:12px;margin-bottom:6px;">
													<input type="checkbox"
														name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[enabled_extensions][]"
														value="<?php echo esc_attr( $extension ); ?>"
														<?php checked( in_array( $extension, (array) $options['enabled_extensions'], true ) ); ?>>
													<?php echo esc_html( strtoupper( $extension ) . ' — ' . $mime ); ?>
												</label>
											<?php endforeach; ?>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="timu-max-dim"><?php esc_html_e( 'Max Image Dimension', 'thisismyurl-image-support' ); ?></label></th>
										<td>
											<input id="timu-max-dim" type="number" min="320" max="6000"
												name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[max_dimension]"
												value="<?php echo esc_attr( $options['max_dimension'] ); ?>" class="small-text">
											<p class="description"><?php esc_html_e( 'Resize oversized images to fit within this width/height while preserving aspect ratio.', 'thisismyurl-image-support' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Metadata Hardening', 'thisismyurl-image-support' ); ?></th>
										<td>
											<fieldset>
												<label style="display:block;margin-bottom:6px;">
													<input type="checkbox"
														name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[strip_metadata]"
														value="1" <?php checked( ! empty( $options['strip_metadata'] ) ); ?>>
													<?php esc_html_e( 'Remove potentially dangerous metadata such as GPS coordinates, camera model, serial numbers, and embedded profiles.', 'thisismyurl-image-support' ); ?>
												</label>
												<label style="display:block;">
													<input type="checkbox"
														name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[embed_metadata]"
														value="1" <?php checked( ! empty( $options['embed_metadata'] ) ); ?>>
													<?php esc_html_e( 'Embed safe creator credit metadata for thisismyurl.com and site attribution.', 'thisismyurl-image-support' ); ?>
												</label>
												<?php if ( ! extension_loaded( 'imagick' ) ) : ?>
													<p class="description" style="color:#d63638;">
														<?php esc_html_e( 'Imagick is not available on this server. Metadata hardening features need Imagick.', 'thisismyurl-image-support' ); ?>
													</p>
												<?php endif; ?>
											</fieldset>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Duplicate Cleanup', 'thisismyurl-image-support' ); ?></th>
										<td>
											<label>
												<input type="checkbox"
													name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[remove_duplicates]"
													value="1" <?php checked( ! empty( $options['remove_duplicates'] ) ); ?>>
												<?php esc_html_e( 'Safely remove obvious binary duplicates and keep the best version while preserving links and redirects.', 'thisismyurl-image-support' ); ?>
											</label>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="timu-exclude-paths"><?php esc_html_e( 'Exclude Path Patterns', 'thisismyurl-image-support' ); ?></label></th>
										<td>
											<textarea id="timu-exclude-paths" rows="4" class="large-text"
												name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[exclude_paths]"
												placeholder="uploads/2010/*&#10;uploads/clients/private/*"
											><?php echo esc_textarea( implode( "\n", (array) $options['exclude_paths'] ) ); ?></textarea>
											<p class="description">
												<?php esc_html_e( 'One glob pattern per line. Matching relative paths are skipped by all destructive operations (cleanup, orphan scan, auto-optimize).', 'thisismyurl-image-support' ); ?>
											</p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="timu-per-page"><?php esc_html_e( 'Items Per Page', 'thisismyurl-image-support' ); ?></label></th>
										<td>
											<input id="timu-per-page" type="number" min="5" max="500"
												name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[list_per_page]"
												value="<?php echo esc_attr( $options['list_per_page'] ); ?>" class="small-text">
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Report Assumptions', 'thisismyurl-image-support' ); ?></th>
										<td>
											<p>
												<label for="timu-monthly-hits" style="display:inline-block;min-width:240px;">
													<?php esc_html_e( 'Estimated monthly image requests', 'thisismyurl-image-support' ); ?>
												</label>
												<input id="timu-monthly-hits" type="number" min="0" max="100000000" step="1"
													name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[report_monthly_image_hits]"
													value="<?php echo esc_attr( $options['report_monthly_image_hits'] ); ?>"
													class="regular-text" style="max-width:180px;">
											</p>
											<p>
												<label for="timu-cost-gb" style="display:inline-block;min-width:240px;">
													<?php esc_html_e( 'Bandwidth cost per GB (USD)', 'thisismyurl-image-support' ); ?>
												</label>
												<input id="timu-cost-gb" type="number" min="0" max="10" step="0.01"
													name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[report_bandwidth_cost_gb]"
													value="<?php echo esc_attr( $options['report_bandwidth_cost_gb'] ); ?>"
													class="regular-text" style="max-width:180px;">
											</p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="timu-attachment-pages"><?php esc_html_e( 'Attachment pages', 'thisismyurl-image-support' ); ?></label></th>
										<td>
											<?php $attachment_mode = isset( $options['attachment_pages'] ) ? (string) $options['attachment_pages'] : 'noindex'; ?>
											<select id="timu-attachment-pages"
												name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[attachment_pages]">
												<option value="noindex" <?php selected( 'noindex', $attachment_mode ); ?>><?php esc_html_e( 'No-index (default)', 'thisismyurl-image-support' ); ?></option>
												<option value="redirect_parent" <?php selected( 'redirect_parent', $attachment_mode ); ?>><?php esc_html_e( 'Redirect to parent post', 'thisismyurl-image-support' ); ?></option>
												<option value="redirect_file" <?php selected( 'redirect_file', $attachment_mode ); ?>><?php esc_html_e( 'Redirect to image file', 'thisismyurl-image-support' ); ?></option>
												<option value="disable" <?php selected( 'disable', $attachment_mode ); ?>><?php esc_html_e( 'Leave as-is (WordPress default)', 'thisismyurl-image-support' ); ?></option>
											</select>
											<p class="description">
												<?php esc_html_e( 'WordPress builds a thin standalone page for every image attachment, which can clutter search results. Choose how those pages behave:', 'thisismyurl-image-support' ); ?>
											</p>
											<ul class="description" style="list-style:disc;margin:6px 0 0 18px;">
												<li><?php esc_html_e( 'No-index: keep the page but tell search engines not to index it. Safe and reversible.', 'thisismyurl-image-support' ); ?></li>
												<li><?php esc_html_e( 'Redirect to parent post: send visitors to the post the image belongs to, falling back to the file when there is no parent.', 'thisismyurl-image-support' ); ?></li>
												<li><?php esc_html_e( 'Redirect to image file: send visitors straight to the image file.', 'thisismyurl-image-support' ); ?></li>
												<li><?php esc_html_e( 'Leave as-is: do nothing — keep the standard WordPress attachment page.', 'thisismyurl-image-support' ); ?></li>
											</ul>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Outbound UTM Parameters', 'thisismyurl-image-support' ); ?></th>
										<td>
											<label>
												<input type="checkbox"
													name="<?php echo esc_attr( TIMU_IC::OPTION_KEY ); ?>[track_outbound_utms]"
													value="1" <?php checked( ! empty( $options['track_outbound_utms'] ) ); ?>>
												<?php esc_html_e( 'Allow UTM parameters in links to thisismyurl.com. No private, site-identifying, account, or user data is included.', 'thisismyurl-image-support' ); ?>
											</label>
										</td>
									</tr>
								</table>

								<?php submit_button( __( 'Save Settings', 'thisismyurl-image-support' ) ); ?>
							</form>
						</div>
					</div>
					<?php
					TIMU_Suite_Settings::render_vortops_postbox( array(
						'save_action'     => 'timu_is_vortops_save',
						'nonce_action'    => 'timu_is_vortops_save',
						'nonce_name'      => 'timu_is_vortops_nonce',
						'redirect_page'   => 'thisismyurl-image-support',
						'field_id'        => 'timu_vortops_api_key_is',
						'btn_id'          => 'btn-vortops-test-is',
						'result_id'       => 'vortops-test-result-is',
						'local_available' => true,
						'local_ok_msg'    => __( 'Alt text is found and filled locally when metadata is present. Vortops adds AI-generated alt text for images that have none — generated from actual image content, not filename guessing.', 'thisismyurl-image-support' ),
					) );
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Report tab.
	 *
	 * @param string $base_url Plugin admin base URL.
	 *
	 * @return void
	 */
	private static function render_report_tab( $base_url ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report_range = isset( $_GET['range'] ) ? sanitize_key( (string) $_GET['range'] ) : '30d';
		if ( ! in_array( $report_range, array( '30d', '90d', '365d', 'all' ), true ) ) {
			$report_range = '30d';
		}

		$report_data = TIMU_IC_File_Ops::get_report_metrics( $report_range );
		?>
		<div id="poststuff" style="padding-top:10px;">
			<div id="post-body" class="metabox-holder columns-1">
				<div id="post-body-content">
					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Business ROI Report', 'thisismyurl-image-support' ); ?></span></h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'Track measurable value from SEO-safe image cleanup and optimization over business-friendly time windows.', 'thisismyurl-image-support' ); ?></p>
							<p>
								<?php
								$ranges = array(
									'30d'  => __( 'Last 30 days', 'thisismyurl-image-support' ),
									'90d'  => __( 'Last 90 days', 'thisismyurl-image-support' ),
									'365d' => __( 'Last 12 months', 'thisismyurl-image-support' ),
									'all'  => __( 'All time', 'thisismyurl-image-support' ),
								);
								foreach ( $ranges as $key => $label ) :
									$is_active = $key === $report_range;
									$url = add_query_arg( array( 'tab' => 'report', 'range' => $key ), $base_url );
									echo '<a class="button ' . ( $is_active ? 'button-primary' : 'button-secondary' ) . '" href="' . esc_url( $url ) . '">'
										. esc_html( $label ) . '</a> ';
								endforeach;
								?>
							</p>

							<table class="widefat striped" style="max-width:960px;">
								<tbody>
									<tr>
										<th style="width:340px;"><?php esc_html_e( 'Images Optimized in Period', 'thisismyurl-image-support' ); ?></th>
										<td><?php echo esc_html( number_format_i18n( (int) $report_data['processed_count'] ) ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Total Transfer Savings (single request basis)', 'thisismyurl-image-support' ); ?></th>
										<td><?php echo esc_html( size_format( (int) $report_data['bytes_saved'], 2 ) ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Average Savings per Image', 'thisismyurl-image-support' ); ?></th>
										<td><?php echo esc_html( number_format_i18n( (float) $report_data['avg_saved_kb'], 2 ) . ' KB' ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Estimated Monthly ROI', 'thisismyurl-image-support' ); ?></th>
										<td>
											<?php
											echo esc_html(
												sprintf(
													/* translators: 1: monthly savings, 2: annual savings */
													__( '$%1$s / month (about $%2$s / year)', 'thisismyurl-image-support' ),
													number_format_i18n( (float) $report_data['monthly_roi'], 2 ),
													number_format_i18n( (float) $report_data['annual_roi'], 2 )
												)
											);
											?>
										</td>
									</tr>
								</tbody>
							</table>
							<p class="description" style="margin-top:10px;">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: renamed files, 2: resized files, 3: duplicate removals */
										__( 'All-time operations: %1$s renamed, %2$s resized, %3$s obvious duplicates removed.', 'thisismyurl-image-support' ),
										number_format_i18n( (int) $report_data['renamed_total'] ),
										number_format_i18n( (int) $report_data['resized_total'] ),
										number_format_i18n( (int) $report_data['duplicates_total'] )
									)
								);
								?>
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
