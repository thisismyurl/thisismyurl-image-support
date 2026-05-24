/**
 * Photo-credit panel for the block editor (Surface 2).
 *
 * Injects a `<PanelBody>` titled "Photo credit" into the InspectorControls of
 * every `core/image` block. The four canonical fields (credit name, credit
 * link, AI-generated toggle, AI model) bind to the *attachment's* post-meta,
 * not the block's attributes — so a value set here is identical to a value
 * set on the attachment-edit screen meta box (Surface 1) and vice versa.
 *
 * Strings match the attachment-edit meta box (Surface 1) exactly. Drift
 * between this surface and the meta box is refused.
 *
 * Save lifecycle:
 *   - useEntityProp produces edits in the core entity store for the
 *     attachment record. Those edits are independent from the post being
 *     edited — saving the post does NOT automatically flush attachment edits.
 *   - We subscribe to the editor's save lifecycle and, when a post save
 *     starts and there are pending attachment edits, dispatch
 *     saveEditedEntityRecord for the attachment so the credit changes
 *     persist alongside the post save the editor just triggered.
 *
 * Capability:
 *   - The block editor itself requires `edit_posts`; users who can't reach
 *     the editor never see this panel. The meta's auth_callback is the
 *     security boundary on writes.
 *   - TODO: switch to disable+explain when contributor role activates on
 *     thisismyurl.com (spec §7.4 + §7.5 resolution). At that point, render
 *     a `<Notice status="warning">` with the permission-disabled-tooltip
 *     copy when the current user lacks `edit_posts` on the attachment.
 *
 * @package TIMU_Image_Support
 */

import { addFilter } from '@wordpress/hooks';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, Notice } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect, subscribe, select, dispatch } from '@wordpress/data';
import { Fragment } from '@wordpress/element';
import { createHigherOrderComponent } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

/**
 * Meta-key constants. Mirror the PHP constants in includes/photo-credits.php.
 * Kept inline rather than localized via wp_localize_script — they're stable,
 * the indirection would obscure the contract, and a divergence would be
 * caught on first save anyway.
 */
const META_CREDIT = '_thisismyurl_photo_credit';
const META_CREDIT_URL = '_thisismyurl_photo_credit_url';
const META_AI_GENERATED = '_thisismyurl_photo_ai_generated';
const META_AI_MODEL = '_thisismyurl_photo_ai_model';

/**
 * Validate URL shape on the client side, mirroring the meta-box server-side
 * check. Returns true for the empty string (optional field) and for any
 * well-formed http(s) URL; false otherwise.
 *
 * @param {string} value Candidate URL.
 * @returns {boolean}
 */
function isValidCreditUrl( value ) {
	if ( ! value || '' === value.trim() ) {
		return true;
	}
	try {
		const url = new URL( value );
		return 'http:' === url.protocol || 'https:' === url.protocol;
	} catch ( e ) {
		return false;
	}
}

/**
 * Panel body for one attachment. Extracted so the BlockEdit wrapper can
 * return null cleanly when there's no attachment to bind to (image block
 * without a selected image, or a placeholder state).
 *
 * @param {Object} props
 * @param {number} props.attachmentId Attachment ID from the block's attributes.
 * @returns {JSX.Element|null}
 */
