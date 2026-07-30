'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const REQUIRED_FORMATS_ATTRIBUTE = 'data-empirical-responsive-images-required-formats';
const MAXIMUM_WIDTH_ATTRIBUTE = 'data-empirical-responsive-images-maximum-width';
const TARGET_KIND_ATTRIBUTE = 'data-empirical-responsive-images-target-kind';
const BUCKET_PROTOCOL_VERSION = 2;
const CANONICAL_WIDTHS = [
	1, 2, 3, 4, 6, 8, 10, 13, 16, 20, 25, 31, 38,
	46, 56, 69, 85, 104, 128, 157, 193, 236, 289, 354,
	434, 531, 650, 796, 975, 1194, 1462, 1790, 2192,
	2684, 3286, 4024, 4928, 5120
];

function loadHelpers() {
	const hook = {};
	const source = fs.readFileSync(
		path.join(__dirname, '../../assets/js/visitor-gap-observer.js'),
		'utf8'
	);
	const context = {
		document: {
			baseURI: 'https://example.test/products/example/',
			currentScript: null,
			querySelector: () => null
		},
		URL,
		window: {
			EmpiricalResponsiveImagesVisitorGapObserver: {},
			EmpiricalResponsiveImagesVisitorGapTestHook: hook
		}
	};

	vm.runInNewContext(source, context);

	return hook;
}

function makeStaticImage(definition = {}) {
	const target = Object.prototype.hasOwnProperty.call(definition, 'target')
		? definition.target
		: 'signed-target';
	const source = definition.src || 'https://example.test/uploads/image.jpg';
	const srcset = definition.srcset || '';
	const width = definition.width || 640;
	const eventHandlers = {};
	const hasRequiredFormats = Object.prototype.hasOwnProperty.call(definition, 'requiredFormats');
	const hasMaximumWidth = Object.prototype.hasOwnProperty.call(definition, 'maximumWidth');

	return {
		complete: definition.complete !== false,
		currentSrc: source,
		nodeName: 'IMG',
		nodeType: 1,
		parentElement: null,
		src: source,
		addEventListener: (name, callback) => {
			eventHandlers[name] = callback;
		},
		getAttribute: (name) => {
			if (name === 'srcset') {
				return srcset;
			}

			if (name === 'src') {
				return source;
			}

			if (name === 'data-empirical-responsive-images-target') {
				return target;
			}

			if (name === REQUIRED_FORMATS_ATTRIBUTE) {
				return hasRequiredFormats ? JSON.stringify(definition.requiredFormats) : null;
			}

			if (name === MAXIMUM_WIDTH_ATTRIBUTE) {
				return hasMaximumWidth ? String(definition.maximumWidth) : null;
			}

			if (name === TARGET_KIND_ATTRIBUTE) {
				return definition.targetKind || null;
			}

			return null;
		},
		hasAttribute: (name) => (
			name === 'data-empirical-responsive-images-target'
				? Boolean(target)
				: (
					(name === REQUIRED_FORMATS_ATTRIBUTE && hasRequiredFormats)
					|| (name === MAXIMUM_WIDTH_ATTRIBUTE && hasMaximumWidth)
				)
		),
		getBoundingClientRect: () => ({
			width,
			height: definition.height || 200
		}),
		matches: (selector) => selector === 'img',
		querySelectorAll: () => [],
		triggerLoad: () => {
			if (eventHandlers.load) {
				eventHandlers.load();
			}
		}
	};
}

