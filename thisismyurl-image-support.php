<?php
/**
 * Author:      Christopher Ross
 * Author URI:  https://thisismyurl.com/
 * Plugin Name: Image Support by thisismyurl.com
 * Plugin URI:  https://thisismyurl.com/thisismyurl-image-support/
 * Donate link: https://thisismyurl.com/donate/
 * Description: Advanced image sanitization, duplicate merging, WebP filesystem discovery, and deep content re-syncing.
 * Version:     0.6122
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/thisismyurl/thisismyurl-image-support
 * Text Domain: thisismyurl-image-support
 * License: GPL2
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TIMU_IC {

    private $backup_dir;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_menu', [ $this, 'cleanup_menus' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_plugin_action_links' ] );
        add_action( 'admin_init', [ $this, 'handle_restore_request' ] );
        add_action( 'template_redirect', [ $this, 'handle_image_404_redirects' ] );
        add_filter( 'the_content', [ $this, 'dynamic_webp_replacement' ] );

        $upload_dir = wp_upload_dir();
        $this->backup_dir = $upload_dir['basedir'] . '/timu-image-backups/';
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
     * Logic to verify file existence, look back 3 months, or generate the asset.
     */
    private function verify_and_locate_webp( $url ) {
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        // If the image isn't in our uploads, we skip it
        if ( strpos( $url, $base_url ) === false ) return false;

        $relative_path = str_replace( $base_url, '', $url );
        $actual_full_path = $base_dir . $relative_path;
        $webp_full_path = preg_replace( '/\.(?:jpg|jpeg|png|gif)$/i', '.webp', $actual_full_path );

        // 1. Check if the WebP exists in the expected location
        if ( file_exists( $webp_full_path ) ) {
            return str_replace( $base_dir, $base_url, $webp_full_path );
        }

        // 2. Look backwards 3 months in the folder structure
        // Expected format: /YYYY/MM/filename.ext
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
                    $check_year--;
                }

                $formatted_month = str_pad( $check_month, 2, '0', STR_PAD_LEFT );
                $lookback_path = "/$check_year/$formatted_month/$file_webp";
                
                if ( file_exists( $base_dir . $lookback_path ) ) {
                    return $base_url . $lookback_path;
                }
            }
        }

        // 3. If still not found, trigger generation in the CORRECT (original) folder
        return $this->generate_webp_on_the_fly( $actual_full_path );
    }

    /**
     * Generates a WebP version of the image if the source exists.
     */
    private function generate_webp_on_the_fly( $source_path ) {
        if ( ! file_exists( $source_path ) ) return false;

        $upload_dir = wp_upload_dir();
        $webp_path  = preg_replace( '/\.(?:jpg|jpeg|png|gif)$/i', '.webp', $source_path );

        // If generation logic is handled by the sister plugin
        if ( class_exists( 'TIMU_WEBP_Support' ) ) {
            // We need the attachment ID for the formal conversion process
            global $wpdb;
            $relative = str_replace( $upload_dir['basedir'], '', $source_path );
            $attachment_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s", ltrim($relative, '/') ) );
            
            if ( $attachment_id ) {
                TIMU_WEBP_Support::convert_to_webp( $attachment_id );
                if ( file_exists( $webp_path ) ) {
                    return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $webp_path );
                }
            }
        }

        // Fallback: Basic PHP GD conversion if the specialized class is missing
        $image = false;
        $info  = getimagesize( $source_path );
        
        switch ( $info['mime'] ) {
            case 'image/jpeg': $image = imagecreatefromjpeg( $source_path ); break;
            case 'image/png':  $image = imagecreatefrompng( $source_path );  break;
        }

        if ( $image ) {
            imagepalettetotruecolor( $image );
            imagewebp( $image, $webp_path, 80 );
            imagedestroy( $image );
            return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $webp_path );
        }

        return false;
    }

    public function handle_image_404_redirects() {
        if ( ! is_404() ) return;
        $upload_dir = wp_upload_dir();
        $requested_uri = $_SERVER['REQUEST_URI'];
        $relative_request = str_replace( parse_url( $upload_dir['baseurl'], PHP_URL_PATH ), '', $requested_uri );

        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_timu_original_path' AND meta_value = %s LIMIT 1", $relative_request ) );

        if ( $id && ( $new_url = wp_get_attachment_url( $id ) ) ) {
            wp_redirect( $new_url, 301 );
            exit;
        }
    }

    private function sanitize_filename( $filename, $post_id=0 ) {
        $info = pathinfo( $filename );
        $name = $info['filename'];
        $ext  = isset($info['extension']) ? strtolower( $info['extension'] ) : '';

        $name = preg_replace( '/-(?:scaled|e[0-9]{10,}|[0-9]+)$/i', '', $name );

        $stop_words = ['and', 'or', 'the', 'a', 'an', 'with', 'for', 'in', 'at', 'by', 'it', 'of', 'to', 'is', 'as', 'on', 'into', 'from', 'about', 'this', 'that', 'than', 'but', 'if', 'up', 'out', 'so', 'yet', 'my', 'your', 'his', 'her', 'their', 'our', 'its', 'me', 'you', 'him', 'them', 'us', 'be', 'been', 'being', 'am', 'are', 'was', 'were', 'do', 'does', 'did', 'have', 'has', 'had', 'dsc', 'img', 'image', 'picture', 'pic', 'photo', 'screenshot', 'screen', 'shot', 'capture', 'scan', 'wp', 'blog', 'site', 'plugin', 'media', 'attachment', 'scaled', 'original', 'placeholder', 'temp', 'tmp', 'test', 'demo', 'sample', 'copy', 'final', 'new', 'old', 'draft'];
        
        $stop_regex = '/\b(' . implode('|', $stop_words) . ')\b/i';
        $name = preg_replace($stop_regex, '', $name);

        $name = strtolower( trim( $name, ' -_' ) );
        $name = preg_replace( '/[\s_]+/', '-', $name );
        $name = preg_replace( '/-+/', '-', $name );

        if ( empty( trim( $name, '-' ) ) || is_numeric( $name ) ) {
            $parent_id = wp_get_post_parent_id( $post_id );
            $name = $parent_id ? sanitize_title( get_the_title( $parent_id ) ) : 'asset-' . $post_id;
        }

        return trim( $name, '-' ) . ( $ext ? '.' . $ext : '' );
    }

    private function merge_duplicate_assets( $duplicate_id, $original_id, $dry_run ) {
        global $wpdb;
        if ( $dry_run ) return;

        $wpdb->update( $wpdb->postmeta, ['meta_value' => $original_id], ['meta_key' => '_thumbnail_id', 'meta_value' => $duplicate_id] );
        $dup_url = wp_get_attachment_url( $duplicate_id );
        $orig_url = wp_get_attachment_url( $original_id );
        if ( $dup_url && $orig_url ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s", $dup_url, $orig_url, '%' . $wpdb->esc_like($dup_url) . '%' ) );
        }
        wp_delete_attachment( $duplicate_id, true );
    }

    private function sync_content_references( $old_filename, $new_filename, $dry_run = false ) {
        global $wpdb;
        $report = [];
        $old_info = pathinfo( $old_filename );
        $new_info = pathinfo( $new_filename );
        $old_base = preg_quote( $old_info['filename'], '/' );
        $search_regex = '/' . $old_base . '(-[0-9]+x[0-9]+)?\.' . preg_quote( $old_info['extension'], '/' ) . '/i';
        $posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s", '%' . $wpdb->esc_like( $old_info['filename'] ) . '%' ) );

        foreach ( $posts as $post ) {
            if ( preg_match_all( $search_regex, $post->post_content, $matches ) ) {
                $report = array_merge($report, $matches[0]);
                if ( ! $dry_run ) {
                    $replacement = $new_info['filename'] . '$1.' . $new_info['extension'];
                    $updated_content = preg_replace( $search_regex, $replacement, $post->post_content );
                    $wpdb->update( $wpdb->posts, [ 'post_content' => $updated_content ], [ 'ID' => $post->ID ] );
                }
            }
        }
        return array_unique( $report );
    }

    private function sync_webp_from_filesystem( $dry_run = false ) {
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $base_path = $upload_dir['basedir'];
        $results = [];
        $original_files = $wpdb->get_col( "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_timu_original_filename'" );
        if ( empty( $original_files ) ) return [];
        $years = array_filter( scandir( $base_path ), function( $item ) { return is_numeric( $item ); } );

        foreach ( $years as $year ) {
            $year_path = $base_path . '/' . $year;
            if ( ! is_dir( $year_path ) ) continue;
            foreach ( array_diff( scandir( $year_path ), [ '.', '..' ] ) as $month ) {
                $current_dir = $year_path . '/' . $month;
                if ( ! is_dir( $current_dir ) ) continue;
                foreach ( array_diff( scandir( $current_dir ), [ '.', '..' ] ) as $file ) {
                    if ( in_array( $file, $original_files ) ) {
                        $webp_name = pathinfo( $file, PATHINFO_FILENAME ) . '.webp';
                        if ( file_exists( $current_dir . '/' . $webp_name ) ) {
                            $variations = $this->sync_content_references( $file, $webp_name, $dry_run );
                            if ( ! empty( $variations ) ) $results[] = ['file' => $file, 'webp' => $webp_name, 'dir' => "$year/$month", 'vars' => $variations];
                        }
                    }
                }
            }
        }
        return $results;
    }

    private function process_image_update( $id, $source_path, $new_name ) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $upload_dir = wp_upload_dir();
        $old_basename = basename( $source_path );
        $new_path = $upload_dir['path'] . '/' . $new_name;
        if ( ! is_dir( $this->backup_dir ) ) wp_mkdir_p( $this->backup_dir );
        if ( $source_path !== $new_path ) {
            if ( ! file_exists( $this->backup_dir . $old_basename ) ) copy( $source_path, $this->backup_dir . $old_basename );
            rename( $source_path, $new_path );
            update_attached_file( $id, $new_path );
        }
        update_post_meta( $id, '_timu_original_path', str_replace( $upload_dir['basedir'], '', $source_path ) );
        update_post_meta( $id, '_timu_original_filename', $old_basename );
        wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $new_path ) );
        if ( class_exists( 'TIMU_WEBP_Support' ) ) TIMU_WEBP_Support::convert_to_webp( $id );
    }

    private function handle_cleanup( $dry_run = true, $limit = 5 ) {
        global $wpdb;
        $last_id = ( $dry_run ) ? 0 : (int) get_option( 'timu_ic_last_id', 0 );
        $targets = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_mime_type FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d", $last_id, $limit ) );
        if ( empty( $targets ) ) { if ( ! $dry_run ) update_option( 'timu_ic_last_id', 0 ); echo "<li>Audit complete.</li>"; return; }
        $seen_names = [];
        foreach ( $targets as $t ) {
            if ( ! in_array( $t->post_mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'] ) ) continue;
            $old_path = get_attached_file( $t->ID );
            if ( ! $old_path || ! file_exists( $old_path ) ) continue;
            $old_basename = basename( $old_path );
            $new_name = $this->sanitize_filename( $old_basename, $t->ID );
            $clean_slug = pathinfo( $new_name, PATHINFO_FILENAME );
            if ( isset( $seen_names[ $clean_slug ] ) ) { $this->merge_duplicate_assets( $t->ID, $seen_names[ $clean_slug ], $dry_run ); continue; }
            $seen_names[ $clean_slug ] = $t->ID;
            $variations = $this->sync_content_references( $old_basename, $new_name, $dry_run );
            if ( ! $dry_run ) { $this->process_image_update( $t->ID, $old_path, $new_name ); update_option( 'timu_ic_last_id', $t->ID ); echo "<li>Updated #{$t->ID}: <strong>$new_name</strong>"; }
            else { echo "<li>Preview #{$t->ID}: Would rename <strong>$old_basename</strong> to <strong>$new_name</strong>"; }
            if ( ! empty( $variations ) ) { echo "<ul style='margin-left:20px; color:#666; font-size:0.85em;'>"; foreach ( $variations as $v ) echo "<li>Fixed: <code>$v</code></li>"; echo "</ul>"; }
            echo "</li>";
        }
    }

    public function handle_restore_request() {
        if ( ! isset( $_GET['restore'] ) || ! current_user_can( 'manage_options' ) ) return;
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'thisismyurl_image_support_restore' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'thisismyurl-image-support' ) );
        }
        $file = basename( sanitize_text_field( wp_unslash( $_GET['restore'] ) ) );
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_timu_original_filename' AND meta_value = %s LIMIT 1", $file ) );
        if ( $id && file_exists( $this->backup_dir . $file ) ) {
            $target = wp_upload_dir()['basedir'] . get_post_meta( $id, '_timu_original_path', true );
            if ( copy( $this->backup_dir . $file, $target ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                update_attached_file( $id, $target );
                wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $target ) );
                unlink( $this->backup_dir . $file );
                wp_redirect( admin_url( 'tools.php?page=thisismyurl-image-support&restored=1' ) );
                exit;
            }
        }
    }

    public function cleanup_menus() {
        foreach ( ['thisismyurl-webp-support', 'thisismyurl-heic-support'] as $s ) remove_submenu_page( 'tools.php', $s );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'thisismyurl-image-support' ) );
        }

        $is_post = ! empty( $_POST ) && ( isset( $_POST['dry_run'] ) || isset( $_POST['run_cleanup'] ) );
        if ( $is_post ) {
            check_admin_referer( 'thisismyurl_image_support_action', 'thisismyurl_image_support_nonce' );
        }

        $user_batch = $is_post && isset( $_POST['batch_limit'] )
            ? max( 1, min( 1000, (int) $_POST['batch_limit'] ) )
            : ( $is_post && isset( $_POST['dry_run'] ) ? 500 : 5 );
        ?>
        <div class="wrap">
            <h1>Image Support <span style="font-size: 0.5em; color: #646970;">by thisismyurl.com</span></h1>
            <div class="postbox">
                <h2 class="hndle"><span>Image Optimization &amp; Deep Sync</span></h2>
                <div class="inside">
                    <form method="post">
                        <?php wp_nonce_field( 'thisismyurl_image_support_action', 'thisismyurl_image_support_nonce' ); ?>
                        <p><label for="batch_limit"><strong>Images per Batch:</strong></label> <input type="number" id="batch_limit" name="batch_limit" value="<?php echo esc_attr( $user_batch ); ?>" min="1" max="1000" style="width: 80px;"></p>
                        <div style="display: flex; gap: 10px;"><input type="submit" name="dry_run" class="button" value="Preview Changes (Dry Run)"><input type="submit" name="run_cleanup" class="button button-primary" value="Update &amp; Sync WebP" onclick="return confirm('Update images and sync WebP?');"></div>
                    </form>
                    <?php if ( $is_post ) : ?>
                        <ul style="margin-top:20px; max-height:500px; overflow:auto; background:#f6f7f7; padding:15px; border:1px solid #dcdcde;">
                            <?php
                            $is_dry_run = isset( $_POST['dry_run'] );
                            $webp_hits  = $this->sync_webp_from_filesystem( $is_dry_run );
                            if ( ! empty( $webp_hits ) ) {
                                echo "<li><strong>Filesystem WebP Discovery:</strong></li><ul style='margin-left:20px;'>";
                                foreach ( $webp_hits as $hit ) {
                                    echo '<li>' . ( $is_dry_run ? 'Found' : 'Replaced' ) . ' <code>' . esc_html( $hit['file'] ) . '</code> in ' . esc_html( $hit['dir'] ) . '</li>';
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
        return array_merge( ['<a href="' . admin_url( 'tools.php?page=thisismyurl-image-support' ) . '">Settings</a>'], $links );
    }
}

new TIMU_IC();

require_once plugin_dir_path( __FILE__ ) . 'github-updater.php';

timu_boot_github_release_updater(
    array(
        'plugin_file' => __FILE__,
        'slug'        => 'thisismyurl-image-support',
        'repo'        => 'thisismyurl/thisismyurl-image-support',
    )
);