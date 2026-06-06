# Changelog

All notable changes to **Christopher Ross - Image Support** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses the `x.Yddd` versioning scheme: `x` = release class (0 = pre-release, 1 = full), `Y` = last digit of year, `ddd` = Julian day.

## [1.6144] - 2026-05-24

### Added
- **Photo-credit attribution (benign — never touches files or `post_content`).** Seven attachment-meta fields — credit name, credit link, AI-generated flag and model, AI-edit flag and model, and a composite flag — registered for REST and the block editor. An attachment-edit meta box and a `core/image` block-editor sidebar panel share the same data. A render filter appends a `.photo-credit` line to the image's figcaption ("Photograph by …", "AI direction by … • model", "Composite by …") with bundled CSS so it renders without a supporting theme. IPTC By-Line / Credit / Copyright pre-fill on upload, and full schema.org/ImageObject `creditText` / `creator` / `copyrightHolder` output. The auto-applied default credit is filterable via `thisismyurl_image_support_default_credit` (empty by default — nothing is invented).
- **Alt-text accessibility fallback (benign).** A `wp_get_attachment_image_attributes` filter fills an empty `alt` from stored alt meta, then attachment title, then a humanised filename. Images marked `data-decorative` are left silent for screen readers.
- **WP-CLI surface.** `wp image-support sanitize` (rename + relink batch, `--all` / `--limit` / `--dry-run`), `wp image-support relink` (filesystem WebP discovery + content relink, `--dry-run`), read-only `wp image-support status`, and `wp image-support photo-credit backfill` / `ai-hero-report`. Mutating commands check `current_user_can( 'manage_options' )`, honour the master enable gate, and refuse a non-dry-run unless destructive operations are confirmed — they never silently no-op.
- **Master enable gate filter `thisismyurl_image_support_enabled`** (default `true`) at the cleanup chokepoint. Both the admin batch and the CLI batch honour it; restore from backup deliberately stays ungated.
- **Decision-point filters.** `thisismyurl_image_support_sanitized_filename` (slug rule), `thisismyurl_image_support_should_process` (per-attachment gate), `thisismyurl_image_support_relink_post_types` and `thisismyurl_image_support_relink_post_statuses` (relink scope, extracted from two inline copies into shared resolvers), and `thisismyurl_image_support_processable_mime_types` (which formats the batch will rename).
- **Lifecycle actions.** `thisismyurl_image_support_before_rename`, `thisismyurl_image_support_after_rename`, `thisismyurl_image_support_filename_sanitized` (success, old + new + path), `thisismyurl_image_support_rename_failed` (failure), and `thisismyurl_image_support_content_relinked` (with the matched-reference report and the write-allowed flag).
- **Reproducible build tooling.** `@wordpress/scripts` (`package.json`, `package-lock.json`, `webpack.config.js`) compiles the `core/image` photo-credit editor bundle from `assets/editor/photo-credit-panel.jsx`. `.gitignore` added; `.distignore` updated so `webpack.config.js` and `assets/editor` are excluded from the .org build zip.
- **`TIMU_IMAGE_SUPPORT_VERSION` / `_DIR` / `_URL` constants** read by the feature modules for asset enqueuing and cache-busting.

### Changed
- **`process_image_update()` split into a thin orchestrator over a `do_process_image_update()` worker** so the rename lifecycle actions fire from exactly one place each while every existing error return is preserved verbatim.
- **Admin section heading renamed** from "Image Optimization & Deep Sync" to "Cleanup & WebP Sync".
- **The headless cleanup batch advances the walk cursor to the highest scanned attachment ID** so an `--all` run always makes forward progress past skipped, failed, or no-op attachments.
- **Plugin Description re-scoped** so the "destructive by design" framing applies only to filename cleanup, merge, and content re-sync; photo-credit and alt-text are documented as benign and never consult the destructive-ops switch.

## [1.6143] — 2026-05-23

### Changed
- Promoted to a full release (class 1). The `0.6xxx` line was pre-release on the `x.Yddd` scheme.
- Standardized the donation link to GitHub Sponsors (`https://github.com/sponsors/thisismyurl`).

## [0.6124] - 2026-05-04

### Changed
- Version bump to keep the `x.Yddd` scheme aligned with the release date (Julian day 124, 2026-05-04). No code changes from `0.6123`; this release ships the hygiene additions below.

### Added
- `.distignore` so the .org build excludes dev-only files (`.github/`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `phpcs.xml.dist`, etc.) from the deployed plugin zip.

## [0.6123] - 2026-05-03

