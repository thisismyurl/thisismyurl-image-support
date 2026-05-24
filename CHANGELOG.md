# Changelog

All notable changes to **Image Support by thisismyurl.com** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses the `x.Yddd` versioning scheme: `x` = release class (0 = pre-release, 1 = full), `Y` = last digit of year, `ddd` = Julian day.

## [0.6144] - 2026-05-24

### Added
- **Master gate filter `thisismyurl_image_support_enabled`** (default `true`) at the cleanup chokepoint. Both the admin batch and the new WP-CLI batch route through it, so a developer can disable the rename/relink/merge pipeline per environment without deactivating the plugin. Restore-from-backup deliberately does **not** consult the gate — disabling cleanup must never disable rollback.
- **Decision-point filters.** `thisismyurl_image_support_sanitized_filename` (adjust the slug rule), `thisismyurl_image_support_should_process` (per-attachment gate with the source path in hand), `thisismyurl_image_support_relink_post_types` and `thisismyurl_image_support_relink_post_statuses` (relink scope — extracted into resolvers from the two inline copies that previously diverged silently), and `thisismyurl_image_support_processable_mime_types` (which formats the batch will rename).
- **Lifecycle actions.** `thisismyurl_image_support_before_rename` / `_after_rename` bracket each rename; `_filename_sanitized` fires on success with old and new names; `_rename_failed` fires on I/O failure; `_content_relinked` fires after each relink pass with the matched-reference report and a write-allowed flag (so a listener can distinguish a dry-run preview from an applied change).
- **WP-CLI command set** under `wp image-support`: `sanitize` (rename + relink batch, `--all`, `--limit`, `--dry-run`, `--format`), `relink` (filesystem WebP discovery + content relink, `--dry-run`, `--format`), and read-only `status`. Mutating commands check `current_user_can( 'manage_options' )`, honour the enable gate, and refuse a non-dry-run unless `thisismyurl_image_support_confirm_destructive` is set. Registered in `includes/class-timu-image-support-cli.php`.
- **Headless runtime methods** `run_cleanup_batch()` and `run_webp_discovery()` so the CLI shares the admin screen's logic, gate, and walk cursor without echoing admin HTML.
- **Photo-credit attribution** (`includes/photo-credits.php`, `includes/photo-credits-admin.php`). A benign feature — it never renames files, merges attachments, or rewrites `post_content`. Seven attachment-meta keys (`_thisismyurl_photo_credit`, `_thisismyurl_photo_credit_url`, `_thisismyurl_photo_ai_generated`, `_thisismyurl_photo_ai_model`, `_thisismyurl_photo_ai_edit`, `_thisismyurl_photo_ai_edit_model`, `_thisismyurl_photo_composite`) registered for REST and the block editor. An attachment-edit meta box (Surface 1) and a `core/image` block-editor sidebar panel (Surface 2) share the same data via the attachment entity record. A `render_block_core/image` filter appends a `<span class="photo-credit">` to the figcaption — "Photograph by …", "AI direction by … • model", "Composite by …", with an "(AI edit: …)" suffix — and the styling ships in `assets/css/photo-credit.css` so it renders without a supporting theme. IPTC By-Line / Credit / Copyright pre-fill on `add_attachment`, never overwriting an editorial entry. WP-CLI: `wp image-support photo-credit backfill` and `wp image-support photo-credit ai-hero-report`. The auto-flag default credit is filterable via `thisismyurl_image_support_default_credit` (empty by default — the plugin never invents a name), and the sweep window via `thisismyurl_image_support_photo_credit_ship_date`.
- **Alt-text accessibility fallback** (`includes/image-alt-fallback.php`). A benign filter on `wp_get_attachment_image_attributes` that fills an empty `alt` from the attachment's stored alt meta → post title → humanised filename. Decorative images (a non-empty `data-decorative` attribute alongside an empty `alt`) are left silent.

### Migrated
- Photo credits and the alt-text fallback moved in from the site-specific plugin (`thisismyurl-site`) so they ship in the published plugin. Internal function names were re-prefixed to `thisismyurl_image_support_*` and the text domain to `thisismyurl-image-support`; the seven `_thisismyurl_photo_*` meta-key string values were kept byte-identical because existing attachment data and the ImageObject schema emitter depend on them. The `'Christopher Ross'` hard-coded auto-flag credit was replaced with the `thisismyurl_image_support_default_credit` filter/option (default empty).

### Documentation
- **Re-scoped the "destructive by design" warning.** The plugin header, readme short description, Description, and FAQ now make explicit that only the filename-cleanup / duplicate-merge / content-resync features are destructive and gated by the opt-in switch; the photo-credit and alt-text features are benign and never consult that switch. A user installing for photo credits will not have their files renamed.

### Changed
- **`process_image_update()` split into an orchestrator over a `do_process_image_update()` worker.** The orchestrator owns the before/after/sanitized/failed actions and fires each from exactly one place; every existing error return inside the worker is preserved verbatim.
- **The headless cleanup batch advances the walk cursor to the highest scanned attachment ID**, so an `--all` CLI run always makes forward progress past skipped or failed attachments instead of re-scanning the same page.
- **Version bump** to `0.6144` (Julian day 144, 2026-05-24). Header `Version:` and readme `Stable tag:` agree.

### Unchanged
- The pre-existing `thisismyurl_image_support_enable_dynamic_webp`, `thisismyurl_image_support_confirm_destructive`, and `thisismyurl_image_support_hide_submenus` filters keep their exact names and signatures. Default behaviour is identical to `0.6124`; every new filter reproduces today's behaviour at its default value.

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
