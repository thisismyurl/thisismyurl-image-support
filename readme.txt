=== Thisismyurl Image Support ===
Contributors: thisismyurl
Donate link: https://github.com/sponsors/thisismyurl
Tags: alt text, accessibility, media library, images, image optimization
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6226
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds alt text, dimensions and file size to the Media list, so you can see which images need attention and fix them without opening each one.

== Description ==

The Media list screen tells you a file's name, who uploaded it, and when. It does not tell you whether the image has alt text, how big it is, or whether it is far larger than your theme will ever display. Those are the three things people actually want to know about their images, and answering any of them today means opening attachments one at a time.

This plugin puts them on the list.

**Alt text, editable in place.** A column showing each image's alt text as an editable field. Type, press Enter or click away, and it saves. Working through two hundred images stops being two hundred page loads.

**A filter for the images that need work.** Narrow the list to images missing alt text, images that have it, oversized originals, or files over a megabyte. "We should sort out our alt text" becomes a list you can finish.

**Dimensions and file size, sortable.** Sort by file size and the six megabyte photo somebody dropped into a post in 2019 comes straight to the top.

**An oversized flag.** WordPress scales big uploads down on arrival, but images that predate that behaviour, or that were imported straight onto disk, escaped it entirely. Those are the enormous originals quietly filling your uploads folder and every backup you take. They get flagged.

**Search that includes alt text.** WordPress already searches image titles, captions, descriptions and filenames. It does not search alt text, which is often the only place the subject of a photograph is written down. Now searching "harbour" finds the harbour photo even when the file is called IMG_4821.jpg.

= What this plugin does not do =

* It does not touch the front end. Everything happens in wp-admin, on one screen.
* It does not change, move, rename, compress or convert a single image file. It reads your library and lets you edit alt text. Nothing else is written.
* It makes no external network requests of any kind. Nothing is sent anywhere.
* It only concerns itself with images. Documents, audio and video are left alone.

= A note on alt text =

Alt text describes an image for people using a screen reader, and for anyone whose image fails to load. A good description says what the image shows and why it is there, in a sentence.

A word on decorative images. Some images carry no meaning and should be silent to a screen reader, and the correct markup for those is an empty alt attribute. WordPress does not record *why* an alt is empty, though: a blank field looks exactly the same whether somebody decided the image was decorative or simply never got to it. So this plugin does not guess. Anything without alt text is listed as missing, including images you have deliberately left blank. If that means a handful of genuinely decorative images stay on your list, that seems better than telling you a job is done when the data cannot actually say so.

= Images only, on purpose =

This release is deliberately about images. Documents and video raise different questions and deserve their own tools rather than a single screen trying to serve everything at once.

== Installation ==

1. Install and activate the plugin.
2. Go to Media and make sure you are in list view, not grid view. The new columns live in the list.
3. Use the filter dropdown to find images missing alt text.

== Frequently Asked Questions ==

= Where are the new columns? =
On the Media list view. If you are looking at the grid, switch to the list using the two small buttons at the top left of the Media screen.

= Does this change my images? =
No. Nothing about your image files is altered, moved or re-saved. The only thing this plugin writes is alt text, and only when you type it.

= What happens to my alt text if I delete the plugin? =
It stays. Alt text is stored in WordPress's own field, exactly where the attachment editor and every other plugin looks for it. Uninstalling removes only the file-size index this plugin builds for sorting.

= Why can I not sort by dimensions? =
Because it would be a lie. Width and height are stored inside a serialized blob that the database cannot sort on, and offering a sort control that quietly did nothing would be worse than not offering it. File size is sortable because this plugin maintains a separate numeric index for it.

= What counts as "oversized"? =
An image more than one and a half times wider than the largest size your site will ever serve, which on a default install is 2048 pixels.

That number comes from measuring rather than guessing. Since WordPress 5.3, uploads are scaled down to 2560 pixels automatically, so an ordinary photo you upload today sits at about 1.25 times the yardstick and is perfectly healthy. Anything much past that did not come through the normal upload path: it predates the scaling, it was imported directly onto disk, or the scaling was switched off. Those files are often several thousand pixels wide and nothing in WordPress points them out.

Developers can adjust the threshold with the `timu_image_support_oversize_threshold` filter, and the yardstick itself with `timu_image_support_widest_width`.

= Does it work with a very large media library? =
The columns and the alt filters scale fine, because they run as ordinary database queries. The "oversized" filter has to inspect image metadata in PHP, so it examines the most recent 2,000 images and caches the answer for ten minutes. On a library larger than that you will be seeing a recent slice rather than everything.

= Can I edit alt text if I did not upload the image? =
Only if your role lets you edit that attachment. The check is per image, not a blanket permission, so contributors do not gain the ability to edit other people's uploads.

== Screenshots ==

1. The Media list with alt text, dimensions and file size, and an oversized image flagged.
2. Filtering the library down to images missing alt text.
3. Editing alt text inline, without leaving the list.

== Changelog ==

= 1.6226 =
* First release of the Media list screen tools: alt text column with inline editing, dimensions and file size columns, a filter for images needing attention, an oversized-image flag, and alt text included in Media search.

== Upgrade Notice ==

= 1.6226 =
First release.
