=== Empirical Responsive Images – Auto Image Sizes, WebP & AVIF ===
Contributors: jesusinica
Tags: responsive images, image sizes, srcset, thumbnails, lcp
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fix oversized images with zero setup. Automatically creates the image sizes, thumbnails, WebP and AVIF your pages need, in the background.

== Description ==

**Activate it and forget it.** Empirical Responsive Images finds the images your WordPress site sends too large, then creates the sizes that actually fit. There is nothing to configure, no bulk run to start, no queue to approve, and no account or API key.

Most sites send phones images made for desktops. WordPress can only offer the thumbnail sizes it already has, so when none is close, the browser downloads far more image than the screen can show. PageSpeed Insights reports this as "Improve image delivery" or "Properly size images", and it can hold back your Largest Contentful Paint (LCP) and Core Web Vitals.

Other tools ask you to guess which image sizes you need. This one measures them on your real pages, so it only creates sizes your layouts use.

= What happens after you activate it =

None of this needs you. It runs on its own for as long as the plugin is active.

* A small browser check compares each displayed image with the sizes already available. Images with a good match are ignored.
* Missing sizes are confirmed before any work is scheduled.
* WordPress creates the new image files later, as a background task.
* Your existing responsive image markup picks them up, and your cache refreshes.
* Future visitors get a closer image size.

No resizing happens in the visitor's browser, and no image is generated while a visitor waits for the page.

= What you get =

* Better-sized responsive images for phones, tablets, laptops, and high-density screens.
* Missing WordPress thumbnails created automatically, so there is no need to regenerate thumbnails for your whole library.
* WebP and AVIF versions when your server supports them.
* Better `srcset` and `sizes` choices through normal WordPress markup.
* Coverage for Media Library images and manageable local theme and plugin images.
* Automatic cache refresh after new image files are ready.
* Status tables under Tools > Responsive Images, if you are curious. Nothing there needs your attention.

Your existing custom image choices, crops, and picture layouts stay as they are. The plugin fills proven gaps instead of replacing WordPress responsive images. It is free, and it does not send images to a content delivery network (CDN) or outside image service.

= Real result =

On one test page, the mobile PageSpeed score went from 75 to 99 and LCP improved from 7.2 seconds to 2.3 seconds. Results depend on your theme, original images, cache, hosting, and other plugins, so no score is guaranteed.

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

1. In WordPress, go to Plugins > Add New and search for Empirical Responsive Images.
2. Click Install Now, then Activate.
3. That's it. There is no setup step.

The plugin starts learning right away and creates confirmed sizes in the background.

== Screenshots ==

1. Activate it and forget it: you have one job, and the plugin does everything else automatically.
2. A large photo in a small phone slot wastes most of its pixels. The plugin creates the size that fits.
3. The image size your layout needs but WordPress never made. The plugin makes it for you.
4. Mobile Lighthouse and PageSpeed result on one test page before and after right-sized image optimization. Scores vary by site and test run.
5. Optional status page: totals and WebP/AVIF support, with nothing for you to do.
6. Optional status page: local images with the widths and spaces seen on real pages.
7. Optional status page: recent demand and image generation, which run automatically.
8. Optional status page: learned image sizes with usage totals and screen sizes.

== Advanced commands ==

List learned image sizes:

`wp empirical-responsive-images sizes`

Generate thumbnails and modern image versions:

`wp empirical-responsive-images regenerate --batch-size=10`

Force a rebuild:

`wp empirical-responsive-images regenerate --force`

== Frequently Asked Questions ==

= Do I need to configure anything? =

No. Install it, activate it, and you are done. It learns, creates sizes, retries when necessary, and refreshes your cache on its own. The settings under Tools > Responsive Images are optional.

= Do I need to approve every new size? =

No. Confirmed gaps are queued, created, and used automatically. The administration page shows progress; it is not an approval queue.

= Do I need to regenerate thumbnails? =

No. The plugin creates the missing sizes your pages actually use, one image at a time, in the background. If you prefer to run a rebuild yourself, the optional WP-CLI commands can do it.

= How long until I see results? =

Pages that get regular traffic are usually first. Regular visitors confirm a missing size across three separate 15-minute periods by default, and the plugin runs up to 50 image jobs per day, so large sites catch up gradually. Pages with few visitors take longer.

= Can this improve "Improve image delivery" or "Properly size images" in PageSpeed Insights? =

It can help when PageSpeed finds images that are much larger than the space where they appear. The plugin creates closer size choices and can add WebP/AVIF versions.

It does not heavily compress original images or turn animated GIFs into videos, so some recommendations may remain. One test page improved from 75 to 99 and its Largest Contentful Paint (LCP) improved from 7.2 seconds to 2.3 seconds. Scores vary; no PageSpeed or Core Web Vitals result is guaranteed.

= Does it convert images to WebP and AVIF? =

Yes, when your server's image library supports the format. It keeps your originals and adds separate WebP/AVIF files.

= Is this different from an image compression plugin? =

Yes. Compression plugins mainly make image files smaller. Empirical Responsive Images mainly creates the image dimensions your layouts need. Both jobs can be useful.

= Can I use it with another image optimization plugin? =

Usually. Let the other plugin compress originals and let this plugin create responsive sizes. If both plugins create WebP/AVIF versions, enable that feature in only one. Test the combination on a copy of your site before using it on a live store or busy site.

= Does it work with caching plugins and CDNs? =

The plugin can refresh caches from WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, Breeze, Autoptimize, and SiteGround Speed Optimizer after creating images.

It can still learn WordPress images when a CDN changes their public URLs. If a separate CDN keeps its own page cache, that CDN or its WordPress plugin must also clear the cached page.

= Does it work with lazy loading, page builders, and WooCommerce? =

Usually, when they produce normal WordPress image elements. The plugin rechecks lazy images after they load and watches images added after the page opens. When a gallery, slider, or WooCommerce product variation swaps a wrapped image, its WebP/AVIF sources step aside so the new image shows.

CSS background images, drawing canvases, remote images, and heavily customized JavaScript galleries may not be eligible.

= Will it slow down visitors? =

Image checks run during spare browser time. Missing files are created later by WordPress background tasks. Visitors do not wait for resizing or WebP/AVIF generation.

= Do logged-in users need the same repeated visits? =

Subscribers do. By default, a logged-in Contributor, Author, Editor, or Administrator can confirm a valid missing size sooner.

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
