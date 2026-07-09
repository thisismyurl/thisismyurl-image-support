<?php
/**
 * TIMU Suite Core — shared classes for the thisismyurl plugin family.
 *
 * CANONICAL SOURCE. This file is distributed to every plugin's includes/
 * directory via bin/sync-timu-suite-core.sh. Edit only here, then run:
 *
 *   bash bin/sync-timu-suite-core.sh
 *
 * Do NOT edit the copies inside plugin includes/ directly — they will be
 * overwritten the next time the sync runs.
 *
 * Three classes, all guarded by class_exists() so only the first TIMU plugin
 * to load wins; the rest silently skip their require_once.
 *
 * @package TIMU_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// 1. TIMU_Vortops_Client — Vortops cloud image-processing API.
// =============================================================================

if ( ! class_exists( 'TIMU_Vortops_Client' ) ) {

	class TIMU_Vortops_Client {

		const OPTION_KEY         = 'timu_vortops_api_key';
		const API_BASE           = 'https://api.vortops.com/v1';

		/**
		 * Post-meta key for AI-generated alt text cached from /v1/describe.
		 * Stored as a supplementary cache; does not overwrite the editor's own
		 * _wp_attachment_image_alt value. The alt-fallback filter reads this key
		 * as tier 1.5 (after stored core alt, before post title).
		 */
		const META_DESCRIBE_ALT  = '_timu_vortops_alt';

		/**
		 * Post-meta key for AI-generated tags cached from /v1/describe.
		 * Stored as a JSON-encoded array of keyword strings.
		 */
		const META_DESCRIBE_TAGS = '_timu_vortops_tags';

		// ── Key management ────────────────────────────────────────────────────

		/** @return string The stored API key, or empty string if none. */
		public static function get_api_key() {
			return (string) get_option( self::OPTION_KEY, '' );
		}

		/** @return bool True if an API key is stored. */
		public static function is_connected() {
			return '' !== self::get_api_key();
		}

		// ── API calls ─────────────────────────────────────────────────────────

		/**
		 * Ping the Vortops API with the stored key.
		 *
		 * @return true|WP_Error True on success.
		 */
		public static function ping() {
			return self::ping_with_key( self::get_api_key() );
		}

		/**
		 * Ping the Vortops API with a specific key (for pre-save connection tests).
		 *
		 * @param string $api_key The API key to test.
		 * @return true|WP_Error True on success.
		 */
		public static function ping_with_key( $api_key ) {
			$api_key = (string) $api_key;

			if ( '' === $api_key ) {
				return new WP_Error( 'no_key', __( 'No Vortops API key provided.', 'timu-suite' ) );
			}

			$response = wp_remote_get(
				self::API_BASE . '/ping',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Accept'        => 'application/json',
					),
					'timeout' => 10,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$body   = wp_remote_retrieve_body( $response );
				$parsed = json_decode( $body, true );
				return new WP_Error(
					'api_error',
					isset( $parsed['error'] ) ? $parsed['error'] : sprintf(
						/* translators: %d: HTTP status code */
						__( 'Vortops API returned status %d.', 'timu-suite' ),
						$code
					)
				);
			}

			return true;
		}

		/**
		 * Get usage for the current billing period.
		 *
		 * @return array|WP_Error Usage data array on success.
		 */
		public static function get_usage() {
			$key = self::get_api_key();
			if ( '' === $key ) {
				return new WP_Error( 'no_key', __( 'No Vortops API key configured.', 'timu-suite' ) );
			}

			$response = wp_remote_get(
				self::API_BASE . '/usage',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'Accept'        => 'application/json',
					),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code   = (int) wp_remote_retrieve_response_code( $response );
			$body   = wp_remote_retrieve_body( $response );
			$parsed = json_decode( $body, true );

			if ( 200 !== $code ) {
				return new WP_Error(
					'api_error',
					isset( $parsed['error'] ) ? $parsed['error'] : sprintf(
						/* translators: %d: HTTP status code */
						__( 'Vortops API returned status %d.', 'timu-suite' ),
						$code
					)
				);
			}

			return is_array( $parsed ) ? $parsed : array();
		}

		/**
		 * Convert a file via Vortops cloud.
		 *
		 * Sends the file as multipart/form-data. Returns the raw output binary
		 * blob on success — write it to disk with file_put_contents().
		 *
		 * @param string $file_path   Absolute path to the source image.
		 * @param string $source_mime MIME type of the source (e.g. 'image/heic').
		 * @param string $target_fmt  Target format hint sent in the request header
		 *                            (e.g. 'webp', 'avif'). Optional; the API
		 *                            chooses a sensible default when omitted.
		 * @return string|WP_Error Raw output blob on success.
		 */
		public static function convert( $file_path, $source_mime = '', $target_fmt = '' ) {
			$key = self::get_api_key();
			if ( '' === $key ) {
				return new WP_Error( 'no_key', __( 'No Vortops API key configured.', 'timu-suite' ) );
			}

			if ( ! file_exists( $file_path ) ) {
				return new WP_Error( 'missing_file', __( 'Source file does not exist.', 'timu-suite' ) );
			}

			$file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $file_content ) {
				return new WP_Error( 'read_error', __( 'Could not read source file.', 'timu-suite' ) );
			}

			$boundary = wp_generate_password( 24, false );
			$filename = basename( $file_path );
			$ct       = '' !== $source_mime ? $source_mime : 'application/octet-stream';

			$body  = '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
			$body .= 'Content-Type: ' . $ct . "\r\n\r\n";
			$body .= $file_content . "\r\n";
			$body .= '--' . $boundary . '--' . "\r\n";

			$headers = array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			);
			if ( '' !== $target_fmt ) {
				$headers['X-Target-Format'] = sanitize_key( $target_fmt );
			}

			$response = wp_remote_post(
				self::API_BASE . '/convert',
				array(
					'headers' => $headers,
					'body'    => $body,
					'timeout' => 60,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$parsed = json_decode( wp_remote_retrieve_body( $response ), true );
				return new WP_Error(
					'convert_error',
					isset( $parsed['error'] ) ? $parsed['error'] : sprintf(
						/* translators: %d: HTTP status code */
						__( 'Vortops conversion failed (HTTP %d).', 'timu-suite' ),
						$code
					)
				);
			}

			return wp_remote_retrieve_body( $response );
		}

		/**
		 * Request AI-generated alt text and keyword tags from the Vortops cloud.
		 *
		 * Calls POST /v1/describe and returns a structured result. The endpoint is
		 * planned but not yet deployed — callers MUST handle WP_Error gracefully
		 * (a 404 or network error returns WP_Error, never throws).
		 *
		 * Intended usage pattern:
		 *   1. On upload: if core alt is empty, call describe() and cache to
		 *      META_DESCRIBE_ALT / META_DESCRIBE_TAGS. Never overwrite core alt.
		 *   2. Alt-fallback filter: read META_DESCRIBE_ALT as tier 1.5 (after
		 *      stored core alt, before post title). No API call on render.
		 *   3. Bulk fill: write describe() result directly to _wp_attachment_image_alt
		 *      so it appears in the Media Library (explicit user action, not cached).
		 *
		 * @param string $file_path Absolute path to the image file.
		 * @param array  $hints     Optional: 'lang' (ISO-639-1 code, default 'en'),
		 *                          'max_length' (int, characters), 'use_case'
		 *                          ('alt_text'|'caption'|'tags', default 'alt_text').
		 * @return array{alt_text:string,tags:string[],confidence:float}|WP_Error
		 */
		public static function describe( $file_path, $hints = array() ) {
			$key = self::get_api_key();
			if ( '' === $key ) {
				return new WP_Error( 'no_key', __( 'No Vortops API key configured.', 'timu-suite' ) );
			}

			if ( ! file_exists( $file_path ) ) {
				return new WP_Error( 'missing_file', __( 'Source file does not exist.', 'timu-suite' ) );
			}

			$file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $file_content ) {
				return new WP_Error( 'read_error', __( 'Could not read image file.', 'timu-suite' ) );
			}

			$boundary = wp_generate_password( 24, false );
			$filename = basename( $file_path );

			$body  = '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
			$body .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
			$body .= $file_content . "\r\n";

			foreach ( array( 'lang', 'max_length', 'use_case' ) as $hint_key ) {
				if ( empty( $hints[ $hint_key ] ) ) {
					continue;
				}
				$value = 'max_length' === $hint_key
					? (string) (int) $hints[ $hint_key ]
					: sanitize_text_field( (string) $hints[ $hint_key ] );
				$body .= '--' . $boundary . "\r\n";
				$body .= 'Content-Disposition: form-data; name="' . $hint_key . '"' . "\r\n\r\n";
				$body .= $value . "\r\n";
			}

			$body .= '--' . $boundary . '--' . "\r\n";

			$response = wp_remote_post(
				self::API_BASE . '/describe',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
						'Accept'        => 'application/json',
					),
					'body'    => $body,
					'timeout' => 45,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code   = (int) wp_remote_retrieve_response_code( $response );
			$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $code ) {
				return new WP_Error(
					'describe_error',
					isset( $parsed['error'] ) ? $parsed['error'] : sprintf(
						/* translators: %d: HTTP status code */
						__( 'Vortops describe failed (HTTP %d).', 'timu-suite' ),
						$code
					)
				);
			}

			return array(
				'alt_text'   => isset( $parsed['alt_text'] ) ? sanitize_text_field( (string) $parsed['alt_text'] ) : '',
				'tags'       => isset( $parsed['tags'] ) && is_array( $parsed['tags'] )
					? array_map( 'sanitize_text_field', $parsed['tags'] )
					: array(),
				'confidence' => isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0.0,
			);
		}

		/**
		 * Sanitize an SVG via Vortops cloud.
		 *
		 * @param string $file_path Absolute path to the SVG file.
		 * @return string|WP_Error Sanitized SVG string on success.
		 */
		public static function sanitize_svg( $file_path ) {
			$key = self::get_api_key();
			if ( '' === $key ) {
				return new WP_Error( 'no_key', __( 'No Vortops API key configured.', 'timu-suite' ) );
			}

			if ( ! file_exists( $file_path ) ) {
				return new WP_Error( 'missing_file', __( 'Source file does not exist.', 'timu-suite' ) );
			}

			$file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $file_content ) {
				return new WP_Error( 'read_error', __( 'Could not read SVG file.', 'timu-suite' ) );
			}

			$boundary = wp_generate_password( 24, false );

			$body  = '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . "\r\n";
			$body .= 'Content-Type: image/svg+xml' . "\r\n\r\n";
			$body .= $file_content . "\r\n";
			$body .= '--' . $boundary . '--' . "\r\n";

			$response = wp_remote_post(
				self::API_BASE . '/svg/sanitize',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
					),
					'body'    => $body,
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$parsed = json_decode( wp_remote_retrieve_body( $response ), true );
				return new WP_Error(
					'sanitize_error',
					isset( $parsed['error'] ) ? $parsed['error'] : sprintf(
						/* translators: %d: HTTP status code */
						__( 'Vortops SVG sanitization failed (HTTP %d).', 'timu-suite' ),
						$code
					)
				);
			}

			return wp_remote_retrieve_body( $response );
		}
	}
}

