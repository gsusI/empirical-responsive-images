=== Empirical Responsive Images ===
Contributors: jesusinica
Tags: images, responsive images, thumbnails, webp, avif
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Learns real responsive-image needs and automatically generates matching thumbnails and WebP/AVIF variants.

== Description ==

Empirical Responsive Images learns which image widths a WordPress site actually needs from real visits. It verifies repeated gaps, automatically generates matching attachment or local asset variants in the background, and can create WebP and AVIF sidecars when the server image editor supports those formats.

Features:

* Automatic visitor learning measures during browser idle time, sends nothing for adequately covered images, and waits for visitor interaction or page exit before reporting uncovered gaps.
* Every automatic report is bound to an HMAC-signed local attachment or asset target.
* Anonymous and Subscriber gaps are verified across multiple server windows. A nonce-authenticated Contributor, Author, Editor, or Administrator can confirm a valid gap immediately.
* An optional administrator-only observer can collect richer diagnostics, but automatic optimization does not depend on administrator visits.
* Observations are stored as aggregate size data in WordPress options.
* Observed width candidates are registered through `add_image_size()`.
* Optional admin batch tool regenerates all known image thumbnails and local asset variants.
* Optional WP-CLI regeneration command.
* WebP and AVIF sidecars are generated when supported by the active image editor.
* WordPress attachment images and local asset images can be wrapped in `<picture>` with AVIF/WebP sources and original fallback markup.
* Stale page markup is purged automatically after successful background generation.
* No external service calls.

== Privacy ==

The full observer runs only for logged-in users who can manage site options. Requests require WordPress REST authentication and a valid REST nonce. It stores aggregate rendered image data and canonical page paths only; query strings and raw IP addresses are not persisted.

Automatic visitor learning is enabled by default for every front-end visitor, including logged-in administrators. A lightweight local script compares each eligible signed local image target's rendered requirement with its native candidates and applicable WebP/AVIF sources during browser idle time; covered images make no request. Uncovered gaps remain in memory until the first pointer, keyboard, scroll, touch, or resize interaction, with a page-exit fallback for passive visits. This keeps telemetry outside initial page navigation. Each target includes its attainable width ceiling so the browser does not request impossible output. Reports contain an HMAC-signed local target and a canonical physical-width bucket. Eligible targets can also include coarse, bounded viewport and slot buckets so responsive `sizes` hints can converge. Anonymous and Subscriber requests omit cookies. Contributor-or-stronger requests use normal same-origin WordPress cookies plus a REST nonce only to prove capability. The stored gap evidence contains no page URL, visitor identity, IP address, user agent, or referrer. Signed attachment targets continue to work when a CDN rewrites the public image URL.

The public request only records evidence. WordPress later re-resolves the signed target, verifies that the image is local and still lacks suitable coverage, and runs target-specific generation through a locked WP-Cron worker. Anonymous visitors and Subscribers must confirm one pair across the configured number of distinct 15-minute server windows, which defaults to three. A Contributor, Author, Editor, Administrator, or custom role with `edit_posts` can confirm a valid pair in one request when it also carries a valid WordPress REST nonce. Trusted status is derived by the server; client payload fields cannot claim it. Safety limits include 12 pairs per report; 120 evidence-bearing reports and 256 accepted unique pairs per window; 96 unqualified probation entries; 256 simultaneous active jobs and targets; a fixed 38-entry canonical width palette per target; 32 recent failure records; a 2,560-pixel automatic ceiling; WordPress's effective attachment srcset ceiling; and 50 generation attempts per day. Invalid or replay-only requests cannot consume the accepted-work budget, and extra replays never buy queue priority. Background generation also pauses before claiming work when the uploads filesystem has less than 256 MiB free; queued work remains for a later watchdog run. Missed-window low-confidence entries can be replaced under pressure, so one-off traffic cannot reserve the queue for 30 days.

Automatic learning remains enabled indefinitely. The 30-day rule is only a cleanup threshold for stale unconfirmed evidence and terminal failure records after their last activity. They are removed during a later learning or status request. Active queue items, successful target history, attachment metadata, generated image files, and future learning do not expire after 30 days.

