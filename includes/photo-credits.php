<?php
/**
 * Photo-credit system.
 *
 * Single source of truth for attachment-level credit data and its rendering
 * surface. Four moving parts live here:
 *
 *   1. Seven `_thisismyurl_photo_*` post-meta keys registered on the
 *      `attachment` post type, REST-exposed, gated by `edit_posts`.
 *      Base four: credit, credit_url, ai_generated, ai_model. Edge-case
 *      three: ai_edit, ai_edit_model, composite — for content-synthesised
 *      retouching and multi-source composites.
 *   2. A render filter on the core image block that appends a
 *      `<span class="photo-credit">…</span>` inside the figcaption (creating
 *      one when absent). Decision tree: composite wins over AI-direction
 *      wins over photograph; the ai_edit suffix appends to whichever base
 *      form fired except AI-direction (which already discloses synthesis).
 *   3. IPTC pre-fill on upload (Media Library and programmatic). Reads
 *      By-Line / Credit / Copyright from the file's IPTC block via
 *      `iptcparse()`. Editorial entries are never overwritten. IPTC pre-fill
 *      does NOT auto-populate the AI-edit, AI-edit-model, or composite
 *      fields — no standard IPTC slot exists for those and they are
 *      editorial-decision-only.
 *   4. WP-CLI under `wp image-support photo-credit`:
 *      - `backfill` sweeps every `image/*` attachment and runs IPTC
 *        pre-fill against attachments uploaded before this module shipped.
 *      - `ai-hero-report` surfaces pipeline AI heroes (`*-hero.*`) for
 *        editorial review, with `--auto-flag` defaulting heroes older than
 *        30 days. The credit name written by `--auto-flag` is filterable via
 *        `thisismyurl_image_support_default_credit` (default empty — nothing
 *        is invented unless the site sets it).
 *      Both honour `--dry-run`.
 *
 * The visible `.photo-credit` styling is bundled with this plugin
 * (assets/css/photo-credit.css) so the credit renders without depending on
 * the active theme. A theme that wants to override the look can target the
 * same `.photo-credit` selector with higher specificity.
 *
 * This is a benign feature: it reads and writes its own attachment meta and
 * appends markup to rendered image blocks. It never renames files, merges
 * attachments, or rewrites `post_content`. The plugin's destructive cleanup
 * behaviour does not apply to it.
 *
 * @package TIMU_Image_Support
 * @since   1.6144
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient key prefix for the one-shot "this attachment was just
 * IPTC-prefilled" marker the admin surface reads on first edit-screen load.
 * The full key is suffixed with the attachment ID; the transient stores an
 * array of meta-key strings indicating which fields were filled from IPTC.
 */
const THISISMYURL_IMAGE_SUPPORT_PHOTO_IPTC_TRANSIENT_PREFIX = '_thisismyurl_photo_credit_iptc_prefilled_';

/**
 * Meta key: human-readable credit string ("Elizabeth Ross").
 *
 * NOTE: the meta-key string values in this module are a stable external
 * contract — the ImageObject schema emitter reads them directly, and existing
 * attachment data is stored under them. The PHP constant names carry the
 * plugin prefix; the string values must not change.
 */
const THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META = '_thisismyurl_photo_credit';

/**
 * Meta key: optional URL the credit name links to.
 */
const THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_URL_META = '_thisismyurl_photo_credit_url';

/**
 * Meta key: boolean — true when the image is AI-generated.
 *
 * Stored as `'1'` / `''` to round-trip cleanly through `get_post_meta()` and
 * the block-editor REST surface (post-meta booleans are not natively
 * round-trip clean as `true` / `false` through the meta REST layer).
 */
const THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_GENERATED_META = '_thisismyurl_photo_ai_generated';

/**
 * Meta key: AI model identifier when the image is AI-generated.
 */
const THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_MODEL_META = '_thisismyurl_photo_ai_model';

/**
 * Meta key: boolean — true when AI synthesised new subject matter into a real
 * photograph (sky replacement, object removal, frame extension, AI composite
 * element). Stored as `'1'` / `''` to round-trip cleanly.
 *
 * Spot-healing, dust removal, and minor sensor-artifact correction do NOT set
 * this — those are darkroom-equivalent retouching. The unifying rule: the
 * credit describes what materially happened to produce what the viewer sees.
 * Retouching does not require disclosure; content synthesis always does.
 */
const THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_EDIT_META = '_thisismyurl_photo_ai_edit';

/**
 * Meta key: AI model used for editing when ai_edit is true
 * (e.g. "Adobe Firefly", "gpt-image-1", "midjourney-inpaint").
 */
const THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_EDIT_MODEL_META = '_thisismyurl_photo_ai_edit_model';

/**
 * Meta key: boolean — true when the image is assembled from multiple sources
 * (multi-source composite, regardless of AI involvement). Stored as `'1'` /
 * `''` to round-trip cleanly.
 *
 * When set, the credit phrasing becomes "Composite by {credit}" — composites
 * are described by what they ARE, not how the source elements were made. This
 * takes precedence over both the photograph and AI-direction formats.
 */
