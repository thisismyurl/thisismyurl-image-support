<?php
/**
 * Audit utilities: orphan images, broken attachments, alt-text gaps,
 * inline orphans, and EXIF inspection.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only inspection helpers used by admin, REST, and CLI surfaces.
 */
class TIMU_IC_Audit {

    /**
     * Scan the uploads directory and return absolute file paths that are not
     * referenced by any attachment row or `_wp_attached_file` meta.
     *
     * Honours configured exclude-path patterns.
     *
     * @return array List of absolute file paths.
     */
    public static function get_orphan_images() {
        $upload_dir = wp_upload_dir();
        $basedir    = trailingslashit( $upload_dir['basedir'] );
        if ( ! is_dir( $basedir ) ) {
            return array();
        }

        $known = self::get_known_relative_paths();

        $orphans = array();
        $iter    = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iter as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }
            $abs_path = $file->getPathname();
            $relative = ltrim( str_replace( $basedir, '', $abs_path ), '/' );

            // Skip our own backup tree.
            if ( 0 === strpos( $relative, 'timu-image-backups/' ) ) {
                continue;
            }

            // Skip non-image files quickly.
            $ext = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'avif', 'tif', 'tiff', 'svg' ), true ) ) {
                continue;
            }

            // Skip generated thumbnails (they're not orphans on their own).
            if ( preg_match( '/-\d+x\d+\.[a-z0-9]+$/i', $relative ) ) {
                continue;
            }

            if ( TIMU_IC_File_Ops::should_exclude( $relative ) ) {
                continue;
            }

            if ( in_array( $relative, $known, true ) ) {
                continue;
            }

            $orphans[] = $abs_path;
        }

        return $orphans;
    }

    /**
     * Read all `_wp_attached_file` meta values into a flat list.
     *
     * @return array
     */
    private static function get_known_relative_paths() {
        global $wpdb;

        $values = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
                '_wp_attached_file'
            )
        );

        return array_values( array_filter( array_map( static function ( $v ) {
            return ltrim( (string) $v, '/' );
        }, $values ) ) );
    }

    /**
     * Return attachment posts whose attached file does not exist on disk.
     *
     * @return WP_Post[]
     */
    public static function get_broken_attachments() {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'fields'         => 'all',
            )
        );

        $broken = array();
        if ( empty( $query->posts ) ) {
            return $broken;
        }

        foreach ( $query->posts as $post ) {
            $path = get_attached_file( $post->ID );
            if ( ! $path || ! file_exists( $path ) ) {
                $broken[] = $post;
            }
        }

        return $broken;
    }

    /**
     * Attachment IDs missing the `_wp_attachment_image_alt` meta value.
     *
     * @param array $post_types Optional MIME-type prefixes; reserved for future
     *                          callers. Accepted but currently ignored beyond
     *                          the implicit image filter.
     *
     * @return int[]
     */
    public static function get_missing_alt_text( $post_types = array() ) {
        unset( $post_types );

        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'fields'         => 'ids',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'meta_query'     => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_wp_attachment_image_alt',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_wp_attachment_image_alt',
                        'value'   => '',
                        'compare' => '=',
                    ),
                ),
            )
        );

        return array_map( 'intval', (array) $query->posts );
    }

    /**
     * Find attachments where `post_parent = 0` but the URL appears in
     * `wp_posts.post_content`.
     *
     * @return array<int, array{attachment_id:int, appears_in:int[]}>
     */
    public static function find_inline_orphans() {
        global $wpdb;

        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'post_parent'    => 0,
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'fields'         => 'ids',
            )
        );

        $results = array();
        if ( empty( $query->posts ) ) {
            return $results;
        }

        foreach ( $query->posts as $attachment_id ) {
            $url = wp_get_attachment_url( (int) $attachment_id );
            if ( ! $url ) {
                continue;
            }

            $basename = wp_basename( $url );
            $like     = '%' . $wpdb->esc_like( $basename ) . '%';

            $appears_in = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','pending','future') AND post_content LIKE %s",
                    $like
                )
            );

            $appears_in = array_values( array_unique( array_map( 'intval', (array) $appears_in ) ) );
            if ( empty( $appears_in ) ) {
                continue;
            }

            $results[] = array(
                'attachment_id' => (int) $attachment_id,
                'appears_in'    => $appears_in,
            );
        }

        return $results;
    }

    /**
     * Read EXIF / IPTC data for an attachment.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return array EXIF data (possibly empty).
     */
    public static function get_exif_data( $attachment_id ) {
        $path = get_attached_file( (int) $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            return array();
        }

        if ( function_exists( 'exif_read_data' ) ) {
            // exif_read_data() emits warnings on unsupported formats; suppress.
            $data = @exif_read_data( $path, 0, true );
            if ( is_array( $data ) ) {
                return $data;
            }
        }

        if ( function_exists( 'wp_read_image_metadata' ) ) {
            $meta = wp_read_image_metadata( $path );
            if ( is_array( $meta ) ) {
                return $meta;
            }
        }

        return array();
    }

    /**
     * Audit an attachment's EXIF for license/copyright data.
     *
     * Returns a structured array per the sister-plugin contract. `has_license`
     * is true only when `license_url` passes FILTER_VALIDATE_URL — the
     * presence of copyright text alone is not sufficient.
     *
     * @param int $attachment_id Attachment post ID.
     *
     * @return array {
     *     @type int      $attachment_id   The queried ID.
     *     @type bool     $has_license     True only when license_url is a valid URL.
     *     @type string   $license_url     From XMP dc:rights or similar if URL-shaped; empty otherwise.
     *     @type string   $copyright_text  exif:Copyright or wp_read_image_metadata 'copyright'.
     *     @type string   $credit_text     exif:Artist or 'credit'; empty if absent.
     *     @type string   $description     exif:ImageDescription or 'caption'; empty if absent.
     *     @type string   $source          'imagick' | 'wp_meta' | 'none'.
     *     @type string[] $audit_flags     Problem keys found during audit.
     * }
     */
    public static function get_license_exif_report( $attachment_id ) {
        $attachment_id = (int) $attachment_id;
        $path          = get_attached_file( $attachment_id );
        $exif          = array();
        $source        = 'none';

        if ( $path && file_exists( $path ) ) {
            if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
                try {
                    $imagick = new \Imagick( $path );
                    foreach ( array( 'exif:Copyright', 'exif:Artist', 'exif:ImageDescription', 'dc:rights', 'xmpRights:WebStatement' ) as $prop ) {
                        try {
                            $val = $imagick->getImageProperty( $prop );
                            if ( false !== $val && '' !== $val ) {
                                $exif[ $prop ] = $val;
                            }
                        } catch ( \Exception $e ) {
                            // Property not present; skip.
                        }
                    }
                    $imagick->destroy();
                    $source = 'imagick';
                } catch ( \Exception $e ) {
                    $source = 'none';
                }
            }

            if ( 'none' === $source && function_exists( 'wp_read_image_metadata' ) ) {
                $meta = wp_read_image_metadata( $path );
                if ( is_array( $meta ) && ! empty( $meta ) ) {
                    $exif   = $meta;
                    $source = 'wp_meta';
                }
            }
        }

        // Resolve field values using source-aware key names.
        $copyright   = '';
        $credit      = '';
        $description = '';
        $license_url = '';

        if ( 'imagick' === $source ) {
            $copyright   = isset( $exif['exif:Copyright'] ) ? (string) $exif['exif:Copyright'] : '';
            $credit      = isset( $exif['exif:Artist'] ) ? (string) $exif['exif:Artist'] : '';
            $description = isset( $exif['exif:ImageDescription'] ) ? (string) $exif['exif:ImageDescription'] : '';
            foreach ( array( 'dc:rights', 'xmpRights:WebStatement' ) as $k ) {
                if ( isset( $exif[ $k ] ) && filter_var( trim( (string) $exif[ $k ] ), FILTER_VALIDATE_URL ) ) {
                    $license_url = trim( (string) $exif[ $k ] );
                    break;
                }
            }
        } elseif ( 'wp_meta' === $source ) {
            $copyright   = isset( $exif['copyright'] ) ? (string) $exif['copyright'] : '';
            $credit      = isset( $exif['credit'] ) ? (string) $exif['credit'] : ( isset( $exif['photographer'] ) ? (string) $exif['photographer'] : '' );
            $description = isset( $exif['caption'] ) ? (string) $exif['caption'] : ( isset( $exif['title'] ) ? (string) $exif['title'] : '' );
            // wp_read_image_metadata does not expose dc:rights; license_url stays empty.
        }

        $has_license = '' !== $license_url;

        // Non-URL rights value in the raw EXIF.
        $has_non_url_rights = false;
        if ( 'imagick' === $source ) {
            foreach ( array( 'dc:rights', 'xmpRights:WebStatement' ) as $k ) {
                if ( isset( $exif[ $k ] ) && '' !== trim( (string) $exif[ $k ] ) && ! filter_var( trim( (string) $exif[ $k ] ), FILTER_VALIDATE_URL ) ) {
                    $has_non_url_rights = true;
                    break;
                }
            }
        }

        $flags = array();
        if ( ! $has_license ) {
            $flags[] = 'missing_license_url';
        }
        if ( '' === $copyright ) {
            $flags[] = 'missing_copyright';
        }
        if ( '' === $credit ) {
            $flags[] = 'missing_credit';
        }
        if ( $has_non_url_rights ) {
            $flags[] = 'non_url_rights_field';
        }

        return array(
            'attachment_id'  => $attachment_id,
            'has_license'    => $has_license,
            'license_url'    => $license_url,
            'copyright_text' => $copyright,
            'credit_text'    => $credit,
            'description'    => $description,
            'source'         => $source,
            'audit_flags'    => $flags,
        );
    }

    /**
     * Whether EXIF has GPS coordinates.
     *
     * @param array $exif EXIF array as returned by get_exif_data().
     *
     * @return bool
     */
    public static function exif_has_gps( $exif ) {
        if ( empty( $exif ) ) {
            return false;
        }

        if ( isset( $exif['GPS'] ) && is_array( $exif['GPS'] ) ) {
            $gps = $exif['GPS'];
            if ( isset( $gps['GPSLatitude'] ) || isset( $gps['GPSLongitude'] ) ) {
                return true;
            }
        }

        if ( isset( $exif['GPSLatitude'] ) || isset( $exif['GPSLongitude'] ) ) {
            return true;
        }

        if ( isset( $exif['latitude'] ) || isset( $exif['longitude'] ) ) {
            return true;
        }

        return false;
    }

    /**
     * Strip identifying EXIF (GPS, serial numbers) while preserving credit
     * fields (Copyright, Artist, ImageDescription) via Imagick.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return true|WP_Error
     */
    public static function strip_exif( $attachment_id ) {
        if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
            return new WP_Error( 'no_imagick', __( 'Imagick is required to strip EXIF data.', 'thisismyurl-image-support' ) );
        }

        $path = get_attached_file( (int) $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            return new WP_Error( 'missing', __( 'File does not exist.', 'thisismyurl-image-support' ) );
        }

        try {
            $imagick = new \Imagick( $path );

            // Capture credit fields we want to retain across the strip.
            $copyright   = '';
            $artist      = '';
            $description = '';

            try {
                $copyright = (string) $imagick->getImageProperty( 'exif:Copyright' );
            } catch ( \Exception $e ) {
                $copyright = '';
            }
            try {
                $artist = (string) $imagick->getImageProperty( 'exif:Artist' );
            } catch ( \Exception $e ) {
                $artist = '';
            }
            try {
                $description = (string) $imagick->getImageProperty( 'exif:ImageDescription' );
            } catch ( \Exception $e ) {
                $description = '';
            }

            // Strip everything, then re-apply the safe fields.
            $imagick->stripImage();

            if ( '' !== $copyright ) {
                $imagick->setImageProperty( 'exif:Copyright', $copyright );
            }
            if ( '' !== $artist ) {
                $imagick->setImageProperty( 'exif:Artist', $artist );
            }
            if ( '' !== $description ) {
                $imagick->setImageProperty( 'exif:ImageDescription', $description );
            }

            $imagick->writeImage( $path );
            $imagick->destroy();
        } catch ( \Exception $e ) {
            return new WP_Error( 'imagick_error', $e->getMessage() );
        }

        return true;
    }

    /**
     * Bulk re-attach a list of pairs.
     *
     * @param array $pairs    Array of [ 'attachment_id' => int, 'parent_id' => int ].
     * @param bool  $dry_run  If true, do not modify posts; return proposed actions.
     *
     * @return array Result: [ 'updated' => int[], 'proposed' => array, 'skipped' => array ]
     */
    public static function reattach_bulk( $pairs, $dry_run = true ) {
        $updated  = array();
        $proposed = array();
        $skipped  = array();

        foreach ( (array) $pairs as $row ) {
            $attachment_id = isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
            $parent_id     = isset( $row['parent_id'] ) ? (int) $row['parent_id'] : 0;

            if ( ! $attachment_id || ! $parent_id ) {
                $skipped[] = $row;
                continue;
            }

            if ( 'attachment' !== get_post_type( $attachment_id ) ) {
                $skipped[] = $row;
                continue;
            }

            if ( $dry_run ) {
                $proposed[] = array(
                    'attachment_id' => $attachment_id,
                    'parent_id'     => $parent_id,
                );
                continue;
            }

            $result = wp_update_post(
                array(
                    'ID'          => $attachment_id,
                    'post_parent' => $parent_id,
                ),
                true
            );

            if ( ! is_wp_error( $result ) ) {
                $updated[] = $attachment_id;
            } else {
                $skipped[] = $row;
            }
        }

        return array(
            'updated'  => $updated,
            'proposed' => $proposed,
            'skipped'  => $skipped,
        );
    }
}