function runDetector(srcset, renderedWidth, options = {}) {
	const requests = [];
	const eventHandlers = {};
	const imageEventHandlers = {};
	const timers = new Map();
	const randomValues = options.randomValues || [0];
	const controlledMath = Object.create(Math);
	const autoRunTimers = options.autoRunTimers !== false;
	let mutationCallback = null;
	let nextTimerId = 1;
	let randomIndex = 0;
	let currentRenderedWidth = renderedWidth;
	let currentSource = options.currentSrc || 'https://example.test/uploads/image.jpg';
	let fallbackSource = options.fallbackSrc || currentSource;
	let currentSrcset = srcset;
	const signedTarget = Object.prototype.hasOwnProperty.call(options, 'target') ? options.target : 'signed-target';
	const hasImageRequiredFormats = Object.prototype.hasOwnProperty.call(options, 'imageRequiredFormats');
	const hasImageMaximumWidth = Object.prototype.hasOwnProperty.call(options, 'imageMaximumWidth');
	const pictureSources = (options.pictureSrcsets || []).map((source) => ({
		getAttribute: (name) => {
			if (name === 'srcset') {
				return source.srcset;
			}

			if (name === 'media') {
				return source.media || '';
			}

			if (name === 'type') {
				return source.type || '';
			}

			return '';
		}
	}));
	const picture = pictureSources.length ? {
		nodeName: 'PICTURE',
		querySelectorAll: () => pictureSources
	} : null;
	const image = {
		complete: options.imageComplete !== false,
		get currentSrc() {
			return currentSource;
		},
		get src() {
			return fallbackSource;
		},
		parentElement: picture,
		getAttribute: (name) => {
			if (name === 'srcset') {
				return currentSrcset;
			}

			if (name === 'src') {
				return fallbackSource;
			}

			if (name === 'data-empirical-responsive-images-target') {
				return signedTarget;
			}

			if (name === REQUIRED_FORMATS_ATTRIBUTE) {
				return hasImageRequiredFormats ? JSON.stringify(options.imageRequiredFormats) : null;
			}

			if (name === MAXIMUM_WIDTH_ATTRIBUTE) {
				return hasImageMaximumWidth ? String(options.imageMaximumWidth) : null;
			}

			if (name === TARGET_KIND_ATTRIBUTE) {
				return options.targetKind || null;
			}

			return null;
		},
		hasAttribute: (name) => (
			name === 'data-empirical-responsive-images-target'
				? Boolean(signedTarget)
				: (
					(name === REQUIRED_FORMATS_ATTRIBUTE && hasImageRequiredFormats)
					|| (name === MAXIMUM_WIDTH_ATTRIBUTE && hasImageMaximumWidth)
				)
		),
		getBoundingClientRect: () => ({ width: currentRenderedWidth, height: 200 }),
		addEventListener: (name, callback) => {
			imageEventHandlers[name] = callback;
		}
	};
	const images = Array.isArray(options.images)
		? options.images.map(makeStaticImage)
		: [image];
	const source = fs.readFileSync(
		path.join(__dirname, '../../assets/js/visitor-gap-observer.js'),
		'utf8'
	);
	const MutationObserver = function (callback) {
		mutationCallback = callback;
	};

	MutationObserver.prototype.observe = () => {};
	controlledMath.random = () => {
		const value = randomValues[Math.min(randomIndex, randomValues.length - 1)];

		++randomIndex;

		return value;
	};

	const runNextTimer = () => {
		const nextTimer = timers.entries().next();

		if (nextTimer.done) {
			return false;
		}

		timers.delete(nextTimer.value[0]);
		nextTimer.value[1]();

		return true;
	};
	const runAllTimers = () => {
		let runs = 0;

		while (runNextTimer()) {
			++runs;

			if (runs > 1000) {
				throw new Error('Observer scheduled too many timers.');
			}
		}
	};
	const observerConfig = {
		endpoint: 'https://example.test/wp-json/visitor-gaps',
		token: 'public-token',
		sampleRate: 1,
		minRenderedWidth: 1,
		maximumWidth: options.maximumWidth || 2560,
		bucketVersion: BUCKET_PROTOCOL_VERSION,
		bucketWidths: CANONICAL_WIDTHS,
		maximumImages: options.maximumImages || 80,
		maximumGaps: options.maximumGaps || 12
	};

	if (Object.prototype.hasOwnProperty.call(options, 'requiredFormats')) {
		observerConfig.requiredFormats = options.requiredFormats;
	}

	const currentScript = Object.prototype.hasOwnProperty.call(options, 'datasetRequiredFormats')
		? {
			dataset: {
				empiricalResponsiveImagesVisitorGapRequiredFormats: options.datasetRequiredFormats
			}
		}
		: null;
	const context = {
		document: {
			baseURI: 'https://example.test/products/example/',
			currentScript,
			documentElement: {},
			readyState: 'complete',
			querySelector: () => null,
			querySelectorAll: () => images,
			addEventListener: () => {}
		},
		Math: controlledMath,
		MutationObserver,
		window: {
			EmpiricalResponsiveImagesVisitorGapObserver: observerConfig,
			MutationObserver,
			addEventListener: (name, callback) => {
				eventHandlers[name] = callback;
			},
			clearTimeout: (timerId) => {
				timers.delete(timerId);
			},
			devicePixelRatio: options.devicePixelRatio || 1,
			innerWidth: options.viewportWidth || 1440,
			fetch: (url, options) => {
				requests.push({ url, options });
				return Promise.resolve();
			},
			matchMedia: (media) => ({
				matches: options.matchMedia ? options.matchMedia(media) : true
			}),
			requestIdleCallback: (callback) => callback(),
			setTimeout: (callback) => {
				const timerId = nextTimerId;

				++nextTimerId;
				timers.set(timerId, callback);

				return timerId;
			}
		},
		URL
	};

	vm.runInNewContext(source, context);

	if (autoRunTimers) {
		runAllTimers();
	}

	requests.setRenderedWidth = (width) => {
		currentRenderedWidth = width;
	};
	requests.setImageSource = (source, nextSrcset) => {
		currentSource = source;
		fallbackSource = source;
		currentSrcset = nextSrcset;
	};
	requests.trigger = (name) => {
		if (eventHandlers[name]) {
			eventHandlers[name]();
		}

		if (autoRunTimers) {
			runAllTimers();
		}
	};
	requests.triggerImageLoad = () => {
		if (imageEventHandlers.load) {
			imageEventHandlers.load();
		}

		if (autoRunTimers) {
			runAllTimers();
		}
	};
	requests.addImage = (definition) => {
		const addedImage = makeStaticImage(definition);

		images.push(addedImage);

		if (mutationCallback) {
			mutationCallback([{ addedNodes: [addedImage] }]);
		}

		if (autoRunTimers) {
			runAllTimers();
		}

		return addedImage;
	};
	requests.runNextTimer = runNextTimer;
	requests.runAllTimers = runAllTimers;

	return requests;
}

