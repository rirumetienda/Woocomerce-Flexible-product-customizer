const fs = require('fs');
const { JSDOM } = require('jsdom');

const html = `<!doctype html><html><body>
<form class="cart"><input name="variation_id" value="456"><button type="button" id="fpcw-open-editor"></button><input id="fpcw-token"><div id="fpcw-saved-summary" hidden></div><button class="single_add_to_cart_button" type="submit">Cart</button><section id="fpcw-product-previews" hidden><div id="fpcw-product-preview-list"></div></section></form>
<section id="product-module"><dialog id="fpcw-editor-modal"><button data-fpcw-close></button>
<div id="fpcw-color-control"></div><div id="fpcw-surface-tabs"></div><div id="fpcw-surface-overview"></div>
<div id="fpcw-view-modes" hidden><button id="fpcw-view-edit"></button><button id="fpcw-view-wrapped"></button></div>
<div id="fpcw-stage-canvases"><section id="fpcw-edit-panel"><div id="fpcw-canvas-shell"><canvas id="fpcw-canvas"></canvas></div></section><section id="fpcw-projection-panel" hidden><div id="fpcw-projection-shell"><canvas id="fpcw-mockup-canvas"></canvas><canvas id="fpcw-projection-canvas"></canvas></div><div id="fpcw-preview-angle-controls" hidden></div></section><div id="fpcw-loading" hidden></div></div>
<div id="fpcw-editor-message"></div><div id="fpcw-expiry-line"></div>
<div id="fpcw-selection-anchor"><div id="fpcw-selection-controls" hidden><div id="fpcw-text-controls" hidden></div></div></div>
<input id="fpcw-image-input" type="file"><button id="fpcw-add-image"></button>
<textarea id="fpcw-text-input"></textarea><button id="fpcw-add-text"></button>
<select id="fpcw-font-family"></select><input id="fpcw-font-size"><label><input id="fpcw-text-color" type="color"></label><input id="fpcw-outline-color" type="color"><div id="fpcw-outline-adjustment" hidden><input id="fpcw-outline-width" type="range"><output id="fpcw-outline-width-value"></output></div>
<button id="fpcw-bold"></button><button id="fpcw-italic"></button><button id="fpcw-underline"></button><button id="fpcw-outline"></button><button id="fpcw-align"><span></span></button>
<button id="fpcw-fit"></button><button id="fpcw-rotate"></button><button id="fpcw-delete"></button><button id="fpcw-save"></button>
</dialog></section></body></html>`;

const dom = new JSDOM(html, { url: 'https://store.test/product/shirt', runScripts: 'outside-only' });
const { window } = dom;
const calls = [];
let projectionRenders = 0;
let lastProjectionRotation = 0;
window.matchMedia = () => ({ matches: true, addEventListener() {}, addListener() {} });
window.FPCWCylindricalPreview = class {
	constructor(canvas) { this.canvas = canvas; }
	render(source, surface, rotation) {
		projectionRenders += 1;
		lastProjectionRotation = rotation;
		this.canvas.width = Math.min(1400, surface.width);
		this.canvas.height = Math.min(1400, surface.height);
		return this.canvas;
	}
};

const context = {
	save() {}, restore() {}, clearRect() {}, fillRect() {}, strokeRect() {}, drawImage() {}, beginPath() {}, rect() {}, clip() {}, moveTo() {}, lineTo() {}, stroke() {},
	translate() {}, rotate() {}, scale() {}, setLineDash() {}, fillText() {}, strokeText() {},
	measureText(text) { return { width: String(text).length * 20 }; },
};
window.HTMLCanvasElement.prototype.getContext = () => context;
window.HTMLCanvasElement.prototype.toBlob = function (callback) { callback(new window.Blob(['png'], { type: 'image/png' })); };
window.HTMLCanvasElement.prototype.getBoundingClientRect = () => ({ left: 0, top: 0, width: 600, height: 600 });
window.HTMLCanvasElement.prototype.setPointerCapture = () => {};
window.HTMLCanvasElement.prototype.hasPointerCapture = () => false;
window.alert = () => {};

