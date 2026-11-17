=== Enhanced Font Manager for Bricks ===
Contributors: stphnwlkr
Tags: bricks, fonts, typography, woff2
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage local and variable fonts, preview type, and inspect the exact CSS Bricks generates.

== Description ==

Open Enhanced Font Manager for Bricks using the circular Aa icon in the Bricks builder toolbar. WordPress also provides Bricks → Enhanced Font Manager. The plugin needs Bricks 2.4.3 or newer with its custom font abilities.

The manager detects variable axes, converts supported uploads to WOFF2, previews saved and new faces, and writes native Bricks font families. The family inspector has exact and effective CSS, variable names, fallback stacks, font-display, size matching, preloading, and heading/body type roles. If Automatic.css is active, the role controls update its native typography settings.

The file library lists assigned and unused files and supports reusing files in another family and permanently deleting selected files after reviewing which Bricks families use them. Google Fonts can be previewed in the builder, then downloaded to WordPress with selected styles and language subsets. The transfer view exports font binaries and settings and accepts Enhanced Font Manager for Bricks and Etch Toolkit font packages.

The WOFF2 WebAssembly worker comes from Etch Font Manager by Don Kanishka. See assets/woff2/ for the source and build references and the Google WOFF2 and Brotli licenses.

Select saved WOFF, TTF or OTF attachments in Files and use Convert to WOFF2 to update their Bricks families or create an unassigned WOFF2 copy. Originals are retained. The Google Fonts view and download actions honor Bricks' Disable Google Fonts setting. Existing local fonts remain available.

== Updates ==

WordPress checks the plugin's public GitHub Releases for updates. The check retrieves public release metadata and downloads the installable ZIP when you choose to update or enable WordPress automatic updates. It requires no GitHub account or token.

== Changelog ==

= 0.3.0 =
* Added GitHub-backed plugin updates in WordPress Admin.
* Hardened validation of incoming font uploads.
* Replaced the toolbar icon with an original circular Aa design for public distribution.
