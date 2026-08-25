<?php
/**
 * Reusable visual template management.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Template_Manager {
	const POST_TYPE = 'fpcw_template';
	const META_KEY  = '_fpcw_template_config';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'wp_ajax_fpcw_save_template_config', array( $this, 'ajax_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
	}

	/**
	 * Register the private admin-only template content type.
	 *
	 * @return void
	 */
	public function register_post_type() {
		$capabilities = array_fill_keys(
			array(
				'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts',
				'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts',
				'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts',
			),
			'manage_woocommerce'
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Customization templates', 'flexible-product-customizer' ),
					'singular_name' => __( 'Customization template', 'flexible-product-customizer' ),
					'add_new_item'  => __( 'Add customization template', 'flexible-product-customizer' ),
					'edit_item'     => __( 'Edit customization template', 'flexible-product-customizer' ),
					'menu_name'     => __( 'Customization', 'flexible-product-customizer' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'woocommerce',
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'map_meta_cap' => false,
				'capabilities' => $capabilities,
				'menu_icon'    => 'dashicons-art',
			)
		);

	}

	/**
	 * Add the visual configuration metabox.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'fpcw-template-builder',
			__( 'Template builder', 'flexible-product-customizer' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the application shell consumed by admin-template.js.
	 *
	 * @param \WP_Post $post Current template post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$config = $this->get_config( $post->ID );
		wp_nonce_field( 'fpcw_save_template', 'fpcw_template_nonce' );
		?>
		<p class="description">
			<?php esc_html_e( 'Choose the product type first. Flat products use an editing area over the mockup; cylindrical products use a separate print map and projection frame.', 'flexible-product-customizer' ); ?>
		</p>
		<div id="fpcw-template-builder"></div>
		<input type="hidden" id="fpcw-template-config" name="fpcw_template_config" value="<?php echo esc_attr( wp_json_encode( $config, JSON_UNESCAPED_SLASHES ) ); ?>" />
		<?php
	}

	/**
	 * Persist a fully sanitized template document.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( empty( $_POST['fpcw_template_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fpcw_template_nonce'] ) ), 'fpcw_save_template' ) ) {
			return;
		}

		if ( ! isset( $_POST['fpcw_template_config'] ) || ! is_string( $_POST['fpcw_template_config'] ) ) {
			return;
		}

		$config = $this->decode_config( wp_unslash( $_POST['fpcw_template_config'] ) );
		if ( is_wp_error( $config ) ) {
			return;
		}

		$this->persist_config( $post_id, $config );
	}

	/** @return void */
	public function ajax_save() {
		check_ajax_referer( 'fpcw_save_template_ajax', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || self::POST_TYPE !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'The template could not be identified.', 'flexible-product-customizer' ) ), 400 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this template.', 'flexible-product-customizer' ) ), 403 );
		}

		$raw    = isset( $_POST['config'] ) && is_string( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '';
		$config = $this->decode_config( $raw );
		if ( is_wp_error( $config ) ) {
			wp_send_json_error( array( 'message' => $config->get_error_message() ), 400 );
		}

		$stored = $this->persist_config( $post_id, $config );
		wp_send_json_success( array( 'config' => $stored ) );
	}

	/**
	 * Load template editor assets only on template screens.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'fpcw-admin', FPCW_URL . 'assets/css/admin.css', array(), FPCW_VERSION );
		wp_enqueue_script( 'fpcw-admin-template', FPCW_URL . 'assets/js/admin-template.js', array(), FPCW_VERSION, true );
		wp_localize_script(
			'fpcw-admin-template',
			'FPCW_ADMIN',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'ajaxNonce'   => wp_create_nonce( 'fpcw_save_template_ajax' ),
				'fontLibrary' => Settings::font_library(),
				'mediaTitle'  => __( 'Select a product base image', 'flexible-product-customizer' ),
				'mediaButton' => __( 'Use this image', 'flexible-product-customizer' ),
				'noImage'     => __( 'No image selected', 'flexible-product-customizer' ),
				'i18n'         => array(
					'productType' => __( 'Product type', 'flexible-product-customizer' ), 'flatProduct' => __( 'Flat', 'flexible-product-customizer' ),
					'cylindricalProduct' => __( 'Cylindrical', 'flexible-product-customizer' ),
					'chooseProductType' => __( 'Choose flat or cylindrical to continue configuring the template.', 'flexible-product-customizer' ),
					'textOptions' => __( 'Text options', 'flexible-product-customizer' ), 'fonts' => __( 'Available fonts', 'flexible-product-customizer' ),
					'productColors' => __( 'Color attributes', 'flexible-product-customizer' ),
					'addColor' => __( 'Add color', 'flexible-product-customizer' ), 'surfaces' => __( 'Printable surfaces', 'flexible-product-customizer' ),
					'addSurface' => __( 'Add surface', 'flexible-product-customizer' ), 'name' => __( 'Name', 'flexible-product-customizer' ),
					'id' => __( 'ID', 'flexible-product-customizer' ), 'swatch' => __( 'Swatch', 'flexible-product-customizer' ),
					'variationValue' => __( 'Variation value', 'flexible-product-customizer' ), 'remove' => __( 'Remove', 'flexible-product-customizer' ),
					'removeSurface' => __( 'Remove surface', 'flexible-product-customizer' ), 'duplicateSurface' => __( 'Duplicate surface', 'flexible-product-customizer' ),
					'copySuffix' => __( 'copy', 'flexible-product-customizer' ), 'canvasWidth' => __( 'Canvas width (px)', 'flexible-product-customizer' ),
					'canvasHeight' => __( 'Canvas height (px)', 'flexible-product-customizer' ), 'mockupWidth' => __( 'Mockup width (px)', 'flexible-product-customizer' ),
					'mockupHeight' => __( 'Mockup height (px)', 'flexible-product-customizer' ), 'baseImages' => __( 'Surface images for this attribute', 'flexible-product-customizer' ),
					'enableSurface' => __( 'Available for this attribute', 'flexible-product-customizer' ),
					'workArea' => __( 'Customer editing area', 'flexible-product-customizer' ), 'width' => __( 'Width', 'flexible-product-customizer' ),
					'height' => __( 'Height', 'flexible-product-customizer' ), 'elementLimits' => __( 'Element limits', 'flexible-product-customizer' ),
					'images' => __( 'Images', 'flexible-product-customizer' ), 'maximumImages' => __( 'Maximum images', 'flexible-product-customizer' ),
					'text' => __( 'Text', 'flexible-product-customizer' ), 'maximumTexts' => __( 'Maximum texts', 'flexible-product-customizer' ),
					'noImageShort' => __( 'No image', 'flexible-product-customizer' ), 'choose' => __( 'Choose', 'flexible-product-customizer' ),
					'clear' => __( 'Clear', 'flexible-product-customizer' ), 'newColor' => __( 'New color', 'flexible-product-customizer' ),
					'newSurface' => __( 'New surface', 'flexible-product-customizer' ),
					'canvas' => __( 'Canvas', 'flexible-product-customizer' ), 'baseImagePosition' => __( 'Base image position', 'flexible-product-customizer' ),
					'positionX' => __( 'X position', 'flexible-product-customizer' ), 'positionY' => __( 'Y position', 'flexible-product-customizer' ),
					'center' => __( 'Center', 'flexible-product-customizer' ), 'fitCanvas' => __( 'Fit to canvas', 'flexible-product-customizer' ),
					'alignLeft' => __( 'Align left', 'flexible-product-customizer' ), 'alignRight' => __( 'Align right', 'flexible-product-customizer' ),
					'alignTop' => __( 'Align top', 'flexible-product-customizer' ), 'alignBottom' => __( 'Align bottom', 'flexible-product-customizer' ),
					'dragHelp' => __( 'Drag either box to move it. Drag an edge or corner handle to resize it.', 'flexible-product-customizer' ),
					'savingTemplate' => __( 'Saving template...', 'flexible-product-customizer' ), 'templateSaved' => __( 'Template saved.', 'flexible-product-customizer' ),
					'saveFailed' => __( 'The template could not be saved. The page was not submitted to prevent data loss.', 'flexible-product-customizer' ),
					'baseImage' => __( 'Base image', 'flexible-product-customizer' ), 'editingArea' => __( 'Editing area', 'flexible-product-customizer' ),
					'previewAttribute' => __( 'Preview attribute', 'flexible-product-customizer' ),
					'cylindricalProjection' => __( 'Cylindrical projection', 'flexible-product-customizer' ),
					'wrapAngle' => __( 'Printable wrap angle (degrees)', 'flexible-product-customizer' ),
					'topDiameter' => __( 'Top diameter (%)', 'flexible-product-customizer' ),
					'bottomDiameter' => __( 'Bottom diameter (%)', 'flexible-product-customizer' ),
					'shading' => __( 'Curvature shading (%)', 'flexible-product-customizer' ),
					'printMap' => __( 'Print map', 'flexible-product-customizer' ),
					'printMapWidth' => __( 'Print map width (px)', 'flexible-product-customizer' ),
					'printMapHeight' => __( 'Print map height (px)', 'flexible-product-customizer' ),
					'projectionFrame' => __( 'Projection frame', 'flexible-product-customizer' ),
					'projectionFramePosition' => __( 'Projection frame position', 'flexible-product-customizer' ),
					'previewAngles' => __( 'Preview angles', 'flexible-product-customizer' ),
					'show' => __( 'Show', 'flexible-product-customizer' ),
					'angleLabel' => __( 'Angle label', 'flexible-product-customizer' ),
					'rotationDegrees' => __( 'Rotation (degrees)', 'flexible-product-customizer' ),
					'frontView' => __( 'Front view', 'flexible-product-customizer' ),
					'leftSide' => __( 'Left side', 'flexible-product-customizer' ),
					'rightSide' => __( 'Right side', 'flexible-product-customizer' ),
					'projectionMask' => __( 'Projection mask', 'flexible-product-customizer' ),
					'lightingOverlay' => __( 'Lighting overlay', 'flexible-product-customizer' ),
					'optionalProjectionLayers' => __( 'Optional projection layers', 'flexible-product-customizer' ),
				),
			)
		);
	}

	/**
	 * Get the stored normalized config.
	 *
	 * @param int $template_id Template post ID.
	 * @return array
	 */
	public function get_config( $template_id ) {
		$config = get_post_meta( absint( $template_id ), self::META_KEY, true );
		return is_array( $config ) ? $this->sanitize_config( $config ) : $this->default_config();
	}

	/**
	 * Resolve attachment IDs into same-origin image URLs for browser clients.
	 *
	 * @param int $template_id Template post ID.
	 * @return array
	 */
	public function get_public_config( $template_id ) {
		$config = $this->get_config( $template_id );
		$storage = new File_Storage();
		foreach ( $config['colors'] as &$color ) {
			foreach ( $color['surfaces'] as &$assignment ) {
				$assignment['image_url'] = ! empty( $assignment['image_id'] ) ? $storage->attachment_url( $assignment['image_id'] ) : '';
			}
			unset( $assignment );
		}
		unset( $color );
		foreach ( $config['surfaces'] as &$surface ) {
			$projection = isset( $surface['projection'] ) && is_array( $surface['projection'] ) ? $surface['projection'] : array();
			$projection['mask_image_url'] = ! empty( $projection['mask_image_id'] ) ? $storage->attachment_url( $projection['mask_image_id'] ) : '';
			$projection['overlay_image_url'] = ! empty( $projection['overlay_image_id'] ) ? $storage->attachment_url( $projection['overlay_image_id'] ) : '';
			$surface['projection'] = $projection;
		}
		unset( $surface );
		$config['font_faces'] = array();
		foreach ( Settings::font_library() as $font ) {
			if ( ! empty( $font['custom'] ) && in_array( $font['family'], $config['fonts'], true ) ) {
				$config['font_faces'][] = array( 'family' => $font['family'], 'url' => $font['url'], 'format' => $font['format'] );
			}
		}
		return $config;
	}

	/**
	 * Migrate a stored public session snapshot without dropping hydrated URLs.
	 *
	 * @param array $snapshot Template snapshot.
	 * @return array
	 */
	public function normalize_snapshot( array $snapshot ) {
		$schema_version = isset( $snapshot['schema_version'] ) ? (int) $snapshot['schema_version'] : 1;
		if ( $schema_version >= 6 ) {
			return $snapshot;
		}
		$surfaces = isset( $snapshot['surfaces'] ) && is_array( $snapshot['surfaces'] ) ? $snapshot['surfaces'] : array();
		foreach ( $surfaces as &$surface ) {
			$width  = max( 1, (float) $surface['width'] );
			$height = max( 1, (float) $surface['height'] );
			if ( $schema_version < 3 ) {
				$area   = isset( $surface['workspace'] ) && is_array( $surface['workspace'] ) ? $surface['workspace'] : array();
				$surface['workspace'] = array(
					'x'      => $width * ( isset( $area['x'] ) ? (float) $area['x'] : 25 ) / 100,
					'y'      => $height * ( isset( $area['y'] ) ? (float) $area['y'] : 20 ) / 100,
					'width'  => $width * ( isset( $area['width'] ) ? (float) $area['width'] : 50 ) / 100,
					'height' => $height * ( isset( $area['height'] ) ? (float) $area['height'] : 60 ) / 100,
				);
				$surface['base_image_transform'] = array( 'x' => 0, 'y' => 0, 'width' => $width, 'height' => $height );
			}
		}
		unset( $surface );
		$snapshot['surfaces'] = $surfaces;
		if ( $schema_version < 4 ) {
			$colors = isset( $snapshot['colors'] ) && is_array( $snapshot['colors'] ) ? $snapshot['colors'] : array();
			foreach ( $colors as &$color ) {
				$color['surfaces'] = array();
				foreach ( $surfaces as $surface ) {
					$image_id = ! empty( $surface['color_images'][ $color['id'] ] ) ? absint( $surface['color_images'][ $color['id'] ] ) : absint( isset( $surface['base_image_id'] ) ? $surface['base_image_id'] : 0 );
					$image_url = ! empty( $surface['color_image_urls'][ $color['id'] ] ) ? $surface['color_image_urls'][ $color['id'] ] : ( isset( $surface['base_image_url'] ) ? $surface['base_image_url'] : '' );
					$color['surfaces'][ $surface['id'] ] = array( 'enabled' => true, 'image_id' => $image_id, 'image_url' => $image_url );
				}
			}
			unset( $color );
			$snapshot['colors'] = $colors;
			foreach ( $snapshot['surfaces'] as &$surface ) {
				unset( $surface['base_image_id'], $surface['base_image_url'], $surface['color_images'], $surface['color_image_urls'] );
			}
			unset( $surface );
		}
		$snapshot['product_type'] = isset( $snapshot['product_type'] ) && 'cylindrical' === $snapshot['product_type'] ? 'cylindrical' : 'flat';
		foreach ( $snapshot['surfaces'] as &$surface ) {
			$width       = max( 100, absint( isset( $surface['width'] ) ? $surface['width'] : 1000 ) );
			$height      = max( 100, absint( isset( $surface['height'] ) ? $surface['height'] : 1000 ) );
			$workspace   = isset( $surface['workspace'] ) && is_array( $surface['workspace'] ) ? $surface['workspace'] : array( 'x' => 0, 'y' => 0, 'width' => $width, 'height' => $height );
			$projection  = isset( $surface['projection'] ) && is_array( $surface['projection'] ) ? $surface['projection'] : array();
			$mask_url    = isset( $projection['mask_image_url'] ) ? $projection['mask_image_url'] : '';
			$overlay_url = isset( $projection['overlay_image_url'] ) ? $projection['overlay_image_url'] : '';
			$surface['print_area'] = $this->sanitize_print_area(
				isset( $surface['print_area'] ) && is_array( $surface['print_area'] ) ? $surface['print_area'] : array(),
				array( 'width' => isset( $workspace['width'] ) ? $workspace['width'] : $width, 'height' => isset( $workspace['height'] ) ? $workspace['height'] : $height )
			);
			$surface['projection'] = $this->sanitize_projection( $projection, $width, $height, $workspace );
			if ( $mask_url ) {
				$surface['projection']['mask_image_url'] = esc_url_raw( $mask_url );
			}
			if ( $overlay_url ) {
				$surface['projection']['overlay_image_url'] = esc_url_raw( $overlay_url );
			}
		}
		unset( $surface );
		$snapshot['schema_version'] = 6;
		return $snapshot;
	}

	/**
	 * Return published templates for product configuration.
	 *
	 * @return array<int,string>
	 */
	public function choices() {
		$posts   = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$choices = array( '' => __( 'Select a template', 'flexible-product-customizer' ) );
		foreach ( $posts as $post ) {
			$config = $this->get_config( $post->ID );
			if ( ! in_array( isset( $config['product_type'] ) ? $config['product_type'] : '', array( 'flat', 'cylindrical' ), true ) ) {
				continue;
			}
			$choices[ $post->ID ] = $post->post_title;
		}
		return $choices;
	}

	/**
	 * Return the template fields needed by the product editor.
	 *
	 * @return array<string,array>
	 */
	public function product_editor_data() {
		$data = array();
		foreach ( $this->choices() as $template_id => $title ) {
			if ( ! $template_id ) {
				continue;
			}
			$config = $this->get_config( $template_id );
			$data[ (string) $template_id ] = array(
				'id'       => (int) $template_id,
				'title'    => $title,
				'colors'   => array_values( $config['colors'] ),
				'surfaces' => array_values(
					array_map(
						static function ( $surface ) use ( $config ) {
							$attributes = array();
							foreach ( $config['colors'] as $color ) {
								if ( ! empty( $color['surfaces'][ $surface['id'] ]['enabled'] ) ) {
									$attributes[] = $color['label'];
								}
							}
							return array(
								'id'         => $surface['id'],
								'label'      => $surface['label'],
								'attributes' => $attributes,
							);
						},
						$config['surfaces']
					)
				),
			);
		}
		return $data;
	}

	/** @param array $config Template or session snapshot. @param string $color_id Color attribute ID. @return array<int,string> */
	public function available_surface_ids( array $config, $color_id ) {
		$valid = wp_list_pluck( isset( $config['surfaces'] ) && is_array( $config['surfaces'] ) ? $config['surfaces'] : array(), 'id' );
		foreach ( isset( $config['colors'] ) && is_array( $config['colors'] ) ? $config['colors'] : array() as $color ) {
			if ( $color['id'] !== $color_id ) {
				continue;
			}
			$available = array();
			foreach ( isset( $color['surfaces'] ) && is_array( $color['surfaces'] ) ? $color['surfaces'] : array() as $surface_id => $assignment ) {
				if ( ! empty( $assignment['enabled'] ) && in_array( $surface_id, $valid, true ) ) {
					$available[] = $surface_id;
				}
			}
			return $available;
		}
		return array();
	}

	/**
	 * Sanitize the whole template as one versioned document.
	 *
	 * @param mixed $config Raw config.
	 * @return array
	 */
	public function sanitize_config( $config ) {
		$config = is_array( $config ) ? $config : array();
		$schema_version = isset( $config['schema_version'] ) ? absint( $config['schema_version'] ) : 1;
		$product_type = isset( $config['product_type'] ) && 'cylindrical' === $config['product_type'] ? 'cylindrical' : 'flat';
		$font_library = wp_list_pluck( Settings::font_library(), 'family' );
		$fonts = isset( $config['fonts'] ) && is_array( $config['fonts'] ) ? $config['fonts'] : array();
		$fonts = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_slice( $fonts, 0, 20 ) ) ) ) );
		if ( ! $fonts ) {
			$fonts = $font_library;
		}

		$colors    = array();
		$color_ids = array();
		$raw_color_surfaces = array();
		foreach ( array_slice( isset( $config['colors'] ) && is_array( $config['colors'] ) ? $config['colors'] : array(), 0, 30 ) as $index => $color ) {
			$id = sanitize_title( isset( $color['id'] ) ? $color['id'] : '' );
			if ( ! $id || in_array( $id, $color_ids, true ) ) {
				$id = 'color-' . ( $index + 1 );
			}
			$color_ids[] = $id;
			$raw_color_surfaces[ $id ] = isset( $color['surfaces'] ) && is_array( $color['surfaces'] ) ? $color['surfaces'] : array();
			$colors[]    = array(
				'id'              => $id,
				'label'           => sanitize_text_field( isset( $color['label'] ) ? $color['label'] : $id ),
				'hex'             => sanitize_hex_color( isset( $color['hex'] ) ? $color['hex'] : '' ) ?: '#ffffff',
				'variation_value' => sanitize_title( isset( $color['variation_value'] ) ? $color['variation_value'] : $id ),
			);
		}
		if ( ! $colors ) {
			$colors    = array( array( 'id' => 'default', 'label' => __( 'Default', 'flexible-product-customizer' ), 'hex' => '#ffffff', 'variation_value' => 'default' ) );
			$color_ids = array( 'default' );
			$raw_color_surfaces['default'] = array();
		}

		$surfaces    = array();
		$surface_ids = array();
		$legacy_images = array();
		foreach ( array_slice( isset( $config['surfaces'] ) && is_array( $config['surfaces'] ) ? $config['surfaces'] : array(), 0, 12 ) as $index => $surface ) {
			$id = sanitize_title( isset( $surface['id'] ) ? $surface['id'] : '' );
			if ( ! $id || in_array( $id, $surface_ids, true ) ) {
				$id = 'surface-' . ( $index + 1 );
			}
			$surface_ids[] = $id;

			$canvas_width  = $this->integer_between( isset( $surface['width'] ) ? $surface['width'] : 1000, 100, 10000 );
			$canvas_height = $this->integer_between( isset( $surface['height'] ) ? $surface['height'] : 1000, 100, 10000 );
			$workspace     = isset( $surface['workspace'] ) && is_array( $surface['workspace'] ) ? $surface['workspace'] : array();
			if ( $schema_version < 3 ) {
				$workspace = array(
					'x'      => $canvas_width * ( isset( $workspace['x'] ) ? (float) $workspace['x'] : 25 ) / 100,
					'y'      => $canvas_height * ( isset( $workspace['y'] ) ? (float) $workspace['y'] : 20 ) / 100,
					'width'  => $canvas_width * ( isset( $workspace['width'] ) ? (float) $workspace['width'] : 50 ) / 100,
					'height' => $canvas_height * ( isset( $workspace['height'] ) ? (float) $workspace['height'] : 60 ) / 100,
				);
			}
			$workspace = $this->sanitize_box(
				$workspace,
				$canvas_width,
				$canvas_height,
				array( 'x' => $canvas_width * 0.25, 'y' => $canvas_height * 0.2, 'width' => $canvas_width * 0.5, 'height' => $canvas_height * 0.6 )
			);
			$base_transform = $this->sanitize_box(
				isset( $surface['base_image_transform'] ) && is_array( $surface['base_image_transform'] ) ? $surface['base_image_transform'] : array(),
				$canvas_width,
				$canvas_height,
				array( 'x' => 0, 'y' => 0, 'width' => $canvas_width, 'height' => $canvas_height )
			);

			$legacy_images[ $id ] = array(
				'base'   => absint( isset( $surface['base_image_id'] ) ? $surface['base_image_id'] : 0 ),
				'colors' => isset( $surface['color_images'] ) && is_array( $surface['color_images'] ) ? $surface['color_images'] : array(),
			);

			$print_area = $this->sanitize_print_area(
				isset( $surface['print_area'] ) && is_array( $surface['print_area'] ) ? $surface['print_area'] : array(),
				array(
					'width'  => $schema_version < 6 ? $workspace['width'] : $canvas_width,
					'height' => $schema_version < 6 ? $workspace['height'] : $canvas_height,
				)
			);
			$projection_defaults = $schema_version < 6 ? $workspace : array( 'x' => $canvas_width * 0.25, 'y' => $canvas_height * 0.2, 'width' => $canvas_width * 0.5, 'height' => $canvas_height * 0.6 );

			$surfaces[] = array(
				'id'             => $id,
				'label'          => sanitize_text_field( isset( $surface['label'] ) ? $surface['label'] : $id ),
				'width'          => $canvas_width,
				'height'         => $canvas_height,
				'workspace'      => $workspace,
				'print_area'     => $print_area,
				'base_image_transform' => $base_transform,
				'projection'     => $this->sanitize_projection( isset( $surface['projection'] ) && is_array( $surface['projection'] ) ? $surface['projection'] : array(), $canvas_width, $canvas_height, $projection_defaults ),
				'allow_images'   => ! empty( $surface['allow_images'] ),
				'allow_text'     => ! empty( $surface['allow_text'] ),
				'max_images'     => $this->integer_between( isset( $surface['max_images'] ) ? $surface['max_images'] : 1, 0, 20 ),
				'max_texts'      => $this->integer_between( isset( $surface['max_texts'] ) ? $surface['max_texts'] : 3, 0, 20 ),
			);
		}

		if ( ! $surfaces ) {
			$surfaces = array(
				array(
					'id' => 'front', 'label' => __( 'Front', 'flexible-product-customizer' ), 'width' => 1000, 'height' => 1000,
					'workspace' => array( 'x' => 250, 'y' => 200, 'width' => 500, 'height' => 600 ),
					'print_area' => array( 'width' => 1000, 'height' => 1000 ),
					'base_image_transform' => array( 'x' => 0, 'y' => 0, 'width' => 1000, 'height' => 1000 ),
					'projection' => $this->sanitize_projection( array(), 1000, 1000, array( 'x' => 250, 'y' => 200, 'width' => 500, 'height' => 600 ) ),
					'allow_images' => true, 'allow_text' => true, 'max_images' => 1, 'max_texts' => 3,
				),
			);
			$surface_ids = array( 'front' );
			$legacy_images['front'] = array( 'base' => 0, 'colors' => array() );
		}

		foreach ( $colors as &$color ) {
			$assignments = array();
			foreach ( $surfaces as $surface ) {
				$surface_id = $surface['id'];
				$raw = isset( $raw_color_surfaces[ $color['id'] ][ $surface_id ] ) && is_array( $raw_color_surfaces[ $color['id'] ][ $surface_id ] ) ? $raw_color_surfaces[ $color['id'] ][ $surface_id ] : array();
				if ( $schema_version < 4 ) {
					$color_image = isset( $legacy_images[ $surface_id ]['colors'][ $color['id'] ] ) ? absint( $legacy_images[ $surface_id ]['colors'][ $color['id'] ] ) : 0;
					$raw = array( 'enabled' => true, 'image_id' => $color_image ?: $legacy_images[ $surface_id ]['base'] );
				}
				$assignments[ $surface_id ] = array(
					'enabled'  => ! isset( $raw['enabled'] ) || ! empty( $raw['enabled'] ),
					'image_id' => absint( isset( $raw['image_id'] ) ? $raw['image_id'] : 0 ),
				);
			}
			$color['surfaces'] = $assignments;
		}
		unset( $color );

		return array(
			'schema_version' => 6,
			'product_type'   => $product_type,
			'fonts'          => $fonts,
			'colors'         => $colors,
			'surfaces'       => $surfaces,
		);
	}

	/**
	 * Build the bundled cylindrical starter template.
	 *
	 * @param int $image_id Bundled mug attachment ID.
	 * @return array
	 */
	public function sample_config( $image_id ) {
		$fonts = wp_list_pluck( Settings::font_library(), 'family' );
		return $this->sanitize_config(
			array(
				'schema_version' => 6,
				'product_type'   => 'cylindrical',
				'fonts'          => $fonts,
				'colors'         => array(
					array(
						'id' => 'white', 'label' => __( 'White', 'flexible-product-customizer' ), 'hex' => '#ffffff', 'variation_value' => 'white',
						'surfaces' => array( 'wrap' => array( 'enabled' => true, 'image_id' => absint( $image_id ) ) ),
					),
				),
				'surfaces'       => array(
					array(
						'id' => 'wrap', 'label' => __( 'Full wrap', 'flexible-product-customizer' ), 'width' => 1200, 'height' => 1200,
						'workspace' => array( 'x' => 210, 'y' => 280, 'width' => 610, 'height' => 680 ),
						'print_area' => array( 'width' => 2400, 'height' => 900 ),
						'base_image_transform' => array( 'x' => 0, 'y' => 0, 'width' => 1200, 'height' => 1200 ),
						'projection' => array(
							'wrap_angle' => 180, 'top_scale' => 100, 'bottom_scale' => 102, 'shading' => 55,
							'frame' => array( 'x' => 210, 'y' => 280, 'width' => 610, 'height' => 680 ),
							'preview_views' => $this->default_preview_views(),
							'mask_image_id' => absint( $image_id ), 'overlay_image_id' => 0,
						),
						'allow_images' => true, 'allow_text' => true, 'max_images' => 4, 'max_texts' => 6,
					),
				),
			)
		);
	}

	/** @return array */
	private function default_config() {
		$fonts = wp_list_pluck( Settings::font_library(), 'family' );
		return array(
			'schema_version' => 6,
			'product_type'   => '',
			'fonts'          => $fonts,
			'colors'         => array(
				array(
					'id' => 'default', 'label' => __( 'Default', 'flexible-product-customizer' ), 'hex' => '#ffffff', 'variation_value' => 'default',
					'surfaces' => array( 'front' => array( 'enabled' => true, 'image_id' => 0 ) ),
				),
			),
			'surfaces'       => array(
				array(
					'id' => 'front', 'label' => __( 'Front', 'flexible-product-customizer' ), 'width' => 1000, 'height' => 1000,
					'workspace' => array( 'x' => 250, 'y' => 200, 'width' => 500, 'height' => 600 ),
					'print_area' => array( 'width' => 1000, 'height' => 1000 ),
					'base_image_transform' => array( 'x' => 0, 'y' => 0, 'width' => 1000, 'height' => 1000 ),
					'projection' => $this->sanitize_projection( array(), 1000, 1000, array( 'x' => 250, 'y' => 200, 'width' => 500, 'height' => 600 ) ),
					'allow_images' => true, 'allow_text' => true,
					'max_images' => 1, 'max_texts' => 3,
				),
			),
		);
	}

	/** @param string $raw JSON document. @return array|\WP_Error */
	private function decode_config( $raw ) {
		$config = json_decode( (string) $raw, true );
		if ( ! is_array( $config ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error( 'fpcw_invalid_template_json', __( 'The template data is invalid.', 'flexible-product-customizer' ) );
		}
		$schema_version = isset( $config['schema_version'] ) ? absint( $config['schema_version'] ) : 1;
		if ( $schema_version >= 5 && ! in_array( isset( $config['product_type'] ) ? $config['product_type'] : '', array( 'flat', 'cylindrical' ), true ) ) {
			return new \WP_Error( 'fpcw_product_type_required', __( 'Choose whether the product is flat or cylindrical before saving the template.', 'flexible-product-customizer' ) );
		}
		return $config;
	}

	/** @param int $post_id Template post ID. @param array $config Raw config. @return array */
	private function persist_config( $post_id, array $config ) {
		$sanitized = $this->sanitize_config( $config );
		update_post_meta( absint( $post_id ), self::META_KEY, $sanitized );
		clean_post_cache( absint( $post_id ) );
		$stored = get_post_meta( absint( $post_id ), self::META_KEY, true );
		return is_array( $stored ) ? $stored : $sanitized;
	}

	/** @param array $box Box coordinates. @param int $canvas_width Canvas width. @param int $canvas_height Canvas height. @param array $defaults Default box. @return array */
	private function sanitize_box( array $box, $canvas_width, $canvas_height, array $defaults ) {
		$x      = $this->integer_between( isset( $box['x'] ) ? round( (float) $box['x'] ) : round( $defaults['x'] ), 0, max( 0, $canvas_width - 1 ) );
		$y      = $this->integer_between( isset( $box['y'] ) ? round( (float) $box['y'] ) : round( $defaults['y'] ), 0, max( 0, $canvas_height - 1 ) );
		$width  = $this->integer_between( isset( $box['width'] ) ? round( (float) $box['width'] ) : round( $defaults['width'] ), 1, max( 1, $canvas_width - $x ) );
		$height = $this->integer_between( isset( $box['height'] ) ? round( (float) $box['height'] ) : round( $defaults['height'] ), 1, max( 1, $canvas_height - $y ) );
		return array( 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height );
	}

	/** @param array $print_area Print dimensions. @param array $defaults Default dimensions. @return array */
	private function sanitize_print_area( array $print_area, array $defaults ) {
		return array(
			'width'  => $this->integer_between( isset( $print_area['width'] ) ? $print_area['width'] : $defaults['width'], 100, 10000 ),
			'height' => $this->integer_between( isset( $print_area['height'] ) ? $print_area['height'] : $defaults['height'], 100, 10000 ),
		);
	}

	/** @param array $projection Cylindrical projection settings. @param int $canvas_width Mockup width. @param int $canvas_height Mockup height. @param array $frame_defaults Default frame. @return array */
	private function sanitize_projection( array $projection, $canvas_width, $canvas_height, array $frame_defaults ) {
		return array(
			'wrap_angle'  => $this->integer_between( isset( $projection['wrap_angle'] ) ? $projection['wrap_angle'] : 180, 90, 360 ),
			'top_scale'   => $this->integer_between( isset( $projection['top_scale'] ) ? $projection['top_scale'] : 100, 50, 150 ),
			'bottom_scale'=> $this->integer_between( isset( $projection['bottom_scale'] ) ? $projection['bottom_scale'] : 100, 50, 150 ),
			'shading'     => $this->integer_between( isset( $projection['shading'] ) ? $projection['shading'] : 45, 0, 100 ),
			'frame'       => $this->sanitize_box( isset( $projection['frame'] ) && is_array( $projection['frame'] ) ? $projection['frame'] : array(), $canvas_width, $canvas_height, $frame_defaults ),
			'preview_views' => $this->sanitize_preview_views( isset( $projection['preview_views'] ) && is_array( $projection['preview_views'] ) ? $projection['preview_views'] : array() ),
			'mask_image_id' => absint( isset( $projection['mask_image_id'] ) ? $projection['mask_image_id'] : 0 ),
			'overlay_image_id' => absint( isset( $projection['overlay_image_id'] ) ? $projection['overlay_image_id'] : 0 ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function default_preview_views() {
		return array(
			array( 'id' => 'front', 'label' => __( 'Front view', 'flexible-product-customizer' ), 'rotation' => 0, 'enabled' => true ),
			array( 'id' => 'left', 'label' => __( 'Left side', 'flexible-product-customizer' ), 'rotation' => -45, 'enabled' => true ),
			array( 'id' => 'right', 'label' => __( 'Right side', 'flexible-product-customizer' ), 'rotation' => 45, 'enabled' => true ),
		);
	}

	/** @param array $views Raw preview views. @return array<int,array<string,mixed>> */
	private function sanitize_preview_views( array $views ) {
		$views = $views ? $views : $this->default_preview_views();
		$clean = array();
		$ids   = array();
		foreach ( array_slice( $views, 0, 6 ) as $index => $view ) {
			$id = sanitize_title( isset( $view['id'] ) ? $view['id'] : '' );
			if ( ! $id ) {
				$id = 'view-' . ( $index + 1 );
			}
			if ( in_array( $id, $ids, true ) ) {
				continue;
			}
			$ids[] = $id;
			$clean[] = array(
				'id'       => $id,
				'label'    => sanitize_text_field( isset( $view['label'] ) ? $view['label'] : $id ),
				'rotation' => max( -180, min( 180, (int) round( isset( $view['rotation'] ) ? (float) $view['rotation'] : 0 ) ) ),
				'enabled'  => ! isset( $view['enabled'] ) || ! empty( $view['enabled'] ),
			);
		}
		return $clean ? $clean : $this->default_preview_views();
	}

	/** @param mixed $value Value. @param int $min Min. @param int $max Max. @return int */
	private function integer_between( $value, $min, $max ) {
		return max( $min, min( $max, absint( $value ) ) );
	}

	/** @param array $columns Columns. @return array */
	public function columns( $columns ) {
		$columns['fpcw_type']     = __( 'Product type', 'flexible-product-customizer' );
		$columns['fpcw_surfaces'] = __( 'Surfaces', 'flexible-product-customizer' );
		$columns['fpcw_colors']   = __( 'Colors', 'flexible-product-customizer' );
		return $columns;
	}

	/** @param string $column Column key. @param int $post_id Post ID. @return void */
	public function column_content( $column, $post_id ) {
		$config = $this->get_config( $post_id );
		if ( 'fpcw_type' === $column ) {
			echo esc_html( 'cylindrical' === $config['product_type'] ? __( 'Cylindrical', 'flexible-product-customizer' ) : __( 'Flat', 'flexible-product-customizer' ) );
		}
		if ( 'fpcw_surfaces' === $column ) {
			echo esc_html( count( $config['surfaces'] ) );
		}
		if ( 'fpcw_colors' === $column ) {
			echo esc_html( count( $config['colors'] ) );
		}
	}
}
