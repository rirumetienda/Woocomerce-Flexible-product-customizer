<?php
/**
 * WooCommerce product-level customization settings.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Product_Settings {
	const SURFACE_META = '_fpcw_surface_settings';

	/** @var Template_Manager */
	private $templates;

	public function __construct( Template_Manager $templates ) {
		$this->templates = $templates;
	}

	/** @return void */
	public function register_hooks() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/** @param array $tabs Tabs. @return array */
	public function add_tab( $tabs ) {
		$tabs['fpcw'] = array(
			'label'    => __( 'Customization', 'flexible-product-customizer' ),
			'target'   => 'fpcw_product_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 75,
		);
		return $tabs;
	}

	/** @return void */
	public function render_panel() {
		global $post;
		$product_id       = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$template_id      = $this->template_id( $product_id );
		$allowed_colors   = $this->allowed_color_ids( $product_id, $template_id );
		$surface_settings = $this->surface_settings( $product_id, $template_id );
		?>
		<div id="fpcw_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_fpcw_enabled',
						'label'       => __( 'Customizable product', 'flexible-product-customizer' ),
						'description' => __( 'Display the visual editor on this product.', 'flexible-product-customizer' ),
					)
				);
				woocommerce_wp_select(
					array(
						'id'          => '_fpcw_template_id',
						'label'       => __( 'Template', 'flexible-product-customizer' ),
						'options'     => $this->templates->choices(),
						'description' => __( 'Defines product colors, printable areas, fonts, and limits.', 'flexible-product-customizer' ),
						'desc_tip'    => true,
					)
				);
				?>
				<div class="fpcw-product-field">
					<label><?php esc_html_e( 'Available color attributes', 'flexible-product-customizer' ); ?></label>
					<div id="fpcw-product-colors" class="fpcw-product-derived-control"></div>
					<input type="hidden" id="_fpcw_allowed_colors" name="_fpcw_allowed_colors" value="<?php echo esc_attr( wp_json_encode( $allowed_colors ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Choose which template color attributes can be purchased with this product.', 'flexible-product-customizer' ); ?></p>
				</div>
				<div class="fpcw-product-field">
					<label><?php esc_html_e( 'Available surfaces and price increments', 'flexible-product-customizer' ); ?></label>
					<div id="fpcw-product-surfaces" class="fpcw-product-derived-control"></div>
					<input type="hidden" id="_fpcw_surface_settings" name="_fpcw_surface_settings" value="<?php echo esc_attr( wp_json_encode( $surface_settings ) ); ?>" />
					<p class="description"><?php esc_html_e( 'A price increment is charged only when the customer adds content to that surface. Use zero for the base surface.', 'flexible-product-customizer' ); ?></p>
				</div>
				<?php
				woocommerce_wp_text_input(
					array(
						'id'          => '_fpcw_color_attribute',
						'label'       => __( 'Variation color attribute', 'flexible-product-customizer' ),
						'placeholder' => 'pa_color',
						'description' => __( 'Optional WooCommerce attribute slug synchronized with template color variation values.', 'flexible-product-customizer' ),
						'desc_tip'    => true,
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	/** @param string $hook Current admin hook. @return void */
	public function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'fpcw-admin', FPCW_URL . 'assets/css/admin.css', array(), FPCW_VERSION );
		wp_enqueue_script( 'fpcw-admin-product', FPCW_URL . 'assets/js/admin-product.js', array(), FPCW_VERSION, true );
		wp_localize_script(
			'fpcw-admin-product',
			'FPCW_PRODUCT_ADMIN',
			array(
				'templates'      => $this->templates->product_editor_data(),
				'currencySymbol' => get_woocommerce_currency_symbol(),
				'priceDecimals'  => wc_get_price_decimals(),
				'i18n'           => array(
					'chooseTemplate' => __( 'Select a template to configure its colors and surfaces.', 'flexible-product-customizer' ),
					'selectAll'      => __( 'Select all', 'flexible-product-customizer' ),
					'noColors'       => __( 'The selected template has no colors.', 'flexible-product-customizer' ),
					'noSurfaces'     => __( 'The selected template has no surfaces.', 'flexible-product-customizer' ),
					'enabled'        => __( 'Enabled', 'flexible-product-customizer' ),
					'priceIncrement' => __( 'Price increment when used', 'flexible-product-customizer' ),
					'baseSurface'    => __( 'Use 0 for a surface included in the product price.', 'flexible-product-customizer' ),
					'availableFor'   => __( 'Available for: %s', 'flexible-product-customizer' ),
				),
			)
		);
	}

	/** @param \WC_Product $product Product being saved. @return void */
	public function save( $product ) {
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}

		$template_id = isset( $_POST['_fpcw_template_id'] ) ? absint( $_POST['_fpcw_template_id'] ) : 0;
		$product->update_meta_data( '_fpcw_enabled', isset( $_POST['_fpcw_enabled'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_fpcw_template_id', $template_id );
		$product->update_meta_data( '_fpcw_allowed_colors', $this->sanitize_posted_colors( $template_id ) );
		$product->update_meta_data( self::SURFACE_META, $this->sanitize_posted_surfaces( $template_id ) );
		$product->update_meta_data( '_fpcw_color_attribute', isset( $_POST['_fpcw_color_attribute'] ) ? sanitize_title( wp_unslash( $_POST['_fpcw_color_attribute'] ) ) : '' );
	}

	/** @param int $product_id Product ID. @return bool */
	public function is_enabled( $product_id ) {
		return 'yes' === get_post_meta( absint( $product_id ), '_fpcw_enabled', true ) && $this->template_id( $product_id ) > 0;
	}

	/** @param int $product_id Product ID. @return int */
	public function template_id( $product_id ) {
		return absint( get_post_meta( absint( $product_id ), '_fpcw_template_id', true ) );
	}

	/** @param int $product_id Product ID. @return string */
	public function color_attribute( $product_id ) {
		return sanitize_title( get_post_meta( absint( $product_id ), '_fpcw_color_attribute', true ) );
	}

	/**
	 * Produce the final config shared by browser, REST, and validation.
	 *
	 * @param int $product_id Product ID.
	 * @return array|null
	 */
	public function get_product_config( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $this->is_enabled( $product_id ) ) {
			return null;
		}

		$template_id = $this->template_id( $product_id );
		$config      = $this->templates->get_public_config( $template_id );
		$allowed     = $this->allowed_color_ids( $product_id, $template_id );
		$config['colors'] = array_values(
			array_filter(
				$config['colors'],
				static function ( $color ) use ( $allowed ) {
					return in_array( $color['id'], $allowed, true );
				}
			)
		);
		$supported_surface_ids = array();
		foreach ( $config['colors'] as $color ) {
			foreach ( $color['surfaces'] as $surface_id => $assignment ) {
				if ( ! empty( $assignment['enabled'] ) ) {
					$supported_surface_ids[] = $surface_id;
				}
			}
		}
		$config['surfaces'] = array_values(
			array_filter(
				$config['surfaces'],
				static function ( $surface ) use ( $supported_surface_ids ) {
					return in_array( $surface['id'], $supported_surface_ids, true );
				}
			)
		);

		$surface_settings = $this->surface_settings( $product_id, $template_id );
		$config['surfaces'] = array_values(
			array_filter(
				array_map(
					static function ( $surface ) use ( $surface_settings ) {
						$settings = isset( $surface_settings[ $surface['id'] ] ) ? $surface_settings[ $surface['id'] ] : array();
						if ( empty( $settings['enabled'] ) ) {
							return null;
						}
						$surface['price_increment'] = (float) $settings['price'];
						$surface['price_display']   = html_entity_decode( wp_strip_all_tags( wc_price( $surface['price_increment'] ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
						return $surface;
					},
					$config['surfaces']
				)
			)
		);
		$surface_ids = wp_list_pluck( $config['surfaces'], 'id' );
		foreach ( $config['colors'] as &$color ) {
			foreach ( $color['surfaces'] as $surface_id => &$assignment ) {
				$assignment['enabled'] = in_array( $surface_id, $surface_ids, true ) && ! empty( $assignment['enabled'] );
			}
			unset( $assignment );
		}
		unset( $color );
		$config['colors'] = array_values(
			array_filter(
				$config['colors'],
				static function ( $color ) {
					foreach ( $color['surfaces'] as $assignment ) {
						if ( ! empty( $assignment['enabled'] ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);

		if ( ! $config['colors'] || ! $config['surfaces'] ) {
			return null;
		}

		$config['template_id']     = $template_id;
		$config['template_name']   = get_the_title( $template_id );
		$config['product_id']      = $product_id;
		$config['required']        = true;
		$config['color_attribute'] = $this->color_attribute( $product_id );
		$config['expires_in']      = 7 * DAY_IN_SECONDS;
		return $config;
	}

	/** @param int $product_id Product ID. @return array<int,array<string,mixed>> */
	public function surface_extras( $product_id ) {
		$config = $this->get_product_config( $product_id );
		$extras = array();
		foreach ( $config && isset( $config['surfaces'] ) ? $config['surfaces'] : array() as $surface ) {
			if ( ! empty( $surface['price_increment'] ) ) {
				$extras[] = array(
					'id'      => $surface['id'],
					'label'   => $surface['label'],
					'price'   => (float) $surface['price_increment'],
					'display' => $surface['price_display'],
				);
			}
		}
		return $extras;
	}

	/**
	 * Sum configured increments only for surfaces containing customer objects.
	 *
	 * @param array $session Customization session.
	 * @return float
	 */
	public function surface_surcharge( array $session ) {
		$payload  = isset( $session['payload'] ) && is_array( $session['payload'] ) ? $session['payload'] : array();
		$snapshot = isset( $payload['template_snapshot'] ) && is_array( $payload['template_snapshot'] ) ? $payload['template_snapshot'] : array();
		$design   = isset( $payload['design']['surfaces'] ) && is_array( $payload['design']['surfaces'] ) ? $payload['design']['surfaces'] : array();
		$prices   = array();
		foreach ( isset( $snapshot['surfaces'] ) && is_array( $snapshot['surfaces'] ) ? $snapshot['surfaces'] : array() as $surface ) {
			$prices[ $surface['id'] ] = isset( $surface['price_increment'] ) ? max( 0, (float) $surface['price_increment'] ) : 0;
		}

		$total = 0;
		foreach ( $design as $surface ) {
			$id = isset( $surface['id'] ) ? sanitize_title( $surface['id'] ) : '';
			if ( $id && ! empty( $surface['objects'] ) && isset( $prices[ $id ] ) ) {
				$total += $prices[ $id ];
			}
		}
		return (float) wc_format_decimal( $total, wc_get_price_decimals() );
	}

	/** @param array $payload Stored customization payload. @return array<int,string> */
	public function used_surface_ids( array $payload ) {
		$used = array();
		foreach ( isset( $payload['design']['surfaces'] ) ? $payload['design']['surfaces'] : array() as $surface ) {
			$id = isset( $surface['id'] ) ? sanitize_title( $surface['id'] ) : '';
			if ( $id && ! empty( $surface['objects'] ) ) {
				$used[] = $id;
			}
		}
		return array_values( array_unique( $used ) );
	}

	/** @param array $payload Stored customization payload. @return array */
	public function used_surface_labels( array $payload ) {
		$labels = array();
		foreach ( isset( $payload['template_snapshot']['surfaces'] ) ? $payload['template_snapshot']['surfaces'] : array() as $surface ) {
			$labels[ $surface['id'] ] = $surface['label'];
		}
		$used = array();
		foreach ( $this->used_surface_ids( $payload ) as $surface_id ) {
			if ( isset( $labels[ $surface_id ] ) ) {
				$used[] = $labels[ $surface_id ];
			}
		}
		return $used;
	}

	/** @param int $product_id Product ID. @param int $template_id Template ID. @return array */
	private function allowed_color_ids( $product_id, $template_id ) {
		$config = $template_id ? $this->templates->get_config( $template_id ) : array( 'colors' => array() );
		$valid  = wp_list_pluck( $config['colors'], 'id' );
		$stored = get_post_meta( absint( $product_id ), '_fpcw_allowed_colors', true );
		if ( is_string( $stored ) ) {
			$stored = array_filter( array_map( 'sanitize_title', explode( ',', $stored ) ) );
		}
		$stored = is_array( $stored ) ? array_map( 'sanitize_title', $stored ) : array();
		$stored = array_values( array_intersect( $valid, $stored ) );
		return $stored ? $stored : $valid;
	}

	/** @param int $product_id Product ID. @param int $template_id Template ID. @return array */
	private function surface_settings( $product_id, $template_id ) {
		$config = $template_id ? $this->templates->get_config( $template_id ) : array( 'surfaces' => array() );
		$stored = get_post_meta( absint( $product_id ), self::SURFACE_META, true );
		$stored = is_array( $stored ) ? $stored : array();
		$result = array();
		foreach ( $config['surfaces'] as $surface ) {
			$existing = isset( $stored[ $surface['id'] ] ) && is_array( $stored[ $surface['id'] ] ) ? $stored[ $surface['id'] ] : null;
			$result[ $surface['id'] ] = array(
				'enabled' => null === $existing ? true : ! empty( $existing['enabled'] ),
				'price'   => null === $existing ? 0 : max( 0, (float) wc_format_decimal( isset( $existing['price'] ) ? $existing['price'] : 0 ) ),
			);
		}
		return $result;
	}

	/** @param int $template_id Template ID. @return array */
	private function sanitize_posted_colors( $template_id ) {
		$config = $template_id ? $this->templates->get_config( $template_id ) : array( 'colors' => array() );
		$valid  = wp_list_pluck( $config['colors'], 'id' );
		$raw    = isset( $_POST['_fpcw_allowed_colors'] ) ? json_decode( wp_unslash( $_POST['_fpcw_allowed_colors'] ), true ) : array();
		$raw    = is_array( $raw ) ? array_map( 'sanitize_title', $raw ) : array();
		$result = array_values( array_intersect( $valid, $raw ) );
		return $result ? $result : array_slice( $valid, 0, 1 );
	}

	/** @param int $template_id Template ID. @return array */
	private function sanitize_posted_surfaces( $template_id ) {
		$config = $template_id ? $this->templates->get_config( $template_id ) : array( 'surfaces' => array() );
		$raw    = isset( $_POST[ self::SURFACE_META ] ) ? json_decode( wp_unslash( $_POST[ self::SURFACE_META ] ), true ) : array();
		$raw    = is_array( $raw ) ? $raw : array();
		$result = array();
		$enabled_count = 0;
		foreach ( $config['surfaces'] as $surface ) {
			$item    = isset( $raw[ $surface['id'] ] ) && is_array( $raw[ $surface['id'] ] ) ? $raw[ $surface['id'] ] : array();
			$enabled = ! empty( $item['enabled'] );
			if ( $enabled ) {
				++$enabled_count;
			}
			$result[ $surface['id'] ] = array(
				'enabled' => $enabled,
				'price'   => max( 0, (float) wc_format_decimal( isset( $item['price'] ) ? $item['price'] : 0 ) ),
			);
		}
		if ( ! $enabled_count && $result ) {
			$first = array_key_first( $result );
			$result[ $first ]['enabled'] = true;
		}
		return $result;
	}
}