const configuration = {
	schema_version: 2,
	product_type: 'cylindrical',
	template_id: 10,
	template_name: 'Shirt',
	product_id: 123,
	required: true,
	color_attribute: '',
	fonts: ['Arial'],
	font_faces: [{ family: 'Arial', url: 'https://store.test/font.woff2', format: 'woff2' }],
	colors: [{ id: 'white', label: 'White', hex: '#ffffff', variation_value: 'white' }],
	surfaces: [{
		id: 'front', label: 'Front', width: 1200, height: 1200,
		workspace: { x: 25, y: 20, width: 50, height: 60 },
		projection: { wrap_angle: 270, top_scale: 100, bottom_scale: 100, shading: 45 },
		base_image_url: '', color_image_urls: {}, allow_images: true, allow_text: true, max_images: 1, max_texts: 2,
		price_increment: 0, price_display: '$0.00',
	}],
};

function session(status) {
	return {
		token: 'a'.repeat(64), cart_proof: 'b'.repeat(64), status, product_id: 123, variation_id: status === 'active' ? 456 : 0,
		expires_at: '2030-01-01T00:00:00+00:00', expires_display: 'January 1, 2030 12:00 am UTC',
		payload: { template_snapshot: configuration, uploads: [], previews: status === 'active' ? [{ surface_id: 'front', url: 'https://store.test/preview.png' }] : [], production_files: [], design: {} },
	};
}

window.FPCW_EDITOR = {
	productId: 123, isVariable: true, configuration, restUrl: 'https://store.test/wp-json/fpcw/v1?lang=es', nonce: 'nonce',
	editToken: '', editVariationId: 0, initialVariationId: 0, webview: false, bridge: {},
	i18n: {
		chooseOptions: 'Choose options', uploadError: 'Upload error', saveError: 'Save error', imageLimit: 'Image limit',
		textLimit: 'Text limit', confirmRemove: 'Remove?', saving: 'Saving', saved: 'Ready', expires: 'Expires %s',
		cartColorLocked: 'Color locked', customizationRequired: 'Customize first', variationChanged: 'Save variation', emptyDesign: 'Empty design',
		color: 'Color', imageLoadError: 'Image load error', fileRules: 'File rules', dimensionRules: 'Dimension rules', exportError: 'Export error',
		editView: 'Edit', wrappedPreview: 'Wrapped', previewUnavailable: 'Unavailable', frontView: 'Front', leftSide: 'Left', rightSide: 'Right',
	},
};

window.fetch = async (url, options) => {
	calls.push({ url, method: options.method, body: options.body });
	let body = session('draft');
	const pathname = new URL(url).pathname;
	if (pathname.endsWith('/renders')) body = { file: { id: 'render' } };
	if (pathname.endsWith('/save')) body = session('active');
	return { ok: true, status: 200, async json() { return body; } };
};

window.eval(fs.readFileSync('flexible-product-customizer/assets/js/editor.js', 'utf8'));
window.document.dispatchEvent(new window.Event('DOMContentLoaded'));

if (configuration.schema_version !== 6 || configuration.product_type !== 'cylindrical' || configuration.surfaces[0].workspace.width !== 600 || configuration.surfaces[0].print_area.width !== 600 || !configuration.surfaces[0].projection.frame || !configuration.surfaces[0].base_image_transform || !configuration.colors[0].surfaces.front) {
	throw new Error('Legacy percentage snapshot was not migrated to the pixel canvas model.');
}

if (!window.document.querySelector('.single_add_to_cart_button').disabled) throw new Error('Add to cart must start disabled.');

