# Enhanced Font Manager for Bricks

A standalone font manager in the Bricks Builder toolbar. It uses Bricks' native font families and supports variable axes, a live preview, WOFF2 conversion, reusable font files, and inspection of the CSS Bricks generates.

Requires WordPress 6.5+, PHP 8.1+, and Bricks 2.4.3+.

## Updates

Install version 0.3.0 or newer once using an installable ZIP. Later stable releases appear under **Plugins** or **Dashboard → Updates** in WordPress Admin. WordPress controls when automatic updates run.

## Build and release

1. Start each build on a new `build/<version>` branch cut from `main`.
2. Match the PHP header, `VERSION`, readme stable tag, and `update.json` to the build version. Use the tag format `v<version>` and the asset name `bricks-variable-fonts.zip` in the manifest.
3. Run `npx pressship verify .`. WordPress.org Plugin Check flags the `Update URI` header because that directory does not allow a self-hosted updater. This plugin is distributed via GitHub. Review and fix other findings before packaging.
4. After merging the build branch, tag the checked commit. Preserve the installation folder and WordPress plugin identity when building the archive:

   ```sh
   git archive --format=zip --prefix=bricks-variable-fonts/ -o bricks-variable-fonts.zip v0.3.0 -- '*.php' assets readme.txt LICENSE.txt
   ```

5. Create a GitHub Release for the tag, attach `bricks-variable-fonts.zip` and the matching `update.json`, then publish it as a stable release. The updater reads metadata from the latest stable GitHub Release, so builds on a branch are not offered to WordPress sites.

Licensed under GPL-2.0-or-later. See [the converter credits and source](assets/woff2/README.txt) for the vendored WOFF2 components.