test('parses and deduplicates width descriptors', () => {
	const helpers = loadHelpers();

	assert.deepEqual(
		Array.from(helpers.parseSrcsetWidths('image-640.jpg 640w, image-320.jpg 320w, duplicate.jpg 640w')),
		[320, 640]
	);

	assert.deepEqual(
		Array.from(helpers.parseSrcsetWidths('image.png 1x, image@2x.png 2x', 200)),
		[200, 400]
	);
});

test('reads the physical width of the browser-selected candidate', () => {
	const helpers = loadHelpers();

	assert.equal(
		helpers.selectedCandidateWidth(
			'small.jpg 448w, original.jpg 2000w',
			'https://example.test/products/example/original.jpg',
			200
		),
		2000
	);
});

test('accepts close candidates and reports material gaps', () => {
	const helpers = loadHelpers();

	assert.equal(helpers.isTargetCovered(640, [320, 640, 768]), true);
	assert.equal(helpers.isTargetCovered(641, [320, 640]), true);
	assert.equal(helpers.isTargetCovered(650, [320, 640, 768]), true);
	assert.equal(helpers.isTargetCovered(700, [320, 640]), false);
	assert.equal(helpers.isTargetCovered(660, [320, 640, 1024]), false);
	assert.equal(helpers.isTargetCovered(660, [320, 640]), false);
	assert.equal(helpers.isTargetCovered(4, [5]), false);
	assert.equal(helpers.isTargetCovered(58, [56]), false);
});

test('strict canonical palette covers every physical width with exact integer bounds', () => {
	const helpers = loadHelpers();
	const observed = new Set();

	for (let target = 1; target <= 5120; ++target) {
		const bucket = helpers.bucketWidth(target, CANONICAL_WIDTHS, 5120);

		assert.ok((50 * bucket) >= (49 * target), `${bucket} is too small for ${target}`);
		assert.ok((5 * bucket) <= (6 * target), `${bucket} is too large for ${target}`);
		assert.equal(
			helpers.bucketWidth(bucket, CANONICAL_WIDTHS, 5120),
			bucket,
			`bucket ${bucket} must be idempotent`
		);
		observed.add(bucket);
	}

	assert.equal(observed.size, 38);
	assert.deepEqual(Array.from(observed), CANONICAL_WIDTHS);
});

