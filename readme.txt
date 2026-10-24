=== Thisismyurl Image Support ===
Contributors: thisismyurl
Donate link: https://github.com/sponsors/thisismyurl
Tags: alt text, accessibility, media library, images, audit
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6297
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows you which pictures on your website need a little attention, and helps you add descriptions. It only looks. It never changes, moves or deletes your pictures.

== Description ==

Over time, a website collects a lot of pictures. This plugin gives you a simple list of them, and shows you which ones could use a little attention.

**It is safe to try.** This plugin only looks at your pictures. It does not change, move, shrink or delete any of them. The only thing you can change is a picture's description, and only when you type one in yourself. You can install it on any website and look around without worrying.

**What it helps you find**

* **Pictures without a description.** A description (called "alt text") is a short sentence saying what is in the picture. People who cannot see the picture, and search engines, rely on it. You can type a description right in the list, press Enter, and it is saved.
* **Pictures that are very large.** Some pictures are much bigger than your website needs. They take up space and can slow your pages down. The plugin points them out so you can decide what to do.
* **Pictures that have gone missing.** Sometimes the picture file is gone but WordPress still lists it. These show up as broken pictures. The plugin shows you which ones.
* **Where each picture is used.** Before you remove anything, you can see which pages are using a picture.
* **A simple overview.** One page tells you how many pictures you have, how much space they use, and what could use attention. It is based on your own pictures.

**What it does not do**

* It does not change how your website looks to visitors. Everything happens in your WordPress admin area.
* It does not change, move, rename, shrink or delete a picture file.
* It does not send anything to anyone. There are no outside connections, no tracking, and nothing leaves your website.
* It only looks at pictures. Documents, audio and video are left alone.

If you uninstall the plugin, the descriptions you typed stay exactly where they are. The plugin only removes its own small notes (such as file sizes it remembered so the list could sort).

= About descriptions (alt text) =

A good description says what the picture shows and why it is there, in one short sentence. For example: "Two people shaking hands outside a bakery."

Some pictures are only decoration and do not need a description. WordPress cannot tell the difference between a picture you chose to leave blank and one you simply have not got to yet. So this plugin lists every picture without a description, even the ones you meant to leave blank. That is on purpose. It is better to show you a slightly longer list than to tell you the job is finished when it might not be.

= For developers =

Filters: `timu_image_support_mime_types`, `timu_image_support_widest_width`, `timu_image_support_oversize_threshold`, `timu_image_support_heavy_bytes`, `timu_image_support_attention_floor`, `timu_image_support_alt_suggestions`, `timu_image_support_index_backfill_limit`, `timu_image_support_oversize_scan_limit`, `timu_image_support_library_scan_limit`, `timu_image_support_untracked_scan_limit`, `timu_image_support_untracked_skip_folders`, `timu_image_support_reference_scan_limit`, `timu_image_support_reference_row_limit` and `timu_image_support_reference_file_limit`.

Actions: `timu_image_support_before_write` and `timu_image_support_after_write`, fired around every alt-text change with the previous and new values.

== Installation ==

1. Install and activate the plugin. Nothing is changed when you do this.
2. In your WordPress menu, go to **Media > Image Support**. This page explains what the plugin found, in plain words.
3. Press the button on that page to open your pictures as a list. The list view is needed because the default picture grid has no columns to show the extra information.
4. Use the filter above the list to find pictures that need a description.

== Frequently Asked Questions ==

= Will this change or delete my pictures? =
No. The plugin only looks at your pictures. The one thing you can change is a picture's description, and only when you type it yourself. If you ever want to undo a description, just edit it again.

= I installed it and cannot see anything. Where is it? =
Look under **Media > Image Support** in your WordPress menu. There is no separate menu item for this plugin. Then press the button on that page to open your pictures as a list. WordPress shows pictures as a grid by default, and a grid has no room for the extra columns. WordPress remembers your choice afterwards.

= Where are the new columns? =
In the Media list, just after the file name. If you see a grid of pictures instead, switch to the list using the two small buttons near the top left of the Media page.

