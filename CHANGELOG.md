# Changelog

All notable changes to **Christopher Ross - Image Support** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses the `x.Yddd` versioning scheme: `x` = release class (0 = pre-release, 1 = full), `Y` = last digit of year, `ddd` = Julian day.

## 1.6291 (2026-10-18)

### Fixed
- The Image Support page threw an uncaught exception when the uploads folder, or a theme or must-use plugin folder, held a subfolder PHP could not open. Both directory walks now use `CATCH_GET_CHILD` and a try/catch, and an unreadable folder is skipped.
- Authors (`upload_files`) could see the names of untracked image files and link to them, including files in private form and store plugin folders. Folder names and links are now administrator-only (`manage_options`); other roles see a count. `gravity_forms`, `wpforms`, `woocommerce_uploads`, `edd`, `give` and similar folders are skipped by the scan (filter: `timu_image_support_untracked_skip_folders`). Those folders, and on a network's main site the `sites` folder holding other sites' uploads, are skipped inside the walk and use none of its file limit.
- The library measurement loaded every attachment with `posts_per_page => -1` and made several disk calls per image. It now reads attachments in batches of 500 with one query per batch and uses the file sizes WordPress recorded, falling back to the plugin's own size index for images that predate that. It holds a short file name per file, so memory grows with the library; the default limit is 50,000 attachments. Past the limit the page says so, leaves the untracked count out and does not write a history reading.
- The reference scan loaded all post content, custom fields and settings into one array. Each source is now read in keyset-paged batches of 200 rows and condensed page by page.
- A failed `preg_match_all` was treated as "nothing found", which marked every image as unreferenced. A failure is now recorded and the page reports the scan as incomplete.
- Untracked-file false positives: attached SVGs, PDF preview images, edited-image backups and non-`inherit` attachments. File names keep their original case for display and links.
- The oversized and no-file counts covered only the newest 2,000 images without saying so.
- `esc_url( get_edit_post_link() )` passed null to `esc_url()` for an Author viewing an image they cannot edit (PHP 8.1 deprecation).
- The size index and caches did not update for changes made through REST, WP-CLI or cron. The cache hooks are now registered on every request.
- The Media search replaced another plugin's `groupby`; it now appends to it.
- Uninstall cleaned only the current site on a multisite network.
- File-size units in the settings script were hard-coded English.
- The reference scan missed non-ASCII and percent-encoded file names, matched `wp-image-12` inside `wp-image-1234`, and called the site icon and custom logo unreferenced. Duplicate postmeta rows no longer double an image's size.
- The large-file count says when some images have no recorded size yet.
- Documents, audio and video no longer get a size index entry.
- `ensure_size_index()` ran before the counts cache was checked on every overview load.

### Changed
- Classes are renamed `TIMU_Image_Support_*` so another plugin in the same family cannot collide with them.
- The plugin description, the "leaves alone" list and the uninstall text now describe what the plugin stores.
- All fourteen developer filters are documented in the readme and on the page.
- When the library is larger than the measurement covers, untracked files and duplicates are shown as not counted rather than as zero, and no history reading is recorded.
- The reference scan recognises the site icon and custom logo (stored as IDs), accented, percent-encoded and dotted file names, and never lets one invalid UTF-8 byte blank a post or file on WordPress before 6.9.
- On the main site of a network, other sites' uploads are skipped.

## 1.6229 (2026-08-17)