test('small and one-pixel boundary widths use deterministic canonical buckets', () => {
	const helpers = loadHelpers();

	assert.equal(helpers.bucketWidth(1, CANONICAL_WIDTHS, 5120), 1);
	assert.equal(helpers.bucketWidth(4, CANONICAL_WIDTHS, 5120), 4);
	assert.equal(helpers.bucketWidth(5, CANONICAL_WIDTHS, 5120), 6);
	assert.equal(helpers.bucketWidth(7, CANONICAL_WIDTHS, 5120), 8);
	assert.equal(helpers.bucketWidth(57, CANONICAL_WIDTHS, 5120), 56);
	assert.equal(helpers.bucketWidth(58, CANONICAL_WIDTHS, 5120), 69);
	assert.equal(helpers.bucketWidth(641, CANONICAL_WIDTHS, 2560), 650);
	assert.equal(helpers.bucketWidth(642, CANONICAL_WIDTHS, 2560), 650);
	assert.equal(helpers.bucketWidth(5028, CANONICAL_WIDTHS, 5120), 4928);
	assert.equal(helpers.bucketWidth(5029, CANONICAL_WIDTHS, 5120), 5120);
	assert.equal(helpers.bucketWidth(99999, CANONICAL_WIDTHS, 2560), 2560);
	assert.equal(helpers.bucketWidth(32, CANONICAL_WIDTHS, 32), 32);
});

test('covered page performs no visitor request', () => {
	const requests = runDetector('image-640.jpg 640w, image-768.jpg 768w', 640);

	assert.equal(requests.length, 0);
});

test('asset reports coarse slot evidence when wrong sizes select an oversized source', () => {
	const requests = runDetector('small.jpg 448w, original.jpg 2000w', 200, {
		currentSrc: 'https://example.test/products/example/original.jpg',
		devicePixelRatio: 2,
		targetKind: 'asset',
		viewportWidth: 1440
	});

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{
				target: 'signed-target',
				width: 434,
				native_width: 448,
				viewport: 1536,
				slot: 208
			}
		]
	});
});

test('attachment reports the same bounded layout evidence for relative CSS slots', () => {
	const requests = runDetector('small.jpg 462w, original.jpg 2000w', 200, {
		currentSrc: 'https://example.test/products/example/original.jpg',
		devicePixelRatio: 2,
		targetKind: 'attachment',
		viewportWidth: 1440
	});

	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{
				target: 'signed-target',
				width: 434,
				native_width: 462,
				viewport: 1536,
				slot: 208
			}
		]
	});
});

test('uncovered page sends one signed target gap without cookies or referrer', () => {
	const requests = runDetector('image-320.jpg 320w, image-1024.jpg 1024w', 640);

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-target', width: 650 }
		]
	});
	assert.equal(requests[0].options.credentials, 'omit');
	assert.equal(requests[0].options.referrerPolicy, 'no-referrer');
	assert.equal(requests[0].options.headers['X-Empirical-Responsive-Images-Token'], 'public-token');
	assert.equal(
		requests[0].options.headers['X-Empirical-Responsive-Images-Bucket-Version'],
		String(BUCKET_PROTOCOL_VERSION)
	);
});

test('same-site image without signed target performs no visitor request', () => {
	const requests = runDetector('image-320.jpg 320w', 640, { target: '' });

	assert.equal(requests.length, 0);
});

test('unsigned images do not consume the signed image budget', () => {
	const images = Array.from({ length: 80 }, () => ({
		srcset: 'unsigned-320.jpg 320w',
		target: '',
		width: 640
	}));

	images.push({
		srcset: 'signed-320.jpg 320w',
		target: 'signed-after-unsigned',
		width: 640
	});

	const requests = runDetector('', 640, {
		images,
		maximumImages: 1
	});

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-after-unsigned', width: 650 }
		]
	});
});

