<?php
/**
 * Metadata curation: edit alt text and normalise attachment title / caption /
 * description from the admin.
 *
 * Every operation here is benign and reversible by hand — it touches only the
 * attachment's own meta and post fields (`_wp_attachment_image_alt`,
 * `post_title`, `post_excerpt`, `post_content`). It never renames a file,
 * deletes an attachment, or edits another post's content. Detection reuses the
 * audit and score passes rather than re-implementing them; this class is the
 * write half those read-only surfaces were missing.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read a bounded page of curation candidates and apply benign metadata edits.
 */
class TIMU_IC_Curation {

    /**
     * Rows returned per page in the editable lists.
     */
    const PAGE_SIZE = 25;

    /**
     * Hard ceiling on a single bulk apply, so one click can never spend an
     * unbounded amount of time writing rows.
     */
    const BULK_CAP = 100;

    /**
     * Alt-text source keys the bulk-fill control accepts.
     */
    const ALT_SOURCES = array( 'title', 'filename', 'template' );

    // -------------------------------------------------------------------------
    // Reads — bounded, paged candidate lists
    // -------------------------------------------------------------------------

    /**
     * One bounded page of image attachments with empty or absent alt text.
     *
     * Uses the same "missing alt" definition as the Library Health Score — the
     * meta row is absent or its trimmed value is empty — but pages the result
     * with `WP_Query` rather than materialising the whole library at once.
     *
     * @param int $paged    1-based page number.
     * @param int $per_page Rows per page (clamped to PAGE_SIZE max).
     *
     * @return array{ids:int[], total:int, paged:int, per_page:int, max_pages:int}
     */
    public static function get_missing_alt_page( $paged = 1, $per_page = self::PAGE_SIZE ) {
        $paged    = max( 1, (int) $paged );
        $per_page = min( self::PAGE_SIZE, max( 1, (int) $per_page ) );

        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => $per_page,
                'paged'          => $paged,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'DESC',
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

        return array(
            'ids'       => array_map( 'intval', (array) $query->posts ),
            'total'     => (int) $query->found_posts,
            'paged'     => $paged,
            'per_page'  => $per_page,
            'max_pages' => (int) $query->max_num_pages,
        );
    }

    /**
     * One bounded page of image attachments whose post title reads as a junk
     * auto-title (camera default, screenshot, bare number, or a title equal to
     * the raw filename).
     *
     * The junk test reuses `TIMU_IC_Score::is_junk_basename()` so the Health
     * Score's "Filenames" factor and this list stay in lock-step. Because the
     * test needs the resolved title and filename per row, the walk is paged in
     * memory rather than expressed as a meta query.
     *
     * @param int $paged    1-based page number.
     * @param int $per_page Rows per page (clamped to PAGE_SIZE max).
     *
     * @return array{rows:array<int,array{id:int,title:string,filename:string}>, total:int, paged:int, per_page:int, max_pages:int}
     */
    public static function get_junk_title_page( $paged = 1, $per_page = self::PAGE_SIZE ) {
        $paged    = max( 1, (int) $paged );
        $per_page = min( self::PAGE_SIZE, max( 1, (int) $per_page ) );

        // Collect every junk-title ID in a bounded id-walk, then slice the page.
        // Each id walk is `fields=ids` + `no_found_rows`, so memory stays flat.
        $junk_ids = self::collect_junk_title_ids();
        $total    = count( $junk_ids );
        $offset   = ( $paged - 1 ) * $per_page;
        $page_ids = array_slice( $junk_ids, $offset, $per_page );

        $rows = array();
        foreach ( $page_ids as $id ) {
            $rows[] = array(
                'id'       => (int) $id,
                'title'    => (string) get_the_title( $id ),
                'filename' => wp_basename( (string) get_attached_file( $id ) ),
            );
        }

        return array(
            'rows'      => $rows,
            'total'     => $total,
            'paged'     => $paged,
            'per_page'  => $per_page,
            'max_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
        );
    }

    /**
     * Walk the image library and collect IDs whose title is junk.
     *
     * @return int[] Attachment IDs with a junk auto-title, newest first.
     */
    private static function collect_junk_title_ids() {
        $ids       = array();
        $paged     = 1;
        $max_loops = 10000;

        do {
            $query = new WP_Query(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'post_mime_type' => 'image',
                    'posts_per_page' => 200,
                    'paged'          => $paged,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'orderby'        => 'ID',
                    'order'          => 'DESC',
                )
            );

            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $id ) {
                if ( self::is_junk_title( (int) $id ) ) {
                    $ids[] = (int) $id;
                }
            }

