=== Empirical Responsive Images ===
Contributors: jesusiniesta
Tags: images, responsive images, thumbnails, webp, avif
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Measures real image slots, registers matching sizes, regenerates thumbnails, and serves WebP/AVIF variants.

== Description ==

Empirical Responsive Images helps WordPress sites discover the image widths they actually render on real devices. It records aggregate front-end observations, registers matching WordPress image sizes, regenerates missing thumbnails, generates responsive variants for local theme/plugin asset images, and can create WebP and AVIF sidecar files when the server image editor supports those formats.

Features:

* Front-end observer measures rendered image width, height, viewport width, DPR, attachment ID, and local asset source URL.
* Observations are stored as aggregate size data in WordPress options.
* Observed width candidates are registered through `add_image_size()`.
* Admin batch tool regenerates image thumbnails and observed local asset variants.
* Optional WP-CLI regeneration command.
* WebP and AVIF sidecars are generated when supported by the active image editor.
* WordPress attachment images and local asset images can be wrapped in `<picture>` with AVIF/WebP sources and original fallback markup.
* Pages are kept out of page cache until a no-cache observation run confirms that known image sizes are already represented in `srcset` output.
* No external service calls.

== Privacy ==

The public observer stores aggregate rendered image data only. It does not persist raw IP addresses. A salted transient key is used only for short-lived rate limiting.

== Cache compatibility ==

Before a page is confirmed stable, the plugin sends WordPress and HTTP cache bypass signals:

* `DONOTCACHEPAGE`
* `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
* `CDN-Cache-Control: no-store`
* `Cloudflare-CDN-Cache-Control: no-store`
* `Surrogate-Control: no-store`
* `X-LiteSpeed-Cache-Control: no-cache`

It also disables LiteSpeed page optimization and lazy-loading constants during those warming requests so the measured image slots match the real rendered layout.

The observer script is marked with optimizer bypass attributes including `data-cfasync="false"`, `data-no-optimize="1"`, `data-no-defer="1"`, `data-no-minify="1"`, and `data-pagespeed-no-defer="1"`. Its REST configuration is also duplicated into `data-eri-*` attributes so it can still run when an optimizer delays inline scripts.

== Installation ==

1. Upload `empirical-responsive-images` to `/wp-content/plugins/`.
2. Activate the plugin.
3. Visit the front end on representative desktop, tablet, and mobile widths.
4. Go to Tools > Responsive Images.
5. Review observed sizes and run thumbnail regeneration.

== WP-CLI ==

List observed sizes:

`wp empirical-responsive-images sizes`

Regenerate thumbnails and modern sidecars:

`wp empirical-responsive-images regenerate --batch-size=10`

Force rebuild:

`wp empirical-responsive-images regenerate --force`

== Frequently Asked Questions ==

= Does this replace WordPress responsive images? =

No. It adds empirical image sizes so WordPress has better candidates for its normal `srcset` output.

= Does this use a CDN or external optimization API? =

No. All work happens inside WordPress with the configured GD or Imagick image editor.

= Why are image sizes registered by width only? =

Responsive `srcset` selection is width-driven. The plugin records height ranges for visibility but registers uncropped width candidates to avoid accidental art-direction crops.

= Does this handle theme or plugin asset images? =

Yes. Manageable local `wp-content` image assets are observed, resized into `wp-content/uploads/empirical-responsive-images/assets/`, and rewritten with empirical `srcset` and `sizes` output.

== Changelog ==

= 0.1.8 =
* Use WordPress's template enhancement output buffer for full-page asset processing.
* Bound public observation rate-limit storage and retained page observations.

= 0.1.7 =
* Skip alternate format sidecars for attachment sub-sizes that do not preserve the original aspect ratio.

= 0.1.6 =
* Skip alpha-flattening alternate formats for transparent source images.

= 0.1.5 =
* Treat asset srcsets capped at the original source width as cache-ready.

= 0.1.4 =
* Match generated asset variant URLs to the public site scheme to avoid mixed-content requests.

= 0.1.3 =
* Add static GIF asset support while skipping animated GIFs to avoid animation loss.

= 0.1.2 =
* Observe small images by default so theme asset covers, logos, and icons can be captured.

= 0.1.1 =
* Add empirical responsive variants for local theme/plugin asset images.

= 0.1.0 =
* Initial release.