= What does "picture file is missing" mean? =
WordPress still lists the picture, but the actual file is no longer on your website. You may notice these as broken pictures. This can happen after a move or a clean-up.

The plugin only tells you about them. It does not remove anything. If you decide to delete one, first check that no page is still using it (the "Where pictures are used" tab can help).

= How do I see where a picture is used? =
Open the "Where images are used" tab under **Media > Image Support**. It lists your pictures, twenty at a time, and shows the places where each one was found: as a featured picture, inside a page or post, in a setting, or in a theme or plugin file.

If it says "No reference found", that means this search did not find one. It does not promise the picture is unused, because some tools keep pictures in places this search cannot see. Please check before deleting anything. The plugin itself never deletes anything.

= Does it send anything anywhere? What does it keep? =
It sends nothing anywhere. There are no outside connections, no tracking and no accounts. It works even if your website is offline.

On your own website it keeps a few small notes, and removes all of them if you uninstall it:

* The file size of each picture, so the list can be sorted by size.
* Two settings (described below).
* A once-a-day count of its own totals, kept for a year, so you can see progress.
* Short-lived saved results, so pages open quickly.

None of this is personal information.

= What is on the "About this image library" page? =
Simple facts about your pictures, not a list of faults: how much space they use, how much of that is smaller copies WordPress made for you, files in your uploads folder that WordPress does not know about, pictures that might be duplicates, and similar. It is the kind of information someone taking over a website would otherwise have to work out by hand.

= How does the progress-over-time part work? =
The plugin saves one reading per day, on days when somebody opens the page. It does not run anything in the background. Days when nobody opened it have no reading. You can download your readings as a spreadsheet (CSV) file whenever you like. The file is made on the spot and is not stored or sent anywhere.

= Why are untracked files shown by folder, not as a list? =
A long list of file names makes it tempting to delete them, and many should be kept. Some are smaller copies that belong to healthy pictures. Others belong to other plugins. Showing folders tells you where to look without suggesting everything in them is rubbish.

= What can I change in the settings? =
Two numbers, on the Settings tab of **Media > Image Support**, both for administrators. One decides how large a picture must be before it is called "very large". The other decides how many megabytes make a file "heavy". Changing a number only changes which pictures get a label. It never changes a picture. Both start at sensible values, so you do not need to touch them.

= What happens to my descriptions if I delete the plugin? =
They stay. Descriptions are saved in the place WordPress itself uses, so every other tool can still see them. Uninstalling only removes the plugin's own small notes.

= Why can I not sort by size in pixels (dimensions)? =
WordPress stores width and height in a way that cannot be sorted reliably, and a sort button that quietly did nothing would be worse than none. File size can be sorted, because this plugin keeps its own number for it.

= What counts as "very large"? =
A picture more than one and a half times wider than the widest size your website uses (2048 pixels on a standard setup). WordPress already shrinks new uploads, so a normal photo you add today is not flagged. Pictures that are flagged usually came from before that shrinking existed, or were put straight onto the website's storage.

It is normal to see none at all on a well-kept website. That means all is well. You can change the number on the Settings tab.

= Will it work on a website with a lot of pictures? =
Yes. The columns, filters and overview work well on large libraries. For very large ones (tens of thousands of pictures), some checks look at the newest 2,000 pictures only, and the page tells you when that happens, so it never guesses.

= Can I edit a description if I did not upload the picture? =
Only if your WordPress role already lets you edit that picture. The plugin does not give anyone extra permissions.

== Screenshots ==

1. The overview: what needs attention, counted from your own library rather than described in the abstract.
2. About this image library: what it weighs, how much of that is generated sizes, what is sitting in the uploads folder unaccounted for, and whether any of it is moving.
3. Where images are used: every image against the places a reference was actually found, with the unreferenced ones gathered at the top.
4. The Media list, with alt text editable in place, dimensions, and a sortable file size.
5. Settings: two thresholds, each with a picture of what that number currently does.

