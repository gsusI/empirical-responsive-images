(function () {
	'use strict';

	var config = window.EmpiricalResponsiveImagesAdmin || {};
	var button = document.querySelector('.empirical-responsive-images-regenerate-control__button');
	var forceCheckbox = document.querySelector('.empirical-responsive-images-regenerate-control__force-checkbox');
	var progress = document.querySelector('.empirical-responsive-images-regenerate-control__progress');
	var status = document.querySelector('.empirical-responsive-images-regenerate-control__status');
	var generatedTotal = 0;
	var processedTotal = 0;
	var imageTotal = 0;

	if (!button || !progress || !status || !config.regenerateEndpoint) {
		return;
	}

	function format(template, values) {
		return String(template).replace(/%([0-9]+)\$d/g, function (match, index) {
			return values[Number(index) - 1] || 0;
		});
	}

	function setRunning(running) {
		button.disabled = running;
		if (forceCheckbox) {
			forceCheckbox.disabled = running;
		}
	}

	function requestBatch(page) {
		return window.fetch(config.regenerateEndpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || ''
			},
			body: JSON.stringify({
				page: page,
				per_page: Number(config.batchSize || 5),
				force: Boolean(forceCheckbox && forceCheckbox.checked)
			})
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('Request failed');
			}

			return response.json();
		});
	}

	function run(page) {
		requestBatch(page).then(function (result) {
			imageTotal = Number(result.total || imageTotal || 0);
			processedTotal = Number(result.done_count || processedTotal || 0);
			generatedTotal += Number(result.format_generated || 0);

			progress.max = imageTotal || 100;
			progress.value = imageTotal ? Math.min(processedTotal, imageTotal) : 0;
			status.textContent = format(config.i18n.running, [processedTotal, imageTotal, generatedTotal]);

			if (result.done) {
				status.textContent = format(config.i18n.done, [processedTotal, generatedTotal]);
				setRunning(false);
				return;
			}

			run(Number(result.next_page || page + 1));
		}).catch(function () {
			status.textContent = config.i18n.failed || 'Regeneration failed.';
			setRunning(false);
		});
	}

	button.addEventListener('click', function () {
		generatedTotal = 0;
		processedTotal = 0;
		imageTotal = 0;
		progress.value = 0;
		status.textContent = config.i18n.starting || 'Starting regeneration...';
		setRunning(true);
		run(1);
	});
}());
