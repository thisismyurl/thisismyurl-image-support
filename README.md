# Image Support

[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org/) [![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/) [![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)

See which images need attention, and fix them without opening each one.

## What it does

The Media list tells you a file's name, who uploaded it, and when. It does not tell you whether an image has alt text, how big it is, or whether it is far larger than your theme will ever display. Those are the three things people actually want to know about their images, and answering any of them today means opening attachments one at a time.

This puts them on the list.

- **Alt text, editable in place.** Type, press Enter or click away, and it saves. Working through two hundred images stops being two hundred page loads.
- **A filter for the images that need work.** Missing alt text, has alt text, oversized originals, or files over a megabyte.
- **Dimensions and file size, with file size sortable.** The six megabyte photo somebody dropped into a post in 2019 comes straight to the top.
- **An oversized flag.** WordPress scales big uploads down on arrival, but images that predate that behaviour, or that were imported straight onto disk, escaped it. Those get flagged.
- **Search that includes alt text.** WordPress searches titles, captions, descriptions and filenames, but not alt text — often the only place the subject of a photograph is written down. Searching "harbour" now finds the harbour photo even when the file is called `IMG_4821.jpg`.

## What it does not do

- It does not touch the front end. Everything happens in wp-admin, on one screen.
- It does not change, move, rename, compress or convert a single image file. It reads your library and lets you edit alt text. Nothing else is written.
- It makes no external network requests of any kind.
- It only concerns itself with images. Documents, audio and video are left alone.

## Requirements

- WordPress 6.4+
- PHP 7.4+

## Installation

1. Upload the `thisismyurl-image-support` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Media and make sure you are in list view, not grid view. The columns live in the list.

## For developers

Five filters and two actions, all documented inline:

| Hook | Type | Purpose |
| --- | --- | --- |
| `timu_image_support_mime_types` | filter | Which image types the plugin operates on |
| `timu_image_support_widest_width` | filter | The width treated as the largest this site will serve |
| `timu_image_support_oversize_threshold` | filter | Multiplier past which an image is flagged (default `1.5`) |
| `timu_image_support_heavy_bytes` | filter | Byte count treated as a heavy file (default 1 MB) |
| `timu_image_support_alt_suggestions` | filter | Return suggested alt strings for an attachment |
| `timu_image_support_before_write` | action | Fires before the plugin modifies an attachment |
| `timu_image_support_after_write` | action | Fires after, with old and new values |

`timu_image_support_alt_suggestions` returns an empty array in this release, so no suggestion interface appears. Suggestions are offered to the person editing and are never written automatically.

## A note on scope

This release is deliberately about images, and deliberately about one screen. Documents and video raise different questions and deserve their own tools rather than a single screen trying to serve everything at once.

Version 1.6226 is a rebuild. Earlier releases carried a filename cleanup engine, photo credits, WebP generation and a library audit surface. Those are recorded in the issue tracker and preserved in git history at the `pre-mvp-origin` tag — see [#57](https://github.com/thisismyurl/thisismyurl-image-support/issues/57) for what was deferred and why.

## Versioning

Versions follow `X.Yjjj.hhmm` — release class, last digit of the year, Julian day, and 24-hour build time.

## About

Image Support is built and maintained by [Christopher Ross](https://thisismyurl.com/). I build focused WordPress tools for problems that keep showing up across real sites. No tracking, no ads, no upsells.

**WordPress.org:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/) · **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl) · **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
