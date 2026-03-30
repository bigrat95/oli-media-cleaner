=== Oli Media Cleaner ===
Contributors: bigrat95
Tags: media, cleanup, unused images, media cleaner, disk space
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan and remove unused media files from your WordPress site to free up disk space.

== Description ==

**Oli Media Cleaner** scans your entire WordPress site to find media files that are no longer in use. It performs a deep analysis across multiple sources to accurately determine which files are safe to remove.

= What Gets Scanned =

* **Post & page content** — all post types, Gutenberg blocks, classic editor
* **Featured images** — all post types including WooCommerce product variations
* **Custom fields (post meta)** — any plugin or theme that stores attachment IDs or URLs
* **ACF (Advanced Custom Fields)** — image, file, gallery, repeater, flexible content, group, clone fields, and Options pages
* **WooCommerce** — product galleries, variation images
* **Elementor** — page builder widget data
* **Theme files** — PHP, CSS, JS templates scanned for hardcoded image references
* **CSS background images** — inline styles in post content
* **Widgets** — image, gallery, text, custom HTML widgets
* **Site identity** — site logo, site icon, theme mods
* **Serialized data** — deep scan of complex plugin data structures

= Features =

* **Batch scanning** — processes 50 attachments per batch to avoid timeouts
* **Whitelist** — protect files you want to keep even if unused
* **Bulk actions** — trash, whitelist, restore, or permanently delete multiple files at once
* **Trash All** — one-click batch trash of all unused images with progress bar (handles thousands)
* **Scheduled auto-cleanup** — enable daily, twice daily, or weekly automatic scan and trash via WP-Cron
* **Progress bar** — real-time scanning progress
* **No external dependencies** — uses native WordPress admin styles, no Bootstrap or jQuery UI
* **Zero custom database tables** — stores data in WordPress options
* **Clean uninstall** — removes all plugin data on deletion

= How to Use =

1. Go to **Oli Media Cleaner** under **Media** in the WordPress admin sidebar
2. Click **Scan for Unused Media**
3. Review the results in the **Unused** tab
4. **Whitelist** any files you want to keep
5. **Trash** files you don't need
6. Go to the **Trash** tab to permanently delete or restore files

== Installation ==

1. Upload the `oli-media-cleaner` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Oli Media Cleaner** under **Media** in the admin sidebar
4. Click **Scan for Unused Media** to start

== Frequently Asked Questions ==

= Is it safe to delete unused media? =

The plugin moves files to WordPress trash first. You can review and restore them before permanently deleting. We recommend making a full backup before any bulk deletion.

= Does it work with ACF? =

Yes. The plugin uses the ACF API to discover all field groups and recursively scans image, file, gallery, repeater, flexible content, group, and clone fields — including ACF Options pages.

= Does it work with WooCommerce? =

Yes. Product featured images, gallery images, and variation images are all detected as "in use."

= Does it work with Elementor? =

Yes. The plugin scans Elementor's `_elementor_data` post meta for image references.

= Does it scan theme files? =

Yes. All PHP, CSS, and JS files in the active theme (and parent theme) are scanned for hardcoded references to `wp-content/uploads/`.

= What about images hardcoded in theme templates? =

Those are detected by the theme file scanner. It finds both URL paths and `wp_get_attachment_image()` function calls.

= Can I whitelist images? =

Yes. You can whitelist individual images or use bulk select to whitelist multiple at once. Whitelisted images are never flagged as unused.

= Does it create custom database tables? =

No. The plugin uses WordPress options only. Clean and lightweight.

== Screenshots ==

1. Main dashboard with stats cards and scan button
2. Unused media list with bulk actions
3. Whitelist tab
4. Scan sources information panel

== Changelog ==

= 1.5.0 =
* Renamed plugin from "Delete Unused Images" to "Oli Media Cleaner"
* New slug: oli-media-cleaner, new prefix: olimc_
* Added "Empty Trash" button — batch-deletes all trashed attachments with progress bar
* Added taxonomy image scanning: WooCommerce category thumbnails, term descriptions, attribute descriptions
* Added termmeta deep scan for images stored by third-party plugins
* Live tab count updates during Trash All and Empty Trash batch operations
* Fixed trash tab count not updating after single/bulk trash, delete, or restore actions

= 1.4.0 =
* Fixed all WordPress Plugin Check errors
* Proper output escaping (esc_html_e, esc_html__, esc_html)
* Translators comments for all placeholder strings
* Ordered placeholders (%1$d, %2$d) for multi-placeholder strings
* Fixed SQL preparation: inline $wpdb->prepare(), esc_like() for LIKE queries
* Dynamic file type filter (only shows detected extensions)
* Per-page selector (20, 50, 100)

= 1.3.0 =
* Clickable column headers to sort by Name, Size, Type, or Date (asc/desc)
* File type filter dropdown (JPG, PNG, GIF, WebP, SVG, PDF, MP4)
* Sort indicators (arrows) on active column
* Filters, sort, and search all reset when switching tabs

= 1.2.0 =
* Added search box to filter images by name, filename, type, or ID
* Search works across all tabs (Unused, Whitelist, Trash)
* Search clears automatically when switching tabs

= 1.1.0 =
* Added "Trash All Unused" button — batch-processes all unused images with progress bar
* Added scheduled auto-cleanup via WP-Cron (daily, twice daily, or weekly)
* Settings panel for enabling/disabling auto-cleanup and choosing frequency
* Native WordPress admin UI refinements (postbox, form-table, nav-tab-wrapper, widefat)
* Reduced custom CSS from 500+ lines to ~20 lines

= 1.0.0 =
* Initial release
* Deep scanning: post content, featured images, post meta, ACF fields, WooCommerce, Elementor, theme files, widgets, site identity
* Whitelist feature with bulk actions
* Trash and permanent delete with bulk actions
* Progress bar during scan
* "What was scanned?" info panel
* Native WordPress admin UI — no external dependencies

== Upgrade Notice ==

= 1.5.0 =
Adds Empty Trash, taxonomy image scanning, and live tab count updates.

= 1.4.0 =
Fixes all WordPress Plugin Check errors for WP.org compliance.

= 1.3.0 =
Adds column sorting and file type filter.

= 1.2.0 =
Adds search functionality to filter images.

= 1.1.0 =
Adds Trash All button and scheduled auto-cleanup.

= 1.0.0 =
Initial release.
