<?php
/**
 * Vault soft-adapter.
 *
 * One job: take an extra safety snapshot through whichever backup engine is
 * installed, before any destructive file operation (convert / optimize /
 * restore). It prefers Vault, falls back to the older Shadow Guardian engine,
 * and gracefully does nothing when neither is present.
 *
 * This adapter is intentionally a no-op safety net layered ON TOP of each
 * plugin's own per-file backup. The plugin keeps its own backups/restore path
 * regardless — Vault is extra insurance, never the only copy.
 *
 * This is the canonical copy shared verbatim across the image / webp / heic /
 * svg / bmp plugin family. Keep it engine-agnostic: the only plugin-specific
 * input is the context slug and the label passed by the caller.
 *
 * @package TIMU_Image_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Route a pre-operation safety snapshot to the active backup engine.
 */
class TIMU_IC_Backup_Adapter {

    /**
     * Context slug recorded on every snapshot this plugin requests.
     *
     * @var string
     */
    const CONTEXT = 'timu-image-support';

    /**
     * Take a safety snapshot through the best available backup engine.
     *
     * When `$paths` carries absolute file paths, Vault snapshots just those
     * files (targeted mode), which is the fast path for "back up the one file
     * I am about to overwrite." An empty `$paths` requests the engine's default
     * scope. Shadow Guardian has no targeted mode, so it always runs its own
     * default-scope backup when it is the active engine.
     *
     * @param string        $label Human-readable label for the backup index row.
     * @param array<string> $paths Optional absolute file paths for a targeted snapshot.
     *
     * @return bool True when a snapshot was attempted, false when no engine was present.
     */
    public static function snapshot( $label, array $paths = array() ) {
        $label = sanitize_text_field( (string) $label );

        if ( class_exists( '\\ThisIsMyURL\\Vault\\Backup' ) ) {
            \ThisIsMyURL\Vault\Backup::snapshot(
                array(
                    'context'         => self::CONTEXT,
                    'label'           => $label,
                    'paths'           => array_values( array_filter( array_map( 'strval', $paths ) ) ),
                    'exclude_uploads' => true,
                )
            );

            return true;
        }

        if ( class_exists( '\\ThisIsMyURL\\Shadow\\Guardian\\Backup_Manager' ) ) {
            \ThisIsMyURL\Shadow\Guardian\Backup_Manager::create_backup(
                array(
                    'trigger'         => 'manual',
                    'context'         => self::CONTEXT,
                    'label'           => $label,
                    'exclude_uploads' => false,
                )
            );

            return true;
        }

        // No shared backup engine is active. The plugin's own per-file backup
        // still runs in the caller, so the destructive operation stays safe.
        return false;
    }
}
