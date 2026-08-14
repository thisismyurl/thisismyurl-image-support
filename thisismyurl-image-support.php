<?php
/**
 * Plugin Name:       Thisismyurl Image Support
 * Plugin URI:        https://thisismyurl.com/downloads/thisismyurl-image-support/
 * Description:       Adds alt text, dimensions and file size to the Media list, so you can see which images need attention and fix them without opening each one.
 * Version:           1.6226
 * Requires at least: 6.4
 * Tested up to:      7.0
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
	define( 'TIMU_IMAGE_SUPPORT_VERSION', '1.6226' );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-image-facts.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-media-columns.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-media-filters.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-media-search.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-alt-editor.php';

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
		// Nothing this plugin does is meaningful outside the admin, so the whole thing
		// stays out of the front-end request entirely rather than loading and then
		// checking conditions.
		if ( ! is_admin() ) {
			return;
		}

		TIMU_Media_Columns::init();
		TIMU_Media_Filters::init();
		TIMU_Media_Search::init();
		TIMU_Alt_Editor::init();

		// Keep the file-size sort index and the oversize cache honest when core rebuilds
		// an attachment's metadata, which happens on upload and on any regeneration.
		add_filter( 'wp_update_attachment_metadata', array( 'TIMU_Image_Facts', 'sync_size_index' ), 10, 2 );
		add_filter( 'wp_update_attachment_metadata', array( 'TIMU_Media_Filters', 'flush_oversize_cache' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Load the list-screen assets, and only there.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'upload.php' !== $hook ) {
			return;
		}

		// The grid view is a JavaScript application with its own template; these columns
		// only exist in the list view, so there is nothing to enhance in grid mode.
		if ( 'list' !== get_user_option( 'media_library_mode' ) ) {
			return;
		}

		$base = plugin_dir_url( __FILE__ ) . 'assets/';

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
				'action'  => TIMU_Alt_Editor::ACTION,
				'nonce'   => wp_create_nonce( TIMU_Alt_Editor::NONCE ),
				'strings' => array(
					'saving' => __( 'Saving…', 'thisismyurl-image-support' ),
					'failed' => __( 'Could not save. Your change was not stored.', 'thisismyurl-image-support' ),
				),
			)
		);
	}
}

TIMU_Image_Support::init();