### Added
- Every untracked folder is now listed rather than the largest twelve, and each one opens to show the files inside it, linked so you can look at one. The point of that table is to account for the number above it, and a truncated list left somebody wondering what was in the part they were not shown. Up to twenty-five names are kept per folder and the rest are counted, so the cached report stays small on a site with tens of thousands of stray files.
- A line showing one number over time in each of the two places it means something: images missing alt text, and untracked image files. Points are evenly spaced rather than spaced by date, because a reading is only taken on days the page is opened and a date axis would imply a continuous record that does not exist. The line takes its colour from the direction of travel rather than the sign of the arithmetic, since down is the good direction for both.
- **"About this image library"**, in the place the settings used to occupy. What the library weighs and how much of that is generated sizes rather than uploads, image files in the uploads folder that no attachment accounts for, likely duplicates, images with no recorded uploader, formats in use, registered sizes, whether upload scaling is on, and whether uploads are organised by year and month. Facts about a library rather than faults in it, which is the half that stays useful on a library already in good order.
- **A reading kept for each day the page is opened**, for a year, so the numbers can be compared over time. There is no scheduled task and nothing runs in the background, so days nobody looked have no reading, and the page says so rather than implying a continuous record.
- **A CSV download of that history for any date range.** Built in memory from numbers the plugin measured, gated by the same capability as the page and by a nonce, with every field passed through a guard against spreadsheet formula injection. Nothing is written to the server and nothing is sent anywhere.
- **Untracked image files reported by folder rather than as a file list**, because a good proportion of them should not be deleted: sizes left behind by a theme change whose parent image is healthy, and directories a plugin manages for itself. Images only; the uploads folder collects plenty that is none of this plugin's business.
- A **Settings** tab, so the two thresholds live on their own screen instead of halfway down the overview.
- **Media > Image Support.** A page that explains what the plugin does and reports what it actually found in this library: how many images are missing alt text, how many are oversized, how many are large files, each linking straight to that set on the Media list. Counts rather than feature descriptions, because "missing alt text" means nothing until it says how many, on this site, at which point it becomes a job with an end.
- **Two settings**, promoted from developer filters: the oversize multiplier and the large-file threshold. The multiplier will not go below 1.3, since below that it would flag ordinary healthy uploads. A filter still overrides either, so a site configured in code keeps behaving that way after somebody saves the screen.
- **Plugins-screen links**, "How it works" and "Your images". The plugin adds columns to an existing screen and has no menu of its own, so activating it and going looking produced nothing at all. Worse, WordPress shows Media as a grid by default, where there is no table to add columns to, so the most likely first experience of a working plugin was that it did nothing.
- Two explanatory diagrams on the overview page, drawn as inline SVG rather than shipped as screenshots. A screenshot of wp-admin goes stale the next time the admin design shifts, has to ship as a binary, and cannot be read by a screen reader.
- **Status on the count cards**, so a number reads as settled, worth a look, or worth setting time aside rather than sitting there uninterpreted. Carried by an icon, a word and a colour together, never colour alone. Only the two cards that describe a real problem carry one: the library total is a denominator, not a verdict, and large files are counted against a size the site owner chose themselves, so calling that urgent would be scolding somebody for their own setting. Urgency needs both a share of the library and a real number, since ten missing descriptions means something different in a library of twelve than in one of nine hundred.

- **"Where images are used"**, a second tab listing every image twenty at a time against the places a reference was actually found: featured-image meta, post and page content, custom fields, site and theme settings, and the files of the active theme and must-use plugins. It exists because core's "Unattached" filter answers a different question. `post_parent` records how a file was uploaded, not how it is used, and on the library this was built against 23% of unattached images were live on the site while 86 attached images were absent from the post they were attached to. Nothing here ever says an image is unused; the strongest claim it makes is that no reference was found, which is a statement about the search.
- **Detection for images whose file has gone missing**, as a count on the overview and a filter on the Media list. An attachment can outlive its file, and WordPress carries on listing it as though it were there. Found on a real 768-image library the day this was written: two entries with no file on disk at all, one of which was the library's only "missing alt text" result. The plugin was reporting a photograph that did not exist as work to be done.
- Where undescribed images turn out to be missing files, the overview says so and links to them, rather than quietly adjusting the alt-text number. The count stays a plain database query; the correction is stated in words next to it.

- **A "helping this get better" section** at the foot of the overview, ranked by what genuinely helps: a bug report first, a missing-feature description second, a review third, a star fourth, sponsorship last. Money is at the bottom on purpose, and the closing line says plainly that the first two are not the consolation prize. It is a section on the plugin's own page, not a notice that follows anybody around wp-admin.

