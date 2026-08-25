(function () {
	'use strict';

	const boot = window.FPCW_PRODUCT_ADMIN;
	const templateSelect = document.getElementById('_fpcw_template_id');
	const colorRoot = document.getElementById('fpcw-product-colors');
	const surfaceRoot = document.getElementById('fpcw-product-surfaces');
	const colorField = document.getElementById('_fpcw_allowed_colors');
	const surfaceField = document.getElementById('_fpcw_surface_settings');
	if (!boot || !templateSelect || !colorRoot || !surfaceRoot || !colorField || !surfaceField) return;
	const priceDecimals = Math.max(0, Math.min(6, Number.parseInt(boot.priceDecimals, 10) || 0));
	const priceStep = priceDecimals ? (1 / (10 ** priceDecimals)).toFixed(priceDecimals) : '1';

	let selectedColors = parseArray(colorField.value);
	let surfaceSettings = parseObject(surfaceField.value);

	function parseArray(value) {
		try {
			const parsed = JSON.parse(value);
			return Array.isArray(parsed) ? parsed.map(String) : [];
		} catch (error) {
			return [];
		}
	}

	function parseObject(value) {
		try {
			const parsed = JSON.parse(value);
			return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
		} catch (error) {
			return {};
		}
	}

	function esc(value) {
		return String(value == null ? '' : value).replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[character]);
	}

	function template() {
		return boot.templates[String(templateSelect.value)] || null;
	}

	function normalize(reset) {
		const current = template();
		if (!current) {
			selectedColors = [];
			surfaceSettings = {};
			return;
		}

		const validColors = current.colors.map((item) => item.id);
		selectedColors = reset ? validColors : selectedColors.filter((id) => validColors.includes(id));
		if (!selectedColors.length && validColors.length) selectedColors = [validColors[0]];

		const nextSurfaces = {};
		current.surfaces.forEach((item) => {
			const existing = !reset && surfaceSettings[item.id] ? surfaceSettings[item.id] : null;
			nextSurfaces[item.id] = {
				enabled: existing ? Boolean(existing.enabled) : true,
				price: existing ? Math.max(0, Number(existing.price) || 0) : 0,
			};
		});
		if (current.surfaces.length && !Object.values(nextSurfaces).some((item) => item.enabled)) {
			nextSurfaces[current.surfaces[0].id].enabled = true;
		}
		surfaceSettings = nextSurfaces;
	}

	function sync() {
		colorField.value = JSON.stringify(selectedColors);
		surfaceField.value = JSON.stringify(surfaceSettings);
	}

	function render(reset) {
		normalize(reset);
		const current = template();
		if (!current) {
			colorRoot.innerHTML = `<p class="fpcw-empty-state">${esc(boot.i18n.chooseTemplate)}</p>`;
			surfaceRoot.innerHTML = `<p class="fpcw-empty-state">${esc(boot.i18n.chooseTemplate)}</p>`;
			sync();
			return;
		}

		if (!current.colors.length) {
			colorRoot.innerHTML = `<p class="fpcw-empty-state">${esc(boot.i18n.noColors)}</p>`;
		} else {
			const allSelected = current.colors.every((item) => selectedColors.includes(item.id));
			colorRoot.innerHTML = `
				<label class="fpcw-derived-all"><input type="checkbox" data-color-all ${allSelected ? 'checked' : ''}> ${esc(boot.i18n.selectAll)}</label>
				<div class="fpcw-derived-colors">
					${current.colors.map((item) => `<label class="fpcw-derived-color"><input type="checkbox" data-color-id="${esc(item.id)}" ${selectedColors.includes(item.id) ? 'checked' : ''}><span style="--fpcw-admin-swatch:${esc(item.hex || '#ffffff')}"></span>${esc(item.label)}</label>`).join('')}
				</div>`;
		}

		if (!current.surfaces.length) {
			surfaceRoot.innerHTML = `<p class="fpcw-empty-state">${esc(boot.i18n.noSurfaces)}</p>`;
		} else {
			const allSelected = current.surfaces.every((item) => surfaceSettings[item.id] && surfaceSettings[item.id].enabled);
			surfaceRoot.innerHTML = `
				<label class="fpcw-derived-all"><input type="checkbox" data-surface-all ${allSelected ? 'checked' : ''}> ${esc(boot.i18n.selectAll)}</label>
				<div class="fpcw-derived-surfaces">
					${current.surfaces.map((item) => {
						const settings = surfaceSettings[item.id];
						const availability = boot.i18n.availableFor.replace('%s', (item.attributes || []).join(', '));
						return `<div class="fpcw-derived-surface"><div class="fpcw-surface-identity"><label><input type="checkbox" data-surface-id="${esc(item.id)}" ${settings.enabled ? 'checked' : ''}> <strong>${esc(item.label)}</strong></label><small>${esc(availability)}</small></div><label class="fpcw-surface-price"><span>${esc(boot.i18n.priceIncrement)}: <strong>${esc(item.label)}</strong></span><span class="fpcw-price-input"><span>${esc(boot.currencySymbol)}</span><input type="number" min="0" step="${priceStep}" data-surface-price="${esc(item.id)}" value="${esc(Number(settings.price).toFixed(priceDecimals))}" ${settings.enabled ? '' : 'disabled'}></span></label></div>`;
					}).join('')}
				</div>
				<p class="description">${esc(boot.i18n.baseSurface)}</p>`;
		}
		sync();
	}

	function keepOneColor(input) {
		const checked = colorRoot.querySelectorAll('[data-color-id]:checked');
		if (!checked.length) input.checked = true;
		selectedColors = Array.from(colorRoot.querySelectorAll('[data-color-id]:checked')).map((item) => item.dataset.colorId);
	}

	function keepOneSurface(input) {
		const checked = surfaceRoot.querySelectorAll('[data-surface-id]:checked');
		if (!checked.length) input.checked = true;
		surfaceRoot.querySelectorAll('[data-surface-id]').forEach((item) => {
			const id = item.dataset.surfaceId;
			surfaceSettings[id].enabled = item.checked;
			const price = surfaceRoot.querySelector(`[data-surface-price="${id}"]`);
			if (price) price.disabled = !item.checked;
		});
	}

	templateSelect.addEventListener('change', () => render(true));
	colorRoot.addEventListener('change', (event) => {
		const input = event.target;
		if (input.matches('[data-color-all]')) {
			colorRoot.querySelectorAll('[data-color-id]').forEach((item) => { item.checked = input.checked; });
			if (!input.checked) {
				const first = colorRoot.querySelector('[data-color-id]');
				if (first) first.checked = true;
			}
		}
		if (input.matches('[data-color-id], [data-color-all]')) {
			keepOneColor(input);
			const all = colorRoot.querySelector('[data-color-all]');
			if (all) all.checked = colorRoot.querySelectorAll('[data-color-id]').length === colorRoot.querySelectorAll('[data-color-id]:checked').length;
			sync();
		}
	});

	surfaceRoot.addEventListener('change', (event) => {
		const input = event.target;
		if (input.matches('[data-surface-all]')) {
			surfaceRoot.querySelectorAll('[data-surface-id]').forEach((item) => { item.checked = input.checked; });
			if (!input.checked) {
				const first = surfaceRoot.querySelector('[data-surface-id]');
				if (first) first.checked = true;
			}
		}
		if (input.matches('[data-surface-id], [data-surface-all]')) {
			keepOneSurface(input);
			const all = surfaceRoot.querySelector('[data-surface-all]');
			if (all) all.checked = surfaceRoot.querySelectorAll('[data-surface-id]').length === surfaceRoot.querySelectorAll('[data-surface-id]:checked').length;
			sync();
		}
	});

	surfaceRoot.addEventListener('input', (event) => {
		const input = event.target;
		if (!input.matches('[data-surface-price]')) return;
		const id = input.dataset.surfacePrice;
		if (surfaceSettings[id]) surfaceSettings[id].price = Math.max(0, Number(input.value) || 0);
		sync();
	});

	const form = templateSelect.closest('form');
	if (form) form.addEventListener('submit', sync);
	render(false);
})();
