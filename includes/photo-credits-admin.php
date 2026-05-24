<?php
/**
 * Photo-credit admin surfaces.
 *
 * Two views over the same attachment-meta data layer registered in
 * includes/photo-credits.php:
 *
 *   1. SURFACE 1 — Attachment-edit screen meta box. Hooked via
 *      `add_meta_boxes_attachment`; renders the four canonical fields (credit
 *      name, credit URL, AI-generated toggle, AI model), the IPTC pre-fill
 *      notice on first edit after upload, and the empty-state line when
 *      nothing is set. Saves through `edit_attachment` after nonce +
 *      capability verification; clears the AI-model meta via `delete_post_meta`
 *      (not an empty-string write) when the toggle is off so the toggle-off
 *      state is genuinely "no AI metadata" rather than "present but empty".
 *
 *   2. SURFACE 2 — Block-editor sidebar panel for `core/image`. Source in
 *      assets/editor/photo-credit-panel.jsx, compiled to
 *      assets/build/photo-credit-panel.js via `@wordpress/scripts`. This file
 *      owns the PHP-side enqueue. The data binding happens entity-to-entity
 *      (core's attachment entity record), so a value set in the panel is
 *      visible in the meta box and vice versa with no sync logic of our own.
 *
 * Permission policy:
 *   - Meta box: hidden when the current user can't `edit_posts` — subscribers
 *     don't reach attachment-edit and shouldn't be taught a feature they have
 *     no path to.
 *   - Block panel: not specially gated (the block editor already requires
 *     `edit_posts`); the meta's own auth_callback is the security boundary.
 *
 * @package TIMU_Image_Support
 * @since   1.6144
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nonce action name for the attachment-edit meta-box save.
 */
const THISISMYURL_IMAGE_SUPPORT_PHOTO_NONCE_ACTION = 'thisismyurl_image_support_photo_credit_save';

/**
 * Nonce request-field name for the attachment-edit meta-box save.
 */
const THISISMYURL_IMAGE_SUPPORT_PHOTO_NONCE_FIELD = '_thisismyurl_image_support_photo_credit_nonce';

/**
 * Per-user, per-attachment transient key for queued save-error notices.
 *
 * @since 1.6144
 *
 * @param int $attachment_id Attachment being edited.
 * @return string
 */
function thisismyurl_image_support_photo_credit_admin_notice_key( $attachment_id ) {
	return 'timu_photo_credit_notices_' . get_current_user_id() . '_' . (int) $attachment_id;
}

/**
 * Thin proxy so `add_meta_boxes_attachment` (which receives the post) reaches
 * the registrar with the type hint intact.
 *
 * @since 1.6144
 *
 * @param mixed $post Current attachment post (anything else is ignored).
 * @return void
 */
function thisismyurl_image_support_photo_credit_register_proxy( $post ) {
	if ( $post instanceof WP_Post ) {
		thisismyurl_image_support_photo_credit_register_meta_box( $post );
	}
}
add_action( 'add_meta_boxes_attachment', 'thisismyurl_image_support_photo_credit_register_proxy' );

/**
 * Register the attachment-edit meta box.
 *
 * Hidden from users who can't `edit_posts` — they don't reach attachment-edit
 * in default WP, so revealing a disabled control would teach them about a
 * feature they have no path to.
 *
 * @since 1.6144
 *
 * @param WP_Post $post Current attachment post.
 * @return void
 */
function thisismyurl_image_support_photo_credit_register_meta_box( WP_Post $post ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	add_meta_box(
		'thisismyurl_image_support_photo_credit',
		__( 'Photo credit', 'thisismyurl-image-support' ),
		'thisismyurl_image_support_photo_credit_render_meta_box',
		'attachment',
		'side',
		'default',
		array( 'attachment' => $post )
	);
}

/**
 * Render the attachment-edit meta box.
 *
 * Reads the four canonical meta values, consumes the one-shot IPTC-prefill
 * transient (deleted after read so the notice never reappears), and drains any
 * queued save-error notices the previous save left behind. Field order:
 * IPTC notice → credit name → credit link → AI toggle → AI model. The
 * empty-state line renders when every value is blank.
 *
 * @since 1.6144
 *
 * @param WP_Post              $post     Current attachment post.
 * @param array<string, mixed> $meta_box add_meta_box() context (unused).
 * @return void
 */