test('random rotation lets signed targets after the first 80 be sampled', () => {
	const images = Array.from({ length: 81 }, (_, index) => ({
		srcset: index === 80 ? 'image-320.jpg 320w' : 'image-640.jpg 640w',
		target: `signed-${String(index).padStart(3, '0')}`,
		width: 640
	}));
	const requests = runDetector('', 640, {
		images,
		maximumImages: 80,
		randomValues: [0, 0.99]
	});

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-080', width: 650 }
		]
	});
});

test('active picture source candidates prevent false gap reports', () => {
	const requests = runDetector('', 640, {
		currentSrc: 'https://example.test/products/example/image-640.webp',
		fallbackSrc: 'https://example.test/products/example/fallback.jpg',
		pictureSrcsets: [
			{ srcset: 'image-640.webp 640w, image-768.webp 768w' }
		]
	});

	assert.equal(requests.length, 0);
});

test('inactive picture sources cannot hide an active source gap', () => {
	const requests = runDetector('', 640, {
		currentSrc: 'https://example.test/products/example/active-320.webp',
		fallbackSrc: 'https://example.test/products/example/fallback.jpg',
		pictureSrcsets: [
			{ srcset: 'active-320.webp 320w' },
			{ srcset: 'inactive-768.jpg 768w' }
		]
	});

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-target', width: 650 }
		]
	});
});

test('native coverage reports when required WebP and AVIF sources are absent', () => {
	const requests = runDetector('image-640.jpg 640w', 640, {
		requiredFormats: ['webp', 'avif']
	});

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-target', width: 650, native_width: 640 }
		]
	});
});

test('covered WebP and AVIF picture sources satisfy format requirements', () => {
	const requests = runDetector('fallback-640.jpg 640w', 640, {
		currentSrc: 'https://example.test/products/example/image-640.webp',
		pictureSrcsets: [
			{
				srcset: 'image-640.webp 640w',
				type: 'image/webp'
			},
			{
				srcset: 'image-640.avif 640w',
				type: 'image/avif'
			}
		],
		requiredFormats: ['webp', 'avif']
	});

	assert.equal(requests.length, 0);
});

test('one missing required picture format reports a gap', () => {
	const requests = runDetector('fallback-640.jpg 640w', 640, {
		currentSrc: 'https://example.test/products/example/image-640.webp',
		pictureSrcsets: [
			{
				srcset: 'image-640.webp 640w',
				type: 'image/webp'
			}
		],
		requiredFormats: ['webp', 'avif']
	});

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-target', width: 650, native_width: 640 }
		]
	});
});

test('empty required formats retain native-only coverage', () => {
	const requests = runDetector('image-640.jpg 640w', 640, {
		requiredFormats: []
	});

	assert.equal(requests.length, 0);
});

test('per-image empty required formats override the global fallback', () => {
	const requests = runDetector('image-640.jpg 640w', 640, {
		requiredFormats: ['webp', 'avif'],
		imageRequiredFormats: []
	});

	assert.equal(requests.length, 0);
});

test('per-image required formats override an empty global fallback', () => {
	const requests = runDetector('image-640.jpg 640w', 640, {
		requiredFormats: [],
		imageRequiredFormats: ['webp']
	});

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-target', width: 650, native_width: 640 }
		]
	});
});

test('required formats fall back to the script dataset JSON', () => {
	const requests = runDetector('image-640.jpg 640w', 640, {
		datasetRequiredFormats: '["webp","avif"]'
	});

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-target', width: 650, native_width: 640 }
		]
	});
});

test('per-image source ceiling prevents impossible DPR gap reports', () => {
	const requests = runDetector('image-1024.jpg 1024w', 600, {
		devicePixelRatio: 2,
		imageMaximumWidth: 1024,
		maximumWidth: 2560
	});

	assert.equal(requests.length, 0);
});

test('per-image ceiling also caps the reported bucket', () => {
	const requests = runDetector('image-640.jpg 640w', 600, {
		devicePixelRatio: 2,
		imageMaximumWidth: 1000,
		maximumWidth: 2560
	});

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-target', width: 1000 }
		]
	});
});

test('global ceiling prevents impossible DPR gap reports', () => {
	const requests = runDetector('image-2560.jpg 2560w', 1400, {
		devicePixelRatio: 2,
		maximumWidth: 2560
	});

	assert.equal(requests.length, 0);
});

