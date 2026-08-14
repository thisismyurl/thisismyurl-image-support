<?php
/**
 * Make the Media search find images by their alt text.
 *
 * Core's attachment search already covers title, caption, description and filename. It
 * does not cover alt text, which is often the only place the actual subject of a photo
 * is written down. Searching "harbour" should find the harbour photo even when the file
 * is called IMG_4821.jpg.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends the Media list search to include alt text.
 */
final class TIMU_Media_Search {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'posts_search', array( __CLASS__, 'extend_search' ), 10, 2 );
		add_filter( 'posts_clauses', array( __CLASS__, 'add_join' ), 10, 2 );
	}

	/**
	 * Whether this query is the Media list screen doing a search.
	 *
	 * @param WP_Query $query Query being run.
	 * @return bool
	 */
	private static function applies( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return false;
		}

		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && 'upload' === $screen->id;
	}

	/**
	 * Join the alt-text meta row so the search clause can reach it.
	 *
	 * A LEFT JOIN with an explicit meta_key, rather than meta_query, because meta_query
	 * would turn the join into a filter and drop every image that has no alt row at all.
	 * The search needs those rows present so they can still match on title or filename.
	 *
	 * @param array    $clauses Query clauses.
	 * @param WP_Query $query   Query being run.
	 * @return array
	 */
	public static function add_join( $clauses, $query ) {
		global $wpdb;

		if ( ! self::applies( $query ) ) {
			return $clauses;
		}

		if ( false !== strpos( $clauses['join'], 'timu_alt_meta' ) ) {
			return $clauses;
		}

		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} AS timu_alt_meta"
			. " ON ( {$wpdb->posts}.ID = timu_alt_meta.post_id AND timu_alt_meta.meta_key = %s ) ",
			'_wp_attachment_image_alt'
		);

		// The join can multiply rows if an attachment somehow carries duplicate alt meta.
		$clauses['groupby'] = "{$wpdb->posts}.ID";

		return $clauses;
	}

	/**
	 * Add alt text to the search clause.
	 *
	 * Core has already built a parenthesised OR clause across its own columns for each
	 * search term. Rather than rebuild that, each term's group is extended with one more
	 * OR against the joined alt column, which keeps core's AND-between-terms behaviour
	 * intact: searching "red harbour" still requires both words somewhere.
	 *
	 * @param string   $search Search SQL.
	 * @param WP_Query $query  Query being run.
	 * @return string
	 */
	public static function extend_search( $search, $query ) {
		global $wpdb;

		if ( ! self::applies( $query ) || '' === trim( (string) $search ) ) {
			return $search;
		}

		$terms = $query->get( 'search_terms' );

		if ( empty( $terms ) || ! is_array( $terms ) ) {
			$terms = array( $query->get( 's' ) );
		}

		foreach ( $terms as $term ) {
			$term = (string) $term;

			if ( '' === trim( $term ) ) {
				continue;
			}

			$like = '%' . $wpdb->esc_like( $term ) . '%';

			// Find the closing parenthesis of this term's group and widen it. Anchoring on
			// core's own post_content comparison keeps this working without assuming the
			// exact column order core used.
			$needle = $wpdb->prepare( "({$wpdb->posts}.post_content LIKE %s)", $like );

			if ( false === strpos( $search, $needle ) ) {
				continue;
			}

			$replacement = $needle . $wpdb->prepare( " OR (timu_alt_meta.meta_value LIKE %s)", $like );
			$search      = str_replace( $needle, $replacement, $search );
		}

		return $search;
	}
}