### Changed
- Stopped firing core's `big_image_size_threshold` filter to find out what it is set to. There is no upload in progress on a settings screen, so every argument had to be invented, and any plugin listening on that filter would have been handed a fake file and asked to answer about it as though it were real. The page now reports core's documented default and says separately whether something on the site is changing it, which is the honest version of the same fact. Flagged by Plugin Check; the same mistake had been fixed once already earlier in this release and crept back in.
- Removed a stray audit log that tooling had written inside the plugin directory, and excluded the repository's own documents from the distributed build. The download is now fifteen files, all of them plugin runtime.
- Reframed what this plugin says it is. It described its mechanism, "adds alt text, dimensions and file size to the Media list", which invited the reader to think "so, columns". It is a reporting tool for a library somebody has inherited: what is undescribed, what is oversized, what is broken, what nothing appears to refer to. Same plugin, different answer to why anybody would install it.
- Dropped "image optimization" from the tags. This release optimises nothing, and fishing for that search was the kind of small dishonesty the rest of the plugin refuses.
- On the "where images are used" list, the unreferenced images are now announced once at the head of their run rather than on every row. Twenty identical lines down a column stop being read at all, and the old treatment was italic grey, which is WordPress's own signal for secondary text that can be skipped. It was carrying the most consequential fact on the screen. The group heading repeats the caveat too, so somebody who reached page nine of seventeen without reading the card at the top still meets it beside the list they are about to act on.
- The heading on that tab said the same six words as the tab itself, about forty pixels apart, so it echoed the navigation rather than saying anything. It now names what the reader is looking at.
- The caveat about what "no reference found" does not mean now comes first in the card, above the sentence describing what was searched. It was second, behind a count of files.
- That sentence also claimed to read "theme and plugin files" while only reading the active theme and must-use plugins. Ordinary plugins are not scanned, and the text now says so, because an image referenced only inside a plugin's own code will show up in this report as not found.
- The large-files figure now carries a grey "nothing wrong, just large" marker rather than no marker at all. A bare number sitting beside four that were labelled read as unfinished, but this count is measured against a size the site owner chose, so it stays outside the green, amber and red ramp. Three colours pass a verdict; grey reports a fact.
- The two settings are now separated under their own headings, each with its own drawing, because they measure different things and a person could reasonably read two rows of one table as two versions of one idea. Dimensions are how many pixels an image contains; file size is how much a visitor downloads. The second drawing makes the point directly: two copies of one photograph at identical dimensions, one seven times heavier than the other, with the current threshold marked across both.
- "The short list of things this will never touch" is now "True of this release. Anything that changes will be in the changelog." Earlier versions of this plugin did rename files and make WebP copies, and those are recorded as deferred rather than abandoned, so a promise covering every future release was one it could not keep. Promising that a change would be announced is one it can.
- The oversize scale now redraws as the multiplier is typed, before anything is saved, along with the pixel width named in the sentence above it. A picture of what a number does is only worth having if it keeps up with the number.
- The scale's axis grows with the setting instead of being fixed at 6000px. Past roughly 2.9x the flag line used to run off the end of a fixed axis, which would have left the diagram quietly showing a threshold that was not where it said it was. The axis now rounds up to a whole thousand, and the "a normal upload" marker drops out once it would collide with the widest-served marker.
- Moved the oversize scale into the oversize multiplier's own settings row and removed the section that used to house it. It was never a lesson about image sizes; it is a picture of what that one number currently does, so it belongs beside the control that sets it and it redraws whenever that control is saved. The longer explanation lives on in the readme for anyone who wants it.
- Removed the drawn mock-up of the Media list from the overview. It illustrated the three columns using something that looked exactly like a working table with editable fields, on a screen where every other input does work, so it invited people to type into a picture. An explanation that has to be explained is not doing its job.
- The coloured rule on each figure now carries state and nothing else. It was brand navy at rest, which put a decorative mark in the same slot, at the same weight, in the same scan position as the three status colours, so a figure with no verdict read as though it had a fourth kind of verdict. Grey says what is true: nothing is being claimed. The brand colour keeps the places where it competes with nothing.
- Each figure reserves the status slot whether or not a status fires, so the numbers in a row land on a shared line. Borrowing the label-above-number order without that reservation is what made them look nudged out of true.
- The Media list badge and the overview status pills now share one pill definition instead of two independent copies of the same recipe.
- Reshaped the overview around a header, a row of figures, and sections whose heading sits above its card rather than inside it. The five figures read left to right, each with a navy rule at the top that carries its state, and the reading measure inside the cards is held separate from the width of the page.
- Rewrote the overview copy to be about what the reader gets rather than how the plugin works. Gone: why width cannot be sorted at the database level, how the oversize threshold was calibrated, and why the diagrams are drawn instead of screenshotted. All true, none of it the reader's problem.
- The alt text explanation now opens on somebody using a screen reader getting the same information from a photograph that everyone else gets, rather than on the "and it helps when images fail to load" framing.
- "What this plugin will not do" is now "What stays exactly as it is". Identical promise, read as reassurance rather than a list of refusals.
- Only the lead and the settings form are boxed now. Seven identical bordered panels in a stack read as generated rather than edited, so the prose sections are separated by space and heading weight instead.

