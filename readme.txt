=== - Image Support by Christopher Ross ===
Contributors: thisismyurl
Author: Christopher Ross
Author URI: https://thisismyurl.com/
Donate link: https://github.com/sponsors/thisismyurl
Support Link: https://thisismyurl.com/contact/
Tags: webp, media, images, optimization, filenames, photo credits, attribution, alt text, accessibility
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.6164.1421
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clean up image filenames, discover matching WebP files, add photo-credit attribution and alt-text fallbacks, and keep backups of renamed originals.

== Description ==

Image Support groups two kinds of feature, and it matters which is which:

**The cleanup features are destructive by design.** Filename sanitization, duplicate merging, and content re-syncing rename attachment files on disk, merge duplicate attachments, and rewrite references inside `wp_posts.post_content`. These run ONLY after you tick the "Confirm destructive operations" option. Run a dry-run first on every batch. Take a database backup before flipping the switch. Backups of originals land in `/wp-content/uploads/timu-image-backups/` and that directory is hardened (`.htaccess`, `index.html`, `web.config`) so it is never directory-listable.

**The photo-credit and alt-text features are benign.** They read and write their own attachment meta and append markup to rendered image blocks. They never rename files, merge attachments, or rewrite `post_content` — installing the plugin for photo credits will not touch your files or your content, and the destructive-ops switch has no effect on them.

Image Support helps site owners tidy up WordPress media filenames, keep content references in sync, attribute their imagery, and meet alt-text accessibility expectations.

Features include:

* Filename sanitization for image attachments (with a per-file extension allowlist, NUL/RTL/path-traversal rejection, and a deterministic fallback when a name reduces to empty).
* Duplicate detection and trash-mode merge handling during cleanup. Merged duplicates are sent to Trash, not force-deleted, and a JSON sidecar of the attachment record is written before the merge.
* Filesystem discovery for existing WebP files, walked via `WP_Query` over attachments — no assumption that uploads use the default `YYYY/MM` tree.
* Async, opt-in WebP generation for JPEG and PNG images. The plugin never blocks a render thread on GD encoding; missing WebPs are scheduled via `wp_schedule_single_event` and produced on the next cron tick.
* DOM-based content reference replacement when filenames change. Uses `WP_HTML_Tag_Processor` (WP 6.2+); rewrites `<img src>`, `<a href>`, and `<img srcset>` only, host-checked against the site URL, with a per-post revision snapshot taken before any update.
* Backups of original files before renaming. Restore is gated by capability, nonce, and POST (admin-post.php) — never GET.
* Redirect support for requests that still hit old image paths.
* A developer surface: WP-CLI commands (`wp image-support sanitize|relink|status`), action hooks bracketing each rename and relink, and filters at every meaningful decision point (master enable gate, filename slug rule, per-attachment process gate, relink scope and statuses, processable MIME types).
* Photo-credit attribution (benign). Seven attachment-meta fields — credit name, credit link, AI-generated flag and model, AI-edit flag and model, and composite flag — surfaced through an attachment-edit meta box and a `core/image` block-editor sidebar panel. A render filter appends a `.photo-credit` line to the image's figcaption ("Photograph by …", "AI direction by … • model", "Composite by …"), with bundled CSS so it renders without a supporting theme. IPTC By-Line / Credit / Copyright pre-fill on upload, and schema.org/ImageObject JSON-LD (`creditText` / `creator` / `copyrightHolder`) emitted on attachment pages for any credited image. `wp image-support photo-credit backfill` sweeps existing attachments; `ai-hero-report` surfaces pipeline AI heroes for editorial review.
* Alt-text accessibility fallback (benign). A filter on `wp_get_attachment_image_attributes` fills an empty `alt` from the attachment's stored alt meta, then its title, then a humanised filename. Decorative images (`data-decorative`) are left silent.

The plugin adds a single tools screen under Tools > Image Support where you can preview a batch of changes, toggle the destructive-ops switch, or apply changes. The photo-credit and alt-text features need no setup — they activate on plugin activation and do nothing until an attachment has credit data or a rendered image is missing its alt.

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
Settings: the destructive-ops switch (cleanup only). The plugin includes an alt-text fallback that fills an empty `alt` attribute from the attachment's stored alt meta, its title, or a humanised filename — no setup required. No logs yet.

= Will installing this for photo credits rename my files or change my content? =
No. The photo-credit and alt-text features are benign: they read and write their own attachment meta and append a credit line to rendered image blocks. They never rename files, merge attachments, or rewrite `post_content`, and the destructive-ops switch does not affect them. The destructive behaviour is confined to the filename-cleanup, duplicate-merge, and content-resync features, which run only after you opt in.

