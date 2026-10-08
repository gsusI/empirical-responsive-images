'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(
	path.join(__dirname, '../../assets/js/picture-source-sync.js'),
	'utf8'
);
const CHILD_ATTRIBUTE = 'data-empirical-responsive-images-picture-child';
const PICTURE_ATTRIBUTE = 'data-empirical-responsive-images-picture';
const PARKED_ATTRIBUTE = 'data-empirical-responsive-images-parked-srcset';
const AVIF_SRCSET = 'https://example.test/uploads/original-650.avif 650w';
const WEBP_SRCSET = 'https://example.test/uploads/original-650.webp 650w';

function makeElement(nodeName, attributes = {}) {
	return {
		nodeName,
		attributes: { ...attributes },
		children: [],
		parentNode: null,
		getAttribute(name) {
			return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
		},
		setAttribute(name, value) {
			this.attributes[name] = String(value);
		},
		hasAttribute(name) {
			return Object.prototype.hasOwnProperty.call(this.attributes, name);
		},
		removeAttribute(name) {
			delete this.attributes[name];
		},
		appendChild(child) {
			child.parentNode = this;
			this.children.push(child);
			return child;
		}
	};
}

function makePicture(imageAttributes = {}, pluginOwned = true) {
	const picture = makeElement('PICTURE', pluginOwned ? { [PICTURE_ATTRIBUTE]: '1' } : {});
	const avif = picture.appendChild(makeElement('SOURCE', { type: 'image/avif', srcset: AVIF_SRCSET }));
	const webp = picture.appendChild(makeElement('SOURCE', { type: 'image/webp', srcset: WEBP_SRCSET }));
	const image = picture.appendChild(makeElement('IMG', {
		[CHILD_ATTRIBUTE]: '1',
		src: 'https://example.test/uploads/original.jpg',
		srcset: 'https://example.test/uploads/original-650.jpg 650w',
		...imageAttributes
	}));

	return { picture, image, sources: [avif, webp] };
}

function boot(initialImages, options = {}) {
	let callback = null;
	let observed = null;
	const context = {
		document: {
			documentElement: makeElement('HTML'),
			querySelectorAll: (selector) => {
				assert.equal(selector, `img[${CHILD_ATTRIBUTE}]`);
				return initialImages;
			}
		}
	};

	if (options.mutationObserver !== false) {
		context.MutationObserver = function MutationObserver(handler) {
			callback = handler;
			this.observe = (target, config) => {
				observed = { target, config };
			};
		};
	}

	vm.runInNewContext(SOURCE, context);

	// Apply attribute changes like a browser, then deliver one batch of records.
	function mutate(changes) {
		const records = changes.map(([target, attributeName, value]) => {
			const oldValue = target.getAttribute(attributeName);
			if (null === value) {
				target.removeAttribute(attributeName);
			} else {
				target.setAttribute(attributeName, value);
			}
			return { target, attributeName, oldValue };
		});

		callback(records);
	}

	return { mutate, observed: () => observed, hasObserver: () => null !== callback };
}

function sourceState(sources) {
	return sources.map((source) => ({
		srcset: source.getAttribute('srcset'),
		parked: source.getAttribute(PARKED_ATTRIBUTE)
	}));
}

const ACTIVE = [
	{ srcset: AVIF_SRCSET, parked: null },
	{ srcset: WEBP_SRCSET, parked: null }
];
const PARKED = [
	{ srcset: null, parked: AVIF_SRCSET },
	{ srcset: null, parked: WEBP_SRCSET }
];

test('observes src and srcset changes with old values across the document', () => {
	const runtime = boot([]);
	const observed = runtime.observed();

	assert.equal(observed.config.attributeOldValue, true);
	assert.equal(observed.config.subtree, true);
	assert.deepEqual(Array.from(observed.config.attributeFilter), ['src', 'srcset']);
});

test('parks sources when a script swaps the image and restores them when it returns', () => {
	const { image, sources } = makePicture();
	const runtime = boot([image]);

	runtime.mutate([
		[image, 'src', 'https://example.test/uploads/other.webp'],
		[image, 'srcset', null]
	]);
	assert.deepEqual(sourceState(sources), PARKED);

	runtime.mutate([[image, 'src', 'https://example.test/uploads/third.webp']]);
	assert.deepEqual(sourceState(sources), PARKED);

	runtime.mutate([
		[image, 'src', 'https://example.test/uploads/original.jpg'],
		[image, 'srcset', 'https://example.test/uploads/original-650.jpg 650w']
	]);
	assert.deepEqual(sourceState(sources), ACTIVE);
});

test('parks sources when only the image srcset changes', () => {
	const { image, sources } = makePicture();
	const runtime = boot([image]);

	runtime.mutate([[image, 'srcset', 'https://example.test/uploads/other-650.jpg 650w']]);

	assert.deepEqual(sourceState(sources), PARKED);
});

test('leaves lazy-loaded images alone when the real image replaces a placeholder', () => {
	const { image, sources } = makePicture({ src: 'data:image/svg+xml,%3Csvg%3E', srcset: '' });
	const runtime = boot([image]);

	runtime.mutate([[image, 'srcset', 'https://example.test/uploads/original-650.jpg 650w']]);
	runtime.mutate([[image, 'src', 'https://example.test/uploads/original.jpg']]);
	assert.deepEqual(sourceState(sources), ACTIVE);

	runtime.mutate([[image, 'src', 'https://example.test/uploads/other.webp']]);
	assert.deepEqual(sourceState(sources), PARKED);
});

test('rebuilds the original state of images inserted after start-up', () => {
	const { image, sources } = makePicture();
	const runtime = boot([]);

	runtime.mutate([
		[image, 'src', 'https://example.test/uploads/other.webp'],
		[image, 'srcset', 'https://example.test/uploads/other-650.webp 650w']
	]);
	assert.deepEqual(sourceState(sources), PARKED);

	runtime.mutate([
		[image, 'src', 'https://example.test/uploads/original.jpg'],
		[image, 'srcset', 'https://example.test/uploads/original-650.jpg 650w']
	]);
	assert.deepEqual(sourceState(sources), ACTIVE);
});

test('ignores pictures and images the plugin did not create', () => {
	const foreign = makePicture({}, false);
	const unmarked = makePicture();
	unmarked.image.removeAttribute(CHILD_ATTRIBUTE);
	const runtime = boot([foreign.image]);

	runtime.mutate([
		[foreign.image, 'src', 'https://example.test/uploads/other.webp'],
		[unmarked.image, 'src', 'https://example.test/uploads/other.webp']
	]);

	assert.deepEqual(sourceState(foreign.sources), ACTIVE);
	assert.deepEqual(sourceState(unmarked.sources), ACTIVE);
});

test('does nothing in browsers without MutationObserver', () => {
	const { image } = makePicture();
	const runtime = boot([image], { mutationObserver: false });

	assert.equal(runtime.hasObserver(), false);
});