function thisismyurl_image_support_photo_credit_render_meta_box( WP_Post $post, $meta_box = array() ) {
	unset( $meta_box );

	$attachment_id = (int) $post->ID;
	$data          = thisismyurl_image_support_get_photo_credit( $attachment_id );

	// Consume the IPTC-prefill marker (one-shot, deleted on read).
	$iptc_transient_key = THISISMYURL_IMAGE_SUPPORT_PHOTO_IPTC_TRANSIENT_PREFIX . $attachment_id;
	$prefill_markers    = get_transient( $iptc_transient_key );
	$prefilled_byline   = false;
	$prefilled_credit   = false;
	if ( is_array( $prefill_markers ) ) {
		delete_transient( $iptc_transient_key );
		foreach ( $prefill_markers as $marker ) {
			if ( ! is_string( $marker ) ) {
				continue;
			}
			if ( false !== strpos( $marker, '::byline' ) ) {
				$prefilled_byline = true;
			} elseif ( false !== strpos( $marker, '::credit' ) || false !== strpos( $marker, '::copyright' ) ) {
				// IPTC Credit and Copyright both read as organisation-level
				// attribution rather than a named photographer.
				$prefilled_credit = true;
			}
		}
	}

	// Drain any save-failure notices the prior save queued. Each entry is
	// array{message:string, retained?:array}.
	$notice_key       = thisismyurl_image_support_photo_credit_admin_notice_key( $attachment_id );
	$queued_notices   = get_transient( $notice_key );
	$render_notices   = array();
	$posted_overrides = array();
	if ( is_array( $queued_notices ) ) {
		delete_transient( $notice_key );
		foreach ( $queued_notices as $notice ) {
			if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
				$render_notices[] = (string) $notice['message'];
			}
			if ( is_array( $notice ) && isset( $notice['retained'] ) && is_array( $notice['retained'] ) ) {
				// Carry forward the editor's last-typed values so they can fix
				// the error without re-entering everything.
				$posted_overrides = array_merge( $posted_overrides, $notice['retained'] );
			}
		}
	}

	// Retained values from a failed save win over stored meta (the editor
	// hasn't fixed the error yet).
	$credit_value     = isset( $posted_overrides['credit'] ) ? (string) $posted_overrides['credit'] : $data['credit'];
	$credit_url_value = isset( $posted_overrides['credit_url'] ) ? (string) $posted_overrides['credit_url'] : $data['credit_url'];
	$ai_generated     = isset( $posted_overrides['ai_generated'] ) ? (bool) $posted_overrides['ai_generated'] : $data['ai_generated'];
	$ai_model_value   = isset( $posted_overrides['ai_model'] ) ? (string) $posted_overrides['ai_model'] : $data['ai_model'];

	$all_empty = ( '' === $credit_value && '' === $credit_url_value && ! $ai_generated && '' === $ai_model_value );

	wp_nonce_field( THISISMYURL_IMAGE_SUPPORT_PHOTO_NONCE_ACTION, THISISMYURL_IMAGE_SUPPORT_PHOTO_NONCE_FIELD );
	?>
	<div class="thisismyurl-photo-credit-meta">
		<?php foreach ( $render_notices as $message ) : ?>
			<div class="notice notice-error inline">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
		<?php endforeach; ?>

		<?php if ( $all_empty && empty( $render_notices ) ) : ?>
			<p class="description thisismyurl-photo-credit-empty">
				<?php esc_html_e( 'No credit set. The image will render without a photographer credit on the front end. Add a credit name above, or leave blank to render silently.', 'thisismyurl-image-support' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $prefilled_byline ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Pre-filled from IPTC By-Line — confirm or edit.', 'thisismyurl-image-support' ); ?></p>
			</div>
		<?php endif; ?>

		<p>
			<label for="thisismyurl-photo-credit-name">
				<strong><?php esc_html_e( 'Credit name', 'thisismyurl-image-support' ); ?></strong>
			</label>
			<input
				type="text"
				id="thisismyurl-photo-credit-name"
				name="thisismyurl_photo_credit"
				class="widefat"
				value="<?php echo esc_attr( $credit_value ); ?>"
			/>
			<span class="description">
				<?php esc_html_e( 'The name that appears under the image on the front end. Leave blank to render no credit.', 'thisismyurl-image-support' ); ?>
			</span>
		</p>

		<?php if ( $prefilled_credit ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Pre-filled from IPTC Credit — confirm or edit.', 'thisismyurl-image-support' ); ?></p>
			</div>
		<?php endif; ?>

		<p>
			<label for="thisismyurl-photo-credit-url">
				<strong><?php esc_html_e( 'Credit link (URL)', 'thisismyurl-image-support' ); ?></strong>
			</label>
			<input
				type="url"
				id="thisismyurl-photo-credit-url"
				name="thisismyurl_photo_credit_url"
				class="widefat"
				placeholder="https://"
				value="<?php echo esc_attr( $credit_url_value ); ?>"
			/>
			<span class="description">
				<?php esc_html_e( 'Optional. The credit name links to this URL when set. Leave blank for an unlinked credit.', 'thisismyurl-image-support' ); ?>
			</span>
		</p>

		<p>
			<label for="thisismyurl-photo-credit-ai">
				<input
					type="checkbox"
					id="thisismyurl-photo-credit-ai"
					name="thisismyurl_photo_ai_generated"
					value="1"
					<?php checked( $ai_generated ); ?>
					data-thisismyurl-photo-credit-ai-toggle="1"
				/>
				<strong><?php esc_html_e( 'This image is AI-generated', 'thisismyurl-image-support' ); ?></strong>
			</label>
			<span class="description" data-thisismyurl-photo-credit-ai-help="off"<?php echo $ai_generated ? ' hidden' : ''; ?>>
				<?php esc_html_e( 'Turn on when the image was generated by an AI model.', 'thisismyurl-image-support' ); ?>
			</span>
			<span class="description" data-thisismyurl-photo-credit-ai-help="on"<?php echo $ai_generated ? '' : ' hidden'; ?>>
				<?php esc_html_e( 'This image will be marked as AI-generated. Name the model below.', 'thisismyurl-image-support' ); ?>
			</span>
		</p>

		<p
			class="thisismyurl-photo-credit-ai-model-wrap"
			data-thisismyurl-photo-credit-ai-model-wrap="1"
			<?php echo $ai_generated ? '' : 'style="display: none;"'; ?>
		>
			<label for="thisismyurl-photo-credit-ai-model">
				<strong><?php esc_html_e( 'AI model', 'thisismyurl-image-support' ); ?></strong>
			</label>
			<input
				type="text"
				id="thisismyurl-photo-credit-ai-model"
				name="thisismyurl_photo_ai_model"
				class="widefat"
				placeholder="gpt-image-1"
				value="<?php echo esc_attr( $ai_model_value ); ?>"
			/>
			<span class="description">
				<?php esc_html_e( 'The model that generated the image (e.g. gpt-image-1, midjourney-v6, stable-diffusion-xl).', 'thisismyurl-image-support' ); ?>
			</span>
		</p>
	</div>
	<?php
}

/**
 * Persist the meta-box payload.
 *
 * Hooked via `edit_attachment` (fires on attachment-screen Update). Skips
 * autosave / REST / bulk-edit / inline-edit paths to avoid double-saves
 * stomping the REST channel. Verifies nonce + capability, sanitises each
 * field, validates URL shape, enforces the AI-on/model-required rule, and
 * queues per-user transient notices for save failures so they render on the
 * next edit-screen load. `delete_post_meta` clears values when blank so a
 * toggle-off leaves no phantom-present key.
 *
 * @since 1.6144
 *
 * @param int $attachment_id Attachment being saved.
 * @return void
 */
function thisismyurl_image_support_photo_credit_save_meta_box( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	if ( $attachment_id <= 0 ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		// REST handles its own auth via the registered meta keys.
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- bulk_edit/inline-save scope check fires before nonce verification.
	if ( isset( $_REQUEST['bulk_edit'] ) ) {
		return;
	}
	if ( isset( $_REQUEST['action'] ) && 'inline-save' === $_REQUEST['action'] ) {
		return;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_POST[ THISISMYURL_IMAGE_SUPPORT_PHOTO_NONCE_FIELD ] ) ) {
		// Not our save — silent return so we don't clobber meta we weren't editing.
		return;
	}
	$nonce = sanitize_text_field( wp_unslash( $_POST[ THISISMYURL_IMAGE_SUPPORT_PHOTO_NONCE_FIELD ] ) );
	if ( ! wp_verify_nonce( $nonce, THISISMYURL_IMAGE_SUPPORT_PHOTO_NONCE_ACTION ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
		return;
	}

	$credit_raw       = isset( $_POST['thisismyurl_photo_credit'] ) ? sanitize_text_field( wp_unslash( $_POST['thisismyurl_photo_credit'] ) ) : '';
	$credit_url_raw   = isset( $_POST['thisismyurl_photo_credit_url'] ) ? trim( (string) wp_unslash( $_POST['thisismyurl_photo_credit_url'] ) ) : '';
	$ai_generated_raw = isset( $_POST['thisismyurl_photo_ai_generated'] ) ? '1' : '';
	$ai_model_raw     = isset( $_POST['thisismyurl_photo_ai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['thisismyurl_photo_ai_model'] ) ) : '';

	$errors   = array();
	$retained = array(
		'credit'       => $credit_raw,
		'credit_url'   => $credit_url_raw,
		'ai_generated' => '1' === $ai_generated_raw,
		'ai_model'     => $ai_model_raw,
	);

	$credit_url_clean = '';
	if ( '' !== $credit_url_raw ) {
		// esc_url_raw returns '' for unparsable input; wp_http_validate_url
		// confirms the URL is well-formed and addressable (scheme + host).
		$candidate = esc_url_raw( $credit_url_raw );
		if ( '' === $candidate || false === wp_http_validate_url( $candidate ) ) {
			$errors[] = __( 'Credit link is not a valid URL. Use the full address including https:// — for example, https://example.com/portfolio.', 'thisismyurl-image-support' );
		} else {
			$credit_url_clean = $candidate;
		}
	}

	if ( '1' === $ai_generated_raw && '' === trim( $ai_model_raw ) ) {
		$errors[] = __( 'AI model is required when "This image is AI-generated" is turned on. Name the model, or turn the toggle off.', 'thisismyurl-image-support' );
	}

	if ( ! empty( $errors ) ) {
		// Queue errors plus in-flight values so the editor can fix and re-save
		// without retyping. Five-minute TTL: long enough for a refresh, short
		// enough not to resurface on a later visit.
		set_transient(
			thisismyurl_image_support_photo_credit_admin_notice_key( $attachment_id ),
			array(
				array(
					'message'  => implode( ' ', $errors ),
					'retained' => $retained,
				),
			),
			5 * MINUTE_IN_SECONDS
		);
		// Do not write any field on a validation failure — partial writes
		// leave the data layer half-saved in a way the editor didn't intend.
		return;
	}

	// Credit name: write, or delete the key when blank (silent-is-honest).
	if ( '' === $credit_raw ) {
		delete_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META );
	} else {
		update_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META, $credit_raw );
	}

	// Credit URL: same delete-when-blank pattern.
	if ( '' === $credit_url_clean ) {
		delete_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_URL_META );
	} else {
		update_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_URL_META, $credit_url_clean );
	}

	// AI-generated flag and model. Delete when off so the data layer is honest
	// about state — a missing key is more truthful than an empty-string key.
	if ( '1' === $ai_generated_raw ) {
		update_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_GENERATED_META, '1' );
		update_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_MODEL_META, $ai_model_raw );
	} else {
		delete_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_GENERATED_META );
		delete_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_MODEL_META );
	}
}
add_action( 'edit_attachment', 'thisismyurl_image_support_photo_credit_save_meta_box' );

