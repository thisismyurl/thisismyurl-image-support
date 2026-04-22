=== WebP Support by thisismyurl ===
Contributors: thisismyurl
Author: thisismyurl
Author URI: https://thisismyurl.com/
Donate link: https://thisismyurl.com/donate/
Support Link: https://thisismyurl.com/contact/
Tags: webp, optimization, speed, image-optimizer, performances
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.251229
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-webp-support/
Primary Branch: main

A free non-destructive WebP for WordPress: Auto-optimize new uploads & bulk-convert existing files with secure backups & 1-click restore.

== Description ==

**Free WebP Optimizer by thisismyurl** is a lightweight, high-performance utility designed to maximize your site speed without the need for expensive monthly subscriptions or external API keys. By converting your images to the modern WebP format, you can significantly reduce file sizes while maintaining high visual quality.

This plugin is built with a **Safety-First** philosophy: every time an image is converted, the original file is archived in a secure backup folder. If you ever need to revert, a single click restores your original JPEG or PNG perfectly.

### Key Features:
* **100% Automatic:** New uploads are converted and optimized the moment they hit your Media Library.
* **Bulk Processing:** Convert your entire historical library using an AJAX-powered tool that prevents server timeouts.
* **Non-Destructive Workflow:** Original images are moved to `/uploads/webp-backups/` for safe keeping.
* **Live Savings Report:** View a real-time dashboard showing exactly how many megabytes of server space you have saved.
* **Individual & Bulk Restore:** Undo changes for a single image or your entire library at any time.
* **GitHub Integrated:** Features a built-in updater to ensure you always have the latest security and performance patches.

== Installation ==

1. Upload the `thisismyurl-webp-support` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Media > WebP Support** to access the dashboard.

== Frequently Asked Questions ==

= Does this delete my original images? =
No. It moves them to a backup folder within your uploads directory. This ensures you never lose your original high-resolution files.

= Will my images break if I delete the plugin? =
We recommend using the "Restore All" button in the dashboard before uninstallation. This reverts your site back to using standard JPEGs and PNGs.

= Does this support PNG transparency? =
Yes! The conversion engine specifically preserves alpha channels to ensure your logos and transparent graphics remain crisp and clear.

== Changelog ==

= 1.251229 =
* Fixed GitHub updater directory renaming logic.
* Corrected menu location to Tools submenu.
* Updated stable tag for release.

= 1.251224 =
* Final release with full WordPress coding standard compliance.
* Implemented uninstall.php for clean database removal.