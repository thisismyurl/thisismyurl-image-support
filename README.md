# Image Support by This Is My URL

Current version: 1.6365

Image Support is a WordPress plugin for SEO-focused filename cleanup, media hardening, content reference synchronization, and safe rollback support.

## What It Does

- Tabbed admin interface with Optimize, Settings, and Report tabs.
- Batch AJAX processing with visible spinner and continuous progress updates.
- Search and pagination for pending and managed media lists.
- Filename sanitization for cleaner, SEO-friendlier image slugs.
- Content reference synchronization when file names change.
- Optional optimize-on-upload and background auto optimization.
- Optional auto optimization triggers for wp-admin traffic and WP-Cron.
- Backup and restore support for renamed image assets.
- 404 redirect support for legacy image paths.
- ROI reporting with business-friendly time windows.
- Duplicate cleanup for obviously identical media while preserving links.
- Metadata hardening (strip sensitive metadata + embed creator credit).
- Resize controls to keep oversized images server-safe.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- GD or Imagick image support

## Installation

1. Copy this plugin into `/wp-content/plugins/thisismyurl-image-support/`.
2. Activate it from the WordPress admin.
3. Open `Tools > Image Support`.

## Usage

1. Open `Tools > Image Support`.
2. Configure optimization, automation, and reporting assumptions in Settings.
3. Run `Optimize All` from the Optimize tab.
4. Review measurable impact in the Report tab.

The plugin processes image attachments in batches, updates matching content references, and keeps backup copies for rollback.

## Restore Behavior

Backup files are stored in `/wp-content/uploads/timu-image-backups/`.

When an image has been renamed, the plugin records the original path and filename in post meta so the file can be restored later and old requests can be redirected to the current attachment URL.

## Notes

- This plugin uses a tabbed interface and automation workflow for consistency.
- The focus is filenames, references, metadata safety, duplicates, and SEO-related asset cleanup.

## Versioning

This plugin uses the format 1.Yddd:

- Y = last digit of the year
- ddd = Julian day number for the final day of that year

For 2026, this is 1.6365.

## License

GPLv2 or later.

---

## About This Is My URL

This plugin is built and maintained by [This Is My URL](https://thisismyurl.com/), a WordPress development and technical SEO practice with more than 25 years of experience helping organizations build practical, maintainable web systems.

Christopher Ross ([@thisismyurl](https://profiles.wordpress.org/thisismyurl/)) is a WordCamp speaker, plugin developer, and WordPress practitioner based in Fort Erie, Ontario, Canada. Member of the WordPress community since 2007.

### More Resources

- **Plugin page:** [https://thisismyurl.com/thisismyurl-image-support/](https://thisismyurl.com/thisismyurl-image-support/)
- **WordPress.org profile:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/)
- **Other plugins:** [github.com/thisismyurl](https://github.com/thisismyurl)
- **Website:** [thisismyurl.com](https://thisismyurl.com/)

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
