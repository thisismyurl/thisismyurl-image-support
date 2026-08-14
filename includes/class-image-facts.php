<?php
/**
 * Facts about an image attachment, and the seams other code hooks into.
 *
 * Everything the list screen displays comes from here, so there is exactly one place
 * that knows how to answer "how big is this file" or "is this image larger than the
 * theme can use".
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only facts about image attachments.
 */
final class TIMU_Image_Facts {

	/**
	 * Denormalised file size, in bytes.
	 *
	 * WordPress stores filesize inside the serialized `_wp_attachment_metadata` array,
	 * which cannot be sorted on in SQL. Sorting the Media list by file size is one of
	 * the main reasons this plugin exists, so the number is mirrored into its own
	 * numeric meta key. Everything else still reads the core metadata; this key is a
	 * sort index and nothing more.
	 */
	const SIZE_META = '_timu_image_filesize';

	/**
	 * MIME types this plugin considers its business.
	 *
	 * Images only, deliberately. Documents, audio and video are out of scope for this
	 * release and belong to different tooling with different questions.
	 *
	 * @return string[]
	 */
	public static function supported_mimes() {
		/**
		 * Filter the image MIME types this plugin operates on.
		 *
		 * @param string[] $mimes MIME types.
		 */
		return (array) apply_filters(
			'timu_image_support_mime_types',
			array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic', 'image/heif' )
		);
	}

	/**
	 * Whether an attachment is an image this plugin handles.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_supported_image( $attachment_id ) {
		return in_array( (string) get_post_mime_type( $attachment_id ), self::supported_mimes(), true );
	}

	/**
	 * Alt text as stored, without any render-time fallback.
	 *
	 * The list screen must show what is actually saved. A fallback here would paint
	 * every row as having alt text and hide exactly the problem the screen exists to
	 * surface.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function stored_alt( $attachment_id ) {
		return trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Intrinsic dimensions from core's attachment metadata.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{width:int,height:int}
	 */
	public static function dimensions( $attachment_id ) {
		$meta = wp_get_attachment_metadata( $attachment_id );

		return array(
			'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height' => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
		);
	}

	/**
	 * File size in bytes, backfilling the sort index on first read.
	 *
	 * Core has recorded `filesize` in attachment metadata since 6.0, but libraries
	 * uploaded before that have nothing, so the file is measured as a last resort. The
	 * result is written to the sort-index meta either way, which means simply browsing
	 * the Media screen backfills the index without a migration step or a batch job.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Bytes, or 0 when the file cannot be measured.
	 */
	public static function filesize( $attachment_id ) {
		$indexed = get_post_meta( $attachment_id, self::SIZE_META, true );

		if ( '' !== $indexed && null !== $indexed ) {
			return (int) $indexed;
		}

		$meta  = wp_get_attachment_metadata( $attachment_id );
		$bytes = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;

		if ( $bytes <= 0 ) {
			$path = get_attached_file( $attachment_id );

			if ( $path && file_exists( $path ) ) {
				$bytes = (int) filesize( $path );
			}
		}

		if ( $bytes > 0 ) {
			update_post_meta( $attachment_id, self::SIZE_META, $bytes );
		}

		return $bytes;
	}

	/**
	 * The widest image this site will ever actually serve to a visitor.
	 *
	 * This is the yardstick for "too big", and getting it right took a correction worth
	 * recording. The obvious reading of "largest size the theme uses" is wrong twice
	 * over:
	 *
	 * Since 5.3 core registers `1536x1536` and `2048x2048` on every single site, whether
	 * or not the theme has any use for them, because they are high-density candidates
	 * for srcset. They are not evidence of theme intent, but they ARE genuinely served,
	 * so they belong in this number.
	 *
	 * Separately, core scales uploads down to 2560px on arrival. So on any modern site a
	 * freshly uploaded photo is at most 2560px against a 2048px yardstick, a ratio of
	 * 1.25, and a flag calibrated for "several times too big" can never fire at all.
	 *
	 * What genuinely wastes bytes is the image that escaped that scaling: anything
	 * uploaded before 5.3, anything imported straight onto disk, and anything on a site
	 * where big-image scaling was filtered off. Those are real, they are often enormous,
	 * and nothing in WordPress surfaces them.
	 *
	 * @return int Width in pixels, or 0 when it cannot be determined.
	 */
	public static function widest_served_width() {
		static $widest = null;

		if ( null !== $widest ) {
			return $widest;
		}

		$widest = 0;

		if ( function_exists( 'wp_get_registered_image_subsizes' ) ) {
			foreach ( wp_get_registered_image_subsizes() as $size ) {
				if ( ! empty( $size['width'] ) ) {
					$widest = max( $widest, (int) $size['width'] );
				}
			}
		}

		if ( $widest <= 0 ) {
			$widest = 2048;
		}

		/**
		 * Filter the width treated as the largest image this site will serve.
		 *
		 * @param int $widest Width in pixels.
		 */
		return (int) apply_filters( 'timu_image_support_widest_width', $widest );
	}

