<?php
/**
 * A dated picture of the image library, and a short history of those pictures.
 *
 * Everything the rest of the plugin reports answers "what is wrong right now". This
 * answers two different questions: what is this library, and is it getting better. The
 * second one needs the plugin to remember, which makes this the only part that keeps
 * anything beyond a cache.
 *
 * One snapshot per day, written when somebody opens the page rather than on a schedule.
 * That means the history has gaps on days nobody looked, which is worth saying out loud
 * rather than pretending to a completeness it does not have. It also means the plugin runs
 * nothing in the background, which is a promise worth more than a tidier graph.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Measures the library, records the measurement, and hands back the history.
 */
final class TIMU_Image_Support_Library_Report {

	/**
	 * Option holding the dated history. Never autoloaded.
	 */
	const OPTION = 'timu_image_history';

	/**
	 * Cached live measurement.
	 */
	const CACHE_KEY = 'timu_image_library_report';

	/**
	 * How long a measurement stays good for. It walks the uploads folder.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * How many days of history to keep.
	 */
	const KEEP_DAYS = 365;

	/**
	 * How many file names to keep per untracked folder.
	 *
	 * Enough to see what a folder holds, few enough that the cached report stays small on a
	 * site with tens of thousands of stray files.
	 */
	const NAMES_PER_FOLDER = 25;

	/**
	 * The numbers carried in every snapshot, and how to label them.
	 *
	 * Declared once because the figures, the history table and the exported file all have
	 * to agree about what a column means.
	 *
	 * @return array<string,string>
	 */
	public static function metrics() {
		return array(
			'images'           => __( 'Images', 'thisismyurl-image-support' ),
			'missing_alt'      => __( 'Missing alt text', 'thisismyurl-image-support' ),
			'oversized'        => __( 'Oversized originals', 'thisismyurl-image-support' ),
			'large_files'      => __( 'Large image files', 'thisismyurl-image-support' ),
			'missing_files'    => __( 'Images with no file', 'thisismyurl-image-support' ),
			'untracked_images' => __( 'Untracked image files', 'thisismyurl-image-support' ),
			'untracked_bytes'  => __( 'Untracked size in bytes', 'thisismyurl-image-support' ),
			'originals_bytes'  => __( 'Originals in bytes', 'thisismyurl-image-support' ),
			'generated_bytes'  => __( 'Generated sizes in bytes', 'thisismyurl-image-support' ),
			'duplicate_extra'  => __( 'Redundant duplicate copies', 'thisismyurl-image-support' ),
			'no_uploader'      => __( 'Images with no recorded uploader', 'thisismyurl-image-support' ),
		);
	}