/**
 * Enqueue the small inline JS that toggles the AI-model field visibility.
 *
 * Hand-rolled vanilla JS (no build step for ~10 lines of DOM toggle); the
 * block-editor panel is the surface that warrants the React build.
 *
 * @since 1.6144
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function thisismyurl_image_support_photo_credit_enqueue_meta_box_assets( $hook ) {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'attachment' !== $screen->post_type ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$inline_js = <<<'JS'
(function () {
	var toggle = document.querySelector('[data-thisismyurl-photo-credit-ai-toggle]');
	if (!toggle) { return; }
	var wrap = document.querySelector('[data-thisismyurl-photo-credit-ai-model-wrap]');
	var helpOff = document.querySelector('[data-thisismyurl-photo-credit-ai-help="off"]');
	var helpOn = document.querySelector('[data-thisismyurl-photo-credit-ai-help="on"]');
	function sync() {
		var on = !!toggle.checked;
		if (wrap) { wrap.style.display = on ? '' : 'none'; }
		if (helpOff) { helpOff.hidden = on; }
		if (helpOn) { helpOn.hidden = !on; }
	}
	toggle.addEventListener('change', sync);
	sync();
}());
JS;

	$version = defined( 'TIMU_IMAGE_SUPPORT_VERSION' ) ? TIMU_IMAGE_SUPPORT_VERSION : false;

	// Register a handle with no source so wp_add_inline_script has something to
	// attach to. Pure DOM API — depends on nothing, footer-printed.
	wp_register_script( 'thisismyurl-image-support-photo-credit-meta-box', '', array(), $version, true );
	wp_enqueue_script( 'thisismyurl-image-support-photo-credit-meta-box' );
	wp_add_inline_script( 'thisismyurl-image-support-photo-credit-meta-box', $inline_js );
}
add_action( 'admin_enqueue_scripts', 'thisismyurl_image_support_photo_credit_enqueue_meta_box_assets' );

/**
 * Enqueue the block-editor sidebar panel (Surface 2).
 *
 * Loads the compiled JSX bundle on every block-editor screen; the panel itself
 * is scoped via the BlockEdit filter to render only when a `core/image` block
 * is selected. Asset metadata (dependencies + version hash) comes from
 * assets/build/photo-credit-panel.asset.php when present; the enqueue is
 * defensive so a missing build silently doesn't load rather than fatal.
 *
 * @since 1.6144
 *
 * @return void
 */
