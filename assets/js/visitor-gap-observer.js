(function () {
	'use strict';

	function parseSrcsetWidths(srcset, renderedWidth) {
		return String(srcset || '').split(',').map(function (candidate) {
			var match = candidate.trim().match(/\s([0-9]+(?:\.[0-9]+)?)(w|x)(?:\s|$)/);

			if (!match) {
				return 0;
			}

			return match[2] === 'w'
				? Number(match[1])
				: Math.ceil(Number(renderedWidth || 0) * Number(match[1]));
		}).filter(function (width, index, widths) {
			return width > 0 && widths.indexOf(width) === index;
		}).sort(function (a, b) {
			return a - b;
		});
	}

	function selectedCandidateWidth(srcset, currentSrc, renderedWidth) {
		var expected = normalizedUrl(currentSrc);

		if (!expected) {
			return 0;
		}

		var selected = String(srcset || '').split(',').map(function (candidate) {
			var parts = candidate.trim().split(/\s+/);

			if (normalizedUrl(parts[0] || '') !== expected || !parts[1]) {
				return 0;
			}

			var match = parts[1].match(/^([0-9]+(?:\.[0-9]+)?)(w|x)$/);

			if (!match) {
				return 0;
			}

			return match[2] === 'w'
				? Number(match[1])
				: Math.ceil(Number(renderedWidth || 0) * Number(match[1]));
		}).filter(function (width) {
			return width > 0;
		});

		return selected.length ? selected[0] : 0;
	}

	function isTargetCovered(targetWidth, candidateWidths) {
		var target = Math.max(1, Math.ceil(Number(targetWidth || 0)));

		return candidateWidths.some(function (candidateWidth) {
			var candidate = Number(candidateWidth || 0);

			return Number.isFinite(candidate)
				&& candidate > 0
				&& (50 * candidate) >= (49 * target)
				&& (5 * candidate) <= (6 * target);
		});
	}

	function coveringCandidateWidth(targetWidth, candidateWidths) {
		var target = Math.max(1, Math.ceil(Number(targetWidth || 0)));
		var covered = candidateWidths.map(Number).filter(function (candidateWidth) {
			return isTargetCovered(target, [candidateWidth]);
		}).sort(function (a, b) {
			var distance = Math.abs(a - target) - Math.abs(b - target);

			return distance || a - b;
		});

		return covered.length ? covered[0] : 0;
	}

	function parseBucketWidths(widths) {
		if (typeof widths === 'string') {
			try {
				widths = JSON.parse(widths);
			} catch (error) {
				return [];
			}
		}

		if (!Array.isArray(widths) || widths.length !== 38) {
			return [];
		}

		var normalized = widths.map(Number);

		if (normalized.some(function (width, index) {
			return !Number.isInteger(width)
				|| width < 1
				|| width > 5120
				|| (index > 0 && width <= normalized[index - 1]);
		})) {
			return [];
		}

		return normalized;
	}

	function bucketWidth(targetWidth, widths, maximumWidth) {
		var buckets = parseBucketWidths(widths);
		var safeMaximum = Math.max(1, Math.min(5120, Math.floor(Number(maximumWidth || 2560))));
		var target = Math.min(safeMaximum, Math.max(1, Math.ceil(Number(targetWidth || 0))));

		for (var index = 0; index < buckets.length; ++index) {
			if ((50 * buckets[index]) >= (49 * target)) {
				return Math.min(buckets[index], safeMaximum);
			}
		}

		return 0;
	}

	function parseRequiredFormats(formats) {
		if (typeof formats === 'string') {
			try {
				formats = JSON.parse(formats);
			} catch (error) {
				return [];
			}
		}

		if (!Array.isArray(formats)) {
			return [];
		}

		return formats.map(function (format) {
			return String(format || '').trim().toLowerCase().replace(/^image\//, '');
		}).filter(function (format, index, normalizedFormats) {
			return /^[a-z0-9.+-]+$/.test(format) && normalizedFormats.indexOf(format) === index;
		});
	}

	var testHook = window.EmpiricalResponsiveImagesVisitorGapTestHook;

	if (testHook && typeof testHook === 'object') {
		testHook.parseSrcsetWidths = parseSrcsetWidths;
		testHook.isTargetCovered = isTargetCovered;
		testHook.coveringCandidateWidth = coveringCandidateWidth;
		testHook.parseBucketWidths = parseBucketWidths;
		testHook.bucketWidth = bucketWidth;
		testHook.selectedCandidateWidth = selectedCandidateWidth;
	}

	var script = document.currentScript || document.querySelector('script[src*="empirical-responsive-images/assets/js/visitor-gap-observer.js"]');
	var dataset = script && script.dataset ? script.dataset : {};
	var config = window.EmpiricalResponsiveImagesVisitorGapObserver || {};
	var endpoint = config.endpoint || dataset.empiricalResponsiveImagesVisitorGapEndpoint || '';
	var token = config.token || dataset.empiricalResponsiveImagesVisitorGapToken || '';
	var sampleRate = Number(config.sampleRate || dataset.empiricalResponsiveImagesVisitorGapSampleRate || 0);
	var minRenderedWidth = Number(config.minRenderedWidth || dataset.empiricalResponsiveImagesVisitorGapMinWidth || 1);
	var maximumWidth = Number(config.maximumWidth || dataset.empiricalResponsiveImagesVisitorGapMaxWidth || 2560);
	var bucketProtocolVersion = Number(config.bucketVersion || dataset.empiricalResponsiveImagesVisitorGapBucketVersion || 0);
	var bucketWidths = parseBucketWidths(
		Array.isArray(config.bucketWidths)
			? config.bucketWidths
			: dataset.empiricalResponsiveImagesVisitorGapBucketWidths || '[]'
	);
	var maximumImages = Number(config.maximumImages || dataset.empiricalResponsiveImagesVisitorGapMaxImages || 80);
	var maximumGaps = Number(config.maximumGaps || config.maximumWidths || dataset.empiricalResponsiveImagesVisitorGapMaxGaps || 12);
	var requiredFormats = parseRequiredFormats(
		Array.isArray(config.requiredFormats)
			? config.requiredFormats
			: dataset.empiricalResponsiveImagesVisitorGapRequiredFormats || '[]'
	);
	var pendingGaps = new Map();
	var reportedGaps = new Set();
	var watchedImages = new WeakSet();
	var watchedImageQueue = [];
	var flushTimer = null;
	var scanTimer = null;
	var resizeObserver = null;
	var reportedGapCount = 0;
	var maximumReportedGaps = 48;

	if (
		typeof window.fetch !== 'function'
		|| !endpoint
		|| !token
		|| bucketProtocolVersion < 1
		|| !bucketWidths.length
		|| sampleRate <= 0
		|| Math.random() > Math.min(1, sampleRate)
	) {
		return;
	}

	maximumImages = Math.max(1, Math.min(120, maximumImages));
	maximumGaps = Math.max(1, Math.min(12, maximumGaps));

	function scheduleFlush() {
		if (flushTimer || reportedGapCount >= maximumReportedGaps) {
			return;
		}

		flushTimer = window.setTimeout(flush, 800);
	}

	function queueGap(target, targetWidth, targetMaximumWidth, nativeWidth, viewportWidth, slotWidth) {
		var width = bucketWidth(targetWidth, bucketWidths, Math.min(maximumWidth, targetMaximumWidth || maximumWidth));
		var key = target + '|' + width;

		if (
			!target
			|| width < 1
			|| reportedGaps.has(key)
			|| pendingGaps.has(key)
			|| reportedGapCount + pendingGaps.size >= maximumReportedGaps
		) {
			return;
		}

		var gap = {
			target: target,
			width: width
		};

		if (nativeWidth > 0) {
			gap.native_width = Math.floor(nativeWidth);
		}

		if (viewportWidth > 0 && slotWidth > 0) {
			gap.viewport = Math.min(5120, Math.ceil(viewportWidth / 128) * 128);
			gap.slot = Math.min(5120, Math.ceil(slotWidth / 16) * 16);
		}

		pendingGaps.set(key, gap);
		scheduleFlush();
	}

	function normalizedUrl(url) {
		var baseUrl = document.baseURI || (window.location && window.location.href) || '';

		if (!url || !baseUrl || typeof URL !== 'function') {
			return '';
		}

		try {
			return new URL(url, baseUrl).href;
		} catch (error) {
			return '';
		}
	}

	function srcsetContainsUrl(srcset, url) {
		var expected = normalizedUrl(url);

		if (!expected) {
			return false;
		}

		return String(srcset || '').split(',').some(function (candidate) {
			var candidateUrl = candidate.trim().split(/\s+/)[0] || '';

			return normalizedUrl(candidateUrl) === expected;
		});
	}

	function isSourceApplicable(source) {
		var media = source.getAttribute('media') || '';

		return !media || typeof window.matchMedia !== 'function' || window.matchMedia(media).matches;
	}

	function getCandidateWidths(image, renderedWidth) {
		var imageSrcset = image.getAttribute('srcset') || '';
		var widths = parseSrcsetWidths(imageSrcset, renderedWidth);
		var picture = image.parentElement;
		var currentSource = image.currentSrc || '';

		if (!picture || String(picture.nodeName || '').toLowerCase() !== 'picture' || !picture.querySelectorAll) {
			return widths;
		}

		if (
			srcsetContainsUrl(imageSrcset, currentSource)
			|| normalizedUrl(image.getAttribute('src') || '') === normalizedUrl(currentSource)
		) {
			return widths;
		}

		var matchingSources = Array.from(picture.querySelectorAll('source[srcset]')).filter(isSourceApplicable);

		var activeSource = matchingSources.find(function (source) {
			return srcsetContainsUrl(source.getAttribute('srcset') || '', currentSource);
		});

		if (activeSource) {
			return parseSrcsetWidths(activeSource.getAttribute('srcset') || '', renderedWidth);
		}

		matchingSources.forEach(function (source) {
			widths = widths.concat(parseSrcsetWidths(source.getAttribute('srcset') || '', renderedWidth));
		});

		return widths.filter(function (width, index, candidates) {
			return candidates.indexOf(width) === index;
		}).sort(function (a, b) {
			return a - b;
		});
	}

	function getSelectedCandidateWidth(image, renderedWidth) {
		var imageSrcset = image.getAttribute('srcset') || '';
		var currentSource = image.currentSrc || '';
		var picture = image.parentElement;

		if (!picture || String(picture.nodeName || '').toLowerCase() !== 'picture' || !picture.querySelectorAll) {
			return selectedCandidateWidth(imageSrcset, currentSource, renderedWidth);
		}

		var matchingSources = Array.from(picture.querySelectorAll('source[srcset]')).filter(isSourceApplicable);
		var activeSource = matchingSources.find(function (source) {
			return srcsetContainsUrl(source.getAttribute('srcset') || '', currentSource);
		});

		return selectedCandidateWidth(
			activeSource ? activeSource.getAttribute('srcset') || '' : imageSrcset,
			currentSource,
			renderedWidth
		);
	}

	function getFormatCandidateWidths(image, renderedWidth, format) {
		var picture = image.parentElement;
		var expectedType = 'image/' + format;
		var widths = [];

		if (!picture || String(picture.nodeName || '').toLowerCase() !== 'picture' || !picture.querySelectorAll) {
			return widths;
		}

		Array.from(picture.querySelectorAll('source[srcset]')).filter(function (source) {
			var sourceType = String(source.getAttribute('type') || '').split(';')[0].trim().toLowerCase();

			return sourceType === expectedType && isSourceApplicable(source);
		}).forEach(function (source) {
			widths = widths.concat(parseSrcsetWidths(source.getAttribute('srcset') || '', renderedWidth));
		});

		return widths.filter(function (width, index, candidates) {
			return candidates.indexOf(width) === index;
		}).sort(function (a, b) {
			return a - b;
		});
	}

	function inspectImage(image) {
		if (!image || !image.getBoundingClientRect) {
			return;
		}

		var target = image.getAttribute('data-empirical-responsive-images-target') || '';
		var targetKind = image.getAttribute('data-empirical-responsive-images-target-kind') || '';

		if (!target) {
			return;
		}

		var rect = image.getBoundingClientRect();
		var renderedWidth = Math.round(rect.width);
		var renderedHeight = Math.round(rect.height);
		var dpr = Math.max(1, Math.min(4, window.devicePixelRatio || 1));
		var imageMaximumWidth = Number(
			image.getAttribute('data-empirical-responsive-images-maximum-width')
		);
		var targetMaximumWidth = maximumWidth;

		if (Number.isFinite(imageMaximumWidth) && imageMaximumWidth > 0) {
			targetMaximumWidth = Math.min(targetMaximumWidth, Math.floor(imageMaximumWidth));
		}

		var targetWidth = Math.min(
			targetMaximumWidth,
			Math.ceil(renderedWidth * dpr)
		);
		var candidateWidths = getCandidateWidths(image, renderedWidth);
		var nativeWidth = coveringCandidateWidth(targetWidth, candidateWidths);
		var learnsLayout = 'asset' === targetKind || 'attachment' === targetKind;
		var selectedWidth = learnsLayout
			? getSelectedCandidateWidth(image, renderedWidth)
			: 0;
		var imageRequiredFormats = requiredFormats;

		if (
			(typeof image.hasAttribute === 'function'
				&& image.hasAttribute('data-empirical-responsive-images-required-formats'))
			|| (
				typeof image.hasAttribute !== 'function'
				&& image.getAttribute('data-empirical-responsive-images-required-formats') !== null
			)
		) {
			imageRequiredFormats = parseRequiredFormats(
				image.getAttribute('data-empirical-responsive-images-required-formats')
			);
		}

		if (renderedWidth < minRenderedWidth || renderedHeight < 1 || targetWidth < 1) {
			return;
		}

		var requiredFormatsCovered = imageRequiredFormats.every(function (format) {
			return isTargetCovered(
				targetWidth,
				getFormatCandidateWidths(image, renderedWidth, format)
			);
		});

		if (
			!isTargetCovered(targetWidth, candidateWidths)
			|| !requiredFormatsCovered
			|| (selectedWidth > 0 && !isTargetCovered(targetWidth, [selectedWidth]))
		) {
			queueGap(
				target,
				targetWidth,
				targetMaximumWidth,
				nativeWidth,
				learnsLayout ? Number(window.innerWidth || 0) : 0,
				learnsLayout ? renderedWidth : 0
			);
		}
	}

	function evictOldestWatchedImage() {
		var image = watchedImageQueue.shift();

		if (!image) {
			return;
		}

		watchedImages.delete(image);

		if (resizeObserver && typeof resizeObserver.unobserve === 'function') {
			resizeObserver.unobserve(image);
		}
	}

	function watchImage(image) {
		if (
			!image
			|| !image.getAttribute
			|| !image.getAttribute('data-empirical-responsive-images-target')
			|| watchedImages.has(image)
		) {
			return;
		}

		while (watchedImageQueue.length >= maximumImages) {
			evictOldestWatchedImage();
		}

		watchedImages.add(image);
		watchedImageQueue.push(image);

		if (resizeObserver) {
			resizeObserver.observe(image);
		}

		image.addEventListener('load', function () {
			if (watchedImages.has(image)) {
				inspectImage(image);
			}
		}, { once: true });

		if (image.complete && watchedImages.has(image)) {
			inspectImage(image);
		}
	}

	function scanImages(reinspectExisting) {
		var signedImages = Array.from(document.querySelectorAll('img')).filter(function (image) {
			return image.getAttribute && image.getAttribute('data-empirical-responsive-images-target');
		});

		if (reinspectExisting) {
			signedImages.forEach(function (image) {
				if (!watchedImages.has(image)) {
					return;
				}

				inspectImage(image);
			});
		}

		var remainingImageBudget = maximumImages - watchedImageQueue.length;
		var unwatchedImages = signedImages.filter(function (image) {
			return !watchedImages.has(image);
		});

		if (remainingImageBudget < 1 || !unwatchedImages.length) {
			return;
		}

		if (unwatchedImages.length > remainingImageBudget) {
			var offset = Math.floor(Math.random() * unwatchedImages.length);

			unwatchedImages = unwatchedImages.slice(offset).concat(unwatchedImages.slice(0, offset));
		}

		unwatchedImages.slice(0, remainingImageBudget).forEach(function (image) {
			watchImage(image);
		});
	}

	function runScanWhenIdle(reinspectExisting) {
		if ('requestIdleCallback' in window) {
			window.requestIdleCallback(function () {
				scanImages(reinspectExisting);
			}, { timeout: 1200 });
			return;
		}

		scanImages(reinspectExisting);
	}

	function scheduleScan() {
		window.clearTimeout(scanTimer);
		scanTimer = window.setTimeout(function () {
			runScanWhenIdle(true);
		}, 350);
	}

	function watchAddedImages(mutations) {
		mutations.forEach(function (mutation) {
			Array.from(mutation.addedNodes || []).forEach(function (node) {
				if (!node || node.nodeType !== 1) {
					return;
				}

				if (node.matches && node.matches('img')) {
					watchImage(node);
				}

				if (node.querySelectorAll) {
					Array.from(node.querySelectorAll('img')).forEach(watchImage);
				}
			});
		});
	}

	function flush() {
		var remainingGapBudget = maximumReportedGaps - reportedGapCount;
		var gaps = Array.from(pendingGaps.entries()).sort(function (a, b) {
			if (a[1].target === b[1].target) {
				return a[1].width - b[1].width;
			}

			return a[1].target < b[1].target ? -1 : 1;
		}).slice(0, Math.min(maximumGaps, remainingGapBudget));

		flushTimer = null;

		if (!gaps.length || remainingGapBudget < 1) {
			return;
		}

		gaps.forEach(function (gap) {
			pendingGaps.delete(gap[0]);
			reportedGaps.add(gap[0]);
		});
		reportedGapCount += gaps.length;

		window.fetch(endpoint, {
			method: 'POST',
			body: JSON.stringify({
				gaps: gaps.map(function (gap) {
					return gap[1];
				})
			}),
			headers: {
				'Content-Type': 'application/json',
				'X-Empirical-Responsive-Images-Token': token,
				'X-Empirical-Responsive-Images-Bucket-Version': String(bucketProtocolVersion)
			},
			credentials: 'omit',
			referrerPolicy: 'no-referrer',
			keepalive: true
		}).catch(function () {});

		if (pendingGaps.size && reportedGapCount < maximumReportedGaps) {
			scheduleFlush();
		}
	}

	if ('ResizeObserver' in window) {
		resizeObserver = new ResizeObserver(function (entries) {
			entries.slice(0, maximumImages).forEach(function (entry) {
				if (watchedImages.has(entry.target)) {
					inspectImage(entry.target);
				}
			});
		});
	}

	if ('MutationObserver' in window) {
		new MutationObserver(watchAddedImages).observe(document.documentElement, {
			childList: true,
			subtree: true
		});
	}

	window.addEventListener('resize', scheduleScan, { passive: true });
	window.addEventListener('load', function () {
		runScanWhenIdle(false);
	}, { once: true });

	if (document.readyState === 'interactive' || document.readyState === 'complete') {
		runScanWhenIdle(false);
	} else {
		document.addEventListener('DOMContentLoaded', function () {
			runScanWhenIdle(false);
		}, { once: true });
	}
}());
