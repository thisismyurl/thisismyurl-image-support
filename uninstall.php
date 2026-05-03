<?php
/**
 * Uninstaller for Image Support.
 *
 * Version sprawl fix: this file no longer carries its own version string. The
 * canonical version lives in the plugin header and readme.txt only.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wp_filesystem;
if ( empty( $wp_filesystem ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
}

// Clean up backup directory.
$upload_dir = wp_upload_dir();
$backup_dir = $upload_dir['basedir'] . '/timu-image-backups/';
if ( $wp_filesystem->exists( $backup_dir ) ) {
	$wp_filesystem->delete( $backup_dir, true );
}

// Clean up post metadata.
delete_metadata( 'post', 0, '_timu_original_path', '', true );
delete_metadata( 'post', 0, '_timu_original_filename', '', true );

// Clean up plugin options.
delete_option( 'timu_ic_last_id' );
delete_option( 'thisismyurl_image_support_confirm_destructive' );

// Clear any pending async WebP-generation jobs.
wp_clear_scheduled_hook( 'thisismyurl_image_support_generate_webp' );

// Flush cache.
wp_cache_flush();