(async () => {
	await window.FlexibleProductCustomizer.open();
	const modal = window.document.getElementById('fpcw-editor-modal');
	if (modal.parentElement !== window.document.body) throw new Error('Editor was not mounted at the document root.');
	if (!modal.hasAttribute('open')) throw new Error('Editor dialog did not enter its open state.');
	if (!window.document.documentElement.classList.contains('fpcw-modal-open')) throw new Error('Page scroll lock was not enabled.');
	window.document.getElementById('fpcw-text-input').value = 'Hello';
	window.document.getElementById('fpcw-add-text').click();
	if (!window.document.getElementById('fpcw-selection-controls').classList.contains('fpcw-selection-floating')) throw new Error('Mobile selection tools were not moved onto the canvas.');
	if (!window.document.getElementById('fpcw-selection-controls').classList.contains('has-text-selection')) throw new Error('The compact mobile text toolbar was not activated.');
	const outline = window.document.getElementById('fpcw-outline');
	outline.click();
	if (outline.getAttribute('aria-pressed') !== 'true' || window.document.getElementById('fpcw-outline-adjustment').hidden) throw new Error('Outline must reveal its thickness control when enabled on mobile.');
	outline.click();
	if (outline.getAttribute('aria-pressed') !== 'false' || !window.document.getElementById('fpcw-outline-adjustment').hidden) throw new Error('Outline thickness must collapse when outline is disabled on mobile.');
	window.document.getElementById('fpcw-view-wrapped').click();
	if (projectionRenders < 1 || window.document.getElementById('fpcw-stage-canvases').dataset.view !== 'wrapped' || window.document.getElementById('fpcw-projection-panel').hidden) throw new Error('The cylindrical wrapped preview did not render.');
	if (window.document.querySelectorAll('#fpcw-preview-angle-controls button').length !== 3) throw new Error('The cylindrical preview angle controls were not rendered.');
	const projectionCanvas = window.document.getElementById('fpcw-projection-canvas');
	const pointerDown = new window.Event('pointerdown', { bubbles: true });
	Object.defineProperties(pointerDown, { clientX: { value: 100 }, pointerId: { value: 1 } });
	projectionCanvas.dispatchEvent(pointerDown);
	const pointerMove = new window.Event('pointermove', { bubbles: true });
	Object.defineProperties(pointerMove, { clientX: { value: 200 }, pointerId: { value: 1 } });
	projectionCanvas.dispatchEvent(pointerMove);
	if (lastProjectionRotation <= 0) throw new Error('Dragging the wrapped preview did not rotate the cylindrical surface.');
	window.document.getElementById('fpcw-view-edit').click();
	if (!window.document.getElementById('fpcw-custom-font-faces')) throw new Error('Custom template fonts were not loaded into the editor.');
	await window.FlexibleProductCustomizer.save();

	const paths = calls.map((call) => new URL(call.url).pathname);
	if (!calls.every((call) => new URL(call.url).searchParams.get('lang') === 'es')) throw new Error('REST URLs did not preserve the language query parameter.');
	if (!paths.some((path) => path.endsWith('/sessions'))) throw new Error('Session creation was not requested.');
	if (paths.filter((path) => path.endsWith('/renders')).length !== 4) throw new Error('Expected three preview uploads and one production render upload.');
	if (!paths.some((path) => path.endsWith('/save'))) throw new Error('Final save was not requested.');
	if (projectionRenders < 2) throw new Error('The saved preview did not use the cylindrical renderer.');
	if (window.document.getElementById('fpcw-token').value !== 'a'.repeat(64)) throw new Error('Saved token was not attached to the cart form.');
	if (window.document.querySelector('.single_add_to_cart_button').disabled) throw new Error('Add to cart was not enabled after saving.');
	if (window.document.getElementById('fpcw-product-previews').hidden) throw new Error('Saved previews were not shown below add to cart.');
	const variation = window.document.querySelector('[name="variation_id"]');
	variation.value = '789';
	variation.dispatchEvent(new window.Event('change', { bubbles: true }));
	await new Promise((resolve) => setTimeout(resolve, 1));
	if (!window.document.querySelector('.single_add_to_cart_button').disabled) throw new Error('Changing the variation must lock add to cart until the design is saved again.');
	process.stdout.write('Editor smoke test passed.\n');
})().catch((error) => {
	process.stderr.write(error.stack + '\n');
	process.exitCode = 1;
});
