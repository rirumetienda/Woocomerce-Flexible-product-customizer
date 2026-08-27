(function () {
	'use strict';

	const root = document.getElementById('fpcw-template-builder');
	const field = document.getElementById('fpcw-template-config');
	if (!root || !field || !window.FPCW_ADMIN) return;

	let config;
	try { config = JSON.parse(field.value); } catch (error) { config = {}; }
	config.product_type = ['flat', 'cylindrical'].includes(config.product_type) ? config.product_type : '';
	config.fonts = Array.isArray(config.fonts) ? config.fonts : [];
	config.colors = Array.isArray(config.colors) ? config.colors : [];
	config.surfaces = Array.isArray(config.surfaces) ? config.surfaces : [];
	const fontLibrary = Array.isArray(window.FPCW_ADMIN.fontLibrary) && window.FPCW_ADMIN.fontLibrary.length
		? window.FPCW_ADMIN.fontLibrary
		: config.fonts.map((family) => ({ family }));
	config.fonts.forEach((family) => {
		if (!fontLibrary.some((font) => font.family === family)) fontLibrary.push({ family });
	});
	const previewCache = new Map();
	const attachmentCache = new Map();
	const activeBoxes = new Map();
	const previewAttributes = new Map();
	const openAttributes = new Set([0]);
	const openSurfaces = new Set([0]);
	const i18n = window.FPCW_ADMIN.i18n;
	let interaction = null;
	let submitLocked = false;

	function esc(value) {
		return String(value == null ? '' : value).replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[character]);
	}

	function slug(value) {
		return String(value || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
	}

	function round(value) {
		return Math.round(Number(value) || 0);
	}

	function clamp(value, min, max) {
		return Math.max(min, Math.min(max, Number(value) || 0));
	}

	function defaultPreviewViews() {
		return [
			{ id: 'front', label: i18n.frontView || 'Front view', rotation: 0, enabled: true, mockup_image_id: 0, mask_image_id: 0, overlay_image_id: 0 },
			{ id: 'left', label: i18n.leftSide || 'Left side', rotation: -45, enabled: true, mockup_image_id: 0, mask_image_id: 0, overlay_image_id: 0 },
			{ id: 'right', label: i18n.rightSide || 'Right side', rotation: 45, enabled: true, mockup_image_id: 0, mask_image_id: 0, overlay_image_id: 0 },
		];
	}

	function normalizePreviewViews(views) {
		const source = Array.isArray(views) && views.length ? views : defaultPreviewViews();
		const seen = new Set();
		const normalized = [];
		source.slice(0, 6).forEach((view, index) => {
			const id = slug(view && view.id ? view.id : 'view-' + (index + 1)) || 'view-' + (index + 1);
			if (seen.has(id)) return;
			seen.add(id);
			normalized.push({
				id,
				label: String(view && view.label ? view.label : id),
				rotation: round(clamp(view && view.rotation != null ? view.rotation : 0, -180, 180)),
				enabled: !view || view.enabled !== false,
				mockup_image_id: Number(view && view.mockup_image_id ? view.mockup_image_id : 0),
				mask_image_id: Number(view && view.mask_image_id ? view.mask_image_id : 0),
				overlay_image_id: Number(view && view.overlay_image_id ? view.overlay_image_id : 0),
			});
		});
		return normalized.length ? normalized : defaultPreviewViews();
	}

	function migrateConfig() {
		const version = Number(config.schema_version || 1);
		if (version < 5 && !config.product_type) config.product_type = 'flat';
		if (version < 4) {
			config.colors.forEach((color) => {
				color.surfaces = {};
				config.surfaces.forEach((surface) => {
					color.surfaces[surface.id] = {
						enabled: true,
						image_id: Number((surface.color_images || {})[color.id] || surface.base_image_id || 0),
					};
				});
			});
			config.surfaces.forEach((surface) => {
				delete surface.base_image_id;
				delete surface.color_images;
			});
		}
		if (version < 6) {
			config.surfaces.forEach((surface) => {
				const workspace = surface.workspace || { x: 0, y: 0, width: surface.width || 1000, height: surface.height || 1000 };
				surface.print_area = surface.print_area || { width: workspace.width, height: workspace.height };
				surface.projection = surface.projection && typeof surface.projection === 'object' ? surface.projection : {};
				surface.projection.frame = surface.projection.frame || Object.assign({}, workspace);
			});
		}
		config.schema_version = 6;
	}

	function normalizeSurface(surface) {
		surface.width = round(clamp(surface.width || 1000, 100, 10000));
		surface.height = round(clamp(surface.height || 1000, 100, 10000));
		surface.shape = config.product_type === 'cylindrical' ? 'rect' : (surface.shape === 'circle' ? 'circle' : 'rect');
		surface.workspace = constrainBox(surface.workspace || { x: surface.width * 0.25, y: surface.height * 0.2, width: surface.width * 0.5, height: surface.height * 0.6 }, surface);
		const printArea = surface.print_area && typeof surface.print_area === 'object' ? surface.print_area : {};
		surface.print_area = {
			width: round(clamp(printArea.width || surface.workspace.width || 1000, 100, 10000)),
			height: round(clamp(printArea.height || surface.workspace.height || 1000, 100, 10000)),
		};
		surface.base_image_transform = constrainBox(surface.base_image_transform || { x: 0, y: 0, width: surface.width, height: surface.height }, surface);
		surface.preview_overlay_image_id = Number(surface.preview_overlay_image_id || 0);
		const projection = surface.projection && typeof surface.projection === 'object' ? surface.projection : {};
		surface.projection = {
			wrap_angle: round(clamp(projection.wrap_angle || 180, 90, 360)),
			top_scale: round(clamp(projection.top_scale || 100, 50, 150)),
			bottom_scale: round(clamp(projection.bottom_scale || 100, 50, 150)),
			shading: round(clamp(projection.shading == null ? 45 : projection.shading, 0, 100)),
			frame: constrainBox(projection.frame || surface.workspace, surface),
			preview_views: normalizePreviewViews(projection.preview_views),
			mask_image_id: Number(projection.mask_image_id || 0),
			overlay_image_id: Number(projection.overlay_image_id || 0),
		};
		return surface;
	}

	function normalizeAssignments() {
		config.colors.forEach((color) => {
			color.surfaces = color.surfaces && typeof color.surfaces === 'object' ? color.surfaces : {};
			config.surfaces.forEach((surface) => {
				const current = color.surfaces[surface.id] || {};
				color.surfaces[surface.id] = { enabled: current.enabled !== false, image_id: Number(current.image_id || 0) };
			});
			Object.keys(color.surfaces).forEach((id) => {
				if (!config.surfaces.some((surface) => surface.id === id)) delete color.surfaces[id];
			});
		});
	}

	function constrainBox(box, surface) {
		const next = {
			x: round(clamp(box.x, 0, Math.max(0, surface.width - 1))),
			y: round(clamp(box.y, 0, Math.max(0, surface.height - 1))),
			width: Math.max(1, round(Number(box.width) || 1)),
			height: Math.max(1, round(Number(box.height) || 1)),
		};
		next.width = round(clamp(next.width, 1, Math.max(1, surface.width - next.x)));
		next.height = round(clamp(next.height, 1, Math.max(1, surface.height - next.y)));
		return next;
	}

	migrateConfig();
	config.surfaces.forEach(normalizeSurface);
	normalizeAssignments();

	function sync() {
		config.schema_version = 6;
		field.value = JSON.stringify(config);
	}

	function uniqueId(prefix, collection) {
		let index = collection.length + 1;
		let candidate = prefix + '-' + index;
		while (collection.some((item) => item.id === candidate)) candidate = prefix + '-' + (++index);
		return candidate;
	}

	function uniqueCopyId(sourceId) {
		const base = (slug(sourceId) || 'surface') + '-copy';
		let candidate = base;
		let index = 2;
		while (config.surfaces.some((surface) => surface.id === candidate)) candidate = `${base}-${index++}`;
		return candidate;
	}

	function render() {
		normalizeAssignments();
		root.innerHTML = `
			<div class="fpcw-save-state" data-save-state aria-live="polite"></div>
			<section class="fpcw-admin-section fpcw-product-type-section">
				<h3>${esc(i18n.productType)}</h3>
				<div class="fpcw-product-type" role="group" aria-label="${esc(i18n.productType)}">
					<button type="button" class="button ${config.product_type === 'flat' ? 'is-selected' : ''}" data-action="set-product-type" data-product-type="flat" aria-pressed="${config.product_type === 'flat'}"><span class="dashicons dashicons-format-image" aria-hidden="true"></span>${esc(i18n.flatProduct)}</button>
					<button type="button" class="button ${config.product_type === 'cylindrical' ? 'is-selected' : ''}" data-action="set-product-type" data-product-type="cylindrical" aria-pressed="${config.product_type === 'cylindrical'}"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>${esc(i18n.cylindricalProduct)}</button>
				</div>
			</section>
			${config.product_type ? '' : `<p class="fpcw-empty-state fpcw-product-type-required">${esc(i18n.chooseProductType)}</p>`}
			<div class="fpcw-template-configuration" ${config.product_type ? '' : 'hidden'}>
			<section class="fpcw-admin-section">
				<h3>${esc(i18n.textOptions)}</h3>
				<div class="fpcw-font-pills" role="group" aria-label="${esc(i18n.fonts)}">${fontLibrary.map(fontPill).join('')}</div>
			</section>
			<section class="fpcw-admin-section">
				<div class="fpcw-admin-heading"><h3>${esc(i18n.productColors)}</h3><button type="button" class="button" data-action="add-color">${esc(i18n.addColor)}</button></div>
				<div class="fpcw-attribute-list">${config.colors.map(attributePanel).join('')}</div>
			</section>
			<section class="fpcw-admin-section">
				<div class="fpcw-admin-heading"><h3>${esc(i18n.surfaces)}</h3><button type="button" class="button button-primary" data-action="add-surface">${esc(i18n.addSurface)}</button></div>
				<div class="fpcw-surface-list">${config.surfaces.map(surfacePanel).join('')}</div>
			</section>
			</div>`;
		bindAttachmentPreviews();
		config.surfaces.forEach((surface, index) => updateSurfacePreview(index));
		sync();
	}

	function fontPill(font) {
		const selected = config.fonts.includes(font.family);
		return `<button type="button" class="fpcw-font-pill ${selected ? 'is-selected' : ''}" data-action="toggle-font" data-font="${esc(font.family)}" aria-pressed="${selected}" style="font-family:${esc(font.family)}">${esc(font.family)}</button>`;
	}

	function attributePanel(color, colorIndex) {
		return `<details class="fpcw-attribute-panel" data-attribute-panel="${colorIndex}" ${openAttributes.has(colorIndex) ? 'open' : ''}>
			<summary><span class="fpcw-attribute-swatch" style="--fpcw-admin-swatch:${esc(color.hex || '#ffffff')}"></span><strong>${esc(color.label || color.id)}</strong><code>${esc(color.id)}</code></summary>
			<div class="fpcw-collapsible-content">
				<div class="fpcw-admin-grid fpcw-admin-grid--four">
					<label>${esc(i18n.name)}<input data-path="colors.${colorIndex}.label" value="${esc(color.label)}" /></label>
					<label>${esc(i18n.id)}<input data-color-id="${colorIndex}" value="${esc(color.id)}" /></label>
					<label>${esc(i18n.swatch)}<input type="color" data-path="colors.${colorIndex}.hex" value="${esc(color.hex || '#ffffff')}" /></label>
					<label>${esc(i18n.variationValue)}<input data-path="colors.${colorIndex}.variation_value" value="${esc(color.variation_value || color.id)}" /></label>
				</div>
				<div class="fpcw-admin-heading fpcw-attribute-surface-heading"><h4>${esc(i18n.baseImages)}</h4><button type="button" class="button-link-delete" data-action="remove-color" data-index="${colorIndex}">${esc(i18n.remove)}</button></div>
				<div class="fpcw-attribute-surfaces">${config.surfaces.map((surface) => attributeSurface(color, colorIndex, surface)).join('')}</div>
			</div>
		</details>`;
	}

	function attributeSurface(color, colorIndex, surface) {
		const assignment = color.surfaces[surface.id];
		return `<div class="fpcw-attribute-surface">
			<div><strong>${esc(surface.label)}</strong><label class="fpcw-check"><input type="checkbox" data-path="colors.${colorIndex}.surfaces.${surface.id}.enabled" ${assignment.enabled ? 'checked' : ''}> ${esc(i18n.enableSurface)}</label></div>
			${imagePicker(colorIndex, surface.id, assignment.image_id)}
		</div>`;
	}

	function surfacePanel(surface, index) {
		normalizeSurface(surface);
		if (!activeBoxes.has(index)) activeBoxes.set(index, config.product_type === 'cylindrical' ? 'projection_frame' : 'workspace');
		const active = activeBoxes.get(index);
		const editingTarget = config.product_type === 'cylindrical' ? 'projection_frame' : 'workspace';
		const editingLabel = config.product_type === 'cylindrical' ? i18n.projectionFrame : i18n.editingArea;
		return `<details class="fpcw-surface-panel" data-surface-panel="${index}" ${openSurfaces.has(index) ? 'open' : ''}>
			<summary><strong>${esc(surface.label || surface.id)}</strong><code>${esc(surface.id)}</code></summary>
			<div class="fpcw-collapsible-content">
				<div class="fpcw-admin-heading"><h4>${esc(surface.label || surface.id)}</h4><div class="fpcw-surface-actions"><button type="button" class="button" data-action="duplicate-surface" data-index="${index}"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span>${esc(i18n.duplicateSurface)}</button><button type="button" class="button-link-delete" data-action="remove-surface" data-index="${index}">${esc(i18n.removeSurface)}</button></div></div>
				<div class="fpcw-admin-grid fpcw-admin-grid--four">
					<label>${esc(i18n.name)}<input data-path="surfaces.${index}.label" value="${esc(surface.label)}" /></label>
					<label>${esc(i18n.id)}<input data-surface-id="${index}" value="${esc(surface.id)}" /></label>
					<label>${esc(config.product_type === 'cylindrical' ? i18n.mockupWidth : i18n.canvasWidth)}<input type="number" min="100" max="10000" step="1" data-path="surfaces.${index}.width" value="${surface.width}" /></label>
					<label>${esc(config.product_type === 'cylindrical' ? i18n.mockupHeight : i18n.canvasHeight)}<input type="number" min="100" max="10000" step="1" data-path="surfaces.${index}.height" value="${surface.height}" /></label>
					${config.product_type === 'flat' ? `<label>${esc(i18n.surfaceShape)}<select data-path="surfaces.${index}.shape"><option value="rect" ${surface.shape === 'rect' ? 'selected' : ''}>${esc(i18n.rectangle)}</option><option value="circle" ${surface.shape === 'circle' ? 'selected' : ''}>${esc(i18n.circle)}</option></select></label>` : ''}
				</div>
				${config.product_type === 'cylindrical' ? projectionControls(surface, index) : ''}
				<div class="fpcw-surface-editor">
					<div class="fpcw-preview-attributes"><span>${esc(i18n.previewAttribute)}</span>${config.colors.map((color) => previewAttributePill(index, color)).join('')}</div>
					<div class="fpcw-box-mode" role="group" aria-label="${esc(i18n.canvas)}">
						<button type="button" class="button ${active === 'base_image_transform' ? 'is-active' : ''}" data-action="select-box" data-surface="${index}" data-target="base_image_transform">${esc(i18n.baseImage)}</button>
						<button type="button" class="button ${active === editingTarget ? 'is-active' : ''}" data-action="select-box" data-surface="${index}" data-target="${editingTarget}">${esc(editingLabel)}</button>
					</div>
					<div class="fpcw-work-preview" data-surface-preview="${index}" style="aspect-ratio:${surface.width}/${surface.height}">
						<div class="fpcw-canvas-color" data-canvas-color></div>
						${previewAttachmentId(index) ? `<img data-base-preview data-attachment-preview="${previewAttachmentId(index)}" alt="" />` : '<div data-base-preview class="fpcw-base-placeholder"></div>'}
						${config.product_type === 'cylindrical' ? '<div class="fpcw-cylinder-guide" data-cylinder-guide aria-hidden="true"></div>' : ''}
						${editableBox(index, 'base_image_transform', i18n.baseImage, active, surface)}
						${editableBox(index, editingTarget, editingLabel, active, surface)}
					</div>
					<p class="description fpcw-drag-help">${esc(i18n.dragHelp)}</p>
					${boxControls(index, 'base_image_transform', i18n.baseImagePosition, surface.base_image_transform, surface)}
					${boxControls(index, editingTarget, config.product_type === 'cylindrical' ? i18n.projectionFramePosition : i18n.workArea, boxValue(surface, editingTarget), surface)}
					<h5>${esc(i18n.topPreviewLayer)}</h5>
					<div class="fpcw-projection-layers fpcw-surface-layers">${surfaceLayerImagePicker(index, 'preview_overlay_image_id', surface.preview_overlay_image_id, i18n.topPreviewLayer)}</div>
					<h5>${esc(i18n.elementLimits)}</h5>
					<div class="fpcw-admin-grid fpcw-admin-grid--four">
						<label class="fpcw-check"><input type="checkbox" data-path="surfaces.${index}.allow_images" ${surface.allow_images ? 'checked' : ''}> ${esc(i18n.images)}</label>
						${numberField(i18n.maximumImages, `surfaces.${index}.max_images`, surface.max_images, 0, 20)}
						<label class="fpcw-check"><input type="checkbox" data-path="surfaces.${index}.allow_text" ${surface.allow_text ? 'checked' : ''}> ${esc(i18n.text)}</label>
						${numberField(i18n.maximumTexts, `surfaces.${index}.max_texts`, surface.max_texts, 0, 20)}
					</div>
				</div>
			</div>
		</details>`;
	}

	function projectionControls(surface, index) {
		return `<section class="fpcw-projection-controls">
			<div class="fpcw-admin-heading"><h5>${esc(i18n.printMap)}</h5></div>
			<div class="fpcw-admin-grid fpcw-admin-grid--two">
				${numberField(i18n.printMapWidth, `surfaces.${index}.print_area.width`, surface.print_area.width, 100, 10000)}
				${numberField(i18n.printMapHeight, `surfaces.${index}.print_area.height`, surface.print_area.height, 100, 10000)}
			</div>
			<div class="fpcw-print-map-preview" style="aspect-ratio:${surface.print_area.width}/${surface.print_area.height}" aria-label="${esc(i18n.printMap)}">
				${[0, 25, 50, 75, 100].map((position, tick) => `<span style="left:${position}%"><i></i><b>${Math.round(surface.projection.wrap_angle * tick / 4)}&deg;</b></span>`).join('')}
			</div>
			<h5>${esc(i18n.cylindricalProjection)}</h5>
			<div class="fpcw-admin-grid fpcw-admin-grid--four">
				${numberField(i18n.wrapAngle, `surfaces.${index}.projection.wrap_angle`, surface.projection.wrap_angle, 90, 360)}
				${numberField(i18n.topDiameter, `surfaces.${index}.projection.top_scale`, surface.projection.top_scale, 50, 150)}
				${numberField(i18n.bottomDiameter, `surfaces.${index}.projection.bottom_scale`, surface.projection.bottom_scale, 50, 150)}
				${numberField(i18n.shading, `surfaces.${index}.projection.shading`, surface.projection.shading, 0, 100)}
			</div>
			<h5>${esc(i18n.previewAngles)}</h5>
			<div class="fpcw-preview-angle-settings">
				${surface.projection.preview_views.map((view, viewIndex) => previewAngleControl(index, view, viewIndex)).join('')}
			</div>
			<h5>${esc(i18n.optionalProjectionLayers)}</h5>
			<div class="fpcw-projection-layers">
				${projectionImagePicker(index, 'mask_image_id', surface.projection.mask_image_id, i18n.projectionMask)}
				${projectionImagePicker(index, 'overlay_image_id', surface.projection.overlay_image_id, i18n.lightingOverlay)}
			</div>
		</section>`;
	}

	function previewAngleControl(surfaceIndex, view, viewIndex) {
		return `<div class="fpcw-preview-angle-row">
			<label class="fpcw-check"><input type="checkbox" data-path="surfaces.${surfaceIndex}.projection.preview_views.${viewIndex}.enabled" ${view.enabled ? 'checked' : ''}> ${esc(i18n.show)}</label>
			<label>${esc(i18n.angleLabel)}<input data-path="surfaces.${surfaceIndex}.projection.preview_views.${viewIndex}.label" value="${esc(view.label)}" /></label>
			${numberField(i18n.rotationDegrees, `surfaces.${surfaceIndex}.projection.preview_views.${viewIndex}.rotation`, view.rotation, -180, 180)}
			<div class="fpcw-preview-angle-images" aria-label="${esc(i18n.angleSpecificImages)}">
				${previewAngleImagePicker(surfaceIndex, viewIndex, 'mockup_image_id', view.mockup_image_id, i18n.previewMockupImage)}
				${previewAngleImagePicker(surfaceIndex, viewIndex, 'mask_image_id', view.mask_image_id, i18n.projectionMask)}
				${previewAngleImagePicker(surfaceIndex, viewIndex, 'overlay_image_id', view.overlay_image_id, i18n.lightingOverlay)}
			</div>
		</div>`;
	}

	function previewAttributePill(surfaceIndex, color) {
		const current = previewColor(surfaceIndex);
		const assignment = color.surfaces[config.surfaces[surfaceIndex].id];
		return `<button type="button" class="fpcw-mini-swatch ${current && current.id === color.id ? 'is-selected' : ''}" data-action="preview-attribute" data-surface="${surfaceIndex}" data-color="${esc(color.id)}" style="--fpcw-admin-swatch:${esc(color.hex)}" ${assignment && assignment.enabled ? '' : 'disabled'} title="${esc(color.label)}" aria-label="${esc(color.label)}"></button>`;
	}

	function boxValue(surface, target) {
		return target === 'projection_frame' ? surface.projection.frame : surface[target];
	}

	function setBoxValue(surface, target, value) {
		if (target === 'projection_frame') surface.projection.frame = value;
		else surface[target] = value;
	}

	function boxPath(target) {
		return target === 'projection_frame' ? 'projection.frame' : target;
	}

	function editableBox(surfaceIndex, target, label, active, surface) {
		const handles = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
		const circleClass = target !== 'base_image_transform' && config.product_type === 'flat' && surface && surface.shape === 'circle' ? 'is-circle' : '';
		return `<div class="fpcw-edit-box fpcw-edit-box--${target === 'base_image_transform' ? 'base' : 'workspace'} ${circleClass} ${active === target ? 'is-active' : ''}" data-edit-box="${target}" data-surface="${surfaceIndex}" tabindex="0"><span>${esc(label)}</span>${handles.map((handle) => `<i data-resize-handle="${handle}"></i>`).join('')}</div>`;
	}

	function boxControls(index, target, label, box, surface) {
		const path = boxPath(target);
		return `<section class="fpcw-box-controls">
			<div class="fpcw-admin-heading"><h5>${esc(label)}</h5><div class="fpcw-align-tools">
				${toolButton(index, target, 'left', 'dashicons-align-left', i18n.alignLeft)}
				${toolButton(index, target, 'center', 'dashicons-align-center', i18n.center)}
				${toolButton(index, target, 'right', 'dashicons-align-right', i18n.alignRight)}
				${toolButton(index, target, 'top', 'dashicons-arrow-up-alt2', i18n.alignTop)}
				${toolButton(index, target, 'bottom', 'dashicons-arrow-down-alt2', i18n.alignBottom)}
				${target === 'base_image_transform' ? toolButton(index, target, 'fit', 'dashicons-editor-expand', i18n.fitCanvas) : ''}
			</div></div>
			<div class="fpcw-admin-grid fpcw-admin-grid--four">
				${numberField(i18n.positionX, `surfaces.${index}.${path}.x`, box.x, 0, surface.width - 1)}
				${numberField(i18n.positionY, `surfaces.${index}.${path}.y`, box.y, 0, surface.height - 1)}
				${numberField(i18n.width, `surfaces.${index}.${path}.width`, box.width, 1, surface.width)}
				${numberField(i18n.height, `surfaces.${index}.${path}.height`, box.height, 1, surface.height)}
			</div>
		</section>`;
	}

	function toolButton(index, target, action, icon, label) {
		return `<button type="button" class="button fpcw-tool-button" data-action="box-action" data-box-action="${action}" data-target="${target}" data-surface="${index}" title="${esc(label)}" aria-label="${esc(label)}"><span class="dashicons ${icon}" aria-hidden="true"></span></button>`;
	}

	function numberField(label, path, value, min, max) {
		return `<label>${esc(label)}<input type="number" min="${round(min)}" max="${round(max)}" step="1" data-path="${path}" value="${round(value)}" /></label>`;
	}

	function imagePicker(colorIndex, surfaceId, attachmentId) {
		return `<div class="fpcw-image-picker">
			<div class="fpcw-image-thumb">${attachmentId ? `<img data-attachment-preview="${attachmentId}" alt="" />` : `<span>${esc(i18n.noImageShort)}</span>`}</div>
			<div><button type="button" class="button" data-action="choose-image" data-color="${colorIndex}" data-surface-id="${esc(surfaceId)}">${esc(i18n.choose)}</button>
			${attachmentId ? `<button type="button" class="button-link-delete" data-action="clear-image" data-color="${colorIndex}" data-surface-id="${esc(surfaceId)}">${esc(i18n.clear)}</button>` : ''}</div>
		</div>`;
	}

	function projectionImagePicker(surfaceIndex, fieldName, attachmentId, label) {
		return `<div class="fpcw-image-picker fpcw-projection-image-picker">
			<strong>${esc(label)}</strong><div class="fpcw-image-thumb">${attachmentId ? `<img data-attachment-preview="${attachmentId}" alt="" />` : `<span>${esc(i18n.noImageShort)}</span>`}</div>
			<div><button type="button" class="button" data-action="choose-projection-image" data-surface="${surfaceIndex}" data-projection-field="${fieldName}">${esc(i18n.choose)}</button>
			${attachmentId ? `<button type="button" class="button-link-delete" data-action="clear-projection-image" data-surface="${surfaceIndex}" data-projection-field="${fieldName}">${esc(i18n.clear)}</button>` : ''}</div>
		</div>`;
	}

	function surfaceLayerImagePicker(surfaceIndex, fieldName, attachmentId, label) {
		return `<div class="fpcw-image-picker fpcw-projection-image-picker">
			<strong>${esc(label)}</strong><div class="fpcw-image-thumb">${attachmentId ? `<img data-attachment-preview="${attachmentId}" alt="" />` : `<span>${esc(i18n.noImageShort)}</span>`}</div>
			<div><button type="button" class="button" data-action="choose-surface-layer-image" data-surface="${surfaceIndex}" data-surface-field="${fieldName}">${esc(i18n.choose)}</button>
			${attachmentId ? `<button type="button" class="button-link-delete" data-action="clear-surface-layer-image" data-surface="${surfaceIndex}" data-surface-field="${fieldName}">${esc(i18n.clear)}</button>` : ''}</div>
		</div>`;
	}

	function previewAngleImagePicker(surfaceIndex, viewIndex, fieldName, attachmentId, label) {
		return `<div class="fpcw-image-picker fpcw-projection-image-picker">
			<strong>${esc(label)}</strong><div class="fpcw-image-thumb">${attachmentId ? `<img data-attachment-preview="${attachmentId}" alt="" />` : `<span>${esc(i18n.noImageShort)}</span>`}</div>
			<div><button type="button" class="button" data-action="choose-preview-view-image" data-surface="${surfaceIndex}" data-view="${viewIndex}" data-view-field="${fieldName}">${esc(i18n.choose)}</button>
			${attachmentId ? `<button type="button" class="button-link-delete" data-action="clear-preview-view-image" data-surface="${surfaceIndex}" data-view="${viewIndex}" data-view-field="${fieldName}">${esc(i18n.clear)}</button>` : ''}</div>
		</div>`;
	}

	function previewColor(surfaceIndex) {
		const surface = config.surfaces[surfaceIndex];
		if (!surface) return null;
		const selectedId = previewAttributes.get(surfaceIndex);
		const selected = config.colors.find((color) => color.id === selectedId && color.surfaces[surface.id] && color.surfaces[surface.id].enabled);
		if (selected) return selected;
		const withImage = config.colors.find((color) => color.surfaces[surface.id] && color.surfaces[surface.id].enabled && color.surfaces[surface.id].image_id);
		const available = withImage || config.colors.find((color) => color.surfaces[surface.id] && color.surfaces[surface.id].enabled) || config.colors[0] || null;
		if (available) previewAttributes.set(surfaceIndex, available.id);
		return available;
	}

	function previewAttachmentId(surfaceIndex) {
		const surface = config.surfaces[surfaceIndex];
		const color = previewColor(surfaceIndex);
		return surface && color && color.surfaces[surface.id] ? Number(color.surfaces[surface.id].image_id || 0) : 0;
	}

	function setPath(path, value) {
		const parts = path.split('.');
		let target = config;
		parts.slice(0, -1).forEach((part) => {
			if (target[part] == null) target[part] = {};
			target = target[part];
		});
		target[parts[parts.length - 1]] = value;
	}

	function getPath(path) {
		return path.split('.').reduce((value, part) => value == null ? undefined : value[part], config);
	}

	function attachmentDetails(id) {
		id = Number(id);
		if (!id) return Promise.resolve(null);
		if (attachmentCache.has(id)) return Promise.resolve(attachmentCache.get(id));
		const attachment = wp.media.attachment(id);
		return attachment.fetch().then(() => {
			const data = attachment.toJSON();
			const details = { url: data.sizes && data.sizes.medium ? data.sizes.medium.url : data.url, width: Number(data.width) || 1, height: Number(data.height) || 1 };
			attachmentCache.set(id, details);
			previewCache.set(id, details.url);
			return details;
		});
	}

	function bindAttachmentPreviews() {
		root.querySelectorAll('[data-attachment-preview]').forEach((image) => {
			const id = Number(image.dataset.attachmentPreview);
			if (previewCache.has(id)) {
				image.src = previewCache.get(id);
				return;
			}
			attachmentDetails(id).then((details) => {
				if (!details) return;
				root.querySelectorAll(`[data-attachment-preview="${id}"]`).forEach((target) => { target.src = details.url; });
			});
		});
	}

	function boxStyle(element, box, surface) {
		if (!element) return;
		element.style.left = `${box.x / surface.width * 100}%`;
		element.style.top = `${box.y / surface.height * 100}%`;
		element.style.width = `${box.width / surface.width * 100}%`;
		element.style.height = `${box.height / surface.height * 100}%`;
	}

	function updateSurfacePreview(index) {
		const surface = config.surfaces[index];
		const preview = root.querySelector(`[data-surface-preview="${index}"]`);
		if (!surface || !preview) return;
		preview.style.aspectRatio = `${surface.width} / ${surface.height}`;
		const color = previewColor(index);
		const background = preview.querySelector('[data-canvas-color]');
		if (background) background.style.background = color ? color.hex : '#ffffff';
		boxStyle(preview.querySelector('[data-base-preview]'), surface.base_image_transform, surface);
		boxStyle(preview.querySelector('[data-cylinder-guide]'), surface.projection.frame, surface);
		boxStyle(preview.querySelector('[data-edit-box="base_image_transform"]'), surface.base_image_transform, surface);
		boxStyle(preview.querySelector('[data-edit-box="workspace"]'), surface.workspace, surface);
		boxStyle(preview.querySelector('[data-edit-box="projection_frame"]'), surface.projection.frame, surface);
		const workspaceBox = preview.querySelector('[data-edit-box="workspace"]');
		if (workspaceBox) workspaceBox.classList.toggle('is-circle', config.product_type === 'flat' && surface.shape === 'circle');
		['workspace', 'base_image_transform', 'projection_frame'].forEach((target) => {
			const path = boxPath(target);
			const box = boxValue(surface, target);
			['x', 'y', 'width', 'height'].forEach((property) => {
				const input = root.querySelector(`[data-path="surfaces.${index}.${path}.${property}"]`);
				if (input) input.value = round(box[property]);
			});
		});
		const printPreview = root.querySelector(`[data-surface-panel="${index}"] .fpcw-print-map-preview`);
		if (printPreview) {
			printPreview.style.aspectRatio = `${surface.print_area.width} / ${surface.print_area.height}`;
			printPreview.querySelectorAll('b').forEach((label, tick) => { label.innerHTML = `${Math.round(surface.projection.wrap_angle * tick / 4)}&deg;`; });
		}
		sync();
	}

	function scaleForCanvasChange(surface, axis, oldValue, newValue) {
		const ratio = newValue / Math.max(1, oldValue);
		for (const target of ['workspace', 'base_image_transform', 'projection_frame']) {
			const box = boxValue(surface, target);
			if (axis === 'width') {
				box.x *= ratio;
				box.width *= ratio;
			} else {
				box.y *= ratio;
				box.height *= ratio;
			}
			setBoxValue(surface, target, constrainBox(box, surface));
		}
	}

	function setColorId(index, rawValue) {
		const oldId = config.colors[index].id;
		const newId = slug(rawValue) || 'color-' + (index + 1);
		config.colors[index].id = newId;
		previewAttributes.forEach((value, key) => { if (value === oldId) previewAttributes.set(key, newId); });
	}

	function setSurfaceId(index, rawValue) {
		const oldId = config.surfaces[index].id;
		const newId = slug(rawValue) || 'surface-' + (index + 1);
		config.surfaces[index].id = newId;
		config.colors.forEach((color) => {
			if (oldId !== newId && color.surfaces[oldId]) {
				color.surfaces[newId] = color.surfaces[oldId];
				delete color.surfaces[oldId];
			}
		});
	}

	function captureIdentityFields() {
		root.querySelectorAll('[data-color-id]').forEach((input) => setColorId(Number(input.dataset.colorId), input.value));
		root.querySelectorAll('input[data-surface-id]').forEach((input) => setSurfaceId(Number(input.dataset.surfaceId), input.value));
	}

	root.addEventListener('toggle', (event) => {
		if (event.target.matches('[data-attribute-panel]')) {
			const index = Number(event.target.dataset.attributePanel);
			if (event.target.open) openAttributes.add(index); else openAttributes.delete(index);
		}
		if (event.target.matches('[data-surface-panel]')) {
			const index = Number(event.target.dataset.surfacePanel);
			if (event.target.open) openSurfaces.add(index); else openSurfaces.delete(index);
		}
	}, true);

	root.addEventListener('input', (event) => {
		const input = event.target;
		if (!input.dataset.path) return;
		const oldValue = getPath(input.dataset.path);
		let value = input.type === 'checkbox' ? input.checked : input.value;
		if (input.type === 'number') value = round(value);
		setPath(input.dataset.path, value);

		const canvasMatch = input.dataset.path.match(/^surfaces\.(\d+)\.(width|height)$/);
		const boxMatch = input.dataset.path.match(/^surfaces\.(\d+)\.(workspace|base_image_transform|projection\.frame)\./);
		const printMapMatch = input.dataset.path.match(/^surfaces\.(\d+)\.print_area\.(width|height)$/);
		const projectionMatch = input.dataset.path.match(/^surfaces\.(\d+)\.projection\./);
		const shapeMatch = input.dataset.path.match(/^surfaces\.(\d+)\.shape$/);
		const previewLabelMatch = input.dataset.path.match(/^surfaces\.(\d+)\.projection\.preview_views\.\d+\.label$/);
		if (canvasMatch) {
			const index = Number(canvasMatch[1]);
			const surface = config.surfaces[index];
			const axis = canvasMatch[2];
			surface[axis] = round(clamp(value, 100, 10000));
			scaleForCanvasChange(surface, axis, Number(oldValue) || 1, surface[axis]);
			updateSurfacePreview(index);
		} else if (boxMatch) {
			const index = Number(boxMatch[1]);
			const target = boxMatch[2] === 'projection.frame' ? 'projection_frame' : boxMatch[2];
			setBoxValue(config.surfaces[index], target, constrainBox(boxValue(config.surfaces[index], target), config.surfaces[index]));
			updateSurfacePreview(index);
		} else if (printMapMatch) {
			const index = Number(printMapMatch[1]);
			normalizeSurface(config.surfaces[index]);
			updateSurfacePreview(index);
		} else if (shapeMatch) {
			const index = Number(shapeMatch[1]);
			normalizeSurface(config.surfaces[index]);
			updateSurfacePreview(index);
		} else if (projectionMatch) {
			normalizeSurface(config.surfaces[Number(projectionMatch[1])]);
			if (previewLabelMatch) sync();
			else updateSurfacePreview(Number(projectionMatch[1]));
		} else {
			sync();
		}
	});

	root.addEventListener('change', (event) => {
		const input = event.target;
		if (input.dataset.path && input.tagName === 'SELECT') {
			input.dispatchEvent(new Event('input', { bubbles: true }));
			return;
		}
		if (input.dataset.colorId != null) {
			setColorId(Number(input.dataset.colorId), input.value);
			render();
		}
		if (input.matches('input[data-surface-id]')) {
			setSurfaceId(Number(input.dataset.surfaceId), input.value);
			render();
		}
	});

	root.addEventListener('click', (event) => {
		const button = event.target.closest('[data-action]');
		if (!button) return;
		const action = button.dataset.action;
		if (action === 'set-product-type') {
			config.product_type = button.dataset.productType === 'cylindrical' ? 'cylindrical' : 'flat';
			activeBoxes.clear();
			render();
		}
		if (action === 'toggle-font') {
			const family = button.dataset.font;
			config.fonts = config.fonts.includes(family) ? config.fonts.filter((font) => font !== family) : config.fonts.concat(family);
			if (!config.fonts.length) config.fonts = [fontLibrary[0].family];
			render();
		}
		if (action === 'add-color') {
			const id = uniqueId('color', config.colors);
			const assignments = {};
			config.surfaces.forEach((surface) => { assignments[surface.id] = { enabled: true, image_id: 0 }; });
			config.colors.push({ id, label: i18n.newColor, hex: '#ffffff', variation_value: id, surfaces: assignments });
			openAttributes.clear();
			openAttributes.add(config.colors.length - 1);
			render();
		}
		if (action === 'remove-color' && config.colors.length > 1) {
			config.colors.splice(Number(button.dataset.index), 1);
			openAttributes.clear();
			openAttributes.add(0);
			render();
		}
		if (action === 'add-surface') {
			const id = uniqueId('surface', config.surfaces);
			config.surfaces.push({ id, label: i18n.newSurface, width: 1000, height: 1000, shape: 'rect', workspace: { x: 250, y: 200, width: 500, height: 600 }, print_area: { width: 2000, height: 800 }, base_image_transform: { x: 0, y: 0, width: 1000, height: 1000 }, projection: { wrap_angle: 180, top_scale: 100, bottom_scale: 100, shading: 45, frame: { x: 250, y: 200, width: 500, height: 600 }, preview_views: defaultPreviewViews(), mask_image_id: 0, overlay_image_id: 0 }, preview_overlay_image_id: 0, allow_images: true, allow_text: true, max_images: 1, max_texts: 3 });
			config.colors.forEach((color) => { color.surfaces[id] = { enabled: true, image_id: 0 }; });
			openSurfaces.clear();
			openSurfaces.add(config.surfaces.length - 1);
			render();
		}
		if (action === 'duplicate-surface') {
			captureIdentityFields();
			const index = Number(button.dataset.index);
			const source = config.surfaces[index];
			if (!source) return;
			const copy = JSON.parse(JSON.stringify(source));
			copy.id = uniqueCopyId(source.id);
			copy.label = `${source.label || source.id} ${i18n.copySuffix}`.trim();
			config.surfaces.splice(index + 1, 0, copy);
			config.colors.forEach((color) => {
				const assignment = color.surfaces[source.id] || { enabled: true, image_id: 0 };
				color.surfaces[copy.id] = JSON.parse(JSON.stringify(assignment));
			});
			activeBoxes.clear();
			previewAttributes.clear();
			openSurfaces.clear();
			openSurfaces.add(index + 1);
			render();
		}
		if (action === 'remove-surface' && config.surfaces.length > 1) {
			const index = Number(button.dataset.index);
			const removed = config.surfaces.splice(index, 1)[0];
			config.colors.forEach((color) => { delete color.surfaces[removed.id]; });
			activeBoxes.clear();
			previewAttributes.clear();
			openSurfaces.clear();
			openSurfaces.add(0);
			render();
		}
		if (action === 'choose-image') chooseImage(Number(button.dataset.color), button.dataset.surfaceId);
		if (action === 'clear-image') {
			config.colors[Number(button.dataset.color)].surfaces[button.dataset.surfaceId].image_id = 0;
			render();
		}
		if (action === 'choose-projection-image') chooseProjectionImage(Number(button.dataset.surface), button.dataset.projectionField);
		if (action === 'clear-projection-image') {
			const item = config.surfaces[Number(button.dataset.surface)];
			if (item && ['mask_image_id', 'overlay_image_id'].includes(button.dataset.projectionField)) item.projection[button.dataset.projectionField] = 0;
			render();
		}
		if (action === 'choose-surface-layer-image') chooseSurfaceLayerImage(Number(button.dataset.surface), button.dataset.surfaceField);
		if (action === 'clear-surface-layer-image') {
			const item = config.surfaces[Number(button.dataset.surface)];
			if (item && button.dataset.surfaceField === 'preview_overlay_image_id') item.preview_overlay_image_id = 0;
			render();
		}
		if (action === 'choose-preview-view-image') choosePreviewViewImage(Number(button.dataset.surface), Number(button.dataset.view), button.dataset.viewField);
		if (action === 'clear-preview-view-image') {
			const item = config.surfaces[Number(button.dataset.surface)];
			const view = item && item.projection && item.projection.preview_views ? item.projection.preview_views[Number(button.dataset.view)] : null;
			if (view && ['mockup_image_id', 'mask_image_id', 'overlay_image_id'].includes(button.dataset.viewField)) view[button.dataset.viewField] = 0;
			render();
		}
		if (action === 'preview-attribute') {
			previewAttributes.set(Number(button.dataset.surface), button.dataset.color);
			render();
		}
		if (action === 'select-box') {
			activeBoxes.set(Number(button.dataset.surface), button.dataset.target);
			render();
		}
		if (action === 'box-action') performBoxAction(Number(button.dataset.surface), button.dataset.target, button.dataset.boxAction);
	});

	function chooseImage(colorIndex, surfaceId) {
		const frame = wp.media({ title: window.FPCW_ADMIN.mediaTitle, button: { text: window.FPCW_ADMIN.mediaButton }, library: { type: 'image' }, multiple: false });
		frame.on('select', () => {
			const attachment = frame.state().get('selection').first().toJSON();
			const surfaceIndex = config.surfaces.findIndex((surface) => surface.id === surfaceId);
			const hadImage = config.colors.some((color) => color.surfaces[surfaceId] && color.surfaces[surfaceId].image_id);
			config.colors[colorIndex].surfaces[surfaceId].image_id = Number(attachment.id);
			previewAttributes.set(surfaceIndex, config.colors[colorIndex].id);
			const details = { url: attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url, width: Number(attachment.width) || 1, height: Number(attachment.height) || 1 };
			attachmentCache.set(Number(attachment.id), details);
			previewCache.set(Number(attachment.id), details.url);
			if (!hadImage) fitBaseImage(surfaceIndex, details);
			else render();
		});
		frame.open();
	}

	function chooseProjectionImage(surfaceIndex, fieldName) {
		if (!config.surfaces[surfaceIndex] || !['mask_image_id', 'overlay_image_id'].includes(fieldName)) return;
		chooseLayerAttachment((attachment) => {
			config.surfaces[surfaceIndex].projection[fieldName] = Number(attachment.id);
			render();
		});
	}

	function chooseSurfaceLayerImage(surfaceIndex, fieldName) {
		if (!config.surfaces[surfaceIndex] || fieldName !== 'preview_overlay_image_id') return;
		chooseLayerAttachment((attachment) => {
			config.surfaces[surfaceIndex][fieldName] = Number(attachment.id);
			render();
		});
	}

	function choosePreviewViewImage(surfaceIndex, viewIndex, fieldName) {
		const surface = config.surfaces[surfaceIndex];
		const view = surface && surface.projection && surface.projection.preview_views ? surface.projection.preview_views[viewIndex] : null;
		if (!view || !['mockup_image_id', 'mask_image_id', 'overlay_image_id'].includes(fieldName)) return;
		chooseLayerAttachment((attachment) => {
			view[fieldName] = Number(attachment.id);
			render();
		});
	}

	function chooseLayerAttachment(callback) {
		const frame = wp.media({ title: window.FPCW_ADMIN.mediaTitle, button: { text: window.FPCW_ADMIN.mediaButton }, library: { type: 'image' }, multiple: false });
		frame.on('select', () => {
			const attachment = frame.state().get('selection').first().toJSON();
			const details = { url: attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url, width: Number(attachment.width) || 1, height: Number(attachment.height) || 1 };
			attachmentCache.set(Number(attachment.id), details);
			previewCache.set(Number(attachment.id), details.url);
			callback(attachment, details);
		});
		frame.open();
	}

	function fitBaseImage(surfaceIndex, details) {
		const surface = config.surfaces[surfaceIndex];
		if (!surface || !details) return;
		const scale = Math.min(surface.width / details.width, surface.height / details.height);
		const width = details.width * scale;
		const height = details.height * scale;
		surface.base_image_transform = constrainBox({ x: (surface.width - width) / 2, y: (surface.height - height) / 2, width, height }, surface);
		render();
	}

	async function performBoxAction(surfaceIndex, target, action) {
		const surface = config.surfaces[surfaceIndex];
		const box = surface ? boxValue(surface, target) : null;
		if (!surface || !box) return;
		if (action === 'left') box.x = 0;
		if (action === 'right') box.x = surface.width - box.width;
		if (action === 'top') box.y = 0;
		if (action === 'bottom') box.y = surface.height - box.height;
		if (action === 'center') {
			box.x = (surface.width - box.width) / 2;
			box.y = (surface.height - box.height) / 2;
		}
		if (action === 'fit' && target === 'base_image_transform') {
			const details = await attachmentDetails(previewAttachmentId(surfaceIndex));
			if (details) {
				const scale = Math.min(surface.width / details.width, surface.height / details.height);
				box.width = details.width * scale;
				box.height = details.height * scale;
				box.x = (surface.width - box.width) / 2;
				box.y = (surface.height - box.height) / 2;
			}
		}
		setBoxValue(surface, target, constrainBox(box, surface));
		updateSurfacePreview(surfaceIndex);
	}

	function resizeProportionally(initial, handle, dx, dy, surface) {
		const horizontal = handle.includes('e') ? (initial.width + dx) / initial.width : (handle.includes('w') ? (initial.width - dx) / initial.width : null);
		const vertical = handle.includes('s') ? (initial.height + dy) / initial.height : (handle.includes('n') ? (initial.height - dy) / initial.height : null);
		let scale = horizontal == null ? vertical : (vertical == null ? horizontal : (Math.abs(horizontal - 1) >= Math.abs(vertical - 1) ? horizontal : vertical));
		const minScale = Math.max(10 / initial.width, 10 / initial.height);
		let maxScale = Infinity;
		if (handle.includes('e')) maxScale = Math.min(maxScale, (surface.width - initial.x) / initial.width);
		if (handle.includes('w')) maxScale = Math.min(maxScale, (initial.x + initial.width) / initial.width);
		if (!handle.includes('e') && !handle.includes('w')) maxScale = Math.min(maxScale, (2 * Math.min(initial.x + initial.width / 2, surface.width - initial.x - initial.width / 2)) / initial.width);
		if (handle.includes('s')) maxScale = Math.min(maxScale, (surface.height - initial.y) / initial.height);
		if (handle.includes('n')) maxScale = Math.min(maxScale, (initial.y + initial.height) / initial.height);
		if (!handle.includes('s') && !handle.includes('n')) maxScale = Math.min(maxScale, (2 * Math.min(initial.y + initial.height / 2, surface.height - initial.y - initial.height / 2)) / initial.height);
		scale = clamp(scale, minScale, Math.max(minScale, maxScale));
		const width = initial.width * scale;
		const height = initial.height * scale;
		let x = initial.x + (initial.width - width) / 2;
		let y = initial.y + (initial.height - height) / 2;
		if (handle.includes('e')) x = initial.x;
		if (handle.includes('w')) x = initial.x + initial.width - width;
		if (handle.includes('s')) y = initial.y;
		if (handle.includes('n')) y = initial.y + initial.height - height;
		return constrainBox({ x, y, width, height }, surface);
	}

	root.addEventListener('pointerdown', (event) => {
		const boxElement = event.target.closest('[data-edit-box].is-active');
		if (!boxElement) return;
		const surfaceIndex = Number(boxElement.dataset.surface);
		const target = boxElement.dataset.editBox;
		const preview = boxElement.closest('[data-surface-preview]');
		const surface = config.surfaces[surfaceIndex];
		if (!preview || !surface) return;
		event.preventDefault();
		interaction = { surfaceIndex, target, handle: event.target.dataset.resizeHandle || 'move', startX: event.clientX, startY: event.clientY, initial: Object.assign({}, boxValue(surface, target)), previewRect: preview.getBoundingClientRect() };
		boxElement.setPointerCapture(event.pointerId);
	});

	document.addEventListener('pointermove', (event) => {
		if (!interaction) return;
		const surface = config.surfaces[interaction.surfaceIndex];
		const dx = (event.clientX - interaction.startX) * surface.width / Math.max(1, interaction.previewRect.width);
		const dy = (event.clientY - interaction.startY) * surface.height / Math.max(1, interaction.previewRect.height);
		if (interaction.handle === 'move') {
			const next = Object.assign({}, interaction.initial);
			next.x = clamp(next.x + dx, 0, surface.width - next.width);
			next.y = clamp(next.y + dy, 0, surface.height - next.height);
			setBoxValue(surface, interaction.target, constrainBox(next, surface));
		} else {
			setBoxValue(surface, interaction.target, resizeProportionally(interaction.initial, interaction.handle, dx, dy, surface));
		}
		updateSurfacePreview(interaction.surfaceIndex);
	});

	document.addEventListener('pointerup', () => { interaction = null; });
	document.addEventListener('pointercancel', () => { interaction = null; });

	const form = field.closest('form');
	if (form) {
		form.addEventListener('submit', async (event) => {
			if (submitLocked) {
				submitLocked = false;
				return;
			}
			event.preventDefault();
			captureIdentityFields();
			sync();
			const submitter = event.submitter || document.getElementById('publish');
			const state = root.querySelector('[data-save-state]');
			if (state) {
				state.textContent = i18n.savingTemplate;
				state.className = 'fpcw-save-state is-saving';
			}
			if (submitter) submitter.disabled = true;
			try {
				const postId = Number((document.getElementById('post_ID') || {}).value || 0);
				const body = new URLSearchParams({ action: 'fpcw_save_template_config', nonce: window.FPCW_ADMIN.ajaxNonce, post_id: String(postId), config: field.value });
				const response = await fetch(window.FPCW_ADMIN.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() });
				const result = await response.json();
				if (!response.ok || !result.success || !result.data || !result.data.config) throw new Error(result.data && result.data.message ? result.data.message : i18n.saveFailed);
				config = result.data.config;
				field.value = JSON.stringify(config);
				if (state) {
					state.textContent = i18n.templateSaved;
					state.className = 'fpcw-save-state is-saved';
				}
				submitLocked = true;
				if (submitter) submitter.disabled = false;
				if (form.requestSubmit) form.requestSubmit(submitter || undefined);
				else if (submitter) submitter.click();
				else form.submit();
			} catch (error) {
				if (submitter) submitter.disabled = false;
				if (state) {
					state.textContent = error.message || i18n.saveFailed;
					state.className = 'fpcw-save-state is-error';
				}
			}
		});
	}

	render();
})();
