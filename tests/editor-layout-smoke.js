const fs = require('fs');

const css = fs.readFileSync('flexible-product-customizer/assets/css/editor.css', 'utf8');
const frontend = fs.readFileSync('flexible-product-customizer/includes/class-frontend.php', 'utf8');
const editor = fs.readFileSync('flexible-product-customizer/assets/js/editor.js', 'utf8');

const requirements = [
	[/<dialog id="fpcw-editor-modal"/, frontend, 'The storefront editor must use a native dialog top layer.'],
	[/document\.body\.appendChild\(dom\.modal\)/, editor, 'The editor must be mounted directly under body.'],
	[/dom\.modal\.showModal\(\)/, editor, 'The editor must open as a modal dialog.'],
	[/\.fpcw-modal:not\(\[open\]\)/, css, 'The closed dialog state is missing.'],
	[/z-index:\s*2147483647/, css, 'The non-dialog fallback does not have a safe stacking level.'],
	[/height:\s*100dvh/, css, 'The editor does not account for the mobile dynamic viewport.'],
	[/env\(safe-area-inset-top\)/, css, 'The editor header does not account for mobile safe areas.'],
	[/\.fpcw-stage-wrap\s*\{[^}]*order:\s*1/s, css, 'The canvas must come first in the mobile layout.'],
	[/\.fpcw-toolbar\s*\{[^}]*order:\s*2/s, css, 'The controls must follow the canvas in the mobile layout.'],
	[/@media \(min-width:\s*821px\)/, css, 'The desktop enhancement breakpoint is missing.'],
	[/\.fpcw-selection-floating\s*\{/, css, 'The mobile contextual toolbar style is missing.'],
	[/\.fpcw-selection-floating\.has-text-selection\s*\{[^}]*grid-template-columns:\s*repeat\(8,/s, css, 'Mobile text tools must fit into an eight-action row.'],
	[/\.fpcw-selection-floating \.fpcw-text-primary-fields\s*\{[^}]*grid-template-columns:/s, css, 'Mobile font, size, color, and outline fields are not compacted into one row.'],
	[/\.fpcw-selection-floating \.fpcw-action-label\s*\{[^}]*display:\s*none/s, css, 'Mobile selection actions must be icon-only.'],
	[/id="fpcw-outline-adjustment"[^>]*hidden/, frontend, 'The mobile outline thickness control must start collapsed.'],
	[/id="fpcw-view-modes"[\s\S]{0,220}\shidden>/, frontend, 'Cylindrical edit and wrapped modes must start from a stable hidden state.'],
	[/id="fpcw-projection-canvas"/, frontend, 'The wrapped preview canvas is missing.'],
	[/id="fpcw-preview-angle-controls"/, frontend, 'Cylindrical preview angle controls are missing.'],
	[/id="fpcw-mockup-canvas"/, frontend, 'The mockup base canvas is not independent from the print editor.'],
	[/id="fpcw-stage-canvases"/, frontend, 'The dual cylindrical stage is missing.'],
	[/\.fpcw-projection-canvas\s*\{[^}]*position:\s*absolute/s, css, 'The wrapped preview is not layered over the product base.'],
	[/\.fpcw-stage-canvases\.is-cylindrical\s*\{[^}]*grid-template-columns:\s*repeat\(2,/s, css, 'Desktop must show print design and product preview together.'],
	[/function positionSelectionTools\(\)/, editor, 'Selected-element tools are not positioned beside the canvas selection.'],
	[/function customizationReady\(\)/, editor, 'Add-to-cart readiness is not derived from customization and variation state.'],
];

for (const [pattern, source, message] of requirements) {
	if (!pattern.test(source)) throw new Error(message);
}

if (/@media \(max-width:/.test(css)) {
	throw new Error('Editor CSS must remain mobile-first; use min-width enhancements.');
}

process.stdout.write('Editor layout smoke test passed.\n');