function PhotoCreditPanelBody( { attachmentId } ) {
	const [ rawMeta, setMeta ] = useEntityProp( 'postType', 'attachment', 'meta', attachmentId );
	const meta = rawMeta || {};

	// useEntityProp returns undefined on the very first render until the
	// entity record resolves. Surface a brief loading state rather than
	// rendering empty fields that would clobber stored meta on save.
	const isResolving = useSelect(
		( s ) => ! s( 'core' ).hasFinishedResolution( 'getEntityRecord', [ 'postType', 'attachment', attachmentId ] ),
		[ attachmentId ]
	);

	const credit = meta[ META_CREDIT ] || '';
	const creditUrl = meta[ META_CREDIT_URL ] || '';
	const aiGenerated = '1' === ( meta[ META_AI_GENERATED ] || '' );
	const aiModel = meta[ META_AI_MODEL ] || '';

	const urlValid = isValidCreditUrl( creditUrl );
	const aiModelMissing = aiGenerated && '' === aiModel.trim();

	/**
	 * Patch one or more meta fields. Always passes the FULL meta object back
	 * (merged) because useEntityProp's setter replaces the value wholesale
	 * for object props — passing a partial would drop the other meta keys
	 * from the optimistic store state.
	 *
	 * @param {Object} patch Subset of meta keys to update.
	 */
	const update = ( patch ) => {
		setMeta( { ...meta, ...patch } );
	};

	if ( isResolving ) {
		return (
			<PanelBody title={ __( 'Photo credit', 'thisismyurl-image-support' ) } initialOpen={ false }>
				<p>{ __( 'Loading credit data…', 'thisismyurl-image-support' ) }</p>
			</PanelBody>
		);
	}

	return (
		<PanelBody title={ __( 'Photo credit', 'thisismyurl-image-support' ) } initialOpen={ false }>
			{ ! urlValid && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Credit link is not a valid URL. Use the full address including https:// — for example, https://example.com/portfolio.', 'thisismyurl-image-support' ) }
				</Notice>
			) }
			{ aiModelMissing && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'AI model is required when "This image is AI-generated" is turned on. Name the model, or turn the toggle off.', 'thisismyurl-image-support' ) }
				</Notice>
			) }
			<TextControl
				label={ __( 'Credit name', 'thisismyurl-image-support' ) }
				help={ __( 'The name that appears under the image on the front end. Leave blank to render no credit.', 'thisismyurl-image-support' ) }
				value={ credit }
				onChange={ ( value ) => update( { [ META_CREDIT ]: value } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Credit link (URL)', 'thisismyurl-image-support' ) }
				help={ __( 'Optional. The credit name links to this URL when set. Leave blank for an unlinked credit.', 'thisismyurl-image-support' ) }
				type="url"
				placeholder="https://"
				value={ creditUrl }
				onChange={ ( value ) => update( { [ META_CREDIT_URL ]: value } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<ToggleControl
				label={ __( 'This image is AI-generated', 'thisismyurl-image-support' ) }
				help={
					aiGenerated
						? __( 'This image will be marked as AI-generated. Name the model below.', 'thisismyurl-image-support' )
						: __( 'Turn on when the image was generated by an AI model.', 'thisismyurl-image-support' )
				}
				checked={ aiGenerated }
				onChange={ ( next ) => {
					if ( next ) {
						update( { [ META_AI_GENERATED ]: '1' } );
					} else {
						// Toggle off: clear AI metadata. Empty string here is
						// translated to delete_post_meta by the sanitize layer
						// (bool_sanitize returns ''; the server explicitly
						// deletes when the value is empty in the meta-box
						// save handler). Passing '' is the correct REST shape
						// for "no value".
						update( {
							[ META_AI_GENERATED ]: '',
							[ META_AI_MODEL ]: '',
						} );
					}
				} }
				__nextHasNoMarginBottom
			/>
			{ aiGenerated && (
				<TextControl
					label={ __( 'AI model', 'thisismyurl-image-support' ) }
					help={ __( 'The model that generated the image (e.g. gpt-image-1, midjourney-v6, stable-diffusion-xl).', 'thisismyurl-image-support' ) }
					placeholder="gpt-image-1"
					value={ aiModel }
					onChange={ ( value ) => update( { [ META_AI_MODEL ]: value } ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			) }
		</PanelBody>
	);
}

/**
 * HOC that wraps every block's BlockEdit. For `core/image` blocks with a
 * resolved attachment ID, we append our `<InspectorControls>` panel.
 * Every other block falls through to BlockEdit untouched.
 */
const withPhotoCreditPanel = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		if ( 'core/image' !== props.name ) {
			return <BlockEdit { ...props } />;
		}
		const attachmentId = props.attributes && props.attributes.id ? parseInt( props.attributes.id, 10 ) : 0;
		if ( ! attachmentId ) {
			// Placeholder image block with no attachment yet — nothing to bind.
			return <BlockEdit { ...props } />;
		}
		return (
			<Fragment>
				<BlockEdit { ...props } />
				<InspectorControls>
					<PhotoCreditPanelBody attachmentId={ attachmentId } />
				</InspectorControls>
			</Fragment>
		);
	};
}, 'withPhotoCreditPanel' );

addFilter( 'editor.BlockEdit', 'thisismyurl-image-support/photo-credit-panel', withPhotoCreditPanel );

/**
 * Cross-entity save bridge.
 *
 * useEntityProp dirties the attachment entity, but the post-editor's Save /
 * Update / Publish button only saves the POST entity. To keep the panel's
 * "no separate save button" promise (spec §0 + §4), we listen for the
 * post-save lifecycle and flush any pending attachment edits at the same
 * moment.
 *
 * The subscriber runs at module load. Idempotent — we only fire the
 * attachment save when a post save is actually starting AND there are
 * pending edits to flush. The `inFlight` ref blocks the duplicate dispatch
 * the lifecycle naturally produces (isSavingPost flips true → we react;
 * if we didn't gate, every subsequent re-render during the save would
 * re-dispatch).
 */
( function bootstrapCrossEntitySave() {
	let wasSaving = false;
	const inFlightAttachmentSaves = new Set();

	subscribe( () => {
		const editorStore = select( 'core/editor' );
		const coreStore = select( 'core' );
		if ( ! editorStore || ! coreStore ) {
			return;
		}

		const isSaving = !! ( editorStore.isSavingPost && editorStore.isSavingPost() );
		const isAutosaving = !! ( editorStore.isAutosavingPost && editorStore.isAutosavingPost() );

		// Detect the rising edge of a real (non-autosave) post save.
		if ( isSaving && ! isAutosaving && ! wasSaving ) {
			wasSaving = true;

			// Find every attachment entity record that currently has edits.
			// `getEntityRecordsEditing` is not a public selector; instead we
			// rely on the editor enumerating all entity records the user
			// touched via getEntityRecordEdits per-ID. We can't enumerate
			// IDs blindly, so we scan the block tree of the current post
			// for core/image blocks and check each one's attachment for
			// pending edits.
			const blocks = editorStore.getBlocks ? editorStore.getBlocks() : [];
			const attachmentIds = collectImageAttachmentIds( blocks );

			attachmentIds.forEach( ( attachmentId ) => {
				const edits = coreStore.getEntityRecordEdits( 'postType', 'attachment', attachmentId );
				if ( edits && Object.keys( edits ).length > 0 && ! inFlightAttachmentSaves.has( attachmentId ) ) {
					inFlightAttachmentSaves.add( attachmentId );
					dispatch( 'core' )
						.saveEditedEntityRecord( 'postType', 'attachment', attachmentId )
						.finally( () => {
							inFlightAttachmentSaves.delete( attachmentId );
						} );
				}
			} );
		} else if ( ! isSaving && wasSaving ) {
			wasSaving = false;
		}
	} );
}() );

/**
 * Walk a block tree, returning every `core/image` block's attachment ID.
 *
 * Recurses through innerBlocks so images inside a Group / Columns / Cover
 * still register. Deduplicates via Set — the same image inserted twice in a
 * post points to the same attachment, and one save is sufficient.
 *
 * @param {Array} blocks Block tree to walk.
 * @returns {Array<number>} Deduplicated attachment IDs.
 */
function collectImageAttachmentIds( blocks ) {
	const ids = new Set();
	const visit = ( list ) => {
		if ( ! Array.isArray( list ) ) {
			return;
		}
		list.forEach( ( block ) => {
			if ( ! block ) {
				return;
			}
			if ( 'core/image' === block.name && block.attributes && block.attributes.id ) {
				ids.add( parseInt( block.attributes.id, 10 ) );
			}
			if ( block.innerBlocks ) {
				visit( block.innerBlocks );
			}
		} );
	};
	visit( blocks );
	return Array.from( ids );
}
