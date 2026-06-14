<?php
/**
 * Media organization: a hierarchical "Folder" taxonomy on attachments plus the
 * Media-library filters that make the library browsable by folder and by
 * completeness (missing alt text, junk filename, oversized).
 *
 * Part A — folders/collections: register the taxonomy, add a folder dropdown to
 * the Media list table, and provide an Organize tab to create folders and
 * bulk-assign attachments via AJAX.
 *
 * Part B — completeness filters: a second Media-library dropdown that filters by
 * a curation gap. Only the criteria that stay cheap as a live upload.php query
 * run here (missing alt via meta_query; junk-name and oversized via a bounded
 * pre-computed `post__in`). The expensive signals (unused, duplicates, GPS) are
 * left to the Audit and Health tabs and linked, never run live.
 *
 * Benign by construction: this module registers a taxonomy and reads/writes term
 * relationships. It never renames a file, rewrites post_content, or deletes an
 * attachment.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Folder taxonomy, Media-library filters, and the Organize admin surface.
 */
class TIMU_IC_Media_Organization {

	/**
	 * Taxonomy key for the attachment folder/collection taxonomy.
	 */
	const TAXONOMY = 'timu_media_folder';

	/**
	 * Query-var name the upload.php folder filter reads and writes.
	 */
	const FOLDER_QUERY_VAR = 'timu_media_folder';

	/**
	 * Query-var name the upload.php completeness filter reads and writes.
	 */
	const COMPLETENESS_QUERY_VAR = 'timu_completeness';

	/**
	 * Upper bound on how many attachments the bounded completeness filters
	 * (junk-name, oversized) inspect when building their `post__in` set. Mirrors
	 * the Health Score's SCAN_CAP discipline so a very large library cannot stall
	 * a Media-library page load. When the library exceeds the cap, the filter
	 * reports against the first N images by ID and the result is partial.
	 */
	const COMPLETENESS_SCAN_CAP = 2000;

	/**
	 * Page size for the bounded walk the completeness filters use.
	 */
	const PAGE_SIZE = 200;

	/**
	 * Completeness criteria that run as live upload.php filters.
	 *
	 * @return string[]
	 */
	public static function live_completeness_criteria() {
		return array( 'missing_alt', 'junk_name', 'oversized' );
	}

	/**
	 * Wire up taxonomy registration, Media-library filters, and AJAX handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );

		// Media list-table filter UI + the query rewrite that applies them.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_media_filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_media_filters' ) );

		// Organize tab AJAX: create folder + assign selected attachments.
		add_action( 'wp_ajax_timu_ic_create_folder', array( __CLASS__, 'ajax_create_folder' ) );
		add_action( 'wp_ajax_timu_ic_assign_folder', array( __CLASS__, 'ajax_assign_folder' ) );
	}

	// -------------------------------------------------------------------------
	// Part A — taxonomy registration
	// -------------------------------------------------------------------------

	/**
	 * Register the hierarchical Folder taxonomy on the attachment post type.
	 *
	 * `public => false` keeps folders out of the front end entirely — they are a
	 * back-of-house curation tool, not a public archive. `show_in_rest` is on so
	 * the block editor and REST clients can read and set folders; `show_ui` and
	 * `show_admin_column` surface it in wp-admin.
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		$labels = array(
			'name'              => _x( 'Folders', 'taxonomy general name', 'thisismyurl-image-support' ),
			'singular_name'     => _x( 'Folder', 'taxonomy singular name', 'thisismyurl-image-support' ),
			'menu_name'         => __( 'Folders', 'thisismyurl-image-support' ),
			'all_items'         => __( 'All folders', 'thisismyurl-image-support' ),
			'edit_item'         => __( 'Edit folder', 'thisismyurl-image-support' ),
			'view_item'         => __( 'View folder', 'thisismyurl-image-support' ),
			'update_item'       => __( 'Update folder', 'thisismyurl-image-support' ),
			'add_new_item'      => __( 'Add new folder', 'thisismyurl-image-support' ),
			'new_item_name'     => __( 'New folder name', 'thisismyurl-image-support' ),
			'parent_item'       => __( 'Parent folder', 'thisismyurl-image-support' ),
			'parent_item_colon' => __( 'Parent folder:', 'thisismyurl-image-support' ),
			'search_items'      => __( 'Search folders', 'thisismyurl-image-support' ),
			'not_found'         => __( 'No folders found.', 'thisismyurl-image-support' ),
		);

		register_taxonomy(
			self::TAXONOMY,
			'attachment',
			array(
				'labels'             => $labels,
				'hierarchical'       => true,
				'public'             => false,
				'show_ui'            => true,
				'show_admin_column'  => true,
				'show_in_rest'       => true,
				'show_in_nav_menus'  => false,
				'show_tagcloud'      => false,
				'query_var'          => self::TAXONOMY,
				'rewrite'            => false,
				'capabilities'       => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'upload_files',
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Media-library list-table filters (folder + completeness)
	// -------------------------------------------------------------------------

	/**
	 * Render the Folder and Completeness dropdowns above the Media list table.
	 *
	 * Only fires on the attachment list view; the grid view (mode=grid) renders
	 * its own toolbar through the media-views JS and is intentionally left to the
	 * Audit/Organize surfaces. See the class docblock note on grid view.
	 *
	 * @param string $post_type Current list-table post type.
	 *
	 * @return void
	 */
	public static function render_media_filters( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		self::render_folder_dropdown();
		self::render_completeness_dropdown();
	}

