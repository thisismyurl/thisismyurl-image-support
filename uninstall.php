<?php
/**
 * Uninstaller.
 *
 * This plugin creates exactly one thing of its own: a numeric file-size index in post
 * meta, used to make the Media list sortable by file size. That goes.
 *
 * Alt text is NOT touched. `_wp_attachment_image_alt` is WordPress's own field and
 * belongs to the site, not to this plugin. Any alt text written through the Media list
 * is ordinary WordPress data and stays exactly where it is after the plugin is gone.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_metadata( 'post', 0, '_timu_image_filesize', '', true );

delete_transient( 'timu_image_oversized_ids' );
