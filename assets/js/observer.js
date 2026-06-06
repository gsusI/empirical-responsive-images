(function () {
	'use strict';

	var script = document.currentScript || document.querySelector('script[src*="empirical-responsive-images/assets/js/observer.js"]');
	var dataset = script && script.dataset ? script.dataset : {};
	var config = window.EmpiricalResponsiveImagesObserver || {};
	var endpoint = config.endpoint || '';
	var sampleRate = Number(config.sampleRate || 0);
	var minRenderedWidth = Number(config.minRenderedWidth || 80);
	var maxImagesPerPayload = Number(config.maxImagesPerPayload || 40);
	var pending = new Map();
	var flushTimer = null;
	var resizeTimer = null;
	var resizeObserver = null;
	var watchedImages = new WeakSet();

	endpoint = endpoint || dataset.eriEndpoint || '';
	sampleRate = Number(config.sampleRate || dataset.eriSampleRate || 0);
	minRenderedWidth = Number(config.minRenderedWidth || dataset.eriMinRenderedWidth || 80);
	maxImagesPerPayload = Number(config.maxImagesPerPayload || dataset.eriMaxImages || 40);

	if (!endpoint || sampleRate <= 0 || Math.random() > sampleRate) {
		return;
	}

	function getAttachmentId(image) {
		var className = image.className || '';
		var match = String(className).match(/(?:^|\s)wp-image-([0-9]+)(?:\s|$)/);

		if (match) {
			return Number(match[1]);
		}

		return Number(image.getAttribute('data-attachment-id') || 0);
	}

	function measureImage(image) {
		if (!image || !image.getBoundingClientRect) {
			return;
		}

		var source = image.currentSrc || image.src || '';
		var rect = image.getBoundingClientRect();
		var dpr = Math.max(1, Math.min(4, window.devicePixelRatio || 1));
		var renderedWidth = Math.round(rect.width);
		var renderedHeight = Math.round(rect.height);

		if (!source || renderedWidth < minRenderedWidth || renderedHeight < 1) {
			return;
		}

		var item = {
			attachment_id: getAttachmentId(image),
			current_src: source,
			src: image.getAttribute('src') || '',
			rendered_width: renderedWidth,
			rendered_height: renderedHeight,
			target_width: Math.ceil(renderedWidth * dpr),
			target_height: Math.ceil(renderedHeight * dpr),
			natural_width: image.naturalWidth || 0,
			srcset_widths: parseSrcsetWidths(image.getAttribute('srcset') || ''),
			dpr: dpr,
			loading: image.getAttribute('loading') || '',
			fetchpriority: image.getAttribute('fetchpriority') || ''
		};
		var key = [
			item.attachment_id,
			item.current_src,
			item.rendered_width,
			item.rendered_height,
			item.dpr,
			window.innerWidth
		].join('|');

		pending.set(key, item);
		scheduleFlush();
	}

	function parseSrcsetWidths(srcset) {
		return String(srcset).split(',').map(function (candidate) {
			var match = candidate.trim().match(/\s([0-9]+)w$/);
			return match ? Number(match[1]) : 0;
		}).filter(function (width, index, widths) {
			return width > 0 && widths.indexOf(width) === index;
		});
	}

	function watchImage(image) {
		if (!image || watchedImages.has(image)) {
			return;
		}

		watchedImages.add(image);

		if (resizeObserver) {
			resizeObserver.observe(image);
		}

		if (image.complete) {
			measureImage(image);
			return;
		}

		image.addEventListener('load', function () {
			measureImage(image);
		});
	}

	function scanImages() {
		document.querySelectorAll('img').forEach(watchImage);
	}

	function scheduleFlush() {
		if (flushTimer) {
			return;
		}

		flushTimer = window.setTimeout(flush, 1200);
	}

	function flush() {
		var images = Array.from(pending.values()).slice(0, maxImagesPerPayload);

		flushTimer = null;
		pending.clear();

		if (!images.length) {
			return;
		}

		var payload = JSON.stringify({
			page_url: window.location.href,
			viewport: {
				width: window.innerWidth || 0,
				height: window.innerHeight || 0,
				dpr: window.devicePixelRatio || 1
			},
			images: images
		});
		var blob = new Blob([payload], { type: 'application/json' });

		if (navigator.sendBeacon && navigator.sendBeacon(endpoint, blob)) {
			return;
		}

		window.fetch(endpoint, {
			method: 'POST',
			body: payload,
			headers: {
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin',
			keepalive: true
		}).catch(function () {});
	}

	function handleResize() {
		window.clearTimeout(resizeTimer);
		resizeTimer = window.setTimeout(scanImages, 250);
	}

	if ('ResizeObserver' in window) {
		resizeObserver = new ResizeObserver(function (entries) {
			entries.forEach(function (entry) {
				measureImage(entry.target);
			});
		});
	}

	if ('MutationObserver' in window) {
		new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				mutation.addedNodes.forEach(function (node) {
					if (!node || node.nodeType !== 1) {
						return;
					}

					if (node.matches && node.matches('img')) {
						watchImage(node);
					}

					if (node.querySelectorAll) {
						node.querySelectorAll('img').forEach(watchImage);
					}
				});
			});
		}).observe(document.documentElement, { childList: true, subtree: true });
	}

	window.addEventListener('resize', handleResize, { passive: true });
	window.addEventListener('load', scanImages, { once: true });

	if (document.readyState === 'interactive' || document.readyState === 'complete') {
		scanImages();
	} else {
		document.addEventListener('DOMContentLoaded', scanImages, { once: true });
	}
}());
