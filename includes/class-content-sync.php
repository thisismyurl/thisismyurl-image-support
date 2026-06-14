<?php
/**
 * Post-content reference sync.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Updates inline image references when an attachment is renamed.
 */
class TIMU_IC_Content_Sync {

    /**
     * Replace inline references to a renamed attachment.
     *
     * @param string $old_filename Old basename, e.g. "DSC_0001.jpg".
     * @param string $new_filename New basename, e.g. "kitchen-renovation.jpg".
     *
     * @return array List of unique matched src strings that were rewritten.
     */
    public static function sync( $old_filename, $new_filename ) {
        global $wpdb;

        $report   = array();
        $old_info = pathinfo( $old_filename );
        $new_info = pathinfo( $new_filename );

        if ( empty( $old_info['filename'] ) || empty( $old_info['extension'] ) || empty( $new_info['filename'] ) || empty( $new_info['extension'] ) ) {
            return $report;
        }

        $old_base     = preg_quote( $old_info['filename'], '/' );
        $search_regex = '/' . $old_base . '(-[0-9]+x[0-9]+)?\.' . preg_quote( $old_info['extension'], '/' ) . '/i';

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s",
                '%' . $wpdb->esc_like( $old_info['filename'] ) . '%'
            )
        );

        $snapshotted = false;

        foreach ( $posts as $post ) {
            if ( preg_match_all( $search_regex, $post->post_content, $matches ) ) {
                // Request one default-scope safety snapshot the first time this
                // pass is about to rewrite any post_content. This is a DB write,
                // not a file op, so there is no path to target — the engine's
                // own scope captures the rows. Done once per sync to avoid
                // re-snapshotting on every matched post.
                if ( ! $snapshotted && class_exists( 'TIMU_IC_Backup_Adapter' ) ) {
                    TIMU_IC_Backup_Adapter::snapshot(
                        /* translators: 1: old filename, 2: new filename. */
                        sprintf(
                            __( 'Pre-content-rewrite: %1$s -> %2$s', 'thisismyurl-image-support' ),
                            $old_filename,
                            $new_filename
                        )
                    );
                    $snapshotted = true;
                }

                $report          = array_merge( $report, $matches[0] );
                $replacement     = $new_info['filename'] . '$1.' . $new_info['extension'];
                $updated_content = preg_replace( $search_regex, $replacement, $post->post_content );
                $wpdb->update(
                    $wpdb->posts,
                    array( 'post_content' => $updated_content ),
                    array( 'ID' => $post->ID )
                );
            }
        }

        return array_values( array_unique( $report ) );
    }
}
