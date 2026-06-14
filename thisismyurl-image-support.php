<?php
/**
 * Plugin Name: Image Support by Christopher Ross
 * Plugin URI:  https://thisismyurl.com/thisismyurl-image-support/
 * Description: Image filename cleanup, duplicate merging, WebP discovery, photo-credit attribution, and alt-text accessibility fallback. The cleanup/merge features are destructive and require opt-in via the "Confirm destructive operations" option before any rename, merge, or post_content rewrite runs; the photo-credit and alt-fallback features are benign and never touch files or post content.
 * Version:     1.6165.0949
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author:      Christopher Ross
 * Author URI:  https://thisismyurl.com/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: thisismyurl-image-support
 * Domain Path: /languages
 * Update URI:  https://github.com/thisismyurl/thisismyurl-image-support
 * Network:     false
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version, filesystem path, and URL. Read by the feature modules below
 * for asset enqueuing and cache-busting. Kept in sync with the `Version:`
 * header above by the release process.
 */
define( 'TIMU_IMAGE_SUPPORT_VERSION', '1.6165.0949' );
define( 'TIMU_IMAGE_SUPPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'TIMU_IMAGE_SUPPORT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Absolute path to this main plugin file.
 *
 * The class engine (class-admin.php) reads this for plugin_basename() when it
 * registers the plugin_action_links filter, so it must point at the bootstrap
 * file rather than at any include.
 */
define( 'TIMU_IC_FILE', __FILE__ );

/**
 * Feature modules. Each is self-contained — registers its own hooks on load.
 *
 *   - photo-credits.php        Attachment credit meta, render filter, IPTC
 *                              pre-fill, bundled CSS, and the WP-CLI commands.
 *   - photo-credits-admin.php  Attachment-edit meta box + block-editor panel.
 *   - photo-credit-schema.php  schema.org/ImageObject JSON-LD on attachment pages.
 *   - image-alt-fallback.php   Three-tier alt-text fallback on render.
 *
 * These are benign: they never rename files, merge attachments, or rewrite
 * post_content. The destructive cleanup pipeline lives in the TIMU_IC class
 * below and stays gated behind its own opt-in option.
 */
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/photo-credits.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/photo-credits-admin.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/photo-credit-schema.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/image-alt-fallback.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/abilities.php';

/**
 * Class engine. These eight classes own the tabbed admin, settings storage,
 * filesystem operations, audit reads, REST routes, the scheduler, and the
 * upload-time optimize pipeline. They reference the coordinator surface on
 * TIMU_IC below (constants + static helpers), so TIMU_IC must be declared
 * before their bootstraps fire — but the class files only declare classes on
 * require, so loading them here (ahead of TIMU_IC) is safe. Their init()
 * calls happen after TIMU_IC is instantiated, at the bottom of this file.
 *
 * Dependency order: leaf utilities (sanitizer, options, content-sync) first,
 * then audit, then file-ops (uses sanitizer + content-sync + audit), then
 * scheduler / rest / admin which orchestrate the rest.
 */
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/interface-compat.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/class-backup-adapter.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/class-sanitizer.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/class-options.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/class-content-sync.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/class-audit.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/class-file-ops.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/class-scheduler.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/class-rest.php';
require_once TIMU_IMAGE_SUPPORT_DIR . 'includes/class-admin.php';

class TIMU_IC {

    /**
     * Settings array option key (class-engine settings UI).
     */
    const OPTION_KEY = 'thisismyurl_image_support_options';

    /**
     * register_setting() group slug for the settings form.
     */
    const SETTINGS_GROUP = 'thisismyurl_image_support';

    /**
     * Option holding the last-detected image-engine environment snapshot.
     */
    const ENV_OPTION_KEY = 'thisismyurl_image_support_env';

    /**
     * Attachment meta: relative path of the originally-uploaded file.
     * Value preserved from the monolith so existing installs keep their data.
     */
    const ORIGINAL_PATH_KEY = '_timu_original_path';

    /**
     * Attachment meta: basename of the originally-uploaded file.
     * Value preserved from the monolith so existing installs keep their data.
     */
    const ORIGINAL_FILENAME_KEY = '_timu_original_filename';

    /**
     * Attachment meta (multi): legacy relative paths a merged duplicate used to
     * live at, so old inline URLs can still be resolved after a dedupe merge.
     */
    const LEGACY_PATH_KEY = '_timu_legacy_path';

    /**
     * Attachment meta: unix timestamp the cleanup pipeline last processed this image.
     */
    const PROCESSED_AT_KEY = '_timu_processed_at';

    /**
     * Attachment meta: bytes saved by the most recent optimize pass.
     */
    const SAVINGS_META_KEY = '_timu_bytes_saved';

    /**
     * Attachment meta: md5 of the processed file, used for duplicate detection.
     */
    const HASH_META_KEY = '_timu_file_hash';

    /**
     * Nonce action shared by the admin AJAX endpoints.
     */
    const AJAX_NONCE_ACTION = 'timu_ic_ajax';

    /**
     * Cron hook for the recurring auto-optimize batch.
     */
    const CRON_HOOK = 'timu_ic_auto_optimize';

    /**
     * Cron hook for the daily auto-optimize cap reset.
     */
    const CRON_DAILY_RESET_HOOK = 'timu_ic_daily_reset';

    /**
     * Transient key that throttles the admin-access auto-optimize tick.
     */
    const ADMIN_TICK_LOCK = 'timu_ic_admin_tick_lock';

    /**
     * Option name holding the cumulative run-stats counter array.
     */
    const STATS_OPTION_KEY = 'thisismyurl_image_support_stats';

    private $backup_dir;

    /**
     * Map every supported source extension to its canonical MIME type.
     *
     * The settings UI iterates this to render the per-extension checkboxes, and
     * the options sanitiser uses its keys as the extension allow-list.
     *
     * @return array<string,string> Extension (lowercase, no dot) => MIME type.
     */
    public static function get_extension_mime_map() {
        $map = array(
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
        );

        /**
         * Filter the extension-to-MIME map of source formats this plugin handles.
         *
         * @since 1.6159
         *
         * @param array<string,string> $map Extension => MIME type.
         */
        $map = apply_filters( 'thisismyurl_image_support_extension_mime_map', $map );

        return is_array( $map ) ? $map : array( 'jpg' => 'image/jpeg' );
    }

    /**
     * Resolve the MIME types currently enabled in settings into a unique list.
     *
     * Used by the cleanup/upload pipeline as the post_mime_type filter when
     * selecting attachments to process.
     *
     * @return array<int,string> Unique enabled source MIME types.
     */
    public static function get_enabled_source_mimes() {
        $map      = self::get_extension_mime_map();
        $options  = get_option( self::OPTION_KEY, array() );
        $enabled  = isset( $options['enabled_extensions'] ) && is_array( $options['enabled_extensions'] )
            ? $options['enabled_extensions']
            : array_keys( $map );

        $mimes = array();
        foreach ( $enabled as $extension ) {
            if ( isset( $map[ $extension ] ) ) {
                $mimes[] = $map[ $extension ];
            }
        }

        $mimes = array_values( array_unique( $mimes ) );

        return empty( $mimes ) ? array( 'image/jpeg' ) : $mimes;
    }

    /**
     * Whether a usable server-side image engine (Imagick or GD) is available.
     *
     * @return bool
     */
    public static function has_supported_image_engine() {
        if ( class_exists( 'Imagick' ) ) {
            return true;
        }

        return extension_loaded( 'gd' ) && function_exists( 'imagewebp' );
    }

    /**
     * Increment a named cumulative run-stat counter.
     *
     * @param string $key   Stat name (e.g. "renamed", "processed").
     * @param int    $delta Amount to add. Defaults to 1.
     *
     * @return void
     */
    public static function increment_stat( $key, $delta = 1 ) {
        $key   = sanitize_key( (string) $key );
        $stats = get_option( self::STATS_OPTION_KEY, array() );
        if ( ! is_array( $stats ) ) {
            $stats = array();
        }

        $stats[ $key ] = ( isset( $stats[ $key ] ) ? (int) $stats[ $key ] : 0 ) + (int) $delta;

        update_option( self::STATS_OPTION_KEY, $stats, false );
    }

    /**
     * Read a named cumulative run-stat counter.
     *
     * @param string $key Stat name.
     *
     * @return int Counter value, or 0 when unset.
     */
    public static function get_stat( $key ) {
        $key   = sanitize_key( (string) $key );
        $stats = get_option( self::STATS_OPTION_KEY, array() );

        return is_array( $stats ) && isset( $stats[ $key ] ) ? (int) $stats[ $key ] : 0;
    }

    /**
     * Build a UTM-tagged outbound link back to thisismyurl.com.
     *
     * @param string $url      Destination URL.
     * @param string $campaign Campaign slug for the utm_campaign parameter.
     *
     * @return string
     */
    public static function get_thisismyurl_link( $url, $campaign ) {
        return add_query_arg(
            array(
                'utm_source'   => 'thisismyurl-image-support',
                'utm_medium'   => 'plugin',
                'utm_campaign' => sanitize_key( (string) $campaign ),
            ),
            esc_url_raw( (string) $url )
        );
    }

    public function __construct() {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        // The admin menu, settings, plugin-row links, and tabbed UI are now
        // owned by TIMU_IC_Admin (the class engine), bootstrapped at the bottom
        // of this file. This instance keeps only the runtime pieces the class
        // engine does not provide: the cleanup batch runner (driven by abilities
        // + the image-support CLI), the image-404 -> WebP redirect, the optional
        // on-render WebP swap, and the legacy restore admin-post handler.
        add_action( 'admin_menu', [ $this, 'cleanup_menus' ] );
        // Restore now flows through admin-post.php (POST), not admin_init (GET). See handle_restore_request().
        add_action( 'admin_post_thisismyurl_image_support_restore', [ $this, 'handle_restore_request' ] );
        add_action( 'template_redirect', [ $this, 'handle_image_404_redirects' ] );

        // The on-render WebP swap is opt-in. Synchronous GD encoding inside the_content is a footgun
        // on cold caches â€” it stalls TTFB on the first hit. Operators who need it can enable via filter
        // and supply async pre-generation; default OFF.
        if ( apply_filters( 'thisismyurl_image_support_enable_dynamic_webp', false ) ) {
            add_filter( 'the_content', [ $this, 'dynamic_webp_replacement' ] );
        }

        $upload_dir       = wp_upload_dir();
        $this->backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'timu-image-backups/';

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-timu-image-support-cli.php';
            \WP_CLI::add_command( 'image-support', 'TIMU_Image_Support_CLI' );
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'thisismyurl-image-support', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /**
     * Lazily initialise and return the WP_Filesystem abstraction.
     *
     * Writing text files through WP_Filesystem (rather than raw file_put_contents)
     * keeps the plugin working on hosts where the filesystem method is FTP/SSH
     * rather than direct — and matches uninstall.php, which already uses it.
     *
     * @return WP_Filesystem_Base|null The filesystem object, or null when it could not be initialised.
     */
    private function filesystem() {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        return $wp_filesystem instanceof WP_Filesystem_Base ? $wp_filesystem : null;
    }

    /**
     * Drop hardening files into the backup directory so it is never directory-listable
     * and never serves anything by default. Idempotent â€” safe to call repeatedly.
     */
    public function ensure_backup_dir_protected() {
        if ( ! is_dir( $this->backup_dir ) ) {
            wp_mkdir_p( $this->backup_dir );
        }
        $filesystem = $this->filesystem();
        if ( null === $filesystem ) {
            return;
        }
        $files = array(
            '.htaccess'   => "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
            'index.html'  => "<!-- silence is golden -->\n",
            'web.config'  => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n",
        );
        foreach ( $files as $name => $body ) {
            $path = $this->backup_dir . $name;
            if ( ! $filesystem->exists( $path ) ) {
                $filesystem->put_contents( $path, $body, FS_CHMOD_FILE );
            }
        }
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
     * returns false for THIS render â€” we never block a render thread on GD encoding.
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

        // Fallback: GD. Refuse if GD is not loaded â€” never fatal-error a request.
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
     * Returns false on any validation failure â€” callers MUST check the return value.
     */
    private function sanitize_filename( $filename, $post_id = 0 ) {
        if ( ! is_string( $filename ) || '' === $filename ) {
            return false;
        }
        // Reject NULs and the RTL override character outright â€” both have been used in
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

        $sanitized = trim( (string) $name, '-' ) . '.' . $ext;

        /**
         * Filter the sanitized filename produced for a renamed attachment.
         *
         * This resolver sits at the single point every rename slug flows through,
         * so a developer can adjust the slug rule (a custom transliteration, a
         * site-specific prefix, a different fallback) for every caller with one
         * filter and no duplication. Returning a value that fails the plugin's own
         * validation downstream is the developer's responsibility; the value is
         * used verbatim. Returning false short-circuits the rename for this
         * attachment exactly as a native validation failure would.
         *
         * @since 1.6144
         *
         * @param string|false $sanitized Resolved filename ("slug.ext"), or false on validation failure.
         * @param string       $filename  Original source filename passed in.
         * @param int          $post_id   Attachment ID being processed.
         */
        return apply_filters( 'thisismyurl_image_support_sanitized_filename', $sanitized, $filename, $post_id );
    }

    /**
     * Master gate for the plugin's cleanup behaviour.
     *
     * Returning false from the filter disables the rename/relink/merge pipeline
     * (and the CLI batch that funnels through it) without deactivating the
     * plugin, so an operator can switch it off per environment or per context.
     * Recovery — restore from backup — deliberately does NOT consult this gate;
     * disabling cleanup must never disable the ability to roll back.
     *
     * @return bool
     */
    public function is_enabled() {
        /**
         * Filter whether Image Support's cleanup pipeline is enabled.
         *
         * @since 1.6144
         *
         * @param bool $enabled Whether cleanup is enabled. Default true.
         */
        return (bool) apply_filters( 'thisismyurl_image_support_enabled', true );
    }

    /**
     * Per-attachment decision gate: should this image be renamed/relinked?
     *
     * Distinct from the master gate — this fires once per attachment with the
     * source path in hand, so a developer can exclude individual attachments by
     * ID, path, or any metadata they look up. Returning false skips the image.
     *
     * @param int    $attachment_id Attachment being considered.
     * @param string $source_path   Absolute current file path.
     *
     * @return bool
     */
    private function should_process_attachment( $attachment_id, $source_path ) {
        /**
         * Filter whether a single attachment is processed by the cleanup batch.
         *
         * @since 1.6144
         *
         * @param bool   $should        Whether to process. Default true.
         * @param int    $attachment_id Attachment being considered.
         * @param string $source_path   Absolute current file path.
         */
        return (bool) apply_filters( 'thisismyurl_image_support_should_process', true, $attachment_id, $source_path );
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
     * Public read-only accessor for the destructive-ops confirmation state.
     *
     * Lets scripted callers (WP-CLI) check the opt-in without flipping it, so a
     * non-dry-run command can refuse cleanly rather than silently no-op.
     *
     * @return bool
     */
    public function is_destructive_confirmed() {
        return $this->destructive_ops_confirmed();
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
        $filesystem = $this->filesystem();
        if ( null === $filesystem ) {
            return false;
        }
        $path = $manifest_dir . 'attachment-' . absint( $attachment_id ) . '-' . time() . '.json';
        return (bool) $filesystem->put_contents( $path, wp_json_encode( $payload, JSON_PRETTY_PRINT ), FS_CHMOD_FILE );
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
     * basename (with optional -WxH thumbnail suffix) matches old â†’ new exactly.
     *
     * Bounded WP_Query batch (posts_per_page=50, paged) over post_type=any/post_status=any
     * minus revisions. Snapshots each affected post via wp_save_post_revision() BEFORE update.
     *
     * @param string $old_filename Source basename, e.g. "old-image.jpg".
     * @param string $new_filename Target basename, e.g. "old-image.webp".
     * @param bool   $dry_run      When true, report only â€” no writes.
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
        $allowed_statuses   = $this->relink_post_statuses();
        $writes_allowed  = ! $dry_run && $this->destructive_ops_confirmed();

        $paged   = 1;
        $batch   = 50;
        $max_loops = 1000; // hard ceiling; ~50k posts is enough for any reasonable site.

        do {
            $query = new WP_Query(
                array(
                    'post_type'      => $allowed_post_types,
                    'post_status'    => $allowed_statuses,
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

        $report = array_values( array_unique( $report ) );

        /**
         * Fires after a filename's references are rewritten across post_content.
         *
         * On a dry run the URLs are reported without being written; the
         * `$writes_allowed` flag distinguishes the two so a listener can log a
         * preview separately from an applied change.
         *
         * @since 1.6144
         *
         * @param string $old_filename   Source basename.
         * @param string $new_filename   Target basename.
         * @param array  $report         Unique source URLs that matched.
         * @param bool   $writes_allowed Whether changes were actually written.
         */
        do_action( 'thisismyurl_image_support_content_relinked', $old_filename, $new_filename, $report, $writes_allowed );

        return $report;
    }

    /**
     * Convenience wrapper for swapping a fully-qualified URL pair across post_content.
     *
     * Used by merge_duplicate_assets when the duplicate's URL is being replaced wholesale
     * by the original's URL. Same DOM-based + per-post-revision discipline as the
     * filename rewriter â€” never preg_replace on post_content.
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
        $allowed_statuses   = $this->relink_post_statuses();
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
                    'post_status'    => $allowed_statuses,
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
     * basename matches old â†’ new with the optional -WxH WP thumbnail suffix.
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

            // <img> also commonly has srcset â€” rewrite its basenames too.
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
     *
     * This is the single resolver for the relink scope. Both the filename rewriter
     * and the URL rewriter read it, so a developer can widen or narrow the set of
     * post types whose `post_content` is searched and rewritten with one filter.
     *
     * @return string[]
     */
    private function candidate_post_types() {
        $types = get_post_types( array( 'public' => true ), 'names' );
        unset( $types['attachment'] );

        /**
         * Filter the post types whose content is searched and rewritten on relink.
         *
         * @since 1.6144
         *
         * @param string[] $types Post-type slugs. Defaults to public types minus attachments.
         */
        $types = apply_filters( 'thisismyurl_image_support_relink_post_types', array_values( $types ) );

        return is_array( $types ) ? array_values( $types ) : array();
    }

    /**
     * Post statuses the relink pass searches when rewriting content references.
     *
     * Extracted into a resolver because the same status list was previously
     * hard-coded inline in both rewriters; one filter now covers both.
     *
     * @return string[]
     */
    private function relink_post_statuses() {
        $statuses = array( 'publish', 'private', 'draft', 'future', 'pending' );

        /**
         * Filter the post statuses included in the relink query.
         *
         * @since 1.6144
         *
         * @param string[] $statuses Post statuses searched for rewritable references.
         */
        $statuses = apply_filters( 'thisismyurl_image_support_relink_post_statuses', $statuses );

        return is_array( $statuses ) ? array_values( $statuses ) : array( 'publish' );
    }

    /**
     * MIME types the cleanup batch is willing to rename.
     *
     * The hardening list that decides which attachments the pipeline will touch.
     * A developer can drop a format (skip GIFs, say) or add one their build can
     * encode without editing the loop. Returned values are matched against
     * `post_mime_type` in handle_cleanup().
     *
     * @return string[]
     */
    private function processable_mime_types() {
        $mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

        /**
         * Filter the attachment MIME types eligible for rename/relink.
         *
         * @since 1.6144
         *
         * @param string[] $mimes Allowed source MIME types.
         */
        $mimes = apply_filters( 'thisismyurl_image_support_processable_mime_types', $mimes );

        return is_array( $mimes ) ? array_values( $mimes ) : array( 'image/jpeg' );
    }

    /**
     * Walk attachments via WP_Query (NOT the filesystem) looking for ones whose original
     * filename has a matching .webp on disk. Drops the assumption that uploads/ uses the
     * default YYYY/MM tree â€” flat layouts, custom uploads_use_yearmonth_folders, and CDN
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
     * Rename an attachment's underlying file and bracket the operation with its
     * lifecycle hooks.
     *
     * The file work lives in do_process_image_update(); this orchestrator owns the
     * extension points so before/after/sanitized/failed fire from exactly one place
     * each, while every error return inside the worker is preserved verbatim.
     *
     * @param int    $id          Attachment ID.
     * @param string $source_path Absolute current file path.
     * @param string $new_name    Sanitized target basename.
     *
     * @return bool True on success, false on any I/O failure.
     */
    private function process_image_update( $id, $source_path, $new_name ) {
        $old_basename = basename( $source_path );

        /**
         * Fires immediately before an attachment file is renamed on disk.
         *
         * @since 1.6144
         *
         * @param int    $id           Attachment being renamed.
         * @param string $source_path  Absolute current file path.
         * @param string $new_name     Sanitized target basename.
         * @param string $old_basename Current basename.
         */
        do_action( 'thisismyurl_image_support_before_rename', $id, $source_path, $new_name, $old_basename );

        $new_path = '';
        $ok       = $this->do_process_image_update( $id, $source_path, $new_name, $new_path );

        if ( $ok ) {
            /**
             * Fires after an attachment file is successfully renamed.
             *
             * @since 1.6144
             *
             * @param int    $id           Attachment that was renamed.
             * @param string $old_basename Previous basename.
             * @param string $new_name     New basename now on disk.
             * @param string $new_path     Absolute path of the renamed file.
             */
            do_action( 'thisismyurl_image_support_filename_sanitized', $id, $old_basename, $new_name, $new_path );
        } else {
            /**
             * Fires when an attachment rename fails on an I/O error.
             *
             * @since 1.6144
             *
             * @param int    $id           Attachment that failed to rename.
             * @param string $source_path  Absolute source path the rename started from.
             * @param string $new_name     Target basename that was attempted.
             */
            do_action( 'thisismyurl_image_support_rename_failed', $id, $source_path, $new_name );
        }

        /**
         * Fires after a rename attempt, on success or failure.
         *
         * @since 1.6144
         *
         * @param int    $id          Attachment that was processed.
         * @param bool   $ok          Whether the rename succeeded.
         * @param string $source_path Absolute source path the rename started from.
         * @param string $new_name    Target basename that was attempted.
         */
        do_action( 'thisismyurl_image_support_after_rename', $id, $ok, $source_path, $new_name );

        return $ok;
    }

    /**
     * Rename an attachment's underlying file with atomic write-to-temp + rename
     * and roll back the surrounding flow on failure.
     *
     * @param int    $id          Attachment ID.
     * @param string $source_path Absolute current file path.
     * @param string $new_name    Sanitized target basename.
     * @param string $new_path    Populated with the destination absolute path on success.
     *
     * @return bool True on success, false on any I/O failure. Callers MUST check the return.
     */
    private function do_process_image_update( $id, $source_path, $new_name, &$new_path ) {
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

        // 1. Backup the original (skip if a backup already exists â€” never overwrite).
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
            // Source unlink failed â€” destination has the file, source still exists. Clean up dest
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

        // Master gate. Returning false from the filter disables the cleanup
        // pipeline (rename + relink + merge) without deactivating the plugin.
        // The CLI batch honours the same filter at its own entry point.
        if ( ! $this->is_enabled() ) {
            echo '<li>' . esc_html__( 'Image Support is disabled by the thisismyurl_image_support_enabled filter.', 'thisismyurl-image-support' ) . '</li>';
            return;
        }

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
        // wrappers keep a 5â€“50-attachment batch from hammering the cache and forcing
        // an O(NÂ²) term recount on every save.
        wp_defer_term_counting( true );
        wp_suspend_cache_invalidation( true );

        try {
            $seen_names      = array();
            $processable_mimes = $this->processable_mime_types();
            foreach ( $targets as $t ) {
                if ( ! in_array( $t->post_mime_type, $processable_mimes, true ) ) {
                    continue;
                }
                $old_path = get_attached_file( $t->ID );
                if ( ! $old_path || ! file_exists( $old_path ) ) {
                    continue;
                }
                if ( ! $this->should_process_attachment( (int) $t->ID, $old_path ) ) {
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
                            esc_html__( 'I/O failure on #%d â€” file not renamed, attachment unchanged. See server error log.', 'thisismyurl-image-support' ),
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
     * Headless cleanup batch for scripted callers (WP-CLI).
     *
     * Mirrors handle_cleanup()'s logic — same gate, same per-attachment workers,
     * same destructive-ops discipline — but returns a structured result instead
     * of echoing admin HTML. The same cursor option (`timu_ic_last_id`) advances
     * so the CLI and the admin screen share one walk position. Lifecycle actions
     * (before/after rename, filename_sanitized, content_relinked, rename_failed)
     * fire from the shared private workers, so a listener sees CLI and admin runs
     * identically.
     *
     * @param bool $dry_run When true, report only — no renames, merges, or rewrites.
     * @param int  $limit   Attachments to walk this batch (clamped 1–50).
     *
     * @return array {
     *     @type bool   $enabled    Whether the master gate allowed the run.
     *     @type bool   $confirmed  Whether destructive ops are confirmed.
     *     @type bool   $complete   True when the walk wrapped past the last attachment.
     *     @type array  $renamed    [ id, from, to ] for each rename (or preview).
     *     @type array  $merged     [ duplicate_id, original_id ] for each merge.
     *     @type array  $skipped    [ id, reason ] for each skip.
     *     @type array  $failed     [ id ] for each I/O failure.
     *     @type int    $relinked   Total content references rewritten/previewed.
     * }
     */
    public function run_cleanup_batch( $dry_run = true, $limit = 5 ) {
        global $wpdb;

        $result = array(
            'enabled'   => $this->is_enabled(),
            'confirmed' => $this->destructive_ops_confirmed(),
            'complete'  => false,
            'renamed'   => array(),
            'merged'    => array(),
            'skipped'   => array(),
            'failed'    => array(),
            'relinked'  => 0,
        );

        if ( ! $result['enabled'] ) {
            return $result;
        }

        if ( ! $dry_run && ! $result['confirmed'] ) {
            return $result;
        }

        $limit   = max( 1, min( 50, (int) $limit ) );
        $last_id = $dry_run ? 0 : (int) get_option( 'timu_ic_last_id', 0 );
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
            $result['complete'] = true;
            return $result;
        }

        wp_defer_term_counting( true );
        wp_suspend_cache_invalidation( true );

        try {
            $seen_names        = array();
            $processable_mimes = $this->processable_mime_types();
            $max_scanned_id    = 0;

            foreach ( $targets as $t ) {
                // Track the highest ID we walked so the cursor can advance past
                // skipped/failed/no-op attachments — otherwise a batch that
                // renames nothing would never move the cursor and an --all walk
                // would spin on the same page forever.
                $max_scanned_id = max( $max_scanned_id, (int) $t->ID );

                if ( ! in_array( $t->post_mime_type, $processable_mimes, true ) ) {
                    continue;
                }
                $old_path = get_attached_file( $t->ID );
                if ( ! $old_path || ! file_exists( $old_path ) ) {
                    continue;
                }
                if ( ! $this->should_process_attachment( (int) $t->ID, $old_path ) ) {
                    $result['skipped'][] = array( 'id' => (int) $t->ID, 'reason' => 'filtered' );
                    continue;
                }

                $old_basename = basename( $old_path );
                $new_name     = $this->sanitize_filename( $old_basename, $t->ID );
                if ( false === $new_name ) {
                    $result['skipped'][] = array( 'id' => (int) $t->ID, 'reason' => 'rejected-filename' );
                    continue;
                }

                $clean_slug = pathinfo( $new_name, PATHINFO_FILENAME );
                if ( isset( $seen_names[ $clean_slug ] ) ) {
                    $this->merge_duplicate_assets( $t->ID, $seen_names[ $clean_slug ], $dry_run );
                    $result['merged'][] = array(
                        'duplicate_id' => (int) $t->ID,
                        'original_id'  => (int) $seen_names[ $clean_slug ],
                    );
                    continue;
                }
                $seen_names[ $clean_slug ] = $t->ID;

                $variations          = $this->sync_content_references( $old_basename, $new_name, $dry_run );
                $result['relinked'] += count( $variations );

                if ( $dry_run ) {
                    $result['renamed'][] = array(
                        'id'   => (int) $t->ID,
                        'from' => $old_basename,
                        'to'   => $new_name,
                    );
                    continue;
                }

                $this->backup_attachment_record( $t->ID );
                if ( ! $this->process_image_update( $t->ID, $old_path, $new_name ) ) {
                    $result['failed'][] = array( 'id' => (int) $t->ID );
                    continue;
                }
                $result['renamed'][] = array(
                    'id'   => (int) $t->ID,
                    'from' => $old_basename,
                    'to'   => $new_name,
                );
            }

            // Advance the shared cursor to the highest ID scanned this batch so
            // the next batch (CLI --all loop, or the admin screen) starts after it.
            if ( ! $dry_run && $max_scanned_id > 0 ) {
                update_option( 'timu_ic_last_id', $max_scanned_id );
            }
        } finally {
            wp_suspend_cache_invalidation( false );
            wp_defer_term_counting( false );
        }

        return $result;
    }

    /**
     * Headless filesystem-WebP discovery for scripted callers (WP-CLI).
     *
     * Thin wrapper over sync_webp_from_filesystem() so the CLI can run the same
     * discovery the admin screen runs. Honours the master gate.
     *
     * @param bool $dry_run When true, report only — no content rewrites.
     *
     * @return array Discovery rows: file, webp, dir, vars.
     */
    public function run_webp_discovery( $dry_run = true ) {
        if ( ! $this->is_enabled() ) {
            return array();
        }

        return $this->sync_webp_from_filesystem( $dry_run );
    }

    /**
     * Restore a previously-renamed asset's original file from the backup dir.
     *
     * Now strictly POST via admin-post.php. The previous GET-based handler made every
     * authenticated admin click a state-changing action vulnerable to a single
     * crafted <img src="â€¦?restore=â€¦"> on any admin page. POST + nonce + capability
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
     * near-duplicate links. Filterable â€” operators can pass an empty array to disable.
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

    // The flat render_admin_page() and add_plugin_action_links() that lived here
    // have moved to the class engine (TIMU_IC_Admin::render_admin_page() and
    // TIMU_IC_Admin::add_plugin_action_links()), which serve the tabbed family
    // UI. Removing them here keeps a single admin surface and a single set of
    // plugin-row links. The destructive cleanup runner (run_cleanup_batch),
    // WebP discovery (run_webp_discovery), 404 redirect, and restore handler
    // remain on this instance because abilities + the image-support CLI drive
    // them and the class engine does not reimplement them.
}

$GLOBALS['timu_ic_instance'] = new TIMU_IC();

/**
 * Boot the class engine. TIMU_IC (the coordinator + cleanup instance) is now
 * defined, so the static constants and helpers these bootstraps depend on are
 * available. Order mirrors the require order: file-ops registers the upload
 * hook, scheduler wires cron, REST registers routes, admin builds the tabbed UI.
 */
TIMU_IC_File_Ops::init();
TIMU_IC_Scheduler::init();
TIMU_IC_REST::init();
TIMU_IC_Admin::init();

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

\ThisIsMyURL\ImageSupport\GitHubReleaseUpdater::boot(
    array(
        'plugin_file' => __FILE__,
        'slug'        => 'thisismyurl-image-support',
        'repo'        => 'thisismyurl/thisismyurl-image-support',
    )
);
