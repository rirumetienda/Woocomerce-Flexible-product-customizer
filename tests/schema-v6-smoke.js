const fs = require('fs');

const template = fs.readFileSync('flexible-product-customizer/includes/class-template-manager.php', 'utf8');
const product = fs.readFileSync('flexible-product-customizer/includes/class-product-settings.php', 'utf8');
const validator = fs.readFileSync('flexible-product-customizer/includes/class-validator.php', 'utf8');
const rest = fs.readFileSync('flexible-product-customizer/includes/class-rest-controller.php', 'utf8');
const repository = fs.readFileSync('flexible-product-customizer/includes/class-repository.php', 'utf8');
const cart = fs.readFileSync('flexible-product-customizer/includes/class-cart-integration.php', 'utf8');
const activator = fs.readFileSync('flexible-product-customizer/includes/class-activator.php', 'utf8');
const plugin = fs.readFileSync('flexible-product-customizer/includes/class-plugin.php', 'utf8');
const library = fs.readFileSync('flexible-product-customizer/includes/class-template-library.php', 'utf8');
const libraryAssets = fs.readdirSync('flexible-product-customizer/assets/demo/library').filter((name) => name.endsWith('.png'));
const frontend = fs.readFileSync('flexible-product-customizer/includes/class-frontend.php', 'utf8');
const editor = fs.readFileSync('flexible-product-customizer/assets/js/editor.js', 'utf8');
const projector = fs.readFileSync('flexible-product-customizer/assets/js/cylindrical-preview.js', 'utf8');
const adminEditor = fs.readFileSync('flexible-product-customizer/assets/js/admin-template.js', 'utf8');
const sampleAsset = 'flexible-product-customizer/assets/demo/sample-white-mug.png';
const threeModule = 'flexible-product-customizer/assets/vendor/three-0.180.0/three.module.min.js';
const threeCore = 'flexible-product-customizer/assets/vendor/three-0.180.0/three.core.min.js';
const threeLicense = 'flexible-product-customizer/licenses/three-LICENSE.txt';