function thisismyurl_image_support_photo_credit_enqueue_block_editor_assets() {
	if ( ! defined( 'TIMU_IMAGE_SUPPORT_DIR' ) || ! defined( 'TIMU_IMAGE_SUPPORT_URL' ) ) {
		return;
	}

	$asset_path = TIMU_IMAGE_SUPPORT_DIR . 'assets/build/photo-credit-panel.asset.php';
	$script_url = TIMU_IMAGE_SUPPORT_URL . 'assets/build/photo-credit-panel.js';
	$script_dir = TIMU_IMAGE_SUPPORT_DIR . 'assets/build/photo-credit-panel.js';

	if ( ! file_exists( $script_dir ) ) {
		// Build hasn't run; refuse to enqueue rather than 404. The meta-box
		// surface still works regardless.
		return;
	}

	$default_version = defined( 'TIMU_IMAGE_SUPPORT_VERSION' ) ? TIMU_IMAGE_SUPPORT_VERSION : false;

	$asset = file_exists( $asset_path )
		? require $asset_path // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		: array(
			'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-hooks', 'wp-components', 'wp-data', 'wp-i18n', 'wp-compose', 'wp-block-editor', 'wp-core-data' ),
			'version'      => $default_version,
		);

	wp_enqueue_script(
		'thisismyurl-image-support-photo-credit-panel',
		$script_url,
		isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array(),
		isset( $asset['version'] ) ? $asset['version'] : $default_version,
		true
	);

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'thisismyurl-image-support-photo-credit-panel', 'thisismyurl-image-support' );
	}
}
add_action( 'enqueue_block_editor_assets', 'thisismyurl_image_support_photo_credit_enqueue_block_editor_assets' );
