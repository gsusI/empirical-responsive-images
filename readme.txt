=== Empirical Responsive Images ===
Contributors: jesusinica
Tags: responsive images, image optimization, image sizes, webp, avif
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Learns which image sizes visitors need, then creates responsive WordPress thumbnails and WebP/AVIF versions in the background.

== Description ==

Empirical Responsive Images helps WordPress send each visitor an image closer to the size shown on their screen. Smaller, better-sized images can mean less data to download and faster-looking pages.

Instead of guessing which image sizes your site might need, the plugin learns from real page layouts. When it repeatedly finds a missing size, it creates that thumbnail in the background. It can also create WebP and AVIF versions when your server supports them.

This directly targets common PageSpeed Insights messages such as "Improve image delivery," "Properly size images," and "Serve images in next-gen formats." Results still depend on your theme, original images, cache, hosting, and other plugins.

= Why image size matters =

A large image may be displayed in a small space. If WordPress has no close match, the browser may download far more image data than it needs.

Empirical Responsive Images gives WordPress more useful choices. Your existing responsive image system stays in place; the plugin fills proven gaps instead of replacing it.

= How it works =

1. After a page loads, a small browser check compares displayed images with the sizes already available.
2. Images with a good match are ignored and send no report.
3. Missing sizes must be confirmed before any work is scheduled.
4. WordPress creates the needed image files later, as a background task.
5. Future visitors can receive a closer image size.

No resizing happens in the visitor's browser. No image is generated while a visitor waits for the page.

= What it can improve =

* Better-sized responsive images for phones, tablets, laptops, and high-density screens.
* New thumbnails for WordPress Media Library images.
* Responsive versions of manageable local theme and plugin images.
* WebP and AVIF versions when supported by the server.
* Better image choices through WordPress's normal responsive image markup.
* Automatic cache refresh after new image files are ready.
* Clear status tables under Tools > Responsive Images.
* Optional maintenance commands for advanced users.

The plugin keeps existing custom image choices, crops, and picture layouts. It does not send images to a content delivery network (CDN) or outside image service.

== Privacy ==

Automatic learning reports only what the plugin needs to identify a local image and a rounded missing size. It may also include a general screen size and the space used by the image so WordPress can make a better choice next time.

These reports do not store the page URL, visitor identity, IP address, browser details, or referring page. Reports from logged-out visitors and Subscribers do not send cookies.

An optional detailed view is available only to site administrators. It can store combined image measurements and page paths for troubleshooting. Query strings and raw IP addresses are not saved.

Everything stays on your WordPress site. Nothing is sent to an external service.

Settings, learned size records, and generated images remain after uninstall by default. You can enable full cleanup under Tools > Responsive Images before uninstalling.

== Protection against abuse ==

The plugin accepts work only for images that belong to your site and were marked by the plugin. Visitors cannot submit any image URL or invent unlimited custom sizes.

Normal visitors must confirm the same missing size over time. Hard limits on requests, waiting jobs, retries, image size, daily work, and free disk space keep everything under control. Repeated or invalid reports do not gain priority.

== Installation ==

1. Upload `empirical-responsive-images` to `/wp-content/plugins/`.
2. Activate the plugin.
3. Done. It starts learning missing local image sizes and creates confirmed sizes in the background.

Tools > Responsive Images shows progress and advanced settings. You do not need to approve each size manually.

== Screenshots ==

1. Simple workflow: observe real image spaces, confirm demand, and create useful WordPress image sizes in the background.
2. Mobile Lighthouse and PageSpeed result on one test page before and after right-sized image optimization. Scores vary by site and test run.
3. Privacy summary with observation totals and WebP/AVIF support status.
4. Local images with the widths and spaces seen on real pages.
5. Learning queue showing recent demand and image-generation status.
6. Learned image sizes with usage totals, screen sizes, and how each size was confirmed.

== Advanced commands ==

List learned image sizes:

`wp empirical-responsive-images sizes`

Generate thumbnails and modern image versions:

`wp empirical-responsive-images regenerate --batch-size=10`

Force a rebuild:

`wp empirical-responsive-images regenerate --force`

== Frequently Asked Questions ==

= Can this improve "Improve image delivery" or "Properly size images" in PageSpeed Insights? =

It can help when PageSpeed finds images that are much larger than the space where they appear. The plugin creates closer size choices and can add WebP/AVIF versions.

