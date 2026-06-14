<?php
/**
 * Uninstaller for Image Support.
 *
 * Version sprawl fix: this file no longer carries its own version string. The
 * canonical version lives in the plugin header and readme.txt only.
 *
 * DATA-LOSS POLICY: the backup directory (`/uploads/timu-image-backups/`) is
 * the ONLY recovery path for every file this plugin renamed. Uninstall does
 * NOT delete it unless the site explicitly opts in by defining
 * `TIMU_IMAGE_SUPPORT_DELETE_BACKUPS_ON_UNINSTALL` truthy in wp-config.php.
 * Orphaned meta and options are always cleaned up; irreplaceable originals are
 * not.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete the backup directory ONLY when the site has explicitly opted in. The
// default is to preserve it: those files are the sole recovery path for renamed
// originals, and an uninstall should not be a destructive act against content.
if ( defined( 'TIMU_IMAGE_SUPPORT_DELETE_BACKUPS_ON_UNINSTALL' ) && TIMU_IMAGE_SUPPORT_DELETE_BACKUPS_ON_UNINSTALL ) {
	global $wp_filesystem;
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}
	if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
		$upload_dir = wp_upload_dir();
		$backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'timu-image-backups/';
		if ( $wp_filesystem->exists( $backup_dir ) ) {
			$wp_filesystem->delete( $backup_dir, true );
		}
	}
}

// Clean up cleanup-pipeline post metadata.
delete_metadata( 'post', 0, '_timu_original_path', '', true );
delete_metadata( 'post', 0, '_timu_original_filename', '', true );

// Clean up photo-credit attachment metadata. These literal key strings are the
// stable external contract declared in includes/photo-credits.php — the plugin
// is not loaded during uninstall, so the keys are repeated here by necessity.
// Keep in sync with thisismyurl_image_support_photo_credit_meta_keys().
$timu_photo_credit_meta_keys = array(
	'_thisismyurl_photo_credit',
	'_thisismyurl_photo_credit_url',
	'_thisismyurl_photo_ai_generated',
	'_thisismyurl_photo_ai_model',
	'_thisismyurl_photo_ai_edit',
	'_thisismyurl_photo_ai_edit_model',
	'_thisismyurl_photo_composite',
);
foreach ( $timu_photo_credit_meta_keys as $timu_meta_key ) {
	delete_metadata( 'post', 0, $timu_meta_key, '', true );
}

// Clean up the media-folder taxonomy terms this plugin created. The taxonomy is
// not registered during uninstall (the plugin is not loaded), so terms are read
// straight from the term tables by the literal taxonomy slug and removed with
// the lower-level wp_delete_term(). Keep this slug in sync with
// TIMU_IC_Media_Organization::TAXONOMY.
global $wpdb;
$timu_folder_taxonomy = 'timu_media_folder';
$timu_folder_term_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
		$timu_folder_taxonomy
	)
);
foreach ( (array) $timu_folder_term_ids as $timu_term_id ) {
	wp_delete_term( (int) $timu_term_id, $timu_folder_taxonomy );
}

// Clean up plugin options.
delete_option( 'timu_ic_last_id' );
delete_option( 'thisismyurl_image_support_confirm_destructive' );
delete_option( 'thisismyurl_image_support_default_credit' );
delete_option( 'thisismyurl_image_support_runs' );

// Clear any pending async WebP-generation jobs.
wp_clear_scheduled_hook( 'thisismyurl_image_support_generate_webp' );

// Flush cache.
wp_cache_flush();