= How do I add a photo credit? =
Open any attachment in the Media Library and fill the "Photo credit" meta box, or select a `core/image` block in the editor and use the "Photo credit" panel in the sidebar. Both write the same attachment meta, so a value set in one shows up in the other. Credits render as a line in the image's figcaption on the front end; leave the fields blank to render nothing. Existing uploads can be swept for IPTC By-Line / Credit data with `wp image-support photo-credit backfill`.

= Are there hooks and filters for developers? =
Yes. The cleanup pipeline can be disabled programmatically with the `thisismyurl_image_support_enabled` filter (default true) without deactivating the plugin. The filename slug rule (`thisismyurl_image_support_sanitized_filename`), the per-attachment process gate (`thisismyurl_image_support_should_process`), the relink scope (`thisismyurl_image_support_relink_post_types`, `thisismyurl_image_support_relink_post_statuses`), and the processable MIME list (`thisismyurl_image_support_processable_mime_types`) are all filterable. Action hooks bracket every rename (`thisismyurl_image_support_before_rename`, `_after_rename`, `_filename_sanitized`, `_rename_failed`) and fire after each relink (`thisismyurl_image_support_content_relinked`). The earlier `thisismyurl_image_support_enable_dynamic_webp` and `thisismyurl_image_support_hide_submenus` filters are unchanged. WP-CLI commands `wp image-support sanitize`, `wp image-support relink`, and `wp image-support status` script the same operations, with `--dry-run` and capability checks on the mutating ones. See README.md for the full reference.

== Support, Contributing & Sponsorship ==

= I want to support you =

I build these tools because WordPress sites in the wild keep hitting the same problems, and a small, focused plugin is usually the right fix. They're free to use, with no tracking and no ads.

If one of them saves you time, here are the genuine ways to help:

