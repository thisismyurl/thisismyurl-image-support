<?php
/**
 * File operations: process / restore / backup / dimension / dedupe.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Filesystem-touching attachment operations.
 */
class TIMU_IC_File_Ops {

    /**
     * Per-request re-entry guard. Keyed by attachment ID.
     *
     * The cleanup pipeline calls wp_generate_attachment_metadata() to rebuild
     * sized variants, and that core function re-fires the same filter the
     * pipeline is registered on. Without this guard the inner pass re-renames
     * the file the outer pass just moved, losing the original to a cascade of
     * numeric-suffix collisions (see changelog for 0.6136).
     *
     * @var array<int,bool>
     */
    private static $in_progress = array();

    /**
     * Wire up upload-time hooks.
     *
     * @return void
     */
    public static function init() {
        add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'maybe_optimize_on_upload' ), 99, 2 );
        // The image-404 -> WebP redirect stays registered by the TIMU_IC instance
        // (the established, tested path). Registering it here too would double the
        // template_redirect work on every front-end 404, so it is intentionally
        // omitted. TIMU_IC_File_Ops::handle_image_404_redirects() remains callable
        // for sister plugins per the compat contract.
    }

    /**
     * Optimize-on-upload hook callback.
     *
     * @param array $metadata      Attachment metadata.
     * @param int   $attachment_id Attachment ID.
     *
     * @return array
     */
    public static function maybe_optimize_on_upload( $metadata, $attachment_id ) {
        $attachment_id = (int) $attachment_id;

        if ( isset( self::$in_progress[ $attachment_id ] ) ) {
            return $metadata;
        }

        $options = TIMU_IC_Options::get();

        if ( empty( $options['optimize_on_upload'] ) || ! TIMU_IC::has_supported_image_engine() ) {
            return $metadata;
        }

        $file = get_attached_file( $attachment_id );
        $mime = get_post_mime_type( $attachment_id );
        if ( ! self::needs_processing( $attachment_id, (string) $file, (string) $mime ) ) {
            return $metadata;
        }

        self::process_attachment_for_cleanup( $attachment_id );

        return $metadata;
    }

    /**
     * Whether the sanitiser's proposed name is just the source with a
     * wp_unique_filename-style trailing -N suffix stripped.
     *
     * If true, the difference is WordPress's own collision suffix; renaming
     * would walk us straight back into a collision. Treat the file as already
     * clean for naming purposes.
     *
     * @param string $original_basename Source basename, e.g. "cover-3000-8.jpg".
     * @param string $proposed_basename Sanitiser output, e.g. "cover-3000.jpg".
     *
     * @return bool
     */
    private static function is_unique_suffix_artifact( $original_basename, $proposed_basename ) {
        if ( $original_basename === $proposed_basename ) {
            return false;
        }

        $original_info = pathinfo( $original_basename );
        $proposed_info = pathinfo( $proposed_basename );

        $original_ext = isset( $original_info['extension'] ) ? strtolower( $original_info['extension'] ) : '';
        $proposed_ext = isset( $proposed_info['extension'] ) ? strtolower( $proposed_info['extension'] ) : '';

        if ( $original_ext !== $proposed_ext ) {
            return false;
        }

        $original_name = isset( $original_info['filename'] ) ? $original_info['filename'] : '';
        $proposed_name = isset( $proposed_info['filename'] ) ? $proposed_info['filename'] : '';

        if ( '' === $original_name || '' === $proposed_name ) {
            return false;
        }

        // Strip a single trailing wp_unique_filename-style suffix from the source.
        $stripped = preg_replace( '/-[0-9]+$/', '', $original_name );

        return $stripped === $proposed_name;
    }

    /**
     * Resolve the backup directory for a given relative attachment path.
     *
     * @param string $relative_path Relative attachment path.
     *
     * @return string
     */
    public static function get_backup_dir( $relative_path ) {
        $upload_dir = wp_upload_dir();
        $subdir     = dirname( $relative_path );

        if ( '.' === $subdir ) {
            $subdir = '';
        }

        return trailingslashit( $upload_dir['basedir'] . '/timu-image-backups/' . $subdir );
    }

    /**
     * Whether a relative path is excluded by user-configured globs.
     *
     * @param string $relative_path Relative attachment path (no leading slash).
     *
     * @return bool
     */
    public static function should_exclude( $relative_path ) {
        $options  = TIMU_IC_Options::get();
        $patterns = isset( $options['exclude_paths'] ) ? (array) $options['exclude_paths'] : array();
        if ( empty( $patterns ) ) {
            return false;
        }

        $relative_path = ltrim( (string) $relative_path, '/' );

        foreach ( $patterns as $pattern ) {
            $pattern = ltrim( (string) $pattern, '/' );
            if ( '' === $pattern ) {
                continue;
            }
            if ( fnmatch( $pattern, $relative_path ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether an attachment needs cleanup work.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $file_path     Absolute file path.
     * @param string $mime          Mime type.
     *
     * @return bool
     */
    public static function needs_processing( $attachment_id, $file_path, $mime ) {
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return false;
        }

        if ( ! in_array( $mime, TIMU_IC::get_enabled_source_mimes(), true ) ) {
            return false;
        }

        $relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( '' !== $relative && self::should_exclude( $relative ) ) {
            return false;
        }

        if ( ! get_post_meta( $attachment_id, TIMU_IC::PROCESSED_AT_KEY, true ) ) {
            return true;
        }

        $basename  = basename( $file_path );
        $sanitized = TIMU_IC_Sanitizer::clean( $basename, $attachment_id );
        if ( $sanitized !== $basename ) {
            return true;
        }

        $options       = TIMU_IC_Options::get();
        $max_dimension = isset( $options['max_dimension'] ) ? (int) $options['max_dimension'] : 2560;
        $size_info     = wp_getimagesize( $file_path );
        if ( ! empty( $size_info[0] ) && ! empty( $size_info[1] ) ) {
            if ( (int) $size_info[0] > $max_dimension || (int) $size_info[1] > $max_dimension ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip identifying metadata and embed safe credit metadata via Imagick.
     *
     * @param string $path          Absolute file path.
     * @param int    $attachment_id Attachment ID.
     * @param array  $options       Plugin options.
     *
     * @return void
     */
    private static function apply_metadata_hardening( $path, $attachment_id, $options ) {
        if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
            return;
        }

        $strip = ! empty( $options['strip_metadata'] );
        $embed = ! empty( $options['embed_metadata'] );

        if ( ! $strip && ! $embed ) {
            return;
        }

        try {
            $imagick = new \Imagick( $path );

            if ( $strip ) {
                $imagick->stripImage();
            }

            if ( $embed ) {
                $site_name = get_bloginfo( 'name' );
                $site_url  = home_url();
                $title     = get_the_title( $attachment_id );
                $file_url  = (string) wp_get_attachment_url( $attachment_id );

                $xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
                    . '<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="Image Support by thisismyurl.com">' . "\n"
                    . ' <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n"
                    . '  <rdf:Description rdf:about="' . htmlspecialchars( $file_url, ENT_XML1, 'UTF-8' ) . '"' . "\n"
                    . '   xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n"
                    . '   xmlns:xmp="http://ns.adobe.com/xap/1.0/">' . "\n"
                    . '   <dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . htmlspecialchars( (string) $title, ENT_XML1, 'UTF-8' ) . '</rdf:li></rdf:Alt></dc:title>' . "\n"
                    . '   <dc:creator><rdf:Seq><rdf:li>thisismyurl.com</rdf:li></rdf:Seq></dc:creator>' . "\n"
                    . '   <dc:source>' . htmlspecialchars( (string) $site_url, ENT_XML1, 'UTF-8' ) . '</dc:source>' . "\n"
                    . '   <dc:rights><rdf:Alt><rdf:li xml:lang="x-default">' . htmlspecialchars( (string) $site_name, ENT_XML1, 'UTF-8' ) . '</rdf:li></rdf:Alt></dc:rights>' . "\n"
                    . '   <xmp:CreatorTool>Image Support by thisismyurl.com</xmp:CreatorTool>' . "\n"
                    . '   <xmp:MetadataDate>' . gmdate( 'c' ) . '</xmp:MetadataDate>' . "\n"
                    . '  </rdf:Description>' . "\n"
                    . ' </rdf:RDF>' . "\n"
                    . '</x:xmpmeta>' . "\n"
                    . '<?xpacket end="w"?>';

                $imagick->setImageProfile( 'xmp', $xmp );
            }

            $imagick->writeImage( $path );
            $imagick->destroy();
        } catch ( \Exception $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'timu-image-support metadata error for attachment #' . (int) $attachment_id . ': ' . $e->getMessage() );
        }
    }

    /**
     * Plan the rename + resize work for an attachment without writing any files.
     *
     * Used by the dry-run CSV export in the admin.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return array|WP_Error Array with proposed change keys, or WP_Error.
     */
    public static function plan_attachment_changes( $attachment_id ) {
        $mime = get_post_mime_type( $attachment_id );
        $file = get_attached_file( $attachment_id );

        if ( ! $file || ! file_exists( $file ) ) {
            return new WP_Error( 'missing', __( 'File does not exist.', 'thisismyurl-image-support' ) );
        }

        if ( ! in_array( $mime, TIMU_IC::get_enabled_source_mimes(), true ) ) {
            return new WP_Error( 'mime', __( 'Unsupported format.', 'thisismyurl-image-support' ) );
        }

        $relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( '' !== $relative && self::should_exclude( $relative ) ) {
            return new WP_Error( 'excluded', __( 'Path matches an exclude pattern.', 'thisismyurl-image-support' ) );
        }

        $options       = TIMU_IC_Options::get();
        $max_dimension = isset( $options['max_dimension'] ) ? (int) $options['max_dimension'] : 2560;

        $current_basename  = basename( $file );
        $proposed_basename = TIMU_IC_Sanitizer::clean( $current_basename, $attachment_id );

        $width        = 0;
        $height       = 0;
        $needs_resize = false;
        $size_info    = wp_getimagesize( $file );
        if ( ! empty( $size_info[0] ) && ! empty( $size_info[1] ) ) {
            $width  = (int) $size_info[0];
            $height = (int) $size_info[1];
            if ( $width > $max_dimension || $height > $max_dimension ) {
                $needs_resize = true;
            }
        }

        return array(
            'attachment_id'      => (int) $attachment_id,
            'current_filename'   => $current_basename,
            'proposed_filename'  => $proposed_basename,
            'current_dimensions' => $width && $height ? $width . 'x' . $height : '',
            'needs_resize'       => $needs_resize ? 'yes' : 'no',
        );
    }

    /**
     * Process a single attachment: rename + resize + harden + dedupe.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return true|WP_Error
     */
    public static function process_attachment_for_cleanup( $attachment_id ) {
        $attachment_id = (int) $attachment_id;

        if ( isset( self::$in_progress[ $attachment_id ] ) ) {
            return new WP_Error( 'reentry', __( 'Already processing this attachment.', 'thisismyurl-image-support' ) );
        }

        $mime = get_post_mime_type( $attachment_id );
        $file = get_attached_file( $attachment_id );

        if ( ! $file || ! file_exists( $file ) ) {
            return new WP_Error( 'missing', __( 'File does not exist.', 'thisismyurl-image-support' ) );
        }

        if ( ! in_array( $mime, TIMU_IC::get_enabled_source_mimes(), true ) ) {
            return new WP_Error( 'mime', __( 'Unsupported format.', 'thisismyurl-image-support' ) );
        }

        self::$in_progress[ $attachment_id ] = true;

        try {
            return self::process_attachment_for_cleanup_inner( $attachment_id, $file );
        } finally {
            unset( self::$in_progress[ $attachment_id ] );
        }
    }

    /**
     * Inner cleanup routine — guarded against re-entry by the public wrapper.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $file          Absolute source path, already validated.
     *
     * @return true|WP_Error
     */
    private static function process_attachment_for_cleanup_inner( $attachment_id, $file ) {
        $options           = TIMU_IC_Options::get();
        $original_abs      = $file;
        $original_rel      = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
        $original_basename = basename( $file );
        $original_size     = (int) filesize( $file );

        if ( '' !== $original_rel && self::should_exclude( $original_rel ) ) {
            return new WP_Error( 'excluded', __( 'Path matches an exclude pattern.', 'thisismyurl-image-support' ) );
        }

        $backup_dir = self::get_backup_dir( $original_rel );
        if ( ! wp_mkdir_p( $backup_dir ) ) {
            return new WP_Error( 'backup_dir', __( 'Could not create backup directory.', 'thisismyurl-image-support' ) );
        }

        $backup_file = $backup_dir . $original_basename;
        if ( ! file_exists( $backup_file ) ) {
            if ( ! copy( $original_abs, $backup_file ) ) {
                return new WP_Error( 'backup_copy', __( 'Could not create backup file.', 'thisismyurl-image-support' ) );
            }
        }

        update_post_meta( $attachment_id, TIMU_IC::ORIGINAL_PATH_KEY, $original_rel );
        update_post_meta( $attachment_id, TIMU_IC::ORIGINAL_FILENAME_KEY, $original_basename );

        $new_name   = TIMU_IC_Sanitizer::clean( $original_basename, $attachment_id );
        $active_abs = $original_abs;
        $active_rel = $original_rel;

        if ( $new_name !== $original_basename ) {
            // Bug 0.6136: if the only difference is a wp_unique_filename-style
            // -N suffix the sanitiser stripped, do not rename. Renaming back
            // toward the suffix-free name collides with the file WordPress
            // already moved aside, and the next pass adds another -N — the
            // origin of the 74k cover-3000-* orphans.
            if ( self::is_unique_suffix_artifact( $original_basename, $new_name ) ) {
                $new_name = $original_basename;
            }
        }

        if ( $new_name !== $original_basename ) {
            $new_abs = dirname( $original_abs ) . '/' . $new_name;
            if ( file_exists( $new_abs ) && realpath( $new_abs ) !== realpath( $original_abs ) ) {
                $new_name = wp_unique_filename( dirname( $original_abs ), $new_name );
                $new_abs  = dirname( $original_abs ) . '/' . $new_name;
            }

            // Source-existence guard: never call rename() on a path that
            // vanished between the start of the call and this point. Returning
            // a WP_Error here stops the cascade cleanly instead of writing
            // a PHP warning and corrupting _wp_attached_file.
            if ( ! file_exists( $original_abs ) ) {
                return new WP_Error( 'source_gone', __( 'Source file disappeared before rename.', 'thisismyurl-image-support' ) );
            }

            // Extra safety snapshot through the shared Vault/Shadow engine before
            // the rename, on top of the plugin's own per-file backup.
            TIMU_IC_Backup_Adapter::snapshot(
                /* translators: %s: original image basename. */
                sprintf( __( 'Pre-rename: %s', 'thisismyurl-image-support' ), $original_basename ),
                array( $original_abs )
            );

            if ( ! rename( $original_abs, $new_abs ) ) {
                return new WP_Error( 'rename', __( 'Could not rename file.', 'thisismyurl-image-support' ) );
            }

            TIMU_IC_Content_Sync::sync( $original_basename, $new_name );

            $active_abs = $new_abs;
            $active_rel = preg_replace( '/[^\/]+$/', $new_name, $original_rel );

            // Tie this rename into the active batch run so "undo last run" can
            // reverse it via restore_image(). No-ops outside a batch.
            TIMU_IC_Run_Log::record_item(
                $attachment_id,
                'rename',
                array(
                    'original_rel'      => $original_rel,
                    'original_basename' => $original_basename,
                    'new_basename'      => $new_name,
                )
            );

            TIMU_IC::increment_stat( 'renamed', 1 );
        }

        update_post_meta( $attachment_id, '_wp_attached_file', $active_rel );
        update_attached_file( $attachment_id, $active_abs );

        $max_dimension = isset( $options['max_dimension'] ) ? (int) $options['max_dimension'] : 2560;
        $size_info     = wp_getimagesize( $active_abs );
        if ( ! empty( $size_info[0] ) && ! empty( $size_info[1] ) ) {
            $width  = (int) $size_info[0];
            $height = (int) $size_info[1];
            if ( $width > $max_dimension || $height > $max_dimension ) {
                $editor = wp_get_image_editor( $active_abs );
                if ( ! is_wp_error( $editor ) ) {
                    // Snapshot before the in-place downscale overwrites the file.
                    TIMU_IC_Backup_Adapter::snapshot(
                        /* translators: %s: image basename being downscaled. */
                        sprintf( __( 'Pre-downscale: %s', 'thisismyurl-image-support' ), basename( $active_abs ) ),
                        array( $active_abs )
                    );

                    $editor->resize( $max_dimension, $max_dimension, false );
                    $saved = $editor->save( $active_abs );
                    if ( ! is_wp_error( $saved ) ) {
                        // restore_image() reverses rename + downscale together
                        // from the per-item backup taken before either ran, so
                        // the run record only needs the attachment reference.
                        TIMU_IC_Run_Log::record_item(
                            $attachment_id,
                            'downscale',
                            array(
                                'original_rel'      => $original_rel,
                                'original_basename' => $original_basename,
                            )
                        );

                        TIMU_IC::increment_stat( 'resized', 1 );
                    }
                }
            }
        }

        self::apply_metadata_hardening( $active_abs, $attachment_id, $options );
        if ( ! empty( $options['strip_metadata'] ) ) {
            TIMU_IC::increment_stat( 'metadata_stripped', 1 );
        }
        if ( ! empty( $options['embed_metadata'] ) ) {
            TIMU_IC::increment_stat( 'metadata_embedded', 1 );
        }

        $slug  = sanitize_title( pathinfo( $new_name, PATHINFO_FILENAME ) );
        $title = ucwords( str_replace( '-', ' ', $slug ) );

        wp_update_post(
            array(
                'ID'         => $attachment_id,
                'post_name'  => $slug,
                'post_title' => $title,
            )
        );

        // Mark the attachment processed BEFORE regenerating metadata, so the
        // inner wp_generate_attachment_metadata() call's filter pass sees
        // PROCESSED_AT_KEY set and short-circuits in needs_processing().
        // The static $in_progress guard above is the primary defence; this
        // is belt-and-braces in case a future caller bypasses the wrapper.
        update_post_meta( $attachment_id, TIMU_IC::PROCESSED_AT_KEY, time() );

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $active_abs );
        if ( ! is_wp_error( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        $new_size = (int) filesize( $active_abs );
        update_post_meta( $attachment_id, TIMU_IC::SAVINGS_META_KEY, max( 0, $original_size - $new_size ) );

        $hash = md5_file( $active_abs );
        if ( $hash ) {
            update_post_meta( $attachment_id, TIMU_IC::HASH_META_KEY, $hash );
        }

        if ( ! empty( $options['remove_duplicates'] ) && $hash ) {
            self::resolve_obvious_duplicates( $attachment_id, $hash );
        }

        TIMU_IC::increment_stat( 'processed', 1 );

        return true;
    }

    /**
     * Resolve obvious binary duplicates by file hash.
     *
     * @param int    $attachment_id Reference attachment ID.
     * @param string $hash          File hash.
     *
     * @return void
     */
    private static function resolve_obvious_duplicates( $attachment_id, $hash ) {
        global $wpdb;

        $duplicates = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                TIMU_IC::HASH_META_KEY,
                $hash
            )
        );

        $duplicates = array_values( array_unique( array_map( 'absint', $duplicates ) ) );
        if ( count( $duplicates ) < 2 ) {
            return;
        }

        $best_id    = 0;
        $best_score = -1;

        foreach ( $duplicates as $id ) {
            $path = get_attached_file( $id );
            if ( ! $path || ! file_exists( $path ) ) {
                continue;
            }

            $size  = (int) filesize( $path );
            $meta  = wp_get_attachment_metadata( $id );
            $w     = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
            $h     = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
            $score = ( $w * $h ) + $size;

            if ( $score > $best_score ) {
                $best_score = $score;
                $best_id    = $id;
            }
        }

        if ( ! $best_id ) {
            return;
        }

        foreach ( $duplicates as $id ) {
            if ( $id === $best_id ) {
                continue;
            }

            $dup_url  = wp_get_attachment_url( $id );
            $best_url = wp_get_attachment_url( $best_id );
            $dup_rel  = (string) get_post_meta( $id, '_wp_attached_file', true );

            // Capture the full attachment record before the force-delete erases
            // it. Force-delete bypasses Trash, so the sidecar is the only path
            // back to this attachment's post + meta for an un-merge.
            $sidecar = self::write_merge_sidecar( (int) $id, (int) $best_id );

            $dup_backup_file = '';
            $dup_path        = get_attached_file( $id );
            if ( $dup_path && file_exists( $dup_path ) ) {
                $upload_dir     = wp_upload_dir();
                $dup_backup_dir = trailingslashit( $upload_dir['basedir'] . '/timu-image-backups/duplicates' );
                wp_mkdir_p( $dup_backup_dir );
                $dup_backup_file = $dup_backup_dir . $id . '-' . basename( $dup_path );
                @copy( $dup_path, $dup_backup_file );

                // Extra safety snapshot before the duplicate is force-deleted.
                TIMU_IC_Backup_Adapter::snapshot(
                    /* translators: %d: attachment ID of the duplicate being merged. */
                    sprintf( __( 'Pre-merge duplicate: attachment %d', 'thisismyurl-image-support' ), (int) $id ),
                    array( $dup_path )
                );
            }

            if ( $dup_url && $best_url ) {
                $wpdb->update(
                    $wpdb->postmeta,
                    array( 'meta_value' => $best_id ),
                    array(
                        'meta_key'   => '_thumbnail_id',
                        'meta_value' => $id,
                    )
                );
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                        $dup_url,
                        $best_url,
                        '%' . $wpdb->esc_like( $dup_url ) . '%'
                    )
                );
            }

            if ( '' !== $dup_rel ) {
                add_post_meta( $best_id, TIMU_IC::LEGACY_PATH_KEY, ltrim( $dup_rel, '/' ), false );
            }

            // Record the merge against the active batch run so undo_run() can
            // un-merge: restore the file from the duplicates backup, re-insert
            // the attachment from the sidecar, and reverse the URL relink.
            TIMU_IC_Run_Log::record_item(
                (int) $id,
                'merge',
                array(
                    'best_id'         => (int) $best_id,
                    'sidecar'         => $sidecar,
                    'dup_backup_file' => $dup_backup_file,
                    'dup_url'         => (string) $dup_url,
                    'best_url'        => (string) $best_url,
                    'dup_rel'         => ltrim( $dup_rel, '/' ),
                )
            );

            wp_delete_attachment( $id, true );
            TIMU_IC::increment_stat( 'duplicates_removed', 1 );
        }
    }

    /**
     * Restore an attachment to the originally-uploaded file.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return bool
     */
    public static function restore_image( $attachment_id ) {
        $original_rel  = (string) get_post_meta( $attachment_id, TIMU_IC::ORIGINAL_PATH_KEY, true );
        $original_name = (string) get_post_meta( $attachment_id, TIMU_IC::ORIGINAL_FILENAME_KEY, true );

        if ( '' === $original_rel || '' === $original_name ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $target_abs = trailingslashit( $upload_dir['basedir'] ) . ltrim( $original_rel, '/' );
        $backup_dir = self::get_backup_dir( $original_rel );
        $backup_abs = $backup_dir . $original_name;

        if ( ! file_exists( $backup_abs ) ) {
            return false;
        }

        if ( ! wp_mkdir_p( dirname( $target_abs ) ) ) {
            return false;
        }

        $current_abs  = get_attached_file( $attachment_id );
        $current_name = basename( (string) $current_abs );

        if ( ! copy( $backup_abs, $target_abs ) ) {
            return false;
        }

        if ( $current_abs && file_exists( $current_abs ) && $current_abs !== $target_abs ) {
            @unlink( $current_abs );
        }

        if ( $current_name !== $original_name ) {
            TIMU_IC_Content_Sync::sync( $current_name, $original_name );
        }

        update_post_meta( $attachment_id, '_wp_attached_file', ltrim( $original_rel, '/' ) );
        update_attached_file( $attachment_id, $target_abs );

        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Guard the metadata regen: wp_generate_attachment_metadata() re-fires
        // the wp_generate_attachment_metadata filter that maybe_optimize_on_upload
        // is registered on. Without this guard the cleanup pipeline would see the
        // just-restored junk-named file as "needs processing" and immediately
        // re-rename it, silently undoing the restore. The same static guard that
        // protects the cleanup pass from re-entry protects the restore here.
        self::$in_progress[ (int) $attachment_id ] = true;
        $metadata = wp_generate_attachment_metadata( $attachment_id, $target_abs );
        unset( self::$in_progress[ (int) $attachment_id ] );
        if ( ! is_wp_error( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        delete_post_meta( $attachment_id, TIMU_IC::ORIGINAL_PATH_KEY );
        delete_post_meta( $attachment_id, TIMU_IC::ORIGINAL_FILENAME_KEY );
        delete_post_meta( $attachment_id, TIMU_IC::SAVINGS_META_KEY );
        delete_post_meta( $attachment_id, TIMU_IC::PROCESSED_AT_KEY );
        delete_post_meta( $attachment_id, TIMU_IC::HASH_META_KEY );

        return true;
    }

    /**
     * Process a batch of attachments inside a recorded run session.
     *
     * Opens a run, processes each attachment through the existing per-item
     * pipeline (which records its own reversible operations against the open
     * run), then closes the run. The run can be reversed as a unit via
     * undo_run().
     *
     * @param int[]  $attachment_ids Attachment IDs to process.
     * @param string $source         Origin label for the run record.
     *
     * @return array{run_id:string,processed:int[],failed:int[],errors:string[]}
     */
    public static function run_batch( array $attachment_ids, $source = 'optimize-batch' ) {
        $run_id    = TIMU_IC_Run_Log::begin( $source );
        $processed = array();
        $failed    = array();
        $errors    = array();

        foreach ( $attachment_ids as $attachment_id ) {
            $result = self::process_attachment_for_cleanup( (int) $attachment_id );
            if ( true === $result ) {
                $processed[] = (int) $attachment_id;
            } else {
                $failed[] = (int) $attachment_id;
                $errors[] = is_wp_error( $result )
                    ? $result->get_error_message()
                    : __( 'Unknown processing error.', 'thisismyurl-image-support' );
            }
        }

        TIMU_IC_Run_Log::end();

        return array(
            'run_id'    => $run_id,
            'processed' => $processed,
            'failed'    => $failed,
            'errors'    => array_values( array_unique( $errors ) ),
        );
    }

    /**
     * Reverse every recorded operation in a run as a unit.
     *
     * Reversal order is deliberate: merges first (un-merge re-inserts the
     * trashed duplicate and reverses its relink while the surviving attachment
     * is still in its post-merge state), then rename/downscale restores (each
     * delegates to restore_image(), which restores the file from its per-item
     * backup and syncs content references back). Each item reports its own
     * success or failure; a partial failure is collected and surfaced, never
     * swallowed.
     *
     * Undo is restorative, so it runs regardless of the destructive-ops opt-in —
     * it only reverses writes the pipeline already made.
     *
     * @param string $run_id Run to reverse.
     *
     * @return array|WP_Error Result map, or WP_Error when the run is unknown.
     */
    public static function undo_run( $run_id ) {
        $run = TIMU_IC_Run_Log::get( $run_id );
        if ( null === $run ) {
            return new WP_Error( 'unknown_run', __( 'That cleanup run is no longer available to undo.', 'thisismyurl-image-support' ) );
        }

        if ( ! empty( $run['undone_at'] ) ) {
            return new WP_Error( 'already_undone', __( 'That cleanup run has already been undone.', 'thisismyurl-image-support' ) );
        }

        $items = isset( $run['items'] ) ? (array) $run['items'] : array();

        // Reverse merges before file restores. Within each group, walk newest
        // record first so any later operation on the same attachment is undone
        // before the earlier one it depended on.
        $merges  = array();
        $restores = array();
        foreach ( array_reverse( $items ) as $item ) {
            if ( 'merge' === ( $item['operation'] ?? '' ) ) {
                $merges[] = $item;
            } else {
                $restores[] = $item;
            }
        }

        $reversed = 0;
        $failures = array();

        foreach ( $merges as $item ) {
            $result = self::undo_merge( $item );
            if ( is_wp_error( $result ) ) {
                $failures[] = $result->get_error_message();
            } else {
                ++$reversed;
            }
        }

        // A rename and a downscale on the same attachment both reverse to the
        // single per-item backup, so restore_image() only needs to run once per
        // attachment. Dedupe by attachment ID.
        $restored_ids = array();
        foreach ( $restores as $item ) {
            $att_id = (int) ( $item['attachment_id'] ?? 0 );
            if ( ! $att_id || isset( $restored_ids[ $att_id ] ) ) {
                continue;
            }
            $restored_ids[ $att_id ] = true;

            if ( self::restore_image( $att_id ) ) {
                ++$reversed;
            } else {
                $failures[] = sprintf(
                    /* translators: %d: attachment ID that could not be restored. */
                    __( 'Could not restore attachment %d (backup missing or already restored).', 'thisismyurl-image-support' ),
                    $att_id
                );
            }
        }

        TIMU_IC_Run_Log::mark_undone( $run_id );

        return array(
            'run_id'   => $run_id,
            'reversed' => $reversed,
            'failures' => $failures,
        );
    }

    /**
     * Write a JSON sidecar of an attachment's full record before a merge.
     *
     * The merge force-deletes the duplicate, bypassing Trash, so this sidecar is
     * the un-merge's only source for the post row and meta. Stored under the
     * backups directory alongside the duplicates file copy.
     *
     * @param int $duplicate_id Attachment about to be merged away.
     * @param int $best_id      Surviving attachment it merges into.
     *
     * @return string Absolute sidecar path, or empty string on failure.
     */
    private static function write_merge_sidecar( $duplicate_id, $best_id ) {
        $post = get_post( $duplicate_id );
        if ( ! $post ) {
            return '';
        }

        $upload_dir   = wp_upload_dir();
        $sidecar_dir  = trailingslashit( $upload_dir['basedir'] . '/timu-image-backups/merge-sidecars' );
        if ( ! wp_mkdir_p( $sidecar_dir ) ) {
            return '';
        }

        $payload = array(
            'duplicate_id'  => (int) $duplicate_id,
            'best_id'       => (int) $best_id,
            'timestamp'     => gmdate( 'c' ),
            'post'          => $post->to_array(),
            'meta'          => get_post_meta( $duplicate_id ),
            'attached_file' => (string) get_post_meta( $duplicate_id, '_wp_attached_file', true ),
        );

        $path = $sidecar_dir . 'attachment-' . (int) $duplicate_id . '-' . time() . '.json';
        if ( false === file_put_contents( $path, wp_json_encode( $payload, JSON_PRETTY_PRINT ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            return '';
        }

        return $path;
    }

    /**
     * Reverse a single recorded merge.
     *
     * Restores the duplicate file from the duplicates backup, re-inserts the
     * attachment from its sidecar, reverses the content URL relink, and removes
     * the legacy-path marker from the surviving attachment.
     *
     * Caveat: force-delete erased the original post ID, so the re-inserted
     * attachment receives a NEW ID. Inline content references are relinked from
     * the survivor's URL back to the duplicate's URL, which restores the visible
     * markup; any code that hard-coded the old numeric attachment ID will still
     * point at the survivor. This is surfaced in the run report.
     *
     * @param array $item Recorded merge item.
     *
     * @return true|WP_Error
     */
    private static function undo_merge( $item ) {
        $payload         = isset( $item['payload'] ) ? (array) $item['payload'] : array();
        $sidecar         = isset( $payload['sidecar'] ) ? (string) $payload['sidecar'] : '';
        $dup_backup_file = isset( $payload['dup_backup_file'] ) ? (string) $payload['dup_backup_file'] : '';
        $best_id         = isset( $payload['best_id'] ) ? (int) $payload['best_id'] : 0;
        $dup_url         = isset( $payload['dup_url'] ) ? (string) $payload['dup_url'] : '';
        $best_url        = isset( $payload['best_url'] ) ? (string) $payload['best_url'] : '';
        $dup_rel         = isset( $payload['dup_rel'] ) ? (string) $payload['dup_rel'] : '';

        if ( '' === $sidecar || ! file_exists( $sidecar ) ) {
            return new WP_Error(
                'merge_sidecar_missing',
                __( 'Merge sidecar is missing; the merged duplicate cannot be re-created.', 'thisismyurl-image-support' )
            );
        }

        $raw    = file_get_contents( $sidecar ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
        $record = json_decode( (string) $raw, true );
        if ( ! is_array( $record ) || empty( $record['post'] ) ) {
            return new WP_Error( 'merge_sidecar_corrupt', __( 'Merge sidecar is unreadable.', 'thisismyurl-image-support' ) );
        }

        $upload_dir = wp_upload_dir();
        $target_rel = '' !== $dup_rel ? $dup_rel : (string) ( $record['attached_file'] ?? '' );
        $target_abs = trailingslashit( $upload_dir['basedir'] ) . ltrim( $target_rel, '/' );

        // Put the duplicate's file back from the backup copy.
        if ( '' !== $dup_backup_file && file_exists( $dup_backup_file ) && '' !== $target_rel ) {
            if ( wp_mkdir_p( dirname( $target_abs ) ) ) {
                copy( $dup_backup_file, $target_abs );
            }
        }

        // Re-insert the attachment post. Force-delete erased the old ID, so this
        // gets a fresh one.
        $post_data                = (array) $record['post'];
        $original_id              = (int) $post_data['ID'];
        unset( $post_data['ID'], $post_data['guid'] );
        $post_data['post_type']   = 'attachment';
        $post_data['post_status'] = 'inherit';

        $new_id = wp_insert_attachment( $post_data, $target_abs, (int) $post_data['post_parent'] );
        if ( is_wp_error( $new_id ) || ! $new_id ) {
            return new WP_Error( 'merge_reinsert_failed', __( 'Could not re-create the merged attachment.', 'thisismyurl-image-support' ) );
        }

        // Restore meta from the sidecar (skip keys WordPress will regenerate).
        $skip_meta = array( '_wp_attached_file', '_wp_attachment_metadata' );
        $meta      = isset( $record['meta'] ) ? (array) $record['meta'] : array();
        foreach ( $meta as $key => $values ) {
            if ( in_array( $key, $skip_meta, true ) ) {
                continue;
            }
            foreach ( (array) $values as $value ) {
                add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
            }
        }

        update_post_meta( $new_id, '_wp_attached_file', ltrim( $target_rel, '/' ) );

        if ( file_exists( $target_abs ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $regen = wp_generate_attachment_metadata( $new_id, $target_abs );
            if ( ! is_wp_error( $regen ) ) {
                wp_update_attachment_metadata( $new_id, $regen );
            }
        }

        // Reverse the content URL relink: survivor URL back to the duplicate's
        // URL across post_content.
        if ( '' !== $dup_url && '' !== $best_url ) {
            global $wpdb;
            $new_url = wp_get_attachment_url( $new_id );
            $restore_to = $new_url ? $new_url : $dup_url;
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                    $best_url,
                    $restore_to,
                    '%' . $wpdb->esc_like( $best_url ) . '%'
                )
            );
        }

        // Drop the legacy-path marker the merge added to the survivor.
        if ( $best_id && '' !== $dup_rel ) {
            delete_post_meta( $best_id, TIMU_IC::LEGACY_PATH_KEY, $dup_rel );
        }

        // NOTE: the merge re-pointed every _thumbnail_id row that equalled the
        // duplicate to the survivor, but recorded nothing about which rows those
        // were. Reversing the swap by matching meta_value = best_id would also
        // re-point posts that legitimately used the survivor as their featured
        // image, corrupting them. We deliberately do NOT reverse the featured-
        // image swap here; the re-created attachment exists and inline content
        // is relinked, but any post whose FEATURED image was the merged-away
        // duplicate keeps the survivor as its thumbnail. This is the one part of
        // a merge undo that is not clean, and it is surfaced in the run report.
        unset( $original_id );

        return true;
    }

    /**
     * Build pending vs managed media buckets for the admin tab.
     *
     * @return array Array with 'pending' and 'media' keys, each a list of WP_Post.
     */
    public static function get_media_lists() {
        // Admin-only listing; -1 acceptable here, scoped to enabled mimes only.
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'post_mime_type' => TIMU_IC::get_enabled_source_mimes(),
            )
        );

        $pending = array();
        $media   = array();

        if ( ! empty( $query->posts ) ) {
            foreach ( $query->posts as $post ) {
                $file = get_attached_file( $post->ID );
                $mime = get_post_mime_type( $post->ID );

                if ( ! $file || ! file_exists( $file ) ) {
                    $post->timu_status = 'missing';
                    $media[]           = $post;
                    continue;
                }

                if ( self::needs_processing( (int) $post->ID, (string) $file, (string) $mime ) ) {
                    $pending[] = $post;
                } else {
                    $media[] = $post;
                }
            }
        }

        return array(
            'pending' => $pending,
            'media'   => $media,
        );
    }

    /**
     * Redirect legacy attachment URLs to the renamed asset.
     *
     * @return void
     */
    public static function handle_image_404_redirects() {
        if ( ! is_404() ) {
            return;
        }

        $upload_dir       = wp_upload_dir();
        $requested_uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $base_path        = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
        $relative_request = ltrim( str_replace( (string) $base_path, '', $requested_uri ), '/' );

        global $wpdb;
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE ( meta_key = %s OR meta_key = %s ) AND meta_value = %s LIMIT 1",
                TIMU_IC::ORIGINAL_PATH_KEY,
                TIMU_IC::LEGACY_PATH_KEY,
                $relative_request
            )
        );

        if ( $id ) {
            $new_url = wp_get_attachment_url( (int) $id );
            if ( $new_url ) {
                $redirect_url = wp_validate_redirect( $new_url, home_url( '/' ) );
                wp_safe_redirect( $redirect_url, 301, 'Image Support by thisismyurl' );
                exit;
            }
        }
    }

    /**
     * Build report metrics for the Report tab.
     *
     * @param string $range_key One of '30d', '90d', '365d', 'all'.
     *
     * @return array
     */
    public static function get_report_metrics( $range_key ) {
        $now   = time();
        $start = 0;

        switch ( $range_key ) {
            case '30d':
                $start = $now - ( 30 * DAY_IN_SECONDS );
                break;
            case '90d':
                $start = $now - ( 90 * DAY_IN_SECONDS );
                break;
            case '365d':
                $start = $now - ( 365 * DAY_IN_SECONDS );
                break;
            case 'all':
            default:
                $start = 0;
                break;
        }

        global $wpdb;
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
                TIMU_IC::PROCESSED_AT_KEY
            )
        );

        $processed_count = 0;
        $bytes_saved     = 0;

        foreach ( $ids as $id ) {
            $processed_at = (int) get_post_meta( (int) $id, TIMU_IC::PROCESSED_AT_KEY, true );
            if ( $start > 0 && ( $processed_at <= 0 || $processed_at < $start ) ) {
                continue;
            }

            $processed_count++;
            $bytes_saved += (int) get_post_meta( (int) $id, TIMU_IC::SAVINGS_META_KEY, true );
        }

        $options         = TIMU_IC_Options::get();
        $monthly_hits    = isset( $options['report_monthly_image_hits'] ) ? (int) $options['report_monthly_image_hits'] : 0;
        $cost_per_gb     = isset( $options['report_bandwidth_cost_gb'] ) ? (float) $options['report_bandwidth_cost_gb'] : 0.0;
        $avg_saved_bytes = $processed_count > 0 ? ( $bytes_saved / $processed_count ) : 0;
        $gb_per_month    = ( $avg_saved_bytes * $monthly_hits ) / ( 1024 * 1024 * 1024 );
        $monthly_roi     = $gb_per_month * $cost_per_gb;

        return array(
            'range'             => $range_key,
            'processed_count'   => $processed_count,
            'bytes_saved'       => $bytes_saved,
            'avg_saved_kb'      => $avg_saved_bytes / 1024,
            'monthly_hits'      => $monthly_hits,
            'cost_per_gb'       => $cost_per_gb,
            'monthly_roi'       => $monthly_roi,
            'annual_roi'        => $monthly_roi * 12,
            'renamed_total'     => TIMU_IC::get_stat( 'renamed' ),
            'resized_total'     => TIMU_IC::get_stat( 'resized' ),
            'duplicates_total'  => TIMU_IC::get_stat( 'duplicates_removed' ),
            'stripped_total'    => TIMU_IC::get_stat( 'metadata_stripped' ),
            'embedded_total'    => TIMU_IC::get_stat( 'metadata_embedded' ),
        );
    }
}
