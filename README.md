# Image Support by thisismyurl

Image Support is a WordPress plugin for cleaning up media filenames, discovering matching WebP files already on disk, updating content references, and restoring originals from backup when needed.

## What It Does

- Sanitizes image filenames by stripping generic words and normalizing names.
- Detects duplicate image slugs during batch cleanup and merges duplicates.
- Rewrites post content references when filenames change.
- Looks for existing WebP files in the current uploads path and up to three previous monthly folders.
- Generates WebP files on the fly for JPEG and PNG images when no matching file exists.
- Stores original files in `/wp-content/uploads/timu-image-backups/` before renaming.
- Redirects requests for moved image URLs to the current attachment URL when a backup mapping exists.

## Requirements

- WordPress 5.0 or higher
- PHP with GD support for fallback WebP generation

## Installation

1. Copy this plugin into `/wp-content/plugins/thisismyurl-image-support/`.
2. Activate it from the WordPress admin.
3. Open `Tools > Image Support`.

## Usage

1. Open `Tools > Image Support`.
2. Set the batch size for the next run.
3. Run `Preview Changes (Dry Run)` to inspect proposed renames and WebP replacements.
4. Run `Update & Sync WebP` to apply the changes.

The plugin processes image attachments in batches, updates matching content references, and keeps a backup copy of any renamed original file.

## Restore Behavior

Backup files are stored in `/wp-content/uploads/timu-image-backups/`.

When an image has been renamed, the plugin records the original path and filename in post meta so the file can be restored later and old requests can be redirected to the current attachment URL.

## Notes

- The admin UI currently provides a single tools screen with batch processing actions.
- The plugin does not currently include ALT text management, metadata dashboards, settings tabs, or operation logs.
- WebP generation falls back to PHP GD when the sister WebP plugin is not available.

## License

GPLv2 or later.