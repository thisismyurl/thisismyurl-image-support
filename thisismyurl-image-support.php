<?php
/**
 * Plugin Name:       Thisismyurl Image Support
 * Plugin URI:        https://thisismyurl.com/downloads/thisismyurl-image-support/
 * Description:       See what is in your image library: missing alt text, oversized or broken images, and files nothing refers to. Edit alt text in place.
 * Version:           1.6291
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       thisismyurl-image-support
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TIMU_IMAGE_SUPPORT_VERSION' ) ) {
	define( 'TIMU_IMAGE_SUPPORT_VERSION', '1.6291' );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-image-facts.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-media-columns.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-media-filters.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-media-search.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-alt-editor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-library-report.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-reference-scan.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-admin-page.php';

/**
 * Bootstrap.
 *
 * Everything here is wp-admin only, and all of it is scoped to the Media list screen.
 * The plugin adds no front-end behaviour, registers no post types, and changes nothing
 * about how images are uploaded or rendered.
 */
final class TIMU_Image_Support {

	/**
	 * Wire up.
	 */
	public static function init() {
		// Whatever keeps the saved results in step with the library has to run wherever an
		// attachment can change, which includes the REST API, WP-CLI and scheduled jobs and
		// not only wp-admin. Those callbacks are cheap: each one deletes a cached value.
		TIMU_Image_Support_Admin_Page::init_cache_hooks();
		TIMU_Image_Support_Reference_Scan::init();

		add_filter( 'wp_update_attachment_metadata', array( 'TIMU_Image_Support_Facts', 'sync_size_index' ), 10, 2 );
		add_filter( 'wp_update_attachment_metadata', array( 'TIMU_Image_Support_Media_Filters', 'flush_oversize_cache' ), 10, 2 );

		// Everything else is a screen or a column, which is meaningful only in the admin, so
		// the front-end request stays out of it entirely.
		if ( ! is_admin() ) {
			return;
		}

		TIMU_Image_Support_Media_Columns::init();
		TIMU_Image_Support_Media_Filters::init();
		TIMU_Image_Support_Media_Search::init();
		TIMU_Image_Support_Alt_Editor::init();
		TIMU_Image_Support_Admin_Page::init();

		// A plugin that adds columns to an existing screen has no obvious way in. Without
		// this link, activating it and going looking produced nothing at all, and because
		// WordPress shows Media as a grid by default, the most likely first experience of a
		// working plugin was that it did nothing.
		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( __CLASS__, 'action_links' )
		);

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Overview and Media list links on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$ours = array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'upload.php?page=' . TIMU_Image_Support_Admin_Page::SLUG ) ),
				esc_html__( 'How it works', 'thisismyurl-image-support' )
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'upload.php?mode=list' ) ),
				esc_html__( 'Your images', 'thisismyurl-image-support' )
			),
		);

		return array_merge( $ours, $links );
	}

	/**
	 * Load the assets, on the two screens that use them.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue_assets( $hook ) {
		$base     = plugin_dir_url( __FILE__ ) . 'assets/';
		$overview = 'media_page_' . TIMU_Image_Support_Admin_Page::SLUG;

		// The overview page needs the stylesheet and its own small script, but none of the
		// inline-editing script, which would have nothing to act on there.
		if ( $overview === $hook ) {
			wp_enqueue_style(
				'timu-image-support',
				$base . 'media-library.css',
				array(),
				TIMU_IMAGE_SUPPORT_VERSION
			);

			wp_enqueue_script(
				'timu-image-overview',
				$base . 'overview.js',
				array(),
				TIMU_IMAGE_SUPPORT_VERSION,
				true
			);

			wp_localize_script(
				'timu-image-overview',
				'timuImageOverview',
				array(
					'labelTemplate'      => TIMU_Image_Support_Admin_Page::diagram_label_template(),
					'heavyLabelTemplate' => TIMU_Image_Support_Admin_Page::heavy_label_template(),
					'sizeTemplates'      => TIMU_Image_Support_Admin_Page::size_templates(),
				)
			);

			return;
		}

		if ( 'upload.php' !== $hook ) {
			return;
		}

		// The grid view is a JavaScript application with its own template; these columns
		// only exist in the list view, so there is nothing to enhance in grid mode.
		if ( 'list' !== get_user_option( 'media_library_mode' ) ) {
			return;
		}

		wp_enqueue_style(
			'timu-image-support',
			$base . 'media-library.css',
			array(),
			TIMU_IMAGE_SUPPORT_VERSION
		);

		wp_enqueue_script(
			'timu-image-support',
			$base . 'media-library.js',
			array(),
			TIMU_IMAGE_SUPPORT_VERSION,
			true
		);

		wp_localize_script(
			'timu-image-support',
			'timuImageSupport',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => TIMU_Image_Support_Alt_Editor::ACTION,
				'nonce'   => wp_create_nonce( TIMU_Image_Support_Alt_Editor::NONCE ),
				'strings' => array(
					'saving' => __( 'Saving…', 'thisismyurl-image-support' ),
					'failed' => __( 'Could not save. Your change was not stored.', 'thisismyurl-image-support' ),
				),
			)
		);
	}
}

TIMU_Image_Support::init();