### Fixed
- The large-files figure read zero on a fresh install. It is counted against the file-size index, and that index fills in lazily as images are looked at, so on a site where nobody had opened the Media list yet the count came back zero on a library holding five large files, wearing a green "nothing over your size" beside it. The index is now built once, bounded, before the first count. A confident wrong answer is worse than a slow one.
- Deleting an image left the overview reporting the old numbers. Removing an attachment cleared the cached counts but not the cached results of the two scans those counts are built from, so the totals were honestly recomputed from a stale list of IDs and the wrong answer was cached again. Somebody who deleted two broken images was still told there were two. Every cache the scans keep is now named in one place and cleared together, and the clear happens both before and after the row actually goes.
- The "Images" figure counted images while the link beside it opened the whole media library. On the library this was written against that was 768 counted against 829 shown, the difference being text files, Word documents, PDFs and audio. Every link on the page now carries core's own image filter, so core's dropdown agrees with the figure.
- Filter names now say "images" rather than "files". The dropdown sits directly beside core's all-file-types filter, where "Files over 1 MB" reads as though it might turn up a PDF.
- The description of alt-text search did not say it applies to the list view only. In grid view the search runs as a different query and the filter never engages.
- The Media filter dropdown said "Files over 1 MB" as fixed text. Once that size became a setting the label was simply untrue for anybody who changed it, so it now reads the configured value.
- The overview counts are cached for five minutes to keep three `found_posts` queries off every page load. Anything that could change a count clears the cache, including the first settings save: until somebody saves that screen the options do not exist, so the first write goes through `add_option()` and fires `add_option_*` rather than `update_option_*`. Hooking only the latter left stale counts on the page that reported them.

## 1.6226 (2026-08-14)

Rebuilt from scratch as a focused Media list tool. This is a deliberate reduction: roughly 14,800 lines became 1,400, and the plugin now does one thing on one screen.

### Added
- **Alt text column with inline editing.** Edit alt text directly in the Media list and save without a page load. Guarded by a nonce and a per-attachment `edit_post` capability check rather than a blanket `upload_files`, so contributors cannot edit other people's uploads.
- **Dimensions and file size columns.** File size is sortable, backed by a numeric index (`_timu_image_filesize`) that backfills as you browse, with no migration step and no batch job. Dimensions are deliberately not sortable: width and height live inside serialized metadata that the database cannot sort on, and offering a control that quietly did nothing would be worse than not offering it.
- **Filter dropdown** for missing alt text, has alt text, oversized originals, and files over 1 MB.
- **Oversized flag** for images meaningfully wider than the largest size the site will ever serve. Calibrated at 1.5× by measurement: core scales uploads to 2560px against a 2048px yardstick, so an ordinary modern upload sits at 1.25× and is not flagged, while a 6000px legacy import lands at 2.93× and is.
- **Alt text included in Media search.** WordPress searches titles, captions, descriptions and filenames but not alt text, which is often the only place a photograph's subject is written down.
- **Extension seams.** `timu_image_support_before_write` and `timu_image_support_after_write` fire around every mutation with old and new values. `timu_image_support_alt_suggestions` returns suggested alt strings and is empty in this release, so no suggestion interface appears; suggestions are offered to the person editing and never written automatically.

### Removed
- Filename sanitisation and content relinking, photo credits, WebP generation, alt-text fallback, WP-CLI commands, REST routes, the background scheduler, the library health score, folder taxonomy, attachment-page SEO, JSON-LD output, audit utilities, the bundled updater, and the JSX build toolchain.
- Everything removed is preserved in git history at the `pre-mvp-origin` tag and recorded in the issue tracker (#51–#63). Nothing was dropped without a written record.

### Fixed
- The oversize threshold could never fire. It compared against the largest registered image size at a multiplier no real upload could reach, because core caps uploads at 2560px and registers 2048px on every site.
- The filter dropdown never rendered. The Media list table fires `restrict_manage_posts` with `$which` set to `bar`, not the `top` the posts list table uses, so the filters worked by URL but no control ever appeared.
- Documentation claimed an empty alt attribute was respected as a deliberate "decorative" marking. WordPress records no such intent, because core writes an empty string whenever an attachment is saved with a blank field, so it is indistinguishable from one nobody filled in. The claim was removed rather than kept as a comfortable fiction.

### Security
- One write path, gated by nonce, per-attachment capability check, and an attachment-type check, in that order. Alt text is capped at 1000 characters.
- No external network requests. No front-end output.
- Uninstall removes only this plugin's own index and transient. Alt text is left alone: it lives in WordPress's own field and belongs to the site, not to this plugin.

## 1.6190.1680 — 2026-07-09

### Added
- **Vortops AI alt text** — when a Vortops API key is connected, the plugin automatically generates alt text from actual image content on upload (no filename guessing). Results are cached as supplementary metadata and appear as a tier-1.5 fallback: after any alt text already set by an editor, before the post-title fallback. The describe endpoint is planned — the seam is live now and activates when the API is deployed.
- "Vortops" bulk fill source added to the alt text bulk-fill tool. Calls the AI on each selected image and writes the result directly to the WordPress alt field — an explicit, editor-visible update.
- Vortops Settings postbox on the Settings tab: API key field, Test Connection button, and capability messaging. The same key works across all thisismyurl plugins.

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
