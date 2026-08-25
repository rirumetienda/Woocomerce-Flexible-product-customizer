import * as THREE from '../vendor/three-0.180.0/three.module.min.js';

const clamp = (value, min, max) => Math.max(min, Math.min(max, Number(value) || 0));

class CylindricalPreview {
	constructor(canvas) {
		if (!(canvas instanceof HTMLCanvasElement)) throw new Error('A canvas is required for the cylindrical preview.');
		this.canvas = canvas;
		this.outputContext = canvas.getContext('2d');
		this.webglCanvas = document.createElement('canvas');
		this.renderer = new THREE.WebGLRenderer({
			canvas: this.webglCanvas,
			alpha: true,
			antialias: true,
			preserveDrawingBuffer: true,
			powerPreference: 'low-power',
		});
		this.renderer.setClearColor(0x000000, 0);
		this.renderer.setPixelRatio(1);
		this.renderer.outputColorSpace = THREE.SRGBColorSpace;
		this.scene = new THREE.Scene();
		this.camera = new THREE.OrthographicCamera(0, 1, 0, -1, 0.1, 4000);
		this.camera.position.set(0, 0, 2000);
		this.camera.lookAt(0, 0, 0);

		this.ambient = new THREE.AmbientLight(0xffffff, 0.78);
		this.keyLight = new THREE.DirectionalLight(0xffffff, 0.42);
		this.keyLight.position.set(-0.35, 0.15, 1);
		this.scene.add(this.ambient, this.keyLight);

		this.mesh = null;
		this.geometryKey = '';
		this.texture = null;
		this.sourceCanvas = null;
	}

	normalize(surface) {
		const projection = surface && surface.projection ? surface.projection : {};
		return {
			wrapAngle: clamp(projection.wrap_angle || 180, 90, 360),
			topScale: clamp(projection.top_scale || 100, 50, 150) / 100,
			bottomScale: clamp(projection.bottom_scale || 100, 50, 150) / 100,
			shading: clamp(projection.shading == null ? 45 : projection.shading, 0, 100) / 100,
			frame: projection.frame || (surface && surface.workspace) || null,
		};
	}

	rotationLimit(surface) {
		const projection = this.normalize(surface);
		return Math.min(180, Math.max(45, (projection.wrapAngle - 180) / 2));
	}

	clampRotation(surface, rotation) {
		const limit = this.rotationLimit(surface);
		return clamp(rotation, -limit, limit);
	}

	ensureTexture(sourceCanvas) {
		if (this.sourceCanvas === sourceCanvas && this.texture) {
			this.texture.needsUpdate = true;
			return;
		}
		if (this.texture) this.texture.dispose();
		this.sourceCanvas = sourceCanvas;
		this.texture = new THREE.CanvasTexture(sourceCanvas);
		this.texture.colorSpace = THREE.SRGBColorSpace;
		this.texture.minFilter = THREE.LinearFilter;
		this.texture.magFilter = THREE.LinearFilter;
		this.texture.generateMipmaps = false;
		this.texture.needsUpdate = true;
	}

	ensureMesh(projection) {
		const segments = Math.max(48, Math.round(projection.wrapAngle / 360 * 128));
		const key = [projection.wrapAngle, projection.topScale, projection.bottomScale, segments].join(':');
		if (this.mesh && this.geometryKey === key) return;
		if (this.mesh) {
			this.scene.remove(this.mesh);
			this.mesh.geometry.dispose();
			this.mesh.material.dispose();
		}
		const angle = THREE.MathUtils.degToRad(projection.wrapAngle);
		const geometry = new THREE.CylinderGeometry(
			projection.topScale,
			projection.bottomScale,
			2,
			segments,
			1,
			true,
			-angle / 2,
			angle
		);
		const material = new THREE.MeshStandardMaterial({
			map: this.texture,
			transparent: true,
			alphaTest: 0.002,
			depthWrite: false,
			roughness: 0.82,
			metalness: 0,
			side: THREE.FrontSide,
		});
		this.mesh = new THREE.Mesh(geometry, material);
		this.scene.add(this.mesh);
		this.geometryKey = key;
	}

	render(sourceCanvas, surface, rotation, maxSize, layers = {}) {
		if (!surface || !sourceCanvas || !sourceCanvas.width || !sourceCanvas.height) return this.canvas;
		const projection = this.normalize(surface);
		const width = Math.max(1, Number(surface.width) || 1);
		const height = Math.max(1, Number(surface.height) || 1);
		const limit = Math.max(320, Number(maxSize) || 1400);
		const pixelScale = Math.min(1, limit / Math.max(width, height));
		const outputWidth = Math.max(1, Math.round(width * pixelScale));
		const outputHeight = Math.max(1, Math.round(height * pixelScale));
		this.renderer.setSize(outputWidth, outputHeight, false);
		if (this.canvas.width !== outputWidth) this.canvas.width = outputWidth;
		if (this.canvas.height !== outputHeight) this.canvas.height = outputHeight;

		this.camera.left = 0;
		this.camera.right = width;
		this.camera.top = 0;
		this.camera.bottom = -height;
		this.camera.updateProjectionMatrix();

		this.ensureTexture(sourceCanvas);
		this.ensureMesh(projection);
		this.mesh.material.map = this.texture;
		this.mesh.material.needsUpdate = true;

		const area = projection.frame || surface.workspace || { x: 0, y: 0, width, height };
		const halfAngle = THREE.MathUtils.degToRad(Math.min(180, projection.wrapAngle)) / 2;
		const visibleRadius = Math.max(projection.topScale, projection.bottomScale) * Math.max(0.01, Math.sin(halfAngle));
		const horizontalScale = Number(area.width) / (visibleRadius * 2);
		this.mesh.scale.set(horizontalScale, Number(area.height) / 2, horizontalScale);
		this.mesh.position.set(Number(area.x) + Number(area.width) / 2, -(Number(area.y) + Number(area.height) / 2), 0);
		this.mesh.rotation.y = THREE.MathUtils.degToRad(this.clampRotation(surface, rotation));

		this.ambient.intensity = 1 - projection.shading * 0.42;
		this.keyLight.intensity = projection.shading * 0.72;
		this.renderer.clear();
		this.renderer.render(this.scene, this.camera);
		this.compositeLayers(layers, pixelScale, outputWidth, outputHeight);
		return this.canvas;
	}

	compositeLayers(layers, pixelScale, width, height) {
		const ctx = this.outputContext;
		ctx.save();
		ctx.setTransform(1, 0, 0, 1, 0, 0);
		ctx.clearRect(0, 0, width, height);
		ctx.drawImage(this.webglCanvas, 0, 0, width, height);
		const box = layers.baseTransform || { x: 0, y: 0, width: width / pixelScale, height: height / pixelScale };
		if (layers.maskImage) {
			ctx.globalCompositeOperation = 'destination-in';
			ctx.drawImage(layers.maskImage, box.x * pixelScale, box.y * pixelScale, box.width * pixelScale, box.height * pixelScale);
		}
		if (layers.overlayImage) {
			ctx.globalCompositeOperation = 'source-atop';
			ctx.drawImage(layers.overlayImage, box.x * pixelScale, box.y * pixelScale, box.width * pixelScale, box.height * pixelScale);
		}
		ctx.restore();
	}

	dispose() {
		if (this.mesh) {
			this.mesh.geometry.dispose();
			this.mesh.material.dispose();
		}
		if (this.texture) this.texture.dispose();
		this.renderer.dispose();
	}
}

window.FPCWCylindricalPreview = CylindricalPreview;
window.dispatchEvent(new CustomEvent('fpcw:cylindrical-preview-ready'));
