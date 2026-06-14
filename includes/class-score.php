<?php
/**
 * Library Health Score: aggregate the audit signals into one 0-100 curation
 * score plus a per-factor breakdown.
 *
 * Every walk over the attachment library is bounded — paged `fields=ids`
 * queries and direct COUNT()s, never an unbounded `posts_per_page=-1` load on
 * an admin hit. The full result is cached in a transient and recomputed only
 * on demand (the Recompute control) or when the cache expires.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Compute and cache the six-factor Library Health Score.
 */
class TIMU_IC_Score {

    /**
     * Transient key holding the most recently computed score payload.
     */
    const TRANSIENT_KEY = 'timu_ic_health_score';

    /**
     * How long a computed score stays cached before a background recompute.
     */
    const CACHE_TTL = DAY_IN_SECONDS;

    /**
     * Page size for the bounded ID walks.
     */
    const PAGE_SIZE = 200;

    /**
     * Hard ceiling on how many files the duplicate and GPS passes will hash /
     * read per computation, so a very large library cannot stall the request.
     * When the library is larger than the cap, the factor reports a sampled
     * estimate rather than an exact count.
     */
    const SCAN_CAP = 2000;

    /**
     * Return the cached score, computing it first if no cache exists.
     *
     * @return array Score payload (see compute()).
     */
    public static function get() {
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( is_array( $cached ) && isset( $cached['overall'] ) ) {
            return $cached;
        }

        return self::compute();
    }

    /**
     * Compute the score fresh, cache it, and return it.
     *
     * @return array Score payload.
     */
    public static function compute() {
        $total = self::count_image_attachments();

        $factors = array(
            'missing_alt' => self::factor_missing_alt( $total ),
            'gps_privacy' => self::factor_gps_privacy( $total ),
            'junk_names'  => self::factor_junk_filenames( $total ),
            'oversized'   => self::factor_oversized( $total ),
            'orphans'     => self::factor_orphans( $total ),
            'duplicates'  => self::factor_duplicates( $total ),
        );

        $weights = self::weights();
        $overall = self::weighted_overall( $factors, $weights );
        $band    = self::band_for( $overall );

        $payload = array(
            'overall'      => $overall,
            'band'         => $band['key'],
            'band_label'   => $band['label'],
            'summary'      => self::summary_for( $overall, $factors ),
            'total_images' => $total,
            'factors'      => $factors,
            'computed_at'  => time(),
        );

        /**
         * Filter the fully computed Library Health Score payload.
         *
         * Lets a developer adjust the final shape — re-band, re-summarise, or
         * inject a custom factor — at the single point every consumer reads.
         *
         * @since 1.6166
         *
         * @param array $payload Computed score payload.
         */
        $payload = apply_filters( 'thisismyurl_image_support_health_score', $payload );

        set_transient( self::TRANSIENT_KEY, $payload, self::CACHE_TTL );

        return $payload;
    }

    /**
     * Clear the cached score so the next read recomputes.
     *
     * @return void
     */
    public static function flush() {
        delete_transient( self::TRANSIENT_KEY );
    }

    // -------------------------------------------------------------------------
    // Scoring configuration (filterable)
    // -------------------------------------------------------------------------

    /**
     * Per-factor weights for the weighted overall average.
     *
     * @return array<string,int> Factor key => weight.
     */
    public static function weights() {
        $weights = array(
            'missing_alt' => 25,
            'gps_privacy' => 25,
            'junk_names'  => 15,
            'oversized'   => 15,
            'orphans'     => 10,
            'duplicates'  => 10,
        );

        /**
         * Filter the per-factor weights used in the overall score average.
         *
         * @since 1.6166
         *
         * @param array<string,int> $weights Factor key => weight.
         */
        return (array) apply_filters( 'thisismyurl_image_support_health_weights', $weights );
    }

