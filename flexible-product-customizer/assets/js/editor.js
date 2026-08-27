(function () {
	'use strict';

	if (!window.FPCW_EDITOR) return;

	const boot = window.FPCW_EDITOR;
	let config = normalizeConfig(boot.configuration);
	const state = {
		token: boot.editToken || '',
		expiresDisplay: '',
		variationId: Number(boot.editVariationId || 0),
		colorId: config.colors[0] ? config.colors[0].id : '',
		surfaceId: config.surfaces[0] ? config.surfaces[0].id : '',
		designs: new Map(),
		uploads: new Map(),
		previewRotations: new Map(),
		selectedId: '',
		viewMode: 'edit',
		controlsObjectId: '',
		outlineToolsOpen: false,
		interaction: null,
		projectionInteraction: null,
		ready: false,
		busy: false,
		inCart: false,
	};

	const dom = {};
	const imageCache = new Map();
	const measureCanvas = document.createElement('canvas');
	const measureContext = measureCanvas.getContext('2d');
	const projectionTextureCanvas = document.createElement('canvas');
	let projectionRenderer = null;
	let projectionUnavailable = false;
	let initialized = false;

	function el(id) { return document.getElementById(id); }
	function normalizeConfig(input) {
		const normalized = input || { colors: [], surfaces: [], fonts: [] };
		const originalVersion = Number(normalized.schema_version || 1);
		if (originalVersion < 3) {
			(normalized.surfaces || []).forEach((item) => {
				const area = item.workspace || { x: 25, y: 20, width: 50, height: 60 };
				item.workspace = {
					x: item.width * Number(area.x) / 100,
					y: item.height * Number(area.y) / 100,
					width: item.width * Number(area.width) / 100,
					height: item.height * Number(area.height) / 100,
				};
				item.base_image_transform = { x: 0, y: 0, width: item.width, height: item.height };
			});
		}
		if (originalVersion < 4) {
			(normalized.colors || []).forEach((color) => {
				color.surfaces = {};
				(normalized.surfaces || []).forEach((item) => {
					color.surfaces[item.id] = {
						enabled: true,
						image_id: Number((item.color_images || {})[color.id] || item.base_image_id || 0),
						image_url: (item.color_image_urls && item.color_image_urls[color.id]) || item.base_image_url || '',
					};
				});
			});
		}
		normalized.product_type = normalized.product_type === 'cylindrical' ? 'cylindrical' : 'flat';
		(normalized.surfaces || []).forEach((item) => {
			item.shape = normalized.product_type === 'flat' && item.shape === 'circle' ? 'circle' : 'rect';
			const workspace = item.workspace || { x: 0, y: 0, width: item.width || 1000, height: item.height || 1000 };
			const projection = item.projection && typeof item.projection === 'object' ? item.projection : {};
			const frame = projection.frame && typeof projection.frame === 'object' ? projection.frame : workspace;
			const printArea = item.print_area && typeof item.print_area === 'object' ? item.print_area : { width: workspace.width, height: workspace.height };
			item.print_area = {
				width: Math.max(100, Math.min(10000, Math.round(Number(printArea.width || workspace.width || 1000)))),
				height: Math.max(100, Math.min(10000, Math.round(Number(printArea.height || workspace.height || 1000)))),
			};
			item.projection = {
				wrap_angle: Math.max(90, Math.min(360, Math.round(Number(projection.wrap_angle || 180)))),
				top_scale: Math.max(50, Math.min(150, Math.round(Number(projection.top_scale || 100)))),
				bottom_scale: Math.max(50, Math.min(150, Math.round(Number(projection.bottom_scale || 100)))),
				shading: Math.max(0, Math.min(100, Math.round(Number(projection.shading == null ? 45 : projection.shading)))),
				frame: {
					x: Math.round(Number(frame.x || 0)), y: Math.round(Number(frame.y || 0)),
					width: Math.max(1, Math.round(Number(frame.width || workspace.width || 1))),
					height: Math.max(1, Math.round(Number(frame.height || workspace.height || 1))),
				},
				preview_views: normalizePreviewViews(projection.preview_views),
				mask_image_id: Number(projection.mask_image_id || 0),
				overlay_image_id: Number(projection.overlay_image_id || 0),
				mask_image_url: projection.mask_image_url || '',
				overlay_image_url: projection.overlay_image_url || '',
			};
		});
		Object.defineProperty(normalized, '_source_schema_version', { value: originalVersion, configurable: true });
		normalized.schema_version = 6;
		return normalized;
	}
	function defaultPreviewViews() {
		return [
			{ id: 'front', label: boot.i18n.frontView || 'Front view', rotation: 0, enabled: true },
			{ id: 'left', label: boot.i18n.leftSide || 'Left side', rotation: -45, enabled: true },
			{ id: 'right', label: boot.i18n.rightSide || 'Right side', rotation: 45, enabled: true },
		];
	}
	function normalizePreviewViews(views) {
		const source = Array.isArray(views) && views.length ? views : defaultPreviewViews();
		const seen = new Set();
		const normalized = [];
		source.slice(0, 6).forEach((view, index) => {
			const id = String(view && view.id ? view.id : 'view-' + (index + 1)).toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-|-$/g, '') || 'view-' + (index + 1);
			if (seen.has(id)) return;
			seen.add(id);
			normalized.push({
				id,
				label: String(view && view.label ? view.label : id),
				rotation: Math.max(-180, Math.min(180, Math.round(Number(view && view.rotation != null ? view.rotation : 0)))),
				enabled: !view || view.enabled !== false,
			});
		});
		return normalized.length ? normalized : defaultPreviewViews();
	}
	function isCylindrical() { return config.product_type === 'cylindrical'; }
	function surface() { return config.surfaces.find((item) => item.id === state.surfaceId); }
	function currentColor() { return config.colors.find((item) => item.id === state.colorId) || null; }
	function availableSurfaces() {
		const color = currentColor();
		return config.surfaces.filter((item) => color && color.surfaces && color.surfaces[item.id] && color.surfaces[item.id].enabled);
	}
	function surfaceDesign(id) {
		if (!state.designs.has(id)) state.designs.set(id, { id, objects: [] });
		return state.designs.get(id);
	}
	function selected() {
		return surfaceDesign(state.surfaceId).objects.find((item) => item.id === state.selectedId) || null;
	}
	function workArea(item) {
		if (isCylindrical()) {
			return { x: 0, y: 0, width: Number(item.print_area.width), height: Number(item.print_area.height) };
		}
		return {
			x: Number(item.workspace.x),
			y: Number(item.workspace.y),
			width: Number(item.workspace.width),
			height: Number(item.workspace.height),
		};
	}
	function editingDimensions(item) {
		const area = workArea(item);
		return isCylindrical() ? { width: area.width, height: area.height } : { width: Number(item.width), height: Number(item.height) };
	}
	function baseImageBox(item) {
		return item.base_image_transform || { x: 0, y: 0, width: item.width, height: item.height };
	}
	function uid(prefix) {
		return prefix + '-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
	}
	function variationId() {
		const input = document.querySelector('form.cart input[name="variation_id"]');
		return input ? Number(input.value || boot.initialVariationId || 0) : Number(boot.initialVariationId || 0);
	}
	function announce(message, error) {
		dom.message.textContent = message || '';
		dom.message.classList.toggle('is-error', Boolean(error));
	}
	function mobileEvent(event, payload) {
		const message = { source: 'flexible-product-customizer', event, payload: payload || {}, bridge: boot.bridge || {} };
		window.dispatchEvent(new CustomEvent('fpcw:' + event, { detail: message }));
		if (window.ReactNativeWebView && typeof window.ReactNativeWebView.postMessage === 'function') {
			window.ReactNativeWebView.postMessage(JSON.stringify(message));
		}
		if (window.parent !== window) window.parent.postMessage(message, window.location.origin);
	}

	async function api(path, options) {
		const request = Object.assign({ credentials: 'same-origin', headers: {} }, options || {});
		request.headers['X-WP-Nonce'] = boot.nonce;
		if (request.body && !(request.body instanceof FormData)) request.headers['Content-Type'] = 'application/json';
		const response = await fetch(restEndpoint(path), request);
		let result = {};
		try { result = await response.json(); } catch (e) { result = {}; }
		if (!response.ok) throw new Error(result.message || 'HTTP ' + response.status);
		return result;
	}

	function restEndpoint(path) {
		const cleanPath = String(path || '').replace(/^\/+/, '');
		const base = boot.restUrl || '';
		try {
			const url = new URL(base, window.location.href);
			const restRoute = url.searchParams.get('rest_route');
			if (restRoute !== null) {
				url.searchParams.set('rest_route', restRoute.replace(/\/+$/, '') + (cleanPath ? '/' + cleanPath : ''));
				return url.toString();
			}
			url.pathname = url.pathname.replace(/\/+$/, '') + (cleanPath ? '/' + cleanPath : '');
			return url.toString();
		} catch (error) {
			return base.replace(/\/+$/, '') + (cleanPath ? '/' + cleanPath : '');
		}
	}

	function initDom() {
		if (initialized) return true;
		dom.modal = el('fpcw-editor-modal');
		dom.canvas = el('fpcw-canvas');
		if (!dom.modal || !dom.canvas || !el('fpcw-open-editor')) return false;
		if (dom.modal.parentElement !== document.body) document.body.appendChild(dom.modal);
		dom.ctx = dom.canvas.getContext('2d');
		dom.shell = el('fpcw-canvas-shell');
		dom.stageCanvases = el('fpcw-stage-canvases');
		dom.editPanel = el('fpcw-edit-panel');
		dom.projectionPanel = el('fpcw-projection-panel');
		dom.projectionShell = el('fpcw-projection-shell');
		dom.mockupCanvas = el('fpcw-mockup-canvas');
		dom.mockupCtx = dom.mockupCanvas ? dom.mockupCanvas.getContext('2d') : null;
		dom.projectionCanvas = el('fpcw-projection-canvas');
		dom.previewAngles = el('fpcw-preview-angle-controls');
		dom.viewModes = el('fpcw-view-modes');
		dom.viewEdit = el('fpcw-view-edit');
		dom.viewWrapped = el('fpcw-view-wrapped');
		dom.loading = el('fpcw-loading');
		dom.message = el('fpcw-editor-message');
		dom.expiry = el('fpcw-expiry-line');
		dom.token = el('fpcw-token');
		dom.summary = el('fpcw-saved-summary');
		dom.selection = el('fpcw-selection-controls');
		dom.selectionAnchor = el('fpcw-selection-anchor');
		dom.textControls = el('fpcw-text-controls');
		dom.textInput = el('fpcw-text-input');
		dom.font = el('fpcw-font-family');
		dom.fontSize = el('fpcw-font-size');
		dom.textColor = el('fpcw-text-color');
		dom.outlineColor = el('fpcw-outline-color');
		dom.outlineAdjustment = el('fpcw-outline-adjustment');
		dom.outlineWidth = el('fpcw-outline-width');
		dom.outlineWidthValue = el('fpcw-outline-width-value');
		dom.productPreviews = el('fpcw-product-previews');
		dom.productPreviewList = el('fpcw-product-preview-list');
		dom.surfaceOverview = el('fpcw-surface-overview');
		dom.priceExtras = Array.from(document.querySelectorAll('[data-fpcw-price-extra-live]'));
		dom.addAnother = el('fpcw-add-another');
		dom.cartForm = document.querySelector('form.cart');
		dom.cartButton = dom.cartForm ? dom.cartForm.querySelector('.single_add_to_cart_button') : null;
		initialized = true;

		config.surfaces.forEach((item) => surfaceDesign(item.id));
		if (!availableSurfaces().some((item) => item.id === state.surfaceId)) state.surfaceId = availableSurfaces()[0] ? availableSurfaces()[0].id : '';
		installFontFaces();
		bindEvents();
		renderStaticControls();
		if (config.color_attribute) {
			const selector = document.querySelector('select[name="attribute_' + config.color_attribute + '"]');
			if (selector && selector.value) syncColorFromVariation(selector.value);
		}
		configureCanvas();
		updateCartButton();
		mobileEvent('ready', { product_id: boot.productId });
		return true;
	}

	function bindEvents() {
		const launcher = el('fpcw-open-editor');
		if (launcher) launcher.addEventListener('click', open);
		document.querySelectorAll('[data-fpcw-close]').forEach((button) => button.addEventListener('click', close));
		el('fpcw-add-image').addEventListener('click', () => el('fpcw-image-input').click());
		el('fpcw-image-input').addEventListener('change', uploadImage);
		el('fpcw-add-text').addEventListener('click', addText);
		el('fpcw-save').addEventListener('click', save);
		if (dom.addAnother) dom.addAnother.addEventListener('click', addAnotherCustomization);
		el('fpcw-delete').addEventListener('click', deleteSelected);
		el('fpcw-rotate').addEventListener('click', rotateSelected);
		el('fpcw-fit').addEventListener('click', fitSelected);
		if (dom.viewEdit) dom.viewEdit.addEventListener('click', () => setViewMode('edit'));
		if (dom.viewWrapped) dom.viewWrapped.addEventListener('click', () => setViewMode('wrapped'));
		dom.textInput.addEventListener('input', updateSelectedText);
		dom.font.addEventListener('change', updateTextStyle);
		dom.fontSize.addEventListener('input', updateTextStyle);
		dom.textColor.addEventListener('input', updateTextStyle);
		dom.outlineColor.addEventListener('input', updateTextStyle);
		if (dom.outlineWidth) dom.outlineWidth.addEventListener('input', updateTextStyle);
		['bold', 'italic', 'underline', 'outline'].forEach((style) => {
			el('fpcw-' + style).addEventListener('click', () => toggleTextStyle(style));
		});
		el('fpcw-align').addEventListener('click', cycleAlignment);
		dom.canvas.addEventListener('pointerdown', pointerDown);
		dom.canvas.addEventListener('pointermove', pointerMove);
		dom.canvas.addEventListener('pointerup', pointerUp);
		dom.canvas.addEventListener('pointercancel', pointerUp);
		dom.canvas.addEventListener('keydown', keyDown);
		if (dom.projectionCanvas) {
			dom.projectionCanvas.addEventListener('pointerdown', projectionPointerDown);
			dom.projectionCanvas.addEventListener('pointermove', projectionPointerMove);
			dom.projectionCanvas.addEventListener('pointerup', projectionPointerUp);
			dom.projectionCanvas.addEventListener('pointercancel', projectionPointerUp);
			dom.projectionCanvas.addEventListener('keydown', projectionKeyDown);
		}
		window.addEventListener('fpcw:cylindrical-preview-ready', () => {
			projectionUnavailable = false;
			render();
		});
		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && isModalOpen() && typeof dom.modal.showModal !== 'function') close();
		});
		dom.modal.addEventListener('cancel', (event) => {
			event.preventDefault();
			close();
		});

		if (config.color_attribute) {
			const selector = document.querySelector('select[name="attribute_' + config.color_attribute + '"]');
			if (selector) selector.addEventListener('change', () => syncColorFromVariation(selector.value));
		}
		window.addEventListener('message', receiveBridgeMessage);
		window.addEventListener('resize', positionSelectionTools);
		document.addEventListener('change', (event) => {
			if (event.target.matches('form.variations_form select, form.cart input[name="variation_id"]')) window.setTimeout(updateCartButton, 0);
		});
		if (window.jQuery && dom.cartForm) {
			window.jQuery(dom.cartForm).on('found_variation reset_data hide_variation', () => window.setTimeout(updateCartButton, 0));
		}
		if (dom.cartForm) {
			dom.cartForm.addEventListener('submit', preventIncompleteCart, true);
			dom.cartForm.addEventListener('click', preventIncompleteCart, true);
		}
		const mobileQuery = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 820px)') : null;
		if (mobileQuery) {
			if (typeof mobileQuery.addEventListener === 'function') mobileQuery.addEventListener('change', placeSelectionControls);
			else if (typeof mobileQuery.addListener === 'function') mobileQuery.addListener(placeSelectionControls);
		}
		placeSelectionControls();
	}

	function renderStaticControls() {
		const colors = el('fpcw-color-control');
		colors.innerHTML = '<label>' + boot.i18n.color + '</label><div class="fpcw-swatches"></div>';
		const colorList = colors.querySelector('.fpcw-swatches');
		config.colors.forEach((color) => {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'fpcw-swatch';
			button.style.setProperty('--swatch', color.hex);
			button.dataset.colorId = color.id;
			button.title = color.label;
			button.setAttribute('aria-label', color.label);
			button.addEventListener('click', () => setColor(color.id, true));
			colorList.appendChild(button);
		});

		renderSurfaceTabs();
		renderSurfaceOverview();
		renderPreviewAngleControls();

		dom.font.innerHTML = '';
		config.fonts.forEach((font) => {
			const option = document.createElement('option');
			option.value = font;
			option.textContent = font;
			dom.font.appendChild(option);
		});

		const old = document.querySelector('.fpcw-text-swatches');
		if (old) old.remove();
		dom.textColor.hidden = false;
		updateViewControls();
		refreshControls();
	}

	function updateViewControls() {
		const cylindrical = isCylindrical();
		if (!cylindrical) state.viewMode = 'edit';
		if (dom.viewModes) dom.viewModes.hidden = !cylindrical;
		if (dom.projectionPanel) dom.projectionPanel.hidden = !cylindrical;
		if (dom.stageCanvases) {
			dom.stageCanvases.classList.toggle('is-cylindrical', cylindrical);
			dom.stageCanvases.dataset.view = state.viewMode;
		}
		if (dom.viewEdit) dom.viewEdit.setAttribute('aria-pressed', String(state.viewMode === 'edit'));
		if (dom.viewWrapped) dom.viewWrapped.setAttribute('aria-pressed', String(state.viewMode === 'wrapped'));
	}

	function setViewMode(mode) {
		state.viewMode = isCylindrical() && mode === 'wrapped' ? 'wrapped' : 'edit';
		state.interaction = null;
		updateViewControls();
		refreshControls();
		render();
		const target = state.viewMode === 'wrapped' ? dom.projectionCanvas : dom.canvas;
		if (target) target.focus();
		mobileEvent('viewChanged', { view: state.viewMode, surface_id: state.surfaceId });
	}

	function renderSurfaceTabs() {
		const tabs = el('fpcw-surface-tabs');
		tabs.innerHTML = '';
		availableSurfaces().forEach((item) => {
			const button = document.createElement('button');
			button.type = 'button';
			button.role = 'tab';
			button.textContent = item.label + (Number(item.price_increment) > 0 ? ' (+' + item.price_display + ')' : '');
			button.dataset.surfaceId = item.id;
			button.addEventListener('click', () => setSurface(item.id));
			tabs.appendChild(button);
		});
	}

	function usedSurfaces() {
		return availableSurfaces().filter((item) => surfaceDesign(item.id).objects.length > 0);
	}

	function renderSurfaceOverview() {
		if (!dom.surfaceOverview) return;
		const items = availableSurfaces();
		const key = state.colorId + ':' + items.map((item) => item.id).join('|');
		if (dom.surfaceOverview.dataset.key !== key) {
			dom.surfaceOverview.dataset.key = key;
			dom.surfaceOverview.innerHTML = '';
			items.forEach((item) => {
				const button = document.createElement('button');
				button.type = 'button';
				button.className = 'fpcw-surface-card';
				button.dataset.surfaceId = item.id;
				button.addEventListener('click', () => setSurface(item.id));
				button.appendChild(document.createElement('canvas'));
				const label = document.createElement('span');
				label.textContent = item.label;
				button.appendChild(label);
				dom.surfaceOverview.appendChild(button);
			});
		}
		items.forEach((item) => {
			const button = dom.surfaceOverview.querySelector('[data-surface-id="' + item.id + '"]');
			if (!button) return;
			const canvas = button.querySelector('canvas');
			const dimensions = editingDimensions(item);
			canvas.width = 120;
			canvas.height = Math.max(48, Math.round(120 * dimensions.height / Math.max(1, dimensions.width)));
			drawSurfaceOverview(canvas, item);
			button.setAttribute('aria-pressed', String(item.id === state.surfaceId));
			button.classList.toggle('is-used', surfaceDesign(item.id).objects.length > 0);
		});
	}

	function drawSurfaceOverview(canvas, item) {
		const ctx = canvas.getContext('2d');
		if (!ctx) return;
		const dimensions = editingDimensions(item);
		const scale = Math.min(canvas.width / Math.max(1, dimensions.width), canvas.height / Math.max(1, dimensions.height));
		const width = dimensions.width * scale;
		const height = dimensions.height * scale;
		ctx.save();
		ctx.clearRect(0, 0, canvas.width, canvas.height);
		ctx.translate((canvas.width - width) / 2, (canvas.height - height) / 2);
		ctx.scale(scale, scale);
		if (isCylindrical()) drawScene(ctx, item, { base: false, printMap: true, objects: true, guides: false, selection: false });
		else drawScene(ctx, item, { base: true, objects: true, guides: false, selection: false });
		ctx.restore();
	}

	function previewViews(item) {
		if (!isCylindrical() || !item || !item.projection) return [{ id: 'default', label: item ? item.label : '', rotation: 0, enabled: true }];
		const views = normalizePreviewViews(item.projection.preview_views).filter((view) => view.enabled !== false);
		return views.length ? views : [{ id: 'front', label: boot.i18n.frontView || 'Front view', rotation: 0, enabled: true }];
	}

	function renderPreviewAngleControls() {
		if (!dom.previewAngles) return;
		const item = surface();
		if (!isCylindrical() || !item) {
			dom.previewAngles.hidden = true;
			dom.previewAngles.innerHTML = '';
			return;
		}
		const current = Math.round(projectionRotation(item));
		dom.previewAngles.hidden = false;
		dom.previewAngles.innerHTML = '';
		previewViews(item).forEach((view) => {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'fpcw-angle-button';
			button.textContent = view.label;
			button.dataset.rotation = String(view.rotation);
			button.dataset.viewId = view.id;
			button.setAttribute('aria-pressed', String(Math.round(view.rotation) === current));
			button.addEventListener('click', () => {
				setProjectionRotation(item, view.rotation);
				renderPreviewAngleControls();
				renderCylindrical(item);
				mobileEvent('previewAngleChanged', { surface_id: item.id, view_id: view.id, rotation: view.rotation });
			});
			dom.previewAngles.appendChild(button);
		});
	}

	function installFontFaces() {
		const previous = el('fpcw-custom-font-faces');
		if (previous) previous.remove();
		if (!Array.isArray(config.font_faces) || !config.font_faces.length) return;
		const formats = { woff2: 'woff2', woff: 'woff', ttf: 'truetype', otf: 'opentype' };
		const rules = config.font_faces.filter((font) => font.family && font.url).map((font) => {
			const family = String(font.family).replace(/[\\']/g, '\\$&');
			const url = String(font.url).replace(/[\\']/g, '\\$&');
			return `@font-face{font-family:'${family}';src:url('${url}') format('${formats[font.format] || font.format || 'woff2'}');font-display:swap;}`;
		});
		if (!rules.length) return;
		const style = document.createElement('style');
		style.id = 'fpcw-custom-font-faces';
		style.textContent = rules.join('\n');
		document.head.appendChild(style);
	}

	async function open() {
		if (state.busy) return;
		const currentVariation = variationId();
		if (boot.isVariable && !currentVariation && !state.token) {
			dom.summary.hidden = false;
			dom.summary.classList.add('is-error');
			dom.summary.textContent = boot.i18n.chooseOptions;
			return;
		}
		showModalLayer();
		document.documentElement.classList.add('fpcw-modal-open');
		document.body.classList.add('fpcw-modal-open');
		dom.loading.hidden = false;
		announce('');
		try {
			if (!state.ready) {
				const response = state.token
					? await api('/sessions/' + state.token, { method: 'GET' })
					: await api('/sessions', { method: 'POST', body: JSON.stringify({ product_id: boot.productId, variation_id: currentVariation }) });
				hydrateSession(response);
			}
			await ensureSceneImages();
			state.ready = true;
			configureCanvas();
			render();
			mobileEvent('opened', { token: state.token, expires_at: state.expiresDisplay });
		} catch (error) {
			announce(error.message, true);
			mobileEvent('error', { message: error.message });
		} finally {
			dom.loading.hidden = true;
		}
	}

	function close() {
		if (state.busy) return;
		hideModalLayer();
		document.documentElement.classList.remove('fpcw-modal-open');
		document.body.classList.remove('fpcw-modal-open');
		const launcher = el('fpcw-open-editor');
		if (launcher) window.setTimeout(() => launcher.focus(), 0);
		mobileEvent('closed', { token: state.token });
	}

	function isModalOpen() {
		return Boolean(dom.modal && (dom.modal.open || dom.modal.hasAttribute('open')));
	}

	function showModalLayer() {
		if (typeof dom.modal.showModal === 'function') {
			if (!dom.modal.open) dom.modal.showModal();
			return;
		}
		dom.modal.setAttribute('open', '');
	}

	function hideModalLayer() {
		if (typeof dom.modal.close === 'function' && dom.modal.open) dom.modal.close();
		else dom.modal.removeAttribute('open');
	}

	function hydrateSession(response) {
		state.token = response.token;
		state.expiresDisplay = response.expires_display;
		state.variationId = Number(response.variation_id || 0);
		state.inCart = response.status === 'cart';
		const snapshot = response.payload.template_snapshot || config;
		const sourceSchemaVersion = Number(snapshot.schema_version || 1);
		config = normalizeConfig(snapshot);
		state.viewMode = 'edit';
		state.previewRotations.clear();
		state.designs.clear();
		config.surfaces.forEach((item) => surfaceDesign(item.id));
		const design = response.payload.design || {};
		if (design.color_id) state.colorId = design.color_id;
		if (!config.colors.some((color) => color.id === state.colorId)) state.colorId = config.colors[0] ? config.colors[0].id : '';
		if (!availableSurfaces().some((item) => item.id === state.surfaceId)) state.surfaceId = availableSurfaces()[0] ? availableSurfaces()[0].id : '';
		if (Array.isArray(design.surfaces)) {
			design.surfaces.forEach((item) => {
				const objects = Array.isArray(item.objects) ? item.objects : [];
				if (sourceSchemaVersion < 6 && isCylindrical()) {
					const legacySurface = config.surfaces.find((surfaceItem) => surfaceItem.id === item.id);
					const legacyArea = legacySurface && legacySurface.workspace ? legacySurface.workspace : { x: 0, y: 0 };
					objects.forEach((object) => {
						object.x = Number(object.x || 0) - Number(legacyArea.x || 0);
						object.y = Number(object.y || 0) - Number(legacyArea.y || 0);
					});
				}
				state.designs.set(item.id, { id: item.id, objects });
			});
		}
		state.uploads.clear();
		(response.payload.uploads || []).forEach((file) => state.uploads.set(file.id, file));
		dom.expiry.textContent = boot.i18n.expires.replace('%s', response.expires_display);
		if (response.status === 'active') markSaved(response);
		installFontFaces();
		renderStaticControls();
	}

	function markSaved(response) {
		dom.token.value = response.token;
		dom.summary.hidden = false;
		dom.summary.classList.remove('is-error');
		dom.summary.textContent = boot.i18n.saved + '. ' + boot.i18n.expires.replace('%s', response.expires_display);
		if (dom.addAnother) dom.addAnother.hidden = false;
		renderSavedPreviews(response.payload.previews || []);
		updateLiveSurfaceExtras();
		updateCartButton();
	}

	function setSurface(id) {
		if (!availableSurfaces().some((item) => item.id === id)) return;
		state.surfaceId = id;
		state.selectedId = '';
		configureCanvas();
		refreshControls();
		render();
	}

	function setColor(id, synchronize) {
		if (!config.colors.some((color) => color.id === id)) return;
		if (state.inCart && config.color_attribute && id !== state.colorId) {
			announce(boot.i18n.cartColorLocked, true);
			return;
		}
		if (state.colorId !== id) markDirty();
		state.colorId = id;
		if (synchronize && config.color_attribute) {
			const color = config.colors.find((item) => item.id === id);
			const selector = document.querySelector('select[name="attribute_' + config.color_attribute + '"]');
			if (selector && color && color.variation_value && selector.value !== color.variation_value) {
				selector.value = color.variation_value;
				selector.dispatchEvent(new Event('change', { bubbles: true }));
			}
		}
		if (!availableSurfaces().some((item) => item.id === state.surfaceId)) state.surfaceId = availableSurfaces()[0] ? availableSurfaces()[0].id : '';
		renderSurfaceTabs();
		renderSurfaceOverview();
		updateLiveSurfaceExtras();
		configureCanvas();
		refreshControls();
		ensureSceneImages().then(render);
	}

	function syncColorFromVariation(value) {
		const color = config.colors.find((item) => item.variation_value === value);
		if (color) setColor(color.id, false);
	}

	function configureCanvas() {
		const item = surface();
		if (!item) return;
		const dimensions = editingDimensions(item);
		dom.canvas.width = dimensions.width;
		dom.canvas.height = dimensions.height;
		dom.shell.style.aspectRatio = dimensions.width + ' / ' + dimensions.height;
		if (dom.mockupCanvas) {
			dom.mockupCanvas.width = item.width;
			dom.mockupCanvas.height = item.height;
		}
		if (dom.projectionShell) dom.projectionShell.style.aspectRatio = item.width + ' / ' + item.height;
		updateViewControls();
		renderPreviewAngleControls();
		refreshControls();
	}

	function refreshControls() {
		document.querySelectorAll('[data-surface-id]').forEach((button) => {
			const active = button.dataset.surfaceId === state.surfaceId;
			button.classList.toggle('is-active', active);
			button.setAttribute('aria-selected', String(active));
		});
		document.querySelectorAll('[data-color-id]').forEach((button) => {
			const active = button.dataset.colorId === state.colorId;
			button.classList.toggle('is-active', active);
			button.setAttribute('aria-pressed', String(active));
		});
		const item = surface();
		el('fpcw-add-image').disabled = !item || !item.allow_images;
		el('fpcw-add-text').disabled = !item || !item.allow_text;

		const object = state.viewMode === 'edit' ? selected() : null;
		const objectId = object ? object.id : '';
		if (state.controlsObjectId !== objectId) {
			state.controlsObjectId = objectId;
			state.outlineToolsOpen = false;
		}
		dom.selection.hidden = !object;
		dom.textControls.hidden = !object || object.type !== 'text';
		dom.selection.classList.toggle('has-text-selection', Boolean(object && object.type === 'text'));
		if (dom.outlineAdjustment) {
			dom.outlineAdjustment.hidden = !object || object.type !== 'text' || (isMobileLayout() && !state.outlineToolsOpen);
		}
		if (object && object.type === 'text') {
			dom.textInput.value = object.text;
			dom.font.value = object.font;
			dom.fontSize.value = Math.round(object.font_size);
			dom.textColor.value = object.color;
			dom.outlineColor.value = object.outline_color || '#ffffff';
			if (dom.outlineWidth) dom.outlineWidth.value = Math.max(1, Math.min(20, Number(object.outline_width || 3)));
			if (dom.outlineWidthValue) dom.outlineWidthValue.value = dom.outlineWidth ? dom.outlineWidth.value : '3';
			['bold', 'italic', 'underline', 'outline'].forEach((style) => {
				el('fpcw-' + style).setAttribute('aria-pressed', String(Boolean(object[style])));
			});
		}
		placeSelectionControls();
		if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(positionSelectionTools);
		else positionSelectionTools();
	}

	function isMobileLayout() {
		return typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 820px)').matches : window.innerWidth <= 820;
	}

	function placeSelectionControls() {
		if (!dom.selection || !dom.selectionAnchor || !dom.shell) return;
		const floating = isMobileLayout();
		const parent = floating ? dom.shell : dom.selectionAnchor;
		if (dom.selection.parentElement !== parent) parent.appendChild(dom.selection);
		dom.selection.classList.toggle('fpcw-selection-floating', floating);
		if (!floating) {
			dom.selection.style.left = '';
			dom.selection.style.top = '';
		}
	}

	function positionSelectionTools() {
		if (!isMobileLayout() || !dom.selection || dom.selection.hidden || !selected() || !surface()) return;
		const shellRect = dom.shell.getBoundingClientRect();
		const canvasRect = dom.canvas.getBoundingClientRect();
		if (!shellRect.width || !canvasRect.width) return;
		const bounds = objectBounds(selected());
		const scaleX = canvasRect.width / dom.canvas.width;
		const scaleY = canvasRect.height / dom.canvas.height;
		const width = Math.min(dom.selection.offsetWidth || 320, Math.max(0, shellRect.width - 16));
		const height = dom.selection.offsetHeight || 120;
		const center = canvasRect.left - shellRect.left + (bounds.left + bounds.width / 2) * scaleX;
		let left = center - width / 2;
		let top = canvasRect.top - shellRect.top + bounds.top * scaleY - height - 10;
		if (top < 8) top = canvasRect.top - shellRect.top + bounds.bottom * scaleY + 10;
		left = Math.max(8, Math.min(shellRect.width - width - 8, left));
		top = Math.max(8, Math.min(shellRect.height - height - 8, top));
		dom.selection.style.left = `${Math.round(left)}px`;
		dom.selection.style.top = `${Math.round(top)}px`;
	}

	function customizationReady() {
		if (!dom.token || !dom.token.value) return false;
		if (!boot.isVariable) return true;
		return variationId() > 0 && variationId() === Number(state.variationId || 0);
	}

	function updateCartButton() {
		if (!dom.cartForm) return;
		dom.cartButton = dom.cartForm.querySelector('.single_add_to_cart_button');
		if (!dom.cartButton) return;
		const ready = customizationReady();
		const variationContainer = dom.cartButton.closest('.woocommerce-variation-add-to-cart');
		const variationPurchasable = !boot.isVariable || !variationContainer || variationContainer.classList.contains('woocommerce-variation-add-to-cart-enabled');
		const enabled = ready && variationPurchasable;
		dom.cartButton.disabled = !enabled;
		dom.cartButton.classList.toggle('disabled', !enabled);
		dom.cartButton.setAttribute('aria-disabled', String(!enabled));
		dom.cartButton.dataset.fpcwLocked = ready ? '0' : '1';
		dom.cartButton.title = ready ? '' : (dom.token.value && boot.isVariable ? boot.i18n.variationChanged : boot.i18n.customizationRequired);
		if (dom.token.value && boot.isVariable && variationId() !== Number(state.variationId || 0)) {
			dom.summary.hidden = false;
			dom.summary.classList.add('is-error');
			dom.summary.textContent = boot.i18n.variationChanged;
		}
	}

	function preventIncompleteCart(event) {
		const isButton = event.type !== 'click' || event.target.closest('.single_add_to_cart_button');
		if (!isButton || customizationReady()) return;
		event.preventDefault();
		event.stopPropagation();
		dom.summary.hidden = false;
		dom.summary.classList.add('is-error');
		dom.summary.textContent = dom.token.value && boot.isVariable ? boot.i18n.variationChanged : boot.i18n.customizationRequired;
	}

	function renderSavedPreviews(previews) {
		if (!dom.productPreviews || !dom.productPreviewList) return;
		dom.productPreviewList.innerHTML = '';
		(previews || []).forEach((preview) => {
			if (!preview.url) return;
			const item = config.surfaces.find((surfaceItem) => surfaceItem.id === preview.surface_id);
			const label = [item ? item.label : preview.surface_id, preview.view_label || ''].filter(Boolean).join(' - ');
			const link = document.createElement('a');
			link.href = preview.url;
			link.target = '_blank';
			link.rel = 'noopener';
			link.className = 'fpcw-preview-link';
			link.title = label;
			const image = document.createElement('img');
			image.src = preview.url;
			image.alt = label;
			image.loading = 'lazy';
			link.appendChild(image);
			dom.productPreviewList.appendChild(link);
		});
		dom.productPreviews.hidden = !dom.productPreviewList.children.length;
	}

	function markDirty() {
		if (!dom.token || !dom.token.value) return;
		dom.token.value = '';
		if (dom.productPreviews) dom.productPreviews.hidden = true;
		dom.summary.hidden = false;
		dom.summary.classList.add('is-error');
		dom.summary.textContent = boot.i18n.customizationRequired;
		updateCartButton();
	}

	function baseUrl(item) {
		const color = currentColor();
		return color && color.surfaces && color.surfaces[item.id] ? color.surfaces[item.id].image_url || '' : '';
	}
	function projectionLayerUrl(item, type) {
		return item && item.projection ? item.projection[type + '_image_url'] || '' : '';
	}

	function loadImage(url) {
		if (!url) return Promise.resolve(null);
		if (imageCache.has(url)) return imageCache.get(url).promise;
		const entry = { image: null, promise: null };
		entry.promise = new Promise((resolve, reject) => {
			const image = new Image();
			image.onload = () => { entry.image = image; resolve(image); };
			image.onerror = () => reject(new Error(boot.i18n.imageLoadError));
			image.crossOrigin = 'anonymous';
			image.src = url;
		});
		imageCache.set(url, entry);
		return entry.promise;
	}

	async function ensureSceneImages() {
		const promises = [];
		config.surfaces.forEach((item) => {
			const url = baseUrl(item);
			if (url) promises.push(loadImage(url).catch(() => null));
			['mask', 'overlay'].forEach((type) => {
				const layerUrl = projectionLayerUrl(item, type);
				if (layerUrl) promises.push(loadImage(layerUrl).catch(() => null));
			});
		});
		state.uploads.forEach((file) => promises.push(loadImage(file.url).catch(() => null)));
		await Promise.all(promises);
	}

	async function cachedImage(url) {
		if (!url) return null;
		try { return await loadImage(url); } catch (e) { return null; }
	}

	function render() {
		const item = surface();
		if (!item || !dom.ctx) return;
		if (isCylindrical()) {
			drawScene(dom.ctx, item, { base: false, printMap: true, objects: true, guides: true, selection: true });
			drawMockupScene(dom.mockupCtx, item);
			renderCylindrical(item);
		} else {
			drawScene(dom.ctx, item, { base: true, objects: true, guides: true, selection: true });
		}
		const url = baseUrl(item);
		if (url) cachedImage(url).then(() => {
			if (surface() !== item) return;
			if (isCylindrical()) {
				drawMockupScene(dom.mockupCtx, item);
				renderCylindrical(item);
			} else {
				drawScene(dom.ctx, item, { base: true, objects: true, guides: true, selection: true });
			}
			renderSurfaceOverview();
		});
		['mask', 'overlay'].forEach((type) => {
			const layerUrl = projectionLayerUrl(item, type);
			if (layerUrl) cachedImage(layerUrl).then(() => { if (surface() === item && isCylindrical()) renderCylindrical(item); });
		});
		if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(positionSelectionTools);
		renderSurfaceOverview();
		updateLiveSurfaceExtras();
	}

	function ensureProjectionRenderer() {
		if (projectionRenderer || projectionUnavailable || !dom.projectionCanvas) return projectionRenderer;
		if (typeof window.FPCWCylindricalPreview !== 'function') return null;
		try {
			projectionRenderer = new window.FPCWCylindricalPreview(dom.projectionCanvas);
		} catch (error) {
			projectionUnavailable = true;
			announce(boot.i18n.previewUnavailable, true);
		}
		return projectionRenderer;
	}

	function projectionRotation(item) {
		return Number(state.previewRotations.get(item.id) || 0);
	}

	function projectionRotationLimit(item) {
		return Math.min(180, Math.max(45, (Number((item.projection || {}).wrap_angle || 180) - 180) / 2));
	}

	function setProjectionRotation(item, rotation, syncControls = true) {
		const limit = projectionRotationLimit(item);
		state.previewRotations.set(item.id, Math.max(-limit, Math.min(limit, Number(rotation) || 0)));
		if (syncControls) renderPreviewAngleControls();
	}

	function buildProjectionTexture(item) {
		const area = workArea(item);
		const max = 2048;
		const scale = Math.min(1, max / Math.max(area.width, area.height));
		projectionTextureCanvas.width = Math.max(1, Math.round(area.width * scale));
		projectionTextureCanvas.height = Math.max(1, Math.round(area.height * scale));
		const ctx = projectionTextureCanvas.getContext('2d');
		ctx.clearRect(0, 0, projectionTextureCanvas.width, projectionTextureCanvas.height);
		ctx.save();
		ctx.scale(scale, scale);
		ctx.translate(-area.x, -area.y);
		drawObjects(ctx, item);
		ctx.restore();
		return projectionTextureCanvas;
	}

	function renderCylindrical(item, maxSize, rotation) {
		const renderer = ensureProjectionRenderer();
		if (!renderer) {
			return null;
		}
		const maskEntry = imageCache.get(projectionLayerUrl(item, 'mask'));
		const overlayEntry = imageCache.get(projectionLayerUrl(item, 'overlay'));
		return renderer.render(buildProjectionTexture(item), item, rotation == null ? projectionRotation(item) : rotation, maxSize || 1400, {
			maskImage: maskEntry && maskEntry.image ? maskEntry.image : null,
			overlayImage: overlayEntry && overlayEntry.image ? overlayEntry.image : null,
			baseTransform: baseImageBox(item),
		});
	}

	function projectionPointerDown(event) {
		if (!isCylindrical() || !surface()) return;
		state.projectionInteraction = { startX: event.clientX, rotation: projectionRotation(surface()) };
		dom.projectionCanvas.setPointerCapture(event.pointerId);
	}

	function projectionPointerMove(event) {
		if (!state.projectionInteraction || !isCylindrical()) return;
		const item = surface();
		const rect = dom.projectionCanvas.getBoundingClientRect();
		const delta = (event.clientX - state.projectionInteraction.startX) / Math.max(1, rect.width) * 180;
		setProjectionRotation(item, state.projectionInteraction.rotation + delta, false);
		renderCylindrical(item);
	}

	function projectionPointerUp(event) {
		if (state.projectionInteraction && dom.projectionCanvas.hasPointerCapture(event.pointerId)) dom.projectionCanvas.releasePointerCapture(event.pointerId);
		state.projectionInteraction = null;
		renderPreviewAngleControls();
	}

	function projectionKeyDown(event) {
		if (!surface() || !['ArrowLeft', 'ArrowRight', 'Home'].includes(event.key)) return;
		event.preventDefault();
		const rotation = event.key === 'Home' ? 0 : projectionRotation(surface()) + (event.key === 'ArrowLeft' ? -5 : 5);
		setProjectionRotation(surface(), rotation);
		renderCylindrical(surface());
	}

	function drawScene(ctx, item, options) {
		const dimensions = editingDimensions(item);
		ctx.save();
		ctx.clearRect(0, 0, dimensions.width, dimensions.height);
		if (options.printMap) drawPrintMapBackground(ctx, item, dimensions);
		if (options.base) {
			const color = config.colors.find((entry) => entry.id === state.colorId);
			ctx.fillStyle = color ? color.hex : '#ffffff';
			ctx.fillRect(0, 0, dimensions.width, dimensions.height);
			const url = baseUrl(item);
			const entry = imageCache.get(url);
			const image = entry && entry.image;
			if (image) {
				const box = baseImageBox(item);
				ctx.drawImage(image, box.x, box.y, box.width, box.height);
			}
		}
		if (options.objects !== false) drawObjects(ctx, item);
		if (options.guides) drawWorkspace(ctx, item);
		if (options.selection && selected()) drawSelection(ctx, item, selected());
		ctx.restore();
	}

	function drawPrintMapBackground(ctx, item, dimensions) {
		const tile = Math.max(24, Math.round(Math.min(dimensions.width, dimensions.height) / 18));
		ctx.fillStyle = '#f7f8f9';
		ctx.fillRect(0, 0, dimensions.width, dimensions.height);
		ctx.fillStyle = '#e7eaed';
		for (let y = 0; y < dimensions.height; y += tile) {
			for (let x = 0; x < dimensions.width; x += tile) {
				if (((x / tile) + (y / tile)) % 2 === 0) ctx.fillRect(x, y, tile, tile);
			}
		}
		const angle = Number(item.projection.wrap_angle || 180);
		ctx.save();
		ctx.strokeStyle = 'rgba(20, 115, 230, .42)';
		ctx.fillStyle = '#174f87';
		ctx.lineWidth = Math.max(1, dimensions.width / 1200);
		ctx.font = `600 ${Math.max(11, Math.round(dimensions.height / 34))}px sans-serif`;
		for (let tick = 0; tick <= 4; tick++) {
			const x = dimensions.width * tick / 4;
			ctx.beginPath();
			ctx.moveTo(x, 0);
			ctx.lineTo(x, dimensions.height);
			ctx.stroke();
			ctx.textAlign = tick === 0 ? 'left' : (tick === 4 ? 'right' : 'center');
			ctx.textBaseline = 'top';
			ctx.fillText(`${Math.round(angle * tick / 4)}\u00b0`, x, Math.max(5, dimensions.height / 80));
		}
		ctx.restore();
	}

	function drawMockupScene(ctx, item) {
		if (!ctx) return;
		ctx.save();
		ctx.clearRect(0, 0, item.width, item.height);
		ctx.fillStyle = '#eef1f3';
		ctx.fillRect(0, 0, item.width, item.height);
		const url = baseUrl(item);
		const entry = imageCache.get(url);
		const image = entry && entry.image;
		if (image) {
			const box = baseImageBox(item);
			ctx.drawImage(image, box.x, box.y, box.width, box.height);
		}
		ctx.restore();
	}

	function workspacePath(ctx, item, area) {
		if (!isCylindrical() && item.shape === 'circle') {
			const radius = Math.min(area.width, area.height) / 2;
			ctx.ellipse(area.x + area.width / 2, area.y + area.height / 2, radius, radius, 0, 0, Math.PI * 2);
			return;
		}
		ctx.rect(area.x, area.y, area.width, area.height);
	}

	function clipWorkArea(ctx, item) {
		const area = workArea(item);
		ctx.beginPath();
		workspacePath(ctx, item, area);
		ctx.clip();
	}

	function drawObjects(ctx, item) {
		ctx.save();
		clipWorkArea(ctx, item);
		surfaceDesign(item.id).objects.forEach((object) => drawObject(ctx, object));
		ctx.restore();
	}

	function drawObject(ctx, object) {
		ctx.save();
		ctx.translate(object.x, object.y);
		ctx.rotate(object.rotation * Math.PI / 180);
		if (object.type === 'image') {
			const file = state.uploads.get(object.file_id);
			const entry = file ? imageCache.get(file.url) : null;
			const image = entry && entry.image;
			if (image) ctx.drawImage(image, -object.width / 2, -object.height / 2, object.width, object.height);
		} else if (object.type === 'text') {
			drawText(ctx, object);
		}
		ctx.restore();
	}

	function fontString(object) {
		return (object.italic ? 'italic ' : '') + (object.bold ? '700 ' : '400 ') + object.font_size + 'px "' + object.font.replace(/"/g, '') + '"';
	}

	function drawText(ctx, object) {
		const lines = String(object.text).split(/\r?\n/);
		const lineHeight = object.font_size * 1.2;
		ctx.font = fontString(object);
		ctx.fillStyle = object.color;
		if (object.outline) {
			ctx.strokeStyle = object.outline_color || '#ffffff';
			ctx.lineWidth = Math.max(1, Math.min(20, Number(object.outline_width || 3)));
			ctx.lineJoin = 'round';
		}
		ctx.textAlign = object.align;
		ctx.textBaseline = 'middle';
		const anchor = object.align === 'left' ? -object.width / 2 : (object.align === 'right' ? object.width / 2 : 0);
		lines.forEach((line, index) => {
			const y = (index - (lines.length - 1) / 2) * lineHeight;
			if (object.outline) ctx.strokeText(line, anchor, y);
			ctx.fillText(line, anchor, y);
			if (object.underline) {
				const width = ctx.measureText(line).width;
				let x = -width / 2;
				if (object.align === 'left') x = -object.width / 2;
				if (object.align === 'right') x = object.width / 2 - width;
				ctx.fillRect(x, y + object.font_size * 0.48, width, Math.max(1, object.font_size / 18));
			}
		});
	}

	function measureText(object) {
		measureContext.font = fontString(object);
		const lines = String(object.text).split(/\r?\n/);
		object.width = Math.max(1, ...lines.map((line) => measureContext.measureText(line || ' ').width)) + object.font_size * 0.2;
		object.height = Math.max(1, lines.length) * object.font_size * 1.2;
	}

	function drawWorkspace(ctx, item) {
		const area = workArea(item);
		const dimensions = editingDimensions(item);
		ctx.save();
		ctx.strokeStyle = '#1473e6';
		ctx.lineWidth = Math.max(2, dimensions.width / 500);
		ctx.setLineDash([dimensions.width / 100, dimensions.width / 140]);
		ctx.beginPath();
		workspacePath(ctx, item, area);
		ctx.stroke();
		ctx.restore();
	}

	function drawSelection(ctx, item, object) {
		const bounds = objectBounds(object);
		const handle = handleSize(item);
		const dimensions = editingDimensions(item);
		ctx.save();
		ctx.strokeStyle = '#111827';
		ctx.fillStyle = '#ffffff';
		ctx.lineWidth = Math.max(2, dimensions.width / 600);
		ctx.setLineDash([]);
		ctx.strokeRect(bounds.left, bounds.top, bounds.width, bounds.height);
		ctx.fillRect(bounds.right - handle / 2, bounds.bottom - handle / 2, handle, handle);
		ctx.strokeRect(bounds.right - handle / 2, bounds.bottom - handle / 2, handle, handle);
		ctx.restore();
	}

	function objectBounds(object) {
		const swap = object.rotation === 90 || object.rotation === 270;
		const width = swap ? object.height : object.width;
		const height = swap ? object.width : object.height;
		return { left: object.x - width / 2, top: object.y - height / 2, right: object.x + width / 2, bottom: object.y + height / 2, width, height };
	}

	function handleSize(item) { return Math.max(14, editingDimensions(item).width * 0.018); }

	async function uploadImage(event) {
		const file = event.target.files[0];
		event.target.value = '';
		if (!file) return;
		if (state.viewMode !== 'edit') setViewMode('edit');
		const item = surface();
		const count = surfaceDesign(item.id).objects.filter((object) => object.type === 'image').length;
		if (!item.allow_images || count >= item.max_images) return announce(boot.i18n.imageLimit, true);
		if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024) {
			return announce(boot.i18n.fileRules, true);
		}
		state.busy = true;
		dom.loading.hidden = false;
		try {
			const local = await inspectLocalImage(file);
			if (local.width > 10000 || local.height > 10000) throw new Error(boot.i18n.dimensionRules);
			const form = new FormData();
			form.append('file', file, file.name);
			const response = await api('/sessions/' + state.token + '/files', { method: 'POST', body: form });
			state.uploads.set(response.file.id, response.file);
			await loadImage(response.file.url);
			const object = {
				id: uid('image'), type: 'image', file_id: response.file.id,
				x: 0, y: 0, width: response.file.width, height: response.file.height, rotation: 0,
			};
			centerAndFit(object, item, 0.82);
			surfaceDesign(item.id).objects.push(object);
			state.selectedId = object.id;
			markDirty();
			refreshControls();
			render();
			announce('');
		} catch (error) {
			announce(error.message || boot.i18n.uploadError, true);
			mobileEvent('error', { message: error.message });
		} finally {
			state.busy = false;
			dom.loading.hidden = true;
		}
	}

	function inspectLocalImage(file) {
		return new Promise((resolve, reject) => {
			const url = URL.createObjectURL(file);
			const image = new Image();
			image.onload = () => { URL.revokeObjectURL(url); resolve({ width: image.naturalWidth, height: image.naturalHeight }); };
			image.onerror = () => { URL.revokeObjectURL(url); reject(new Error(boot.i18n.uploadError)); };
			image.src = url;
		});
	}

	function addText() {
		if (state.viewMode !== 'edit') setViewMode('edit');
		const item = surface();
		const text = dom.textInput.value.trim();
		const count = surfaceDesign(item.id).objects.filter((object) => object.type === 'text').length;
		if (!text) return dom.textInput.focus();
		if (!item.allow_text || count >= item.max_texts) return announce(boot.i18n.textLimit, true);
		const area = workArea(item);
		const object = {
			id: uid('text'), type: 'text', text: text.slice(0, 300), font: config.fonts[0], font_size: Math.max(24, Math.min(72, area.height / 7)),
			color: '#000000', outline_color: '#ffffff', outline_width: 3, bold: false, italic: false, underline: false, outline: false, align: 'center',
			x: area.x + area.width / 2, y: area.y + area.height / 2, width: 1, height: 1, rotation: 0,
		};
		measureText(object);
		centerAndFit(object, item, 0.9);
		surfaceDesign(item.id).objects.push(object);
		state.selectedId = object.id;
		markDirty();
		refreshControls();
		render();
		announce('');
	}

	function updateSelectedText() {
		const object = selected();
		if (!object || object.type !== 'text') return;
		object.text = dom.textInput.value.slice(0, 300) || ' ';
		markDirty();
		measureText(object);
		constrain(object, surface());
		render();
	}

	function updateTextStyle() {
		const object = selected();
		if (!object || object.type !== 'text') return;
		object.font = config.fonts.includes(dom.font.value) ? dom.font.value : config.fonts[0];
		object.font_size = Math.max(8, Math.min(300, Number(dom.fontSize.value || object.font_size)));
		object.color = dom.textColor.value;
		object.outline_color = dom.outlineColor.value;
		object.outline_width = Math.max(1, Math.min(20, Number((dom.outlineWidth && dom.outlineWidth.value) || object.outline_width || 3)));
		markDirty();
		if (dom.outlineWidthValue) dom.outlineWidthValue.value = String(Math.round(object.outline_width));
		measureText(object);
		constrain(object, surface());
		refreshControls();
		render();
	}

	function toggleTextStyle(style) {
		const object = selected();
		if (!object || object.type !== 'text') return;
		if (style === 'outline' && isMobileLayout() && object.outline && !state.outlineToolsOpen) {
			state.outlineToolsOpen = true;
			refreshControls();
			return;
		}
		object[style] = !object[style];
		if (style === 'outline') state.outlineToolsOpen = Boolean(object.outline);
		markDirty();
		measureText(object);
		constrain(object, surface());
		refreshControls();
		render();
	}

	function cycleAlignment() {
		const object = selected();
		if (!object || object.type !== 'text') return;
		const values = ['left', 'center', 'right'];
		object.align = values[(values.indexOf(object.align) + 1) % values.length];
		markDirty();
		const icon = el('fpcw-align').querySelector('.dashicons');
		icon.className = 'dashicons dashicons-align-' + object.align;
		render();
	}

	function rotateSelected() {
		const object = selected();
		if (!object) return;
		object.rotation = (object.rotation + 90) % 360;
		markDirty();
		constrain(object, surface());
		render();
	}

	function fitSelected() {
		const object = selected();
		if (!object) return;
		centerAndFit(object, surface(), 0.96);
		markDirty();
		refreshControls();
		render();
	}

	function deleteSelected() {
		const object = selected();
		if (!object || !window.confirm(boot.i18n.confirmRemove)) return;
		const design = surfaceDesign(state.surfaceId);
		design.objects = design.objects.filter((item) => item.id !== object.id);
		state.designs.set(state.surfaceId, design);
		state.selectedId = '';
		markDirty();
		refreshControls();
		render();
	}

	function centerAndFit(object, item, margin) {
		const area = workArea(item);
		const rotated = object.rotation === 90 || object.rotation === 270;
		const availableWidth = (rotated ? area.height : area.width) * margin;
		const availableHeight = (rotated ? area.width : area.height) * margin;
		const scale = Math.min(availableWidth / object.width, availableHeight / object.height);
		if (Number.isFinite(scale) && scale > 0) scaleObject(object, scale);
		object.x = area.x + area.width / 2;
		object.y = area.y + area.height / 2;
		constrain(object, item);
	}

	function scaleObject(object, scale) {
		if (object.type === 'text') {
			object.font_size = Math.max(8, Math.min(300, object.font_size * scale));
			measureText(object);
		} else {
			object.width *= scale;
			object.height *= scale;
		}
	}

	function constrain(object, item) {
		const area = workArea(item);
		let bounds = objectBounds(object);
		const maxBounds = { width: area.width * 4, height: area.height * 4 };
		if (bounds.width > maxBounds.width || bounds.height > maxBounds.height) {
			const scale = Math.min(maxBounds.width / bounds.width, maxBounds.height / bounds.height);
			scaleObject(object, scale);
			bounds = objectBounds(object);
		}
		const visibleX = Math.min(area.width * 0.18, Math.max(12, area.width * 0.04));
		const visibleY = Math.min(area.height * 0.18, Math.max(12, area.height * 0.04));
		object.x = Math.max(area.x + visibleX - bounds.width / 2, Math.min(area.x + area.width - visibleX + bounds.width / 2, object.x));
		object.y = Math.max(area.y + visibleY - bounds.height / 2, Math.min(area.y + area.height - visibleY + bounds.height / 2, object.y));
	}

	function canvasPoint(event) {
		const rect = dom.canvas.getBoundingClientRect();
		return { x: (event.clientX - rect.left) * dom.canvas.width / rect.width, y: (event.clientY - rect.top) * dom.canvas.height / rect.height };
	}

	function pointInObject(point, object) {
		const angle = -object.rotation * Math.PI / 180;
		const dx = point.x - object.x;
		const dy = point.y - object.y;
		const x = dx * Math.cos(angle) - dy * Math.sin(angle);
		const y = dx * Math.sin(angle) + dy * Math.cos(angle);
		return Math.abs(x) <= object.width / 2 && Math.abs(y) <= object.height / 2;
	}

	function pointerDown(event) {
		if (!state.ready) return;
		const point = canvasPoint(event);
		const current = selected();
		if (current) {
			const bounds = objectBounds(current);
			const size = handleSize(surface()) * 1.5;
			if (Math.abs(point.x - bounds.right) <= size && Math.abs(point.y - bounds.bottom) <= size) {
				state.interaction = { mode: 'resize', object: current, start: point, width: current.width, height: current.height, fontSize: current.font_size || 0 };
				dom.canvas.setPointerCapture(event.pointerId);
				return;
			}
		}
		const objects = surfaceDesign(state.surfaceId).objects;
		const hit = [...objects].reverse().find((object) => pointInObject(point, object));
		if (!hit) {
			state.selectedId = '';
			refreshControls();
			render();
			return;
		}
		state.selectedId = hit.id;
		state.interaction = { mode: 'drag', object: hit, offsetX: point.x - hit.x, offsetY: point.y - hit.y };
		dom.canvas.setPointerCapture(event.pointerId);
		refreshControls();
		render();
	}

	function pointerMove(event) {
		if (!state.interaction) return;
		markDirty();
		const point = canvasPoint(event);
		const action = state.interaction;
		if (action.mode === 'drag') {
			action.object.x = point.x - action.offsetX;
			action.object.y = point.y - action.offsetY;
			constrain(action.object, surface());
		} else {
			const dx = Math.abs(point.x - action.object.x) * 2;
			const dy = Math.abs(point.y - action.object.y) * 2;
			const swap = action.object.rotation === 90 || action.object.rotation === 270;
			const localWidth = swap ? dy : dx;
			const localHeight = swap ? dx : dy;
			const scale = Math.max(0.08, Math.min(localWidth / action.width, localHeight / action.height));
			if (action.object.type === 'text') {
				action.object.font_size = Math.max(8, Math.min(300, action.fontSize * scale));
				measureText(action.object);
			} else {
				action.object.width = action.width * scale;
				action.object.height = action.height * scale;
			}
			constrain(action.object, surface());
		}
		refreshControls();
		render();
	}

	function pointerUp(event) {
		if (state.interaction && dom.canvas.hasPointerCapture(event.pointerId)) dom.canvas.releasePointerCapture(event.pointerId);
		state.interaction = null;
	}

	function keyDown(event) {
		const object = selected();
		if (!object) return;
		if (event.key === 'Delete' || event.key === 'Backspace') {
			event.preventDefault();
			deleteSelected();
			return;
		}
		const movement = event.shiftKey ? 10 : 1;
		if (event.key === 'ArrowLeft') object.x -= movement;
		else if (event.key === 'ArrowRight') object.x += movement;
		else if (event.key === 'ArrowUp') object.y -= movement;
		else if (event.key === 'ArrowDown') object.y += movement;
		else return;
		event.preventDefault();
		markDirty();
		constrain(object, surface());
		render();
	}

	async function exportPreview(item, rotation) {
		await ensureSceneImages();
		const max = 1400;
		const scale = Math.min(1, max / Math.max(item.width, item.height));
		const canvas = document.createElement('canvas');
		canvas.width = Math.max(1, Math.round(item.width * scale));
		canvas.height = Math.max(1, Math.round(item.height * scale));
		const ctx = canvas.getContext('2d');
		ctx.scale(scale, scale);
		ctx.fillStyle = isCylindrical() ? '#eef1f3' : ((config.colors.find((entry) => entry.id === state.colorId) || {}).hex || '#ffffff');
		ctx.fillRect(0, 0, item.width, item.height);
		const image = await cachedImage(baseUrl(item));
		if (image) {
			const box = baseImageBox(item);
			ctx.drawImage(image, box.x, box.y, box.width, box.height);
		}
		if (isCylindrical()) {
			const projected = renderCylindrical(item, max, rotation);
			if (projected) ctx.drawImage(projected, 0, 0, item.width, item.height);
			else drawObjects(ctx, item);
		} else {
			drawObjects(ctx, item);
		}
		return canvasBlob(canvas);
	}

	async function exportProduction(item) {
		await ensureSceneImages();
		const area = workArea(item);
		const max = 3000;
		const scale = Math.min(1, max / Math.max(area.width, area.height));
		const canvas = document.createElement('canvas');
		canvas.width = Math.max(1, Math.round(area.width * scale));
		canvas.height = Math.max(1, Math.round(area.height * scale));
		const ctx = canvas.getContext('2d');
		ctx.scale(scale, scale);
		ctx.translate(-area.x, -area.y);
		drawObjects(ctx, item);
		return canvasBlob(canvas);
	}

	function canvasBlob(canvas) {
		return new Promise((resolve, reject) => canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error(boot.i18n.exportError)), 'image/png'));
	}

	function serializeDesign() {
		return {
			schema_version: 3,
			color_id: state.colorId,
			surfaces: usedSurfaces().map((item) => ({
				id: item.id,
				objects: surfaceDesign(item.id).objects.map((object) => Object.assign({}, object)),
			})),
		};
	}

	async function save() {
		if (state.busy || !state.token) return;
		const currentVariation = variationId() || state.variationId;
		if (boot.isVariable && !currentVariation) return announce(boot.i18n.chooseOptions, true);
		if (!availableSurfaces().some((item) => surfaceDesign(item.id).objects.length)) return announce(boot.i18n.emptyDesign, true);
		state.busy = true;
		dom.loading.hidden = false;
		el('fpcw-save').disabled = true;
		announce(boot.i18n.saving);
		try {
			for (const item of usedSurfaces()) {
				for (const view of previewViews(item)) {
					await uploadRender(item, 'preview', await exportPreview(item, view.rotation), view);
				}
				await uploadRender(item, 'production', await exportProduction(item));
			}
			const response = await api('/sessions/' + state.token + '/save', {
				method: 'POST',
				body: JSON.stringify({ design: serializeDesign(), variation_id: currentVariation }),
			});
			state.expiresDisplay = response.expires_display;
			state.variationId = Number(response.variation_id || 0);
			markSaved(response);
			announce(boot.i18n.saved);
			mobileEvent('saved', {
				token: response.token,
				proof: response.cart_proof,
				product_id: response.product_id,
				variation_id: response.variation_id,
				expires_at: response.expires_at,
				previews: (response.payload.previews || []).map((preview) => ({ surface_id: preview.surface_id, url: preview.url })),
			});
			setTimeout(close, 250);
		} catch (error) {
			announce(error.message || boot.i18n.saveError, true);
			mobileEvent('error', { message: error.message });
		} finally {
			state.busy = false;
			dom.loading.hidden = true;
			el('fpcw-save').disabled = false;
		}
	}

	async function uploadRender(item, kind, blob, view) {
		const form = new FormData();
		form.append('surface_id', item.id);
		form.append('kind', kind);
		if (view && kind === 'preview') {
			form.append('view_id', view.id);
			form.append('view_label', view.label);
			form.append('rotation', String(view.rotation));
		}
		form.append('file', blob, kind + '-' + item.id + (view && kind === 'preview' ? '-' + view.id : '') + '.png');
		return api('/sessions/' + state.token + '/renders', { method: 'POST', body: form });
	}

	function updateLiveSurfaceExtras() {
		if (!dom.priceExtras || !dom.priceExtras.length) return;
		const extras = usedSurfaces().filter((item) => Number(item.price_increment) > 0);
		const text = extras.map((item) => '+ ' + item.price_display + ' ' + (boot.i18n.extra || 'extra') + ' - ' + item.label).join('\n');
		dom.priceExtras.forEach((node) => {
			node.textContent = text;
			node.hidden = !text;
		});
	}

	function addAnotherCustomization() {
		if (state.busy) return;
		state.token = '';
		state.expiresDisplay = '';
		state.variationId = variationId() || 0;
		state.selectedId = '';
		state.ready = false;
		state.inCart = false;
		state.uploads.clear();
		state.previewRotations.clear();
		state.designs.clear();
		config.surfaces.forEach((item) => surfaceDesign(item.id));
		if (dom.token) dom.token.value = '';
		if (dom.productPreviewList) dom.productPreviewList.innerHTML = '';
		if (dom.productPreviews) dom.productPreviews.hidden = true;
		if (dom.addAnother) dom.addAnother.hidden = true;
		dom.summary.hidden = false;
		dom.summary.classList.add('is-error');
		dom.summary.textContent = boot.i18n.customizationRequired;
		renderSurfaceOverview();
		updateLiveSurfaceExtras();
		updateCartButton();
		open();
	}

	function receiveBridgeMessage(event) {
		if (event.origin && event.origin !== 'null' && event.origin !== window.location.origin) return;
		let data = event.data;
		if (typeof data === 'string') {
			try { data = JSON.parse(data); } catch (e) { return; }
		}
		if (!data || data.source !== 'flexible-product-customizer-host') return;
		if (data.command === 'open') open();
		if (data.command === 'save') save();
		if (data.command === 'setColor' && data.color_id) setColor(data.color_id, true);
		if (data.command === 'setSurface' && data.surface_id) setSurface(data.surface_id);
		if (data.command === 'setView' && data.view) setViewMode(data.view);
	}

	window.FlexibleProductCustomizer = { open, close, save, setColor, setSurface, setView: setViewMode };
	function start() {
		if (!initDom()) return;
		if (boot.editToken || boot.webview || boot.newCustomization) window.setTimeout(open, 50);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
	else start();
})();