const THISISMYURL_IMAGE_SUPPORT_PHOTO_COMPOSITE_META = '_thisismyurl_photo_composite';

/**
 * Default ship date for the `ai-hero-report` sweep window.
 *
 * Heroes uploaded BEFORE this date are pre-existing and eligible for the
 * editorial-review-then-auto-flag workflow. Filterable via
 * `thisismyurl_image_support_photo_credit_ship_date` so a site adopting the
 * feature later can move the window to its own adoption date.
 *
 * Format: YYYY-MM-DD, parsed as start-of-day UTC.
 */
const THISISMYURL_IMAGE_SUPPORT_PHOTO_SHIP_DATE = '2026-05-16';

/**
 * Every managed photo-credit meta key, in registration order.
 *
 * One list, read by the registrar and the delete-on-empty guard, so the set
 * of keys the module owns is declared in exactly one place.
 *
 * @since 1.6144
 *
 * @return string[]
 */
function thisismyurl_image_support_photo_credit_meta_keys() {
	return array(
		THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META,
		THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_URL_META,
		THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_GENERATED_META,
		THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_MODEL_META,
		THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_EDIT_META,
		THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_EDIT_MODEL_META,
		THISISMYURL_IMAGE_SUPPORT_PHOTO_COMPOSITE_META,
	);
}

/**
 * Register attachment post-meta for the credit surface.
 *
 * All seven keys are scoped to the `attachment` post type, REST-exposed for
 * the block-editor sidebar, and gated behind `edit_posts` via `auth_callback`.
 * Sanitisers run on every write — REST, block-editor, IPTC pre-fill, or CLI.
 *
 * Hooked at priority 20 on `init` to land after any core post-type wiring.
 *
 * @since 1.6144
 *
 * @return void
 */
function thisismyurl_image_support_photo_credit_register_meta() {
	$auth_cb = static function () {
		return current_user_can( 'edit_posts' );
	};

	$text_sanitize = static function ( $value ) {
		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	};

	$url_sanitize = static function ( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		return esc_url_raw( $value );
	};

	$bool_sanitize = static function ( $value ) {
		// Round-trip safely across REST (booleans), checkbox POSTs ('1'/'on'),
		// and CLI string args. Anything truthy → '1', anything else → ''.
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_numeric( $value ) ) {
			return ( (int) $value ) > 0 ? '1' : '';
		}
		if ( is_string( $value ) ) {
			$trimmed = strtolower( trim( $value ) );
			return in_array( $trimmed, array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '';
		}
		return '';
	};

	$string_meta = array(
		THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META       => array( 'Human-readable photo credit (e.g. photographer name).', $text_sanitize ),
		THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_URL_META   => array( 'Optional URL the credit name links to.', $url_sanitize ),
		THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_MODEL_META     => array( 'AI model identifier when the image is AI-generated (e.g. "gpt-image-1").', $text_sanitize ),
		THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_EDIT_MODEL_META => array( 'AI model used for editing when ai_edit is true (e.g. "Adobe Firefly").', $text_sanitize ),
	);

	$bool_meta = array(
		THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_GENERATED_META => 'Boolean-as-string: "1" when image is AI-generated, "" otherwise.',
		THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_EDIT_META      => 'Boolean-as-string: "1" when AI synthesised new content into a real photograph, "" otherwise.',
		THISISMYURL_IMAGE_SUPPORT_PHOTO_COMPOSITE_META     => 'Boolean-as-string: "1" when image is a multi-source composite, "" otherwise.',
	);

	foreach ( $string_meta as $key => $spec ) {
		register_post_meta(
			'attachment',
			$key,
			array(
				'type'              => 'string',
				'description'       => $spec[0],
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => $spec[1],
				'auth_callback'     => $auth_cb,
			)
		);
	}

	foreach ( $bool_meta as $key => $description ) {
		register_post_meta(
			'attachment',
			$key,
			array(
				'type'              => 'string',
				'description'       => $description,
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => $bool_sanitize,
				'auth_callback'     => $auth_cb,
			)
		);
	}
}
add_action( 'init', 'thisismyurl_image_support_photo_credit_register_meta', 20 );

/**
 * Delete-on-empty guard for all managed photo-credit meta keys.
 *
 * When a write of '' or null lands on any managed key (from any path — REST,
 * block-editor entity save, or PHP), delete the meta entry instead of storing
 * an empty value. Keeps `metadata_exists()` truthful about presence vs
 * absence, which downstream code (schema emitters, render filter) relies on to
 * distinguish "not set" from "explicitly empty."
 *
 * Hook contract: `update_{$meta_type}_metadata` filter; returning a non-null
 * value short-circuits the standard update. We return `true` after calling
 * `delete_post_meta` so the API treats the operation as succeeded (it did —
 * the meta is gone).
 *
 * @since 1.6144
 *
 * @param mixed  $check      Whether to allow updating the metadata. Null = default behaviour.
 * @param int    $object_id  Attachment ID.
 * @param string $meta_key   Meta key being written.
 * @param mixed  $meta_value Value being written.
 * @return mixed Non-null to short-circuit the default update; otherwise $check unchanged.
 */
