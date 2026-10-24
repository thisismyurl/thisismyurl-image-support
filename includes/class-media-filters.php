<?php
/**
 * Filtering the Media list by alt-text state and by image weight.
 *
 * "We should fix our alt text" is an unbounded worry until you can see the actual list.
 * This turns it into a finite, workable set.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the alt-state and oversize filters to the Media list screen.
 */
final class TIMU_Image_Support_Media_Filters {

	const QUERY_VAR = 'timu_image_filter';

	/**
	 * Every cache the PHP-side scans keep.
	 *
	 * Listed in one place because the alternative was remembering to name each transient
	 * at each of the several points that can invalidate them, and forgetting one is
	 * exactly how this screen twice reported a number that was no longer true.
	 */
	const SCAN_CACHES = array( 'timu_image_oversized_ids', 'timu_image_broken_ids' );

	/**
	 * Drop every scan cache.
	 */
	public static function flush_scans() {
		foreach ( self::SCAN_CACHES as $key ) {
			delete_transient( $key );
		}
	}

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_dropdown' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filter' ) );
	}

	/**
	 * Available filters.
	 *
	 * @return array<string,string>
	 */
	private static function choices() {
		return array(
			// This dropdown sits beside core's own media-type filter, which covers every
			// kind of file, so every label here names images explicitly. Otherwise "Files
			// over 1 MB" reads as though it might turn up a PDF.
			''            => __( 'All images', 'thisismyurl-image-support' ),
			'missing-alt' => __( 'Images missing alt text', 'thisismyurl-image-support' ),
			'has-alt'     => __( 'Images with alt text', 'thisismyurl-image-support' ),
			'oversized'   => __( 'Oversized originals', 'thisismyurl-image-support' ),
			'broken'      => __( 'Images with no file', 'thisismyurl-image-support' ),

			// Reads the configured threshold rather than saying "1 MB". Once that number
			// became a setting, a fixed label was simply untrue for anybody who changed it.
			'heavy'       => sprintf(
				/* translators: %s: file size, e.g. "2 MB". */
				__( 'Images over %s', 'thisismyurl-image-support' ),
				size_format( TIMU_Image_Support_Facts::heavy_bytes(), 0 )
			),
		);
	}

	/**
	 * Output the dropdown next to core's own Media filters.
	 *
	 * The Media list table fires `restrict_manage_posts` with `$which` set to 'bar',
	 * not the 'top' that the posts list table uses. Guarding on 'top' here silently
	 * rendered nothing at all: the filters still worked if you typed the query string
	 * by hand, but no control ever appeared for anyone to click.
	 *
	 * @param string $post_type Post type being listed.
	 * @param string $which     Which tablenav is rendering.
	 */
	public static function render_dropdown( $post_type, $which ) {
		if ( 'attachment' !== $post_type || ! in_array( $which, array( 'bar', 'top' ), true ) ) {
			return;
		}

		// Reading a filter value to repopulate a dropdown is not a state change, so no
		// nonce is required; the value is validated against a fixed list below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		$choices = self::choices();

		if ( ! isset( $choices[ $current ] ) ) {
			$current = '';
		}

		echo '<label class="screen-reader-text" for="' . esc_attr( self::QUERY_VAR ) . '">'
			. esc_html__( 'Filter images', 'thisismyurl-image-support' ) . '</label>';
		echo '<select name="' . esc_attr( self::QUERY_VAR ) . '" id="' . esc_attr( self::QUERY_VAR ) . '">';

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Apply the selected filter to the Media query.
	 *
	 * @param WP_Query $query Query being run.
	 */
	public static function apply_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter, validated against a fixed list.
		$filter = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		if ( '' === $filter || ! array_key_exists( $filter, self::choices() ) ) {
			return;
		}

		// Every filter here is about images, so narrow the type first. Without this,
		// "missing alt text" would list every PDF on the site.
		$query->set( 'post_mime_type', TIMU_Image_Support_Facts::supported_mimes() );

		switch ( $filter ) {
			case 'missing-alt':
				// Missing means either no row at all, or a row holding an empty string.
				// Checking only for NOT EXISTS misses every image whose alt was typed and
				// then cleared, which is a large share of them in practice.
				$query->set(
					'meta_query',
					array(
						'relation' => 'OR',
						array(
							'key'     => '_wp_attachment_image_alt',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_wp_attachment_image_alt',
							'value'   => '',
							'compare' => '=',
						),
					)
				);
				break;

			case 'has-alt':
				$query->set(
					'meta_query',
					array(
						array(
							'key'     => '_wp_attachment_image_alt',
							'value'   => '',
							'compare' => '!=',
						),
					)
				);
				break;

			case 'heavy':
				$query->set(
					'meta_query',
					array(
						array(
							'key'     => TIMU_Image_Support_Facts::SIZE_META,
							'value'   => TIMU_Image_Support_Facts::heavy_bytes(),
							'compare' => '>=',
							'type'    => 'NUMERIC',
						),
					)
				);
				break;

			case 'broken':
				$ids = self::broken_ids();
				$query->set( 'post__in', $ids ? $ids : array( 0 ) );
				break;

			case 'oversized':
				// Width lives inside serialized metadata and cannot be compared in SQL, so
				// the set is resolved in PHP and passed back as an ID list. See
				// oversized_ids() for why that is acceptable here and where it stops being
				// acceptable.
				$ids = self::oversized_ids();
				$query->set( 'post__in', $ids ? $ids : array( 0 ) );
				break;
		}
	}

	/**
	 * IDs of images meaningfully wider than the site's largest registered size.
	 *
	 * There is no SQL answer to this: the width is inside `_wp_attachment_metadata`,
	 * serialized. So the candidate set is walked in PHP.
	 *
	 * That is bounded deliberately. Only images at least as large as the flag threshold
	 * could possibly qualify, and the walk is capped, so the cost is proportional to how
	 * many big images a site has rather than to the size of the library. The result is
	 * cached for the request and for a short window afterwards.
	 *
	 * @return int[]
	 */
	public static function oversized_ids() {
		$cache_key = 'timu_image_oversized_ids';
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = array();

		foreach ( self::scan_candidates() as $id ) {
			if ( TIMU_Image_Support_Facts::is_oversized( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		set_transient( $cache_key, $ids, 10 * MINUTE_IN_SECONDS );

		return $ids;
	}

	/**
	 * IDs of images whose file is no longer on disk.
	 *
	 * Same bounded, cached shape as the oversize scan, and for the same reason: there is no
	 * SQL answer to "does this file exist", so the candidate set is walked in PHP and the
	 * result is held for a short window.
	 *
	 * @return int[]
	 */
	public static function broken_ids() {
		$cache_key = 'timu_image_broken_ids';
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = array();

		foreach ( self::scan_candidates() as $id ) {
			if ( TIMU_Image_Support_Facts::file_missing( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		set_transient( $cache_key, $ids, 10 * MINUTE_IN_SECONDS );

		return $ids;
	}

	/**
	 * The bounded candidate set both PHP-side scans walk.
	 *
	 * @return int[]
	 */
	private static function scan_candidates() {
		$cap = (int) apply_filters( 'timu_image_support_oversize_scan_limit', 2000 );

		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => TIMU_Image_Support_Facts::supported_mimes(),
				'posts_per_page' => $cap,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Drop the oversize cache when an attachment's metadata changes.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata.
	 */
	public static function flush_oversize_cache( $metadata, $attachment_id ) {
		self::flush_scans();

		return $metadata;
	}
}
