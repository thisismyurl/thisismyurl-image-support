=== Image Support by thisismyurl ===
Contributors: thisismyurl
Author: Christopher Ross
Author URI: https://thisismyurl.com/
Donate link: https://thisismyurl.com/donate/
Support Link: https://thisismyurl.com/contact/
Tags: webp, media, images, optimization, filenames
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 0.6112
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clean up image filenames, discover matching WebP files, update content references, and keep backups of renamed originals.

== Description ==

Image Support helps site owners tidy up WordPress media filenames and keep content references in sync.

Features include:

* Filename sanitization for image attachments.
* Duplicate detection and merge handling during cleanup.
* Filesystem discovery for existing WebP files.
* Fallback WebP generation for JPEG and PNG images.
* Content reference replacement when filenames change.
* Backups of original files before renaming.
* Redirect support for requests that still hit old image paths.

The plugin adds a single tools screen under Tools > Image Support where you can preview a batch of changes or apply them.

== Installation ==

1. Upload the thisismyurl-image-support folder to the /wp-content/plugins/ directory.
2. Activate the plugin through the Plugins menu in WordPress.
3. Open Tools > Image Support.
4. Run a dry run before applying changes.

== Frequently Asked Questions ==

= What happens during a dry run? =
The plugin scans a batch of image attachments, reports proposed filename changes, and shows WebP discoveries without renaming files.

= Does it back up originals? =
Yes. Before a file is renamed, the original file is copied into /wp-content/uploads/timu-image-backups/.

= Can it work with existing WebP files? =
Yes. The plugin looks for matching WebP files in the current uploads folder and up to three previous monthly upload directories.

= Can it generate WebP files? =
Yes. If a matching WebP file is missing, the plugin can generate one for JPEG and PNG files by using PHP GD. If the sister WebP plugin is installed, that conversion path is used first.

= Does it have settings, logs, or ALT text tools? =
No. The current plugin provides a single tools screen for previewing and applying cleanup batches.

== Changelog ==

= 1.5.1229 =
* Current release.
* Adds image cleanup, WebP discovery, content sync, backup, restore, and redirect handling.

== Upgrade Notice ==

= 1.5.1229 =
Use the dry run first so you can review filename and WebP updates before applying them.