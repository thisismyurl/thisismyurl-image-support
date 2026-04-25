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

== Support, Contributing & Sponsorship ==

= I want to support you =

I'm building these tools because WordPress developers and site owners deserve straightforward, practical solutions. There's no tracking, no ads, and you don't need to pay to use these plugins.

If they're helpful, here are genuine ways to support the work:

* **Sponsor this project:** Visit https://github.com/sponsors/thisismyurl if sponsorship fits your budget. Sponsorship helps, but it's always optional.
* **Contribute code or ideas:** Opening a pull request, reporting an issue, or testing edge cases is just as valuable as sponsorship. Helping me improve these plugins is a great way to contribute.
* **Share your experience:** A review on my [Google My Business profile](https://business.google.com/refer) or a follow on [WordPress.org](https://profiles.wordpress.org/thisismyurl/), [GitHub](https://github.com/thisismyurl), or [LinkedIn](https://linkedin.com/in/thisismyurl) helps others find this work.

= I found a bug or have a feature idea =

* **File an issue on GitHub:** Visit https://github.com/thisismyurl/[plugin-name]/issues and include your WordPress and PHP version.
* **Start a discussion:** Use the Discussions tab on GitHub for questions or ideas.

= I want to contribute code =

Code contributions are welcome and genuinely valuable:

1. Fork the repository on GitHub.
2. Create a feature branch (e.g., `feature/improve-safety`).
3. Make your changes and test thoroughly.
4. Follow WordPress coding standards.
5. Open a pull request with a clear description of what changed and why.

I review PRs thoughtfully and appreciate well-tested contributions. Contributing is never required, but it's genuinely helpful.


== Changelog ==

= 1.5.1229 =
* Current release.
* Adds image cleanup, WebP discovery, content sync, backup, restore, and redirect handling.

== Upgrade Notice ==

= 1.5.1229 =
Use the dry run first so you can review filename and WebP updates before applying them.