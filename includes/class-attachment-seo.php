<?php
/**
 * Attachment-page SEO control.
 *
 * WordPress auto-generates a standalone page for every image attachment
 * (`?attachment_id=…` and the pretty `/<slug>/attachment/` permalink). These
 * pages are thin — one image, little surrounding content — and they dilute a
 * site's index when search engines crawl them. This class lets an operator
 * choose how those pages behave, from a single Settings-tab control:
 *
 *  - `noindex`         add a `noindex,follow` robots directive (default).
 *  - `redirect_parent` 301 to the attachment's parent post, falling back to the
 *                      file URL when the attachment has no parent.
 *  - `redirect_file`   301 straight to the file URL.
 *  - `disable`         do nothing — WordPress's stock behaviour.
 *
 * The default is `noindex`: non-destructive and fully reversible. Redirects are
 * the heavier hammer and are opt-in.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Apply the configured SEO behaviour to image attachment pages.
 */
class TIMU_IC_Attachment_SEO {

    /**
     * Register the hooks the configured mode needs — and only those.
     *
     * `noindex` hooks `wp_robots`; the two redirect modes hook
     * `template_redirect`. `disable` registers nothing at all, so the stock
     * WordPress attachment page is left entirely untouched.
     *
     * @return void
     */
    public static function init() {
        $mode = self::mode();

        if ( 'noindex' === $mode ) {
            add_filter( 'wp_robots', array( __CLASS__, 'filter_robots' ) );
            return;
        }

        if ( 'redirect_parent' === $mode || 'redirect_file' === $mode ) {
            // Priority 5: ahead of the main plugin's image-404 handler (default
            // 10), which only acts on is_404() and so never overlaps, but the
            // earlier slot keeps attachment handling unambiguous.
            add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 5 );
        }
    }

    /**
     * The effective attachment-page mode from saved options.
     *
     * @return string One of the values in TIMU_IC_Options::attachment_page_modes().
     */
    private static function mode() {
        $options = TIMU_IC_Options::get();
        $mode    = isset( $options['attachment_pages'] ) ? (string) $options['attachment_pages'] : 'noindex';

        return in_array( $mode, TIMU_IC_Options::attachment_page_modes(), true ) ? $mode : 'noindex';
    }

    /**
     * Add a no-index directive to the robots array on image attachment pages.
     *
     * Uses the WP 5.7+ `wp_robots` pipeline (`wp_robots_no_robots()` shape):
     * `noindex` keeps the page out of the index while `follow` lets crawlers
     * pass through to the linked file and parent. Non-image attachments and
     * every other view are left untouched.
     *
     * @param array $robots Robots directives keyed by directive name.
     *
     * @return array
     */
    public static function filter_robots( $robots ) {
        if ( ! self::is_image_attachment() ) {
            return $robots;
        }

        $robots['noindex'] = true;
        $robots['follow']  = true;

        return $robots;
    }

    /**
     * Redirect an image attachment page per the configured mode.
     *
     * Guards, in order: only on a singular image attachment view; never for a
     * logged-in user who can edit the attachment (so previews still work); and
     * never to the attachment's own permalink (loop protection).
     *
     * @return void
     */
    public static function maybe_redirect() {
        if ( ! self::is_image_attachment() ) {
            return;
        }

        $attachment_id = get_queried_object_id();
        if ( ! $attachment_id ) {
            return;
        }

        // Leave the page intact for an editor previewing it.
        if ( is_user_logged_in() && current_user_can( 'edit_post', $attachment_id ) ) {
            return;
        }

        $target = self::redirect_target( $attachment_id );
        if ( '' === $target ) {
            return;
        }

        // Loop protection: never redirect a page to itself.
        $self = get_permalink( $attachment_id );
        if ( $self && self::same_url( $self, $target ) ) {
            return;
        }

        wp_safe_redirect( $target, 301 );
        exit;
    }

    /**
     * Resolve the redirect destination for an attachment under the active mode.
     *
     * `redirect_parent` prefers the parent post permalink and falls back to the
     * file URL when there is no parent. `redirect_file` always targets the file.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return string Absolute URL, or '' when none can be resolved.
     */
    private static function redirect_target( $attachment_id ) {
        $mode = self::mode();

        if ( 'redirect_parent' === $mode ) {
            $parent_id = (int) get_post_field( 'post_parent', $attachment_id );
            if ( $parent_id > 0 && 'publish' === get_post_status( $parent_id ) ) {
                $parent_url = get_permalink( $parent_id );
                if ( $parent_url ) {
                    return $parent_url;
                }
            }
        }

        // redirect_file, or redirect_parent with no usable parent.
        $file_url = wp_get_attachment_url( $attachment_id );

        return $file_url ? $file_url : '';
    }

    /**
     * Whether the current request is a singular image attachment view.
     *
     * @return bool
     */
    private static function is_image_attachment() {
        if ( ! is_attachment() ) {
            return false;
        }

        $mime = get_post_mime_type( get_queried_object_id() );

        return is_string( $mime ) && 0 === strpos( $mime, 'image/' );
    }

    /**
     * Compare two URLs by scheme-insensitive host + path, ignoring query/fragment.
     *
     * @param string $a First URL.
     * @param string $b Second URL.
     *
     * @return bool
     */
    private static function same_url( $a, $b ) {
        $pa = wp_parse_url( $a );
        $pb = wp_parse_url( $b );

        $host_a = isset( $pa['host'] ) ? strtolower( $pa['host'] ) : '';
        $host_b = isset( $pb['host'] ) ? strtolower( $pb['host'] ) : '';
        $path_a = isset( $pa['path'] ) ? untrailingslashit( $pa['path'] ) : '';
        $path_b = isset( $pb['path'] ) ? untrailingslashit( $pb['path'] ) : '';

        return $host_a === $host_b && $path_a === $path_b;
    }
}
