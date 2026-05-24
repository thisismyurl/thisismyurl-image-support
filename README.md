# This Is My URL - Image Support

[![CI](https://github.com/thisismyurl/thisismyurl-image-support/actions/workflows/ci.yml/badge.svg)](https://github.com/thisismyurl/thisismyurl-image-support/actions/workflows/ci.yml) [![WordPress Tested](https://img.shields.io/badge/WordPress-7.0-blue)](https://wordpress.org/) [![License](https://img.shields.io/badge/License-GPL--2.0-blue)](LICENSE)


Current version: 1.6144

Image Support is a WordPress plugin for SEO-focused filename cleanup, media hardening, content reference synchronization, photo-credit attribution, alt-text accessibility, and safe rollback support.

## What It Does

Image Support groups two kinds of feature, and it matters which is which.

**Cleanup features — destructive by design.** These rename attachment files, merge duplicates, and rewrite references in `post_content`. They run only after you tick the "Confirm destructive operations" option, and a dry run previews every batch first.

- Filename sanitization for cleaner, SEO-friendlier image slugs — with an extension whitelist, NUL / RTL / path-traversal rejection, and a deterministic fallback when a name reduces to empty.
- Content reference synchronization when filenames change, via `WP_HTML_Tag_Processor` — rewrites `<img src>`, `<a href>`, and `<img srcset>` only, host-checked against your site URL, with a per-post revision snapshot taken before any write.
- Duplicate detection with trash-mode merge: merged duplicates are sent to Trash (never force-deleted) and a JSON sidecar of the attachment record is written first.
- Backup of original files before renaming, and restore gated by capability, nonce, and POST (never GET).
- 404 redirect support for requests that still hit old image paths.
- Filesystem discovery of existing WebP files (walked via `WP_Query`, no `YYYY/MM` assumption), plus async, opt-in WebP generation that schedules on cron rather than blocking a render thread.

**Benign features — they never touch files or post content.**

- Photo-credit attribution: per-attachment credit name, credit link, AI-generated and AI-edited flags with model names, and a composite flag — set in a Media Library meta box or a `core/image` block-editor sidebar panel, rendered as a figcaption line, pre-filled from IPTC on upload, and emitted as schema.org/ImageObject credit fields.
- Alt-text accessibility fallback: fills an otherwise-empty `alt` attribute from the attachment's own alt meta, then its title, then a humanised filename — while leaving images explicitly marked decorative silent to screen readers.

A single screen under **Tools → Image Support** is where you preview a batch, toggle the destructive-ops switch, and apply changes. A WP-CLI surface, action hooks, and filters are documented under [For developers](#for-developers).

## Requirements

- WordPress 6.4+
- PHP 8.1+
- GD or Imagick image support

## Installation

1. Copy this plugin into `/wp-content/plugins/thisismyurl-image-support/`.
2. Activate it from the WordPress admin.
3. Open `Tools > Image Support`.

## Usage

1. Open `Tools > Image Support`.
2. Preview a batch with a dry run — nothing is written.
3. Take a database backup, then tick **Confirm destructive operations** when you are ready to apply renames, merges, and content re-syncs.
4. Apply the batch. Originals are backed up before any rename, and a per-post revision snapshot is taken before any content rewrite.

The plugin processes image attachments in batches, updates matching content references, and keeps backup copies for rollback. The photo-credit and alt-text features need no setup — they do nothing until an attachment has credit data or a rendered image is missing its `alt`. For scripted runs, see the WP-CLI commands under [For developers](#for-developers).

## Restore Behavior

Backup files are stored in `/wp-content/uploads/timu-image-backups/`.

When an image has been renamed, the plugin records the original path and filename in post meta so the file can be restored later and old requests can be redirected to the current attachment URL.

## Notes

- The destructive cleanup features (rename, merge, content re-sync) run only behind the **Confirm destructive operations** switch, with a dry run first.
- The focus is filenames, content references, duplicate cleanup, safe rollback, photo-credit attribution, and alt-text accessibility.

## Versioning

This plugin uses the format `x.Yddd`:

- `x` = release class (`0` = pre-release, `1` = full release)
- `Y` = last digit of the year
- `ddd` = Julian day-of-year of the release date

A full release cut on 2026-05-24 — the 144th day of 2026 — is `1.6144`.

## License

GPLv2 or later.

---

## For developers

Image Support exposes a scripting surface (WP-CLI), an observation surface (action hooks), and an extension surface (filters). Everything is additive and backward compatible — defaults reproduce the plugin's standard behaviour, and the pre-existing filters keep their names and signatures.

### Actions

| Action | When it fires | Arguments |
| --- | --- | --- |
| `thisismyurl_image_support_before_rename` | Immediately before an attachment file is renamed on disk. | `$id`, `$source_path`, `$new_name`, `$old_basename` |
| `thisismyurl_image_support_filename_sanitized` | After a file is successfully renamed. | `$id`, `$old_basename`, `$new_name`, `$new_path` |
| `thisismyurl_image_support_rename_failed` | When a rename fails on an I/O error. | `$id`, `$source_path`, `$new_name` |
| `thisismyurl_image_support_after_rename` | After a rename attempt, on success or failure. | `$id`, `$ok` (bool), `$source_path`, `$new_name` |
| `thisismyurl_image_support_content_relinked` | After a filename's references are rewritten across `post_content`. | `$old_filename`, `$new_filename`, `$report` (matched URLs), `$writes_allowed` (bool) |
| `thisismyurl_image_support_generate_webp` | Cron one-shot to generate a missing WebP off the render thread. | `$source_path` |

### Filters

| Filter | What it controls | Default |
| --- | --- | --- |
| `thisismyurl_image_support_enabled` | Master gate. Returning false disables the cleanup pipeline (and the CLI batch) without deactivating the plugin. Restore is never gated by it. | `true` |
| `thisismyurl_image_support_sanitized_filename` | The slug produced for a renamed attachment (`$sanitized`, `$filename`, `$post_id`). Return false to skip the rename. | computed slug |
| `thisismyurl_image_support_should_process` | Per-attachment gate (`$should`, `$attachment_id`, `$source_path`). Return false to skip one image. | `true` |
| `thisismyurl_image_support_relink_post_types` | Post types whose `post_content` is searched and rewritten on relink. | public types minus `attachment` |
| `thisismyurl_image_support_relink_post_statuses` | Post statuses included in the relink query. | `publish, private, draft, future, pending` |
| `thisismyurl_image_support_processable_mime_types` | Attachment MIME types eligible for rename/relink. | `image/jpeg, image/png, image/gif, image/webp` |
| `thisismyurl_image_support_confirm_destructive` | Opt-in for destructive writes (rename, merge, `post_content` rewrite). Pre-existing. | option value, default `false` |
| `thisismyurl_image_support_enable_dynamic_webp` | Enables the on-render `the_content` WebP swap (off by default; synchronous GD encoding is a TTFB footgun). Pre-existing. | `false` |
| `thisismyurl_image_support_hide_submenus` | Sister-plugin Tools submenu slugs to hide. Pass an empty array to keep them. Pre-existing. | `['thisismyurl-webp-support', 'thisismyurl-heic-support']` |
| `thisismyurl_image_support_default_credit` | The credit name auto-applied to pipeline AI heroes that lack one. Empty by default — nothing is invented. | `''` |
| `thisismyurl_image_support_photo_credit_ship_date` | The `YYYY-MM-DD` cutoff for the AI-hero backfill sweep; move it to your own adoption date. | module ship date |

### WP-CLI

All commands live under `wp image-support`. `sanitize` and `relink` mutate: they check `current_user_can( 'manage_options' )`, honour `thisismyurl_image_support_enabled`, and refuse a non-dry-run unless destructive operations are confirmed. `status` and `photo-credit ai-hero-report` are read-only; `photo-credit backfill` writes credit meta and supports `--dry-run`.

```bash
# Preview a single batch of filename + content-reference changes
wp image-support sanitize --dry-run

# Process one batch of up to 25 attachments
wp image-support sanitize --limit=25

# Walk the whole library, one batch at a time, until exhausted
wp image-support sanitize --all

# Preview filesystem WebP discovery and the relinks it would make
wp image-support relink --dry-run

# Apply the WebP relinks
wp image-support relink

# Report enabled state, version, destructive-ops opt-in, cursor, attachment count
wp image-support status
wp image-support status --format=json

# Backfill photo-credit fields from existing IPTC By-Line / Credit / Copyright data
wp image-support photo-credit backfill --dry-run
wp image-support photo-credit backfill

# List pipeline AI heroes that still need an editorial credit decision
wp image-support photo-credit ai-hero-report
```

A non-dry-run mutating command run without confirmation exits with an error explaining how to opt in — it never silently no-ops.

---

## Support and Contribute

### Ways to Support

I build these tools because WordPress sites in the wild keep hitting the same problems, and a focused plugin is usually the right fix. There's no tracking, no ads, and you don't need to pay to use these plugins.

If you find them helpful, here are some genuine ways to support the work:

- **Sponsor if it fits your budget:** You can sponsor the project through [GitHub Sponsors](https://github.com/sponsors/thisismyurl). Sponsorship helps, but it's always optional.
- **Contribute code or ideas:** Opening a pull request, reporting an issue, or testing edge cases is just as valuable as sponsorship. Helping me improve these plugins is a great way to contribute.
- **Share your experience:** A follow on [WordPress.org](https://profiles.wordpress.org/thisismyurl/), [GitHub](https://github.com/thisismyurl), or [LinkedIn](https://linkedin.com/in/thisismyurl) helps others find this work.

### Report Issues and Questions

Found a bug? Want to suggest a feature? Just curious how something works?

- **File an issue:** Use the [Issues](../../issues) tab. Include your WordPress and PHP version, and steps to reproduce.
- **Start a discussion:** Use the [Discussions](../../discussions) tab for questions, ideas, or general conversation about the plugin.

### Contributing Code

Code contributions are welcome and genuinely valuable. Here's the workflow:

1. **Fork this repository** and clone it locally.
2. **Create a feature branch** with a clear name (e.g., `feature/improve-safety-check`).
3. **Make your changes** and test thoroughly on edge cases.
4. **Follow WordPress coding standards** — run `composer run lint:phpcs` before opening a PR.
5. **Open a pull request** with a clear description of what changed and why.

I review PRs thoughtfully and appreciate well-tested contributions. Contributing is never required, but it's genuinely helpful.

---


## About This Is My URL

This plugin supports the work I do at [This Is My URL](https://thisismyurl.com/wordpress-seo-services/), where I help WordPress teams build secure, performant, and maintainable sites.

This plugin is built and maintained by [This Is My URL](https://thisismyurl.com/), a WordPress development and technical SEO practice. I'm Christopher Ross, a WordPress developer and technical SEO specialist working on the open web since 1996 and on WordPress since 2007.

### My Background

- **On the open web since 1996, on WordPress since 2007** — shipping production systems for media, education, and government
- **WordPress contributor since 2007** — plugins published on .org, code shipped to media, education, and government deployments
- **Technical SEO practitioner** helping sites improve performance, security, and search visibility
- **Lead instructor and curriculum architect** at the M.L. Campbell Training Center — Sherwin-Williams' international training facility for the industrial wood division

I believe in straightforward solutions that work. No hype. No unnecessary complexity.

### Ways to Connect

- **WordPress.org profile:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/)
- **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl)
- **Website:** [thisismyurl.com](https://thisismyurl.com/)
- **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)


## Contributors

- **Christopher Ross** ([@thisismyurl](https://github.com/thisismyurl)) — author and maintainer
- **Contributors:** Thanks to everyone who's reported issues, tested edge cases, and contributed code

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).


---
*This project follows the [10 Core Pillars](PILLARS.md). Support quality work [here](https://github.com/sponsors/thisismyurl).*
