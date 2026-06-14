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
		add_action( 'wp_ajax_timu_ic_dry_run_csv', array( __CLASS__, 'ajax_dry_run_csv' ) );
		add_action( 'wp_ajax_timu_ic_strip_exif', array( __CLASS__, 'ajax_strip_exif' ) );
		add_action( 'wp_ajax_timu_ic_reattach_bulk', array( __CLASS__, 'ajax_reattach_bulk' ) );
		add_action( 'wp_ajax_timu_ic_get_exif', array( __CLASS__, 'ajax_get_exif' ) );

		// Admin-post handlers for file downloads.
		add_action( 'admin_post_timu_ic_export_orphans_csv', array( __CLASS__, 'handle_export_orphans_csv' ) );
		add_action( 'admin_post_timu_ic_export_broken_csv', array( __CLASS__, 'handle_export_broken_csv' ) );
		add_action( 'admin_post_timu_ic_export_alt_text_csv', array( __CLASS__, 'handle_export_alt_text_csv' ) );
		add_action( 'admin_post_timu_ic_export_alt_text_json', array( __CLASS__, 'handle_export_alt_text_json' ) );

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
			TIMU_IC_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			TIMU_IC_VERSION,
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
				. esc_html__( 'Donate', 'thisismyurl-image-support' ) . '</a>',
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

		$processed_ids = array();
		$failed_ids    = array();
		$errors        = array();

		foreach ( $ids as $attachment_id ) {
			$result = TIMU_IC_File_Ops::process_attachment_for_cleanup( (int) $attachment_id );
			if ( true === $result ) {
				$processed_ids[] = $attachment_id;
			} else {
				$failed_ids[] = $attachment_id;
				$errors[]     = is_wp_error( $result )
					? $result->get_error_message()
					: __( 'Unknown processing error.', 'thisismyurl-image-support' );
			}
		}

		wp_send_json_success(
			array(
				'processed_ids' => $processed_ids,
				'failed_ids'    => $failed_ids,
				'errors'        => array_values( array_unique( $errors ) ),
			)
		);
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

		$allowed_tabs = array( 'optimize', 'settings', 'report', 'audit' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'optimize';
		if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
			$active_tab = 'optimize';
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
						),
						'batchSize'  => TIMU_IC_Options::get_batch_size(),
						'perPage'    => (int) $options['list_per_page'],
						'pendingIds' => $pending_ids,
						'strings'    => array(
							'processing'        => __( 'Processing…', 'thisismyurl-image-support' ),
							'restoring'         => __( 'Restoring…', 'thisismyurl-image-support' ),
							'confirmRestoreAll' => __( 'Restore all images? This cannot be undone.', 'thisismyurl-image-support' ),
							'failedPrefix'      => __( 'Some images failed:', 'thisismyurl-image-support' ),
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
					'optimize' => __( 'Optimize', 'thisismyurl-image-support' ),
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
				case 'optimize':
					self::render_optimize_tab( $lists, $options, $pending_ids, $restorable, $base_url, $thisismyurl_url, $donate_url );
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
									<?php esc_html_e( 'Donate to Development', 'thisismyurl-image-support' ); ?>
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
	 * Render the Audit tab.
	 *
	 * @param string $base_url Plugin admin base URL.
	 *
	 * @return void
	 */
	private static function render_audit_tab( $base_url ) {
		$orphans        = TIMU_IC_Audit::get_orphan_images();
		$broken         = TIMU_IC_Audit::get_broken_attachments();
		$no_alt_ids     = TIMU_IC_Audit::get_missing_alt_text();
		$inline_orphans = TIMU_IC_Audit::find_inline_orphans();
		$upload_dir     = wp_upload_dir();
		$basedir        = trailingslashit( $upload_dir['basedir'] );
		$queue_status   = TIMU_IC_Scheduler::get_queue_status();

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

					<!-- Missing Alt Text -->
					<div class="postbox">
						<h2 class="hndle">
							<span>
								<?php esc_html_e( 'Attachments Missing Alt Text', 'thisismyurl-image-support' ); ?>
								<span style="font-weight:normal;font-size:0.85em;color:#646970;">(<?php echo esc_html( count( $no_alt_ids ) ); ?>)</span>
							</span>
						</h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'Image attachments with no alt text set. Alt text matters for accessibility and SEO.', 'thisismyurl-image-support' ); ?></p>
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
								<table class="widefat striped" id="audit-alt-table" style="border:none;box-shadow:none;">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Preview', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'ID', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'Title', 'thisismyurl-image-support' ); ?></th>
											<th><?php esc_html_e( 'File Name', 'thisismyurl-image-support' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $no_alt_ids as $att_id ) : ?>
											<tr>
												<td><?php echo wp_kses_post( wp_get_attachment_image( $att_id, array( 40, 40 ) ) ); ?></td>
												<td>
													<a href="<?php echo esc_url( (string) get_edit_post_link( $att_id ) ); ?>">
														#<?php echo esc_html( $att_id ); ?>
													</a>
												</td>
												<td><?php echo esc_html( get_the_title( $att_id ) ); ?></td>
												<td><?php echo esc_html( basename( (string) get_attached_file( $att_id ) ) ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php else : ?>
								<p><?php esc_html_e( 'All image attachments have alt text.', 'thisismyurl-image-support' ); ?></p>
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
									'30d'  => __( 'Last 30 Days', 'thisismyurl-image-support' ),
									'90d'  => __( 'Last 90 Days', 'thisismyurl-image-support' ),
									'365d' => __( 'Last 12 Months', 'thisismyurl-image-support' ),
									'all'  => __( 'All Time', 'thisismyurl-image-support' ),
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
