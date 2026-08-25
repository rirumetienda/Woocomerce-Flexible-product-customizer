const fs = require('fs');
const { JSDOM } = require('jsdom');

const dom = new JSDOM('<!doctype html><form><div id="fpcw-font-library"></div><input id="fpcw-font-library-value"><button type="button" id="fpcw-add-fonts">Add</button></form>', { runScripts: 'outside-only' });
const { window } = dom;
window.FPCW_FONT_ADMIN = { fonts: [{ id: 9, family: 'Brand Sans', file: 'brand.woff2' }], i18n: { remove: 'Remove', chooseFonts: 'Choose', useFonts: 'Use', unsupported: 'Unsupported' } };
window.wp = { media() { return { on() {}, open() {} }; } };

window.eval(fs.readFileSync('flexible-product-customizer/assets/js/admin-fonts.js', 'utf8'));
const family = window.document.querySelector('[data-font-family="0"]');
if (!family) throw new Error('Stored custom font was not rendered.');
family.value = 'Updated Sans';
family.dispatchEvent(new window.Event('input', { bubbles: true }));
let saved = JSON.parse(window.document.getElementById('fpcw-font-library-value').value);
if (saved[0].family !== 'Updated Sans' || saved[0].id !== 9) throw new Error('Custom font settings were not serialized.');
window.document.querySelector('[data-remove-font="0"]').click();
saved = JSON.parse(window.document.getElementById('fpcw-font-library-value').value);
if (saved.length) throw new Error('Custom font could not be removed from settings.');

const settings = fs.readFileSync('flexible-product-customizer/includes/class-settings.php', 'utf8');
for (const extension of ['woff2', 'woff', 'ttf', 'otf']) {
	if (!settings.includes(`$mimes['${extension}']`)) throw new Error(`Missing server MIME support for ${extension}.`);
}
process.stdout.write('Admin font library smoke test passed.\n');
