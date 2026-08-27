<?php
/**
 * Product page editor shell and assets.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Frontend {
	/** @var Repository */ private $repository;
	/** @var Template_Manager */ private $templates;
	/** @var Product_Settings */ private $products;
	/** @var File_Storage */ private $storage;
	/** @var int */ private $product_id = 0;
	/** @var bool */ private $editor_rendered = false;

	public function __construct( Repository $repository, Template_Manager $templates, Product_Settings $products, File_Storage $storage ) {
		$this->repository = $repository;
		$this->templates  = $templates;
		$this->products   = $products;
		$this->storage    = $storage;
	}

	/** @return void */
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_launcher' ), 5 );
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_saved_previews' ), 20 );
		add_action( 'wp_footer', array( $this, 'render_editor' ), 30 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'append_price_extras' ), 20, 2 );
		add_filter( 'body_class', array( $this, 'body_classes' ) );
	}

	/** @return void */
	public function enqueue() {
		if ( ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$product_id = $product->get_id();
		if ( $product->is_type( 'variation' ) ) {
			$product_id = $product->get_parent_id();
		}
		$config = $this->products->get_product_config( $product_id );
		if ( ! $config ) {
			return;
		}
		$this->product_id = $product_id;

		$edit_token  = isset( $_GET['fpc_edit'] ) ? sanitize_text_field( wp_unslash( $_GET['fpc_edit'] ) ) : '';
		$edit_session = $edit_token ? $this->repository->find( $edit_token ) : null;
		if ( ! $edit_session || $edit_session['product_id'] !== $product_id || $this->repository->is_expired( $edit_session ) || ! Session_Identity::owns( $edit_session ) ) {
			$edit_token = '';
		}

		$bridge_context = array();
		$initial_variation_id = isset( $_GET['fpc_variation_id'] ) ? absint( $_GET['fpc_variation_id'] ) : 0;
		if ( ! empty( $_GET['fpc_bridge'] ) ) {
			$bridge = Bridge::verify( sanitize_text_field( wp_unslash( $_GET['fpc_bridge'] ) ) );
			if ( $bridge && (int) $bridge['product_id'] === $product_id ) {
				$bridge_context = array( 'external_reference' => $bridge['external_reference'] );
				$initial_variation_id = (int) $bridge['variation_id'];
			}
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'fpcw-editor', FPCW_URL . 'assets/css/editor.css', array(), FPCW_VERSION );
		if ( 'cylindrical' === $config['product_type'] && function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( 'fpcw-cylindrical-preview', FPCW_URL . 'assets/js/cylindrical-preview.js', array(), FPCW_VERSION );
		}
		wp_enqueue_script( 'fpcw-editor', FPCW_URL . 'assets/js/editor.js', array(), FPCW_VERSION, false );
		wp_localize_script(
			'fpcw-editor',
			'FPCW_EDITOR',
			array(
				'productId'    => $product_id,
				'isVariable'   => $product->is_type( 'variable' ),
				'configuration'=> $config,
				'restUrl'      => esc_url_raw( rest_url( Rest_Controller::NAMESPACE ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'editToken'    => $edit_token,
				'editVariationId' => $edit_session ? (int) $edit_session['variation_id'] : 0,
				'initialVariationId' => $initial_variation_id,
				'webview'      => ! empty( $_GET['fpc_webview'] ),
				'bridge'       => $bridge_context,
				'newCustomization' => ! empty( $_GET['fpc_new'] ),
				'i18n'         => array(
					'chooseOptions' => __( 'Choose the product options before opening the editor.', 'flexible-product-customizer' ),
					'uploadError'   => __( 'The image could not be uploaded.', 'flexible-product-customizer' ),
					'saveError'     => __( 'The customization could not be saved.', 'flexible-product-customizer' ),
					'imageLimit'    => __( 'This surface has reached its image limit.', 'flexible-product-customizer' ),
					'textLimit'     => __( 'This surface has reached its text limit.', 'flexible-product-customizer' ),
					'confirmRemove' => __( 'Remove the selected element?', 'flexible-product-customizer' ),
					'cartColorLocked'=> __( 'Remove the item from the cart before changing its product color.', 'flexible-product-customizer' ),
					'color'          => __( 'Color', 'flexible-product-customizer' ),
					'imageLoadError' => __( 'An image could not be loaded.', 'flexible-product-customizer' ),
					'fileRules'      => __( 'Use PNG, JPEG, or WebP up to 10 MB.', 'flexible-product-customizer' ),
					'dimensionRules' => __( 'Maximum dimensions are 10,000 x 10,000 pixels.', 'flexible-product-customizer' ),
					'exportError'    => __( 'The generated image could not be exported.', 'flexible-product-customizer' ),
					'saving'        => __( 'Saving...', 'flexible-product-customizer' ),
					'saved'         => __( 'Customization ready', 'flexible-product-customizer' ),
					'addAnotherCustomization' => __( 'Add another customization', 'flexible-product-customizer' ),
					'extra'         => __( 'extra', 'flexible-product-customizer' ),
					'expires'       => __( 'Available in your cart until %s. After that date the design and files are deleted automatically.', 'flexible-product-customizer' ),
					'customizationRequired' => __( 'Save your customization to enable adding this product to the cart.', 'flexible-product-customizer' ),
					'variationChanged' => __( 'Save the customization again for the selected variation.', 'flexible-product-customizer' ),
					'emptyDesign' => __( 'Add an image or text before saving the customization.', 'flexible-product-customizer' ),
					'editView' => __( 'Edit', 'flexible-product-customizer' ),
					'wrappedPreview' => __( 'Wrapped preview', 'flexible-product-customizer' ),
					'previewUnavailable' => __( 'The wrapped preview is not available in this browser.', 'flexible-product-customizer' ),
					'frontView' => __( 'Front view', 'flexible-product-customizer' ),
					'leftSide' => __( 'Left side', 'flexible-product-customizer' ),
					'rightSide' => __( 'Right side', 'flexible-product-customizer' ),
				),
			)
		);
	}

	/** @return void */
	public function render_launcher() {
		global $product;
		$product_id = $product instanceof \WC_Product && $product->is_type( 'variation' ) ? $product->get_parent_id() : ( $product instanceof \WC_Product ? $product->get_id() : 0 );
		if ( ! $product_id || ! $this->products->get_product_config( $product_id ) ) {
			return;
		}
		$this->product_id = $product_id;
		?>
		<div class="fpcw-launcher">
			<input type="hidden" name="fpcw_token" id="fpcw-token" value="" />
			<button type="button" class="button alt fpcw-open-editor" id="fpcw-open-editor">
				<span class="dashicons dashicons-art" aria-hidden="true"></span>
				<?php esc_html_e( 'Customize product', 'flexible-product-customizer' ); ?>
			</button>
			<div id="fpcw-saved-summary" class="fpcw-saved-summary" hidden></div>
			<button type="button" class="button fpcw-add-another" id="fpcw-add-another" hidden>
				<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'Add another customization', 'flexible-product-customizer' ); ?>
			</button>
		</div>
		<?php
	}

	/** @return void */
	public function render_saved_previews() {
		if ( ! $this->product_id || ! $this->products->get_product_config( $this->product_id ) ) {
			return;
		}
		?>
		<section id="fpcw-product-previews" class="fpcw-product-previews" hidden aria-live="polite">
			<strong><?php esc_html_e( 'Customization previews', 'flexible-product-customizer' ); ?></strong>
			<div id="fpcw-product-preview-list" class="fpcw-preview-list"></div>
		</section>
		<?php
	}

	/** @param string $price_html Existing price. @param \WC_Product $product Product. @return string */
	public function append_price_extras( $price_html, $product ) {
		if ( ! is_product() || ! $product instanceof \WC_Product ) {
			return $price_html;
		}
		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		if ( $product_id !== (int) get_queried_object_id() ) {
			return $price_html;
		}
		$extras = $this->products->surface_extras( $product_id );
		if ( ! $extras ) {
			return $price_html;
		}
		return $price_html . '<span class="fpcw-price-extras" data-fpcw-price-extra-live hidden></span>';
	}

	/** @return void */
	public function render_editor() {
		if ( $this->editor_rendered || ! $this->product_id || ! $this->products->get_product_config( $this->product_id ) ) {
			return;
		}
		$this->editor_rendered = true;
		?>
		<dialog id="fpcw-editor-modal" class="fpcw-modal" aria-labelledby="fpcw-editor-title">
			<div class="fpcw-modal__backdrop" data-fpcw-close></div>
			<section class="fpcw-editor" role="document">
				<header class="fpcw-editor__header">
					<div>
						<h2 id="fpcw-editor-title"><?php esc_html_e( 'Product customization', 'flexible-product-customizer' ); ?></h2>
						<p id="fpcw-expiry-line"></p>
					</div>
					<button type="button" class="fpcw-icon-button" data-fpcw-close aria-label="<?php esc_attr_e( 'Close editor', 'flexible-product-customizer' ); ?>" title="<?php esc_attr_e( 'Close', 'flexible-product-customizer' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</header>

				<div class="fpcw-editor__body">
					<aside class="fpcw-toolbar" aria-label="<?php esc_attr_e( 'Customization controls', 'flexible-product-customizer' ); ?>">
						<div id="fpcw-color-control" class="fpcw-control-group"></div>
						<div class="fpcw-control-group">
							<label for="fpcw-image-input"><?php esc_html_e( 'Image', 'flexible-product-customizer' ); ?></label>
							<input type="file" id="fpcw-image-input" accept="image/png,image/jpeg,image/webp" hidden />
							<button type="button" class="button" id="fpcw-add-image"><span class="dashicons dashicons-upload" aria-hidden="true"></span><?php esc_html_e( 'Upload image', 'flexible-product-customizer' ); ?></button>
							<small><?php esc_html_e( 'PNG recommended. PNG, JPEG or WebP; maximum 10 MB and 10,000 x 10,000 px.', 'flexible-product-customizer' ); ?></small>
						</div>

						<div class="fpcw-control-group">
							<label for="fpcw-text-input"><?php esc_html_e( 'Text', 'flexible-product-customizer' ); ?></label>
							<textarea id="fpcw-text-input" maxlength="300" rows="2"></textarea>
							<button type="button" class="button" id="fpcw-add-text"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Add text', 'flexible-product-customizer' ); ?></button>
						</div>

						<div id="fpcw-selection-anchor"><div id="fpcw-selection-controls" class="fpcw-control-group" hidden>
							<div id="fpcw-text-controls" hidden>
								<div class="fpcw-text-primary-fields">
									<label class="fpcw-font-field"><?php esc_html_e( 'Font', 'flexible-product-customizer' ); ?><select id="fpcw-font-family"></select></label>
									<label><?php esc_html_e( 'Size', 'flexible-product-customizer' ); ?><input type="number" id="fpcw-font-size" min="8" max="300" step="1" /></label>
									<label><?php esc_html_e( 'Color', 'flexible-product-customizer' ); ?><input type="color" id="fpcw-text-color" /></label>
									<label><?php esc_html_e( 'Outline', 'flexible-product-customizer' ); ?><input type="color" id="fpcw-outline-color" /></label>
								</div>
								<div id="fpcw-outline-adjustment" class="fpcw-outline-adjustment" hidden><label class="fpcw-outline-width"><?php esc_html_e( 'Outline thickness', 'flexible-product-customizer' ); ?><input type="range" id="fpcw-outline-width" min="1" max="20" step="1" value="3" /><output id="fpcw-outline-width-value">3</output></label></div>
								<div class="fpcw-segmented" aria-label="<?php esc_attr_e( 'Text style', 'flexible-product-customizer' ); ?>">
									<button type="button" id="fpcw-bold" aria-pressed="false" title="<?php esc_attr_e( 'Bold', 'flexible-product-customizer' ); ?>"><strong>B</strong></button>
									<button type="button" id="fpcw-italic" aria-pressed="false" title="<?php esc_attr_e( 'Italic', 'flexible-product-customizer' ); ?>"><em>I</em></button>
									<button type="button" id="fpcw-underline" aria-pressed="false" title="<?php esc_attr_e( 'Underline', 'flexible-product-customizer' ); ?>"><u>U</u></button>
									<button type="button" id="fpcw-outline" aria-pressed="false" title="<?php esc_attr_e( 'Text outline', 'flexible-product-customizer' ); ?>"><strong>O</strong></button>
									<button type="button" id="fpcw-align" title="<?php esc_attr_e( 'Change alignment', 'flexible-product-customizer' ); ?>"><span class="dashicons dashicons-align-center" aria-hidden="true"></span></button>
								</div>
							</div>
							<div class="fpcw-selection-actions">
								<button type="button" class="button" id="fpcw-fit" title="<?php esc_attr_e( 'Fit', 'flexible-product-customizer' ); ?>" aria-label="<?php esc_attr_e( 'Fit', 'flexible-product-customizer' ); ?>"><span class="dashicons dashicons-editor-expand" aria-hidden="true"></span><span class="fpcw-action-label"><?php esc_html_e( 'Fit', 'flexible-product-customizer' ); ?></span></button>
								<button type="button" class="button" id="fpcw-rotate" title="<?php esc_attr_e( 'Rotate 90 degrees', 'flexible-product-customizer' ); ?>" aria-label="<?php esc_attr_e( 'Rotate 90 degrees', 'flexible-product-customizer' ); ?>"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span><span class="fpcw-action-label"><?php esc_html_e( 'Rotate 90 degrees', 'flexible-product-customizer' ); ?></span></button>
								<button type="button" class="button fpcw-danger" id="fpcw-delete" title="<?php esc_attr_e( 'Remove', 'flexible-product-customizer' ); ?>" aria-label="<?php esc_attr_e( 'Remove', 'flexible-product-customizer' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="fpcw-action-label"><?php esc_html_e( 'Remove', 'flexible-product-customizer' ); ?></span></button>
							</div>
						</div></div>
					</aside>

					<main class="fpcw-stage-wrap">
						<div id="fpcw-surface-tabs" class="fpcw-tabs" role="tablist"></div>
						<div id="fpcw-surface-overview" class="fpcw-surface-overview" aria-label="<?php esc_attr_e( 'Surfaces', 'flexible-product-customizer' ); ?>"></div>
						<div id="fpcw-view-modes" class="fpcw-view-modes" role="group" aria-label="<?php esc_attr_e( 'Editor view', 'flexible-product-customizer' ); ?>" hidden>
							<button type="button" id="fpcw-view-edit" aria-pressed="true"><span class="dashicons dashicons-edit" aria-hidden="true"></span><?php esc_html_e( 'Print design', 'flexible-product-customizer' ); ?></button>
							<button type="button" id="fpcw-view-wrapped" aria-pressed="false"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><?php esc_html_e( 'Product preview', 'flexible-product-customizer' ); ?></button>
						</div>
						<div id="fpcw-stage-canvases" class="fpcw-stage-canvases">
							<section id="fpcw-edit-panel" class="fpcw-stage-panel fpcw-stage-panel--edit">
								<h3><?php esc_html_e( 'Print design', 'flexible-product-customizer' ); ?></h3>
								<div class="fpcw-canvas-shell" id="fpcw-canvas-shell">
									<canvas id="fpcw-canvas" tabindex="0"></canvas>
								</div>
							</section>
							<section id="fpcw-projection-panel" class="fpcw-stage-panel fpcw-stage-panel--projection" hidden>
								<h3><?php esc_html_e( 'Product preview', 'flexible-product-customizer' ); ?></h3>
								<div class="fpcw-canvas-shell fpcw-projection-shell" id="fpcw-projection-shell">
									<canvas id="fpcw-mockup-canvas" aria-hidden="true"></canvas>
									<canvas id="fpcw-projection-canvas" class="fpcw-projection-canvas" tabindex="0" aria-label="<?php esc_attr_e( 'Wrapped product preview', 'flexible-product-customizer' ); ?>"></canvas>
								</div>
								<div id="fpcw-preview-angle-controls" class="fpcw-angle-controls" hidden></div>
							</section>
							<div id="fpcw-loading" class="fpcw-loading" hidden><?php esc_html_e( 'Loading...', 'flexible-product-customizer' ); ?></div>
						</div>
						<div id="fpcw-editor-message" class="fpcw-editor-message" role="status" aria-live="polite"></div>
					</main>
				</div>

				<footer class="fpcw-editor__footer">
					<button type="button" class="button" data-fpcw-close><?php esc_html_e( 'Cancel', 'flexible-product-customizer' ); ?></button>
					<button type="button" class="button alt" id="fpcw-save"><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Save customization', 'flexible-product-customizer' ); ?></button>
				</footer>
			</section>
		</dialog>
		<?php
	}

	/** @param array $classes Body classes. @return array */
	public function body_classes( $classes ) {
		if ( ! empty( $_GET['fpc_webview'] ) ) {
			$classes[] = 'fpcw-webview';
		}
		return $classes;
	}
}
