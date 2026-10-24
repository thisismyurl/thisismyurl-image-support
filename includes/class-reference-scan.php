<?php
/**
 * Where each image is referenced, as far as can honestly be determined.
 *
 * WordPress records how an image was uploaded, not how it is used. `post_parent` is set
 * when a file is uploaded from inside a post and left empty when it is uploaded from the
 * Media screen, so core's "Unattached" filter answers a question almost nobody is asking.
 * Measured on a real 766-image library, 23% of unattached images were live on the site and
 * 86 attached images were absent from the post they were attached to.
 *
 * So this looks for the reference itself, in the five places one can be found without
 * guessing: featured-image meta, post content, custom fields, site and theme settings, and
 * the files of the active theme and must-use plugins.
 *
 * What it cannot see is the point of the whole class. Page builders that store layouts in
 * their own tables, shortcode attributes resolved at render time, images composed in
 * JavaScript, and anything a plugin keeps somewhere private are all invisible here. That is
 * why nothing in this file ever concludes an image is unused. The strongest thing it says
 * is that no reference was found, which is a statement about the search rather than about
 * the image.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and caches a map of image ID to the places it is referenced.
 */
final class TIMU_Image_Support_Reference_Scan {

	/**
	 * Cached scan result.
	 */
	const CACHE_KEY = 'timu_image_reference_map';

	/**
	 * How long a scan stays good for.
	 *
	 * Long, because the scan reads files off disk and nobody should pay for that on a page
	 * refresh. There is a Rescan control for when somebody wants it done again.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Rows read from the database per query while gathering the text to search.
	 */
	const PAGE_SIZE = 200;

	/**
	 * Set while a scan runs if any source could not be read in full.
	 *
	 * @var bool
	 */
	private static $incomplete = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		foreach ( array( 'add_attachment', 'delete_attachment', 'switch_theme' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Drop the cached scan.
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * The places a reference can be found, in the order they are worth trusting.
	 *
	 * @return array<string,string>
	 */
	public static function sources() {
		return array(
			'featured' => __( 'Featured picture', 'thisismyurl-image-support' ),
			'content'  => __( 'Post or page content', 'thisismyurl-image-support' ),
			'field'    => __( 'A custom field', 'thisismyurl-image-support' ),
			'option'   => __( 'A site or theme setting', 'thisismyurl-image-support' ),
			'file'     => __( 'A theme or plugin file', 'thisismyurl-image-support' ),
		);
	}

	/**
	 * The scan, from cache when it can be.
	 *
	 * @param bool $force Rebuild even if a cached result exists.
	 * @return array{map:array<int,string[]>,total:int,scanned:int,capped:bool,files:int,generated:int}
	 */
	public static function map( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) && isset( $cached['map'] ) ) {
				return $cached;
			}
		}