function thisismyurl_image_support_photo_credit_delete_on_empty( $check, $object_id, $meta_key, $meta_value ) {
	if ( ! in_array( $meta_key, thisismyurl_image_support_photo_credit_meta_keys(), true ) ) {
		return $check;
	}
	// Treat null, '', and boolean false as deletes.
	if ( null === $meta_value || '' === $meta_value || false === $meta_value ) {
		delete_post_meta( (int) $object_id, $meta_key );
		return true;
	}
	return $check;
}
add_filter( 'update_post_metadata', 'thisismyurl_image_support_photo_credit_delete_on_empty', 10, 4 );

/**
 * Read the full credit payload for an attachment.
 *
 * Returns an associative array with a consistent shape regardless of which
 * fields are populated — callers do not have to null-check each key.
 *
 * @since 1.6144
 *
 * @param int $attachment_id Attachment ID.
 * @return array{credit:string,credit_url:string,ai_generated:bool,ai_model:string,ai_edit:bool,ai_edit_model:string,composite:bool}
 */
function thisismyurl_image_support_get_photo_credit( $attachment_id ) {
	$attachment_id = (int) $attachment_id;

	$empty = array(
		'credit'        => '',
		'credit_url'    => '',
		'ai_generated'  => false,
		'ai_model'      => '',
		'ai_edit'       => false,
		'ai_edit_model' => '',
		'composite'     => false,
	);

	if ( $attachment_id <= 0 ) {
		return $empty;
	}

	return array(
		'credit'        => trim( (string) get_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META, true ) ),
		'credit_url'    => trim( (string) get_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_URL_META, true ) ),
		'ai_generated'  => '1' === (string) get_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_GENERATED_META, true ),
		'ai_model'      => trim( (string) get_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_MODEL_META, true ) ),
		'ai_edit'       => '1' === (string) get_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_EDIT_META, true ),
		'ai_edit_model' => trim( (string) get_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_EDIT_MODEL_META, true ) ),
		'composite'     => '1' === (string) get_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_COMPOSITE_META, true ),
	);
}

/**
 * Build the inner `<span class="photo-credit">…</span>` markup for an attachment.
 *
 * Render formats (the human comes first in every base form; the AI model is
 * the disclosed tool, not the lead credit):
 *   - Composite:    "Composite by {credit}"
 *   - AI-generated: "AI direction by {credit} • {model}" (model when known)
 *                   "AI direction by {credit}"           (model empty)
 *   - Photograph:   "Photograph by {credit}"
 *
 * Decision tree (precedence top-down — first match wins for the BASE form):
 *   1. Empty credit → '' (silent-when-empty: no credit, no markup).
 *   2. `composite` true → "Composite by …" — composites are described by what
 *      they ARE, beating the AI-direction form even when an element was
 *      AI-generated.
 *   3. `ai_generated` true → "AI direction by …" (with optional model).
 *   4. Otherwise → "Photograph by …".
 *
 * Suffix (applied after the base form, except for AI-direction):
 *   - `ai_edit` true → append " (AI edit: {model})" or " (AI-edited)" when the
 *     model is empty. The AI-direction branch already discloses synthesis, so
 *     the suffix is suppressed there to avoid stuttering.
 *
 * When `credit_url` is set, the credit name (only) is wrapped in an
 * `<a href rel="nofollow">…</a>`. All values pass through `esc_html` /
 * `esc_url` before reaching the DOM.
 *
 * @since 1.6144
 *
 * @param int $attachment_id Attachment ID.
 * @return string HTML for the photo-credit span, or '' when nothing should render.
 */
