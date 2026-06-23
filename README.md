# Image Support

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/) [![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/) [![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)

See what is actually in your media library, and clean up what does not belong there.

## What it does

- Scans your media library for orphaned attachments, oversized images, broken references, and missing alt text
- Reports total disk usage broken down by file type
- Finds attachments with no post parent so you can review them before removing anything
- Deletes safely: dry-run preview first, then permanent removal only after you confirm
- Exports audit results to CSV for review or archiving
- Runs optional background passes through WP-Cron for large libraries

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Upload the `thisismyurl-image-support` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open the Image Support panel under Media to run your first audit.

## Versioning

Versions follow `X.Yjjj.hhmm` — year, Julian day, 24-hour time of the build.

## About

Image Support is built and maintained by [Christopher Ross](https://thisismyurl.com/). I build focused WordPress tools for problems that keep showing up across real sites. No tracking, no ads, no upsells.

**WordPress.org:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/) · **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl) · **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
