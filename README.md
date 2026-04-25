# Image Support by This Is My URL

Current version: 1.6365

Image Support is a WordPress plugin for SEO-focused filename cleanup, media hardening, content reference synchronization, and safe rollback support.

## What It Does

- Tabbed admin interface with Optimize, Settings, and Report tabs.
- Batch AJAX processing with visible spinner and continuous progress updates.
- Search and pagination for pending and managed media lists.
- Filename sanitization for cleaner, SEO-friendlier image slugs.
- Content reference synchronization when file names change.
- Optional optimize-on-upload and background auto optimization.
- Optional auto optimization triggers for wp-admin traffic and WP-Cron.
- Backup and restore support for renamed image assets.
- 404 redirect support for legacy image paths.
- ROI reporting with business-friendly time windows.
- Duplicate cleanup for obviously identical media while preserving links.
- Metadata hardening (strip sensitive metadata + embed creator credit).
- Resize controls to keep oversized images server-safe.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- GD or Imagick image support

## Installation

1. Copy this plugin into `/wp-content/plugins/thisismyurl-image-support/`.
2. Activate it from the WordPress admin.
3. Open `Tools > Image Support`.

## Usage

1. Open `Tools > Image Support`.
2. Configure optimization, automation, and reporting assumptions in Settings.
3. Run `Optimize All` from the Optimize tab.
4. Review measurable impact in the Report tab.

The plugin processes image attachments in batches, updates matching content references, and keeps backup copies for rollback.

## Restore Behavior

Backup files are stored in `/wp-content/uploads/timu-image-backups/`.

When an image has been renamed, the plugin records the original path and filename in post meta so the file can be restored later and old requests can be redirected to the current attachment URL.

## Notes

- This plugin uses a tabbed interface and automation workflow for consistency.
- The focus is filenames, references, metadata safety, duplicates, and SEO-related asset cleanup.

## Versioning

This plugin uses the format 1.Yddd:

- Y = last digit of the year
- ddd = Julian day number for the final day of that year

For 2026, this is 1.6365.

## License

GPLv2 or later.

---

## Support and Contribute

### Ways to Support

I'm building these tools because WordPress developers and site owners deserve straightforward, practical solutions. There's no tracking, no ads, and you don't need to pay to use these plugins.

If you find them helpful, here are some genuine ways to support the work:

- **Sponsor if it fits your budget:** You can sponsor the project through [GitHub Sponsors](https://github.com/sponsors/thisismyurl). Sponsorship helps, but it's always optional.
- **Contribute code or ideas:** Opening a pull request, reporting an issue, or testing edge cases is just as valuable as sponsorship. Helping me improve these plugins is a great way to contribute.
- **Share your experience:** A review on [my Google My Business profile](https://business.google.com/refer) or a follow on [WordPress.org](https://profiles.wordpress.org/thisismyurl/), [GitHub](https://github.com/thisismyurl), or [LinkedIn](https://linkedin.com/in/thisismyurl) helps others find this work.

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

This plugin is built and maintained by [This Is My URL](https://thisismyurl.com/), a WordPress development and technical SEO practice. I'm Christopher Ross, a WordPress developer and technical SEO specialist with 25+ years of experience in software development, training, and digital learning.

### My Background

- **25+ years** in software development, technical training, and digital systems design
- **WordPress contributor since 2007** with a strong track record helping organizations build practical, maintainable web systems
- **Technical SEO practitioner** helping sites improve performance, security, and search visibility
- **Training specialist** focused on practical outcomes and helping teams adopt technology with confidence

I believe in straightforward solutions that work. No hype. No unnecessary complexity.

### Ways to Connect

- **WordPress.org profile:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/)
- **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl)
- **Website:** [thisismyurl.com](https://thisismyurl.com/)
- **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)


## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
