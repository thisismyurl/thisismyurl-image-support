<?php
/**
 * schema.org/ImageObject JSON-LD emitter for credited attachments.
 *
 * Reads the existing `_thisismyurl_photo_*` credit meta (registered in
 * photo-credits.php) and emits a schema.org/ImageObject node on attachment
 * pages whose attachment carries credit data. No new storage, no new admin
 * surface — this is the consumer the meta keys were always documented to feed.
 *
 * Mapping (only populated fields are emitted; nothing is invented):
 *   - contentUrl / url       the attachment file URL
 *   - name                   the attachment title, when set
 *   - caption                the attachment caption (post_excerpt), when set
 *   - creditText             the human credit string ("Elizabeth Ross")
 *   - creator                Person (or Organization for composites/AI direction)
 *                            carrying the credit name and optional `url`
 *   - copyrightHolder        same entity as creator (the party that holds rights)
 *
 * AI-generated and composite images name the credited party as an Organization
 * rather than a Person: "AI direction by …" and "Composite by …" describe a
 * production role, not a photographer, so Person would overclaim authorship.
 *
 * @package TIMU_Image_Support
 * @since   1.6149
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the ImageObject JSON-LD array for an attachment, or null when the
 * attachment has no credit and so nothing should be emitted.
 *
 * Returns a plain associative array (not encoded) so it is unit-testable and
 * so callers can merge or filter it before output.
 *
 * @since 1.6149
 *
 * @param int $attachment_id Attachment ID.
 * @return array<string,mixed>|null JSON-LD node, or null when there is nothing to say.
 */
function thisismyurl_image_support_build_image_object_schema( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	if ( $attachment_id <= 0 ) {
		return null;
	}

	$data = thisismyurl_image_support_get_photo_credit( $attachment_id );

	// Silent honesty: no credit string means no claim to make.
	if ( '' === $data['credit'] ) {
		return null;
	}

	$schema = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'ImageObject',
		'creditText'  => $data['credit'],
	);

	$content_url = wp_get_attachment_url( $attachment_id );
	if ( is_string( $content_url ) && '' !== $content_url ) {
		$schema['contentUrl'] = $content_url;
		$schema['url']        = $content_url;
	}

	$title = get_the_title( $attachment_id );
	if ( is_string( $title ) && '' !== trim( $title ) ) {
		$schema['name'] = trim( $title );
	}

	$caption = wp_get_attachment_caption( $attachment_id );
	if ( is_string( $caption ) && '' !== trim( $caption ) ) {
		$schema['caption'] = trim( $caption );
	}

	// AI direction and composites credit a production role, not a photographer:
	// model them as an Organization so we don't overclaim individual authorship.
	$entity_type = ( $data['ai_generated'] || $data['composite'] ) ? 'Organization' : 'Person';

	$entity = array(
		'@type' => $entity_type,
		'name'  => $data['credit'],
	);
	if ( '' !== $data['credit_url'] ) {
		$entity['url'] = $data['credit_url'];
	}

	$schema['creator']         = $entity;
	$schema['copyrightHolder'] = $entity;

	/**
	 * Filter the ImageObject JSON-LD node before it is emitted.
	 *
	 * Return null to suppress output for this attachment.
	 *
	 * @since 1.6149
	 *
	 * @param array<string,mixed>|null $schema        The JSON-LD node.
	 * @param int                      $attachment_id Attachment ID.
	 * @param array                    $data          Resolved credit payload.
	 */
	return apply_filters( 'thisismyurl_image_support_image_object_schema', $schema, $attachment_id, $data );
}

/**
 * Emit the ImageObject JSON-LD in the head of an attachment page.
 *
 * Hooked on `wp_head` and gated to `is_attachment()` so the schema describes
 * exactly the resource the page is about. Posts that merely embed a credited
 * image carry their own page-level schema elsewhere; the attachment page is the
 * canonical home for the image's own metadata.
 *
 * @since 1.6149
 *
 * @return void
 */
function thisismyurl_image_support_render_image_object_schema() {
	if ( ! is_attachment() ) {
		return;
	}

	$attachment_id = get_queried_object_id();
	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return;
	}

	$schema = thisismyurl_image_support_build_image_object_schema( $attachment_id );
	if ( null === $schema ) {
		return;
	}

	// Default JSON flags escape `/` to `\/`, which neutralises any `</script>`
	// sequence that could appear inside a string value — the one XSS vector for
	// inline JSON-LD. Do NOT add JSON_UNESCAPED_SLASHES here.
	printf(
		'<script type="application/ld+json">%s</script>' . "\n",
		wp_json_encode( $schema )
	);
}
add_action( 'wp_head', 'thisismyurl_image_support_render_image_object_schema' );