	/**
	 * How much wider an image is than the largest size the site can use.
	 *
	 * Returned as a multiplier so the caller decides what counts as excessive. 1.0 means
	 * the image is exactly the widest registered size; 3.0 means it is three times wider
	 * than anything that will ever be rendered.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return float Multiplier, or 0.0 when it cannot be judged.
	 */
	public static function oversize_factor( $attachment_id ) {
		$widest = self::widest_served_width();
		$dims   = self::dimensions( $attachment_id );

		if ( $widest <= 0 || $dims['width'] <= 0 ) {
			return 0.0;
		}

		return round( $dims['width'] / $widest, 2 );
	}

	/**
	 * The multiplier past which an image is worth flagging.
	 *
	 * Calibrated at 1.5, from measurement rather than instinct. A modern upload lands at
	 * 2560px against a 2048px yardstick, a ratio of 1.25, so anything at or below 1.5
	 * would flag ordinary healthy images. Above it you are looking at a file that
	 * escaped core's upload scaling, which is the case actually worth surfacing.
	 *
	 * @return float
	 */
	public static function oversize_threshold() {
		/**
		 * Filter the oversize flag threshold.
		 *
		 * @param float $threshold Multiplier of the widest registered image size.
		 */
		return (float) apply_filters( 'timu_image_support_oversize_threshold', 1.5 );
	}

	/**
	 * Whether an image is large enough past the theme's needs to be worth flagging.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_oversized( $attachment_id ) {
		$factor = self::oversize_factor( $attachment_id );

		return $factor > 0.0 && $factor >= self::oversize_threshold();
	}

	/**
	 * Alt text suggestions offered by anything that wants to supply them.
	 *
	 * Nothing supplies suggestions in this release, so this returns an empty array and
	 * the editor shows no suggestion affordance. The seam exists now so that a provider
	 * can be added later without reopening the editor: it returns strings, it does not
	 * write anything, and the person editing still chooses.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[] Suggested alt strings, best first.
	 */
	public static function alt_suggestions( $attachment_id ) {
		/**
		 * Filter alt text suggestions for an attachment.
		 *
		 * Suggestions are offered to the person editing. They are never written
		 * automatically, and an empty array means no suggestion UI is shown at all.
		 *
		 * @param string[] $suggestions   Suggested alt strings.
		 * @param int      $attachment_id Attachment ID.
		 */
		$suggestions = apply_filters( 'timu_image_support_alt_suggestions', array(), (int) $attachment_id );

		return array_values( array_filter( array_map( 'strval', (array) $suggestions ) ) );
	}

	/**
	 * Announce that an attachment is about to be modified.
	 *
	 * Every write in this plugin passes through here first. Nothing listens today. The
	 * point is that anything which needs to take a snapshot before a change, now or
	 * later, has one predictable place to do it rather than needing to guess at which
	 * function happens to touch the database.
	 *
	 * @param int    $attachment_id Attachment being changed.
	 * @param string $context       Short slug describing the change, e.g. 'alt-text'.
	 * @param array  $data          Contextual data for listeners.
	 */
	public static function before_write( $attachment_id, $context, array $data = array() ) {
		/**
		 * Fires immediately before this plugin modifies an attachment.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $context       What is being changed.
		 * @param array  $data          Contextual data, including the previous value.
		 */
		do_action( 'timu_image_support_before_write', (int) $attachment_id, (string) $context, $data );
	}

	/**
	 * Announce that an attachment has been modified.
	 *
	 * @param int    $attachment_id Attachment that changed.
	 * @param string $context       Short slug describing the change.
	 * @param array  $data          Contextual data for listeners.
	 */
	public static function after_write( $attachment_id, $context, array $data = array() ) {
		/**
		 * Fires immediately after this plugin modifies an attachment.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $context       What was changed.
		 * @param array  $data          Contextual data, including old and new values.
		 */
		do_action( 'timu_image_support_after_write', (int) $attachment_id, (string) $context, $data );
	}

	/**
	 * Keep the file-size sort index correct when core regenerates metadata.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata.
	 */
	public static function sync_size_index( $metadata, $attachment_id ) {
		if ( isset( $metadata['filesize'] ) && (int) $metadata['filesize'] > 0 ) {
			update_post_meta( (int) $attachment_id, self::SIZE_META, (int) $metadata['filesize'] );
		} else {
			delete_post_meta( (int) $attachment_id, self::SIZE_META );
		}

		return $metadata;
	}
}