function thisismyurl_image_support_build_photo_credit_html( $attachment_id ) {
	$data = thisismyurl_image_support_get_photo_credit( $attachment_id );

	if ( '' === $data['credit'] ) {
		return '';
	}

	// Wrap the credit name in an anchor when a URL is set. rel="nofollow"
	// because uncontrolled outbound credit links should not pass equity.
	$credit_html = esc_html( $data['credit'] );
	if ( '' !== $data['credit_url'] ) {
		$credit_html = sprintf(
			'<a href="%1$s" rel="nofollow">%2$s</a>',
			esc_url( $data['credit_url'] ),
			esc_html( $data['credit'] )
		);
	}

	$suppress_ai_edit_suffix = false;

	if ( $data['composite'] ) {
		$inner = sprintf(
			/* translators: 1: composite-director name (may be wrapped in <a>). */
			esc_html__( 'Composite by %1$s', 'thisismyurl-image-support' ),
			$credit_html
		);
	} elseif ( $data['ai_generated'] ) {
		if ( '' !== $data['ai_model'] ) {
			$inner = sprintf(
				/* translators: 1: human director name (may be wrapped in <a>), 2: AI model name. */
				esc_html__( 'AI direction by %1$s', 'thisismyurl-image-support' ) . ' &bull; %2$s',
				$credit_html,
				esc_html( $data['ai_model'] )
			);
		} else {
			$inner = sprintf(
				/* translators: 1: human director name (may be wrapped in <a>). */
				esc_html__( 'AI direction by %1$s', 'thisismyurl-image-support' ),
				$credit_html
			);
		}
		// AI-direction already discloses synthesis; the ai_edit suffix would stutter.
		$suppress_ai_edit_suffix = true;
	} else {
		$inner = sprintf(
			/* translators: 1: photographer name (may be wrapped in <a>). */
			esc_html__( 'Photograph by %1$s', 'thisismyurl-image-support' ),
			$credit_html
		);
	}

	if ( $data['ai_edit'] && ! $suppress_ai_edit_suffix ) {
		if ( '' !== $data['ai_edit_model'] ) {
			$inner .= sprintf(
				/* translators: 1: AI editing model name. */
				' ' . esc_html__( '(AI edit: %1$s)', 'thisismyurl-image-support' ),
				esc_html( $data['ai_edit_model'] )
			);
		} else {
			$inner .= ' ' . esc_html__( '(AI-edited)', 'thisismyurl-image-support' );
		}
	}

	return sprintf( '<span class="photo-credit">%s</span>', $inner );
}

/**
 * Inject the photo-credit span into rendered core image blocks.
 *
 * Hooks `render_block_core/image` — the per-block render filter WordPress
 * fires for every server-rendered core/image instance. The block render pass
 * is where the figcaption HTML actually exists; the
 * `wp_get_attachment_image_attributes` filter only sees `<img>` attribute
 * arrays and cannot touch the surrounding `<figure>`/`<figcaption>`.
 *
 * Behaviour:
 *   - Resolve the attachment ID from `$block['attrs']['id']`.
 *   - If credit data is missing, return the block untouched (silent honesty).
 *   - If a `<figcaption>` exists, append the span inside it.
 *   - Otherwise, insert a figcaption before `</figure>` carrying just the span.
 *
 * Uses two narrow regex passes rather than DOMDocument: image-block HTML is
 * small and well-formed, and a DOM round-trip introduces quote-style and
 * whitespace drift.
 *
 * @since 1.6144
 *
 * @param string              $block_content Rendered block HTML.
 * @param array<string,mixed> $block         Parsed block array (`blockName`, `attrs`, …).
 * @return string Modified block HTML.
 */
function thisismyurl_image_support_render_image_with_credit( $block_content, $block ) {
	$attrs         = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
	$attachment_id = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;

	if ( $attachment_id <= 0 ) {
		return $block_content;
	}

	$credit_html = thisismyurl_image_support_build_photo_credit_html( $attachment_id );
	if ( '' === $credit_html ) {
		return $block_content;
	}

	// Belt-and-braces: never inject the same span twice if the filter fires
	// more than once on the same content (e.g. a nested re-render).
	if ( false !== strpos( $block_content, 'class="photo-credit"' ) ) {
		return $block_content;
	}

	// Case 1: existing figcaption — append the span before its closing tag.
	//
	// Insert with a literal str_replace rather than preg_replace: the credit
	// markup is the REPLACEMENT, and a photographer name containing `$1`, `$0`,
	// or `\0` (e.g. "A$AP Photography") would otherwise be read as a
	// backreference and corrupt the output. str_replace never interprets the
	// replacement string. The closing-tag match is case-sensitive because the
	// block editor always emits lowercase `</figcaption>` / `</figure>`; a
	// `stripos` guard above only decides which branch to take.
	if ( false !== stripos( $block_content, '<figcaption' ) ) {
		$pos = stripos( $block_content, '</figcaption>' );
		if ( false === $pos ) {
			return $block_content;
		}
		$tag = substr( $block_content, $pos, strlen( '</figcaption>' ) );
		return substr_replace( $block_content, $credit_html . $tag, $pos, strlen( $tag ) );
	}

	// Case 2: no figcaption — wrap the span in a new one and slot it in before
	// </figure>. wp-element-caption matches the block editor's own figcaption
	// class so the bundled CSS picks it up cleanly.
	$pos = stripos( $block_content, '</figure>' );
	if ( false === $pos ) {
		return $block_content;
	}
	$tag        = substr( $block_content, $pos, strlen( '</figure>' ) );
	$figcaption = sprintf( '<figcaption class="wp-element-caption">%s</figcaption>', $credit_html );

	return substr_replace( $block_content, $figcaption . $tag, $pos, strlen( $tag ) );
}
add_filter( 'render_block_core/image', 'thisismyurl_image_support_render_image_with_credit', 10, 2 );

