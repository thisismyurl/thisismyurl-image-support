<?php
/**
 * WP-CLI commands for This Is My URL - Image Support.
 *
 * Registered via `WP_CLI::add_command( 'image-support', 'TIMU_Image_Support_CLI' )`
 * inside TIMU_IC::__construct() when WP-CLI is loaded.
 *
 * The mutating commands (`sanitize`, `relink`) are thin wrappers over the
 * plugin's headless runtime methods (TIMU_IC::run_cleanup_batch() and
 * TIMU_IC::run_webp_discovery()). They check the same `manage_options`
 * capability the admin screen requires and honour the
 * `thisismyurl_image_support_enabled` gate, so disabling the plugin by filter
 * disables the CLI batch too. Destructive writes additionally require the
 * `thisismyurl_image_support_confirm_destructive` opt-in; without it a non
 * dry-run reports and refuses, exactly as the admin screen does.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
    return;
}

/**
 * Sanitize image filenames, relink content references, and discover WebP files.
 */
class TIMU_Image_Support_CLI extends WP_CLI_Command {

    /**
     * Resolve the live plugin instance, or fail the command gracefully.
     *
     * @return TIMU_IC
     */
    private function instance() {
        if ( empty( $GLOBALS['timu_ic_instance'] ) || ! $GLOBALS['timu_ic_instance'] instanceof TIMU_IC ) {
            WP_CLI::error( 'Image Support is not loaded.' );
        }

        return $GLOBALS['timu_ic_instance'];
    }

    /**
     * Sanitize attachment filenames and relink their content references.
     *
     * Walks attachments in cursor order (the same cursor the admin screen uses),
     * renames each file to an SEO-friendly slug, and rewrites matching references
     * inside post_content. Destructive: a non-dry-run requires the
     * "Confirm destructive operations" opt-in. Always run --dry-run first.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Walk the entire Media Library, one batch at a time, until exhausted.
     *   Without --all a single batch of --limit attachments is processed.
     *
     * [--limit=<number>]
     * : Attachments per batch. Clamped to 1–50.
     * ---
     * default: 50
     * ---
     *
     * [--dry-run]
     * : Report proposed renames and relinks without writing anything.
     *
     * [--format=<format>]
     * : Output format for the per-attachment summary.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     *   - ids
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp image-support sanitize --dry-run
     *     wp image-support sanitize --limit=25
     *     wp image-support sanitize --all
     *     wp image-support sanitize --all --dry-run --format=json
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Associative arguments.
     *
     * @return void
     */
    public function sanitize( $args, $assoc_args ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            WP_CLI::error( 'You need the manage_options capability to sanitize images. Run as an administrator (wp --user=<admin>).' );
        }

        $plugin = $this->instance();

        if ( ! $plugin->is_enabled() ) {
            WP_CLI::error( 'Image Support is disabled by the thisismyurl_image_support_enabled filter.' );
        }

        $all     = ! empty( $assoc_args['all'] );
        $dry_run = ! empty( $assoc_args['dry-run'] );
        $limit   = isset( $assoc_args['limit'] ) ? max( 1, min( 50, (int) $assoc_args['limit'] ) ) : 50;

        if ( ! $dry_run && ! $plugin->is_destructive_confirmed() ) {
            WP_CLI::error( 'Destructive operations are not confirmed. Enable them under Tools > Image Support > Settings, set the thisismyurl_image_support_confirm_destructive option to true, or run with --dry-run.' );
        }

        $rows      = array();
        $renamed   = 0;
        $merged    = 0;
        $skipped   = 0;
        $failed    = 0;
        $relinked  = 0;
        $max_loops = 100000; // Hard ceiling for the --all walk; far past any real library.

