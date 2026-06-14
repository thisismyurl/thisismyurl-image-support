<?php
/**
 * Filename sanitiser.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Image filename sanitisation rules.
 */
class TIMU_IC_Sanitizer {

    /**
     * Stop words removed from filenames before slugifying.
     *
     * @return array
     */
    private static function stop_words() {
        return array(
            'and', 'or', 'the', 'a', 'an', 'with', 'for', 'in', 'at', 'by',
            'it', 'of', 'to', 'is', 'as', 'on', 'into', 'from', 'about', 'this',
            'that', 'than', 'but', 'if', 'up', 'out', 'so', 'yet',
            'my', 'your', 'his', 'her', 'their', 'our', 'its',
            'me', 'you', 'him', 'them', 'us',
            'be', 'been', 'being', 'am', 'are', 'was', 'were',
            'do', 'does', 'did', 'have', 'has', 'had',
            'dsc', 'img', 'image', 'picture', 'pic', 'photo', 'screenshot',
            'screen', 'shot', 'capture', 'scan', 'wp', 'blog', 'site', 'plugin',
            'media', 'attachment', 'scaled', 'original', 'placeholder', 'temp',
            'tmp', 'test', 'demo', 'sample', 'copy', 'final', 'new', 'old', 'draft',
        );
    }

    /**
     * Clean a filename for SEO-friendly naming.
     *
     * @param string $filename Source filename.
     * @param int    $post_id  Attachment ID for fallback naming.
     *
     * @return string
     */
    public static function clean( $filename, $post_id = 0 ) {
        $info = pathinfo( $filename );
        $name = isset( $info['filename'] ) ? $info['filename'] : $filename;
        $ext  = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';

        $name = preg_replace( '/-(?:scaled|e[0-9]{10,}|[0-9]+)$/i', '', $name );

        $stop_regex = '/\\b(' . implode( '|', self::stop_words() ) . ')\\b/i';
        $name       = preg_replace( $stop_regex, '', $name );

        $name = strtolower( trim( (string) $name, ' -_' ) );
        $name = preg_replace( '/[^a-z0-9\-_\s]/', '-', $name );
        $name = preg_replace( '/[\s_]+/', '-', $name );
        $name = preg_replace( '/-+/', '-', $name );

        if ( '' === trim( (string) $name, '-' ) || is_numeric( $name ) ) {
            $parent_id = wp_get_post_parent_id( $post_id );
            $name      = $parent_id ? sanitize_title( get_the_title( $parent_id ) ) : 'asset-' . (int) $post_id;
        }

        return trim( (string) $name, '-' ) . ( $ext ? '.' . $ext : '' );
    }
}