/**
 * Enqueue the bundled photo-credit stylesheet on the front end.
 *
 * Self-sufficient styling so the credit renders correctly regardless of the
 * active theme. Theme custom properties are referenced with literal fallbacks
 * so the stylesheet stands alone but defers to a theme's tokens when present.
 *
 * @since 1.6144
 *
 * @return void
 */
function thisismyurl_image_support_enqueue_photo_credit_style() {
	if ( is_admin() ) {
		return;
	}
	wp_enqueue_style(
		'thisismyurl-image-support-photo-credit',
		plugins_url( 'assets/css/photo-credit.css', dirname( __FILE__ ) ),
		array(),
		defined( 'TIMU_IMAGE_SUPPORT_VERSION' ) ? TIMU_IMAGE_SUPPORT_VERSION : false
	);
}
add_action( 'wp_enqueue_scripts', 'thisismyurl_image_support_enqueue_photo_credit_style' );

/**
 * Pre-fill credit meta from IPTC headers when an image is uploaded.
 *
 * Hooked on `add_attachment` (post-insert, fires for both Media Library
 * uploads and `wp_insert_attachment()` callers). Reads the IPTC block and
 * stores By-Line as the credit, falling back through Credit and Copyright
 * when By-Line is empty.
 *
 * Never overwrites an editorial entry: if the credit meta is already
 * populated, this is a no-op.
 *
 * @since 1.6144
 *
 * @param int $attachment_id Newly created attachment ID.
 * @return void
 */
function thisismyurl_image_support_photo_credit_iptc_prefill( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	if ( $attachment_id <= 0 ) {
		return;
	}

	// Only image attachments — skip PDFs, audio, video, application/octet-stream.
	$mime = (string) get_post_mime_type( $attachment_id );
	if ( '' === $mime || 0 !== strpos( $mime, 'image/' ) ) {
		return;
	}

	// Don't overwrite editorial entries. Only fill the gap.
	$existing = (string) get_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META, true );
	if ( '' !== trim( $existing ) ) {
		return;
	}

	$file = get_attached_file( $attachment_id );
	if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
		return;
	}

	$iptc = thisismyurl_image_support_photo_credit_read_iptc( $file );
	if ( null === $iptc ) {
		return;
	}

	$credit = thisismyurl_image_support_photo_credit_pick_field( $iptc );
	if ( '' === $credit ) {
		return;
	}

	update_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META, $credit );

	// Stash a one-shot marker so the admin surface can render its "Pre-filled
	// from IPTC — confirm or edit" notice on the first edit-screen load. Read
	// and deleted by the admin layer; never reappears for this attachment.
	$source_field = thisismyurl_image_support_photo_credit_pick_source_field( $iptc );
	set_transient(
		THISISMYURL_IMAGE_SUPPORT_PHOTO_IPTC_TRANSIENT_PREFIX . $attachment_id,
		array( thisismyurl_image_support_photo_credit_iptc_payload_for( $source_field ) ),
		15 * MINUTE_IN_SECONDS
	);
}
add_action( 'add_attachment', 'thisismyurl_image_support_photo_credit_iptc_prefill' );

/**
 * Identify which IPTC field produced the prefilled value, so the admin notice
 * can name the source ("By-Line" vs "Credit") accurately.
 *
 * @since 1.6144
 *
 * @param array<string, array<int, string>> $iptc Parsed IPTC payload.
 * @return string IPTC field code (`2#080`/`2#110`/`2#116`) or '' when none usable.
 */
function thisismyurl_image_support_photo_credit_pick_source_field( $iptc ) {
	foreach ( array( '2#080', '2#110', '2#116' ) as $field ) {
		if ( empty( $iptc[ $field ] ) || ! is_array( $iptc[ $field ] ) ) {
			continue;
		}
		$value = isset( $iptc[ $field ][0] ) ? sanitize_text_field( trim( (string) $iptc[ $field ][0] ) ) : '';
		if ( '' !== $value ) {
			return $field;
		}
	}

	return '';
}

/**
 * Map an IPTC source field code to the meta key it populated, so the admin
 * layer can name the source without knowing IPTC field codes itself.
 *
 * @since 1.6144
 *
 * @param string $source_field IPTC field code from pick_source_field().
 * @return string Marker string the admin surface decodes for notice wording.
 */
function thisismyurl_image_support_photo_credit_iptc_payload_for( $source_field ) {
	// All three source fields land in the same credit meta key today; the
	// source code is what distinguishes the human-facing notice wording.
	switch ( $source_field ) {
		case '2#080': // By-Line.
			return THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META . '::byline';
		case '2#110': // Credit.
			return THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META . '::credit';
		case '2#116': // Copyright Notice.
			return THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META . '::copyright';
		default:
			return THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META;
	}
}