By default, settings, observations, and plugin-generated image files are retained when the plugin is uninstalled. Site administrators can explicitly enable deletion under Tools > Responsive Images before uninstalling.

== Cache compatibility ==

Automatic visitor learning does not wait for an administrator to confirm pages or hold normal pages out of cache. After a background job succeeds, the plugin performs a debounced cache purge so regenerated markup can expose the new candidates.

An advanced legacy page-measurement guard is available only when automatic visitor learning is disabled. When explicitly enabled, it sends these WordPress and HTTP cache bypass signals until detailed page observations are stable:

* `DONOTCACHEPAGE`
* `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
* `CDN-Cache-Control: no-store`
* `Cloudflare-CDN-Cache-Control: no-store`
* `Surrogate-Control: no-store`
* `X-LiteSpeed-Cache-Control: no-cache`

It also disables LiteSpeed page optimization and lazy-loading constants during those warming requests so the measured image slots match the real rendered layout.

The observer script is marked with optimizer bypass attributes including `data-cfasync="false"`, `data-no-optimize="1"`, `data-no-defer="1"`, `data-no-minify="1"`, and `data-pagespeed-no-defer="1"`. Its REST configuration is also duplicated into `data-empirical-responsive-images-*` attributes so it can still run when an optimizer delays inline scripts.

== Installation ==

1. Upload `empirical-responsive-images` to `/wp-content/plugins/`.
2. Activate the plugin.
3. Done. The plugin learns uncovered local image sizes from real visits and processes qualified gaps in the background.

Tools > Responsive Images provides status and advanced settings. No review, promotion, or regeneration step is required for automatic learning.

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

Responsive `srcset` selection is width-driven. The plugin records height ranges for visibility but registers uncropped width candidates to avoid accidental art-direction crops. Author-owned `<picture>` art direction and cropped attachment renditions are preserved rather than rewritten as a different crop.

= Does this handle theme or plugin asset images? =

Yes. Manageable local `wp-content` image assets are observed, resized into `wp-content/uploads/empirical-responsive-images/assets/`, and rewritten with empirical `srcset` and `sizes` output.

= Does normal page rendering create image files? =

No. Front-end rendering only measures coverage and reads existing variants. Qualified target-specific jobs generate files later through WP-Cron. Uploads and optional admin/WP-CLI maintenance can also generate files.

= Does an administrator need to review or promote learned sizes? =

No. Activation is enough. Repeated gaps are verified, queued, generated, retried, and exposed through refreshed markup automatically. The admin queue is status only.

= Do logged-in users need the same three confirmations? =

Subscribers do. By default, Contributors, Authors, Editors, and Administrators do not: one valid gap report confirms immediately when WordPress authenticates the user with both the `edit_posts` capability and a valid REST nonce. Custom roles follow the capability, not the role name. Without both checks, the normal configured multi-window threshold applies.

= What happens to data when I uninstall the plugin? =

Data is retained by default to prevent accidental loss. Enable the uninstall cleanup setting first if you want uninstalling to remove settings, observations, and plugin-generated image files.

= Who can submit detailed front-end observations? =

Only logged-in users with the `manage_options` capability. The full observer uses WordPress cookie authentication and a REST nonce; anonymous visitors cannot submit or modify detailed observation data. The separate target-bound automatic detector runs for every visitor and can submit only signed image handles plus fixed width buckets.

= What does automatic visitor learning collect? =

Visitors can submit only an HMAC-signed handle for a local image target and a canonical target-width bucket that the browser found inadequately covered. Eligible targets can also submit coarse viewport and rendered-slot buckets used to improve `sizes` hints. Detection runs during browser idle time without starting an initial-navigation request; reports wait for visitor interaction or page exit. Anonymous and Subscriber requests omit cookies. Contributor-or-stronger requests use same-origin WordPress authentication only to prove capability; no page URL, visitor identity, IP address, user agent, or referrer is stored with the gap. Public requests never perform image generation directly.

= Can one-pixel screen changes create endless image sizes? =

No. Near matches count as covered, and every remaining pixel width maps into a fixed canonical palette. A one-pixel resize normally maps to the same bucket; the palette has only 38 possible widths across the full supported range. Existing candidates satisfy buckets without creating duplicate files, while fixed probation, queue, target, request, source, retry, and daily generation ceilings bound work.

= Does learning stop after 30 days? =

No. Learning stays on. Thirty days only controls cleanup of abandoned, never-confirmed evidence and terminal failure records. A terminal failure can re-enter learning after a 24-hour cooldown when fresh evidence arrives under the same role-based confirmation rules. Active jobs, successful history, generated variants, and newly discovered screen sizes remain unaffected.

= Does automatic processing work on every host? =

It uses WordPress image editors and WP-Cron. GD or Imagick must support the requested output format and uploads must be writable. Each cron invocation drains as many qualified jobs as fit in a 30-second runtime budget, then schedules remaining work for a later run. A job already in progress may finish after the deadline. Developers can adjust the bounded 1-to-120-second budget with the `empirical_responsive_images_background_optimizer_runtime_seconds` filter. On sites where WP-Cron is disabled, the host should invoke WordPress cron normally; otherwise queued work waits.

== Changelog ==

= 0.3.2 =
* Keep automatic gap-report requests out of initial page navigation by waiting for visitor interaction or page exit.
* Consume visible-page REST response bodies and reserve keepalive transport for the page-exit fallback.

= 0.3.1 =
* Drain multiple qualified image jobs during each time-boxed WP-Cron invocation instead of processing only one.
* Renew worker ownership and recheck upload capacity before each new job while preserving daily, retry, queue, and storage limits.

= 0.3.0 =
* Enable automatic target-bound visitor gap detection that sends nothing when current responsive candidates provide adequate coverage.
* Sign every attachment or local asset target and verify it again on the server.
* Confirm repeated gaps, then generate only the affected target through bounded WP-Cron jobs without administrator action.
* Let nonce-authenticated users with `edit_posts` confirm a valid gap in one report while keeping the configured multi-window threshold for Subscribers and anonymous visitors.
* Add fixed request, queue, target, per-image width, pixel, retry, and daily work limits.
* Apply 30-day cleanup eligibility only to stale unconfirmed evidence and terminal failure records; active jobs and generated variants persist.
* Keep automatic learning independent from administrator visits, approval, promotion, and the legacy page cache guard.
* Add canonical cross-browser width buckets, per-image attainable-width and applicable-format checks, low-confidence queue eviction, stale-file recovery, and durable width limits.
* Add coarse target-bound layout evidence and empirical `sizes` output for relative and responsive image slots.
* Preserve author-owned `srcset`, `sizes`, art direction, and animation-sensitive source formats.
* Prevent invalid or replay-only traffic from muting learning, remove replay-based queue priority, and reopen terminal failures automatically after a bounded cooldown.
* Bound synchronous source conversion by bytes and pixels, reserve uploads filesystem space, and let fresh qualified demand replace unconfirmed queue entries under pressure.
* Store attachment WebP/AVIF output in collision-proof plugin-owned paths.
* Debounce stale page-markup cache purges after background or explicit regeneration completes.

= 0.2.0 =
* Align plugin ownership metadata with WeSolvers.
* Require administrator capability and REST nonce verification for every observation write.
* Use SiteGround Speed Optimizer's documented public purge functions instead of an unregistered action name.
* Use the full plugin prefix for generated image-size names and observer data attributes.
* Keep front-end rendering read-only; missing local asset variants now require explicit regeneration.
* Prevent duplicate sidecar encoding during forced attachment regeneration.
* Serialize concurrent observation writes to prevent lost aggregate data.
* Purge a page cache when its first observation creates a blocked readiness record.
* Reject oversized or off-site observation payloads and omit query strings from stored page keys.
* Support custom WordPress content and upload directory mappings for local assets.
* Preserve plugin data on uninstall unless an administrator explicitly opts into deletion.
* Add PHPUnit, PHPCS, PHP compatibility, PHPStan, version-sync, CI, and package quality gates.

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
