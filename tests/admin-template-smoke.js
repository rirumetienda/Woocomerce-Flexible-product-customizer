const fs = require('fs');
const { JSDOM } = require('jsdom');

const initial = {
	schema_version: 6,
	product_type: '',
	fonts: ['Arial'],
	colors: [{ id: 'white', label: 'White', hex: '#ffffff', variation_value: 'white', surfaces: { front: { enabled: true, image_id: 0 } } }],
	surfaces: [{
		id: 'front', label: 'Front', width: 1000, height: 1000,
		workspace: { x: 250, y: 200, width: 500, height: 600 },
		print_area: { width: 2000, height: 800 },
		base_image_transform: { x: 0, y: 0, width: 1000, height: 1000 },
		projection: { wrap_angle: 180, top_scale: 100, bottom_scale: 100, shading: 45, frame: { x: 250, y: 200, width: 500, height: 600 }, preview_views: [], mask_image_id: 0, overlay_image_id: 0 },
		allow_images: true, allow_text: true, max_images: 1, max_texts: 3,
	}],
};

const dom = new JSDOM(`<!doctype html><form id="post"><input id="post_ID" value="42"><button id="publish" type="submit">Publish</button><div id="fpcw-template-builder"></div><input id="fpcw-template-config" name="fpcw_template_config"></form>`, { runScripts: 'outside-only', url: 'https://store.test/wp-admin/post.php' });
const { window } = dom;
const form = window.document.getElementById('post');
let submitted = false;
form.requestSubmit = () => { submitted = true; };
window.document.getElementById('fpcw-template-config').value = JSON.stringify(initial);
window.FPCW_ADMIN = {
	ajaxUrl: 'https://store.test/wp-admin/admin-ajax.php', ajaxNonce: 'nonce', mediaTitle: 'Media', mediaButton: 'Use',
	fontLibrary: [{ family: 'Arial' }, { family: 'Georgia' }],
	i18n: {
		productType: 'Product type', flatProduct: 'Flat', cylindricalProduct: 'Cylindrical', chooseProductType: 'Choose a type',
		textOptions: 'Text options', fonts: 'Fonts', productColors: 'Colors', addColor: 'Add color', surfaces: 'Surfaces',
		addSurface: 'Add surface', name: 'Name', id: 'ID', swatch: 'Swatch', variationValue: 'Variation', remove: 'Remove',
		removeSurface: 'Remove surface', duplicateSurface: 'Duplicate', copySuffix: 'copy', canvasWidth: 'Width', canvasHeight: 'Height', mockupWidth: 'Mockup width', mockupHeight: 'Mockup height', baseImages: 'Images',
		workArea: 'Area', width: 'Width', height: 'Height', elementLimits: 'Limits', images: 'Images',
		maximumImages: 'Max images', text: 'Text', maximumTexts: 'Max texts', noImageShort: 'No image', choose: 'Choose', clear: 'Clear',
		newColor: 'New color', newSurface: 'New surface', canvas: 'Canvas', baseImagePosition: 'Base position', positionX: 'X', positionY: 'Y',
		center: 'Center', fitCanvas: 'Fit', alignLeft: 'Left', alignRight: 'Right', alignTop: 'Top', alignBottom: 'Bottom', dragHelp: 'Drag',
		savingTemplate: 'Saving', templateSaved: 'Saved', saveFailed: 'Failed', baseImage: 'Base', editingArea: 'Area',
		enableSurface: 'Available', previewAttribute: 'Preview', cylindricalProjection: 'Projection', wrapAngle: 'Wrap',
		topDiameter: 'Top', bottomDiameter: 'Bottom', shading: 'Shading', printMap: 'Print map', printMapWidth: 'Print width', printMapHeight: 'Print height',
		projectionFrame: 'Projection frame', projectionFramePosition: 'Projection position', projectionMask: 'Mask', lightingOverlay: 'Overlay', optionalProjectionLayers: 'Layers',
		previewAngles: 'Preview angles', show: 'Show', angleLabel: 'Angle label', rotationDegrees: 'Rotation', frontView: 'Front view', leftSide: 'Left side', rightSide: 'Right side',
	},
};
window.wp = { media() { return { on() {}, open() {} }; } };
window.wp.media.attachment = () => ({ fetch: () => Promise.resolve(), toJSON: () => ({ url: '', width: 1000, height: 1000 }) });
window.fetch = async (url, options) => {
	const body = new window.URLSearchParams(options.body);
	if (body.get('action') !== 'fpcw_save_template_config' || body.get('post_id') !== '42') throw new Error('Wrong AJAX persistence request.');
	return { ok: true, async json() { return { success: true, data: { config: JSON.parse(body.get('config')) } }; } };
};

window.eval(fs.readFileSync('flexible-product-customizer/assets/js/admin-template.js', 'utf8'));

