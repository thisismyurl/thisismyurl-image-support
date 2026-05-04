<?php
/**
 * Author:      Christopher Ross
 * Author URI:  https://thisismyurl.com/
 * Plugin Name: Image Support by thisismyurl.com
 * Plugin URI:  https://thisismyurl.com/thisismyurl-image-support/
 * Donate link: https://thisismyurl.com/donate/
 * Description: Advanced image sanitization, duplicate merging, WebP filesystem discovery, and deep content re-syncing. Destructive — requires opt-in via the "Confirm destructive operations" option before any rename, merge, or post_content rewrite runs.
 * Version:     0.6124
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Update URI: https://github.com/thisismyurl/thisismyurl-image-support
 * Text Domain: thisismyurl-image-support
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TIMU_IC {

    private $backup_dir;

    public function __construct() {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_menu', [ $this, 'cleanup_menus' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_plugin_action_links' ] );
        // Restore now flows through admin-post.php (POST), not admin_init (GET). See handle_restore_request().
        add_action( 'admin_post_thisismyurl_image_support_restore', [ $this, 'handle_restore_request' ] );
        add_action( 'template_redirect', [ $this, 'handle_image_404_redirects' ] );

        // The on-render WebP swap is opt-in. Synchronous GD encoding inside the_content is a footgun
        // on cold caches — it stalls TTFB on the first hit. Operators who need it can enable via filter
        // and supply async pre-generation; default OFF.
        if ( apply_filters( 'thisismyurl_image_support_enable_dynamic_webp', false ) ) {
            add_filter( 'the_content', [ $this, 'dynamic_webp_replacement' ] );
        }

        $upload_dir       = wp_upload_dir();
        $this->backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'timu-image-backups/';
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'thisismyurl-image-support', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /**
     * Drop hardening files into the backup directory so it is never directory-listable
     * and never serves anything by default. Idempotent — safe to call repeatedly.
     */
    public function ensure_backup_dir_protected() {
        if ( ! is_dir( $this->backup_dir ) ) {
            wp_mkdir_p( $this->backup_dir );
        }
        $files = array(
            '.htaccess'   => "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
            'index.html'  => "<!-- silence is golden -->\n",
            'web.config'  => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n",
        );
        foreach ( $files as $name => $body ) {
            $path = $this->backup_dir . $name;
            if ( ! file_exists( $path ) ) {
                file_put_contents( $path, $body );
            }
        }
    }

    public function add_menu_page() {
        add_management_page( 'Image Support', 'Image Support', 'manage_options', 'thisismyurl-image-support', [ $this, 'render_admin_page' ] );
    }

    /**
     * Filters content to replace standard images with WebP, verifying existence
     * and performing a 3-month lookback or generation if missing.
     */
    public function dynamic_webp_replacement( $content ) {
        if ( empty( $content ) ) return $content;

        // Pattern to find <img> tags
        $pattern = '/(<img[^>]+src=["\']([^"\']+\.(?:jpg|jpeg|png|gif))["\'][^>]*>)/i';
        
        return preg_replace_callback( $pattern, function( $matches ) {
            $full_img_tag = $matches[1];
            $original_url = $matches[2];
            
            // Verify existence and find/generate the appropriate WebP URL
            $webp_url = $this->verify_and_locate_webp( $original_url );

            if ( ! $webp_url ) {
                return $full_img_tag;
            }

            $path_info = pathinfo( $original_url );
            $raw_base_name = preg_replace( '/(-[0-9]+x[0-9]+)?$/i', '', $path_info['filename'] );
            $image_orig = str_replace('\\', '', preg_quote( $raw_base_name, '/' ));
            
            // We use the new URL path to ensure the replacement is accurate
            $webp_info = pathinfo( $webp_url );
            $image_new = $webp_info['filename'];

            $search_regex = '/' . $image_orig . '(-[0-9]+x[0-9]+)?\.(?:jpg|jpeg|png|gif)/i';
            $replacement  = $image_new . '$1.webp';

            return preg_replace($search_regex, $replacement, $full_img_tag);
        }, $content );
    }

    /**
     * Verify the WebP exists in the expected location, or look back 3 months.
     *
     * Per-request memoization avoids stat()-storms on pages with many duplicate <img> tags.
     * If still missing, schedules a single async generation job (one-shot wp-cron) and
     * returns false for THIS render — we never block a render thread on GD encoding.
     */
    private function verify_and_locate_webp( $url ) {
        static $cache = array();
        if ( isset( $cache[ $url ] ) ) {
            return $cache[ $url ];
        }

        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        if ( false === strpos( $url, $base_url ) ) {
            return $cache[ $url ] = false;
        }

        $relative_path    = str_replace( $base_url, '', $url );
        $actual_full_path = $base_dir . $relative_path;
        $webp_full_path   = preg_replace( '/\.(?:jpg|jpeg|png|gif)$/i', '.webp', $actual_full_path );

        if ( file_exists( $webp_full_path ) ) {
            return $cache[ $url ] = str_replace( $base_dir, $base_url, $webp_full_path );
        }

        if ( preg_match( '/\/(\d{4})\/(\d{2})\/(.+)$/', $relative_path, $matches ) ) {
            $year  = (int) $matches[1];
            $month = (int) $matches[2];
            $file  = $matches[3];
            $file_webp = preg_replace( '/\.(?:jpg|jpeg|png|gif)$/i', '.webp', $file );

            for ( $i = 1; $i <= 3; $i++ ) {
                $check_month = $month - $i;
                $check_year  = $year;
                if ( $check_month <= 0 ) {
                    $check_month += 12;
                    --$check_year;
                }
                $formatted_month = str_pad( $check_month, 2, '0', STR_PAD_LEFT );
                $lookback_path   = "/$check_year/$formatted_month/$file_webp";
                if ( file_exists( $base_dir . $lookback_path ) ) {
                    return $cache[ $url ] = $base_url . $lookback_path;
                }
            }
        }

        // Schedule async generation, do not block render. Operator gets the WebP on next view.
        if ( ! wp_next_scheduled( 'thisismyurl_image_support_generate_webp', array( $actual_full_path ) ) ) {
            wp_schedule_single_event( time() + 5, 'thisismyurl_image_support_generate_webp', array( $actual_full_path ) );
        }
        return $cache[ $url ] = false;
    }

    /**
     * Generates a WebP version of the image if the source exists.
     *
     * Now safe to call from a wp-cron one-shot event (see verify_and_locate_webp()).
     * Checks for GD before invoking GD functions; defers to the sister plugin if it
     * is present AND its API contract is satisfied.
     */
    public function generate_webp_on_the_fly( $source_path ) {
        if ( ! file_exists( $source_path ) ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $webp_path  = preg_replace( '/\.(?:jpg|jpeg|png|gif)$/i', '.webp', $source_path );

        // Sister plugin: only call when both class AND method exist (API guard).
        if ( $this->sister_plugin_supports( 'convert_to_webp' ) ) {
            global $wpdb;
            $relative      = str_replace( $upload_dir['basedir'], '', $source_path );
            $attachment_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                    '_wp_attached_file',
                    ltrim( $relative, '/' )
                )
            );

            if ( $attachment_id ) {
                TIMU_WEBP_Support::convert_to_webp( $attachment_id );
                if ( file_exists( $webp_path ) ) {
                    return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $webp_path );
                }
            }
        }

        // Fallback: GD. Refuse if GD is not loaded — never fatal-error a request.
        if ( ! extension_loaded( 'gd' ) || ! function_exists( 'imagewebp' ) ) {
            return false;
        }

        $info = @getimagesize( $source_path );
        if ( ! is_array( $info ) || empty( $info['mime'] ) ) {
            return false;
        }

        $image = false;
        switch ( $info['mime'] ) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg( $source_path );
                break;
            case 'image/png':
                $image = imagecreatefrompng( $source_path );
                break;
        }

        if ( $image ) {
            imagepalettetotruecolor( $image );
            imagewebp( $image, $webp_path, 80 );
            imagedestroy( $image );
            return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $webp_path );
        }

        return false;
    }

    /**
     * Verifies that the sister TIMU_WEBP_Support plugin is present AND advertises
     * the method we are about to call. Future-proofs against a sister API rename.
     */
    private function sister_plugin_supports( $method ) {
        if ( ! class_exists( 'TIMU_WEBP_Support' ) ) {
            return false;
        }
        if ( ! method_exists( 'TIMU_WEBP_Support', $method ) ) {
            return false;
        }
        if ( defined( 'TIMU_WEBP_SUPPORT_VERSION' ) && version_compare( TIMU_WEBP_SUPPORT_VERSION, '0.6000', '<' ) ) {
            return false;
        }
        return true;
    }

    public function handle_image_404_redirects() {
        if ( ! is_404() ) {
            return;
        }
        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return;
        }
        $upload_dir       = wp_upload_dir();
        $requested_uri    = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        // Strip query string + fragment; we are matching against a stored relative path only.
        $requested_path   = (string) wp_parse_url( $requested_uri, PHP_URL_PATH );
        if ( '' === $requested_path ) {
            return;
        }
        $relative_request = str_replace(
            (string) wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH ),
            '',
            $requested_path
        );

        global $wpdb;
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                '_timu_original_path',
                $relative_request
            )
        );

        if ( $id ) {
            $new_url = wp_get_attachment_url( $id );
            if ( $new_url ) {
                wp_safe_redirect( $new_url, 301 );
                exit;
            }
        }
    }

    /**
     * Sanitize an attachment filename for the rename pipeline.
     *
     * The previous implementation stripped a 60-word stop-list (and/or/the/wp/draft/test/...).
     * That collapsed dissimilar uploads onto identical slugs and silently merged distinct
     * assets under merge_duplicate_assets(). The stop-list is gone. The pipeline now:
     *
     *  - validates the source filename (no .., no NULs, no RTL override, leading-dot rejected);
     *  - enforces an extension whitelist tied to actual image MIME types we support;
     *  - removes the WP "-scaled" / "-eNNNN" / numeric-suffix tail to recover the canonical base;
     *  - normalises whitespace + underscores to hyphens, lowercases, single-dash collapse;
     *  - falls back to a deterministic "asset-{ID}" slug only when the name reduces to empty.
     *
     * Returns false on any validation failure — callers MUST check the return value.
     */
    private function sanitize_filename( $filename, $post_id = 0 ) {
        if ( ! is_string( $filename ) || '' === $filename ) {
            return false;
        }
        // Reject NULs and the RTL override character outright — both have been used in
        // documented filename-spoofing exploits.
        if ( false !== strpos( $filename, "\0" ) || false !== strpos( $filename, "\xE2\x80\xAE" ) ) {
            return false;
        }
        // Reject path traversal and absolute paths. We only operate on basenames.
        if ( false !== strpos( $filename, '..' ) || false !== strpos( $filename, '/' ) || false !== strpos( $filename, '\\' ) ) {
            return false;
        }
        // Leading dot files (.htaccess, .ds_store) are not valid attachment basenames.
        if ( 0 === strpos( $filename, '.' ) ) {
            return false;
        }

        $info = pathinfo( $filename );
        $name = isset( $info['filename'] ) ? $info['filename'] : '';
        $ext  = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';

        // Extension whitelist tied to the MIME types this plugin's pipeline actually supports.
        $allowed_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
        if ( '' === $ext || ! in_array( $ext, $allowed_ext, true ) ) {
            return false;
        }

        // Strip WordPress-generated tails: -scaled, -eNNNNNNNNNN (edited variants), trailing numeric.
        $name = preg_replace( '/-(?:scaled|e[0-9]{10,}|[0-9]+)$/i', '', $name );

        // Normalise whitespace + underscores; collapse dashes; lowercase.
        $name = preg_replace( '/[\s_]+/', '-', $name );
        $name = preg_replace( '/-+/', '-', $name );
        $name = strtolower( trim( (string) $name, ' -_' ) );

        // Final pass through sanitize_file_name to apply WP's own safety rules.
        $name = sanitize_file_name( $name );

        if ( '' === trim( (string) $name, '-' ) || is_numeric( $name ) ) {
            $parent_id = wp_get_post_parent_id( $post_id );
            $name      = $parent_id ? sanitize_title( get_the_title( $parent_id ) ) : 'asset-' . absint( $post_id );
        }

        return trim( (string) $name, '-' ) . '.' . $ext;
    }

    /**
     * Returns true when the operator has explicitly opted in to destructive operations.
     *
     * Default is FALSE. Destructive paths (force-delete, post_content rewrites) refuse
     * to run unless this option is set. Operators can flip via wp_options or filter.
     */
    private function destructive_ops_confirmed() {
        return (bool) apply_filters(
            'thisismyurl_image_support_confirm_destructive',
            (bool) get_option( 'thisismyurl_image_support_confirm_destructive', false )
        );
    }

    /**
     * Backup an attachment's file + post + post_meta to a JSON sidecar before destructive ops.
     *
     * Writes to {backup_dir}/manifests/attachment-{ID}-{timestamp}.json. The file copy is
     * preserved on disk by trash mode (we use trash, not force-delete), so the manifest
     * captures the metadata that trash does not preserve indefinitely.
     */
    private function backup_attachment_record( $attachment_id ) {
        $post = get_post( $attachment_id );
        if ( ! $post ) {
            return false;
        }
        $manifest_dir = $this->backup_dir . 'manifests/';
        if ( ! is_dir( $manifest_dir ) ) {
            wp_mkdir_p( $manifest_dir );
            $this->ensure_backup_dir_protected();
        }
        $payload = array(
            'attachment_id' => $attachment_id,
            'timestamp'     => gmdate( 'c' ),
            'post'          => $post->to_array(),
            'meta'          => get_post_meta( $attachment_id ),
            'attached_file' => get_attached_file( $attachment_id ),
            'url'           => wp_get_attachment_url( $attachment_id ),
        );
        $path = $manifest_dir . 'attachment-' . absint( $attachment_id ) . '-' . time() . '.json';
        return (bool) file_put_contents( $path, wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
    }

    private function merge_duplicate_assets( $duplicate_id, $original_id, $dry_run ) {
        global $wpdb;
        if ( $dry_run ) {
            return;
        }
        if ( ! $this->destructive_ops_confirmed() ) {
            return;
        }

        // Snapshot the duplicate before any destructive action.
        $this->backup_attachment_record( $duplicate_id );

        $wpdb->update(
            $wpdb->postmeta,
            array( 'meta_value' => $original_id ),
            array( 'meta_key' => '_thumbnail_id', 'meta_value' => $duplicate_id )
        );

        $dup_url  = wp_get_attachment_url( $duplicate_id );
        $orig_url = wp_get_attachment_url( $original_id );
        if ( $dup_url && $orig_url ) {
            $this->sync_url_references( $dup_url, $orig_url, false );
        }

        // Trash, not force-delete. Operator can recover from Trash for 30 days.
        wp_delete_attachment( $duplicate_id, false );
    }

    /**
     * Rewrite filename references inside post_content using WP_HTML_Tag_Processor (WP 6.2+).
     *
     * Walks <img> and <a> tags only. Skips elements inside <code>, <pre>, <script>.
     * For each src/href, only rewrites when the host matches the site URL AND the
     * basename (with optional -WxH thumbnail suffix) matches old → new exactly.
     *
     * Bounded WP_Query batch (posts_per_page=50, paged) over post_type=any/post_status=any
     * minus revisions. Snapshots each affected post via wp_save_post_revision() BEFORE update.
     *
     * @param string $old_filename Source basename, e.g. "old-image.jpg".
     * @param string $new_filename Target basename, e.g. "old-image.webp".
     * @param bool   $dry_run      When true, report only — no writes.
     * @return array Unique list of source URLs that matched, for the operator report.
     */
    private function sync_content_references( $old_filename, $new_filename, $dry_run = false ) {
        if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
            return array();
        }

        $old_info = pathinfo( $old_filename );
        $new_info = pathinfo( $new_filename );
        if ( empty( $old_info['filename'] ) || empty( $old_info['extension'] ) ) {
            return array();
        }
        if ( empty( $new_info['filename'] ) || empty( $new_info['extension'] ) ) {
            return array();
        }

        global $wpdb;

        $report          = array();
        $old_base        = $old_info['filename'];
        $old_ext         = strtolower( $old_info['extension'] );
        $new_base        = $new_info['filename'];
        $new_ext         = strtolower( $new_info['extension'] );
        $allowed_post_types = $this->candidate_post_types();
        $writes_allowed  = ! $dry_run && $this->destructive_ops_confirmed();

        $paged   = 1;
        $batch   = 50;
        $max_loops = 1000; // hard ceiling; ~50k posts is enough for any reasonable site.

        do {
            $query = new WP_Query(
                array(
                    'post_type'      => $allowed_post_types,
                    'post_status'    => array( 'publish', 'private', 'draft', 'future', 'pending' ),
                    'posts_per_page' => $batch,
                    'paged'          => $paged,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    's'              => $old_base, // narrow the candidate set; final match is exact via DOM.
                    'suppress_filters' => true,
                )
            );

            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $post_id ) {
                $content = get_post_field( 'post_content', $post_id );
                if ( empty( $content ) || false === strpos( $content, $old_base ) ) {
                    continue;
                }

                list( $rewritten, $hits ) = $this->rewrite_references_in_html(
                    $content,
                    $old_base,
                    $old_ext,
                    $new_base,
                    $new_ext
                );

                if ( empty( $hits ) ) {
                    continue;
                }

                $report = array_merge( $report, $hits );

                if ( $writes_allowed && $rewritten !== $content ) {
                    // Snapshot the post BEFORE we touch post_content.
                    wp_save_post_revision( $post_id );
                    wp_update_post(
                        array(
                            'ID'           => $post_id,
                            'post_content' => $rewritten,
                        )
                    );
                }
            }

            ++$paged;
            --$max_loops;
        } while ( $max_loops > 0 );

        return array_values( array_unique( $report ) );
    }

    /**
     * Convenience wrapper for swapping a fully-qualified URL pair across post_content.
     *
     * Used by merge_duplicate_assets when the duplicate's URL is being replaced wholesale
     * by the original's URL. Same DOM-based + per-post-revision discipline as the
     * filename rewriter — never preg_replace on post_content.
     */
    private function sync_url_references( $old_url, $new_url, $dry_run = false ) {
        if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
            return array();
        }
        $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $old_host  = wp_parse_url( $old_url, PHP_URL_HOST );
        if ( $old_host && $home_host && strtolower( $old_host ) !== strtolower( $home_host ) ) {
            return array();
        }

        $report          = array();
        $writes_allowed  = ! $dry_run && $this->destructive_ops_confirmed();
        $allowed_post_types = $this->candidate_post_types();
        $needle          = wp_basename( wp_parse_url( $old_url, PHP_URL_PATH ) );
        if ( '' === $needle ) {
            return array();
        }

        $paged = 1;
        $batch = 50;
        $max_loops = 1000;

        do {
            $query = new WP_Query(
                array(
                    'post_type'      => $allowed_post_types,
                    'post_status'    => array( 'publish', 'private', 'draft', 'future', 'pending' ),
                    'posts_per_page' => $batch,
                    'paged'          => $paged,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    's'              => $needle,
                    'suppress_filters' => true,
                )
            );

            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $post_id ) {
                $content = get_post_field( 'post_content', $post_id );
                if ( empty( $content ) || false === strpos( $content, $old_url ) ) {
                    continue;
                }

                list( $rewritten, $hits ) = $this->rewrite_url_in_html( $content, $old_url, $new_url );
                if ( empty( $hits ) ) {
                    continue;
                }
                $report = array_merge( $report, $hits );

                if ( $writes_allowed && $rewritten !== $content ) {
                    wp_save_post_revision( $post_id );
                    wp_update_post(
                        array(
                            'ID'           => $post_id,
                            'post_content' => $rewritten,
                        )
                    );
                }
            }

            ++$paged;
            --$max_loops;
        } while ( $max_loops > 0 );

        return array_values( array_unique( $report ) );
    }

    /**
     * Walk an HTML blob and rewrite img/src + a/href filename basenames safely.
     *
     * Only rewrites when the host matches site host (or URL is relative) and the
     * basename matches old → new with the optional -WxH WP thumbnail suffix.
     */
    private function rewrite_references_in_html( $html, $old_base, $old_ext, $new_base, $new_ext ) {
        $hits      = array();
        $processor = new WP_HTML_Tag_Processor( $html );
        $home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

        while ( $processor->next_tag() ) {
            $tag = strtolower( $processor->get_tag() );
            if ( 'img' !== $tag && 'a' !== $tag ) {
                continue;
            }
            $attr = ( 'img' === $tag ) ? 'src' : 'href';
            $url  = $processor->get_attribute( $attr );
            if ( ! is_string( $url ) || '' === $url ) {
                continue;
            }
            $rewritten = $this->maybe_rewrite_url_basename( $url, $old_base, $old_ext, $new_base, $new_ext, $home_host );
            if ( null === $rewritten ) {
                continue;
            }
            $hits[] = $url;
            $processor->set_attribute( $attr, $rewritten );

            // <img> also commonly has srcset — rewrite its basenames too.
            if ( 'img' === $tag ) {
                $srcset = $processor->get_attribute( 'srcset' );
                if ( is_string( $srcset ) && '' !== $srcset ) {
                    $new_srcset = $this->rewrite_srcset_basenames( $srcset, $old_base, $old_ext, $new_base, $new_ext, $home_host, $hits );
                    if ( $new_srcset !== $srcset ) {
                        $processor->set_attribute( 'srcset', $new_srcset );
                    }
                }
            }
        }

        return array( $processor->get_updated_html(), $hits );
    }

    /**
     * Walk an HTML blob and replace a fully-qualified URL with another, host-checked.
     */
    private function rewrite_url_in_html( $html, $old_url, $new_url ) {
        $hits      = array();
        $processor = new WP_HTML_Tag_Processor( $html );

        while ( $processor->next_tag() ) {
            $tag = strtolower( $processor->get_tag() );
            if ( 'img' !== $tag && 'a' !== $tag ) {
                continue;
            }
            $attr = ( 'img' === $tag ) ? 'src' : 'href';
            $url  = $processor->get_attribute( $attr );
            if ( $url === $old_url ) {
                $hits[] = $url;
                $processor->set_attribute( $attr, $new_url );
            }
        }

        return array( $processor->get_updated_html(), $hits );
    }

    /**
     * Rewrite each comma-delimited URL in a srcset attribute when its basename matches.
     */
    private function rewrite_srcset_basenames( $srcset, $old_base, $old_ext, $new_base, $new_ext, $home_host, &$hits ) {
        $parts = preg_split( '/\s*,\s*/', $srcset );
        if ( ! is_array( $parts ) ) {
            return $srcset;
        }
        $out = array();
        foreach ( $parts as $candidate ) {
            $bits = preg_split( '/\s+/', trim( $candidate ), 2 );
            if ( empty( $bits[0] ) ) {
                $out[] = $candidate;
                continue;
            }
            $rewritten_url = $this->maybe_rewrite_url_basename( $bits[0], $old_base, $old_ext, $new_base, $new_ext, $home_host );
            if ( null === $rewritten_url ) {
                $out[] = $candidate;
                continue;
            }
            $hits[] = $bits[0];
            $bits[0] = $rewritten_url;
            $out[]   = trim( implode( ' ', $bits ) );
        }
        return implode( ', ', $out );
    }

    /**
     * Decide whether a single URL's path basename is a same-asset rewrite target.
     *
     * Returns the rewritten URL on a match, NULL on a skip. Match rule:
     * basename === "{old_base}.{old_ext}" or "{old_base}-{W}x{H}.{old_ext}".
     */
    private function maybe_rewrite_url_basename( $url, $old_base, $old_ext, $new_base, $new_ext, $home_host ) {
        $parts = wp_parse_url( $url );
        if ( false === $parts || empty( $parts['path'] ) ) {
            return null;
        }
        // Host check: skip cross-origin URLs entirely.
        if ( ! empty( $parts['host'] ) ) {
            if ( '' === $home_host || strtolower( $parts['host'] ) !== $home_host ) {
                return null;
            }
        }
        $basename = wp_basename( $parts['path'] );
        $pattern  = '/^' . preg_quote( $old_base, '/' ) . '(-\d+x\d+)?\.' . preg_quote( $old_ext, '/' ) . '$/i';
        if ( ! preg_match( $pattern, $basename, $m ) ) {
            return null;
        }
        $suffix       = isset( $m[1] ) ? $m[1] : '';
        $new_basename = $new_base . $suffix . '.' . $new_ext;
        $new_path     = substr( $parts['path'], 0, -strlen( $basename ) ) . $new_basename;

        // Reassemble: scheme + host + path + query + fragment.
        $rebuilt = '';
        if ( ! empty( $parts['scheme'] ) ) {
            $rebuilt .= $parts['scheme'] . '://';
        } elseif ( 0 === strpos( $url, '//' ) ) {
            $rebuilt .= '//';
        }
        if ( ! empty( $parts['host'] ) ) {
            $rebuilt .= $parts['host'];
            if ( ! empty( $parts['port'] ) ) {
                $rebuilt .= ':' . $parts['port'];
            }
        }
        $rebuilt .= $new_path;
        if ( ! empty( $parts['query'] ) ) {
            $rebuilt .= '?' . $parts['query'];
        }
        if ( ! empty( $parts['fragment'] ) ) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt;
    }

    /**
     * Candidate post types for content-reference rewrites: every public type plus
     * the registered private ones that hold body copy. Excludes attachments and revisions.
     */
    private function candidate_post_types() {
        $types = get_post_types( array( 'public' => true ), 'names' );
        unset( $types['attachment'] );
        return array_values( $types );
    }

    /**
     * Walk attachments via WP_Query (NOT the filesystem) looking for ones whose original
     * filename has a matching .webp on disk. Drops the assumption that uploads/ uses the
     * default YYYY/MM tree — flat layouts, custom uploads_use_yearmonth_folders, and CDN
     * offload plugins all break the previous scandir() pass.
     */
    private function sync_webp_from_filesystem( $dry_run = false ) {
        $results = array();
        $paged   = 1;
        $batch   = 100;
        $max_loops = 1000;

        do {
            $query = new WP_Query(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => $batch,
                    'paged'          => $paged,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'meta_key'       => '_timu_original_filename',
                )
            );

            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $attachment_id ) {
                $original_filename = get_post_meta( $attachment_id, '_timu_original_filename', true );
                if ( empty( $original_filename ) ) {
                    continue;
                }
                $current_path = get_attached_file( $attachment_id );
                if ( ! $current_path ) {
                    continue;
                }
                $dir       = trailingslashit( dirname( $current_path ) );
                $webp_name = pathinfo( $original_filename, PATHINFO_FILENAME ) . '.webp';
                if ( ! file_exists( $dir . $webp_name ) ) {
                    continue;
                }

                $variations = $this->sync_content_references( $original_filename, $webp_name, $dry_run );
                if ( ! empty( $variations ) ) {
                    $relative_dir = str_replace( wp_upload_dir()['basedir'], '', untrailingslashit( $dir ) );
                    $results[]    = array(
                        'file' => $original_filename,
                        'webp' => $webp_name,
                        'dir'  => trim( $relative_dir, '/' ),
                        'vars' => $variations,
                    );
                }
            }

            ++$paged;
            --$max_loops;
        } while ( $max_loops > 0 );

        return $results;
    }

    /**
     * Rename an attachment's underlying file with atomic write-to-temp + rename
     * and roll back the surrounding flow on failure.
     *
     * Returns true on success, false on any I/O failure. Callers MUST check the return.
     */
    private function process_image_update( $id, $source_path, $new_name ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $upload_dir   = wp_upload_dir();
        $old_basename = basename( $source_path );
        $new_path     = trailingslashit( $upload_dir['path'] ) . $new_name;

        $this->ensure_backup_dir_protected();

        if ( $source_path === $new_path ) {
            update_post_meta( $id, '_timu_original_path', str_replace( $upload_dir['basedir'], '', $source_path ) );
            update_post_meta( $id, '_timu_original_filename', $old_basename );
            return true;
        }

        // 1. Backup the original (skip if a backup already exists — never overwrite).
        $backup_path = $this->backup_dir . $old_basename;
        if ( ! file_exists( $backup_path ) ) {
            if ( ! @copy( $source_path, $backup_path ) ) {
                return false;
            }
        }

        // 2. Atomic rename via temp file. Write to {dest}.tmp THEN rename onto {dest}.
        // This avoids leaving a half-written file at the destination if the operation
        // is interrupted, and rename() across the same filesystem is atomic on POSIX.
        $temp_path = $new_path . '.tmp';
        if ( ! @copy( $source_path, $temp_path ) ) {
            return false;
        }
        if ( ! @rename( $temp_path, $new_path ) ) {
            @unlink( $temp_path );
            return false;
        }
        if ( ! @unlink( $source_path ) ) {
            // Source unlink failed — destination has the file, source still exists. Clean up dest
            // and bail rather than leave duplicate physical files behind.
            @unlink( $new_path );
            return false;
        }

        if ( ! update_attached_file( $id, $new_path ) ) {
            // Worst case: file moved but attachment record didn't update. Roll the file back.
            @rename( $new_path, $source_path );
            return false;
        }

        update_post_meta( $id, '_timu_original_path', str_replace( $upload_dir['basedir'], '', $source_path ) );
        update_post_meta( $id, '_timu_original_filename', $old_basename );

        $meta = wp_generate_attachment_metadata( $id, $new_path );
        if ( ! is_wp_error( $meta ) && is_array( $meta ) ) {
            wp_update_attachment_metadata( $id, $meta );
        }

        if ( $this->sister_plugin_supports( 'convert_to_webp' ) ) {
            TIMU_WEBP_Support::convert_to_webp( $id );
        }

        return true;
    }

    private function handle_cleanup( $dry_run = true, $limit = 5 ) {
        global $wpdb;

        // Enforce the destructive-ops gate at the front door for any non-dry-run.
        if ( ! $dry_run && ! $this->destructive_ops_confirmed() ) {
            echo '<li>' . esc_html__( 'Refusing to run: enable "Confirm destructive operations" in plugin settings or set the thisismyurl_image_support_confirm_destructive option to true. Dry-run still works.', 'thisismyurl-image-support' ) . '</li>';
            return;
        }

        $limit   = max( 1, min( 50, (int) $limit ) );
        $last_id = ( $dry_run ) ? 0 : (int) get_option( 'timu_ic_last_id', 0 );
        $targets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_name, post_mime_type FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
                'attachment',
                $last_id,
                $limit
            )
        );
        if ( empty( $targets ) ) {
            if ( ! $dry_run ) {
                update_option( 'timu_ic_last_id', 0 );
            }
            echo '<li>' . esc_html__( 'Audit complete.', 'thisismyurl-image-support' ) . '</li>';
            return;
        }

        // Defer term counting + suspend cache invalidation around the batch. These two
        // wrappers keep a 5–50-attachment batch from hammering the cache and forcing
        // an O(N²) term recount on every save.
        wp_defer_term_counting( true );
        wp_suspend_cache_invalidation( true );

        try {
            $seen_names = array();
            foreach ( $targets as $t ) {
                if ( ! in_array( $t->post_mime_type, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
                    continue;
                }
                $old_path = get_attached_file( $t->ID );
                if ( ! $old_path || ! file_exists( $old_path ) ) {
                    continue;
                }
                $old_basename = basename( $old_path );
                $new_name     = $this->sanitize_filename( $old_basename, $t->ID );
                if ( false === $new_name ) {
                    echo '<li>' . sprintf(
                        /* translators: 1: attachment ID, 2: filename */
                        esc_html__( 'Skipped #%1$d: rejected source filename %2$s', 'thisismyurl-image-support' ),
                        absint( $t->ID ),
                        '<code>' . esc_html( $old_basename ) . '</code>'
                    ) . '</li>';
                    continue;
                }
                $clean_slug = pathinfo( $new_name, PATHINFO_FILENAME );
                if ( isset( $seen_names[ $clean_slug ] ) ) {
                    $this->merge_duplicate_assets( $t->ID, $seen_names[ $clean_slug ], $dry_run );
                    continue;
                }
                $seen_names[ $clean_slug ] = $t->ID;

                $variations = $this->sync_content_references( $old_basename, $new_name, $dry_run );

                if ( ! $dry_run ) {
                    $this->backup_attachment_record( $t->ID );
                    $ok = $this->process_image_update( $t->ID, $old_path, $new_name );
                    if ( ! $ok ) {
                        echo '<li>' . sprintf(
                            /* translators: %d attachment ID */
                            esc_html__( 'I/O failure on #%d — file not renamed, attachment unchanged. See server error log.', 'thisismyurl-image-support' ),
                            absint( $t->ID )
                        ) . '</li>';
                        continue;
                    }
                    update_option( 'timu_ic_last_id', $t->ID );
                    echo '<li>' . sprintf(
                        /* translators: 1: attachment ID, 2: new filename */
                        esc_html__( 'Updated #%1$d: %2$s', 'thisismyurl-image-support' ),
                        absint( $t->ID ),
                        '<strong>' . esc_html( $new_name ) . '</strong>'
                    );
                } else {
                    echo '<li>' . sprintf(
                        /* translators: 1: attachment ID, 2: old filename, 3: new filename */
                        esc_html__( 'Preview #%1$d: Would rename %2$s to %3$s', 'thisismyurl-image-support' ),
                        absint( $t->ID ),
                        '<strong>' . esc_html( $old_basename ) . '</strong>',
                        '<strong>' . esc_html( $new_name ) . '</strong>'
                    );
                }

                if ( ! empty( $variations ) ) {
                    echo '<ul style="margin-left:20px; color:#666; font-size:0.85em;">';
                    foreach ( $variations as $v ) {
                        echo '<li>' . esc_html__( 'Fixed:', 'thisismyurl-image-support' ) . ' <code>' . esc_html( $v ) . '</code></li>';
                    }
                    echo '</ul>';
                }
                echo '</li>';
            }
        } finally {
            wp_suspend_cache_invalidation( false );
            wp_defer_term_counting( false );
        }
    }

    /**
     * Restore a previously-renamed asset's original file from the backup dir.
     *
     * Now strictly POST via admin-post.php. The previous GET-based handler made every
     * authenticated admin click a state-changing action vulnerable to a single
     * crafted <img src="…?restore=…"> on any admin page. POST + nonce + capability
     * brings this in line with WP REST mutation discipline.
     *
     * Verifies byte-length match between source and copied destination BEFORE deleting
     * the backup. A short copy from a full disk would have happily unlinked the only
     * surviving copy of the original.
     */
    public function handle_restore_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'thisismyurl-image-support' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'thisismyurl_image_support_restore' );

        $raw  = isset( $_POST['restore'] ) ? sanitize_text_field( wp_unslash( $_POST['restore'] ) ) : '';
        $file = basename( $raw );
        if ( '' === $file || $file !== $raw ) {
            wp_die( esc_html__( 'Invalid backup filename.', 'thisismyurl-image-support' ), '', array( 'response' => 400 ) );
        }

        global $wpdb;
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                '_timu_original_filename',
                $file
            )
        );

        if ( ! $id ) {
            wp_safe_redirect( admin_url( 'tools.php?page=thisismyurl-image-support&restored=0' ) );
            exit;
        }

        $backup_path = $this->backup_dir . $file;
        if ( ! file_exists( $backup_path ) ) {
            wp_safe_redirect( admin_url( 'tools.php?page=thisismyurl-image-support&restored=0' ) );
            exit;
        }

        $upload_dir       = wp_upload_dir();
        $original_relpath = get_post_meta( $id, '_timu_original_path', true );
        if ( empty( $original_relpath ) ) {
            wp_safe_redirect( admin_url( 'tools.php?page=thisismyurl-image-support&restored=0' ) );
            exit;
        }
        $target = $upload_dir['basedir'] . $original_relpath;

        // Refuse to restore outside of the uploads tree.
        $real_base   = realpath( $upload_dir['basedir'] );
        $target_dir  = realpath( dirname( $target ) );
        if ( false === $real_base || false === $target_dir || 0 !== strpos( $target_dir, $real_base ) ) {
            wp_die( esc_html__( 'Restore target is outside the uploads directory; refusing.', 'thisismyurl-image-support' ), '', array( 'response' => 400 ) );
        }

        $source_size = @filesize( $backup_path );
        if ( false === $source_size ) {
            wp_die( esc_html__( 'Cannot read backup file size.', 'thisismyurl-image-support' ), '', array( 'response' => 500 ) );
        }
        if ( ! @copy( $backup_path, $target ) ) {
            wp_die( esc_html__( 'Restore copy failed.', 'thisismyurl-image-support' ), '', array( 'response' => 500 ) );
        }
        $target_size = @filesize( $target );
        if ( false === $target_size || $target_size !== $source_size ) {
            // Short copy. Do not unlink the backup.
            wp_die( esc_html__( 'Restore copy did not match source byte length; backup retained.', 'thisismyurl-image-support' ), '', array( 'response' => 500 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        update_attached_file( $id, $target );
        $meta = wp_generate_attachment_metadata( $id, $target );
        if ( ! is_wp_error( $meta ) && is_array( $meta ) ) {
            wp_update_attachment_metadata( $id, $meta );
        }
        @unlink( $backup_path );

        wp_safe_redirect( admin_url( 'tools.php?page=thisismyurl-image-support&restored=1' ) );
        exit;
    }

    /**
     * Hide sister-plugin submenu entries so the Tools sidebar doesn't carry three
     * near-duplicate links. Filterable — operators can pass an empty array to disable.
     */
    public function cleanup_menus() {
        $defaults = array( 'thisismyurl-webp-support', 'thisismyurl-heic-support' );
        $slugs    = apply_filters( 'thisismyurl_image_support_hide_submenus', $defaults );
        if ( ! is_array( $slugs ) || empty( $slugs ) ) {
            return;
        }
        foreach ( $slugs as $s ) {
            remove_submenu_page( 'tools.php', $s );
        }
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'thisismyurl-image-support' ) );
        }

        // Settings POST: toggle the destructive-ops confirmation flag.
        if ( isset( $_POST['thisismyurl_image_support_settings_nonce'] )
            && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['thisismyurl_image_support_settings_nonce'] ) ), 'thisismyurl_image_support_settings' ) ) {
            update_option( 'thisismyurl_image_support_confirm_destructive', ! empty( $_POST['confirm_destructive'] ) );
        }

        $is_post = ! empty( $_POST ) && ( isset( $_POST['dry_run'] ) || isset( $_POST['run_cleanup'] ) );
        if ( $is_post ) {
            check_admin_referer( 'thisismyurl_image_support_action', 'thisismyurl_image_support_nonce' );
        }

        // Batch is capped at 50 to match handle_cleanup()'s internal clamp; the previous 1000 was theatre.
        $user_batch = $is_post && isset( $_POST['batch_limit'] )
            ? max( 1, min( 50, (int) $_POST['batch_limit'] ) )
            : 5;

        $confirmed = (bool) get_option( 'thisismyurl_image_support_confirm_destructive', false );
        ?>
        <div class="wrap">
            <h1>Image Support <span style="font-size: 0.5em; color: #646970;">by thisismyurl.com</span></h1>

            <div class="notice notice-warning" style="padding:12px 16px;">
                <p><strong><?php esc_html_e( 'This plugin renames files, merges duplicate attachments, and rewrites post_content. Run a dry-run first. Backups land in /wp-content/uploads/timu-image-backups/. The destructive-ops switch is OFF by default.', 'thisismyurl-image-support' ); ?></strong></p>
            </div>

            <div class="postbox">
                <h2 class="hndle"><span><?php esc_html_e( 'Settings', 'thisismyurl-image-support' ); ?></span></h2>
                <div class="inside">
                    <form method="post">
                        <?php wp_nonce_field( 'thisismyurl_image_support_settings', 'thisismyurl_image_support_settings_nonce' ); ?>
                        <p>
                            <label>
                                <input type="checkbox" name="confirm_destructive" value="1" <?php checked( $confirmed ); ?> />
                                <?php esc_html_e( 'I understand this plugin renames files, deletes duplicates, and rewrites post_content. Allow destructive operations.', 'thisismyurl-image-support' ); ?>
                            </label>
                        </p>
                        <p><input type="submit" class="button" value="<?php esc_attr_e( 'Save settings', 'thisismyurl-image-support' ); ?>"></p>
                    </form>
                </div>
            </div>

            <div class="postbox">
                <h2 class="hndle"><span><?php esc_html_e( 'Image Optimization & Deep Sync', 'thisismyurl-image-support' ); ?></span></h2>
                <div class="inside">
                    <form method="post">
                        <?php wp_nonce_field( 'thisismyurl_image_support_action', 'thisismyurl_image_support_nonce' ); ?>
                        <p>
                            <label for="batch_limit"><strong><?php esc_html_e( 'Images per Batch:', 'thisismyurl-image-support' ); ?></strong></label>
                            <input type="number" id="batch_limit" name="batch_limit" value="<?php echo esc_attr( $user_batch ); ?>" min="1" max="50" style="width: 80px;">
                            <span class="description"><?php esc_html_e( 'Max 50 per run.', 'thisismyurl-image-support' ); ?></span>
                        </p>
                        <div style="display: flex; gap: 10px;">
                            <input type="submit" name="dry_run" class="button" value="<?php esc_attr_e( 'Preview Changes (Dry Run)', 'thisismyurl-image-support' ); ?>">
                            <input type="submit" name="run_cleanup" class="button button-primary" value="<?php esc_attr_e( 'Update & Sync WebP', 'thisismyurl-image-support' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Update images and sync WebP? This rewrites post_content and renames files.', 'thisismyurl-image-support' ) ); ?>');">
                        </div>
                    </form>
                    <?php if ( $is_post ) : ?>
                        <ul style="margin-top:20px; max-height:500px; overflow:auto; background:#f6f7f7; padding:15px; border:1px solid #dcdcde;">
                            <?php
                            $is_dry_run = isset( $_POST['dry_run'] );
                            $webp_hits  = $this->sync_webp_from_filesystem( $is_dry_run );
                            if ( ! empty( $webp_hits ) ) {
                                echo '<li><strong>' . esc_html__( 'Filesystem WebP Discovery:', 'thisismyurl-image-support' ) . '</strong></li><ul style="margin-left:20px;">';
                                foreach ( $webp_hits as $hit ) {
                                    $label = $is_dry_run
                                        ? esc_html__( 'Found', 'thisismyurl-image-support' )
                                        : esc_html__( 'Replaced', 'thisismyurl-image-support' );
                                    echo '<li>' . $label . ' <code>' . esc_html( $hit['file'] ) . '</code> ' . esc_html__( 'in', 'thisismyurl-image-support' ) . ' ' . esc_html( $hit['dir'] ) . '</li>';
                                }
                                echo '</ul><hr>';
                            }
                            $this->handle_cleanup( $is_dry_run, $user_batch );
                            ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function add_plugin_action_links( $links ) {
        return array_merge(
            array( '<a href="' . esc_url( admin_url( 'tools.php?page=thisismyurl-image-support' ) ) . '">' . esc_html__( 'Settings', 'thisismyurl-image-support' ) . '</a>' ),
            $links
        );
    }
}

$GLOBALS['timu_ic_instance'] = new TIMU_IC();

// Async WebP generation handler. Bound at file scope so wp-cron can invoke it
// without depending on whether the admin page has been hit this request.
add_action(
    'thisismyurl_image_support_generate_webp',
    function ( $source_path ) {
        if ( ! empty( $GLOBALS['timu_ic_instance'] ) && is_string( $source_path ) ) {
            $GLOBALS['timu_ic_instance']->generate_webp_on_the_fly( $source_path );
        }
    },
    10,
    1
);

require_once plugin_dir_path( __FILE__ ) . 'github-updater.php';

timu_boot_github_release_updater(
    array(
        'plugin_file' => __FILE__,
        'slug'        => 'thisismyurl-image-support',
        'repo'        => 'thisismyurl/thisismyurl-image-support',
    )
);