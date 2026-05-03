=== Image Support by thisismyurl ===
Contributors: thisismyurl
Author: Christopher Ross
Author URI: https://thisismyurl.com/
Donate link: https://thisismyurl.com/donate/
Support Link: https://thisismyurl.com/contact/
Tags: webp, media, images, optimization, filenames
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.6123
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clean up image filenames, discover matching WebP files, update content references, and keep backups of renamed originals.

== Description ==

**This plugin is destructive by design.** It renames attachment files on disk, merges duplicate attachments, and rewrites references inside `wp_posts.post_content`. Run a dry-run first on every batch. Take a database backup before flipping the destructive-ops switch. Backups of originals land in `/wp-content/uploads/timu-image-backups/` and that directory is hardened (`.htaccess`, `index.html`, `web.config`) so it is never directory-listable.

Image Support helps site owners tidy up WordPress media filenames and keep content references in sync.

Features include:

* Filename sanitization for image attachments (with a per-file extension whitelist, NUL/RTL/path-traversal rejection, and a deterministic fallback when a name reduces to empty).
* Duplicate detection and trash-mode merge handling during cleanup. Merged duplicates are sent to Trash, not force-deleted, and a JSON sidecar of the attachment record is written before the merge.
* Filesystem discovery for existing WebP files, walked via `WP_Query` over attachments — no assumption that uploads use the default `YYYY/MM` tree.
* Async, opt-in WebP generation for JPEG and PNG images. The plugin never blocks a render thread on GD encoding; missing WebPs are scheduled via `wp_schedule_single_event` and produced on the next cron tick.
* DOM-based content reference replacement when filenames change. Uses `WP_HTML_Tag_Processor` (WP 6.2+); rewrites `<img src>`, `<a href>`, and `<img srcset>` only, host-checked against the site URL, with a per-post revision snapshot taken before any update.
* Backups of original files before renaming. Restore is gated by capability, nonce, and POST (admin-post.php) — never GET.
* Redirect support for requests that still hit old image paths.

The plugin adds a single tools screen under Tools > Image Support where you can preview a batch of changes, toggle the destructive-ops switch, or apply changes.

== Installation ==

1. Upload the thisismyurl-image-support folder to the /wp-content/plugins/ directory.
2. Activate the plugin through the Plugins menu in WordPress.
3. Open Tools > Image Support.
4. Run a dry run before applying changes.
5. Tick the "Allow destructive operations" checkbox in Settings only when you are ready to write.

== Frequently Asked Questions ==

= What happens during a dry run? =
The plugin scans a batch of image attachments, reports proposed filename changes, and shows WebP discoveries without renaming files or writing to `post_content`.

= Does it back up originals? =
Yes. Before a file is renamed, the original file is copied into `/wp-content/uploads/timu-image-backups/`. A JSON sidecar of the attachment's post + meta is also written for any merge operation.

= Is the backup directory web-accessible? =
No. The plugin drops `.htaccess`, `index.html`, and `web.config` into the backup directory at creation so it is never directory-listable on Apache, nginx (via the index file), or IIS.

= Can it work with existing WebP files? =
Yes. The plugin looks for matching WebP files in the same directory as the attachment, regardless of whether the upload tree is `YYYY/MM`.

= Can it generate WebP files? =
Yes, but generation is asynchronous and opt-in. If a matching WebP file is missing, the plugin schedules a one-shot wp-cron event to produce it. Pages render with the original image until the WebP exists. The on-render `the_content` filter is OFF by default and only enables when the `thisismyurl_image_support_enable_dynamic_webp` filter returns true.

= Why is there a destructive-ops switch? =
Because filename renaming, duplicate merging, and `post_content` rewriting are not reversible without the per-post revisions and backup files this plugin produces. The switch makes the destructive nature explicit; the dry-run path always works without it.

= Does it have settings, logs, or ALT text tools? =
Settings: the destructive-ops switch. No logs or ALT text tools yet.

== Support, Contributing & Sponsorship ==

= I want to support you =