test('external images without a signed local target create no regeneration hints', () => {
	const requests = runDetector('', 640, {
		currentSrc: 'https://cdn.example.net/advert.jpg',
		target: ''
	});

	assert.equal(requests.length, 0);
});

test('signed attachment targets still learn when image URLs are CDN-hosted', () => {
	const requests = runDetector('https://cdn.example.net/image-320.jpg 320w', 640, {
		currentSrc: 'https://cdn.example.net/image-320.jpg'
	});

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-target', width: 650 }
		]
	});
});

test('resize rechecks watched images without ResizeObserver support', () => {
	const requests = runDetector('image-640.jpg 640w', 640);

	requests.setRenderedWidth(700);
	requests.trigger('resize');

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-target', width: 796 }
		]
	});
});

test('mutation-added signed images replace old entries in the bounded watcher queue', () => {
	const images = Array.from({ length: 80 }, (_, index) => ({
		srcset: 'image-640.jpg 640w',
		target: `signed-${String(index).padStart(3, '0')}`,
		width: 640
	}));
	const requests = runDetector('', 640, {
		images,
		maximumImages: 80
	});

	requests.addImage({
		srcset: 'late-320.jpg 320w',
		target: 'signed-after-capacity',
		width: 640
	});

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-after-capacity', width: 650 }
		]
	});
});

test('stale load callback does not inspect an evicted image', () => {
	const requests = runDetector('old-320.jpg 320w', 640, {
		imageComplete: false,
		maximumImages: 1
	});

	requests.addImage({
		srcset: 'replacement-640.jpg 640w',
		target: 'signed-replacement',
		width: 640
	});
	requests.triggerImageLoad();

	assert.equal(requests.length, 0);
});

test('later mutation gaps can report after two earlier requests', () => {
	const requests = runDetector('image-320.jpg 320w', 640);

	requests.addImage({
		srcset: 'second-320.jpg 320w',
		target: 'signed-second',
		width: 640
	});
	requests.addImage({
		srcset: 'third-320.jpg 320w',
		target: 'signed-third',
		width: 640
	});

	assert.equal(requests.length, 3);
	assert.deepEqual(
		requests.map((request) => JSON.parse(request.options.body).gaps[0].target),
		['signed-target', 'signed-second', 'signed-third']
	);
});

test('later resize gaps collapse into the finite canonical width ladder', () => {
	const requests = runDetector('image-1.jpg 1w', 64, {
		maximumWidth: 4096
	});

	for (let index = 2; index <= 49; ++index) {
		requests.setRenderedWidth(index * 64);
		requests.trigger('resize');
	}

	const widths = requests.flatMap((request) => (
		JSON.parse(request.options.body).gaps.map((gap) => gap.width)
	));

	assert.equal(requests.length, 16);
	assert.deepEqual(widths, [
		69, 128, 193, 289, 354, 434, 531, 650,
		796, 975, 1194, 1462, 1790, 2192, 2684, 3286
	]);
});

test('report budget sends at most 12 gaps per request and 48 total', () => {
	const images = Array.from({ length: 60 }, (_, index) => ({
		srcset: 'image-320.jpg 320w',
		target: `signed-${String(index).padStart(3, '0')}`,
		width: 640
	}));
	const requests = runDetector('', 640, {
		autoRunTimers: false,
		images
	});

	assert.equal(requests.length, 0);

	requests.runAllTimers();

	const requestGapCounts = requests.map((request) => (
		JSON.parse(request.options.body).gaps.length
	));

	assert.deepEqual(requestGapCounts, [12, 12, 12, 12]);
	assert.equal(requestGapCounts.reduce((total, count) => total + count, 0), 48);
});

test('completed lazy placeholder is rechecked when the real image loads', () => {
	const requests = runDetector('', 640, {
		currentSrc: 'data:image/gif;base64,placeholder'
	});

	requests.setImageSource(
		'https://example.test/uploads/real-image.jpg',
		'real-image-320.jpg 320w'
	);
	requests.triggerImageLoad();

	assert.equal(requests.length, 1);
	assert.deepEqual(JSON.parse(requests[0].options.body), {
		gaps: [
			{ target: 'signed-target', width: 650 }
		]
	});
});
