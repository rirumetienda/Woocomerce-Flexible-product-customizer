<?php
/**
 * WooCommerce cart persistence and expiration behavior.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Cart_Integration {
	/** @var Repository */ private $repository;
	/** @var Product_Settings */ private $products;
	/** @var File_Storage */ private $storage;
	/** @var array<string,float> */ private $base_prices = array();

	public function __construct( Repository $repository, Product_Settings $products, File_Storage $storage ) {
		$this->repository = $repository;
		$this->products   = $products;
		$this->storage    = $storage;
	}

	/** @return void */
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 4 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'remove_expired_items' ) );
		add_action( 'woocommerce_remove_cart_item', array( $this, 'release_removed_item' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'display_thumbnail' ), 10, 3 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( $this, 'store_api_images' ), 10, 3 );
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( $this, 'store_api_cart_data' ), 10, 2 );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_data' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_surface_prices' ), 20 );
	}

	/** @return void */
	public function enqueue_styles() {
		if ( is_cart() || is_checkout() || is_account_page() ) {
			wp_enqueue_style( 'fpcw-storefront', FPCW_URL . 'assets/css/storefront.css', array(), FPCW_VERSION );
		}
	}

	/** @param bool $passed Passed. @param int $product_id Product. @param int $quantity Quantity. @param int $variation_id Variation. @param array $variations Variations. @param array $cart_item_data Existing data. @return bool */
	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		if ( ! $passed || ! $this->products->is_enabled( $product_id ) ) {
			return $passed;
		}
		$token = ! empty( $cart_item_data['fpcw_token'] ) ? sanitize_text_field( $cart_item_data['fpcw_token'] ) : $this->request_token();
		if ( ! $token ) {
			wc_add_notice( __( 'Customize this product before adding it to the cart.', 'flexible-product-customizer' ), 'error' );
			return false;
		}

		$valid = $this->validate_session( $token, $product_id, $variation_id );
		if ( is_wp_error( $valid ) ) {
			wc_add_notice( $valid->get_error_message(), 'error' );
			return false;
		}
		return true;
	}

	/** @param array $cart_item_data Data. @param int $product_id Product. @param int $variation_id Variation. @param int $quantity Quantity. @return array */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id, $quantity ) {
		$token = $this->request_token();
		$session = $token ? $this->validate_session( $token, $product_id, $variation_id ) : null;
		if ( $session && ! is_wp_error( $session ) ) {
			$cart_item_data['fpcw_token'] = $token;
			$cart_item_data['fpcw_surface_surcharge'] = $this->products->surface_surcharge( $session );
			$this->repository->update( $token, array( 'status' => 'cart' ) );
		}
		return $cart_item_data;
	}

	/** @param array $data Store API add data. @param \WP_REST_Request $request Request. @return array */
	public function store_api_cart_data( $data, $request ) {
		$extensions = $request->get_param( 'extensions' );
		$extensions = is_array( $extensions ) ? $extensions : array();
		$token      = isset( $extensions['flexible-product-customizer']['token'] ) ? sanitize_text_field( $extensions['flexible-product-customizer']['token'] ) : sanitize_text_field( (string) $request->get_param( 'fpcw_token' ) );
		$proof      = isset( $extensions['flexible-product-customizer']['proof'] ) ? sanitize_text_field( $extensions['flexible-product-customizer']['proof'] ) : sanitize_text_field( (string) $request->get_param( 'fpcw_proof' ) );
		if ( ! $token ) {
			return $data;
		}
		$product_id   = absint( $request->get_param( 'id' ) );
		$product      = wc_get_product( $product_id );
		$variation_id = $product instanceof \WC_Product_Variation ? $product_id : 0;
		$product_id   = $product instanceof \WC_Product_Variation ? $product->get_parent_id() : $product_id;
		$valid        = $this->validate_session( $token, $product_id, $variation_id, $proof );
		if ( is_wp_error( $valid ) ) {
			if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'fpcw_invalid_customization', $valid->get_error_message(), 400 );
			}
			return $data;
		}
		$data['cart_item_data']['fpcw_token'] = $token;
		$data['cart_item_data']['fpcw_surface_surcharge'] = $this->products->surface_surcharge( $valid );
		$this->repository->update( $token, array( 'status' => 'cart' ) );
		return $data;
	}

	/** @param string $cart_item_key Cart key. @param \WC_Cart $cart Cart. @return void */
	public function release_removed_item( $cart_item_key, $cart ) {
		$item = isset( $cart->cart_contents[ $cart_item_key ] ) ? $cart->cart_contents[ $cart_item_key ] : null;
		if ( $item && ! empty( $item['fpcw_token'] ) ) {
			$session = $this->repository->find( $item['fpcw_token'] );
			if ( $session && 'cart' === $session['status'] ) {
				$this->repository->update( $session['token'], array( 'status' => 'active' ) );
			}
		}
	}

	/**
	 * Expose the customization token, expiration, and previews in Store API cart items.
	 *
	 * @return void
	 */
	public function register_store_api_data() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CartItemSchema' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace'       => 'flexible-product-customizer',
				'data_callback'   => function ( $cart_item ) {
					$session = $this->session_from_cart_item( $cart_item );
					if ( ! $session ) {
						return array();
					}
					$previews = array();
					$used_ids = $this->products->used_surface_ids( $session['payload'] );
					foreach ( isset( $session['payload']['previews'] ) ? $session['payload']['previews'] : array() as $preview ) {
						if ( $used_ids && ! in_array( $preview['surface_id'], $used_ids, true ) ) {
							continue;
						}
						$previews[] = array(
							'surface_id' => $preview['surface_id'],
							'view_id'    => isset( $preview['view_id'] ) ? $preview['view_id'] : 'default',
							'view_label' => isset( $preview['view_label'] ) ? $preview['view_label'] : '',
							'rotation'   => isset( $preview['rotation'] ) ? (int) $preview['rotation'] : 0,
							'url'        => $this->storage->public_url( $preview['relative_path'] ),
						);
					}
					return array(
						'token'       => $session['token'],
						'expires_at'  => $this->repository->expiration_iso( $session ),
						'previews'    => $previews,
						'surcharge'   => $this->products->surface_surcharge( $session ),
					);
				},
				'schema_callback' => static function () {
					return array(
						'properties' => array(
							'token' => array( 'type' => 'string', 'readonly' => true ),
							'expires_at' => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
							'previews' => array( 'type' => 'array', 'readonly' => true, 'items' => array( 'type' => 'object' ) ),
							'surcharge' => array( 'type' => 'number', 'readonly' => true ),
						),
					);
				},
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/** @param \WC_Cart $cart Cart. @return void */
	public function remove_expired_items( $cart ) {
		$removed = false;
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['fpcw_token'] ) ) {
				continue;
			}
			$session = $this->repository->find( $item['fpcw_token'] );
			if ( ! $session || $this->repository->is_expired( $session ) ) {
				$cart->remove_cart_item( $key );
				$removed = true;
				if ( $session && 'ordered' !== $session['status'] ) {
					$this->storage->delete_temporary_session( $session['token'] );
					$this->repository->delete( $session['token'] );
				}
			}
		}
		if ( $removed && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'An expired customization and its files were removed from your cart.', 'flexible-product-customizer' ), 'notice' );
		}
	}

	/** @param array $item_data Display data. @param array $cart_item Cart item. @return array */
	public function display_item_data( $item_data, $cart_item ) {
		$session = $this->session_from_cart_item( $cart_item );
		if ( ! $session ) {
			return $item_data;
		}
		$payload = $this->storage->hydrate_payload_urls( $session['payload'], $session );
		$expiry  = $this->repository->expiration_display( $session );
		$summary = '<span class="fpcw-cart-summary-title">' . esc_html( $payload['template_name'] ) . '</span>';
		$summary .= '<small class="fpcw-cart-summary-expiry">' . esc_html__( 'Available until', 'flexible-product-customizer' ) . ': ' . esc_html( $expiry ) . '</small>';
		$item_data[] = array( 'key' => __( 'Customization', 'flexible-product-customizer' ), 'value' => esc_html( $payload['template_name'] ), 'display' => $summary );

		$used_surfaces = $this->products->used_surface_labels( $session['payload'] );
		if ( $used_surfaces ) {
			$item_data[] = array( 'key' => __( 'Surfaces', 'flexible-product-customizer' ), 'value' => esc_html( implode( ', ', $used_surfaces ) ) );
		}
		$surcharge = $this->products->surface_surcharge( $session );
		if ( $surcharge > 0 ) {
			$item_data[] = array( 'key' => __( 'Customization surcharge', 'flexible-product-customizer' ), 'value' => wp_strip_all_tags( wc_price( $surcharge ) ), 'display' => wc_price( $surcharge ) );
		}

		$edit_url = add_query_arg( 'fpc_edit', $session['token'], get_permalink( $session['product_id'] ) );
		$new_args = array( 'fpc_new' => '1' );
		if ( ! empty( $session['variation_id'] ) ) {
			$new_args['fpc_variation_id'] = (int) $session['variation_id'];
		}
		$add_url = add_query_arg( $new_args, get_permalink( $session['product_id'] ) );
		$actions = '<span class="fpcw-cart-actions"><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit customization', 'flexible-product-customizer' ) . '</a><a href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add another customization', 'flexible-product-customizer' ) . '</a></span>';
		$item_data[] = array( 'key' => __( 'Design', 'flexible-product-customizer' ), 'value' => __( 'Edit customization', 'flexible-product-customizer' ), 'display' => $actions );
		return $item_data;
	}

	/** @param string $thumbnail Thumbnail HTML. @param array $cart_item Cart item. @param string $cart_item_key Key. @return string */
	public function display_thumbnail( $thumbnail, $cart_item, $cart_item_key ) {
		$session = $this->session_from_cart_item( $cart_item );
		if ( ! $session || empty( $session['payload']['previews'] ) ) {
			return $thumbnail;
		}
		$previews = '';
		$used_ids = $this->products->used_surface_ids( $session['payload'] );
		foreach ( $session['payload']['previews'] as $preview ) {
			if ( empty( $preview['relative_path'] ) || ( $used_ids && ! in_array( $preview['surface_id'], $used_ids, true ) ) ) {
				continue;
			}
			$url = $this->storage->public_url( $preview['relative_path'] );
			$label = ! empty( $preview['view_label'] ) ? $preview['view_label'] : __( 'Customization preview', 'flexible-product-customizer' );
			$previews .= '<a class="fpcw-cart-preview-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $label ) . '"><img class="fpcw-cart-preview" src="' . esc_url( $url ) . '" alt="' . esc_attr( $label ) . '" loading="lazy"></a>';
		}
		return $thumbnail . ( $previews ? '<span class="fpcw-cart-preview-list">' . $previews . '</span>' : '' );
	}

	/** @param array $images Store API images. @param array $cart_item Cart item. @param string $cart_item_key Cart key. @return array */
	public function store_api_images( $images, $cart_item, $cart_item_key ) {
		$session = $this->session_from_cart_item( $cart_item );
		if ( ! $session || empty( $session['payload']['previews'] ) ) {
			return $images;
		}
		$used_ids = $this->products->used_surface_ids( $session['payload'] );
		$preview = null;
		foreach ( $session['payload']['previews'] as $candidate ) {
			if ( empty( $candidate['relative_path'] ) || ( $used_ids && ! in_array( $candidate['surface_id'], $used_ids, true ) ) ) {
				continue;
			}
			$preview = $candidate;
			break;
		}
		if ( ! $preview ) {
			return $images;
		}
		$url   = $this->storage->public_url( $preview['relative_path'] );
		$image = ! empty( $images[0] ) ? (array) $images[0] : array();
		$image = (object) array_merge(
			$image,
			array(
				'id' => 0, 'src' => $url, 'thumbnail' => $url, 'srcset' => '', 'sizes' => '',
				'name' => __( 'Customization preview', 'flexible-product-customizer' ),
				'alt' => __( 'Customization preview', 'flexible-product-customizer' ),
			)
		);
		return array( $image );
	}

	/** @param \WC_Cart $cart Cart. @return void */
	public function apply_surface_prices( $cart ) {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['fpcw_token'] ) || empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof \WC_Product ) {
				continue;
			}
			$session = $this->repository->find( $cart_item['fpcw_token'] );
			if ( ! $session || $this->repository->is_expired( $session ) ) {
				continue;
			}
			if ( ! isset( $this->base_prices[ $cart_item_key ] ) ) {
				$this->base_prices[ $cart_item_key ] = (float) $cart_item['data']->get_price( 'edit' );
			}
			$surcharge = $this->products->surface_surcharge( $session );
			$cart_item['data']->set_price( $this->base_prices[ $cart_item_key ] + $surcharge );
			$cart->cart_contents[ $cart_item_key ]['fpcw_surface_surcharge'] = $surcharge;
		}
	}

	/** @param string $token Token. @param int $product_id Product ID. @param int $variation_id Variation. @return array|\WP_Error */
	private function validate_session( $token, $product_id, $variation_id, $proof = '' ) {
		$session = $this->repository->find( $token );
		if ( ! $session || ! in_array( $session['status'], array( 'active', 'cart' ), true ) ) {
			return new \WP_Error( 'fpcw_incomplete', __( 'Save the customization before adding this product to the cart.', 'flexible-product-customizer' ) );
		}
		if ( $this->repository->is_expired( $session ) ) {
			return new \WP_Error( 'fpcw_expired', __( 'This customization has expired. Create a new design.', 'flexible-product-customizer' ) );
		}
		$authorized = Session_Identity::owns( $session ) || $this->repository->verify_cart_proof( $token, $proof );
		if ( ! $authorized || (int) $session['product_id'] !== (int) $product_id || (int) $session['variation_id'] !== (int) $variation_id ) {
			return new \WP_Error( 'fpcw_mismatch', __( 'The customization does not match the selected product options.', 'flexible-product-customizer' ) );
		}
		return $session;
	}

	/** @return string */
	private function request_token() {
		return isset( $_REQUEST['fpcw_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['fpcw_token'] ) ) : '';
	}

	/** @param array $cart_item Cart item. @return array|null */
	private function session_from_cart_item( $cart_item ) {
		return ! empty( $cart_item['fpcw_token'] ) ? $this->repository->find( $cart_item['fpcw_token'] ) : null;
	}
}