const requirements = [
	[/['"]schema_version['"]\s*=>\s*6/, template, 'Template schema v6 is missing.'],
	[/const DRAFT_TTL\s*=\s*HOUR_IN_SECONDS/, repository, 'Draft session TTL is not centralized at one hour.'],
	[/const CART_TTL\s*=\s*7 \* DAY_IN_SECONDS/, repository, 'Cart session TTL is not centralized at seven days.'],
	[/find_reusable_empty_draft_for_current_owner/, repository, 'Recent empty drafts are not reused before quota checks.'],
	[/delete_empty_drafts_for_current_owner/, repository, 'Empty draft cleanup before quota checks is missing.'],
	[/payload_has_customer_data/, repository, 'Draft cleanup is not protected from deleting customer content.'],
	[/status <> 'cart'[\s\S]{0,160}updated_at <= %s/, repository, 'Expired non-cart sessions are not found by inactivity.'],
	[/status IN \( 'draft', 'active' \)[\s\S]{0,160}updated_at > %s/, repository, 'Open-session quota is not limited to recent draft/active sessions.'],
	[/['"]product_type['"]/, template, 'Flat and cylindrical template types are missing.'],
	[/['"]print_area['"]/, template, 'Independent cylindrical print map dimensions are missing.'],
	[/['"]frame['"]\s*=>\s*\$this->sanitize_box/, template, 'The mockup projection frame is not normalized centrally.'],
	[/mask_image_url/, template, 'Projection mask URLs are not hydrated.'],
	[/function sample_config/, template, 'The bundled starter template configuration is missing.'],
	[/function sanitize_projection/, template, 'Cylindrical geometry is not normalized centrally.'],
	[/preview_views/, template, 'Configurable cylindrical preview angles are missing from template normalization.'],
	[/mockup_image_url/, template, 'Angle-specific cylindrical mockup URLs are not hydrated.'],
	[/preview_overlay_image_id/, template, 'Surface top preview layer IDs are not normalized.'],
	[/preview_overlay_image_url/, editor, 'Surface top preview layer URLs are not rendered by the editor.'],
	[/function currentPreviewView/, editor, 'The editor does not track active cylindrical preview views.'],
	[/EDITOR_LOCK_KEY/, editor, 'The customer editor does not enforce a same-device editor lock.'],
	[/function acquireEditorLock/, editor, 'The customer editor lock acquisition helper is missing.'],
	[/function releaseEditorLock/, editor, 'The customer editor lock release helper is missing.'],
	[/editorAlreadyOpen/, frontend, 'The same-device editor lock message is not localized from PHP.'],
	[/addAnotherUnavailable/, frontend, 'The add-another disabled state message is not localized from PHP.'],
	[/available_surface_ids/, template, 'Attribute-specific surface availability is not centralized.'],
	[/['"]image_id['"]/, template, 'Attribute-specific surface images are missing.'],
	[/function surface_extras/, product, 'Product surface extras are not exposed centrally.'],
	[/\$config\[['"]required['"]\]\s*=\s*true/, product, 'Customizable products are not always required to have a saved design.'],
	[/fpcw_surface_unavailable/, validator, 'Unavailable attribute surfaces are not rejected by validation.'],
	[/fpcw_empty_design/, validator, 'Empty customizations are not rejected by validation.'],
	[/['"]outline_width['"]/, validator, 'Outline thickness is not validated server-side.'],
	[/required_surface_ids/, rest, 'REST render validation is not scoped to the selected attribute.'],
	[/fpcw_open_session_limit/, rest, 'Open-session quota is not filterable.'],
	[/session_update_values[\s\S]{0,260}Repository::CART_TTL[\s\S]{0,120}Repository::DRAFT_TTL/, rest, 'REST updates do not apply the correct draft/cart retention window.'],
	[/delete_temporary_session[\s\S]{0,180}fpcw_session_expired/, rest, 'Expired REST sessions do not delete temporary files immediately.'],
	[/preview_render_key/, rest, 'REST preview validation is not scoped to surface and angle.'],
	[/fpcw-cart-preview-link/, cart, 'Cart previews are not linked to their full image.'],
	[/schema_version[\s\S]{0,120}<\s*6/, activator, 'Stored templates do not have an automatic schema v6 migration.'],
	[/install_sample_template/, activator, 'The bundled cylindrical starter template is not installed.'],
	[/update_option\( 'fpcw_pending_upgrade_from'/, activator, 'Template installation is not deferred during early upgrade checks.'],
	[/complete_deferred_upgrade[\s\S]{0,700}Template_Library::maybe_install/, activator, 'The bundled blank template library does not install from the deferred init hook.'],
	[/complete_deferred_upgrade/, plugin, 'The deferred template installer is not registered from the plugin boot sequence.'],
	[/add_action\( 'init',[\s\S]{0,120}complete_deferred_upgrade[\s\S]{0,40}20 \)/, plugin, 'The deferred template installer must run after normal init post-type registration.'],
	[/final class Template_Library/, library, 'The bundled blank template library class is missing.'],
	[/const OPTION\s*=\s*'fpcw_template_library_version'/, library, 'The template library version option is missing.'],
	[/function maybe_install/, library, 'The template library maybe_install guard is missing.'],
	[/blank-round-mousepad-v1/, library, 'The round mousepad bundled template is missing.'],
	[/Superficie redonda[\s\S]{0,260}'circle'/, library, 'Circular bundled surfaces are missing.'],
	[/wp_enqueue_script_module/, frontend, 'The local cylindrical renderer module is not enqueued.'],
	[/function buildProjectionTexture/, editor, 'The editor does not centralize its flat production texture for projection.'],
	[/function drawMockupScene/, editor, 'The live product mockup is not rendered independently from the print map.'],
	[/function workspacePath/, editor, 'Surface clipping paths are not centralized.'],
	[/function clipWorkArea/, editor, 'Customer objects are not clipped through the surface shape.'],
	[/duplicate-surface/, adminEditor, 'Surface duplication is missing from the template builder.'],
	[/surfaceShape/, adminEditor, 'The template builder does not expose surface shape controls.'],
	[/new THREE\.CylinderGeometry/, projector, 'The cylindrical preview does not use Three.js cylinder geometry.'],
	[/new THREE\.CanvasTexture/, projector, 'The flat design canvas is not reused as the cylinder texture.'],
	[/compositeLayers/, projector, 'Projection masks and lighting overlays are not composited.'],
];

for (const [pattern, source, message] of requirements) {
	if (!pattern.test(source)) throw new Error(message);
}
if (![threeModule, threeCore, threeLicense, sampleAsset].every((file) => fs.existsSync(file) && fs.statSync(file).size > 0)) {
	throw new Error('The local Three.js runtime, license, or sample mockup is incomplete.');
}
if (libraryAssets.length < 120) {
	throw new Error('The bundled blank mockup library is incomplete.');
}
process.stdout.write('Schema v6 cylindrical integration smoke test passed.\n');
