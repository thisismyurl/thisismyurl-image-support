<?php
/**
 * The overview screen: what this plugin does, what it found on this site, and the two
 * numbers worth letting somebody change.
 *
 * This screen exists because of a real failure. The plugin adds columns to the Media list
 * and nothing else, so a person who activated it and went looking for it found nothing at
 * all. Worse, WordPress defaults the Media screen to grid view, where none of the columns
 * render. The most likely first experience of a working plugin was "this did nothing."
 *
 * So the page leads with the way in, reports what it actually found in this library rather
 * than describing features in the abstract, and says plainly what the plugin will not do.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media > Image Support: overview, live counts, and settings.
 */
final class TIMU_Image_Support_Admin_Page {

	/**
	 * Menu/page slug.
	 */
	const SLUG = 'timu-image-support';

	/**
	 * Settings group.
	 */
	const GROUP = 'timu_image_support_settings';

	/**
	 * The admin-post action name for the CSV download.
	 */
	const EXPORT_ACTION = 'timu_image_export_history';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export_history' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Keep the saved results honest whenever an attachment changes.
	 *
	 * Registered on every request, not only inside wp-admin, because attachments also change
	 * through the REST API, WP-CLI and scheduled jobs. The callbacks only delete a cached
	 * value, so registering them costs a few array entries and nothing else.
	 */
	public static function init_cache_hooks() {
		// The counts are cached for five minutes to keep three found_posts queries off
		// every page load, which matters on a large library. Anything that could change a
		// count clears it, so somebody who fixes an alt text and comes back does not read
		// a stale number and think the save failed.
		//
		// Writing alt text changes only the counts, so that one clears the counts alone.
		add_action( 'timu_image_support_after_write', array( __CLASS__, 'flush_counts' ) );
		add_action( 'wp_update_attachment_metadata', array( __CLASS__, 'flush_counts' ) );

		// Adding or removing an image changes what the PHP-side scans would find, so those
		// caches have to go too. Clearing only the counts was a real bug: the counts were
		// recomputed honestly, from a stale list of IDs, and the wrong answer was then
		// cached again. Somebody who deleted two broken images was still told there were
		// two. `deleted_post` is belt and braces, because `delete_attachment` fires before
		// the row is actually gone and anything recomputing in between would re-cache it.
		foreach ( array( 'add_attachment', 'delete_attachment' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_all_caches' ) );
		}

		add_action( 'deleted_post', array( __CLASS__, 'flush_after_delete' ), 10, 2 );

		// Raising or lowering a threshold changes which images qualify.
		//
		// Both `add_option_` and `update_option_` are needed, and missing that was a real
		// bug caught in testing: until somebody saves this screen the options do not exist
		// in the database at all, so the FIRST save goes through add_option() and fires
		// `add_option_*`. Hooking only `update_option_*` meant the first save silently left
		// stale counts on the page that reported it.
		foreach ( array( 'add_option_', 'update_option_' ) as $prefix ) {
			add_action( $prefix . TIMU_Image_Support_Facts::OPTION_HEAVY_MB, array( __CLASS__, 'flush_counts' ) );
			add_action( $prefix . TIMU_Image_Support_Facts::OPTION_OVERSIZE, array( __CLASS__, 'flush_all_caches' ) );
		}
	}

	/**
	 * Drop the cached counts.
	 *
	 * Hooked to filters as well as actions, so it returns its first argument untouched.
	 *
	 * @param mixed $passthrough Value to return unchanged.
	 * @return mixed
	 */
	public static function flush_counts( $passthrough = null ) {
		delete_transient( 'timu_image_overview_counts' );

		return $passthrough;
	}

	/**
	 * Drop the counts and the oversize list.
	 *
	 * Changing the oversize multiplier invalidates the cached ID list too, not just the
	 * count derived from it.
	 *
	 * @param mixed $passthrough Value to return unchanged.
	 * @return mixed
	 */
	public static function flush_all_caches( $passthrough = null ) {
		TIMU_Image_Support_Media_Filters::flush_scans();
		TIMU_Image_Support_Library_Report::flush();

		return self::flush_counts( $passthrough );
	}

	/**
	 * Clear the caches once an attachment has actually gone.
	 *
	 * @param int          $post_id Deleted post ID.
	 * @param WP_Post|null $post    The post that was deleted.
	 */
	public static function flush_after_delete( $post_id, $post = null ) {
		if ( $post instanceof WP_Post && 'attachment' !== $post->post_type ) {
			return;
		}

		self::flush_all_caches();
	}

	/**
	 * Add the page under Media.
	 *
	 * Under Media rather than Tools because everything the plugin does happens on the
	 * Media list screen, and a person looking for it will look where the images are.
	 *
	 * The capability is `upload_files`, not `manage_options`: the instructions are for
	 * whoever works through the library, which is usually not an administrator. The
	 * settings section inside the page is gated separately.
	 */
	public static function add_page() {
		add_submenu_page(
			'upload.php',
			__( 'Image Support', 'thisismyurl-image-support' ),
			__( 'Image Support', 'thisismyurl-image-support' ),
			'upload_files',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the two settings.
	 *
	 * Only two of the plugin's five tunables are here. The others stay developer filters
	 * on purpose: the widest-served width is derived from the sizes the site actually
	 * registers and a hand-typed value would just be wrong, and the MIME list and scan
	 * limit are not decisions a site owner has any reason to make.
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			TIMU_Image_Support_Facts::OPTION_OVERSIZE,
			array(
				'type'              => 'number',
				'sanitize_callback' => array( __CLASS__, 'sanitize_threshold' ),
				'default'           => TIMU_Image_Support_Facts::DEFAULT_OVERSIZE,
			)
		);

		register_setting(
			self::GROUP,
			TIMU_Image_Support_Facts::OPTION_HEAVY_MB,
			array(
				'type'              => 'number',
				'sanitize_callback' => array( __CLASS__, 'sanitize_heavy_mb' ),
				'default'           => TIMU_Image_Support_Facts::DEFAULT_HEAVY_MB,
			)
		);
	}

	/**
	 * Clamp the oversize multiplier to a range where it can still mean something.
	 *
	 * Below 1.3 it would flag ordinary uploads, because core scales uploads to 2560px
	 * against a 2048px yardstick and healthy images already sit at about 1.25.
	 *
	 * @param mixed $value Submitted value.
	 * @return float
	 */
	public static function sanitize_threshold( $value ) {
		$value = (float) $value;

		if ( $value < 1.3 ) {
			$value = 1.3;
		}

		if ( $value > 10.0 ) {
			$value = 10.0;
		}

		return round( $value, 2 );
	}

	/**
	 * Clamp the heavy-file threshold, in megabytes.
	 *
	 * @param mixed $value Submitted value.
	 * @return float
	 */
	public static function sanitize_heavy_mb( $value ) {
		$value = (float) $value;

		if ( $value < 0.1 ) {
			$value = 0.1;
		}

		if ( $value > 100.0 ) {
			$value = 100.0;
		}

		return round( $value, 2 );
	}

	/**
	 * How large a share of the library has to be affected before a finding is urgent.
	 */
	const ATTENTION_SHARE = 0.1;

	/**
	 * How many images have to be affected before a finding can be urgent at all.
	 *
	 * A share on its own breaks at both ends. One image missing alt text in a library of
	 * three is a third of the library and a minute of work, which does not deserve a red
	 * badge; four hundred missing out of five thousand is eight per cent and a genuine
	 * backlog, which does. So the urgent state needs both a share and a real number.
	 */
	const ATTENTION_FLOOR = 10;

	/**
	 * A number of affected images that is a real backlog whatever the library size.
	 *
	 * The share alone fails at the large end the way the floor fixes the small end: four
	 * hundred images without alt text in a library of five thousand is eight per cent, which
	 * would read as a minor note, and it is several days of writing. Past this many, the
	 * proportion stops being the interesting number.
	 */
	const ATTENTION_BACKLOG = 50;

	/**
	 * Which state a count is in.
	 *
	 * Only used for the two cards that describe a real problem. Applying it to a count
	 * measured against a threshold the site owner chose themselves would put a moral charge
	 * on their own preference.
	 *
	 * @param int $count Affected images.
	 * @param int $total Images in the library.
	 * @return string One of settled, look, attention.
	 */
	private static function state( $count, $total ) {
		if ( $count <= 0 ) {
			return 'settled';
		}

		/**
		 * Filter the number of affected images below which nothing is ever urgent.
		 *
		 * @param int $floor Image count.
		 */
		$floor = (int) apply_filters( 'timu_image_support_attention_floor', self::ATTENTION_FLOOR );

		if ( $count < $floor ) {
			return 'look';
		}

		$big_share   = $total > 0 && ( $count / $total ) > self::ATTENTION_SHARE;
		$big_backlog = $count >= self::ATTENTION_BACKLOG;

		return ( $big_share || $big_backlog ) ? 'attention' : 'look';
	}

	/**
	 * A status pill: an icon shape, a word, and a colour.
	 *
	 * Three channels, never colour alone. The icon is decorative reinforcement of the word
	 * beside it, so it is hidden from assistive technology rather than announced twice.
	 *
	 * @param string $state State key.
	 * @param string $label Human wording for this card's state.
	 */
	private static function status_pill( $state, $label ) {
		$paths = array(
			'settled'   => 'M2 6.2 L4.8 9 L10 3',
			'look'      => 'M1 6 C3 2.6 9 2.6 11 6 C9 9.4 3 9.4 1 6 Z M6 4.6 A1.4 1.4 0 1 0 6 7.4 A1.4 1.4 0 1 0 6 4.6',
			'attention' => 'M6 1.6 L11.2 10.4 L0.8 10.4 Z M6 4.6 L6 7.2 M6 8.6 L6 9',
			'problem'   => 'M6 1.6 L11.2 10.4 L0.8 10.4 Z M6 4.6 L6 7.2 M6 8.6 L6 9',

			// Grey, and shaped like an information mark rather than a warning, because this
			// one reports a fact instead of passing a verdict.
			'noted'     => 'M6 1.4 A4.6 4.6 0 1 0 6.01 1.4 M6 5.6 L6 8.6 M6 3.4 L6 3.7',
		);

		if ( ! isset( $paths[ $state ] ) ) {
			return;
		}

		printf(
			'<span class="timu-status is-%1$s">'
			. '<svg class="timu-status-icon" viewBox="0 0 12 12" aria-hidden="true" focusable="false">'
			. '<path d="%2$s" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />'
			. '</svg>%3$s</span>',
			esc_attr( $state ),
			esc_attr( $paths[ $state ] ),
			esc_html( $label )
		);
	}

	/**
	 * URL of the Media list, in list mode.
	 *
	 * `mode=list` is deliberate. Core defaults the Media screen to grid, and in grid view
	 * this plugin renders nothing whatsoever, because there is no table to add columns to.
	 * Sending people to the bare screen is how the plugin came to look broken.
	 *
	 * @param string $filter Optional filter value to pre-apply.
	 * @return string
	 */
	private static function list_url( $filter = '' ) {
		// Core's own media-type filter, so the list is narrowed to images and core's
		// dropdown shows that it has been. Without it the "Images" figure counted 768 and
		// the link beside it opened a list of 829, because every document, audio file and
		// PDF came along. A number and the link under it have to agree.
		$args = array(
			'mode'              => 'list',
			'attachment-filter' => 'post_mime_type:image',
		);

		if ( '' !== $filter ) {
			$args[ TIMU_Image_Support_Media_Filters::QUERY_VAR ] = $filter;
		}

		return add_query_arg( $args, admin_url( 'upload.php' ) );
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see this page. Please ask the person who looks after your website.', 'thisismyurl-image-support' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch, validated below.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overview';

		if ( ! in_array( $view, array( 'overview', 'references', 'settings' ), true ) ) {
			$view = 'overview';
		}

		echo '<div class="wrap timu-overview timu-ui">';

		self::render_header( $view );
		echo '<hr class="wp-header-end">';
		self::render_tabs( $view );

		if ( 'references' === $view ) {
			self::render_references();
		} elseif ( 'settings' === $view ) {
			self::render_settings();
		} else {
			$counts = self::counts();

			self::render_counts( $counts );
			self::render_about();
			self::render_limits();
			self::render_developer_notes();
			self::render_support();
		}

		self::render_footer();

		echo '</div>';
	}

	/**
	 * Page header: what this is, and the way in.
	 *
	 * The action sits in the header rather than buried in a panel, because getting to the
	 * list view is the single thing somebody arriving here most needs to do.
	 */
	private static function render_header( $view = 'overview' ) {
		echo '<header class="timu-ui-head">';

		echo '<div>';
		printf( '<h1>%s</h1>', esc_html__( 'Image Support', 'thisismyurl-image-support' ) );
		printf(
			'<p class="timu-ui-lede">%s</p>',
			esc_html__( 'Shows you which pictures need a little attention. It only looks, and never changes your pictures.', 'thisismyurl-image-support' )
		);
		echo '</div>';

		echo '<div class="timu-ui-head-actions">';
		printf(
			'<a class="button button-primary" href="%s">%s</a>',
			esc_url( self::list_url() ),
			esc_html__( 'Open your pictures', 'thisismyurl-image-support' )
		);
		echo '</div>';

		echo '</header>';

		// The list-view hint matters to someone arriving for the first time, so it is shown on the overview only, and only until
		// WordPress has been switched to the list view.
		if ( 'overview' === $view && 'list' !== get_user_option( 'media_library_mode' ) ) {
			echo '<div class="timu-ui-assure timu-ui-assure--note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div><p><strong>'
				. esc_html__( 'Switch to the list view first.', 'thisismyurl-image-support' ) . '</strong> '
				. esc_html__( 'WordPress shows your pictures as a grid of small squares, and a grid has no room for the extra details this tool adds. The button above switches you to the list view, and WordPress remembers your choice.', 'thisismyurl-image-support' )
				. '</p></div></div>';
		}
	}

	/**
	 * Footer: the one thing that is always true here, and a quiet link to the sister plugin.
	 */
	private static function render_footer() {
		echo '<p class="timu-ui-foot"><span class="dashicons dashicons-shield" aria-hidden="true"></span><span>'
			. esc_html__( 'This page only looks. The only thing it ever changes is a description you type yourself.', 'thisismyurl-image-support' )
			. '</span><span class="timu-ui-foot-links">';

		if ( defined( 'TIMU_FORMATS_VERSION' ) ) {
			printf(
				'<span class="timu-ui-switch"><a href="%1$s" aria-current="page">%2$s</a><a href="%3$s">%4$s</a></span>',
				esc_url( self::page_url() ),
				esc_html__( 'Image Support', 'thisismyurl-image-support' ),
				esc_url( admin_url( 'upload.php?page=thisismyurl-image-formats' ) ),
				esc_html__( 'Image Formats', 'thisismyurl-image-support' )
			);
		}

		echo '</span></p>';
	}

	/**
	 * The page's own URL, for a given view.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	private static function page_url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::SLUG ), $args ),
			admin_url( 'upload.php' )
		);
	}

	/**
	 * Tabs across the top.
	 *
	 * @param string $current Active view.
	 */
	private static function render_tabs( $current ) {
		$tabs = array(
			'overview'   => __( 'Overview', 'thisismyurl-image-support' ),
			'references' => __( 'Where pictures are used', 'thisismyurl-image-support' ),
			'settings'   => __( 'Settings', 'thisismyurl-image-support' ),
		);

		echo '<nav class="nav-tab-wrapper timu-ui-tabs" aria-label="' . esc_attr__( 'Image Support sections', 'thisismyurl-image-support' ) . '">';

		foreach ( $tabs as $view => $label ) {
			printf(
				'<a class="nav-tab%s" href="%s"%s>%s</a>',
				$view === $current ? ' nav-tab-active' : '',
				esc_url( self::page_url( 'overview' === $view ? array() : array( 'view' => $view ) ) ),
				$view === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * A section heading with its one-line summary, sitting above the card rather than
	 * inside it. The rhythm of heading, summary, card is what stops a page of cards
	 * reading as a stack of identical boxes.
	 *
	 * @param string $title   Section heading.
	 * @param string $summary One line under it.
	 */
	private static function section_head( $title, $summary ) {
		echo '<div class="timu-sec-head">';
		printf( '<h2>%s</h2>', esc_html( $title ) );
		printf( '<p>%s</p>', esc_html( $summary ) );
		echo '</div>';
	}

	/**
	 * What the plugin found in this library, with a way into each set.
	 *
	 * Live counts rather than a feature list. "Missing alt text" means nothing until it
	 * says how many, on this site, right now, at which point it becomes a job with an end.
	 *
	 * @param array $counts Count data.
	 */
	private static function render_counts( array $counts ) {
		echo '<div class="timu-sec">';
		self::section_head(
			__( 'Your pictures right now', 'thisismyurl-image-support' ),
			__( 'These numbers were counted from your own pictures just now. Documents, sound files and videos are not counted.', 'thisismyurl-image-support' )
		);

		$total = $counts['total'];

		// Two of these four describe a problem worth acting on, and only those two carry a
		// status. The library total is the denominator rather than a finding. Large files
		// are measured against a size the site owner set themselves further down this page,
		// so calling that number urgent would be scolding somebody for their own setting.
		$rows = array(
			array(
				'label'  => __( 'Pictures', 'thisismyurl-image-support' ),
				'count'  => $total,
				'filter' => '',
				'help'   => __( 'All the pictures on your website. Documents, sound files and videos are left out.', 'thisismyurl-image-support' ),
				'state'  => '',
			),
			array(
				'label'  => __( 'Pictures with no description', 'thisismyurl-image-support' ),
				'count'  => $counts['missing_alt'],
				'filter' => 'missing-alt',
				'help'   => __( 'A description (called alt text) tells people who cannot see a picture what it shows. These pictures do not have one yet.', 'thisismyurl-image-support' ),
				'state'  => self::state( $counts['missing_alt'], $total ),
				'words'  => array(
					'settled'   => __( 'Every picture has a description', 'thisismyurl-image-support' ),
					'look'      => __( 'A few still need a description', 'thisismyurl-image-support' ),
					'attention' => __( 'Worth setting aside some time', 'thisismyurl-image-support' ),
				),
			),
			array(
				'label'  => __( 'Pictures much bigger than needed', 'thisismyurl-image-support' ),
				'count'  => $counts['oversized'],
				'filter' => 'oversized',
				'help'   => __( 'These pictures are far bigger than your website ever shows. They take up space and make your backups bigger.', 'thisismyurl-image-support' )
					. ( defined( 'TIMU_FORMATS_VERSION' ) ? ' ' . __( 'Image Formats can make a picture smaller from its own page.', 'thisismyurl-image-support' ) : '' ),
				'state'  => self::state( $counts['oversized'], $total ),
				'words'  => array(
					'settled'   => __( 'Nothing is too big', 'thisismyurl-image-support' ),
					'look'      => __( 'A few to look at', 'thisismyurl-image-support' ),
					'attention' => __( 'Worth tidying up', 'thisismyurl-image-support' ),
				),
			),
			array(
				'label'  => __( 'Pictures that take a lot of space', 'thisismyurl-image-support' ),
				'count'  => $counts['heavy'],
				'filter' => 'heavy',
				/* translators: %s: file size, e.g. "1 MB". */
				'help'   => sprintf( __( 'Pictures with a file bigger than %s, the size you chose below.', 'thisismyurl-image-support' ), size_format( TIMU_Image_Support_Facts::heavy_bytes(), 0 ) ),
				// This card never turns amber or red. The number is measured against a size
				// the site owner chose themselves, so treating it as a fault would be
				// scolding somebody for their own setting. Above zero it says so in grey,
				// which answers the only question the figure raises: is this bad? No.
				'state'  => 0 === $counts['heavy'] ? 'settled' : 'noted',
				'words'  => array(
					'settled' => __( 'None are over your size', 'thisismyurl-image-support' ),
					'noted'   => __( 'Nothing is wrong. They are just large.', 'thisismyurl-image-support' ),
				),
			),
			array(
				'label'  => __( 'Pictures with a missing file', 'thisismyurl-image-support' ),
				'count'  => $counts['broken'],
				'filter' => 'broken',
				'help'   => __( 'The picture is still listed, but its file is no longer there.', 'thisismyurl-image-support' ),
				// Any of these is worth knowing about immediately, so this one skips the
				// share rule: a single missing file is a broken image on a page somewhere.
				'state'  => 0 === $counts['broken'] ? 'settled' : 'problem',
				'words'  => array(
					'settled' => __( 'Every file is where it should be', 'thisismyurl-image-support' ),
					'problem' => __( 'Worth tidying up', 'thisismyurl-image-support' ),
				),
			),
		);

		echo '<div class="timu-cards">';

		foreach ( $rows as $row ) {
			printf( '<div class="timu-card%s">', $row['state'] ? esc_attr( ' has-' . $row['state'] ) : '' );

			printf( '<span class="timu-card-count">%s</span>', esc_html( number_format_i18n( $row['count'] ) ) );

			// Label and status share a row so the number keeps the eye's first stop.
			echo '<span class="timu-card-head">';
			printf( '<span class="timu-card-label">%s</span>', esc_html( $row['label'] ) );

			if ( $row['state'] && isset( $row['words'][ $row['state'] ] ) ) {
				self::status_pill( $row['state'], $row['words'][ $row['state'] ] );
			}

			echo '</span>';

			printf( '<span class="timu-card-help">%s</span>', esc_html( $row['help'] ) );

			if ( $row['count'] > 0 ) {
				printf(
					'<a class="timu-card-link" href="%s">%s</a>',
					esc_url( self::list_url( $row['filter'] ) ),
					esc_html__( 'Show me these', 'thisismyurl-image-support' )
				);
			}

			echo '</div>';
		}

		echo '</div>';

		$scan_cap = (int) apply_filters( 'timu_image_support_oversize_scan_limit', 2000 );

		if ( $total > $scan_cap ) {
			echo '<p class="timu-note timu-note-flag">' . esc_html(
				sprintf(
					/* translators: 1: images checked, 2: images in the library. */
					__( 'To keep this page quick, the checks for pictures that are too big or missing a file looked at your newest %1$s of your %2$s pictures. The real numbers may be a little higher. All the other numbers cover every picture.', 'thisismyurl-image-support' ),
					number_format_i18n( $scan_cap ),
					number_format_i18n( $total )
				)
			) . '</p>';
		}

		if ( isset( $counts['index_complete'] ) && ! $counts['index_complete'] ) {
			echo '<p class="timu-note timu-note-flag">' . esc_html__(
				'Some pictures have not had their file size written down yet, so the number of large files may be a little low for now. It fixes itself as you open the Media list or reload this page.',
				'thisismyurl-image-support'
			) . '</p>';
		}

		// A missing file still counts as an image with no alt text, so it turns up as work
		// to do. Saying so here keeps the alt-text number honest without quietly changing
		// it, and points at the thing actually worth fixing first.
		if ( $counts['broken_no_alt'] > 0 ) {
			printf(
				'<p class="timu-note timu-note-flag">%s <a href="%s">%s</a></p>',
				esc_html(
					sprintf(
						/* translators: 1: number of images, 2: total missing alt text. */
						_n(
							'%1$d of the %2$d pictures with no description has a file that has gone missing. Nobody can describe a picture that is no longer there, so it is best to tidy these up first.',
							'%1$d of the %2$d pictures with no description have files that have gone missing. Nobody can describe a picture that is no longer there, so it is best to tidy these up first.',
							$counts['broken_no_alt'],
							'thisismyurl-image-support'
						),
						$counts['broken_no_alt'],
						$counts['missing_alt']
					)
				),
				esc_url( self::list_url( 'broken' ) ),
				esc_html__( 'Show the pictures with a missing file', 'thisismyurl-image-support' )
			);
		}

		if ( 0 === $counts['oversized'] && $counts['total'] > 0 ) {
			echo '<p class="timu-note">' . esc_html__(
				'Having none that are too big is normal and healthy. WordPress shrinks big pictures when they are added, so this number is usually zero. It only finds pictures that got in some other way.',
				'thisismyurl-image-support'
			) . '</p>';
		}

		// Alt text is the one number here that somebody works through deliberately, so it
		// is the one worth drawing. The others move when images are added or removed rather
		// than when anybody sets out to change them.
		printf( '<h3>%s</h3>', esc_html__( 'Pictures with no description, over time', 'thisismyurl-image-support' ) );
		self::sparkline( 'missing_alt', __( 'Pictures with no description', 'thisismyurl-image-support' ) );
		echo '</div>';
	}

	/**
	 * The oversize scale as inline SVG.
	 *
	 * @param int $widest  Widest served width.
	 * @param int $flag_at Width at which the flag fires.
	 */
	private static function oversize_diagram( $widest, $flag_at ) {
		$scale       = self::diagram_scale( $flag_at );
		$show_normal = self::diagram_shows_normal( $scale );

		$label = str_replace(
			array( '{widest}', '{flag}' ),
			array( $widest, $flag_at ),
			self::diagram_label_template()
		);

		$x = static function ( $px ) use ( $scale ) {
			return round( 60 + ( min( $px, $scale ) / $scale ) * 620, 2 );
		};

		$widest_x = $x( $widest );
		$flag_x   = $x( $flag_at );

		// At the lowest multipliers the two markers sit close enough that centred captions
		// run into each other and read as one wrong word. Rather than drop one, the left
		// caption ends at its own line and the right one starts at its own line, which
		// pushes them apart while keeping each attached to the mark it names.
		$tight        = self::captions_collide( $widest_x, $flag_x );
		$widest_align = $tight ? 'end' : 'middle';
		$flag_align   = $tight ? 'start' : 'middle';
		$widest_cap_x = $tight ? $widest_x - 4 : $widest_x;
		$flag_cap_x   = $tight ? $flag_x + 4 : $flag_x;

		?>
		<svg class="timu-diagram" viewBox="0 0 720 128" role="img"
			aria-label="<?php echo esc_attr( $label ); ?>"
			data-timu-widest="<?php echo esc_attr( $widest ); ?>"
			data-timu-scale="<?php echo esc_attr( $scale ); ?>">
			<title><?php echo esc_html( $label ); ?></title>

			<rect class="js-timu-band-safe" x="60" y="46" width="<?php echo esc_attr( $x( $flag_at ) - 60 ); ?>" height="18" fill="#edf6ee" />
			<rect class="js-timu-band-flag" x="<?php echo esc_attr( $x( $flag_at ) ); ?>" y="46"
				width="<?php echo esc_attr( 680 - $x( $flag_at ) ); ?>" height="18" fill="#fcf0f1" />

			<line x1="60" y1="64" x2="680" y2="64" stroke="#3c434a" stroke-width="1.5" />

			<line class="js-timu-widest-line" x1="<?php echo esc_attr( $x( $widest ) ); ?>" y1="38"
				x2="<?php echo esc_attr( $x( $widest ) ); ?>" y2="72" stroke="#3c434a" stroke-width="1.5" />
			<text class="timu-svg-tick js-timu-widest-tick" x="<?php echo esc_attr( $x( $widest ) ); ?>" y="30" text-anchor="middle">
				<?php echo esc_html( sprintf( '%dpx', $widest ) ); ?>
			</text>
			<text class="timu-svg-cap js-timu-widest-cap" x="<?php echo esc_attr( $widest_cap_x ); ?>" y="90"
				text-anchor="<?php echo esc_attr( $widest_align ); ?>">
				<?php echo esc_html__( 'widest your site shows', 'thisismyurl-image-support' ); ?>
			</text>

			<g class="js-timu-normal"<?php echo $show_normal ? '' : ' hidden'; ?>>
				<line class="js-timu-normal-line" x1="<?php echo esc_attr( $x( 2560 ) ); ?>" y1="44"
					x2="<?php echo esc_attr( $x( 2560 ) ); ?>" y2="66" stroke="#646970" stroke-width="1" stroke-dasharray="2 2" />
				<text class="timu-svg-cap js-timu-normal-cap" x="<?php echo esc_attr( $x( 2560 ) ); ?>" y="108" text-anchor="middle">
					<?php echo esc_html__( 'a normal picture', 'thisismyurl-image-support' ); ?>
				</text>
			</g>

			<line class="js-timu-flag-line" x1="<?php echo esc_attr( $x( $flag_at ) ); ?>" y1="38"
				x2="<?php echo esc_attr( $x( $flag_at ) ); ?>" y2="72" stroke="#d63638" stroke-width="1.5" />
			<text class="timu-svg-tick timu-svg-flagtick js-timu-flag-tick" x="<?php echo esc_attr( $x( $flag_at ) ); ?>" y="30" text-anchor="middle">
				<?php echo esc_html( sprintf( '%dpx', $flag_at ) ); ?>
			</text>
			<text class="timu-svg-cap timu-svg-flagtick js-timu-flag-cap" x="<?php echo esc_attr( $flag_cap_x ); ?>" y="90"
				text-anchor="<?php echo esc_attr( $flag_align ); ?>">
				<?php echo esc_html__( 'marked from here', 'thisismyurl-image-support' ); ?>
			</text>

			<text class="timu-svg-cap" x="60" y="90" text-anchor="start">0</text>
			<text class="timu-svg-cap js-timu-scale-cap" x="680" y="90" text-anchor="end">
				<?php echo esc_html( sprintf( '%dpx', $scale ) ); ?>
			</text>
		</svg>
		<?php
	}

	/**
	 * The spoken description of the diagram, with tokens rather than numbers.
	 *
	 * Tokens instead of printf placeholders because the same sentence is rebuilt in
	 * JavaScript each time the field changes, and a translated string with named tokens
	 * survives that trip in one piece.
	 *
	 * @return string
	 */
	public static function diagram_label_template() {
		/* translators: {widest} and {flag} are pixel widths filled in on the page. Keep both tokens exactly as written. */
		return __( 'A scale of picture widths. Your site shows pictures up to {widest}px wide. Pictures wider than {flag}px are marked as too big.', 'thisismyurl-image-support' );
	}

	/**
	 * The two sample weights the file-size diagram compares, in megabytes.
	 *
	 * A realistic pair: the same photograph saved carelessly and saved well. Mirrored in
	 * assets/overview.js.
	 */
	const SAMPLE_HEAVY_MB = 2.4;

	/**
	 * 320 KB exactly, rather than 0.32 of a megabyte.
	 *
	 * A megabyte is 1024 kilobytes, so 0.32 MB renders as "327.7 KB", which is correct and
	 * looks like a measurement rather than an example. A sample should read as a round
	 * number a person could have chosen.
	 */
	const SAMPLE_LIGHT_MB = 0.3125;

	/**
	 * What a file-size setting looks like, drawn.
	 *
	 * The oversize scale next to the other field measures pixels. This one measures weight,
	 * and the point it has to make is that those are not the same measurement: both bars
	 * are the same photograph at the same dimensions, and one is seven times heavier than
	 * the other. People conflate the two constantly, and a sentence saying so is easy to
	 * skim past in a way that two bars of obviously different lengths is not.
	 *
	 * @param float $heavy_mb Current large-file threshold, in megabytes.
	 */
	private static function heavy_diagram( $heavy_mb ) {
		$axis = self::heavy_axis( $heavy_mb );

		$label = str_replace(
			'{heavy}',
			self::format_mb( $heavy_mb ),
			self::heavy_label_template()
		);

		$x = static function ( $mb ) use ( $axis ) {
			return round( 150 + ( min( $mb, $axis ) / $axis ) * 460, 2 );
		};

		$rows = array(
			array(
				'mb' => self::SAMPLE_HEAVY_MB,
				'y'  => 34,
			),
			array(
				'mb' => self::SAMPLE_LIGHT_MB,
				'y'  => 74,
			),
		);

		$mark_x = $x( $heavy_mb );

		?>
		<svg class="timu-diagram" viewBox="0 0 720 126" role="img"
			aria-label="<?php echo esc_attr( $label ); ?>"
			data-timu-heavy-axis="<?php echo esc_attr( $axis ); ?>">
			<title><?php echo esc_html( $label ); ?></title>

			<?php foreach ( $rows as $row ) : ?>
				<?php $over = $row['mb'] >= $heavy_mb; ?>
				<text class="timu-svg-cap" x="6" y="<?php echo esc_attr( $row['y'] + 13 ); ?>" text-anchor="start">1600 &#215; 900</text>

				<?php // A rail behind each bar, so a short bar reads as a small share of something rather than as a stub floating in empty space. ?>
				<rect x="150" y="<?php echo esc_attr( $row['y'] ); ?>" width="460" height="18" rx="2" fill="#f6f7f7" />

				<rect class="js-timu-heavy-bar" data-timu-mb="<?php echo esc_attr( $row['mb'] ); ?>"
					x="150" y="<?php echo esc_attr( $row['y'] ); ?>"
					width="<?php echo esc_attr( $x( $row['mb'] ) - 150 ); ?>" height="18" rx="2"
					fill="<?php echo esc_attr( $over ? '#dbe3ec' : '#e8eaed' ); ?>"
					stroke="<?php echo esc_attr( $over ? '#123456' : '#c3c4c7' ); ?>" />
				<text class="timu-svg-cap js-timu-heavy-size" data-timu-mb="<?php echo esc_attr( $row['mb'] ); ?>"
					x="<?php echo esc_attr( $x( $row['mb'] ) + 8 ); ?>" y="<?php echo esc_attr( $row['y'] + 13 ); ?>" text-anchor="start">
					<?php echo esc_html( self::format_mb( $row['mb'] ) ); ?>
				</text>
			<?php endforeach; ?>

			<line class="js-timu-heavy-line" x1="<?php echo esc_attr( $mark_x ); ?>" y1="20"
				x2="<?php echo esc_attr( $mark_x ); ?>" y2="104" stroke="#123456" stroke-width="1.5" stroke-dasharray="3 3" />
			<text class="timu-svg-tick timu-svg-weighttick js-timu-heavy-tick" x="<?php echo esc_attr( $mark_x ); ?>" y="14" text-anchor="middle">
				<?php echo esc_html( self::format_mb( $heavy_mb ) ); ?>
			</text>
			<text class="timu-svg-cap timu-svg-weighttick js-timu-heavy-cap" x="<?php echo esc_attr( $mark_x ); ?>" y="120" text-anchor="middle">
				<?php echo esc_html__( 'large from here', 'thisismyurl-image-support' ); ?>
			</text>
		</svg>
		<?php
	}

	/**
	 * The spoken description of the file-size diagram.
	 *
	 * @return string
	 */
	public static function heavy_label_template() {
		/* translators: {heavy} is a file size such as "1 MB". Keep the token exactly as written. */
		return __( 'Two copies of the same photo, both 1600 by 900 pixels. One takes up 2.4 MB and the other 320 KB. A picture counts as large from {heavy}.', 'thisismyurl-image-support' );
	}

	/**
	 * How many megabytes the file-size axis covers.
	 *
	 * Wide enough to show both samples against the threshold, capped so that an extreme
	 * setting does not squash the bars into slivers. Past the cap the marker sits at the
	 * far end, which is honest: at that setting neither sample counts as large.
	 *
	 * Mirrored in assets/overview.js.
	 *
	 * @param float $heavy_mb Threshold in megabytes.
	 * @return float
	 */
	private static function heavy_axis( $heavy_mb ) {
		return max( 3, min( ceil( $heavy_mb * 2 ), 12 ) );
	}

	/**
	 * A threshold rendered the way a person would say it.
	 *
	 * @param float $mb Megabytes.
	 * @return string
	 */
	private static function format_mb( $mb ) {
		return size_format( $mb * MB_IN_BYTES, ( $mb < 1 ) ? 0 : 1 );
	}

	/**
	 * Whether the two captions under the axis would run into each other.
	 *
	 * "widest served" and "flagged from here" are roughly 70 and 95 pixels wide at the
	 * caption size, so centred on their marks they need about 82 pixels between the marks
	 * to clear. 90 leaves a little air.
	 *
	 * Mirrored in assets/overview.js, which redraws this as the field changes.
	 *
	 * @param float $widest_x Position of the widest-served mark.
	 * @param float $flag_x   Position of the flag mark.
	 * @return bool
	 */
	private static function captions_collide( $widest_x, $flag_x ) {
		return ( $flag_x - $widest_x ) < 90;
	}

	/**
	 * How wide the drawn axis has to be for the flag line to stay on it.
	 *
	 * A fixed 6000px axis is right at the default setting and wrong past about 2.9x, where
	 * the flag falls off the end of the chart and the picture starts telling a comfortable
	 * lie. The axis grows instead, rounded to a whole thousand so the end label stays a
	 * round number.
	 *
	 * PHP draws the first frame and JavaScript redraws it as the field changes, so this
	 * rule and its twin in assets/overview.js have to agree.
	 *
	 * @param int $flag_at Width at which flagging starts.
	 * @return int
	 */
	private static function diagram_scale( $flag_at ) {
		return (int) max( 6000, ceil( $flag_at * 1.15 / 1000 ) * 1000 );
	}

	/**
	 * Whether the "a normal upload" marker is worth drawing.
	 *
	 * Once the axis stretches far enough, 2560px and the widest served size land within a
	 * few pixels of each other and their captions collide. Past that point the marker adds
	 * nothing, so it goes.
	 *
	 * @param int $scale Axis width in pixels.
	 * @return bool
	 */
	private static function diagram_shows_normal( $scale ) {
		return $scale <= 9000;
	}

	/**
	 * The two settings, for whoever is allowed to change them.
	 *
	 * Non-administrators are told the settings exist and who can change them, rather than
	 * being shown a screen with a silent hole in it.
	 */
	private static function render_settings() {
		$widest  = TIMU_Image_Support_Facts::widest_served_width();
		$flag_at = (int) round( $widest * TIMU_Image_Support_Facts::oversize_threshold() );

		echo '<div class="timu-sec">';
		self::section_head(
			__( 'Settings', 'thisismyurl-image-support' ),
			__( 'Two numbers that decide which pictures get marked. They only mark pictures. They never change them.', 'thisismyurl-image-support' )
		);

		echo '<div class="timu-card-lg">';

		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>' . esc_html__(
				'Two numbers decide which pictures get marked: how wide a picture can be, and how big its file can be. Only an administrator can change them, so you are seeing them as they are set now. You can leave them as they are. They are already set to good choices.',
				'thisismyurl-image-support'
			) . '</p>';

			printf(
				'<p><strong>%s</strong> %s<br /><strong>%s</strong> %s</p>',
				esc_html__( 'How wide is too wide:', 'thisismyurl-image-support' ),
				esc_html( number_format_i18n( TIMU_Image_Support_Facts::oversize_threshold(), 2 ) ),
				esc_html__( 'How big is a large file:', 'thisismyurl-image-support' ),
				esc_html( size_format( TIMU_Image_Support_Facts::heavy_bytes(), 1 ) )
			);

			self::oversize_diagram( $widest, $flag_at );

			echo '</div></div>';
			return;
		}

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );

		echo '<p class="timu-prose">' . esc_html__(
			'These two settings measure two different things. One is how many tiny dots (called pixels) a picture has. The other is how much a visitor has to download to see it.',
			'thisismyurl-image-support'
		) . '</p>';

		printf( '<h3>%s</h3>', esc_html__( 'Picture size, in pixels', 'thisismyurl-image-support' ) );

		echo '<p class="timu-prose">' . esc_html__(
			'A picture is made of tiny dots called pixels. A picture much wider than your website ever shows has extra dots that nobody will ever see.',
			'thisismyurl-image-support'
		) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		// The scale is drawn inside this field's own cell rather than in a section of its
		// own further down the page. It is not a lesson about image sizes, it is what this
		// particular number currently does, so it belongs next to the control that sets it
		// and it redraws every time that control is saved.
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>'
			. '<input name="%1$s" id="%1$s" type="number" step="0.05" min="1.3" max="10" value="%3$s" class="small-text" /> &times;'
			. '<p class="description">%4$s</p>',
			esc_attr( TIMU_Image_Support_Facts::OPTION_OVERSIZE ),
			esc_html__( 'How wide is too wide', 'thisismyurl-image-support' ),
			esc_attr( TIMU_Image_Support_Facts::oversize_threshold() ),
			wp_kses(
				sprintf(
					/* translators: 1: widest served width in pixels, 2: current flag width in pixels, already wrapped for live updating. */
					__( 'How many times wider than your biggest picture size (%1$dpx) a picture must be before it is marked. Right now, pictures wider than %2$s are marked. You cannot go below 1.3, because that would mark ordinary, healthy pictures.', 'thisismyurl-image-support' ),
					$widest,
					'<span class="timu-flag-px">' . esc_html( sprintf( '%dpx', $flag_at ) ) . '</span>'
				),
				array( 'span' => array( 'class' => array() ) )
			)
		);

		self::oversize_diagram( $widest, $flag_at );

		echo '</td></tr>';
		echo '</tbody></table>';

		// The second setting gets its own heading and its own table rather than sitting as
		// a second row under the first. They measure genuinely different things, and two
		// rows in one table invites somebody to read them as two versions of one idea.
		printf( '<h3>%s</h3>', esc_html__( 'File size, in megabytes', 'thisismyurl-image-support' ) );

		echo '<p class="timu-prose">' . esc_html__(
			'File size is how much a visitor has to download. It is not the same as the width above. Two copies of one photo can be the same width but have very different file sizes, depending on how the picture was saved.',
			'thisismyurl-image-support'
		) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>'
			. '<input name="%1$s" id="%1$s" type="number" step="0.1" min="0.1" max="100" value="%3$s" class="small-text" /> %5$s'
			. '<p class="description">%4$s</p>',
			esc_attr( TIMU_Image_Support_Facts::OPTION_HEAVY_MB ),
			esc_html__( 'A large file is one over', 'thisismyurl-image-support' ),
			esc_attr( TIMU_Image_Support_Facts::heavy_mb() ),
			esc_html__( 'A picture with a file this big is worth a second look. It is highlighted in the file size column and shows up in the large files list. This only marks pictures. It never changes them.', 'thisismyurl-image-support' ),
			esc_html( trim( str_replace( '{n}', '', self::size_templates()['mb'] ) ) )
		);

