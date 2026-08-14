<?php
/**
 * Alt text, dimensions and file size columns on the Media list screen.
 *
 * Core's Media list shows File, Author, Uploaded to and Date. It does not show alt
 * text, how big an image is on disk, or what its dimensions are, which means the three
 * questions people most often have about their images cannot be answered from the
 * screen that lists them.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds and renders this plugin's Media list columns.
 */
final class TIMU_Media_Columns {

	const COL_ALT  = 'timu_alt';
	const COL_DIMS = 'timu_dimensions';
	const COL_SIZE = 'timu_filesize';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_columns' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_upload_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_sorting' ) );
	}

	/**
	 * Insert the columns after the file name.
	 *
	 * Placed before Author rather than appended, because these describe the file and
	 * belong next to it. Author and Date keep their trailing position.
	 *
	 * @param string[] $columns Existing columns.
	 * @return string[]
	 */
	public static function add_columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new[ self::COL_ALT ]  = __( 'Alt text', 'thisismyurl-image-support' );
				$new[ self::COL_DIMS ] = __( 'Dimensions', 'thisismyurl-image-support' );
				$new[ self::COL_SIZE ] = __( 'File size', 'thisismyurl-image-support' );
			}
		}

		// If core ever renames the title column, still show ours rather than silently
		// dropping them.
		if ( ! isset( $new[ self::COL_ALT ] ) ) {
			$new[ self::COL_ALT ]  = __( 'Alt text', 'thisismyurl-image-support' );
			$new[ self::COL_DIMS ] = __( 'Dimensions', 'thisismyurl-image-support' );
			$new[ self::COL_SIZE ] = __( 'File size', 'thisismyurl-image-support' );
		}

		return $new;
	}

	/**
	 * Render one cell.
	 *
	 * @param string $column        Column key.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function render_column( $column, $attachment_id ) {
		if ( ! in_array( $column, array( self::COL_ALT, self::COL_DIMS, self::COL_SIZE ), true ) ) {
			return;
		}

		// Non-images get an em dash rather than a misleading blank, so the row still
		// reads as "this column does not apply" instead of "this value is missing".
		if ( ! TIMU_Image_Facts::is_supported_image( $attachment_id ) ) {
			echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">'
				. esc_html__( 'Not an image', 'thisismyurl-image-support' ) . '</span>';
			return;
		}

		switch ( $column ) {
			case self::COL_ALT:
				self::render_alt( (int) $attachment_id );
				break;
			case self::COL_DIMS:
				self::render_dimensions( (int) $attachment_id );
				break;
			case self::COL_SIZE:
				self::render_filesize( (int) $attachment_id );
				break;
		}
	}

	/**
	 * Alt text cell, with the inline editor attached.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function render_alt( $attachment_id ) {
		$alt      = TIMU_Image_Facts::stored_alt( $attachment_id );
		$editable = current_user_can( 'edit_post', $attachment_id );

		if ( ! $editable ) {
			if ( '' === $alt ) {
				echo '<span class="timu-alt-missing">' . esc_html__( 'Missing', 'thisismyurl-image-support' ) . '</span>';
			} else {
				echo esc_html( $alt );
			}
			return;
		}

		printf(
			'<div class="timu-alt-cell" data-id="%1$d">
				<label class="screen-reader-text" for="timu-alt-%1$d">%2$s</label>
				<input type="text" class="timu-alt-input" id="timu-alt-%1$d" value="%3$s" placeholder="%4$s" />
				<button type="button" class="button button-small timu-alt-save" hidden>%5$s</button>
				<span class="timu-alt-state" role="status" aria-live="polite"></span>
			</div>',
			(int) $attachment_id,
			/* translators: %s: image file name. */
			esc_attr( sprintf( __( 'Alt text for %s', 'thisismyurl-image-support' ), get_the_title( $attachment_id ) ) ),
			esc_attr( $alt ),
			esc_attr__( 'Describe this image', 'thisismyurl-image-support' ),
			esc_html__( 'Save', 'thisismyurl-image-support' )
		);
	}

	/**
	 * Dimensions cell, flagging images far larger than the theme can use.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function render_dimensions( $attachment_id ) {
		$dims = TIMU_Image_Facts::dimensions( $attachment_id );

		if ( $dims['width'] <= 0 || $dims['height'] <= 0 ) {
			echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">'
				. esc_html__( 'Unknown', 'thisismyurl-image-support' ) . '</span>';
			return;
		}

		printf(
			'<span class="timu-dims">%s</span>',
			esc_html( sprintf( '%d &times; %d', $dims['width'], $dims['height'] ) )
		);

		if ( ! TIMU_Image_Facts::is_oversized( $attachment_id ) ) {
			return;
		}

		$factor = TIMU_Image_Facts::oversize_factor( $attachment_id );
		$widest = TIMU_Image_Facts::widest_served_width();

		printf(
			'<span class="timu-oversize" title="%s">%s</span>',
			esc_attr(
				sprintf(
					/* translators: 1: multiplier, 2: widest registered image width in pixels. */
					__( 'This image is %1$s times wider than the largest size this site ever serves (%2$dpx). It escaped the scaling WordPress normally applies on upload, so the extra pixels sit in your uploads folder and in every backup.', 'thisismyurl-image-support' ),
					number_format_i18n( $factor, 1 ),
					$widest
				)
			),
			esc_html__( 'Oversized', 'thisismyurl-image-support' )
		);
	}

	/**
	 * File size cell.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function render_filesize( $attachment_id ) {
		$bytes = TIMU_Image_Facts::filesize( $attachment_id );

		if ( $bytes <= 0 ) {
			echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">'
				. esc_html__( 'Unknown', 'thisismyurl-image-support' ) . '</span>';
			return;
		}

		// A megabyte of image is worth noticing on a page that has several of them.
		$heavy = $bytes >= (int) apply_filters( 'timu_image_support_heavy_bytes', MB_IN_BYTES );

		printf(
			'<span class="timu-size%s">%s</span>',
			$heavy ? ' is-heavy' : '',
			esc_html( size_format( $bytes, $bytes >= MB_IN_BYTES ? 1 : 0 ) )
		);
	}

	/**
	 * Declare which of our columns can be sorted.
	 *
	 * Dimensions is intentionally absent. Width and height live inside the serialized
	 * attachment metadata and there is no honest way to sort on them without a second
	 * index; promising a sort that silently did nothing would be worse than not
	 * offering it.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns[ self::COL_SIZE ] = self::COL_SIZE;
		$columns[ self::COL_ALT ]  = self::COL_ALT;

		return $columns;
	}

	/**
	 * Apply sorting for our columns.
	 *
	 * File size sorts on the numeric index maintained by TIMU_Image_Facts. Attachments
	 * with no index yet are included via the LEFT JOIN that `meta_query` builds, so a
	 * partially backfilled library still sorts sensibly rather than losing rows.
	 *
	 * @param WP_Query $query Query being run.
	 */
	public static function apply_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( self::COL_SIZE === $orderby ) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					array( 'key' => TIMU_Image_Facts::SIZE_META, 'compare' => 'EXISTS' ),
					array( 'key' => TIMU_Image_Facts::SIZE_META, 'compare' => 'NOT EXISTS' ),
				)
			);
			$query->set( 'orderby', 'meta_value_num' );
			$query->set( 'meta_key', TIMU_Image_Facts::SIZE_META );
			return;
		}

		if ( self::COL_ALT === $orderby ) {
			// Sorting by alt groups the described and the undescribed together, which is
			// the useful reading of "sort by alt text" on this screen.
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'EXISTS' ),
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				)
			);
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'meta_key', '_wp_attachment_image_alt' );
		}
	}
}
