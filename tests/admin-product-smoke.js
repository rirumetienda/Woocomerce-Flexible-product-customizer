const fs = require('fs');
const { JSDOM } = require('jsdom');

const dom = new JSDOM(`<!doctype html><form id="post"><select id="_fpcw_template_id"><option value="">None</option><option value="10" selected>Shirt</option></select><div id="fpcw-product-colors"></div><input id="_fpcw_allowed_colors" value='["white","black"]'><div id="fpcw-product-surfaces"></div><input id="_fpcw_surface_settings" value='{"front":{"enabled":true,"price":0},"back":{"enabled":true,"price":5}}'></form>`, { runScripts: 'outside-only' });
const { window } = dom;
window.FPCW_PRODUCT_ADMIN = {
	currencySymbol: '$', priceDecimals: 2,
	templates: {
		10: {
			colors: [{ id: 'white', label: 'White', hex: '#fff' }, { id: 'black', label: 'Black', hex: '#000' }],
			surfaces: [{ id: 'front', label: 'Front', attributes: ['White', 'Black'] }, { id: 'back', label: 'Back', attributes: ['Black'] }],
		},
	},
	i18n: { chooseTemplate: 'Choose', selectAll: 'All', noColors: 'No colors', noSurfaces: 'No surfaces', enabled: 'Enabled', priceIncrement: 'Price', baseSurface: 'Base', availableFor: 'Available for: %s' },
};

window.eval(fs.readFileSync('flexible-product-customizer/assets/js/admin-product.js', 'utf8'));

const black = window.document.querySelector('[data-color-id="black"]');
black.checked = false;
black.dispatchEvent(new window.Event('change', { bubbles: true }));
const backPrice = window.document.querySelector('[data-surface-price="back"]');
if (!backPrice.closest('.fpcw-derived-surface').textContent.includes('Back')) throw new Error('Surface price is not visibly associated with its surface label.');
backPrice.value = '7.50';
backPrice.dispatchEvent(new window.Event('input', { bubbles: true }));
window.document.getElementById('post').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));

const colors = JSON.parse(window.document.getElementById('_fpcw_allowed_colors').value);
const surfaces = JSON.parse(window.document.getElementById('_fpcw_surface_settings').value);
if (colors.length !== 1 || colors[0] !== 'white') throw new Error('Template color selection was not serialized.');
if (!surfaces.front.enabled || !surfaces.back.enabled || surfaces.back.price !== 7.5) throw new Error('Surface selection and prices were not serialized.');
process.stdout.write('Admin product smoke test passed.\n');
