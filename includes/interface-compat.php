<?php
/**
 * Sister-plugin compatibility surface contract.
 *
 * Documents the public methods that sister plugins (e.g. thisismyurl-heic-support,
 * thisismyurl-svg-support) may call on this plugin once both are active. The
 * filter `timu_image_support_compat_version` returns the interface version as a
 * string. Sister plugins should call `apply_filters` and bail if the returned
 * value is missing or below their minimum supported version.
 *
 * @package TIMU_Image_Support
 * @since 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! interface_exists( 'TIMU_IC_Interface' ) ) {

    /**
     * Public surface that sister plugins may rely on.
     *
     * Implementations are static-method-style; this interface is documentation
     * scaffolding rather than a strict contract because PHP does not allow
     * declaring static methods on interfaces in a way that matches our pattern
     * across all PHP versions cleanly. Treat the method list below as binding.
     *
     * Methods:
     *
     * - TIMU_IC_File_Ops::process_attachment_for_cleanup( int $attachment_id )    @since 1.0
     *     Returns true on success or WP_Error on failure.
     *
     * - TIMU_IC_File_Ops::restore_image( int $attachment_id )                     @since 1.0
     *     Returns bool — true if the original was restored from backup.
     *
     * - TIMU_IC_File_Ops::should_exclude( string $relative_path )                 @since 1.0
     *     Returns bool — true if the relative path matches a configured glob.
     *
     * - TIMU_IC_Audit::get_orphan_images()                                        @since 1.0
     *     Returns array of absolute file paths under uploads/ with no DB row.
     *
     * - TIMU_IC_Audit::get_broken_attachments()                                   @since 1.0
     *     Returns array of WP_Post attachment objects with missing files on disk.
     *
     * - TIMU_IC_Audit::get_missing_alt_text( array $post_types = array() )        @since 1.0
     *     Returns array of attachment IDs lacking _wp_attachment_image_alt.
     *
     * - TIMU_IC_Audit::find_inline_orphans()                                      @since 1.0
     *     Returns array of [ 'attachment_id' => int, 'appears_in' => array ].
     *
     * - TIMU_IC_Audit::get_exif_data( int $attachment_id )                        @since 1.0
     *     Returns array of EXIF/IPTC data, or empty array on failure.
     */
    interface TIMU_IC_Interface {
        /**
         * Marker constant pointing at the documented compat version.
         */
        const VERSION = '1.0';
    }
}
