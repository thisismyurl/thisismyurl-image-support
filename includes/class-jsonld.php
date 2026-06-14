<?php
/**
 * JSON-LD ImageObject emitter for WordPress attachment pages.
 *
 * Emits schema.org/ImageObject structured data on attachment pages when the
 * `emit_attachment_schema` option is enabled. Default-off to avoid injecting
 * frontend output without explicit consent.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits schema.org ImageObject JSON-LD on image attachment pages.
 */
class TIMU_IC_JSON_LD {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'maybe_emit' ), 5 );
	}

	/**
	 * Emit JSON-LD if conditions are met.
	 *
	 * @return void
	 */
	public static function maybe_emit() {
		if ( ! is_attachment() ) {
			return;
		}

		// WordPress 6.4 introduced an option to disable attachment pages.
		if ( ! get_option( 'wp_attachment_pages_enabled', 1 ) ) {
			return;
		}

		$options = TIMU_IC_Options::get();
		if ( empty( $options['emit_attachment_schema'] ) ) {
			return;
		}

		$attachment_id = get_the_ID();
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$data = self::build( (int) $attachment_id, $options );
		if ( empty( $data ) ) {
			return;
		}

		echo '<script type="application/ld+json">' . "\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n";
		echo '</script>' . "\n";
	}

	/**
	 * Build the ImageObject array for a given attachment.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $options       Plugin options.
	 *
	 * @return array Empty on failure.
	 */
	public static function build( $attachment_id, $options ) {
		$content_url = wp_get_attachment_url( $attachment_id );
		if ( ! $content_url ) {
			return array();
		}

		$full        = wp_get_attachment_image_src( $attachment_id, 'full' );
		$mime        = get_post_mime_type( $attachment_id );
		$title       = get_the_title( $attachment_id );
		$description = wp_strip_all_tags( (string) get_post_field( 'post_content', $attachment_id ) );

		$node = array(
			'@context'             => 'https://schema.org',
			'@type'                => 'ImageObject',
			'contentUrl'           => esc_url( $content_url ),
			'url'                  => esc_url( (string) get_permalink( $attachment_id ) ),
			'representativeOfPage' => true,
		);

		if ( '' !== $title ) {
			$node['name'] = esc_html( $title );
		}
		if ( '' !== $description ) {
			$node['description'] = esc_html( $description );
		}
		if ( $mime ) {
			$node['encodingFormat'] = $mime;
		}

		// Width and height must be QuantitativeValue with UN/CEFACT pixel unit.
		if ( $full && isset( $full[1], $full[2] ) && $full[1] && $full[2] ) {
			$node['width'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (int) $full[1],
				'unitCode' => 'E37',
			);
			$node['height'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (int) $full[2],
				'unitCode' => 'E37',
			);
		}

		// Thumbnail — must be a nested ImageObject, not a bare URL string.
		$thumb_src = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
		if ( $thumb_src && $thumb_src[0] !== $content_url ) {
			$node['thumbnail'] = array(
				'@type'      => 'ImageObject',
				'contentUrl' => esc_url( $thumb_src[0] ),
			);
			if ( isset( $thumb_src[1], $thumb_src[2] ) && $thumb_src[1] && $thumb_src[2] ) {
				$node['thumbnail']['width']  = array(
					'@type'    => 'QuantitativeValue',
					'value'    => (int) $thumb_src[1],
					'unitCode' => 'E37',
				);
				$node['thumbnail']['height'] = array(
					'@type'    => 'QuantitativeValue',
					'value'    => (int) $thumb_src[2],
					'unitCode' => 'E37',
				);
			}
		}

		// EXIF-backed fields — branch on source.
		$exif         = TIMU_IC_Audit::get_exif_data( $attachment_id );
		$copyright    = self::resolve_exif_field( $exif, array( 'exif:Copyright', 'Copyright' ), array( 'copyright' ) );
		$credit       = self::resolve_exif_field( $exif, array( 'exif:Artist', 'Artist' ), array( 'credit', 'photographer' ) );
		$license_url  = self::resolve_license_url( $exif );

		if ( '' !== $copyright ) {
			$node['copyrightNotice'] = esc_html( $copyright );
		}
		if ( '' !== $credit ) {
			$node['creditText'] = esc_html( $credit );
		}

		// creator — only when a credit string is present.
		if ( '' !== $credit ) {
			$node['creator'] = array(
				'@type' => 'Person',
				'name'  => esc_html( $credit ),
			);
		}

		// license requires a valid URL; acquireLicensePage is gated on license.
		if ( '' !== $license_url ) {
			$node['license'] = esc_url( $license_url );

			$acquire = ! empty( $options['acquire_license_page_url'] )
				? esc_url( (string) $options['acquire_license_page_url'] )
				: '';
			if ( '' !== $acquire ) {
				$node['acquireLicensePage'] = $acquire;
			}
		}

		$lang = (string) get_bloginfo( 'language' );
		if ( '' !== $lang ) {
			$node['inLanguage'] = $lang;
		}

		return $node;
	}

	/**
	 * Resolve an EXIF field trying Imagick keys first, then wp_read_image_metadata keys.
	 *
	 * @param array    $exif       EXIF array.
	 * @param string[] $imagick_keys Keys to check in the Imagick namespace.
	 * @param string[] $wp_keys      Keys to check in the wp_read_image_metadata namespace.
	 *
	 * @return string Empty string if not found.
	 */
	private static function resolve_exif_field( $exif, $imagick_keys, $wp_keys ) {
		foreach ( $imagick_keys as $key ) {
			if ( isset( $exif[ $key ] ) && '' !== (string) $exif[ $key ] ) {
				return (string) $exif[ $key ];
			}
		}
		foreach ( $wp_keys as $key ) {
			if ( isset( $exif[ $key ] ) && '' !== (string) $exif[ $key ] ) {
				return (string) $exif[ $key ];
			}
		}
		return '';
	}

	/**
	 * Resolve a license URL from XMP dc:rights or EXIF Copyright, if the value
	 * is URL-shaped. Free-text values ("All rights reserved") are rejected.
	 *
	 * @param array $exif EXIF array.
	 *
	 * @return string Valid URL or empty string.
	 */
	private static function resolve_license_url( $exif ) {
		$candidates = array(
			isset( $exif['dc:rights'] ) ? (string) $exif['dc:rights'] : '',
			isset( $exif['rights'] ) ? (string) $exif['rights'] : '',
			isset( $exif['xmpRights:WebStatement'] ) ? (string) $exif['xmpRights:WebStatement'] : '',
		);

		foreach ( $candidates as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' !== $candidate && filter_var( $candidate, FILTER_VALIDATE_URL ) ) {
				return $candidate;
			}
		}

		return '';
	}
}
