<?php
/**
 * Uninstaller.
 *
 * This plugin creates these things of its own: a numeric file-size index in post meta,
 * used to make the Media list sortable by file size, the two settings from the overview
 * screen, a daily history of its own counts, and short-lived cached results. Those go, on
 * every site of a network.
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

/**
 * Remove everything this plugin stored for the current site.
 */
function timu_image_support_uninstall_site() {
	delete_metadata( 'post', 0, '_timu_image_filesize', '', true );

	delete_option( 'timu_image_support_oversize_threshold' );
	delete_option( 'timu_image_support_heavy_mb' );
	delete_option( 'timu_image_history' );

	delete_transient( 'timu_image_oversized_ids' );
	delete_transient( 'timu_image_broken_ids' );
	delete_transient( 'timu_image_reference_map' );
	delete_transient( 'timu_image_library_report' );
	delete_transient( 'timu_image_overview_counts' );
}

/**
 * Run the cleanup for every site on a network, or for the one site otherwise.
 */
function timu_image_support_uninstall() {
	if ( ! is_multisite() ) {
		timu_image_support_uninstall_site();

		return;
	}

	// Every site on the network, not only the one the plugin was removed from.
	$sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $sites as $site_id ) {
		switch_to_blog( $site_id );
		timu_image_support_uninstall_site();
		restore_current_blog();
	}
}

timu_image_support_uninstall();