* **Sponsor the work:** [GitHub Sponsors](https://github.com/sponsors/thisismyurl) is the simplest way. Any amount helps, and none of it is expected.
* **Contribute code or ideas:** A pull request, a bug report, or a tested edge case is worth as much as a donation. Helping me improve these plugins is a great way to contribute.
* **Share it:** A note on [WordPress.org](https://profiles.wordpress.org/thisismyurl/), [GitHub](https://github.com/thisismyurl), or [LinkedIn](https://linkedin.com/in/thisismyurl) helps other people find work that might save them the same afternoon.

This plugin is built and maintained by [Christopher Ross](https://thisismyurl.com/), the WordPress development and technical SEO practice of Christopher Ross. I help teams build WordPress sites that stay secure, fast, and maintainable, and I write small, focused plugins like this one for the problems those sites keep running into.

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

= 1.6149 =
* Shipped the schema.org/ImageObject JSON-LD emitter the photo-credit module always documented: attachment pages now output `creditText`, `creator`, and `copyrightHolder` (Person for photographs, Organization for AI-direction and composites) read from the existing credit meta. AI-generated and composite images credit a production role rather than a photographer.
* Security: the photo-credit render filter now inserts credit markup with a literal string splice instead of `preg_replace`, so a credit name containing `$1`, `$0`, or `\0` (e.g. "A$AP Photography") is no longer interpreted as a regex backreference.
* Data safety: uninstall no longer deletes `/uploads/timu-image-backups/` (the only recovery path for renamed originals) unless `TIMU_IMAGE_SUPPORT_DELETE_BACKUPS_ON_UNINSTALL` is explicitly defined truthy. Uninstall now also removes the previously orphaned `_thisismyurl_photo_*` attachment meta and the `thisismyurl_image_support_default_credit` option.
* Reliability: the backup-directory hardening files and the merge-manifest JSON sidecar are now written through WP_Filesystem instead of raw `file_put_contents`, so they no longer fail silently on FTP/SSH filesystem mounts.
* Removed the dead legacy `updater.php` (`FWO_GitHub_Updater`); the live updater is `github-updater.php` (`TIMU_GitHub_Release_Updater`).

= 1.6148 =
* Added WordPress 7.0 Abilities API support: the `thisismyurl-image-support/sanitize-filenames` ability exposes the filename-sanitization and content-relink batch (the same operation as `wp image-support sanitize`) for discovery and REST/AI invocation. Guarded by `manage_options`, the master enable filter, and the destructive-operations opt-in; defaults to a dry run.

= 1.6147 =
* Unified plugin versioning to the x.Yddd calendar-version scheme.
* Confirmed compatibility with WordPress 7.0.


= 1.6144 =
* Developer surface: added the master gate filter `thisismyurl_image_support_enabled` (default true) at the cleanup chokepoint — both the admin batch and the new CLI batch honour it. Recovery (restore from backup) deliberately stays ungated.
* Developer surface: added decision-point filters — `thisismyurl_image_support_sanitized_filename` (slug rule), `thisismyurl_image_support_should_process` (per-attachment gate), `thisismyurl_image_support_relink_post_types` and `thisismyurl_image_support_relink_post_statuses` (relink scope, extracted from two inline copies), and `thisismyurl_image_support_processable_mime_types` (which formats the batch will rename).
* Developer surface: added lifecycle actions — `thisismyurl_image_support_before_rename`, `thisismyurl_image_support_after_rename`, `thisismyurl_image_support_filename_sanitized` (success, old + new), `thisismyurl_image_support_rename_failed` (failure), and `thisismyurl_image_support_content_relinked` (with the matched-reference report and write-allowed flag).
* Developer surface: added WP-CLI commands `wp image-support sanitize` (rename + relink batch, `--all` / `--limit` / `--dry-run`), `wp image-support relink` (filesystem WebP discovery + content relink, `--dry-run`), and read-only `wp image-support status`. Mutating commands check `current_user_can( 'manage_options' )`, honour the enable gate, and refuse a non-dry-run unless destructive operations are confirmed.
* Quality: extracted `process_image_update()` into a thin orchestrator over a `do_process_image_update()` worker so the rename lifecycle actions fire from one place while every existing error return is preserved verbatim.
* Quality: the headless cleanup batch advances the walk cursor to the highest scanned attachment ID so an `--all` run always makes forward progress past skipped or failed attachments.
* Feature: photo-credit attribution (benign — no files or content touched). Seven attachment-meta fields (credit, credit link, AI-generated flag + model, AI-edit flag + model, composite flag) registered for REST and the block editor, an attachment-edit meta box and a `core/image` sidebar panel sharing the same data, a render filter that appends a `.photo-credit` line to the figcaption, bundled CSS so it renders theme-independently, IPTC pre-fill on upload, and schema.org/ImageObject credit fields. WP-CLI: `wp image-support photo-credit backfill` and `ai-hero-report`. The auto-flag default credit is filterable (`thisismyurl_image_support_default_credit`, empty by default — nothing is invented).
* Feature: alt-text accessibility fallback (benign). A `wp_get_attachment_image_attributes` filter fills an empty `alt` from stored alt meta → attachment title → humanised filename; decorative images (`data-decorative`) stay silent.
* Docs: re-scoped the "destructive by design" framing so it applies only to the filename-cleanup / merge / content-resync features. The photo-credit and alt-text features are documented as benign and never consult the destructive-ops switch.
* Build: rebuilt the `core/image` photo-credit editor bundle so its JavaScript text domain is `thisismyurl-image-support`, matching `wp_set_script_translations()` so the panel's strings are translatable under the plugin's own domain. Added reproducible `@wordpress/scripts` build tooling (`package.json`, `webpack.config.js`) that compiles the bundle from `assets/editor/photo-credit-panel.jsx`.
* Quality: version bump to Julian day 144 (2026-05-24) under the `x.Yddd` scheme.

= 1.6143 =
* First full release (class 1). The 0.6xxx line was pre-release on the `x.Yddd` scheme.
* Standardized the donation link to GitHub Sponsors.

= 0.6124 =
* Quality: version bump to match the `x.Yddd` scheme on Julian day 124 (2026-05-04). No code changes from 0.6123.
* Quality: `.distignore` added so the .org build excludes dev-only files from the deployed zip.

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

= 1.6144 =
Adds two benign features — photo-credit attribution and an alt-text accessibility fallback — plus a full developer surface (WP-CLI `wp image-support sanitize|relink|status`, action hooks, and filters). The new features never touch files or post content. The destructive-ops switch and the dry-run-first discipline are unchanged, and existing filters keep their names and signatures.

= 1.6143 =
First full release. The destructive-ops switch remains OFF by default — toggle it under Tools > Image Support > Settings before re-running write operations.

= 0.6124 =
Hygiene-only bump on top of the 0.6123 safety release. The destructive-ops switch remains OFF by default — toggle it under Tools > Image Support > Settings before re-running write operations.

= 0.6123 =
Major safety release. Take a database backup before updating. The destructive-ops switch is now OFF by default — toggle it under Tools > Image Support > Settings before re-running write operations.
