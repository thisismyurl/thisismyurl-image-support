<?php
/**
 * Uninstaller for Image Support
 *
 * @package TIMU_Image_Support
 * Version:  1.5.1229
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

// Flush cache.
wp_cache_flush();