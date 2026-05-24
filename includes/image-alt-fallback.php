<?php
/**
 * Image alt-text fallback.
 *
 * Some theme templates and block renders call `wp_get_attachment_image()`
 * with an `alt` attribute derived from upstream data (a brand name, a client
 * title, a post excerpt). When that upstream value is empty, the rendered tag
 * lands as `alt=""` — which assistive tech reads as "image without
 * description" and which an accessibility audit flags as missing.
 *
 * This filter sits on `wp_get_attachment_image_attributes` and supplies a
 * fallback derived from the attachment record itself, in priority order:
 *
 *   1. The attachment's `_wp_attachment_image_alt` post-meta (core's own alt;
 *      if present we don't override).
 *   2. The attachment post title.
 *   3. The filename basename, humanised (last resort).
 *
 * Decorative imagery is left untouched: when the caller passes a non-empty
 * `data-decorative` attribute alongside an empty `alt`, the image is
 * deliberately silent to screen readers and the fallback is suppressed.
 *
 * This is a benign, read-only feature: it adds an attribute to rendered
 * markup and never touches files, the database, or `post_content`. The
 * plugin's destructive cleanup behaviour does not apply to it.
 *
 * @package TIMU_Image_Support
 * @since   1.6144
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fill in alt text when it would otherwise be empty.
 *
 * @since 1.6144
 *
 * @param array<string,string> $attr       Image attributes being rendered.
 * @param WP_Post              $attachment Attachment post object.
 * @return array<string,string> Attributes with an `alt` fallback applied when one was missing.
 */
function thisismyurl_image_support_alt_fallback( $attr, $attachment ) {
	if ( ! is_array( $attr ) ) {
		$attr = array();
	}

	// Already has a non-empty alt — nothing to do.
	if ( isset( $attr['alt'] ) && '' !== trim( (string) $attr['alt'] ) ) {
		return $attr;
	}

	// Explicit decorative opt-out: the caller passed an empty alt AND set
	// `data-decorative` to a non-empty value. Respect it — a purely
	// decorative image should stay alt="" and not add screen-reader noise.
	if ( isset( $attr['data-decorative'] ) && '' !== (string) $attr['data-decorative'] ) {
		return $attr;
	}

	$fallback = thisismyurl_image_support_resolve_alt_fallback( $attachment );

	if ( '' !== $fallback ) {
		$attr['alt'] = $fallback;
	}

	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'thisismyurl_image_support_alt_fallback', 10, 2 );

/**
 * Resolve the best available fallback alt string for an attachment.
 *
 * Walks the three-tier ladder (stored alt meta, post title, humanised
 * filename) and returns the first non-empty result, or '' when the
 * attachment yields nothing usable. Extracted from the filter so the
 * priority ladder reads as one linear pass with early returns rather than a
 * stack of nested conditionals.
 *
 * @since 1.6144
 *
 * @param mixed $attachment Attachment post object (anything else yields '').
 * @return string Fallback alt text, or '' when none could be derived.
 */
function thisismyurl_image_support_resolve_alt_fallback( $attachment ) {
	if ( ! $attachment instanceof WP_Post ) {
		return '';
	}

	// 1. The attachment's stored alt meta.
	$meta_alt = (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
	if ( '' !== trim( $meta_alt ) ) {
		return trim( $meta_alt );
	}

	// 2. The attachment title.
	if ( '' !== trim( (string) $attachment->post_title ) ) {
		return trim( (string) $attachment->post_title );
	}

	// 3. The humanised filename basename.
	$file = get_attached_file( $attachment->ID );
	if ( ! is_string( $file ) || '' === $file ) {
		return '';
	}

	$humanised = trim( (string) preg_replace( '/[-_]+/', ' ', pathinfo( $file, PATHINFO_FILENAME ) ) );
	// Drop a trailing -NNNxNNN dimension suffix (e.g. "photo 1024x768").
	$humanised = trim( (string) preg_replace( '/\s+\d+x\d+$/', '', $humanised ) );

	return '' !== $humanised ? ucfirst( $humanised ) : '';
}