	/**
	 * Output the folder filter dropdown.
	 *
	 * @return void
	 */
	private static function render_folder_dropdown() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table filter; no state change.
		$selected = isset( $_GET[ self::FOLDER_QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::FOLDER_QUERY_VAR ] ) ) : '';

		echo '<label class="screen-reader-text" for="timu-media-folder-filter">'
			. esc_html__( 'Filter by folder', 'thisismyurl-image-support' ) . '</label>';
		echo '<select name="' . esc_attr( self::FOLDER_QUERY_VAR ) . '" id="timu-media-folder-filter">';
		echo '<option value="">' . esc_html__( 'All folders', 'thisismyurl-image-support' ) . '</option>';

		foreach ( $terms as $term ) {
			$padding = str_repeat( '— ', max( 0, self::term_depth( $term ) ) );
			printf(
				'<option value="%1$s"%2$s>%3$s%4$s (%5$s)</option>',
				esc_attr( $term->slug ),
				selected( $selected, $term->slug, false ),
				esc_html( $padding ),
				esc_html( $term->name ),
				esc_html( number_format_i18n( $term->count ) )
			);
		}

		echo '</select>';
	}

	/**
	 * Output the completeness filter dropdown plus links to the Audit-tab views
	 * for the expensive criteria that cannot run live.
	 *
	 * @return void
	 */
	private static function render_completeness_dropdown() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table filter; no state change.
		$selected = isset( $_GET[ self::COMPLETENESS_QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::COMPLETENESS_QUERY_VAR ] ) ) : '';

		$options = array(
			'missing_alt' => __( 'Missing alt text', 'thisismyurl-image-support' ),
			'junk_name'   => __( 'Junk filename', 'thisismyurl-image-support' ),
			'oversized'   => __( 'Oversized dimensions', 'thisismyurl-image-support' ),
		);

		echo '<label class="screen-reader-text" for="timu-completeness-filter">'
			. esc_html__( 'Filter by completeness', 'thisismyurl-image-support' ) . '</label>';
		echo '<select name="' . esc_attr( self::COMPLETENESS_QUERY_VAR ) . '" id="timu-completeness-filter">';
		echo '<option value="">' . esc_html__( 'Any completeness', 'thisismyurl-image-support' ) . '</option>';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Apply the folder and completeness filters to the Media list query.
	 *
	 * Guards: admin only, the main query only, the attachment list table only,
	 * and a capability check. The folder filter narrows by term; the completeness
	 * filter either adds a meta_query (missing alt) or resolves a bounded
	 * `post__in` set (junk-name, oversized) so the live query stays fast.
	 *
	 * @param WP_Query $query The query about to run.
	 *
	 * @return void
	 */
	public static function apply_media_filters( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->base ) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		self::maybe_apply_folder_filter( $query );
		self::maybe_apply_completeness_filter( $query );
	}

	/**
	 * Narrow the Media query to a selected folder term via tax_query.
	 *
	 * @param WP_Query $query The Media list query.
	 *
	 * @return void
	 */
	private static function maybe_apply_folder_filter( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table filter.
		$slug = isset( $_GET[ self::FOLDER_QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::FOLDER_QUERY_VAR ] ) ) : '';
		if ( '' === $slug ) {
			return;
		}

		$tax_query   = (array) $query->get( 'tax_query' );
		$tax_query[] = array(
			'taxonomy' => self::TAXONOMY,
			'field'    => 'slug',
			'terms'    => $slug,
		);

		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Apply the selected completeness criterion to the Media query.
	 *
	 * @param WP_Query $query The Media list query.
	 *
	 * @return void
	 */
	private static function maybe_apply_completeness_filter( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table filter.
		$criterion = isset( $_GET[ self::COMPLETENESS_QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::COMPLETENESS_QUERY_VAR ] ) ) : '';
		if ( '' === $criterion || ! in_array( $criterion, self::live_completeness_criteria(), true ) ) {
			return;
		}

		if ( 'missing_alt' === $criterion ) {
			self::apply_missing_alt_filter( $query );
			return;
		}

		// Junk-name and oversized are not expressible as a pure meta_query, so we
		// resolve a bounded set of matching IDs and constrain the query to them.
		$ids = ( 'junk_name' === $criterion )
			? self::collect_junk_name_ids()
			: self::collect_oversized_ids();

		// An empty match set must yield zero rows, not "no filter". Sentinel 0.
		$existing = (array) $query->get( 'post__in' );
		$ids      = empty( $existing ) ? $ids : array_values( array_intersect( $existing, $ids ) );
		$query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );
	}

	/**
	 * Add the missing-alt meta_query to the Media query.
	 *
	 * Mirrors TIMU_IC_Audit::get_missing_alt_text(): the meta row is absent or
	 * its value is empty.
	 *
	 * @param WP_Query $query The Media list query.
	 *
	 * @return void
	 */
	private static function apply_missing_alt_filter( $query ) {
		$meta_query = (array) $query->get( 'meta_query' );

		$meta_query[] = array(
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
		);

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$query->set( 'meta_query', $meta_query );
	}

	// -------------------------------------------------------------------------
	// Bounded ID collectors for the non-meta completeness filters
	// -------------------------------------------------------------------------

	/**
	 * Collect attachment IDs whose basename matches the junk-filename patterns.
	 *
	 * Reuses TIMU_IC_Score::is_junk_basename so the Media filter and the Health
	 * Score agree on what "junk" means. Bounded by COMPLETENESS_SCAN_CAP.
	 *
	 * @return int[] Matching attachment IDs (may be empty).
	 */
	private static function collect_junk_name_ids() {
		$ids     = array();
		$scanned = 0;

		foreach ( self::walk_image_ids() as $id ) {
			if ( $scanned >= self::COMPLETENESS_SCAN_CAP ) {
				break;
			}
			++$scanned;

			$file = get_attached_file( $id );
			if ( ! $file ) {
				continue;
			}
			if ( TIMU_IC_Score::is_junk_basename( wp_basename( $file ) ) ) {
				$ids[] = (int) $id;
			}
		}

		return $ids;
	}

	/**
	 * Collect attachment IDs whose stored width or height exceeds the configured
	 * max dimension.
	 *
	 * Reads `_wp_attachment_metadata` width/height via wp_get_attachment_metadata,
	 * matching the Health Score's oversized factor. Bounded by COMPLETENESS_SCAN_CAP.
	 *
	 * @return int[] Matching attachment IDs (may be empty).
	 */
	private static function collect_oversized_ids() {
		$options = TIMU_IC_Options::get();
		$max     = isset( $options['max_dimension'] ) ? (int) $options['max_dimension'] : 2560;

		$ids     = array();
		$scanned = 0;

		foreach ( self::walk_image_ids() as $id ) {
			if ( $scanned >= self::COMPLETENESS_SCAN_CAP ) {
				break;
			}
			++$scanned;

			$meta = wp_get_attachment_metadata( $id );
			$w    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
			$h    = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
			if ( $w > $max || $h > $max ) {
				$ids[] = (int) $id;
			}
		}

		return $ids;
	}

	/**
	 * Generator yielding image-attachment IDs in bounded pages, oldest first.
	 *
	 * Same shape as the Score and Audit walkers: `fields=ids`, `no_found_rows`,
	 * paged, so memory stays flat regardless of library size.
	 *
	 * @return Generator<int>
	 */
	private static function walk_image_ids() {
		$paged     = 1;
		$max_loops = 10000;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image',
					'posts_per_page' => self::PAGE_SIZE,
					'paged'          => $paged,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $id ) {
				yield (int) $id;
			}

			++$paged;
			--$max_loops;
		} while ( $max_loops > 0 );
	}

	// -------------------------------------------------------------------------
	// Part A — Organize tab AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: create a folder term.
	 *
	 * @return void
	 */
	public static function ajax_create_folder() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$parent = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;

		if ( '' === $name ) {
			wp_send_json_error( __( 'A folder name is required.', 'thisismyurl-image-support' ) );
		}

		$result = self::create_folder( $name, $parent );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Create a folder term and return its display payload.
	 *
	 * Split from the AJAX handler so the smoke test and any future CLI caller can
	 * exercise the create path without forging a nonce.
	 *
	 * @param string $name   Folder name.
	 * @param int    $parent Parent term ID, or 0 for a top-level folder.
	 *
	 * @return array{term_id:int,name:string,slug:string}|WP_Error
	 */
	public static function create_folder( $name, $parent = 0 ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'empty_name', __( 'A folder name is required.', 'thisismyurl-image-support' ) );
		}

		$args = array();
		if ( $parent > 0 && term_exists( $parent, self::TAXONOMY ) ) {
			$args['parent'] = (int) $parent;
		}

		$inserted = wp_insert_term( $name, self::TAXONOMY, $args );
		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		$term = get_term( (int) $inserted['term_id'], self::TAXONOMY );
		if ( ! $term instanceof WP_Term ) {
			return new WP_Error( 'lookup_failed', __( 'The folder was created but could not be read back.', 'thisismyurl-image-support' ) );
		}

		return array(
			'term_id' => (int) $term->term_id,
			'name'    => $term->name,
			'slug'    => $term->slug,
		);
	}

	/**
	 * AJAX: assign a set of attachments to a folder term.
	 *
	 * @return void
	 */
	public static function ajax_assign_folder() {
		check_ajax_referer( TIMU_IC::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-image-support' ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$ids     = isset( $_POST['attachment_ids'] ) ? (array) wp_unslash( $_POST['attachment_ids'] ) : array();
		$ids     = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( ! $term_id ) {
			wp_send_json_error( __( 'Choose a folder to assign to.', 'thisismyurl-image-support' ) );
		}
		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'Select at least one image to assign.', 'thisismyurl-image-support' ) );
		}

		$result = self::assign_to_folder( $ids, $term_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Assign a list of attachments to a folder term, appending (not replacing).
	 *
	 * Skips any ID that is not an attachment. Returns the per-id outcome so the
	 * caller can report assigned vs skipped.
	 *
	 * @param int[] $attachment_ids Attachment IDs to assign.
	 * @param int   $term_id        Target folder term ID.
	 *
	 * @return array{assigned:int[],skipped:int[],term_id:int}|WP_Error
	 */
	public static function assign_to_folder( $attachment_ids, $term_id ) {
		$term_id = (int) $term_id;
		if ( ! term_exists( $term_id, self::TAXONOMY ) ) {
			return new WP_Error( 'no_term', __( 'That folder no longer exists.', 'thisismyurl-image-support' ) );
		}

		$assigned = array();
		$skipped  = array();

		foreach ( (array) $attachment_ids as $id ) {
			$id = (int) $id;
			if ( 'attachment' !== get_post_type( $id ) ) {
				$skipped[] = $id;
				continue;
			}

			$result = wp_set_object_terms( $id, $term_id, self::TAXONOMY, true );
			if ( is_wp_error( $result ) ) {
				$skipped[] = $id;
				continue;
			}

			$assigned[] = $id;
		}

		return array(
			'assigned' => $assigned,
			'skipped'  => $skipped,
			'term_id'  => $term_id,
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Compute a term's depth in the folder hierarchy for indented display.
	 *
	 * @param WP_Term $term The term.
	 *
	 * @return int Depth, 0 for a top-level folder.
	 */
	private static function term_depth( $term ) {
		$depth  = 0;
		$parent = (int) $term->parent;
		$guard  = 0;

		while ( $parent > 0 && $guard < 50 ) {
			$next = get_term( $parent, self::TAXONOMY );
			if ( ! $next instanceof WP_Term ) {
				break;
			}
			++$depth;
			$parent = (int) $next->parent;
			++$guard;
		}

		return $depth;
	}
}