### Security
- **`merge_duplicate_assets()` no longer force-deletes attachments.** Duplicates are sent to Trash via `wp_delete_attachment( $id, false )`. A JSON sidecar of the attachment record (post + meta + attached file path + URL) is written to `uploads/timu-image-backups/manifests/` before the merge.
- **All destructive operations gated by an opt-in option.** `thisismyurl_image_support_confirm_destructive` defaults to `false`. Dry-run paths always work without the gate.
- **`sync_content_references()` rewritten with `WP_HTML_Tag_Processor` (WP 6.2+).** Walks `<img src>`, `<a href>`, and `<img srcset>` only. Skips elements outside the site host. Per-post revision snapshot via `wp_save_post_revision()` taken before any update. The previous regex-on-`post_content` could match inside `<code>`, `<pre>`, attribute strings, and JSON, with no rollback path.
- **Site-wide `UPDATE wp_posts SET post_content = REPLACE(...)` is gone.** Replaced with bounded `WP_Query` batches (50 posts per page, public post types only, revisions excluded).
- **`handle_restore_request()` converted from GET to POST** via `admin-post.php`. Capability check, nonce, and POST verb required. Byte-length verified between source and copy before backup unlink. Realpath check ensures restore target stays inside the uploads directory.
- **Filename sanitizer hardened.** Drops the 60-word stop-list (`and`, `or`, `the`, `wp`, `draft`, `test`, ...) that produced silent attachment merges. Adds extension whitelist tied to MIME support, rejects `..`, NUL, RTL override character, and leading-dot filenames.
- **Backup directory hardened at creation.** `.htaccess` (Apache deny), `index.html` (silence), `web.config` (IIS deny) dropped at directory-create time.
- **Atomic file rename.** `process_image_update()` writes to `{dest}.tmp`, renames to `{dest}`, then unlinks source. Failure at any step rolls the file system back; no half-state.
- **`handle_image_404_redirects()` sanitizes `$_SERVER['REQUEST_URI']` properly** with `esc_url_raw( wp_unslash( ... ) )` and uses `wp_safe_redirect`.
- **`dynamic_webp_replacement` filter is OFF by default** and never encodes WebP synchronously inside `the_content`. Missing WebPs are scheduled via `wp_schedule_single_event`.

### Changed
- **Version sprawl resolved.** Header, readme `Stable tag`, and changelog all on `0.6123`. `uninstall.php` no longer carries its own version string.
- **Plugin header updated.** `Tested up to` corrected from 6.9 (unreleased) to 6.8. `Requires at least` raised to 6.4. `Requires PHP` raised to 8.1. `License` normalized to "GPLv2 or later". `Network: false` declared. `Domain Path` added.
- **GD presence checked** with `extension_loaded( 'gd' )` and `function_exists( 'imagewebp' )` before any GD call.
- **Sister-plugin invocation guarded.** `TIMU_WEBP_Support::convert_to_webp` is only called when class + method exist and (when defined) `TIMU_WEBP_SUPPORT_VERSION >= 0.6000`.
- **`scandir` filesystem walker replaced with `WP_Query`** over the attachment post type. The previous walker assumed the default `YYYY/MM` upload tree and broke on flat layouts.
- **`cleanup_menus` is filterable** via `thisismyurl_image_support_hide_submenus`. Operators who want the sister-plugin menu entries back can pass an empty array.
- **i18n loader added** (`load_plugin_textdomain`). User-facing strings wrapped with `__()` / `esc_html__()` / `esc_html_e()`.
- **Cleanup batch wrapped** in `wp_defer_term_counting` and `wp_suspend_cache_invalidation` to avoid O(N²) recounts and cache thrash.
- **All redirects use `wp_safe_redirect`.**
- **All `$wpdb` SQL uses brace-style table prefixes** (`{$wpdb->postmeta}`) consistently.
- **Admin batch limit clamped to 50** in both the form (`max="50"`) and `handle_cleanup` (`min( 50, ... )`).

### Added
- Async WebP generation via `wp_schedule_single_event` and the `thisismyurl_image_support_generate_webp` action.
- Per-request memoization in `verify_and_locate_webp` to prevent stat()-storms on pages with many duplicate `<img>` tags.
- `thisismyurl_image_support_confirm_destructive` option + admin checkbox.
- `thisismyurl_image_support_enable_dynamic_webp` filter (OFF by default).
- `thisismyurl_image_support_hide_submenus` filter.
- Visible warning banner on the Tools > Image Support page.
- `uninstall.php` now removes plugin options and clears the scheduled WebP-generation hook.

### Removed
- The 60-word filename stop-list. Catastrophic-collision risk; not a recoverable behaviour.
- The synchronous on-render GD encoding code path inside `the_content`. Async generation only.
- The `scandir`-over-`YYYY/MM` filesystem walker.
- `Version: 1.5.1229` from `uninstall.php` (single source of truth = plugin header + readme).

## Earlier

The plugin previously shipped under a different versioning scheme (e.g. `1.5.1229`). Detailed history before `0.6123` is not reconstructed here; consult the git log on `thisismyurl/thisismyurl-image-support` for commit-level provenance.
