(function () {
	'use strict';

	const boot = window.FPCW_FONT_ADMIN;
	const root = document.getElementById('fpcw-font-library');
	const field = document.getElementById('fpcw-font-library-value');
	const addButton = document.getElementById('fpcw-add-fonts');
	if (!boot || !root || !field || !addButton || !window.wp || !wp.media) return;

	let fonts = Array.isArray(boot.fonts) ? boot.fonts.map((font) => ({ id: Number(font.id), family: String(font.family || ''), file: String(font.file || '') })) : [];
	const extensions = ['woff2', 'woff', 'ttf', 'otf'];

	function esc(value) {
		return String(value == null ? '' : value).replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[character]);
	}

	function sync() {
		field.value = JSON.stringify(fonts);
	}

	function render() {
		root.innerHTML = fonts.map((font, index) => `<div class="fpcw-font-row"><input type="text" value="${esc(font.family)}" data-font-family="${index}" aria-label="Font family"><code>${esc(font.file || '')}</code><button type="button" class="button-link-delete" data-remove-font="${index}">${esc(boot.i18n.remove)}</button></div>`).join('');
		sync();
	}

	root.addEventListener('input', (event) => {
		if (event.target.dataset.fontFamily == null) return;
		fonts[Number(event.target.dataset.fontFamily)].family = event.target.value.trim();
		sync();
	});

	root.addEventListener('click', (event) => {
		const button = event.target.closest('[data-remove-font]');
		if (!button) return;
		fonts.splice(Number(button.dataset.removeFont), 1);
		render();
	});

	addButton.addEventListener('click', () => {
		const frame = wp.media({ title: boot.i18n.chooseFonts, button: { text: boot.i18n.useFonts }, multiple: true });
		frame.on('select', () => {
			let unsupported = false;
			frame.state().get('selection').each((item) => {
				const attachment = item.toJSON();
				const filename = String(attachment.filename || attachment.url || '');
				const extension = filename.split('.').pop().toLowerCase();
				if (!extensions.includes(extension)) {
					unsupported = true;
					return;
				}
				if (fonts.some((font) => font.id === Number(attachment.id))) return;
				fonts.push({ id: Number(attachment.id), family: String(attachment.title || filename.replace(/\.[^.]+$/, '')), file: filename });
			});
			if (unsupported) window.alert(boot.i18n.unsupported);
			render();
		});
		frame.open();
	});

	render();
})();