            ++$paged;
            --$max_loops;
        } while ( $max_loops > 0 );

        return $ids;
    }

    /**
     * Whether an attachment's post title reads as a junk auto-title.
     *
     * True when the title is empty, matches a camera/phone/screenshot default
     * (delegated to the score's basename test), or is identical to the raw
     * upload filename (with or without extension) — the value WordPress writes
     * when no human ever names the image.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return bool
     */
    public static function is_junk_title( $attachment_id ) {
        $attachment_id = (int) $attachment_id;
        $title         = trim( (string) get_post_field( 'post_title', $attachment_id ) );

        if ( '' === $title ) {
            return true;
        }

        $file = (string) get_attached_file( $attachment_id );
        if ( '' !== $file ) {
            $basename = wp_basename( $file );
            $stem     = pathinfo( $basename, PATHINFO_FILENAME );

            // WordPress sets the title to the sanitised filename stem on upload;
            // a title still equal to that stem is one nobody has curated.
            if ( 0 === strcasecmp( $title, $basename ) || 0 === strcasecmp( $title, $stem ) ) {
                return true;
            }

            // The score's basename test catches IMG_1234, DSC0001, screenshots,
            // bare numbers, and the rest of the camera-default family.
            if ( TIMU_IC_Score::is_junk_basename( $basename ) ) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Derivation — turn a filename / template into clean text
    // -------------------------------------------------------------------------

    /**
     * Humanise a filename into a readable phrase.
     *
     * `christmas-tree-2024-scaled.jpg` becomes `Christmas tree 2024`: the
     * extension and WordPress's generated tails (`-scaled`, `-e1700000000`,
     * `-1024x768`) are stripped, separators collapse to spaces, and the result
     * is title-cased for the first letter only (sentence case, per the brand
     * label convention).
     *
     * @param string $file Filename or full path.
     *
     * @return string Humanised phrase, or '' when nothing usable remains.
     */
    public static function humanize_filename( $file ) {
        $stem = pathinfo( wp_basename( (string) $file ), PATHINFO_FILENAME );

        // Drop the same generated tails the junk-basename test normalises away.
        $stem = preg_replace( '/-(?:scaled|e[0-9]{10,}|\d+x\d+)$/i', '', (string) $stem );

        // Separators to spaces, collapse runs, trim.
        $stem = preg_replace( '/[-_]+/', ' ', (string) $stem );
        $stem = preg_replace( '/\s+/', ' ', (string) $stem );
        $stem = trim( (string) $stem );

        if ( '' === $stem ) {
            return '';
        }

        // Sentence case: capitalise the first character only.
        return self::ucfirst_mb( $stem );
    }

    /**
     * Multibyte-safe ucfirst that lowercases nothing else.
     *
     * @param string $text Input.
     *
     * @return string
     */
    private static function ucfirst_mb( $text ) {
        if ( '' === $text ) {
            return '';
        }
        if ( function_exists( 'mb_strtoupper' ) ) {
            $first = mb_strtoupper( mb_substr( $text, 0, 1 ) );
            return $first . mb_substr( $text, 1 );
        }
        return ucfirst( $text );
    }

    /**
     * Expand a template string against an attachment's tokens.
     *
     * Supported tokens: `{title}`, `{filename}` (humanised), `{site_name}`.
     * Unknown tokens are left intact so a typo is visible rather than silently
     * dropped. The result is run through `sanitize_text_field`.
     *
     * @param string $template Template containing zero or more tokens.
     * @param int    $att_id   Attachment ID supplying the token values.
     *
     * @return string
     */
    public static function expand_template( $template, $att_id ) {
        $att_id = (int) $att_id;

        $replacements = array(
            '{title}'     => (string) get_the_title( $att_id ),
            '{filename}'  => self::humanize_filename( (string) get_attached_file( $att_id ) ),
            '{site_name}' => (string) get_bloginfo( 'name' ),
        );

        $out = strtr( (string) $template, $replacements );

        return sanitize_text_field( $out );
    }

    /**
     * Resolve the alt-text value a given source would produce for one image.
     *
     * @param int    $att_id   Attachment ID.
     * @param string $source   One of ALT_SOURCES.
     * @param string $template Template string, used only when $source is 'template'.
     *
     * @return string Resolved alt value (may be empty when the source yields nothing).
     */
    public static function resolve_alt_source( $att_id, $source, $template = '' ) {
        $att_id = (int) $att_id;

        switch ( $source ) {
            case 'title':
                return sanitize_text_field( (string) get_the_title( $att_id ) );
            case 'filename':
                return self::humanize_filename( (string) get_attached_file( $att_id ) );
            case 'template':
                return self::expand_template( $template, $att_id );
            default:
                return '';
        }
    }

    // -------------------------------------------------------------------------
    // Writes — alt text
    // -------------------------------------------------------------------------

    /**
     * Save alt text on a single image attachment.
     *
     * @param int    $att_id Attachment ID.
     * @param string $alt    New alt value (sanitised here).
     *
     * @return true|WP_Error True on success.
     */
    public static function save_alt_text( $att_id, $alt ) {
        $att_id = (int) $att_id;

        if ( 'attachment' !== get_post_type( $att_id ) ) {
            return new WP_Error( 'not_attachment', __( 'That ID is not an attachment.', 'thisismyurl-image-support' ) );
        }

        // Alt text is a plain string; sanitize_text_field strips tags and
        // collapses whitespace — the same treatment the Media Library applies.
        $clean = sanitize_text_field( (string) $alt );

        update_post_meta( $att_id, '_wp_attachment_image_alt', $clean );

        return true;
    }

    /**
     * Bulk-fill alt text on a set of attachments from a chosen source.
     *
     * Only writes when the resolved value is non-empty and the existing alt is
     * empty — so a bulk run never clobbers alt text an editor already wrote.
     * Bounded to BULK_CAP IDs per call.
     *
     * @param int[]  $ids      Attachment IDs.
     * @param string $source   One of ALT_SOURCES.
     * @param string $template Template string (used when $source is 'template').
     *
     * @return array{filled:int, skipped:int, processed:int} Result counts.
     */
    public static function bulk_fill_alt( $ids, $source, $template = '' ) {
        $ids    = array_slice( array_values( array_filter( array_map( 'absint', (array) $ids ) ) ), 0, self::BULK_CAP );
        $source = in_array( $source, self::ALT_SOURCES, true ) ? $source : 'title';

        $filled  = 0;
        $skipped = 0;

        foreach ( $ids as $id ) {
            if ( 'attachment' !== get_post_type( $id ) ) {
                ++$skipped;
                continue;
            }

            $existing = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
            if ( '' !== $existing ) {
                ++$skipped;
                continue;
            }

            $value = self::resolve_alt_source( $id, $source, $template );
            if ( '' === trim( $value ) ) {
                ++$skipped;
                continue;
            }

            update_post_meta( $id, '_wp_attachment_image_alt', $value );
            ++$filled;
        }

        return array(
            'filled'    => $filled,
            'skipped'   => $skipped,
            'processed' => count( $ids ),
        );
    }

    // -------------------------------------------------------------------------
    // Writes — title / caption / description normalisation
    // -------------------------------------------------------------------------

    /**
     * Compute the before/after fields a normalise run would produce for one
     * attachment, honouring the per-field opt-ins. Pure — writes nothing.
     *
     * @param int   $att_id Attachment ID.
     * @param array $opts   {
     *     @type bool   $title              Whether to derive a clean title.
     *     @type bool   $caption            Whether to set the caption.
     *     @type bool   $description        Whether to set the description.
     *     @type string $title_template     Optional template for the title; '' uses humanised filename.
     *     @type string $caption_template   Template for the caption; required when caption is on.
     *     @type string $description_template Template for the description; required when description is on.
     * }
     *
     * @return array{id:int, title:array{from:string,to:string,change:bool}, caption:array{from:string,to:string,change:bool}, description:array{from:string,to:string,change:bool}}
     */
    public static function preview_normalize( $att_id, $opts ) {
        $att_id = (int) $att_id;
        $opts   = self::normalize_opts( $opts );

        $current_title       = (string) get_post_field( 'post_title', $att_id );
        $current_caption     = (string) get_post_field( 'post_excerpt', $att_id );
        $current_description = (string) get_post_field( 'post_content', $att_id );

        $next_title       = $current_title;
        $next_caption     = $current_caption;
        $next_description = $current_description;

        if ( $opts['title'] ) {
            $next_title = '' !== $opts['title_template']
                ? self::expand_template( $opts['title_template'], $att_id )
                : self::humanize_filename( (string) get_attached_file( $att_id ) );
            // Never write an empty title over an existing one.
            if ( '' === trim( $next_title ) ) {
                $next_title = $current_title;
            }
        }

        if ( $opts['caption'] ) {
            $next_caption = self::expand_template( $opts['caption_template'], $att_id );
        }

        if ( $opts['description'] ) {
            $next_description = self::expand_template( $opts['description_template'], $att_id );
        }

        return array(
            'id'          => $att_id,
            'title'       => array(
                'from'   => $current_title,
                'to'     => $next_title,
                'change' => $opts['title'] && $next_title !== $current_title,
            ),
            'caption'     => array(
                'from'   => $current_caption,
                'to'     => $next_caption,
                'change' => $opts['caption'] && $next_caption !== $current_caption,
            ),
            'description' => array(
                'from'   => $current_description,
                'to'     => $next_description,
                'change' => $opts['description'] && $next_description !== $current_description,
            ),
        );
    }

    /**
     * Apply a normalisation to one attachment, honouring per-field opt-ins.
     *
     * Writes only the fields whose opt-in is set and whose value actually
     * changes, via a single `wp_update_post` on the attachment itself.
     *
     * @param int   $att_id Attachment ID.
     * @param array $opts   Same shape as preview_normalize().
     *
     * @return array{id:int, changed:bool, fields:string[]}|WP_Error
     */
    public static function normalize_attachment( $att_id, $opts ) {
        $att_id = (int) $att_id;

        if ( 'attachment' !== get_post_type( $att_id ) ) {
            return new WP_Error( 'not_attachment', __( 'That ID is not an attachment.', 'thisismyurl-image-support' ) );
        }

        $preview = self::preview_normalize( $att_id, $opts );

        $update = array( 'ID' => $att_id );
        $fields = array();

        if ( $preview['title']['change'] ) {
            $update['post_title'] = $preview['title']['to'];
            $fields[]             = 'title';
        }
        if ( $preview['caption']['change'] ) {
            $update['post_excerpt'] = $preview['caption']['to'];
            $fields[]               = 'caption';
        }
        if ( $preview['description']['change'] ) {
            $update['post_content'] = $preview['description']['to'];
            $fields[]               = 'description';
        }

        if ( empty( $fields ) ) {
            return array(
                'id'      => $att_id,
                'changed' => false,
                'fields'  => array(),
            );
        }

        $result = wp_update_post( $update, true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array(
            'id'      => $att_id,
            'changed' => true,
            'fields'  => $fields,
        );
    }

    /**
     * Bulk normalise a set of attachments, with a dry-run mode.
     *
     * In dry-run mode no post is written; the return carries the before/after
     * preview for each row so the UI can show the diff before the operator
     * commits. Bounded to BULK_CAP IDs per call.
     *
     * @param int[] $ids     Attachment IDs.
     * @param array $opts    Same shape as preview_normalize().
     * @param bool  $dry_run When true, preview only.
     *
     * @return array{dry_run:bool, processed:int, changed:int, previews:array} Result.
     */
    public static function bulk_normalize( $ids, $opts, $dry_run = true ) {
        $ids  = array_slice( array_values( array_filter( array_map( 'absint', (array) $ids ) ) ), 0, self::BULK_CAP );
        $opts = self::normalize_opts( $opts );

        $changed  = 0;
        $previews = array();

        foreach ( $ids as $id ) {
            if ( 'attachment' !== get_post_type( $id ) ) {
                continue;
            }

            $preview      = self::preview_normalize( $id, $opts );
            $row_changes  = $preview['title']['change'] || $preview['caption']['change'] || $preview['description']['change'];

            if ( $dry_run ) {
                if ( $row_changes ) {
                    $previews[] = $preview;
                }
                continue;
            }

            $result = self::normalize_attachment( $id, $opts );
            if ( ! is_wp_error( $result ) && ! empty( $result['changed'] ) ) {
                ++$changed;
                $previews[] = $preview;
            }
        }

        return array(
            'dry_run'   => (bool) $dry_run,
            'processed' => count( $ids ),
            'changed'   => $dry_run ? count( $previews ) : $changed,
            'previews'  => $previews,
        );
    }

    /**
     * Normalise the loosely typed opts array into a known shape.
     *
     * @param array $opts Raw opts.
     *
     * @return array Normalised opts with every key present.
     */
    private static function normalize_opts( $opts ) {
        $opts = is_array( $opts ) ? $opts : array();

        return array(
            'title'                => ! empty( $opts['title'] ),
            'caption'              => ! empty( $opts['caption'] ),
            'description'          => ! empty( $opts['description'] ),
            'title_template'       => isset( $opts['title_template'] ) ? sanitize_text_field( (string) $opts['title_template'] ) : '',
            'caption_template'     => isset( $opts['caption_template'] ) ? sanitize_text_field( (string) $opts['caption_template'] ) : '',
            'description_template' => isset( $opts['description_template'] ) ? sanitize_text_field( (string) $opts['description_template'] ) : '',
        );
    }
}