// =============================================================================
// 2. TIMU_Suite_Event — records which service processed each attachment.
// =============================================================================

if ( ! class_exists( 'TIMU_Suite_Event' ) ) {

	class TIMU_Suite_Event {

		const META_SOURCE = '_timu_suite_conversion_source';

		/**
		 * Record that an attachment was processed by a specific service.
		 *
		 * Stores the source in post meta and, if Vault is active, labels the
		 * snapshot with the service name so Vault's history distinguishes
		 * local conversions from cloud conversions.
		 *
		 * Call BEFORE the backup-adapter snapshot so the label is ready.
		 *
		 * @param int    $attachment_id The attachment ID.
		 * @param string $action        e.g. 'convert', 'sanitize', 'restore'.
		 * @param string $format        e.g. 'webp', 'avif', 'heic-to-webp', 'svg'.
		 * @param string $source        'local' or 'vortops'.
		 * @param string $plugin        e.g. 'webp-support', 'heic-support'.
		 */
		public static function record( $attachment_id, $action, $format, $source, $plugin ) {
			update_post_meta( (int) $attachment_id, self::META_SOURCE, sanitize_key( $source ) );
		}

		/**
		 * Get the source label for a snapshot label string.
		 *
		 * Plugins pass this into their backup-adapter snapshot call so the Vault
		 * history entry shows which service did the work.
		 *
		 * @param string $source 'local' or 'vortops'.
		 * @return string e.g. 'local' or 'via Vortops'.
		 */
		public static function source_label( $source ) {
			return 'vortops' === $source ? 'via Vortops' : 'local';
		}

		/**
		 * Retrieve the last recorded conversion source for an attachment.
		 *
		 * @param int $attachment_id
		 * @return string 'local', 'vortops', or '' if not yet recorded.
		 */
		public static function get_source( $attachment_id ) {
			return (string) get_post_meta( (int) $attachment_id, self::META_SOURCE, true );
		}
	}
}