/**
 * Read and parse the IPTC block from an image file.
 *
 * Returns `null` when the file cannot be read, has no APP13 marker, or
 * `iptcparse()` rejects the payload. Otherwise returns the raw IPTC
 * associative array keyed by `2#NNN` field codes.
 *
 * @since 1.6144
 *
 * @param string $file Absolute path to the image file.
 * @return array<string, array<int, string>>|null
 */
function thisismyurl_image_support_photo_credit_read_iptc( $file ) {
	// `iptcparse` ships with the standard PHP build but is absent on some
	// minimal LiteSpeed and shared-host stacks.
	if ( ! function_exists( 'iptcparse' ) || ! function_exists( 'getimagesize' ) ) {
		return null;
	}

	$info = array();
	// Suppress getimagesize's E_WARNING when the file is unreadable or not a
	// recognised image; the null return is handled explicitly below.
	$size = @getimagesize( $file, $info ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( false === $size || ! is_array( $info ) || ! isset( $info['APP13'] ) ) {
		return null;
	}

	$parsed = iptcparse( $info['APP13'] );
	if ( false === $parsed || ! is_array( $parsed ) ) {
		return null;
	}

	return $parsed;
}

/**
 * Pick the best credit string out of a parsed IPTC payload.
 *
 * Priority order:
 *   1. `2#080` By-Line (the photographer)
 *   2. `2#110` Credit (organisation or service)
 *   3. `2#116` Copyright Notice (last-resort hint)
 *
 * Returns the empty string when none of those fields carries usable text.
 *
 * @since 1.6144
 *
 * @param array<string, array<int, string>> $iptc Parsed IPTC payload.
 * @return string
 */
function thisismyurl_image_support_photo_credit_pick_field( $iptc ) {
	foreach ( array( '2#080', '2#110', '2#116' ) as $field ) {
		if ( empty( $iptc[ $field ] ) || ! is_array( $iptc[ $field ] ) ) {
			continue;
		}
		$value = isset( $iptc[ $field ][0] ) ? sanitize_text_field( trim( (string) $iptc[ $field ][0] ) ) : '';
		if ( '' !== $value ) {
			return $value;
		}
	}

	return '';
}

/**
 * Default credit name written by `ai-hero-report --auto-flag`.
 *
 * Empty by default: the plugin never invents a name. A site that wants
 * auto-flag to stamp a house credit sets it via the
 * `thisismyurl_image_support_default_credit` filter or the
 * `thisismyurl_image_support_default_credit` option.
 *
 * @since 1.6144
 *
 * @return string Default credit name, or '' when none is configured.
 */
function thisismyurl_image_support_default_credit() {
	$default = (string) get_option( 'thisismyurl_image_support_default_credit', '' );

	/**
	 * Filter the default credit name applied by `ai-hero-report --auto-flag`.
	 *
	 * @since 1.6144
	 *
	 * @param string $default Credit name. Empty disables auto-flag credit writes.
	 */
	return (string) apply_filters( 'thisismyurl_image_support_default_credit', $default );
}

/*
 * ---------------------------------------------------------------------------
 * WP-CLI: `wp image-support photo-credit <subcommand>`
 *
 * Subcommands:
 *   - backfill          IPTC pre-fill sweep against pre-existing attachments.
 *   - ai-hero-report    Surface pipeline AI heroes for editorial review;
 *                       --auto-flag defaults heroes older than 30 days.
 * ---------------------------------------------------------------------------
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Photo-credit CLI commands.
	 */
	final class TIMU_Image_Support_Photo_Credit_Command {

		/**
		 * Sweep every image attachment and apply IPTC pre-fill.
		 *
		 * Attachments that already carry an editorial credit value are skipped
		 * — editorial wins over IPTC, always.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Report what would change without writing meta.
		 *
		 * [--batch-size=<n>]
		 * : Process this many attachments per query page. Default: 100.
		 *
		 * ## EXAMPLES
		 *
		 *     wp image-support photo-credit backfill --dry-run
		 *     wp image-support photo-credit backfill
		 *     wp image-support photo-credit backfill --batch-size=250
		 *
		 * @when after_wp_load
		 *
		 * @param array<int,string>    $args       Positional args (unused).
		 * @param array<string,string> $assoc_args Associative args.
		 * @return void
		 */
		public function backfill( $args, $assoc_args ) {
			unset( $args );

			$dry_run    = isset( $assoc_args['dry-run'] );
			$batch_size = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 100;

			$totals = array(
				'scanned'   => 0,
				'skipped'   => 0,
				'no_file'   => 0,
				'no_iptc'   => 0,
				'no_credit' => 0,
				'would_set' => 0,
				'set'       => 0,
			);

			$paged = 1;
			while ( true ) {
				$query = new WP_Query(
					array(
						'post_type'              => 'attachment',
						'post_status'            => 'inherit',
						'post_mime_type'         => 'image',
						'posts_per_page'         => $batch_size,
						'paged'                  => $paged,
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
					)
				);

				if ( empty( $query->posts ) ) {
					break;
				}

				foreach ( $query->posts as $attachment_id ) {
					$attachment_id = (int) $attachment_id;
					++$totals['scanned'];

					$existing = (string) get_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META, true );
					if ( '' !== trim( $existing ) ) {
						++$totals['skipped'];
						continue;
					}

					$file = get_attached_file( $attachment_id );
					if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
						++$totals['no_file'];
						continue;
					}

					$iptc = thisismyurl_image_support_photo_credit_read_iptc( $file );
					if ( null === $iptc ) {
						++$totals['no_iptc'];
						continue;
					}

					$credit = thisismyurl_image_support_photo_credit_pick_field( $iptc );
					if ( '' === $credit ) {
						++$totals['no_credit'];
						continue;
					}

					if ( $dry_run ) {
						++$totals['would_set'];
						WP_CLI::log( sprintf( '[dry-run] attachment %d → "%s"', $attachment_id, $credit ) );
						continue;
					}

					update_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META, $credit );
					++$totals['set'];
					WP_CLI::log( sprintf( 'attachment %d → "%s"', $attachment_id, $credit ) );
				}

				++$paged;
			}

			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Scanned:            %d', $totals['scanned'] ) );
			WP_CLI::log( sprintf( 'Skipped (existing): %d', $totals['skipped'] ) );
			WP_CLI::log( sprintf( 'No file on disk:    %d', $totals['no_file'] ) );
			WP_CLI::log( sprintf( 'No IPTC block:      %d', $totals['no_iptc'] ) );
			WP_CLI::log( sprintf( 'IPTC empty:         %d', $totals['no_credit'] ) );

			if ( $dry_run ) {
				WP_CLI::success( sprintf( 'Would set credit on %d attachment(s).', $totals['would_set'] ) );
			} else {
				WP_CLI::success( sprintf( 'Set credit on %d attachment(s).', $totals['set'] ) );
			}
		}

		/**
		 * Surface pipeline AI heroes for editorial review.
		 *
		 * Sweeps image attachments uploaded before the module ship date
		 * (filterable via `thisismyurl_image_support_photo_credit_ship_date`)
		 * whose filename matches the `*-hero.*` pipeline naming convention and
		 * which lack either an editorial credit or an explicit ai_generated
		 * flag.
		 *
		 * Reporting is read-only; `--auto-flag` switches in editorial-default
		 * writes — but ONLY for heroes uploaded more than 30 days ago, anchored
		 * on the attachment's UPLOAD date so the deadline is deterministic. The
		 * credit name written is the configured default
		 * (`thisismyurl_image_support_default_credit`); when that is empty,
		 * auto-flag still sets the AI-generated flag but writes no credit name.
		 *
		 * Does NOT modify the attachment file or any post body — meta only.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format. One of table, json, csv. Default: table.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 *   - csv
		 * ---
		 *
		 * [--auto-flag]
		 * : Write editorial defaults to heroes uploaded more than 30 days ago.
		 * Without this flag, the command is read-only.
		 *
		 * [--dry-run]
		 * : With --auto-flag, preview what would change without writing meta.
		 * Without --auto-flag, this flag is a no-op.
		 *
		 * ## EXAMPLES
		 *
		 *     wp image-support photo-credit ai-hero-report
		 *     wp image-support photo-credit ai-hero-report --format=json
		 *     wp image-support photo-credit ai-hero-report --auto-flag --dry-run
		 *     wp image-support photo-credit ai-hero-report --auto-flag
		 *
		 * @when after_wp_load
		 *
		 * @param array<int,string>    $args       Positional args (unused).
		 * @param array<string,string> $assoc_args Associative args.
		 * @return void
		 */
		public function ai_hero_report( $args, $assoc_args ) {
			unset( $args );

			$format    = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
			$auto_flag = isset( $assoc_args['auto-flag'] );
			$dry_run   = isset( $assoc_args['dry-run'] );

			if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
				WP_CLI::error( sprintf( 'Unknown --format value "%s". Use table, json, or csv.', $format ) );
			}

			/**
			 * Filter the ship date that bounds the AI-hero sweep window.
			 *
			 * @since 1.6144
			 *
			 * @param string $ship_date YYYY-MM-DD, parsed as start-of-day UTC.
			 */
			$ship_date = (string) apply_filters( 'thisismyurl_image_support_photo_credit_ship_date', THISISMYURL_IMAGE_SUPPORT_PHOTO_SHIP_DATE );
			$ship_ts   = strtotime( $ship_date . ' 00:00:00 UTC' );
			if ( false === $ship_ts ) {
				WP_CLI::error( 'Could not parse the photo-credit ship date.' );
			}

			$default_credit    = thisismyurl_image_support_default_credit();
			$now_ts            = time();
			$thirty_days_ago   = $now_ts - ( 30 * DAY_IN_SECONDS );
			$rows              = array();
			$auto_flag_writes  = 0;
			$auto_flag_skipped = 0;

			$paged = 1;
			while ( true ) {
				$query = new WP_Query(
					array(
						'post_type'              => 'attachment',
						'post_status'            => 'inherit',
						'post_mime_type'         => 'image',
						'posts_per_page'         => 100,
						'paged'                  => $paged,
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
					)
				);

				if ( empty( $query->posts ) ) {
					break;
				}

				foreach ( $query->posts as $attachment_id ) {
					$attachment_id = (int) $attachment_id;

					$post = get_post( $attachment_id );
					if ( ! $post instanceof WP_Post ) {
						continue;
					}

					// Pre-ship-date only.
					$upload_ts = strtotime( $post->post_date_gmt . ' UTC' );
					if ( false === $upload_ts || $upload_ts >= $ship_ts ) {
						continue;
					}

					// Filename must match the pipeline hero convention.
					$file = get_attached_file( $attachment_id );
					if ( ! is_string( $file ) || '' === $file ) {
						continue;
					}
					$basename = wp_basename( $file );
					if ( ! preg_match( '/-hero\.[A-Za-z0-9]+$/', $basename ) ) {
						continue;
					}

					// Exclude heroes already decided (credit AND ai flag set).
					$existing_credit = (string) get_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META, true );
					$existing_ai_raw = get_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_GENERATED_META, true );
					$has_credit      = '' !== trim( $existing_credit );
					$has_ai_flag     = '' !== (string) $existing_ai_raw;

					if ( $has_credit && $has_ai_flag ) {
						continue;
					}

					$attached_to  = (int) $post->post_parent;
					$parent_title = '';
					if ( $attached_to > 0 ) {
						$parent = get_post( $attached_to );
						if ( $parent instanceof WP_Post ) {
							$parent_title = (string) $parent->post_title;
						}
					}

					$rows[] = array(
						'ID'                  => $attachment_id,
						'filename'            => $basename,
						'upload_date'         => mysql2date( 'Y-m-d', $post->post_date, false ),
						'attached_to'         => $attached_to > 0 ? (string) $attached_to : '',
						'attached_post_title' => $parent_title,
					);

					// Auto-flag only fires when the upload itself is past the
					// 30-day grace window, anchored on the upload date.
					if ( $auto_flag && $upload_ts < $thirty_days_ago ) {
						if ( $dry_run ) {
							++$auto_flag_writes;
							WP_CLI::log( sprintf(
								'[dry-run] attachment %d (%s) → credit="%s", ai_generated="1", ai_model=""',
								$attachment_id,
								$basename,
								'' !== $default_credit ? $default_credit : '(none — no default credit configured)'
							) );
						} else {
							if ( '' !== $default_credit ) {
								update_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_CREDIT_META, $default_credit );
							}
							update_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_GENERATED_META, '1' );
							update_post_meta( $attachment_id, THISISMYURL_IMAGE_SUPPORT_PHOTO_AI_MODEL_META, '' );
							++$auto_flag_writes;
							WP_CLI::log( sprintf(
								'attachment %d (%s) → auto-flagged AI-generated%s',
								$attachment_id,
								$basename,
								'' !== $default_credit ? ' / credit "' . $default_credit . '"' : ' / no credit (no default configured)'
							) );
						}
					} elseif ( $auto_flag ) {
						++$auto_flag_skipped;
					}
				}

				++$paged;
			}

			// Render the report via WP-CLI's format helper.
			$fields = array( 'ID', 'filename', 'upload_date', 'attached_to', 'attached_post_title' );
			if ( ! empty( $rows ) ) {
				WP_CLI\Utils\format_items( $format, $rows, $fields );
			} elseif ( 'table' === $format ) {
				WP_CLI::log( 'No pre-existing AI-hero candidates found.' );
			} else {
				// json/csv with no rows: emit an empty structure so downstream
				// consumers don't have to special-case the no-output path.
				WP_CLI\Utils\format_items( $format, array(), $fields );
			}

			if ( $auto_flag ) {
				WP_CLI::log( '' );
				if ( $dry_run ) {
					WP_CLI::success( sprintf(
						'Would auto-flag %d hero(es); %d still inside 30-day grace window.',
						$auto_flag_writes,
						$auto_flag_skipped
					) );
				} else {
					WP_CLI::success( sprintf(
						'Auto-flagged %d hero(es); %d still inside 30-day grace window.',
						$auto_flag_writes,
						$auto_flag_skipped
					) );
				}
			}
		}
	}

	WP_CLI::add_command( 'image-support photo-credit', 'TIMU_Image_Support_Photo_Credit_Command' );

	// WP-CLI auto-derives subcommand names from public method names but does
	// not translate underscores → hyphens. Register the hyphenated form
	// explicitly so both spellings resolve to the same method.
	WP_CLI::add_command(
		'image-support photo-credit ai-hero-report',
		array( 'TIMU_Image_Support_Photo_Credit_Command', 'ai_hero_report' ),
		array(
			'shortdesc' => 'Surface pipeline AI heroes for editorial review.',
		)
	);
}
