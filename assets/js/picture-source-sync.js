(function () {
	'use strict';

	// A plugin <picture> lists AVIF/WebP sources for the image it wrapped. When
	// a gallery, slider or variation script swaps the fallback img, those
	// sources still win and the browser keeps showing the old image. Park the
	// source srcsets while the img shows something else, and bring them back
	// when it returns to the image they were generated for.
	var CHILD_ATTRIBUTE = 'data-empirical-responsive-images-picture-child';
	var PICTURE_ATTRIBUTE = 'data-empirical-responsive-images-picture';
	var PARKED_ATTRIBUTE = 'data-empirical-responsive-images-parked-srcset';

	if (typeof MutationObserver !== 'function' || typeof WeakMap !== 'function' || !document.documentElement) {
		return;
	}

	var originals = new WeakMap();

	function readState(image) {
		return {
			src: image.getAttribute('src') || '',
			srcset: image.getAttribute('srcset') || ''
		};
	}

	// Lazy loaders start from an empty or data: placeholder, and the sources
	// still describe the real image they fill in afterwards.
	function isPlaceholder(value) {
		return !value || /^data:/i.test(value);
	}

	function isPluginChild(image) {
		var picture = image && image.parentNode;

		return Boolean(
			image &&
			image.nodeName === 'IMG' &&
			image.hasAttribute(CHILD_ATTRIBUTE) &&
			picture &&
			picture.nodeName === 'PICTURE' &&
			picture.hasAttribute(PICTURE_ATTRIBUTE)
		);
	}

	function setSourcesActive(image, active) {
		var children = image.parentNode.children || [];

		for (var index = 0; index < children.length; index++) {
			var source = children[index];

			if (source.nodeName !== 'SOURCE') {
				continue;
			}

			if (active) {
				if (source.hasAttribute(PARKED_ATTRIBUTE)) {
					source.setAttribute('srcset', source.getAttribute(PARKED_ATTRIBUTE));
					source.removeAttribute(PARKED_ATTRIBUTE);
				}
			} else if (source.hasAttribute('srcset')) {
				source.setAttribute(PARKED_ATTRIBUTE, source.getAttribute('srcset'));
				source.removeAttribute('srcset');
			}
		}
	}

	function sync(image) {
		var original = originals.get(image);
		var current = readState(image);

		if (isPlaceholder(original.src)) {
			originals.set(image, current);
			return;
		}

		setSourcesActive(image, current.src === original.src && current.srcset === original.srcset);
	}

	function handleMutations(records) {
		var touched = [];

		for (var index = 0; index < records.length; index++) {
			var record = records[index];
			var image = record.target;

			if (!isPluginChild(image)) {
				continue;
			}

			if (!originals.has(image)) {
				// Images inserted after start-up: rebuild the pre-change state
				// from the first old value of each attribute in this batch.
				var original = { src: undefined, srcset: undefined };

				for (var later = index; later < records.length; later++) {
					var candidate = records[later];

					if (candidate.target === image && undefined === original[candidate.attributeName]) {
						original[candidate.attributeName] = candidate.oldValue || '';
					}
				}

				var current = readState(image);
				originals.set(image, {
					src: undefined === original.src ? current.src : original.src,
					srcset: undefined === original.srcset ? current.srcset : original.srcset
				});
			}

			if (touched.indexOf(image) === -1) {
				touched.push(image);
			}
		}

		touched.forEach(sync);
	}

	var images = document.querySelectorAll('img[' + CHILD_ATTRIBUTE + ']');

	for (var index = 0; index < images.length; index++) {
		if (isPluginChild(images[index])) {
			originals.set(images[index], readState(images[index]));
		}
	}

	new MutationObserver(handleMutations).observe(document.documentElement, {
		attributes: true,
		attributeFilter: ['src', 'srcset'],
		attributeOldValue: true,
		subtree: true
	});
})();