        do {
            $batch = $plugin->run_cleanup_batch( $dry_run, $limit );

            foreach ( $batch['renamed'] as $row ) {
                $rows[] = array(
                    'id'     => $row['id'],
                    'action' => $dry_run ? 'would-rename' : 'renamed',
                    'from'   => $row['from'],
                    'to'     => $row['to'],
                );
            }
            foreach ( $batch['merged'] as $row ) {
                $rows[] = array(
                    'id'     => $row['duplicate_id'],
                    'action' => 'merged-into-' . $row['original_id'],
                    'from'   => '',
                    'to'     => '',
                );
            }

            $renamed  += count( $batch['renamed'] );
            $merged   += count( $batch['merged'] );
            $skipped  += count( $batch['skipped'] );
            $failed   += count( $batch['failed'] );
            $relinked += (int) $batch['relinked'];

            // Single-batch run, or the walk wrapped past the last attachment.
            if ( ! $all || $batch['complete'] || true === $dry_run ) {
                break;
            }
            --$max_loops;
        } while ( $max_loops > 0 );

        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        if ( ! empty( $rows ) ) {
            \WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'action', 'from', 'to' ) );
        }

        $summary = sprintf(
            'Renamed %d, merged %d, skipped %d, failed %d, relinked %d reference(s)%s.',
            $renamed,
            $merged,
            $skipped,
            $failed,
            $relinked,
            $dry_run ? ' [dry-run]' : ''
        );

        if ( $failed > 0 ) {
            WP_CLI::error( $summary, false );
            WP_CLI::halt( 1 );
        }

        WP_CLI::success( $summary );
    }

    /**
     * Discover WebP files on disk and relink content references to them.
     *
     * Walks attachments that carry a recorded original filename, finds a matching
     * .webp beside each, and rewrites post_content references from the original to
     * the WebP. Destructive on a non-dry-run: requires the "Confirm destructive
     * operations" opt-in. Run --dry-run first.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report discoveries and proposed relinks without writing post_content.
     *
     * [--format=<format>]
     * : Output format for the discovery summary.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     *   - ids
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp image-support relink --dry-run
     *     wp image-support relink
     *     wp image-support relink --dry-run --format=json
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Associative arguments.
     *
     * @return void
     */
    public function relink( $args, $assoc_args ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            WP_CLI::error( 'You need the manage_options capability to relink content. Run as an administrator (wp --user=<admin>).' );
        }

        $plugin = $this->instance();

        if ( ! $plugin->is_enabled() ) {
            WP_CLI::error( 'Image Support is disabled by the thisismyurl_image_support_enabled filter.' );
        }

        $dry_run = ! empty( $assoc_args['dry-run'] );

        if ( ! $dry_run && ! $plugin->is_destructive_confirmed() ) {
            WP_CLI::error( 'Destructive operations are not confirmed. Enable them under Tools > Image Support > Settings, set the thisismyurl_image_support_confirm_destructive option to true, or run with --dry-run.' );
        }

        $hits = $plugin->run_webp_discovery( $dry_run );

        $rows      = array();
        $relinked  = 0;
        foreach ( $hits as $hit ) {
            $count     = isset( $hit['vars'] ) ? count( (array) $hit['vars'] ) : 0;
            $relinked += $count;
            $rows[]    = array(
                'file'       => isset( $hit['file'] ) ? $hit['file'] : '',
                'webp'       => isset( $hit['webp'] ) ? $hit['webp'] : '',
                'dir'        => isset( $hit['dir'] ) ? $hit['dir'] : '',
                'references' => $count,
            );
        }

        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        if ( ! empty( $rows ) ) {
            \WP_CLI\Utils\format_items( $format, $rows, array( 'file', 'webp', 'dir', 'references' ) );
        }

        WP_CLI::success(
            sprintf(
                'Discovered %d WebP file(s), relinked %d reference(s)%s.',
                count( $hits ),
                $relinked,
                $dry_run ? ' [dry-run]' : ''
            )
        );
    }

    /**
     * Report plugin state: enabled, version, destructive-ops opt-in, cursor, counts.
     *
     * Read-only. No capability risk, no mutation.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp image-support status
     *     wp image-support status --format=json
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Associative arguments.
     *
     * @return void
     */
    public function status( $args, $assoc_args ) {
        $plugin = $this->instance();

        global $wpdb;
        $attachments = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s",
                'attachment'
            )
        );

        $version = '';
        if ( function_exists( 'get_file_data' ) ) {
            $data    = get_file_data( dirname( __DIR__ ) . '/thisismyurl-image-support.php', array( 'Version' => 'Version' ) );
            $version = isset( $data['Version'] ) ? $data['Version'] : '';
        }

        $rows = array(
            array( 'field' => 'enabled', 'value' => $plugin->is_enabled() ? 'yes' : 'no' ),
            array( 'field' => 'version', 'value' => $version ),
            array( 'field' => 'destructive_confirmed', 'value' => $plugin->is_destructive_confirmed() ? 'yes' : 'no' ),
            array( 'field' => 'cursor_last_id', 'value' => (string) (int) get_option( 'timu_ic_last_id', 0 ) ),
            array( 'field' => 'attachments', 'value' => (string) $attachments ),
        );

        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        \WP_CLI\Utils\format_items( $format, $rows, array( 'field', 'value' ) );
    }
}