It does not heavily compress original images or turn animated GIFs into videos, so some recommendations may remain. One test page improved from 75 to 99 and its Largest Contentful Paint (LCP) improved from 7.2 seconds to 2.3 seconds. Scores vary; no PageSpeed or Core Web Vitals result is guaranteed.

= Is this different from an image compression plugin? =

Yes. Compression plugins mainly make image files smaller. Empirical Responsive Images mainly creates the image dimensions your layouts need. Both jobs can be useful.

= Can I use it with another image optimization plugin? =

Usually. Let the other plugin compress originals and let this plugin create responsive sizes. If both plugins create WebP/AVIF versions, enable that feature in only one. Test the combination on a copy of your site before using it on a live store or busy site.

= Does it work with caching plugins and CDNs? =

The plugin can refresh caches from WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, Breeze, Autoptimize, and SiteGround Speed Optimizer after creating images.

It can still learn WordPress images when a CDN changes their public URLs. If a separate CDN keeps its own page cache, that CDN or its WordPress plugin must also clear the cached page.

= Does it work with lazy loading and page builders? =

Usually, when they produce normal WordPress image elements. The plugin rechecks lazy images after they load and watches images added after the page opens. When a gallery or slider swaps a wrapped image, its WebP/AVIF sources step aside so the new image shows.

CSS background images, drawing canvases, remote images, and heavily customized JavaScript galleries may not be eligible.

= Will it slow down visitors? =

Image checks run during spare browser time. Missing files are created later by WordPress background tasks. Visitors do not wait for resizing or WebP/AVIF generation.

= Do I need to approve every new size? =

No. Confirmed gaps are queued, created, retried when necessary, and used automatically. The administration page shows progress; it is not an approval queue.

= Do logged-in users need the same repeated visits? =

Subscribers do. By default, a logged-in Contributor, Author, Editor, or Administrator can confirm a valid missing size sooner. Regular visitors must confirm the same gap in three separate 15-minute periods by default.

= What visitor information is collected? =

Only a secure code pointing to a local image and rounded size information needed to find a missing choice. The plugin does not store a visitor's identity, IP address, browser details, referring page, or the URL of the page they visited.

= Can bots create endless image sizes? =

No. Only marked local images and a fixed list of sizes are accepted. Near matches count as covered, and strict queue, retry, storage, and daily work limits keep generation bounded.

= Will it work on my hosting? =

Most standard WordPress hosts should work if uploads are writable, WordPress background tasks run normally, and the server can create the requested image format. If background tasks are disabled, queued work waits until the host runs them.

== Changelog ==

= 0.3.12 =
* Fix WebP and AVIF images not showing on HTTPS sites whose uploads URL is stored with http. Picture sources now load over HTTPS whenever the page or the original image does, which stops browsers blocking them as mixed content. WooCommerce product galleries were one place this showed up.
* Stop repeat visitor reports from using up daily image work and refreshing pages when the image files and layout are already in place.

= 0.3.11 =
* Fix a full-site cache clear after background image work. Once new image sizes are ready, only the published post each image belongs to and posts using it as their featured image are refreshed; other pages pick up the new sizes when their cache expires. Sites with a long queue were cleared every night as the daily limit reset at midnight UTC.

= 0.3.10 =
* Fix galleries, sliders and product variation swaps that kept showing the original photo when the plugin had wrapped the image in a picture element. The WebP/AVIF sources now step aside while a script shows another image and return when the original comes back.

= 0.3.9 =
* Synchronize matching responsive image preloads, including WP Rocket hints, with current priority-image candidates. Preserve art-directed, format-specific and ambiguous hints.
* Preserve authored responsive sizes when upstream filters produce duplicate image attributes, avoiding competing oversized downloads.
* Delay upgrade cache purges until WordPress has loaded cache-plugin dependencies, preventing first-request failures with WP Rocket.
* Fix high-priority image learning that could generate a slightly undersized candidate the browser skips. New targets round upward, with existing queue and storage limits preserved.
* Keep cached version-2 image reports compatible during updates.

= 0.3.8 =
* Improve LCP image learning with static WebP support, tighter viable sizing for high-priority images, exact-width generation, and WP Rocket observer compatibility.

= 0.3.7 =
* Rewrite the public WordPress.org description and FAQs in plain language while preserving privacy details, compatibility guidance, and performance caveats.