if (!window.document.querySelector('.fpcw-template-configuration').hidden) throw new Error('A new template must require choosing its product type first.');
window.document.querySelector('[data-product-type="cylindrical"]').click();
const wrapAngle = window.document.querySelector('[data-path="surfaces.0.projection.wrap_angle"]');
if (!wrapAngle) throw new Error('Cylindrical projection controls were not shown after selecting the product type.');
if (window.document.querySelectorAll('.fpcw-preview-angle-row').length !== 3) throw new Error('Default cylindrical preview angles were not shown.');
wrapAngle.value = '270';
wrapAngle.dispatchEvent(new window.Event('input', { bubbles: true }));
const leftAngle = window.document.querySelector('[data-path="surfaces.0.projection.preview_views.1.rotation"]');
leftAngle.value = '-40';
leftAngle.dispatchEvent(new window.Event('input', { bubbles: true }));

const width = window.document.querySelector('[data-path="surfaces.0.width"]');
width.value = '1200';
width.dispatchEvent(new window.Event('input', { bubbles: true }));
const areaWidth = window.document.querySelector('[data-path="surfaces.0.projection.frame.width"]');
areaWidth.value = '350';
areaWidth.dispatchEvent(new window.Event('input', { bubbles: true }));
const printWidth = window.document.querySelector('[data-path="surfaces.0.print_area.width"]');
printWidth.value = '2400';
printWidth.dispatchEvent(new window.Event('input', { bubbles: true }));

const preview = window.document.querySelector('[data-surface-preview="0"]');
preview.getBoundingClientRect = () => ({ left: 0, top: 0, width: 600, height: 500 });
if (!preview.style.aspectRatio.includes('1200')) throw new Error('Canvas dimensions did not update the live preview.');
const workspace = preview.querySelector('[data-edit-box="projection_frame"]');
if (Math.abs(Number.parseFloat(workspace.style.width) - (350 / 1200 * 100)) > 0.01) throw new Error('Projection frame pixels did not update the live preview.');

workspace.setPointerCapture = () => {};
function pointer(target, type, x, y) {
	const event = new window.Event(type, { bubbles: true, cancelable: true });
	Object.defineProperties(event, { clientX: { value: x }, clientY: { value: y }, pointerId: { value: 1 } });
	target.dispatchEvent(event);
}
const xBeforeDrag = Number(window.document.querySelector('[data-path="surfaces.0.projection.frame.x"]').value);
pointer(workspace, 'pointerdown', 100, 100);
pointer(window.document, 'pointermove', 160, 150);
pointer(window.document, 'pointerup', 160, 150);
const xAfterDrag = Number(window.document.querySelector('[data-path="surfaces.0.projection.frame.x"]').value);
if (xAfterDrag <= xBeforeDrag) throw new Error('Projection frame could not be dragged inside the mockup.');

const widthBeforeResize = Number(window.document.querySelector('[data-path="surfaces.0.projection.frame.width"]').value);
const heightBeforeResize = Number(window.document.querySelector('[data-path="surfaces.0.projection.frame.height"]').value);
const resizeHandle = workspace.querySelector('[data-resize-handle="se"]');
pointer(resizeHandle, 'pointerdown', 300, 300);
pointer(window.document, 'pointermove', 340, 330);
pointer(window.document, 'pointerup', 340, 330);
const widthAfterResize = Number(window.document.querySelector('[data-path="surfaces.0.projection.frame.width"]').value);
const heightAfterResize = Number(window.document.querySelector('[data-path="surfaces.0.projection.frame.height"]').value);
if (widthAfterResize <= widthBeforeResize) throw new Error('Projection frame could not be resized from a corner handle.');
if (Math.abs(widthBeforeResize / heightBeforeResize - widthAfterResize / heightAfterResize) > 0.01) throw new Error('Projection frame aspect ratio changed during pointer resize.');

window.document.querySelector('[data-action="duplicate-surface"]').click();
form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));

(async () => {
	await new Promise((resolve) => setTimeout(resolve, 10));
	const saved = JSON.parse(window.document.getElementById('fpcw-template-config').value);
	if (!submitted) throw new Error('WordPress submit did not continue after confirmed AJAX persistence.');
	if (saved.schema_version !== 6 || saved.product_type !== 'cylindrical' || saved.surfaces.length !== 2 || saved.surfaces[0].width !== 1200 || saved.surfaces[0].projection.frame.width <= 350) {
		throw new Error('Template state was not persisted before submit.');
	}
	if (saved.surfaces[0].projection.wrap_angle !== 270 || saved.surfaces[0].projection.preview_views[1].rotation !== -40 || saved.surfaces[0].print_area.width !== 2400 || !saved.surfaces[1].projection) throw new Error('Cylindrical surface geometry was not persisted.');
	if (!saved.surfaces[0].base_image_transform) throw new Error('Base image position was not persisted.');
	if (saved.surfaces[1].id !== 'front-copy' || !saved.colors[0].surfaces.front || !saved.colors[0].surfaces['front-copy']) throw new Error('Duplicated surface assignments were not persisted independently.');
	for (const box of [saved.surfaces[0].projection.frame, saved.surfaces[0].base_image_transform]) {
		if (Object.values(box).some((value) => !Number.isInteger(value))) throw new Error('Template coordinates must be integers.');
	}
	process.stdout.write('Admin template persistence and canvas smoke test passed.\n');
})().catch((error) => {
	process.stderr.write(error.stack + '\n');
	process.exitCode = 1;
});