	/**
	 * Drop the cached measurement.
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * The current picture, from cache when it can be.
	 *
	 * @param bool $force Rebuild regardless.
	 * @return array
	 */
	public static function current( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) && isset( $cached['metrics'] ) ) {
				// Recording happens here too, not only on a fresh measurement. The
				// measurement is cached for an hour and a reading is kept per day, so tying
				// the two together meant a warm cache could swallow the day's reading
				// entirely and leave the history empty while the page looked fine.
				self::record( $cached );

				return $cached;
			}
		}

		$report = self::measure();

		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );
		self::record( $report );

		return $report;
	}

	/**
	 * Keep today's numbers, once a day.
	 *
	 * @param array $report Measurement.
	 */
	private static function record( array $report ) {
		// A reading taken from part of the library would put a false step in the trend line.
		if ( ! empty( $report['detail']['library_capped'] ) ) {
			return;
		}

		$today   = gmdate( 'Y-m-d' );
		$history = self::history();

		if ( isset( $history[ $today ] ) ) {
			return;
		}

		$history[ $today ] = $report['metrics'];

		if ( count( $history ) > self::KEEP_DAYS ) {
			ksort( $history );
			$history = array_slice( $history, -self::KEEP_DAYS, null, true );
		}

		update_option( self::OPTION, $history, false );
	}

	/**
	 * Every snapshot, oldest first.
	 *
	 * @param string $from Optional YYYY-MM-DD lower bound, inclusive.
	 * @param string $to   Optional YYYY-MM-DD upper bound, inclusive.
	 * @return array<string,array<string,int>>
	 */
	public static function history( $from = '', $to = '' ) {
		$history = get_option( self::OPTION, array() );

		if ( ! is_array( $history ) ) {
			return array();
		}

		ksort( $history );

		if ( '' === $from && '' === $to ) {
			return $history;
		}

		return array_filter(
			$history,
			static function ( $date ) use ( $from, $to ) {
				if ( '' !== $from && $date < $from ) {
					return false;
				}

				return ! ( '' !== $to && $date > $to );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Measure the library.
	 *
	 * @return array
	 */
	private static function measure() {
		global $wpdb;

		$counts = TIMU_Image_Support_Admin_Page::public_counts();

		$upload = wp_upload_dir();
		$base   = trailingslashit( str_replace( DIRECTORY_SEPARATOR, '/', $upload['basedir'] ) );

		$scan      = self::scan_attachments();
		$known     = $scan['known'];
		$originals = $scan['originals'];
		$generated = $scan['generated'];
		$subsizes  = $scan['subsizes'];

		$duplicate_groups = 0;
		$duplicate_extra  = 0;

		foreach ( $scan['signature'] as $copies ) {
			if ( $copies > 1 ) {
				++$duplicate_groups;
				$duplicate_extra += $copies - 1;
			}
		}

		// A measurement that stopped at its limit does not know about the newest attachments, so
		// every file they own would look untracked. Say nothing rather than say something false.
		$untracked = $scan['capped']
			? array(
				'files'  => 0,
				'bytes'  => 0,
				'by_dir' => array(),
				'capped' => true,
			)
			: self::untracked( $base, $known );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the whole report is cached by current().
		$no_uploader = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND post_author = 0",
				$wpdb->esc_like( 'image/' ) . '%'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
		$formats = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_mime_type AS mime, COUNT(*) AS total FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND post_mime_type LIKE %s
				 GROUP BY post_mime_type ORDER BY total DESC",
				$wpdb->esc_like( 'image/' ) . '%'
			)
		);

		$by_format = array();

		foreach ( $formats as $row ) {
			$by_format[ str_replace( 'image/', '', $row->mime ) ] = (int) $row->total;
		}

		$registered = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();

		return array(
			'generated' => time(),
			'metrics'   => array(
				'images'           => (int) $counts['total'],
				'missing_alt'      => (int) $counts['missing_alt'],
				'oversized'        => (int) $counts['oversized'],
				'large_files'      => (int) $counts['heavy'],
				'missing_files'    => (int) $counts['broken'],
				'untracked_images' => $untracked['files'],
				'untracked_bytes'  => $untracked['bytes'],
				'originals_bytes'  => $originals,
				'generated_bytes'  => $generated,
				'duplicate_extra'  => $duplicate_extra,
				'no_uploader'      => $no_uploader,
			),
			'detail'    => array(
				'duplicate_groups' => $duplicate_groups,
				'subsize_files'    => $subsizes,
				'registered_sizes' => count( $registered ),
				'formats'          => $by_format,
				'untracked_by_dir' => $untracked['by_dir'],
				'untracked_capped' => $untracked['capped'],
				'library_capped'   => $scan['capped'],
				'library_limit'    => $scan['limit'],
				'unmeasured'       => $scan['unmeasured'],
				// Core's documented default, reported rather than resolved. Asking for the
				// real number means firing `big_image_size_threshold`, and there is no
				// upload in progress to fire it about: every argument would have to be
				// invented. Any plugin listening on that filter would be handed a fake file
				// and a fake size and asked to answer as though it were real. So this
				// reports the default and says separately whether something is changing it.
				'scaling'          => 2560,
				'scaling_filtered' => (bool) has_filter( 'big_image_size_threshold' ),
				'yearmonth'        => (bool) get_option( 'uploads_use_yearmonth_folders' ),
			),
		);
	}

	/**
	 * Walk every attachment in batches, keeping only what the report needs.
	 *
	 * One query per batch reads the attachment's file, its metadata and its edit backups
	 * together, so the cost grows with the number of batches rather than with a database
	 * call and several disk checks per image. What is kept per image is a short file name for
	 * each file it owns, so memory grows with the number of files, at roughly 100 bytes each,
	 * and the default limit is set with that in mind. File sizes come from the sizes
	 * WordPress recorded when it made each file, which is why this never has to touch the
	 * disk for them.
	 *
	 * Every attachment is read, not only images, because a PDF's preview pictures and an
	 * edited image's backups are files that live in the uploads folder and are accounted for.
	 *
	 * @return array{known:array<string,bool>,originals:int,generated:int,subsizes:int,signature:array<string,int>,capped:bool}
	 */
	private static function scan_attachments() {
		global $wpdb;

		/**
		 * Filter how many attachments the library measurement will read.
		 *
		 * @param int $limit Attachment count.
		 */
		$limit = (int) apply_filters( 'timu_image_support_library_scan_limit', 50000 );
		$batch = 500;
		$seen  = 0;
		$last  = 0;

		$out = array(
			'known'      => array(),
			'originals'  => 0,
			'generated'  => 0,
			'subsizes'   => 0,
			'signature'  => array(),
			'capped'     => false,
			'limit'      => $limit,
			'unmeasured' => 0,
		);

		$basedir = trailingslashit( str_replace( DIRECTORY_SEPARATOR, '/', wp_upload_dir()['basedir'] ) );
		$prev    = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the whole report is cached by current().
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_mime_type AS mime, f.meta_value AS attached, m.meta_value AS metadata, b.meta_value AS backups, s.meta_value AS indexed
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} f ON ( f.post_id = p.ID AND f.meta_key = %s )
					 LEFT JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = %s )
					 LEFT JOIN {$wpdb->postmeta} b ON ( b.post_id = p.ID AND b.meta_key = %s )
					 LEFT JOIN {$wpdb->postmeta} s ON ( s.post_id = p.ID AND s.meta_key = %s )
					 WHERE p.post_type = 'attachment' AND p.ID > %d
					 ORDER BY p.ID ASC
					 LIMIT %d",
					'_wp_attached_file',
					'_wp_attachment_metadata',
					'_wp_attachment_backup_sizes',
					'_timu_image_filesize',
					$last,
					$batch
				)
			);

			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$last = (int) $row->ID;

				// An attachment with duplicate meta rows comes back once per combination. Rows are in
				// ID order, so repeats sit together and only the first is counted.
				if ( $last === $prev ) {
					continue;
				}

				$prev = $last;

				if ( ++$seen > $limit ) {
					$out['capped'] = true;
					break 2;
				}

				$file = (string) $row->attached;

				// Very old sites recorded an absolute path. Make it relative to uploads like the rest.
				if ( 0 === strpos( str_replace( '\\', '/', $file ), $basedir ) ) {
					$file = substr( str_replace( '\\', '/', $file ), strlen( $basedir ) );
				}
				$meta = maybe_unserialize( $row->metadata );
				$meta = is_array( $meta ) ? $meta : array();

				if ( '' !== $file ) {
					$out['known'][ strtolower( $file ) ] = true;
					$dir                                 = dirname( $file );
					$prefix                              = ( '.' === $dir ) ? '' : $dir . '/';

					foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
						if ( empty( $size['file'] ) ) {
							continue;
						}

						$out['known'][ strtolower( $prefix . $size['file'] ) ] = true;

						if ( ! empty( $size['filesize'] ) ) {
							$out['generated'] += (int) $size['filesize'];
							++$out['subsizes'];
						}
					}

					if ( ! empty( $meta['original_image'] ) ) {
						$out['known'][ strtolower( $prefix . $meta['original_image'] ) ] = true;
					}

					$backups = maybe_unserialize( $row->backups );

					foreach ( is_array( $backups ) ? $backups : array() as $backup ) {
						if ( ! empty( $backup['file'] ) ) {
							$out['known'][ strtolower( $prefix . $backup['file'] ) ] = true;
						}
					}
				}

				if ( 0 !== strpos( (string) $row->mime, 'image/' ) ) {
					continue;
				}

				$bytes = ! empty( $meta['filesize'] ) ? (int) $meta['filesize'] : (int) $row->indexed;

				if ( $bytes > 0 ) {
					$out['originals'] += $bytes;
				} else {
					++$out['unmeasured'];
				}

				// Two images of identical pixel dimensions and identical byte count are the same
				// picture in all but name often enough to be worth mentioning. It is a signature,
				// not a hash, so it is offered as "likely" and never as certain.
				if ( ! empty( $meta['width'] ) && $bytes > 0 ) {
					$key = $meta['width'] . 'x' . ( $meta['height'] ?? 0 ) . ':' . $bytes;

					$out['signature'][ $key ] = ( $out['signature'][ $key ] ?? 0 ) + 1;
				}
			}
		} while ( $batch === $fetched );

		return $out;
	}

	/**
	 * Image files in the uploads folder that no attachment accounts for.
	 *
	 * Images only. The uploads folder is a dumping ground for PDFs, exports, backups and
	 * whatever else a plugin decided to keep there, and none of that is this plugin's
	 * business to comment on.
	 *
	 * Grouped by folder rather than listed by file, and that is the whole design. Some of
	 * these are generated sizes left behind when a theme changed the registered sizes, and
	 * their parent image is alive and well. Some belong to a plugin that manages its own
	 * directory. A list of file names invites deletion; a count per folder invites looking.
	 *
	 * @param string $base  Uploads base directory, slash-normalised and trailing-slashed.
	 * @param array  $known Map of known relative paths, lowercased.
	 * @return array{files:int,bytes:int,by_dir:array<string,array{count:int,bytes:int,names:string[]}>,capped:bool}
	 */
	private static function untracked( $base, array $known ) {
		$result = array(
			'files'  => 0,
			'bytes'  => 0,
			'by_dir' => array(),
			'capped' => false,
		);

		if ( ! is_dir( $base ) ) {
			return $result;
		}

		/**
		 * Filter how many files the untracked scan will look at.
		 *
		 * @param int $limit File count.
		 */
		$limit = (int) apply_filters( 'timu_image_support_untracked_scan_limit', 50000 );
		$seen  = 0;

		/**
		 * Filter the top-level uploads folders the untracked scan leaves alone.
		 *
		 * These belong to plugins that keep private files in uploads and rely on a hard-to-guess
		 * folder name or a server rule to protect them. Listing what is inside would be the
		 * wrong thing to do even for an administrator.
		 *
		 * @param string[] $folders Folder names, lowercase.
		 */
		$skip = (array) apply_filters(
			'timu_image_support_untracked_skip_folders',
			array( 'gravity_forms', 'wpforms', 'woocommerce_uploads', 'edd', 'give', 'wc-logs', 'wpcf7_uploads', 'ninja-forms', 'formidable', 'fluentform', 'forminator' )
		);

		// On the main site of a network every other site's uploads live in a folder inside this
		// one. They belong to those sites, and their private folders are no business of this one.
		if ( is_multisite() && is_main_site() ) {
			$skip[] = 'sites';
		}

		$skip = array_map( 'strtolower', $skip );

		try {
			// CATCH_GET_CHILD makes a folder PHP cannot open (a backup plugin's 0700 folder, or
			// one owned by another user) a folder that is skipped, not an uncaught exception
			// that takes the whole page down.
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
					static function ( $current, $key, $inner ) use ( $skip ) {
						// Top-level folders on the skip list are never opened, so they use up none of
						// the file limit and none of the disk reads.
						return ! ( $current->isDir() && '' === $inner->getSubPath() && in_array( strtolower( $current->getFilename() ), $skip, true ) );
					}
				),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				if ( ++$seen > $limit ) {
					$result['capped'] = true;
					break;
				}

				$path    = str_replace( DIRECTORY_SEPARATOR, '/', $file->getPathname() );
				$display = substr( $path, strlen( $base ) );
				$rel     = strtolower( $display );

				if ( ! preg_match( '/[.](jpe?g|png|gif|webp|avif|svg)$/', $rel ) || isset( $known[ $rel ] ) ) {
					continue;
				}

				$parts = explode( '/', $rel );

				$folder = count( $parts ) > 1 ? implode( '/', array_slice( $parts, 0, min( 2, count( $parts ) - 1 ) ) ) : '/';
				$bytes  = (int) $file->getSize();

				++$result['files'];
				$result['bytes'] += $bytes;

				if ( ! isset( $result['by_dir'][ $folder ] ) ) {
					$result['by_dir'][ $folder ] = array(
						'count' => 0,
						'bytes' => 0,
						'names' => array(),
					);
				}

				++$result['by_dir'][ $folder ]['count'];
				$result['by_dir'][ $folder ]['bytes'] += $bytes;

				// A bounded sample of names per folder, so somebody can see what is actually in
				// there without the cached report growing without limit on a large site. The
				// original capitalisation is kept: on Linux a link to the lowercased name is a 404.
				if ( count( $result['by_dir'][ $folder ]['names'] ) < self::NAMES_PER_FOLDER ) {
					$result['by_dir'][ $folder ]['names'][] = $display;
				}
			}
		} catch ( Exception $e ) {
			// A file that goes unreadable part-way through leaves a partial result, reported as
			// capped so the page does not claim a complete count.
			$result['capped'] = true;
		}

		uasort(
			$result['by_dir'],
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		return $result;
	}
}