// =============================================================================
// 3. TIMU_Suite_Settings — shared Vortops settings UI for all TIMU plugins.
// =============================================================================

if ( ! class_exists( 'TIMU_Suite_Settings' ) ) {

	class TIMU_Suite_Settings {

		/** Prevents registering the centralized AJAX handler more than once. */
		private static $ajax_registered = false;

		/**
		 * Register the shared AJAX handler for the Vortops test-connection button.
		 *
		 * Call from each plugin's init() hook. The guard ensures it only runs once
		 * even when multiple TIMU plugins are active.
		 */
		public static function register_ajax_handlers() {
			if ( self::$ajax_registered ) {
				return;
			}
			self::$ajax_registered = true;
			add_action( 'wp_ajax_timu_suite_vortops_test', array( __CLASS__, 'ajax_vortops_test' ) );
		}

		/**
		 * Centralized AJAX handler for the Vortops API key test-connection button.
		 *
		 * Uses nonce action 'timu_suite_vortops_test'. Does NOT save the key —
		 * that is handled by each plugin's own admin_post handler.
		 */
		public static function ajax_vortops_test() {
			check_ajax_referer( 'timu_suite_vortops_test', 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Unauthorized.' );
			}
			$api_key = isset( $_POST['api_key'] )
				? sanitize_text_field( wp_unslash( $_POST['api_key'] ) )
				: '';
			if ( '' === $api_key ) {
				wp_send_json_error( 'Please enter an API key first.' );
			}
			$result = TIMU_Vortops_Client::ping_with_key( $api_key );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}
			wp_send_json_success( array(
				'message' => 'Connected successfully. Save the settings to activate Vortops on this site.',
			) );
		}

		/**
		 * Render the standard Vortops settings postbox.
		 *
		 * Outputs complete HTML including the save form (posting to admin-post.php)
		 * and an inline <script> that wires the Test Connection button. The script
		 * is scoped entirely to the IDs passed in $args, so multiple postboxes on
		 * the same page do not conflict.
		 *
		 * Required $args keys:
		 *   save_action     string  admin_post action, e.g. 'timu_webp_vortops_save'
		 *   nonce_action    string  wp_nonce_field action
		 *   nonce_name      string  wp_nonce_field name
		 *   redirect_page   string  tools.php?page= value, e.g. 'webp-optimizer'
		 *   field_id        string  HTML id for the password input
		 *   btn_id          string  HTML id for the Test Connection button
		 *   result_id       string  HTML id for the result <div>
		 *
		 * Optional $args keys:
		 *   local_available  bool    Whether local processing works on this server.
		 *                            Default true.
		 *   local_ok_msg     string  Text shown when local processing is available.
		 *   gap_msg          string  Text shown when local processing is unavailable
		 *                            (the server-limitation explanation).
		 *
		 * @param array $args Configuration — see above.
		 */
		public static function render_vortops_postbox( array $args ) {
			$defaults = array(
				'local_available' => true,
				'local_ok_msg'    => 'Your server handles this locally. Vortops is optional — connect an account for a cloud backup path.',
				'gap_msg'         => 'Your server cannot process this file type locally. Connecting a Vortops account enables cloud processing as a complete alternative.',
			);
			$args = array_merge( $defaults, $args );

			$save_action   = sanitize_key( $args['save_action'] );
			$nonce_action  = $args['nonce_action'];
			$nonce_name    = sanitize_key( $args['nonce_name'] );
			$redirect_page = sanitize_key( $args['redirect_page'] );
			$field_id      = sanitize_key( $args['field_id'] );
			$btn_id        = sanitize_key( $args['btn_id'] );
			$result_id     = sanitize_key( $args['result_id'] );
			$local_avail   = (bool) $args['local_available'];
			$connected     = TIMU_Vortops_Client::is_connected();
			$current_key   = TIMU_Vortops_Client::get_api_key();

			// Nonce for the centralized test-connection AJAX handler.
			$test_nonce = wp_create_nonce( 'timu_suite_vortops_test' );
			$ajax_url   = esc_url( admin_url( 'admin-ajax.php' ) );
			$post_url   = esc_url( admin_url( 'admin-post.php' ) );
			?>
			<?php if ( isset( $_GET['vortops-saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible" style="margin:12px 0;">
				<p><?php esc_html_e( 'Vortops settings saved.', 'timu-suite' ); ?></p>
			</div>
			<?php endif; ?>

			<div class="postbox" style="margin-top:12px;">
				<h2 class="hndle"><span><?php esc_html_e( 'Cloud Services (Vortops)', 'timu-suite' ); ?></span></h2>
				<div class="inside">

					<?php if ( $local_avail ) : ?>
					<p><?php echo esc_html( $args['local_ok_msg'] ); ?></p>
					<?php else : ?>
					<div class="notice notice-warning inline" style="padding:8px 12px;margin-bottom:12px;">
						<p><?php echo esc_html( $args['gap_msg'] ); ?></p>
					</div>
					<?php endif; ?>

					<?php if ( $connected ) : ?>
					<div class="notice notice-success inline" style="padding:8px 12px;margin-bottom:12px;">
						<p><?php esc_html_e( 'Vortops is connected. Cloud processing is active for any thisismyurl plugins that use it.', 'timu-suite' ); ?></p>
					</div>
					<?php endif; ?>

					<form method="post" action="<?php echo $post_url; // already escaped above ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( $save_action ); ?>" />
						<?php wp_nonce_field( $nonce_action, $nonce_name ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="<?php echo esc_attr( $field_id ); ?>">
										<?php esc_html_e( 'API key', 'timu-suite' ); ?>
									</label>
								</th>
								<td>
									<input type="password"
									       id="<?php echo esc_attr( $field_id ); ?>"
									       name="timu_vortops_api_key"
									       value="<?php echo esc_attr( $current_key ); ?>"
									       class="regular-text"
									       placeholder="<?php esc_attr_e( 'Paste your Vortops API key', 'timu-suite' ); ?>" />
									<button type="button"
									        id="<?php echo esc_attr( $btn_id ); ?>"
									        class="button"
									        style="margin-left:6px;">
										<?php esc_html_e( 'Test connection', 'timu-suite' ); ?>
									</button>
									<div id="<?php echo esc_attr( $result_id ); ?>"
									     style="margin-top:6px;min-height:20px;"
									     aria-live="polite"></div>
									<p class="description">
										<?php
										printf(
											/* translators: %s: link to vortops.com */
											esc_html__( 'Get a free API key at %s. The same key works across all thisismyurl plugins — connect once and all of them use it.', 'timu-suite' ),
											'<a href="https://vortops.com" target="_blank" rel="noopener noreferrer">vortops.com</a>'
										);
										?>
									</p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save Vortops settings', 'timu-suite' ), 'secondary' ); ?>
					</form>

				</div><!-- .inside -->
			</div><!-- .postbox -->

			<script>
			/* TIMU Suite — Vortops test connection (scoped to <?php echo esc_js( $field_id ); ?>) */
			( function ( $ ) {
				$( '#<?php echo esc_js( $btn_id ); ?>' ).on( 'click', function () {
					var $btn    = $( this );
					var $result = $( '#<?php echo esc_js( $result_id ); ?>' );
					var apiKey  = $( '#<?php echo esc_js( $field_id ); ?>' ).val().trim();
					if ( ! apiKey ) {
						$result.html( '<span style="color:#d63638;">✗ Enter an API key first.</span>' );
						return;
					}
					$btn.prop( 'disabled', true ).text( 'Testing…' );
					$result.html( '' );
					$.ajax( {
						url:      '<?php echo esc_js( $ajax_url ); ?>',
						method:   'POST',
						dataType: 'json',
						data:     {
							action:  'timu_suite_vortops_test',
							nonce:   '<?php echo esc_js( $test_nonce ); ?>',
							api_key: apiKey
						}
					} )
					.done( function ( res ) {
						if ( res && res.success ) {
							$result.html( '<span style="color:#00a32a;">✓ ' + ( res.data && res.data.message ? res.data.message : 'Connected.' ) + '</span>' );
						} else {
							$result.html( '<span style="color:#d63638;">✗ ' + ( res && res.data ? res.data : 'Connection failed.' ) + '</span>' );
						}
					} )
					.fail( function () {
						$result.html( '<span style="color:#d63638;">✗ Request failed.</span>' );
					} )
					.always( function () {
						$btn.prop( 'disabled', false ).text( 'Test connection' );
					} );
				} );
			}( jQuery ) );
			</script>
			<?php
		}
	}
}