    /**
     * Band thresholds, highest floor first. Each entry maps a minimum score to
     * a non-colour text label.
     *
     * @return array<int,array{min:int,key:string,label:string}>
     */
    public static function bands() {
        $bands = array(
            array(
                'min'   => 90,
                'key'   => 'excellent',
                'label' => __( 'Excellent', 'thisismyurl-image-support' ),
            ),
            array(
                'min'   => 75,
                'key'   => 'good',
                'label' => __( 'Good', 'thisismyurl-image-support' ),
            ),
            array(
                'min'   => 50,
                'key'   => 'needs-work',
                'label' => __( 'Needs work', 'thisismyurl-image-support' ),
            ),
            array(
                'min'   => 0,
                'key'   => 'poor',
                'label' => __( 'Poor', 'thisismyurl-image-support' ),
            ),
        );

        /**
         * Filter the score band thresholds and labels.
         *
         * @since 1.6166
         *
         * @param array $bands Band definitions, highest floor first.
         */
        return (array) apply_filters( 'thisismyurl_image_support_health_bands', $bands );
    }

    // -------------------------------------------------------------------------
    // Per-factor computation
    // -------------------------------------------------------------------------

    /**
     * Missing-alt factor: ratio of image attachments with empty alt text.
     *
     * @param int $total Library size (image attachments).
     *
     * @return array Factor result.
     */
    private static function factor_missing_alt( $total ) {
        global $wpdb;

        // Bounded COUNT — no row materialisation. An image is "missing alt" when
        // the meta row is absent or its trimmed value is empty.
        $affected = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} m
                   ON m.post_id = p.ID AND m.meta_key = %s
                 WHERE p.post_type = 'attachment'
                   AND p.post_mime_type LIKE %s
                   AND ( m.meta_id IS NULL OR TRIM(m.meta_value) = '' )",
                '_wp_attachment_image_alt',
                $wpdb->esc_like( 'image/' ) . '%'
            )
        );

        return self::ratio_factor(
            'missing_alt',
            __( 'Alt text', 'thisismyurl-image-support' ),
            $affected,
            $total,
            __( 'Images with no alt text. Alt text matters for accessibility and search.', 'thisismyurl-image-support' )
        );
    }

    /**
     * Junk-filename factor: ratio whose basename matches a non-descriptive
     * pattern (camera/phone defaults, screenshots, untitled, bare numbers).
     *
     * @param int $total Library size.
     *
     * @return array Factor result.
     */
    private static function factor_junk_filenames( $total ) {
        $affected = 0;

        foreach ( self::walk_image_ids() as $id ) {
            $file = get_attached_file( $id );
            if ( ! $file ) {
                continue;
            }
            if ( self::is_junk_basename( wp_basename( $file ) ) ) {
                $affected++;
            }
        }

        return self::ratio_factor(
            'junk_names',
            __( 'Filenames', 'thisismyurl-image-support' ),
            $affected,
            $total,
            __( 'Images with camera-default or non-descriptive filenames (IMG_, DSC, screenshot, untitled).', 'thisismyurl-image-support' )
        );
    }

    /**
     * Oversized factor: ratio whose stored width or height exceeds the
     * configured max dimension.
     *
     * @param int $total Library size.
     *
     * @return array Factor result.
     */
    private static function factor_oversized( $total ) {
        $options = TIMU_IC_Options::get();
        $max     = isset( $options['max_dimension'] ) ? (int) $options['max_dimension'] : 2560;

        $affected = 0;
        foreach ( self::walk_image_ids() as $id ) {
            $meta = wp_get_attachment_metadata( $id );
            $w    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
            $h    = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
            if ( $w > $max || $h > $max ) {
                $affected++;
            }
        }

        $factor = self::ratio_factor(
            'oversized',
            __( 'Dimensions', 'thisismyurl-image-support' ),
            $affected,
            $total,
            sprintf(
                /* translators: %d: configured maximum pixel dimension */
                __( 'Images wider or taller than the %dpx limit. Downscaling cuts page weight.', 'thisismyurl-image-support' ),
                $max
            )
        );

        $factor['max_dimension'] = $max;

        return $factor;
    }

    /**
     * Unused-attachment factor: image attachments referenced nowhere — not in
     * any post_content, not as a featured image, not attached to a parent.
     *
     * This is the curation signal that matters: real library entries that no
     * longer earn their place. It is distinct from the Audit tab's filesystem
     * orphan list (files on disk with no DB record), which stays its own signal.
     * The detector is bounded and extrapolates from a sample on large libraries.
     *
     * @param int $total Library size.
     *
     * @return array Factor result.
     */
    private static function factor_orphans( $total ) {
        $result   = TIMU_IC_Audit::count_unused_attachments();
        $affected = isset( $result['count'] ) ? (int) $result['count'] : 0;

        $factor            = self::count_factor(
            'orphans',
            __( 'Unused images', 'thisismyurl-image-support' ),
            $affected,
            $total,
            __( 'Unused images (referenced nowhere) — not in any post, featured image, or gallery. Review before deleting.', 'thisismyurl-image-support' )
        );
        $factor['sampled'] = ! empty( $result['sampled'] );

        return $factor;
    }

    /**
     * Duplicate factor: binary-identical images, scored as a count penalty.
     *
     * Computes md5 hashes in a bounded, capped walk rather than relying on the
     * post-processing `_timu_file_hash` meta (which is only set after an image
     * has been through the cleanup pipeline). The affected count is the number
     * of redundant copies — every duplicate beyond the first in each hash group.
     *
     * @param int $total Library size.
     *
     * @return array Factor result.
     */
    private static function factor_duplicates( $total ) {
        $seen    = array();
        $extra   = 0;
        $scanned = 0;

        foreach ( self::walk_image_ids() as $id ) {
            if ( $scanned >= self::SCAN_CAP ) {
                break;
            }
            $file = get_attached_file( $id );
            if ( ! $file || ! file_exists( $file ) ) {
                continue;
            }
            $hash = md5_file( $file );
            if ( ! $hash ) {
                continue;
            }
            $scanned++;
            if ( isset( $seen[ $hash ] ) ) {
                $extra++;
            } else {
                $seen[ $hash ] = true;
            }
        }

        $factor            = self::count_factor(
            'duplicates',
            __( 'Duplicates', 'thisismyurl-image-support' ),
            $extra,
            $total,
            __( 'Binary-identical copies of the same image. Merging reclaims storage and tidies the library.', 'thisismyurl-image-support' )
        );
        $factor['sampled'] = ( $scanned >= self::SCAN_CAP && $total > self::SCAN_CAP );

        return $factor;
    }

    /**
     * Privacy factor: ratio of images carrying GPS coordinates in EXIF.
     *
     * The EXIF read is the most expensive pass, so it is capped at SCAN_CAP and
     * the ratio is extrapolated from the sample when the library is larger.
     *
     * @param int $total Library size.
     *
     * @return array Factor result.
     */
    private static function factor_gps_privacy( $total ) {
        $with_gps = 0;
        $scanned  = 0;

        foreach ( self::walk_image_ids() as $id ) {
            if ( $scanned >= self::SCAN_CAP ) {
                break;
            }
            $scanned++;
            $exif = TIMU_IC_Audit::get_exif_data( $id );
            if ( TIMU_IC_Audit::exif_has_gps( $exif ) ) {
                $with_gps++;
            }
        }

        // Extrapolate the affected count from the sampled rate when capped.
        $affected = $with_gps;
        $sampled  = ( $scanned > 0 && $scanned < $total );
        if ( $sampled && $scanned > 0 ) {
            $affected = (int) round( ( $with_gps / $scanned ) * $total );
        }

        $factor            = self::ratio_factor(
            'gps_privacy',
            __( 'Privacy (GPS)', 'thisismyurl-image-support' ),
            $affected,
            $total,
            __( 'Images carrying GPS coordinates in their EXIF data. Strip these to protect location privacy.', 'thisismyurl-image-support' )
        );
        $factor['sampled'] = $sampled;

        return $factor;
    }

    // -------------------------------------------------------------------------
    // Factor shaping
    // -------------------------------------------------------------------------

    /**
     * Build a ratio-style factor result: sub-score = 100 * (1 - affected/total).
     *
     * @param string $key         Factor key.
     * @param string $label       Display label.
     * @param int    $affected    Affected count.
     * @param int    $total       Library size.
     * @param string $description One-line plain-language description.
     *
     * @return array Factor result.
     */
    private static function ratio_factor( $key, $label, $affected, $total, $description ) {
        $affected = max( 0, min( (int) $affected, (int) $total ) );
        $sub      = $total > 0 ? (int) round( 100 * ( 1 - ( $affected / $total ) ) ) : 100;

        return array(
            'key'         => $key,
            'label'       => $label,
            'affected'    => $affected,
            'total'       => (int) $total,
            'percent'     => $total > 0 ? round( 100 * ( $affected / $total ), 1 ) : 0.0,
            'sub_score'   => self::clamp_score( $sub ),
            'description' => $description,
        );
    }

    /**
     * Build a count-style factor result. The penalty scales with the affected
     * count relative to library size: a handful of orphans on a large library
     * is a light touch; the same count on a tiny library hurts more.
     *
     * @param string $key         Factor key.
     * @param string $label       Display label.
     * @param int    $affected    Affected count.
     * @param int    $total       Library size.
     * @param string $description One-line description.
     *
     * @return array Factor result.
     */
    private static function count_factor( $key, $label, $affected, $total, $description ) {
        $affected = max( 0, (int) $affected );
        $base     = max( 1, (int) $total );
        $ratio    = min( 1, $affected / $base );
        $sub      = (int) round( 100 * ( 1 - $ratio ) );

        // A count can exceed library size (more orphan files than attachments),
        // so cap the displayed percent at 100 to avoid a >100% readout. The
        // sub-score above already clamps the penalty via min( 1, ... ).
        $percent = $total > 0 ? min( 100.0, round( 100 * ( $affected / $base ), 1 ) ) : 0.0;

        return array(
            'key'         => $key,
            'label'       => $label,
            'affected'    => $affected,
            'total'       => (int) $total,
            'percent'     => $percent,
            'sub_score'   => self::clamp_score( $sub ),
            'description' => $description,
        );
    }

    /**
     * Weighted average of the factor sub-scores.
     *
     * @param array $factors Computed factors keyed by factor key.
     * @param array $weights Weights keyed by factor key.
     *
     * @return int Overall score, 0-100.
     */
    private static function weighted_overall( $factors, $weights ) {
        $weighted_sum = 0;
        $weight_total = 0;

        foreach ( $factors as $key => $factor ) {
            $weight = isset( $weights[ $key ] ) ? (int) $weights[ $key ] : 0;
            if ( $weight <= 0 ) {
                continue;
            }
            $weighted_sum += $factor['sub_score'] * $weight;
            $weight_total += $weight;
        }

        if ( $weight_total <= 0 ) {
            return 100;
        }

        return self::clamp_score( (int) round( $weighted_sum / $weight_total ) );
    }

    /**
     * Resolve a score to its band definition.
     *
     * @param int $score Overall score.
     *
     * @return array{key:string,label:string}
     */
    private static function band_for( $score ) {
        foreach ( self::bands() as $band ) {
            if ( $score >= (int) $band['min'] ) {
                return array(
                    'key'   => $band['key'],
                    'label' => $band['label'],
                );
            }
        }

        return array(
            'key'   => 'poor',
            'label' => __( 'Poor', 'thisismyurl-image-support' ),
        );
    }

    /**
     * Build the one-line plain-language summary under the score.
     *
     * @param int   $score   Overall score.
     * @param array $factors Computed factors.
     *
     * @return string
     */
    private static function summary_for( $score, $factors ) {
        $issues = 0;
        foreach ( $factors as $factor ) {
            if ( $factor['affected'] > 0 ) {
                $issues++;
            }
        }

        if ( 0 === $issues ) {
            return __( 'Your media library is in great shape — no issues found.', 'thisismyurl-image-support' );
        }

        if ( $score >= 75 ) {
            return sprintf(
                /* translators: %s: number of factors with issues */
                _n(
                    'Your media library is in good shape. %s area is worth a look.',
                    'Your media library is in good shape. %s areas are worth a look.',
                    $issues,
                    'thisismyurl-image-support'
                ),
                number_format_i18n( $issues )
            );
        }

        return sprintf(
            /* translators: %s: number of factors with issues */
            _n(
                '%s area needs attention to improve your library.',
                '%s areas need attention to improve your library.',
                $issues,
                'thisismyurl-image-support'
            ),
            number_format_i18n( $issues )
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Bounded count of image attachments in the library.
     *
     * @return int
     */
    private static function count_image_attachments() {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'attachment' AND post_mime_type LIKE %s",
                $wpdb->esc_like( 'image/' ) . '%'
            )
        );
    }

    /**
     * Generator yielding image attachment IDs in bounded pages.
     *
     * Walks the library PAGE_SIZE rows at a time with `fields=ids` and
     * `no_found_rows`, so memory stays flat regardless of library size and no
     * single query loads the whole set.
     *
     * @return Generator<int>
     */
    private static function walk_image_ids() {
        $paged     = 1;
        $max_loops = 10000;

        do {
            $query = new WP_Query(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'post_mime_type' => 'image',
                    'posts_per_page' => self::PAGE_SIZE,
                    'paged'          => $paged,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                )
            );

            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $id ) {
                yield (int) $id;
            }

            ++$paged;
            --$max_loops;
        } while ( $max_loops > 0 );
    }

    /**
     * Whether a basename is a non-descriptive / camera-default junk name.
     *
     * @param string $basename File basename (with extension).
     *
     * @return bool
     */
    public static function is_junk_basename( $basename ) {
        $name = strtolower( (string) pathinfo( $basename, PATHINFO_FILENAME ) );

        // Strip WordPress-generated tails so the test sees the canonical base.
        $name = preg_replace( '/-(?:scaled|e[0-9]{10,}|\d+x\d+)$/', '', $name );
        $name = trim( (string) $name );

        if ( '' === $name ) {
            return true;
        }

        $patterns = array(
            '/^img[\-_]?\d+/',          // IMG_1234, IMG-1234, IMG1234.
            '/^dsc[\-_]?\d+/',          // DSC_0001, DSCN0001-ish.
            '/^dscf?\d+/',              // DSCF1234 (Fujifilm).
            '/^p\d{6,}/',               // P1010101 (Panasonic/Olympus).
            '/^untitled/',              // untitled, untitled-1.
            '/^screenshot/',            // screenshot, Screen Shot.
            '/^screen[\-_ ]?shot/',     // screen shot 2024-...
            '/^image\d*$/',             // image, image1, image23.
            '/^photo[\-_]?\d+/',        // photo_1, photo-2.
            '/^pic[\-_]?\d+/',          // pic1, pic_2.
            '/^capture/',               // capture, capture-3.
            '/^scan[\-_]?\d+/',         // scan_001.
            '/^\d+$/',                  // bare numeric: 1234567.
            '/^(?:wa|signal|fb)[\-_]?img/', // WhatsApp / Signal / FB exports.
            '/^received_\d+/',          // messenger-style received_1234.
            '/^download(?:[\-_ ]?\d+)?$/', // download, download-1.
            '/^unnamed/',               // gmail attachment default.
        );

        /**
         * Filter the junk-filename patterns the score counts against.
         *
         * @since 1.6166
         *
         * @param string[] $patterns Regex patterns (lowercase, anchored on the base name).
         */
        $patterns = (array) apply_filters( 'thisismyurl_image_support_junk_patterns', $patterns );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $name ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clamp a score into the 0-100 range.
     *
     * @param int $score Raw score.
     *
     * @return int
     */
    private static function clamp_score( $score ) {
        return max( 0, min( 100, (int) $score ) );
    }
}