		self::heavy_diagram( TIMU_Image_Support_Facts::heavy_mb() );

		echo '</td></tr>';
		echo '</tbody></table>';

		submit_button();
		echo '</form>';
		echo '</div></div>';
	}

	/**
	 * The honest limits. Kept on the page, not buried in a readme.
	 */
	private static function render_limits() {
		echo '<div class="timu-sec">';
		// "Never" was the wrong word here. Earlier releases did rename files and make WebP
		// copies, and those are recorded as deferred rather than abandoned, so a promise
		// about every future version was one this plugin could not keep. Promising that a
		// change would be announced is a promise it can.
		self::section_head(
			__( 'What this tool never touches', 'thisismyurl-image-support' ),
			__( 'This is true of this version. If it ever changes, we will say so in the list of changes.', 'thisismyurl-image-support' )
		);

		echo '<div class="timu-card-lg">';

		$limits = array(
			__( 'Your picture files. Nothing is renamed, moved, made smaller or changed in any way. The only thing it can change is a picture description, and only when you type one. It also keeps a small list of file sizes, a daily count of what it found, and short-lived copies of its own results.', 'thisismyurl-image-support' ),
			__( 'The pages your visitors see. Everything here happens behind the scenes, in your dashboard.', 'thisismyurl-image-support' ),
			__( 'Your privacy. Nothing is sent anywhere, and nothing is tracked.', 'thisismyurl-image-support' ),
			__( 'Anything that is not a picture. Your documents, sound files and videos are left completely alone.', 'thisismyurl-image-support' ),
			__( 'Your descriptions, even if you remove this tool. They are saved in the same place WordPress always keeps them, so they belong to your website. If you uninstall it, only its own file size list, daily counts, saved results and these two settings are removed. Nothing else.', 'thisismyurl-image-support' ),
		);

		echo '<ul class="timu-limits">';

		foreach ( $limits as $limit ) {
			printf( '<li>%s</li>', esc_html( $limit ) );
		}

		echo '</ul>';

		echo '<h3>' . esc_html__( 'About picture descriptions', 'thisismyurl-image-support' ) . '</h3>';

		echo '<p>' . esc_html__(
			'A picture description (called alt text) tells people who cannot see the picture what it shows. They often use a screen reader, which reads the description out loud. One short sentence about what the picture shows is enough.',
			'thisismyurl-image-support'
		) . '</p>';

		echo '<p>' . esc_html__(
			'Some pictures are only for decoration and do not need a description. WordPress cannot tell us which ones, and an empty box looks the same either way. So this tool does not guess. Every picture without a description is counted, even one you left empty on purpose. A few decorative pictures on your list is better than being told the job is done when it might not be.',
			'thisismyurl-image-support'
		) . '</p>';

		echo '</div></div>';
	}

	/**
	 * Hooks, for whoever comes next.
	 */
	private static function render_developer_notes() {
		echo '<div class="timu-sec">';
		self::section_head(
			__( 'For developers (you can skip this part)', 'thisismyurl-image-support' ),
			__( 'Technical notes for web developers. Most people never need these.', 'thisismyurl-image-support' )
		);

		echo '<details class="timu-card-lg timu-dev"><summary>' . esc_html__( 'Show the technical notes', 'thisismyurl-image-support' ) . '</summary>';

		$hooks = array(
			array( 'timu_image_support_mime_types', __( 'filter', 'thisismyurl-image-support' ), __( 'Which image types the plugin looks at.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_widest_width', __( 'filter', 'thisismyurl-image-support' ), __( 'The width treated as the largest this site serves. Derived from registered sizes by default.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_oversize_threshold', __( 'filter', 'thisismyurl-image-support' ), __( 'Overrides the setting above, for sites that configure in code.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_heavy_bytes', __( 'filter', 'thisismyurl-image-support' ), __( 'Overrides the large-file threshold, in bytes.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_oversize_scan_limit', __( 'filter', 'thisismyurl-image-support' ), __( 'How many images the oversize scan examines. Defaults to 2,000.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_attention_floor', __( 'filter', 'thisismyurl-image-support' ), __( 'The smallest count at which a card is marked as needing attention.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_index_backfill_limit', __( 'filter', 'thisismyurl-image-support' ), __( 'How many images get a file size index entry per page load. Defaults to 2,000.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_library_scan_limit', __( 'filter', 'thisismyurl-image-support' ), __( 'How many attachments the library measurement reads. Defaults to 100,000.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_untracked_scan_limit', __( 'filter', 'thisismyurl-image-support' ), __( 'How many files in the uploads folder the untracked scan looks at. Defaults to 50,000.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_untracked_skip_folders', __( 'filter', 'thisismyurl-image-support' ), __( 'Top-level uploads folders the untracked scan leaves alone, such as those used by form and store plugins.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_reference_scan_limit', __( 'filter', 'thisismyurl-image-support' ), __( 'How many images the reference scan examines. Defaults to 5,000.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_reference_row_limit', __( 'filter', 'thisismyurl-image-support' ), __( 'How many rows of content, custom fields or settings the reference scan reads per source. Defaults to 200,000.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_reference_file_limit', __( 'filter', 'thisismyurl-image-support' ), __( 'How many theme and must-use plugin files the reference scan reads. Defaults to 1,500.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_alt_suggestions', __( 'filter', 'thisismyurl-image-support' ), __( 'Return suggested alt strings for an image. Empty in this release, so no suggestion interface appears. Suggestions are offered to the person editing and are never written on their behalf.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_before_write', __( 'action', 'thisismyurl-image-support' ), __( 'Fires before the plugin changes an image, with the previous value.', 'thisismyurl-image-support' ) ),
			array( 'timu_image_support_after_write', __( 'action', 'thisismyurl-image-support' ), __( 'Fires after, with the old and new values.', 'thisismyurl-image-support' ) ),
		);

		echo '<table class="widefat striped timu-hooks"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Hook', 'thisismyurl-image-support' ) );
		printf( '<th>%s</th>', esc_html__( 'Type', 'thisismyurl-image-support' ) );
		printf( '<th>%s</th>', esc_html__( 'What it does', 'thisismyurl-image-support' ) );
		echo '</tr></thead><tbody>';

		foreach ( $hooks as $hook ) {
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
				esc_html( $hook[0] ),
				esc_html( $hook[1] ),
				esc_html( $hook[2] )
			);
		}

		echo '</tbody></table>';

		printf(
			'<p class="timu-note">%s</p>',
			esc_html__( 'A filter always wins over the setting on this page, so a site configured in code keeps behaving that way after somebody visits this screen.', 'thisismyurl-image-support' )
		);

		echo '</details></div>';
	}

	/**
	 * How many rows a page of the reference report holds.
	 */
	const REFERENCES_PER_PAGE = 20;

	/**
	 * Where each image is referenced, listed twenty at a time.
	 */
	private static function render_references() {
		// A nonce-checked link that only clears a cached scan. Nothing is created, deleted
		// or altered; the next page load simply does the reading again.
		if ( isset( $_GET['timu_rescan'] ) && check_admin_referer( 'timu_rescan' ) ) {
			TIMU_Image_Support_Reference_Scan::flush();
		}

		$scan = TIMU_Image_Support_Reference_Scan::map();
		$map  = $scan['map'];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$only_idle = isset( $_GET['ref'] ) && 'none' === sanitize_key( wp_unslash( $_GET['ref'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

		$idle = array_filter(
			$map,
			static function ( $hits ) {
				return empty( $hits );
			}
		);

		$rows = $only_idle ? $idle : $map;

		// Images nobody can find a use for are the reason somebody opened this, so they sit
		// at the top rather than scattered through the list by upload date.
		uasort(
			$rows,
			static function ( $a, $b ) {
				return count( $a ) <=> count( $b );
			}
		);

		self::render_reference_intro( $scan, count( $idle ), count( $map ) );
		self::render_reference_filters( $only_idle, count( $map ), count( $idle ) );

		$total      = count( $rows );
		$pages      = (int) max( 1, ceil( $total / self::REFERENCES_PER_PAGE ) );
		$paged      = min( $paged, $pages );
		$slice      = array_slice( $rows, ( $paged - 1 ) * self::REFERENCES_PER_PAGE, self::REFERENCES_PER_PAGE, true );
		$sources    = TIMU_Image_Support_Reference_Scan::sources();
		$idle_total = count( $idle );

		if ( empty( $slice ) ) {
			echo '<div class="timu-card-lg"><p class="timu-prose">'
				. esc_html__( 'Nothing to list here.', 'thisismyurl-image-support' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped timu-refs"><thead><tr>';
		printf( '<th scope="col" class="timu-ref-thumb">%s</th>', esc_html__( 'Picture', 'thisismyurl-image-support' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'File', 'thisismyurl-image-support' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Used in', 'thisismyurl-image-support' ) );
		echo '</tr></thead><tbody>';

		// Unreferenced images sort first, so on any page they arrive as one contiguous run.
		// Saying "No reference found" once at the head of that run beats printing it twenty
		// times down a column, where twenty identical lines stop being read at all. The
		// caveat rides on the header too, because somebody who reached page nine of
		// seventeen never saw the card at the top.
		$group = null;

		foreach ( $slice as $id => $hits ) {
			$this_group = empty( $hits ) ? 'none' : 'found';

			if ( $this_group !== $group ) {
				$group = $this_group;
				self::render_reference_group_head( $group, 'none' === $group ? $idle_total : 0 );
			}

			echo '<tr>';

			echo '<td class="timu-ref-thumb">';
			if ( TIMU_Image_Support_Facts::file_missing( $id ) ) {
				printf( '<span class="timu-ref-gone" title="%s">&#9633;</span>', esc_attr__( 'The file is missing', 'thisismyurl-image-support' ) );
			} else {
				echo wp_kses_post( wp_get_attachment_image( $id, array( 40, 40 ), true ) );
			}
			echo '</td>';

			$edit_link = get_edit_post_link( $id );
			$file_name = basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );

			// Somebody who cannot edit an image gets no edit link, and gets its name as text.
			if ( $edit_link ) {
				printf( '<td><a href="%s">%s</a></td>', esc_url( $edit_link ), esc_html( $file_name ) );
			} else {
				printf( '<td>%s</td>', esc_html( $file_name ) );
			}

			echo '<td>';

			if ( empty( $hits ) ) {
				// Carried for anyone moving through the table cell by cell, who does not get
				// the grouping from the layout. Nothing is drawn.
				printf(
					'<span class="timu-ref-none">%s</span>',
					esc_html__( 'Not found anywhere', 'thisismyurl-image-support' )
				);
			} else {
				foreach ( $hits as $hit ) {
					if ( isset( $sources[ $hit ] ) ) {
						printf( '<span class="timu-ref-tag">%s</span>', esc_html( $sources[ $hit ] ) );
					}
				}
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		self::render_reference_pagination( $paged, $pages, $total, $only_idle );
	}

	/**
	 * A heading row separating the two halves of the table.
	 *
	 * @param string $group Either 'none' or 'found'.
	 * @param int    $count Images with no reference found, when that is the group.
	 */
	private static function render_reference_group_head( $group, $count ) {
		if ( 'none' === $group ) {
			printf(
				'<tr class="timu-ref-group is-none"><th colspan="3" scope="colgroup">'
				. '<span class="timu-ref-group-label">%s</span> <span class="timu-ref-group-note">%s</span>'
				. '</th></tr>',
				esc_html(
					sprintf(
						/* translators: %s: number of images. */
						_n( 'Not found anywhere: %s picture', 'Not found anywhere: %s pictures', $count, 'thisismyurl-image-support' ),
						number_format_i18n( $count )
					)
				),
				esc_html__( 'A picture listed here may still be in use. Please check before you delete anything.', 'thisismyurl-image-support' )
			);

			return;
		}

		printf(
			'<tr class="timu-ref-group"><th colspan="3" scope="colgroup">'
			. '<span class="timu-ref-group-label">%s</span></th></tr>',
			esc_html__( 'Used somewhere', 'thisismyurl-image-support' )
		);
	}

	/**
	 * What the report is, and the limits of it.
	 *
	 * @param array $scan  Scan result.
	 * @param int   $idle  Images with no reference found.
	 * @param int   $shown Images examined.
	 */
	private static function render_reference_intro( array $scan, $idle, $shown ) {
		// The heading was the same six words as the tab directly above it, which made it an
		// echo of the navigation rather than a statement about the page. This one reads
		// straight into the sentence beneath it.
		self::section_head(
			__( 'What this scan found', 'thisismyurl-image-support' ),
			__( 'Every place we could be sure a picture is used.', 'thisismyurl-image-support' )
		);

		echo '<div class="timu-card-lg">';

		// The caveat goes first. It is the one paragraph here that exists to stop somebody
		// deleting a live image, and it was sitting second, behind a sentence about file
		// counts. What follows it is context for the warning, not its equal.
		echo '<p class="timu-note timu-note-lead">' . esc_html__(
			'"Not found anywhere" does not mean nobody uses the picture. Page builders, shortcodes and some plugins keep pictures in places this check cannot see. Please look at where a picture appears before you delete it. This tool never deletes anything for you.',
			'thisismyurl-image-support'
		) . '</p>';

		echo '<details class="timu-ui-more"><summary>' . esc_html__( 'How this check works', 'thisismyurl-image-support' ) . '</summary><p class="timu-note timu-note-quiet">' . esc_html(
			sprintf(
				/* translators: 1: images examined, 2: number of files read. */
				__( 'This looked at %1$s pictures. It checked featured pictures, the words in your posts and pages, custom fields, and your site and theme settings, and read %2$s files from your active theme and must-use plugins. It does not look inside ordinary plugins, so a picture that only a plugin uses may show as not found. The same is true for pictures a gallery picks by number, or that a store or form plugin keeps for itself.', 'thisismyurl-image-support' ),
				number_format_i18n( $shown ),
				number_format_i18n( $scan['files'] )
			)
		) . '</p></details>';

		if ( ! empty( $scan['incomplete'] ) ) {
			echo '<p class="timu-note timu-note-flag">' . esc_html__(
				'Part of your website could not be read in full, so this list may be missing some places. Please do not delete anything because of it.',
				'thisismyurl-image-support'
			) . '</p>';
		}

		if ( ! empty( $scan['capped'] ) ) {
			echo '<p class="timu-note timu-note-flag">' . esc_html(
				sprintf(
					/* translators: 1: images examined, 2: images in the library. */
					__( 'Your library holds %2$s images and this scan covered the most recent %1$s of them. The rest were not examined.', 'thisismyurl-image-support' ),
					number_format_i18n( $scan['scanned'] ),
					number_format_i18n( $scan['total'] )
				)
			) . '</p>';
		}

		printf(
			'<p><a class="button" href="%s">%s</a> <span class="timu-ref-when">%s</span></p>',
			esc_url(
				wp_nonce_url(
					self::page_url(
						array(
							'view'        => 'references',
							'timu_rescan' => 1,
						)
					),
					'timu_rescan'
				)
			),
			esc_html__( 'Check again', 'thisismyurl-image-support' ),
			esc_html(
				sprintf(
					/* translators: %s: human-readable time difference, e.g. "5 minutes". */
					__( 'Last checked %s ago.', 'thisismyurl-image-support' ),
					human_time_diff( (int) $scan['generated'] )
				)
			)
		);

		echo '</div>';
	}

	/**
	 * The two views of the list.
	 *
	 * @param bool $only_idle Whether the idle-only view is active.
	 * @param int  $all       Total examined.
	 * @param int  $idle      Images with no reference found.
	 */
	private static function render_reference_filters( $only_idle, $all, $idle ) {
		$links = array(
			array(
				'label'  => __( 'All pictures', 'thisismyurl-image-support' ),
				'count'  => $all,
				'url'    => self::page_url( array( 'view' => 'references' ) ),
				'active' => ! $only_idle,
			),
			array(
				'label'  => __( 'Not found anywhere', 'thisismyurl-image-support' ),
				'count'  => $idle,
				'url'    => self::page_url(
					array(
						'view' => 'references',
						'ref'  => 'none',
					)
				),
				'active' => $only_idle,
			),
		);

		echo '<ul class="subsubsub timu-ref-views">';

		foreach ( $links as $i => $link ) {
			printf(
				'<li>%s<a href="%s"%s>%s <span class="count">(%s)</span></a></li>',
				$i > 0 ? ' | ' : '',
				esc_url( $link['url'] ),
				$link['active'] ? ' class="current" aria-current="page"' : '',
				esc_html( $link['label'] ),
				esc_html( number_format_i18n( $link['count'] ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * Pagination under the table.
	 *
	 * @param int  $paged     Current page.
	 * @param int  $pages     Total pages.
	 * @param int  $total     Total rows.
	 * @param bool $only_idle Whether the idle-only view is active.
	 */
	private static function render_reference_pagination( $paged, $pages, $total, $only_idle ) {
		$base = self::page_url(
			$only_idle
				? array(
					'view' => 'references',
					'ref'  => 'none',
				)
				: array( 'view' => 'references' )
		);

		echo '<div class="tablenav bottom timu-ref-nav"><div class="tablenav-pages">';

		printf(
			'<span class="displaying-num">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: number of images. */
					_n( '%s picture', '%s pictures', $total, 'thisismyurl-image-support' ),
					number_format_i18n( $total )
				)
			)
		);

		if ( $pages > 1 ) {
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', $base ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'type'      => 'plain',
					)
				)
			);
		}

		echo '</div></div>';
	}


	/**
	 * Send the history as a CSV file.
	 *
	 * "Safe" here means specific things. The file is composed in memory from numbers this
	 * plugin measured, so there is no path anywhere in the request and nothing on disk can
	 * be reached through it. It is gated by the same capability as the screen that offers
	 * it and by a nonce, so it cannot be triggered by a link somebody else planted. Every
	 * field is a formatted integer, and each one is still passed through a guard against
	 * spreadsheet formula injection, because a CSV opened in Excel will execute a cell that
	 * begins with an equals sign.
	 */
	public static function export_history() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to download this.', 'thisismyurl-image-support' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::EXPORT_ACTION );

		$from = isset( $_POST['from'] ) ? self::clean_date( sanitize_text_field( wp_unslash( $_POST['from'] ) ) ) : '';
		$to   = isset( $_POST['to'] ) ? self::clean_date( sanitize_text_field( wp_unslash( $_POST['to'] ) ) ) : '';

		$history = TIMU_Image_Support_Library_Report::history( $from, $to );
		$metrics = TIMU_Image_Support_Library_Report::metrics();

		$rows   = array();
		$rows[] = array_merge( array( __( 'Date', 'thisismyurl-image-support' ) ), array_values( $metrics ) );

		foreach ( $history as $date => $values ) {
			$row = array( $date );

			foreach ( array_keys( $metrics ) as $key ) {
				$row[] = isset( $values[ $key ] ) ? (string) (int) $values[ $key ] : '';
			}

			$rows[] = $row;
		}

		$name = sprintf( 'image-library-%s.csv', gmdate( 'Y-m-d' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $name );

		// Writing to the response body, not to the filesystem, so WP_Filesystem does not apply.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );

		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $row ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	/**
	 * A date, or nothing.
	 *
	 * @param string $value Submitted value.
	 * @return string Empty string when it is not a plain YYYY-MM-DD date.
	 */
	private static function clean_date( $value ) {
		$value = sanitize_text_field( $value );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Stop a spreadsheet treating a cell as a formula.
	 *
	 * Nothing this plugin writes into the file could begin with one of these, since every
	 * value is a number it measured itself. It is here because the day somebody adds a
	 * column carrying a file name is the day it matters, and the guard is one line.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	private static function csv_cell( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return $value;
		}

		$risky = array( '=', '+', '-', '@', "\t", "\r" );

		return in_array( $value[0], $risky, true ) ? "'" . $value : $value;
	}

	/**
	 * What this library is, dated, with whatever history has been gathered.
	 */
	private static function render_about() {
		$report  = TIMU_Image_Support_Library_Report::current();
		$detail  = $report['detail'];
		$now     = $report['metrics'];
		$history = TIMU_Image_Support_Library_Report::history();
		$before  = self::earlier_snapshot( $history );

		echo '<div class="timu-sec">';
		self::section_head(
			__( 'About your pictures', 'thisismyurl-image-support' ),
			__( 'What you have, how much space it takes, and whether things are getting better.', 'thisismyurl-image-support' )
		);

		echo '<div class="timu-card-lg">';

		printf(
			'<p class="timu-note timu-note-quiet">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: human-readable time difference, e.g. "20 minutes". */
					__( 'Counted %s ago. One count is saved each day that someone opens this page. Days when nobody opened it have no count.', 'thisismyurl-image-support' ),
					human_time_diff( (int) $report['generated'] )
				)
			)
		);

		$tracked = (int) $now['originals_bytes'] + (int) $now['generated_bytes'];

		// When the library is larger than the measurement covers, some figures are not known.
		// They are shown as not counted, never as zero, and never compared with an earlier reading.
		$capped = ! empty( $detail['library_capped'] );

		if ( ! empty( $detail['library_capped'] ) ) {
			echo '<p class="timu-note timu-note-flag">' . esc_html(
				sprintf(
					/* translators: %s: number of attachments the measurement covers. */
					__( 'You have more than %s pictures, which is more than this can count at once. The size numbers cover the oldest ones, and stray files were not counted at all.', 'thisismyurl-image-support' ),
					number_format_i18n( (int) ( $detail['library_limit'] ?? 0 ) )
				)
			) . '</p>';
		}

		$facts = array(
			array(
				'label' => __( 'Space your pictures use', 'thisismyurl-image-support' ),
				'value' => size_format( $tracked, 1 ),
				'help'  => sprintf(
					/* translators: 1: size of the original files, 2: percentage that is generated sizes. */
					__( '%1$s of that is your original pictures. The other %2$d%% is smaller copies that WordPress made.', 'thisismyurl-image-support' ),
					size_format( (int) $now['originals_bytes'], 1 ),
					$tracked ? (int) round( $now['generated_bytes'] / $tracked * 100 ) : 0
				),
			),
			array(
				'label' => __( 'Stray picture files', 'thisismyurl-image-support' ),
				'value' => $capped ? __( 'Not counted', 'thisismyurl-image-support' ) : number_format_i18n( (int) $now['untracked_images'] ),
				'delta' => $capped ? null : self::delta( $now, $before, 'untracked_images' ),
				'help'  => $capped
					? __( 'You have more pictures than this can count at once, so stray files were not counted. See the note above.', 'thisismyurl-image-support' )
					: sprintf(
						/* translators: %s: file size. */
						__( 'Picture files in your uploads folder that are not in your Media Library, using %s.', 'thisismyurl-image-support' ),
						size_format( (int) $now['untracked_bytes'], 1 )
					),
			),
			array(
				'label' => __( 'Possible copies', 'thisismyurl-image-support' ),
				'value' => number_format_i18n( (int) $now['duplicate_extra'] ),
				'delta' => $capped ? null : self::delta( $now, $before, 'duplicate_extra' ),
				'help'  => sprintf(
					/* translators: %s: number of groups. */
					_n( 'Extra copies in %s group of pictures that match in size and weight.', 'Extra copies in %s groups of pictures that match in size and weight.', (int) $detail['duplicate_groups'], 'thisismyurl-image-support' ),
					number_format_i18n( (int) $detail['duplicate_groups'] )
				),
			),
			array(
				'label' => __( 'No uploader recorded', 'thisismyurl-image-support' ),
				'value' => number_format_i18n( (int) $now['no_uploader'] ),
				'help'  => __( 'Pictures with no person listed as adding them. This usually means they came in with an import, not by hand.', 'thisismyurl-image-support' ),
			),
		);

		echo '<div class="timu-cards timu-about-cards">';

		foreach ( $facts as $fact ) {
			echo '<div class="timu-card">';
			echo '<span class="timu-card-head"><span class="timu-card-label">' . esc_html( $fact['label'] ) . '</span>';

			if ( ! empty( $fact['delta'] ) ) {
				printf(
					'<span class="timu-delta is-%s">%s</span>',
					esc_attr( $fact['delta']['direction'] ),
					esc_html( $fact['delta']['label'] )
				);
			}

			echo '</span>';
			printf( '<span class="timu-card-count">%s</span>', esc_html( $fact['value'] ) );
			printf( '<span class="timu-card-help">%s</span>', esc_html( $fact['help'] ) );
			echo '</div>';
		}

		echo '</div>';

		if ( ! empty( $detail['unmeasured'] ) ) {
			echo '<p class="timu-note timu-note-quiet">' . esc_html(
				sprintf(
					/* translators: %s: number of images. */
					_n(
						'%s picture has no file size written down yet, so it is left out of the size numbers. Opening the Media list adds more of them over time.',
						'%s pictures have no file size written down yet, so they are left out of the size numbers. Opening the Media list adds more of them over time.',
						(int) $detail['unmeasured'],
						'thisismyurl-image-support'
					),
					number_format_i18n( (int) $detail['unmeasured'] )
				)
			) . '</p>';
		}

		self::render_untracked_folders( $detail );
		self::render_library_facts( $detail, $now );
		self::render_history( $history );

		echo '</div></div>';
	}

	/**
	 * The snapshot to compare today against.
	 *
	 * The oldest reading still inside the last thirty days, so the comparison spans as much
	 * of the month as was actually recorded rather than claiming a fixed period.
	 *
	 * @param array $history Dated snapshots.
	 * @return array|null
	 */
	private static function earlier_snapshot( array $history ) {
		$today  = gmdate( 'Y-m-d' );
		$cutoff = gmdate( 'Y-m-d', strtotime( '-30 days', strtotime( $today ) ) );

		foreach ( $history as $date => $metrics ) {
			if ( $date >= $cutoff && $date < $today ) {
				return array(
					'date'    => $date,
					'metrics' => $metrics,
				);
			}
		}

		return null;
	}

	/**
	 * The change in one number since an earlier reading.
	 *
	 * Down is the good direction for every number this is used on, so the colour follows
	 * the meaning rather than the arithmetic.
	 *
	 * @param array      $now    Current metrics.
	 * @param array|null $before Earlier snapshot.
	 * @param string     $key    Metric key.
	 * @return array|null
	 */
	private static function delta( array $now, $before, $key ) {
		if ( ! is_array( $before ) || ! isset( $before['metrics'][ $key ] ) ) {
			return null;
		}

		$change = (int) $now[ $key ] - (int) $before['metrics'][ $key ];
		$days   = max( 1, (int) round( ( strtotime( gmdate( 'Y-m-d' ) ) - strtotime( $before['date'] ) ) / DAY_IN_SECONDS ) );

		if ( 0 === $change ) {
			return array(
				'label'     => sprintf(
					/* translators: %d: number of days. */
					_n( 'no change in %d day', 'no change in %d days', $days, 'thisismyurl-image-support' ),
					$days
				),
				'direction' => 'flat',
			);
		}

		return array(
			'label'     => sprintf(
				/* translators: 1: signed number, 2: number of days. */
				_n( '%1$s in %2$d day', '%1$s in %2$d days', $days, 'thisismyurl-image-support' ),
				( $change > 0 ? '+' : '' ) . number_format_i18n( $change ),
				$days
			),
			'direction' => $change < 0 ? 'better' : 'worse',
		);
	}

	/**
	 * A small line of one number over time.
	 *
	 * Drawn from the readings this plugin has taken, which are irregular: one per day the
	 * page was opened, and nothing on days nobody looked. So the points are spaced evenly
	 * rather than by date, and the caption says how many readings and over what span. A
	 * chart that spaced them by date would imply a continuous record that does not exist.
	 *
	 * @param string $key   Metric key.
	 * @param string $label What the line is of.
	 * @param bool   $bytes Whether the values are byte counts.
	 */
	private static function sparkline( $key, $label, $bytes = false ) {
		$history = TIMU_Image_Support_Library_Report::history();
		$points  = array();

		foreach ( $history as $date => $metrics ) {
			if ( isset( $metrics[ $key ] ) ) {
				$points[ $date ] = (int) $metrics[ $key ];
			}
		}

		if ( count( $points ) < 2 ) {
			printf(
				'<p class="timu-note timu-note-quiet">%s</p>',
				esc_html__( 'Not enough counts yet to draw a line. One count is saved each day this page is opened.', 'thisismyurl-image-support' )
			);

			return;
		}

		$values = array_values( $points );
		$dates  = array_keys( $points );
		$count  = count( $values );
		$high   = max( $values );
		$low    = min( $values );
		$span   = max( 1, $high - $low );

		$width  = 640;
		$height = 90;
		$pad    = 12;

		$coords = array();

		foreach ( $values as $i => $value ) {
			$x = $pad + ( $i / max( 1, $count - 1 ) ) * ( $width - ( $pad * 2 ) );
			$y = $height - $pad - ( ( $value - $low ) / $span ) * ( $height - ( $pad * 2 ) );

			$coords[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}

		$first   = $values[0];
		$last    = $values[ $count - 1 ];
		$change  = $last - $first;
		$reading = $bytes ? 'size_format' : 'number_format_i18n';

		$description = sprintf(
			/* translators: 1: what is being measured, 2: number of readings, 3: first value, 4: first date, 5: last value, 6: last date. */
			__( '%1$s across %2$s readings: %3$s on %4$s, %5$s on %6$s.', 'thisismyurl-image-support' ),
			$label,
			number_format_i18n( $count ),
			$bytes ? size_format( $first, 1 ) : number_format_i18n( $first ),
			$dates[0],
			$bytes ? size_format( $last, 1 ) : number_format_i18n( $last ),
			$dates[ $count - 1 ]
		);

		$tone = 'flat';

		if ( 0 !== $change ) {
			$tone = $change < 0 ? 'better' : 'worse';
		}

		printf(
			'<svg class="timu-spark is-%1$s" viewBox="0 0 %2$d %3$d" preserveAspectRatio="none" role="img" aria-label="%4$s">'
			. '<title>%4$s</title>'
			. '<polyline points="%5$s" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />'
			. '<circle cx="%6$s" cy="%7$s" r="3" fill="currentColor" />'
			. '</svg>',
			esc_attr( $tone ),
			(int) $width,
			(int) $height,
			esc_attr( $description ),
			esc_attr( implode( ' ', $coords ) ),
			esc_attr( explode( ',', end( $coords ) )[0] ),
			esc_attr( explode( ',', end( $coords ) )[1] )
		);

		printf(
			'<p class="timu-spark-cap">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: first value, 2: first date, 3: last value, 4: last date. */
					__( '%1$s on %2$s, %3$s now.', 'thisismyurl-image-support' ),
					$bytes ? size_format( $first, 1 ) : number_format_i18n( $first ),
					$dates[0],
					$bytes ? size_format( $last, 1 ) : number_format_i18n( $last )
				)
			)
		);
	}
	/**
	 * Untracked files, by folder.
	 *
	 * @param array $detail Report detail.
	 */
	private static function render_untracked_folders( array $detail ) {
		if ( empty( $detail['untracked_by_dir'] ) ) {
			return;
		}

		printf( '<h3>%s</h3>', esc_html__( 'Where the stray pictures are', 'thisismyurl-image-support' ) );

		// Folder names can give away more than they seem to. Some plugins keep private files in
		// uploads and rely on a folder name nobody could guess, so an Author, who may open this
		// screen, is told how many there are and nothing about where.
		if ( ! current_user_can( 'manage_options' ) ) {
			$files = 0;

			foreach ( $detail['untracked_by_dir'] as $row ) {
				$files += (int) $row['count'];
			}

			echo '<p class="timu-note timu-note-lead">' . esc_html(
				sprintf(
					/* translators: %s: number of image files. */
					_n(
						'%s picture file in your uploads folder is not in your Media Library. Which folder it is in is shown to administrators.',
						'%s picture files in your uploads folder are not in your Media Library. Which folders they are in is shown to administrators.',
						$files,
						'thisismyurl-image-support'
					),
					number_format_i18n( $files )
				)
			) . '</p>';

			return;
		}

		if ( ! empty( $detail['untracked_capped'] ) ) {
			echo '<p class="timu-note timu-note-flag">' . esc_html__(
				'Your uploads folder is large, so only the first part was checked. The numbers below may be low.',
				'thisismyurl-image-support'
			) . '</p>';
		}

		echo '<p class="timu-note timu-note-lead">' . esc_html__(
			'This shows folders, not a list of files, on purpose. Some of these files were left behind when a theme changed its picture sizes, and the picture they belong to is fine. Others belong to a plugin that looks after its own folder and should be left alone. This only tells you where to look.',
			'thisismyurl-image-support'
		) . '</p>';

		echo '<table class="widefat striped timu-refs"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Folder', 'thisismyurl-image-support' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Pictures', 'thisismyurl-image-support' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Size', 'thisismyurl-image-support' ) );
		echo '</tr></thead><tbody>';

		$uploads = wp_upload_dir();

		// Every folder, not a top twelve. The point of this table is to account for the
		// number above it, and a truncated list leaves somebody wondering what was in the
		// part they were not shown.
		foreach ( $detail['untracked_by_dir'] as $folder => $row ) {
			echo '<tr><td>';
			self::render_untracked_names( $folder, $row, $uploads['baseurl'] );
			echo '</td>';

			printf(
				'<td>%s</td><td>%s</td></tr>',
				esc_html( number_format_i18n( $row['count'] ) ),
				esc_html( size_format( $row['bytes'], 1 ) )
			);
		}

		echo '</tbody></table>';

		self::sparkline( 'untracked_images', __( 'Stray picture files', 'thisismyurl-image-support' ) );
	}

	/**
	 * One folder, openable to show what is inside it.
	 *
	 * The file names link to the files themselves, because "what are these" is the only
	 * question worth asking about a folder of images nobody has accounted for, and looking
	 * at one answers it faster than any description could. This list is only ever drawn for
	 * administrators, and folders that belong to form and store plugins are left out of the
	 * scan altogether, because a file there may be private even when its URL resolves.
	 *
	 * @param string $folder  Relative folder path.
	 * @param array  $row     Folder data.
	 * @param string $baseurl Uploads base URL.
	 */
	private static function render_untracked_names( $folder, array $row, $baseurl ) {
		$names = isset( $row['names'] ) ? (array) $row['names'] : array();

		if ( empty( $names ) ) {
			printf( '<code>%s</code>', esc_html( $folder ) );

			return;
		}

		echo '<details class="timu-folder">';
		printf( '<summary><code>%s</code></summary>', esc_html( $folder ) );
		echo '<ul class="timu-folder-files">';

		foreach ( $names as $name ) {
			printf(
				'<li><a href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a></li>',
				esc_url( trailingslashit( $baseurl ) . $name ),
				esc_html( basename( $name ) ),
				esc_html__( '(opens in a new tab)', 'thisismyurl-image-support' )
			);
		}

		echo '</ul>';

		$hidden = (int) $row['count'] - count( $names );

		if ( $hidden > 0 ) {
			printf(
				'<p class="timu-folder-more">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: number of files. */
						_n( '%s more file in this folder is not listed.', '%s more files in this folder are not listed.', $hidden, 'thisismyurl-image-support' ),
						number_format_i18n( $hidden )
					)
				)
			);
		}

		echo '</details>';
	}

	/**
	 * The plain facts about how this library is set up.
	 *
	 * @param array $detail Report detail.
	 * @param array $now    Current metrics.
	 */
	private static function render_library_facts( array $detail, array $now ) {
		printf( '<h3>%s</h3>', esc_html__( 'How your pictures are set up', 'thisismyurl-image-support' ) );

		$formats = array();

		foreach ( $detail['formats'] as $ext => $count ) {
			$formats[] = sprintf( '%s %s', number_format_i18n( $count ), strtoupper( $ext ) );
		}

		$per_image = $now['images']
			? number_format_i18n( round( $detail['subsize_files'] / max( 1, (int) $now['images'] ), 1 ), 1 )
			: '0';

		$rows = array(
			array(
				__( 'Kinds of picture you use', 'thisismyurl-image-support' ),
				$formats ? implode( ', ', $formats ) : __( 'None', 'thisismyurl-image-support' ),
			),
			array(
				__( 'Picture sizes your site makes', 'thisismyurl-image-support' ),
				sprintf(
					/* translators: 1: number of registered sizes, 2: average files generated per image. */
					__( '%1$s sizes, which makes about %2$s files for each picture', 'thisismyurl-image-support' ),
					number_format_i18n( (int) $detail['registered_sizes'] ),
					$per_image
				),
			),
			array(
				__( 'Shrinking big pictures', 'thisismyurl-image-support' ),
				empty( $detail['scaling_filtered'] )
					? sprintf(
						/* translators: %s: width in pixels. */
						__( 'WordPress shrinks pictures wider than %spx', 'thisismyurl-image-support' ),
						number_format_i18n( (int) $detail['scaling'] )
					)
					: sprintf(
						/* translators: %s: width in pixels. */
						__( 'WordPress normally shrinks pictures wider than %spx, but something on your site turns that off. If very large pictures keep arriving, this is why.', 'thisismyurl-image-support' ),
						number_format_i18n( (int) $detail['scaling'] )
					),
			),
			array(
				__( 'Uploads folder', 'thisismyurl-image-support' ),
				$detail['yearmonth']
					? __( 'Organised by year and month', 'thisismyurl-image-support' )
					: __( 'One single folder', 'thisismyurl-image-support' ),
			),
		);

		echo '<table class="widefat striped timu-refs"><tbody>';

		foreach ( $rows as $row ) {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $row[0] ), esc_html( $row[1] ) );
		}

		echo '</tbody></table>';

		if ( isset( $detail['formats']['svg+xml'] ) || isset( $detail['formats']['svg'] ) ) {
			echo '<p class="timu-note timu-note-flag">' . esc_html__(
				'Your pictures include SVG files. WordPress does not allow these to begin with, so something on your site was set up to allow them. An SVG is a small program that draws a picture, and it can contain code. This is worth knowing if you took over this site from someone else.',
				'thisismyurl-image-support'
			) . '</p>';
		}
	}

	/**
	 * The history, and the way to take it away with you.
	 *
	 * @param array $history Dated snapshots.
	 */
	private static function render_history( array $history ) {
		printf( '<h3>%s</h3>', esc_html__( 'Progress over time', 'thisismyurl-image-support' ) );

		if ( count( $history ) < 2 ) {
			echo '<p class="timu-prose">' . esc_html__(
				'Only one count so far. Come back tomorrow and there will be something to compare it with. One count is saved each day this page is opened, for a year.',
				'thisismyurl-image-support'
			) . '</p>';
		} else {
			$dates = array_keys( $history );

			echo '<p class="timu-prose">' . esc_html(
				sprintf(
					/* translators: 1: number of readings, 2: first date, 3: last date. */
					__( '%1$s readings, from %2$s to %3$s.', 'thisismyurl-image-support' ),
					number_format_i18n( count( $history ) ),
					(string) reset( $dates ),
					(string) end( $dates )
				)
			) . '</p>';
		}

		$dates = array_keys( $history );
		$first = $dates ? (string) reset( $dates ) : gmdate( 'Y-m-d' );
		$last  = $dates ? (string) end( $dates ) : gmdate( 'Y-m-d' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="timu-export">';
		wp_nonce_field( self::EXPORT_ACTION );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::EXPORT_ACTION ) );

		printf(
			'<span class="timu-export-field"><label for="timu-from">%s</label> <input type="date" id="timu-from" name="from" value="%s" /></span>',
			esc_html__( 'From', 'thisismyurl-image-support' ),
			esc_attr( $first )
		);
		printf(
			'<span class="timu-export-field"><label for="timu-to">%s</label> <input type="date" id="timu-to" name="to" value="%s" /></span>',
			esc_html__( 'To', 'thisismyurl-image-support' ),
			esc_attr( $last )
		);

		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Download as a spreadsheet file (CSV)', 'thisismyurl-image-support' ) );
		echo '</form>';

		echo '<p class="timu-note timu-note-quiet">' . esc_html__(
			'The file is made when you ask for it and holds only the numbers on this page. Nothing is saved on your website and nothing is sent anywhere.',
			'thisismyurl-image-support'
		) . '</p>';
	}
	/**
	 * Ways to help, in the order that actually helps.
	 *
	 * Modelled on the donate page on thisismyurl.com, including the thing that makes that
	 * page work: the list is ranked by what is genuinely most useful, and money is not at
	 * the top of it. The brief here is narrower still, helping this plugin get better, so
	 * sponsorship sits last and the two items that actually improve the software lead.
	 *
	 * Deliberately not a notice, a banner, or anything that follows somebody around
	 * wp-admin. It is the last section of the plugin's own page, which is where a person
	 * who already likes the thing will be, and nowhere else.
	 */
	private static function render_support() {
		echo '<div class="timu-sec">';
		self::section_head(
			__( 'Helping this tool get better', 'thisismyurl-image-support' ),
			__( 'Five things that make a difference, in the order they make a difference.', 'thisismyurl-image-support' )
		);

		echo '<div class="timu-card-lg">';

		echo '<p class="timu-prose">' . esc_html__(
			'This tool is free and will stay free. It takes a few hours each month to look after, and a whole weekend whenever WordPress changes something that breaks it. Here are the ways you can help, starting with the ones that help most.',
			'thisismyurl-image-support'
		) . '</p>';

		$ways = array(
			array(
				'title' => __( 'Tell me when it breaks', 'thisismyurl-image-support' ),
				'body'  => __( 'A note saying what you did and what went wrong helps me more than any rating. A screenshot of something that looks odd is a big help too.', 'thisismyurl-image-support' ),
				'url'   => 'https://github.com/thisismyurl/thisismyurl-image-support/issues/new',
				'cta'   => __( 'Report something', 'thisismyurl-image-support' ),
			),
			array(
				'title' => __( 'Tell me what is missing', 'thisismyurl-image-support' ),
				'body'  => __( 'Some of the best ideas people have sent me were not code. They were someone telling me about a job this tool did not do.', 'thisismyurl-image-support' ),
				'url'   => 'https://github.com/thisismyurl/thisismyurl-image-support/discussions',
				'cta'   => __( 'Suggest something', 'thisismyurl-image-support' ),
			),
			array(
				'title' => __( 'Leave a review', 'thisismyurl-image-support' ),
				'body'  => __( 'A few real sentences about how this helped you mean more than many quick star ratings, and they help other people find it.', 'thisismyurl-image-support' ),
				'url'   => 'https://wordpress.org/support/plugin/thisismyurl-image-support/reviews/',
				'cta'   => __( 'Review on WordPress.org', 'thisismyurl-image-support' ),
			),
			array(
				'title' => __( 'Star it on GitHub', 'thisismyurl-image-support' ),
				'body'  => __( 'It takes ten seconds and costs nothing.', 'thisismyurl-image-support' ),
				'url'   => 'https://github.com/thisismyurl/thisismyurl-image-support',
				'cta'   => __( 'View on GitHub', 'thisismyurl-image-support' ),
			),
			array(
				'title' => __( 'Sponsor the work', 'thisismyurl-image-support' ),
				'body'  => __( 'Last on this list on purpose. Sponsorship keeps these free plugins working with new versions of WordPress and PHP. Part of it each quarter goes to teaching young people in small towns about open-source work. I grew up in one. Nobody showed me the door, and I do not want to keep it shut behind me.', 'thisismyurl-image-support' ),
				'url'   => 'https://github.com/sponsors/thisismyurl',
				'cta'   => __( 'Sponsor on GitHub', 'thisismyurl-image-support' ),
			),
		);

		echo '<ol class="timu-ways">';

		foreach ( $ways as $way ) {
			echo '<li class="timu-way">';
			printf( '<h3>%s</h3>', esc_html( $way['title'] ) );
			printf( '<p>%s</p>', esc_html( $way['body'] ) );
			printf(
				'<a class="timu-way-link" href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a>',
				esc_url( $way['url'] ),
				esc_html( $way['cta'] ),
				esc_html__( '(opens in a new tab)', 'thisismyurl-image-support' )
			);
			echo '</li>';
		}

		echo '</ol>';

		echo '<p class="timu-note">' . esc_html__(
			'If none of this fits your budget, that is completely fine. The first two help this tool more than money does.',
			'thisismyurl-image-support'
		) . '</p>';

		echo '</div></div>';
	}

	/**
	 * Templates for the two file-size units, taken from WordPress so the labels agree.
	 *
	 * The settings field is drawn by PHP and redrawn by JavaScript as it is typed in. If the
	 * two spelled the unit differently, the label would change by itself on save. So the
	 * script is handed the unit as WordPress formats it, with the number swapped for {n}.
	 *
	 * @return array{kb:string,mb:string}
	 */
	public static function size_templates() {
		$kb = str_replace( number_format_i18n( 1, 0 ), '{n}', size_format( KB_IN_BYTES, 0 ) );
		$mb = str_replace( number_format_i18n( 1, 1 ), '{n}', size_format( MB_IN_BYTES, 1 ) );

		return array(
			'kb' => false !== strpos( $kb, '{n}' ) ? $kb : '{n} KB',
			'mb' => false !== strpos( $mb, '{n}' ) ? $mb : '{n} MB',
		);
	}

	/**
	 * Counts for the cards.
	 *
	 * The alt-text counts are ordinary indexed queries and stay cheap. The oversize count
	 * has to read image metadata in PHP, so it reuses the same bounded, cached list the
	 * Media filter uses rather than starting a second scan.
	 *
	 * @return array<string,int>
	 */
	public static function public_counts() {
		return self::counts();
	}

	/**
	 * The counts, computed once and cached.
	 *
	 * @return array<string,int>
	 */
	private static function counts() {
		// The large-files figure is counted against the size index, and the index fills in
		// lazily as images are looked at. On a fresh install nothing has been looked at, so
		// the count came back zero on a library with five large files and wore a green
		// "nothing over your size" while doing it. Built before counting, once, and only when
		// the counts actually have to be recomputed.
		$cache_key = 'timu_image_overview_counts';
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$index_complete = TIMU_Image_Support_Facts::ensure_size_index();

		$mimes = TIMU_Image_Support_Facts::supported_mimes();

		$base = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => $mimes,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		);

		$total = new WP_Query( $base );

		$missing = new WP_Query(
			array_merge(
				$base,
				array(
					'meta_query' => array(
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
			)
		);

		$heavy = new WP_Query(
			array_merge(
				$base,
				array(
					'meta_query' => array(
						array(
							'key'     => TIMU_Image_Support_Facts::SIZE_META,
							'value'   => TIMU_Image_Support_Facts::heavy_bytes(),
							'compare' => '>=',
							'type'    => 'NUMERIC',
						),
					),
				)
			)
		);

		// The broken list is already bounded and cached, and it is normally tiny, so asking
		// how many of those also lack alt text costs a couple of meta reads rather than a
		// second pass over the library.
		$broken       = TIMU_Image_Support_Media_Filters::broken_ids();
		$broken_noalt = 0;

		foreach ( $broken as $id ) {
			if ( '' === TIMU_Image_Support_Facts::stored_alt( $id ) ) {
				++$broken_noalt;
			}
		}

		$counts = array(
			'total'          => (int) $total->found_posts,
			'missing_alt'    => (int) $missing->found_posts,
			'heavy'          => (int) $heavy->found_posts,
			'oversized'      => count( TIMU_Image_Support_Media_Filters::oversized_ids() ),
			'broken'         => count( $broken ),
			'broken_no_alt'  => $broken_noalt,
			// False while some images have no recorded size yet, which makes the large-file count a minimum.
			'index_complete' => $index_complete,
		);

		set_transient( $cache_key, $counts, 5 * MINUTE_IN_SECONDS );

		return $counts;
	}
}