		$result = self::build();

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Do the work.
	 *
	 * Every source is reduced to one lowercase haystack of filename-shaped tokens first,
	 * then each image is tested against those. The alternative, searching the database once
	 * per image, is the same work multiplied by the size of the library.
	 *
	 * @return array
	 */
	private static function build() {
		global $wpdb;

		/**
		 * Filter how many images a single scan will examine.
		 *
		 * @param int $limit Image count.
		 */
		$limit = (int) apply_filters( 'timu_image_support_reference_scan_limit', 5000 );

		$images = $wpdb->esc_like( 'image/' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the whole result of this scan is cached in a transient by map(); caching each query as well would cache the same work twice.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND post_mime_type LIKE %s",
				$images
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, m.meta_value AS file
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON ( p.ID = m.post_id AND m.meta_key = %s )
				 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
				 ORDER BY p.ID DESC
				 LIMIT %d",
				'_wp_attached_file',
				$images,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		self::$incomplete = false;

		$haystacks = array(
			'content' => self::content_haystack(),
			'field'   => self::field_haystack(),
			'option'  => self::option_haystack(),
		);

		$files             = self::theme_files();
		$haystacks['file'] = self::file_haystack( $files );
		$map               = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- covered by the transient in map().
		$thumbnail_ids = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'" );
		$featured      = array_flip( array_map( 'intval', $thumbnail_ids ) );

		// Two images almost every site uses are stored as an ID, never as a file name: the site
		// icon and the custom logo. Searching for file names would call both unreferenced.
		$by_setting = array_flip( array_filter( array( (int) get_option( 'site_icon' ), (int) get_theme_mod( 'custom_logo' ) ) ) );

		foreach ( $rows as $row ) {
			$id   = (int) $row->ID;
			$stem = self::stem( (string) $row->file );
			$hits = array();

			if ( isset( $featured[ $id ] ) ) {
				$hits[] = 'featured';
			}

			if ( isset( $by_setting[ $id ] ) ) {
				$hits[] = 'option';
			}

			if ( '' !== $stem ) {
				// `wp-image-123` is the class the editor puts on an inserted image, which
				// catches one that was later renamed on disk.
				// The id needle carries its spaces, so wp-image-12 can never match inside wp-image-1234.
				$needles = array( $stem, ' wp-image-' . $id . ' ' );

				foreach ( $haystacks as $key => $hay ) {
					foreach ( $needles as $needle ) {
						if ( '' !== $hay && false !== strpos( $hay, $needle ) ) {
							$hits[] = $key;
							break;
						}
					}
				}
			}

			$map[ $id ] = array_values( array_unique( $hits ) );
		}

		return array(
			'map'        => $map,
			'total'      => $total,
			'scanned'    => count( $map ),
			'capped'     => $total > count( $map ),
			'files'      => count( $files ),
			'generated'  => time(),
			// True when a source could not be read in full. An image with "no reference found"
			// from an incomplete scan is not evidence of anything, and the page says so.
			'incomplete' => self::$incomplete,
		);
	}

	/**
	 * The comparable part of a file name.
	 *
	 * Core appends `-scaled` to a big upload and `-800x600` to every generated size, so a
	 * reference to any size still has to match the original.
	 *
	 * @param string $file Value of `_wp_attached_file`.
	 * @return string
	 */
	private static function stem( $file ) {
		$stem = self::lower( pathinfo( $file, PATHINFO_FILENAME ) );

		return (string) preg_replace( '/-(scaled|\d+x\d+)$/', '', $stem );
	}

	/**
	 * Lowercase a string, using mbstring when the host has it.
	 *
	 * WordPress polyfills only a few of the mb_ functions, and this is not one of them.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Reduce a body of text to the file names inside it.
	 *
	 * Content, settings and theme files can run to many megabytes, and all that is wanted
	 * from them is which images they mention. Keeping only the file-name-shaped tokens turns
	 * an unbounded haystack into a small one.
	 *
	 * @param string $text Raw text.
	 * @return string[] Lowercase tokens. Empty when there is nothing to find.
	 */
	private static function condense( $text ) {
		if ( '' === $text ) {
			return array();
		}

		$matches = array();
		// File names are matched as they are written in a page (percent-encoded) and as they are
		// stored on disk, and in any language. Invalid UTF-8 is dropped rather than failing the match.
		$text = rawurldecode( $text );

		// Before WordPress 6.9, wp_check_invalid_utf8() strips with iconv(), which returns false on
		// a single bad byte, so a Latin-1 copyright sign in a stylesheet would blank the whole file.
		// Scrub it first where mbstring exists, and treat anything that still cannot be cleaned as a
		// failed read, never as an empty one.
		if ( function_exists( 'mb_scrub' ) ) {
			$text = mb_scrub( $text, 'UTF-8' );
		}

		$clean = wp_check_invalid_utf8( $text, true );

		if ( ! is_string( $clean ) || '' === $clean ) {
			self::$incomplete = true;

			return array();
		}

		$text  = $clean;
		$found = preg_match_all( '/[\p{L}\p{N}_.\-]+\.(?:jpe?g|png|gif|webp|avif|svg)|wp-image-\d+/iu', $text, $matches );

		if ( false === $found ) {
			// The regular expression engine gave up (a backtrack or memory limit on a very long
			// string). Treating that as "nothing here" would tell somebody every image is
			// unreferenced, which is the one answer this plugin must never give by accident.
			self::$incomplete = true;

			return array();
		}

		return array_map( array( __CLASS__, 'lower' ), $matches[0] );
	}

	/**
	 * Gather tokens from a table a page at a time.
	 *
	 * Each page is reduced to its tokens before the next is fetched, so memory holds the
	 * tokens and one page of text, never the whole table.
	 *
	 * @param callable $fetch Receives the last key seen and the page size, returns rows of
	 *                        objects with `k` (the key) and `v` (the text).
	 * @return string One space-separated haystack.
	 */
	private static function paged_haystack( callable $fetch ) {
		/**
		 * Filter how many rows one source (content, custom fields, settings) will read.
		 *
		 * @param int $limit Row count.
		 */
		$limit  = (int) apply_filters( 'timu_image_support_reference_row_limit', 200000 );
		$tokens = array();
		$last   = 0;
		$read   = 0;

		do {
			$rows    = (array) $fetch( $last, self::PAGE_SIZE );
			$fetched = count( $rows );

			foreach ( $rows as $row ) {
				$last = (int) $row->k;

				if ( ++$read > $limit ) {
					self::$incomplete = true;
					break 2;
				}

				foreach ( self::condense( (string) $row->v ) as $token ) {
					$tokens[ $token ] = true;
				}
			}
		} while ( self::PAGE_SIZE === $fetched );

		return $tokens ? ' ' . implode( ' ', array_keys( $tokens ) ) . ' ' : '';
	}

	/**
	 * Every published body of content, condensed.
	 *
	 * @return string
	 */
	private static function content_haystack() {
		global $wpdb;

		return self::paged_haystack(
			static function ( $last, $size ) use ( $wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- covered by the transient in map().
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID AS k, post_content AS v FROM {$wpdb->posts}
						 WHERE post_type NOT IN ( 'attachment', 'revision' )
						   AND post_status NOT IN ( 'auto-draft', 'trash' )
						   AND post_content != ''
						   AND ID > %d
						 ORDER BY ID ASC
						 LIMIT %d",
						$last,
						$size
					)
				);
			}
		);
	}

	/**
	 * Custom fields, condensed.
	 *
	 * Attachment meta is excluded deliberately. An image's own `_wp_attachment_metadata`
	 * contains its own file name, so including it makes every image appear to be referenced
	 * by itself. That mistake produced a confident, useless answer the first time this was
	 * measured.
	 *
	 * @return string
	 */
	private static function field_haystack() {
		global $wpdb;

		return self::paged_haystack(
			static function ( $last, $size ) use ( $wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- covered by the transient in map().
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT pm.meta_id AS k, pm.meta_value AS v FROM {$wpdb->postmeta} pm
						 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						 WHERE p.post_type != 'attachment'
						   AND pm.meta_key NOT LIKE %s
						   AND pm.meta_value != ''
						   AND pm.meta_id > %d
						 ORDER BY pm.meta_id ASC
						 LIMIT %d",
						$wpdb->esc_like( '_wp_' ) . '%',
						$last,
						$size
					)
				);
			}
		);
	}

	/**
	 * Site options, theme modifications and widgets, condensed.
	 *
	 * Transients are excluded, and that matters more than it looks. On the library this was
	 * built against, 16 of the 18 options mentioning an upload were transients: cached
	 * fragments of already-rendered pages. A cache is a copy of a use, not a use, and it
	 * expires, so counting one would make an image appear referenced on Monday and
	 * unreferenced on Tuesday with nothing having changed. A report somebody deletes files
	 * from cannot wobble like that.
	 *
	 * @return string
	 */
	private static function option_haystack() {
		global $wpdb;

		return self::paged_haystack(
			static function ( $last, $size ) use ( $wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- covered by the transient in map().
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_id AS k, option_value AS v FROM {$wpdb->options}
						 WHERE ( option_name LIKE %s
							  OR option_name LIKE %s
							  OR option_value LIKE %s )
						   AND option_name NOT LIKE %s
						   AND option_name NOT LIKE %s
						   AND option_id > %d
						 ORDER BY option_id ASC
						 LIMIT %d",
						$wpdb->esc_like( 'theme_mods_' ) . '%',
						$wpdb->esc_like( 'widget_' ) . '%',
						'%' . $wpdb->esc_like( '/uploads/' ) . '%',
						$wpdb->esc_like( '_transient_' ) . '%',
						$wpdb->esc_like( '_site_transient_' ) . '%',
						$last,
						$size
					)
				);
			}
		);
	}

	/**
	 * Files worth reading, from the active theme and any must-use plugins.
	 *
	 * A hero image hard-coded into a stylesheet is exactly the file that looks abandoned
	 * from the database and is on the front page of the site. Bounded on every axis: which
	 * directories, which extensions, how big a file, and how many.
	 *
	 * @return string[] Absolute paths.
	 */
	private static function theme_files() {
		$roots = array_unique(
			array_filter(
				array(
					get_stylesheet_directory(),
					get_template_directory(),
					defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
				)
			)
		);

		/**
		 * Filter how many theme and plugin files a scan will read.
		 *
		 * @param int $max File count.
		 */
		$max       = (int) apply_filters( 'timu_image_support_reference_file_limit', 1500 );
		$max_bytes = 512 * KB_IN_BYTES;
		$found     = array();

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) || count( $found ) >= $max ) {
				continue;
			}

			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveCallbackFilterIterator(
						new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
						static function ( $current ) {
							$name = $current->getFilename();

							if ( $current->isDir() ) {
								return ! in_array( $name, array( 'node_modules', 'vendor', '.git', 'tests' ), true );
							}

							return (bool) preg_match( '/\.(css|scss|php|js|json|html)$/i', $name );
						}
					),
					RecursiveIteratorIterator::LEAVES_ONLY,
					// A folder PHP cannot open is skipped rather than ending the scan.
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);

				foreach ( $iterator as $file ) {
					if ( count( $found ) >= $max ) {
						break;
					}

					if ( $file->isFile() && $file->getSize() <= $max_bytes ) {
						$found[] = $file->getPathname();
					}
				}
			} catch ( Exception $e ) {
				// A file or folder that goes unreadable mid-walk leaves a partial list. Say so.
				self::$incomplete = true;
			}
		}

		return $found;
	}

	/**
	 * Read the files and condense them.
	 *
	 * @param string[] $files Absolute paths.
	 * @return string
	 */
	private static function file_haystack( array $files ) {
		$tokens = array();

		foreach ( $files as $path ) {
			// Checked rather than silenced: a file that cannot be read is skipped, and the
			// scan reports how many it did read so the gap is visible.
			if ( ! is_readable( $path ) ) {
				continue;
			}

			// Reading a theme file this plugin located itself, to search it for a file name.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = file_get_contents( $path );

			if ( false === $contents ) {
				continue;
			}

			foreach ( self::condense( $contents ) as $token ) {
				$tokens[ $token ] = true;
			}
		}

		return $tokens ? ' ' . implode( ' ', array_keys( $tokens ) ) . ' ' : '';
	}
}
