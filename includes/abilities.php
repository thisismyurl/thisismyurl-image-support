<?php
/**
 * WP 7 Abilities API registration for This Is My URL - Image Support.
 *
 * Exposes the media-library filename sanitization + content-relink batch as a
 * discoverable, REST/AI-invokable ability. The ability wraps the same
 * TIMU_IC::run_cleanup_batch() method the WP-CLI `wp image-support sanitize`
 * command drives, so there is one cleanup implementation and the same gates
 * (master enable filter, destructive opt-in, manage_options) apply everywhere.
 *
 * @package TIMU_Image_Support
 * @since   1.6145
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // Abilities API unavailable (WordPress < 6.9).
		}

		wp_register_ability(
			'thisismyurl-image-support/sanitize-filenames',
			array(
				'label'               => __( 'Sanitize Image Filenames', 'thisismyurl-image-support' ),
				'description'         => __( 'Renames media-library attachment files to SEO-friendly slugs and rewrites matching references in post content. Walks attachments in cursor order one batch at a time. Run with dry_run first to preview proposed renames and relinks; a live run additionally requires the plugin\'s destructive-operations opt-in to be enabled.', 'thisismyurl-image-support' ),
				'category'            => 'site',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'dry_run' => array(
							'type'        => 'boolean',
							'description' => __( 'When true, report proposed renames and relinks without writing any file or post content. Defaults to true so a first call is always safe.', 'thisismyurl-image-support' ),
							'default'     => true,
						),
						'limit'   => array(
							'type'        => 'integer',
							'description' => __( 'Number of attachments to process in this batch. Clamped to 1-50.', 'thisismyurl-image-support' ),
							'minimum'     => 1,
							'maximum'     => 50,
							'default'     => 50,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'renamed', 'merged', 'skipped', 'failed', 'relinked', 'complete', 'dry_run' ),
					'properties'           => array(
						'renamed'  => array(
							'type'        => 'integer',
							'description' => __( 'Count of attachment files renamed (or that would be renamed in a dry run).', 'thisismyurl-image-support' ),
						),
						'merged'   => array(
							'type'        => 'integer',
							'description' => __( 'Count of duplicate attachments merged into an existing original.', 'thisismyurl-image-support' ),
						),
						'skipped'  => array(
							'type'        => 'integer',
							'description' => __( 'Count of attachments skipped (filtered out or filename rejected).', 'thisismyurl-image-support' ),
						),
						'failed'   => array(
							'type'        => 'integer',
							'description' => __( 'Count of attachments that failed to rename.', 'thisismyurl-image-support' ),
						),
						'relinked' => array(
							'type'        => 'integer',
							'description' => __( 'Count of post-content references rewritten to the new filenames.', 'thisismyurl-image-support' ),
						),
						'complete' => array(
							'type'        => 'boolean',
							'description' => __( 'True when the cursor reached the end of the media library (no more attachments to walk).', 'thisismyurl-image-support' ),
						),
						'dry_run'  => array(
							'type'        => 'boolean',
							'description' => __( 'Echoes whether this run was a preview (no writes) or a live run.', 'thisismyurl-image-support' ),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input = array() ) {
					if ( ! function_exists( 'wp_register_ability' ) ) {
						return new WP_Error( 'abilities_unavailable', __( 'The Abilities API is unavailable.', 'thisismyurl-image-support' ) );
					}

					$input   = is_array( $input ) ? $input : array();
					$dry_run = isset( $input['dry_run'] ) ? (bool) $input['dry_run'] : true;
					$limit   = isset( $input['limit'] ) ? max( 1, min( 50, (int) $input['limit'] ) ) : 50;

					if ( empty( $GLOBALS['timu_ic_instance'] ) || ! $GLOBALS['timu_ic_instance'] instanceof TIMU_IC ) {
						return new WP_Error( 'timu_ic_not_loaded', __( 'Image Support is not loaded.', 'thisismyurl-image-support' ) );
					}

					$plugin = $GLOBALS['timu_ic_instance'];

					if ( ! $plugin->is_enabled() ) {
						return new WP_Error( 'timu_ic_disabled', __( 'Image Support is disabled by the thisismyurl_image_support_enabled filter.', 'thisismyurl-image-support' ) );
					}

					if ( ! $dry_run && ! $plugin->is_destructive_confirmed() ) {
						return new WP_Error( 'timu_ic_not_confirmed', __( 'Destructive operations are not confirmed. Enable the destructive-operations opt-in or call with dry_run set to true.', 'thisismyurl-image-support' ) );
					}

					$batch = $plugin->run_cleanup_batch( $dry_run, $limit );

					return array(
						'renamed'  => count( $batch['renamed'] ),
						'merged'   => count( $batch['merged'] ),
						'skipped'  => count( $batch['skipped'] ),
						'failed'   => count( $batch['failed'] ),
						'relinked' => (int) $batch['relinked'],
						'complete' => (bool) $batch['complete'],
						'dry_run'  => $dry_run,
					);
				},
				'permission_callback' => static function (): bool {
					// Touches uploads and post content site-wide; manage_options is
					// the correct bar, not the narrower upload_files.
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					// Destructive, but a logged-in manage_options check fully guards
					// it and dry_run defaults true, so a first call cannot mutate.
					'show_in_rest' => true,
				),
			)
		);
	}
);