I'm building these tools because WordPress developers and site owners deserve straightforward, practical solutions. There's no tracking, no ads, and you don't need to pay to use these plugins.

If they're helpful, here are genuine ways to support the work:

* **Sponsor this project:** Visit https://github.com/sponsors/thisismyurl if sponsorship fits your budget. Sponsorship helps, but it's always optional.
* **Contribute code or ideas:** Opening a pull request, reporting an issue, or testing edge cases is just as valuable as sponsorship. Helping me improve these plugins is a great way to contribute.
* **Share your experience:** A review on my [Google My Business profile](https://business.google.com/refer) or a follow on [WordPress.org](https://profiles.wordpress.org/thisismyurl/), [GitHub](https://github.com/thisismyurl), or [LinkedIn](https://linkedin.com/in/thisismyurl) helps others find this work.

= I found a bug or have a feature idea =

* **File an issue on GitHub:** Visit https://github.com/thisismyurl/thisismyurl-image-support/issues and include your WordPress and PHP version.
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

= 0.6123 =
* Safety: replaced regex-based `post_content` rewrites with `WP_HTML_Tag_Processor` (WP 6.2+). Walks `<img src>`, `<a href>`, and `<img srcset>` only, host-checked, with per-post revisions snapshotted before update.
* Safety: replaced site-wide `UPDATE wp_posts SET post_content = REPLACE(...)` with bounded `WP_Query` batches (50 posts per page, public post types only, revisions excluded).
* Safety: duplicate merges now send the duplicate to Trash, not force-delete. JSON sidecar of attachment record written before merge.
* Safety: destructive operations are gated by a `thisismyurl_image_support_confirm_destructive` option, OFF by default. Dry-run always works without it.
* Safety: filename sanitizer drops the 60-word stop-list (and/or/the/wp/draft/test/...) that caused silent merges; adds extension whitelist, rejects `..`, NUL, RTL override, and leading dots.
* Safety: backup directory hardened with `.htaccess`, `index.html`, `web.config` at creation.
* Safety: file rename is now atomic (write-to-temp + rename + verify). Failures roll back; no half-state on disk.
* Safety: restore handler converted from GET to POST via `admin-post.php`. Byte-length match verified before backup unlink. Realpath-checked target path stays inside uploads.
* Safety: `dynamic_webp_replacement` no longer encodes WebP synchronously inside `the_content`. Missing WebPs are scheduled via `wp_schedule_single_event` and produced asynchronously. The filter itself is OFF by default.
* Safety: `handle_image_404_redirects` now sanitizes `$_SERVER['REQUEST_URI']` properly and uses `wp_safe_redirect`.
* Safety: GD presence is checked with `extension_loaded` before any `imagecreatefrom*` call.
* Quality: i18n loader added; user-facing strings wrapped with `__()` / `esc_html__()` / `esc_html_e()`.
* Quality: `wp_defer_term_counting` and `wp_suspend_cache_invalidation` wrap each cleanup batch.
* Quality: filesystem walker (`scandir` over `YYYY/MM`) replaced with `WP_Query` over attachment post type.
* Quality: sister `TIMU_WEBP_Support` invocation is method-and-version-guarded.
* Quality: hostile `cleanup_menus` is now filterable via `thisismyurl_image_support_hide_submenus`.
* Quality: `Tested up to` corrected from 6.9 (unreleased) to 6.8. `Requires PHP` raised to 8.1. License normalized to "GPLv2 or later". `Network: false` declared.
* Quality: version sprawl resolved — header, readme `Stable tag`, and changelog now agree. `uninstall.php` no longer carries its own version.
* Quality: changelog reconstructed (was previously single-entry).

= 1.5.1229 =
* Legacy version string from a prior numbering scheme. Retained here only for archive context; superseded by 0.6123 onward.

== Upgrade Notice ==

= 0.6123 =
Major safety release. Take a database backup before updating. The destructive-ops switch is now OFF by default — toggle it under Tools > Image Support > Settings before re-running write operations.
