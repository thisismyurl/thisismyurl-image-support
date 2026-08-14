<?php
/**
 * Saving alt text from the Media list, without opening each attachment.
 *
 * This is the only thing in the plugin that writes. Checking alt text across a few
 * hundred images currently means a few hundred page loads, which is why it does not get
 * done.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint for inline alt text editing.
 */
final class TIMU_Alt_Editor {

	const ACTION = 'timu_image_save_alt';
	const NONCE  = 'timu_image_save_alt';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Save one attachment's alt text.
	 *
	 * Three gates, in order: a valid nonce, a capability check against this specific
	 * attachment, and a confirmation that the target really is an attachment. The
	 * capability check is per-attachment (`edit_post`) rather than a blanket
	 * `upload_files`, because being allowed to upload files does not imply being allowed
	 * to edit somebody else's.
	 */
	public static function handle_save() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$attachment_id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'That image could not be found.', 'thisismyurl-image-support' ) ), 404 );
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this image.', 'thisismyurl-image-support' ) ), 403 );
		}

		$alt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';

		// Core stores alt as a plain string and renders it into an attribute. Length is
		// capped so a paste accident cannot write an unbounded value into postmeta.
		$alt = trim( mb_substr( $alt, 0, 1000 ) );

		$previous = TIMU_Image_Facts::stored_alt( $attachment_id );

		if ( $previous === $alt ) {
			wp_send_json_success(
				array(
					'alt'     => $alt,
					'changed' => false,
					'message' => __( 'No change.', 'thisismyurl-image-support' ),
				)
			);
		}

		TIMU_Image_Facts::before_write(
			$attachment_id,
			'alt-text',
			array( 'previous' => $previous, 'next' => $alt )
		);

		// Stored either way, including when empty, because that is what core does when an
		// attachment is saved with a blank alt field. Note that WordPress records no
		// intent here: an empty value is indistinguishable from one nobody ever filled
		// in, so this plugin does not pretend to tell "decorative" from "not done yet".
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

		TIMU_Image_Facts::after_write(
			$attachment_id,
			'alt-text',
			array( 'previous' => $previous, 'next' => $alt )
		);

		wp_send_json_success(
			array(
				'alt'     => $alt,
				'changed' => true,
				'message' => '' === $alt
					? __( 'Cleared. This image now counts as missing alt text.', 'thisismyurl-image-support' )
					: __( 'Saved.', 'thisismyurl-image-support' ),
			)
		);
	}
}