== Changelog ==

= 1.6297 =
The Media list was hard to read on laptop-width screens: the File column was squeezed to a sliver and filenames wrapped one letter per line. That is fixed.

* Fixed: the File column on the Media list is no longer squeezed by the added Alt text, Dimensions and File size columns. The list now sizes its columns to their content, and on narrower desktop windows the Author, Uploaded to, Comments and Date columns step aside (Screen Options turns them back on).

= 1.6291 =
A review of 1.6229 turned up problems in how the scans behave on large and unusual sites, and in what this page said about itself. All of them are fixed.

* Fixed: the Image Support page stopped working when the uploads folder, or a theme or must-use plugin folder, held a subfolder PHP could not read. Backup and security plugins often create these. Unreadable folders are now skipped.
* Fixed: Authors, who can open the page, could see the names of untracked image files and link to them, including files in folders that form and store plugins keep private. Folder names and links are now shown to administrators only, other roles see a count, and those plugins' folders are not scanned.
* Fixed: the library measurement loaded every image, with several disk checks each, in one request, and the reference scan loaded every post and custom field into memory at once. Both now read in batches, so memory grows with the number of files rather than with every row loaded at once, and the library measurement no longer touches the disk per image.
* Fixed: a reference scan whose text search failed would report "no reference found" for every image. It now says the scan was incomplete.
* Fixed: the untracked-files figure counted attached SVGs, PDF previews, edited-image backups and private attachments as untracked, and linked lowercased file names that do not exist on case-sensitive servers.
* Fixed: oversized and no-file counts silently covered only the newest 2,000 images. The page now says when that applies.
* Fixed: a PHP deprecation notice for an Author viewing an image they cannot edit.
* Fixed: the file size index and cached results did not update when media was changed through the REST API, WP-CLI or a scheduled job.
* Fixed: the Media search no longer replaces another plugin's GROUP BY clause.
* Fixed: on a network, uninstalling now removes the plugin's data from every site, not only the current one.
* Fixed: file-size units in the settings field are now taken from WordPress, so the label no longer changes by itself in other languages.
* Changed: the plugin description, the "leaves alone" list and the uninstall note now say what the plugin stores. Several of them said it changed nothing or stored less than it does.
* Changed: every class is now named after the plugin, so another plugin cannot declare one with the same name.
* Fixed: the site icon and custom logo, which are stored as IDs, were reported as having no reference. File names with accented letters, percent-encoding or extra dots are now matched.
* Fixed: when a library is larger than the measurement covers, the page no longer shows a zero or a trend for untracked files and duplicates. It says they were not counted, and no history reading is written.
* Fixed: on the main site of a network, other sites' uploads are not scanned or listed.
* Documented every developer filter. There are fourteen, and the readme listed two.

= 1.6229 =
* Added Media > Image Support: a page explaining what the plugin does, reporting how many images in your library are missing alt text or larger than they need to be, linking straight to each of those sets, and holding the settings.
* Added two settings, previously developer-only filters: the oversize multiplier and the large-file threshold. A filter still overrides either one, so a site configured in code keeps behaving that way.
* Added "How it works" and "Your images" links on the Plugins screen. Without them, activating the plugin and going looking produced nothing at all, because the columns live on an existing screen that WordPress shows as a grid by default.
* Fixed: the description of alt-text search did not say it applies to the list view. It does not work in the grid, where the search runs a different query.

= 1.6226 =
* First release of the Media list screen tools: alt text column with inline editing, dimensions and file size columns, a filter for images needing attention, an oversized-image flag, and alt text included in Media search.

== Upgrade Notice ==

= 1.6297 =
Fixes the File column being squeezed on the Media list at laptop widths. Recommended.

= 1.6291 =
Fixes the Image Support page failing on sites with unreadable upload folders, limits what Authors can see, and makes the scans safe on large libraries. Recommended.

= 1.6229 =
Adds a Media > Image Support page that explains the plugin and reports what it found in your library, plus settings for the two thresholds.

= 1.6226 =
First release.
