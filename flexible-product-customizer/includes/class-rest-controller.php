<?php
/**
 * Public storefront and authenticated integration REST contract.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Rest_Controller {
	const NAMESPACE = 'fpcw/v1';

	/** @var Repository */ private $repository;
	/** @var File_Storage */ private $storage;
	/** @var Template_Manager */ private $templates;
	/** @var Product_Settings */ private $products;
	/** @var Validator */ private $validator;

	public function __construct( Repository $repository, File_Storage $storage, Template_Manager $templates, Product_Settings $products, Validator $validator ) {
		$this->repository = $repository;
		$this->storage    = $storage;
		$this->templates  = $templates;
		$this->products   = $products;
		$this->validator  = $validator;
	}

	/** @return void */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/** @return void */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/configuration',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'product_configuration' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_session' ),
				'permission_callback' => array( $this, 'storefront_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions/(?P<token>[a-f0-9]{64})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_session' ),
				'permission_callback' => array( $this, 'session_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions/(?P<token>[a-f0-9]{64})/files',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_file' ),
				'permission_callback' => array( $this, 'session_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions/(?P<token>[a-f0-9]{64})/save',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_session' ),
				'permission_callback' => array( $this, 'session_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions/(?P<token>[a-f0-9]{64})/renders',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_render' ),
				'permission_callback' => array( $this, 'session_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bridge-tokens',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_bridge_token' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	/** @param \WP_REST_Request $request Request. @return \WP_REST_Response|\WP_Error */
	public function product_configuration( $request ) {
		$config = $this->products->get_product_config( $request['id'] );
		if ( ! $config ) {
			return new \WP_Error( 'fpcw_not_customizable', __( 'This product is not available for customization.', 'flexible-product-customizer' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'configuration' => $config, 'editor_url' => add_query_arg( 'fpc_webview', '1', get_permalink( $request['id'] ) ) ) );
	}

	/** @param \WP_REST_Request $request Request. @return \WP_REST_Response|\WP_Error */
	public function create_session( $request ) {
		$product_id   = absint( $request->get_param( 'product_id' ) );
		$variation_id = absint( $request->get_param( 'variation_id' ) );
		$config       = $this->products->get_product_config( $product_id );
		if ( ! $config ) {
			return new \WP_Error( 'fpcw_not_customizable', __( 'This product cannot be customized.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}
		if ( ! $this->valid_variation( $product_id, $variation_id ) ) {
			return new \WP_Error( 'fpcw_invalid_variation', __( 'Choose valid product options before opening the editor.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}
		if ( $this->repository->count_open_for_current_owner() >= 20 ) {
			return new \WP_Error( 'fpcw_session_limit', __( 'You have too many open customizations. Wait for old sessions to expire or complete an existing design.', 'flexible-product-customizer' ), array( 'status' => 429 ) );
		}

		$session = $this->repository->create(
			array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'template_id'  => $config['template_id'],
				'payload'      => array(
					'schema_version'   => 1,
					'template_name'    => $config['template_name'],
					'template_snapshot'=> $config,
					'uploads'          => array(),
					'previews'         => array(),
					'production_files' => array(),
					'design'           => array(),
				),
			)
		);
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		return rest_ensure_response( $this->session_response( $session ) );
	}

	/** @param \WP_REST_Request $request Request. @return \WP_REST_Response */
	public function get_session( $request ) {
		return rest_ensure_response( $this->session_response( $this->repository->find( $request['token'] ) ) );
	}

	/** @param \WP_REST_Request $request Request. @return \WP_REST_Response|\WP_Error */
	public function upload_file( $request ) {
		$session = $this->repository->find( $request['token'] );
		$files   = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'fpcw_missing_upload', __( 'Select an image to upload.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}
		$uploads   = isset( $session['payload']['uploads'] ) && is_array( $session['payload']['uploads'] ) ? $session['payload']['uploads'] : array();
		$max_files = 0;
		foreach ( $session['payload']['template_snapshot']['surfaces'] as $surface ) {
			$max_files += (int) $surface['max_images'];
		}
		$max_files = max( 0, min( 30, $max_files ) );
		$total     = array_sum( wp_list_pluck( $uploads, 'size' ) );
		if ( 0 === $max_files || count( $uploads ) >= $max_files || $total + (int) $files['file']['size'] > 50 * MB_IN_BYTES ) {
			return new \WP_Error( 'fpcw_upload_quota', __( 'This customization has reached its temporary upload quota.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}

		$file = $this->storage->save_source_upload( $session, $files['file'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$stored = $file;
		unset( $stored['url'] );
		$payload              = $session['payload'];
		$payload['uploads'][] = $stored;
		if ( ! $this->repository->update( $session['token'], array( 'payload' => $payload ) ) ) {
			$this->storage->delete_file_records( array( $stored ) );
			return new \WP_Error( 'fpcw_database_error', __( 'The uploaded image could not be attached to the customization.', 'flexible-product-customizer' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'file' => $file ) );
	}

	/** @param \WP_REST_Request $request Request. @return \WP_REST_Response|\WP_Error */
	public function upload_render( $request ) {
		$session    = $this->repository->find( $request['token'] );
		$surface_id = sanitize_key( $request->get_param( 'surface_id' ) );
		$kind       = 'production' === $request->get_param( 'kind' ) ? 'production' : 'preview';
		$view_id    = sanitize_key( $request->get_param( 'view_id' ) );
		$view_label = sanitize_text_field( (string) $request->get_param( 'view_label' ) );
		$rotation   = null !== $request->get_param( 'rotation' ) ? (int) round( (float) $request->get_param( 'rotation' ) ) : 0;
		$files      = $request->get_file_params();
		$surfaces   = wp_list_pluck( $session['payload']['template_snapshot']['surfaces'], 'id' );
		if ( ! in_array( $surface_id, $surfaces, true ) || empty( $files['file'] ) ) {
			return new \WP_Error( 'fpcw_invalid_render', __( 'The generated surface image is invalid.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}

		$file = $this->storage->save_render( $session, $surface_id, $kind, $files['file'], $view_id, $view_label, $rotation );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$stored = $file;
		unset( $stored['url'] );
		$collection = 'preview' === $kind ? 'previews' : 'production_files';
		$records    = isset( $session['payload'][ $collection ] ) && is_array( $session['payload'][ $collection ] ) ? $session['payload'][ $collection ] : array();
		$records    = array_values(
			array_filter(
				$records,
				static function ( $record ) use ( $surface_id, $kind, $file ) {
					if ( ! isset( $record['surface_id'] ) || $record['surface_id'] !== $surface_id ) {
						return true;
					}
					if ( 'preview' !== $kind ) {
						return false;
					}
					return sanitize_key( isset( $record['view_id'] ) ? $record['view_id'] : 'default' ) !== $file['view_id'];
				}
			)
		);
		$records[] = $stored;
		$payload   = $session['payload'];
		$payload[ $collection ] = $records;
		if ( ! $this->repository->update( $session['token'], array( 'payload' => $payload ) ) ) {
			return new \WP_Error( 'fpcw_database_error', __( 'The generated image could not be attached to the customization.', 'flexible-product-customizer' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'file' => $file ) );
	}

	/** @param \WP_REST_Request $request Request. @return \WP_REST_Response|\WP_Error */
	public function save_session( $request ) {
		$session = $this->repository->find( $request['token'] );
		$raw     = $request->get_param( 'design' );
		$design  = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $design ) ) {
			return new \WP_Error( 'fpcw_invalid_design', __( 'The design data is invalid.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}

		$validated = $this->validator->validate_design( $design, $session );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$variation_id = absint( $request->get_param( 'variation_id' ) );
		if ( ! $this->valid_variation( $session['product_id'], $variation_id ) ) {
			return new \WP_Error( 'fpcw_invalid_variation', __( 'The selected product options are invalid.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}
		if ( 'cart' === $session['status'] && (int) $session['variation_id'] !== $variation_id ) {
			return new \WP_Error( 'fpcw_cart_variation_locked', __( 'Remove the item from the cart before changing its product options.', 'flexible-product-customizer' ), array( 'status' => 409 ) );
		}
		if ( ! $this->color_matches_variation( $session, $validated['color_id'], $variation_id ) ) {
			return new \WP_Error( 'fpcw_color_mismatch', __( 'The design color does not match the selected WooCommerce variation.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}

		$used_ids = $validated['used_file_ids'];
		unset( $validated['used_file_ids'] );
		$payload                     = $session['payload'];
		$production_ids              = wp_list_pluck( isset( $payload['production_files'] ) ? $payload['production_files'] : array(), 'surface_id' );
		$normalized_snapshot = $this->templates->normalize_snapshot( $payload['template_snapshot'] );
		$required_surface_ids = $this->templates->available_surface_ids( $normalized_snapshot, $validated['color_id'] );
		$required_preview_keys = array();
		$preview_keys = array();
		foreach ( isset( $payload['previews'] ) && is_array( $payload['previews'] ) ? $payload['previews'] : array() as $preview ) {
			$preview_keys[] = $this->preview_render_key( $preview );
		}
		foreach ( $normalized_snapshot['surfaces'] as $surface ) {
			if ( ! in_array( $surface['id'], $required_surface_ids, true ) ) {
				continue;
			}
			foreach ( $this->preview_views_for_surface( $normalized_snapshot, $surface ) as $view ) {
				$key = $surface['id'] . ':' . $view['id'];
				$required_preview_keys[] = $key;
				if ( ! in_array( $key, $preview_keys, true ) ) {
					return new \WP_Error( 'fpcw_missing_render', sprintf( __( 'The preview for %s is missing.', 'flexible-product-customizer' ), $surface['label'] . ' - ' . $view['label'] ), array( 'status' => 400 ) );
				}
			}
			if ( ! in_array( $surface['id'], $production_ids, true ) ) {
				return new \WP_Error( 'fpcw_missing_render', sprintf( __( 'The preview for %s is missing.', 'flexible-product-customizer' ), $surface['label'] ), array( 'status' => 400 ) );
			}
		}
		foreach ( array( 'previews', 'production_files' ) as $collection ) {
			$records = isset( $payload[ $collection ] ) && is_array( $payload[ $collection ] ) ? $payload[ $collection ] : array();
			$stale   = array_values(
				array_filter(
					$records,
					function ( $record ) use ( $collection, $required_surface_ids, $required_preview_keys ) {
						if ( empty( $record['surface_id'] ) || ! in_array( $record['surface_id'], $required_surface_ids, true ) ) {
							return true;
						}
						return 'previews' === $collection && ! in_array( $this->preview_render_key( $record ), $required_preview_keys, true );
					}
				)
			);
			if ( $stale ) {
				$this->storage->delete_file_records( $stale );
			}
			$payload[ $collection ] = array_values(
				array_filter(
					$records,
					function ( $record ) use ( $collection, $required_surface_ids, $required_preview_keys ) {
						if ( empty( $record['surface_id'] ) || ! in_array( $record['surface_id'], $required_surface_ids, true ) ) {
							return false;
						}
						return 'previews' !== $collection || in_array( $this->preview_render_key( $record ), $required_preview_keys, true );
					}
				)
			);
		}
		$payload['uploads']          = $this->storage->prune_uploads( isset( $payload['uploads'] ) ? $payload['uploads'] : array(), $used_ids );
		$payload['design']           = $validated;
		$updated = $this->repository->update(
			$session['token'],
			array( 'payload' => $payload, 'variation_id' => $variation_id, 'status' => 'cart' === $session['status'] ? 'cart' : 'active' )
		);
		if ( ! $updated ) {
			return new \WP_Error( 'fpcw_database_error', __( 'The customization could not be saved. Please try again.', 'flexible-product-customizer' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->session_response( $this->repository->find( $session['token'] ) ) );
	}

	/** @param array $record Preview file record. @return string */
	private function preview_render_key( array $record ) {
		return sanitize_key( isset( $record['surface_id'] ) ? $record['surface_id'] : '' ) . ':' . sanitize_key( isset( $record['view_id'] ) ? $record['view_id'] : 'default' );
	}

	/** @param array $snapshot Template snapshot. @param array $surface Surface config. @return array<int,array<string,mixed>> */
	private function preview_views_for_surface( array $snapshot, array $surface ) {
		if ( 'cylindrical' !== ( isset( $snapshot['product_type'] ) ? $snapshot['product_type'] : '' ) ) {
			return array( array( 'id' => 'default', 'label' => isset( $surface['label'] ) ? $surface['label'] : __( 'Customization preview', 'flexible-product-customizer' ), 'rotation' => 0, 'enabled' => true ) );
		}
		$views = isset( $surface['projection']['preview_views'] ) && is_array( $surface['projection']['preview_views'] ) ? $surface['projection']['preview_views'] : array();
		if ( ! $views ) {
			$views = array(
				array( 'id' => 'front', 'label' => __( 'Front view', 'flexible-product-customizer' ), 'rotation' => 0, 'enabled' => true ),
				array( 'id' => 'left', 'label' => __( 'Left side', 'flexible-product-customizer' ), 'rotation' => -45, 'enabled' => true ),
				array( 'id' => 'right', 'label' => __( 'Right side', 'flexible-product-customizer' ), 'rotation' => 45, 'enabled' => true ),
			);
		}
		$clean = array();
		foreach ( array_slice( $views, 0, 6 ) as $index => $view ) {
			if ( isset( $view['enabled'] ) && ! $view['enabled'] ) {
				continue;
			}
			$id = sanitize_key( isset( $view['id'] ) ? $view['id'] : 'view-' . ( $index + 1 ) );
			$clean[] = array(
				'id'       => $id ? $id : 'view-' . ( $index + 1 ),
				'label'    => sanitize_text_field( isset( $view['label'] ) ? $view['label'] : $id ),
				'rotation' => max( -180, min( 180, (int) round( isset( $view['rotation'] ) ? (float) $view['rotation'] : 0 ) ) ),
			);
		}
		return $clean ? $clean : array( array( 'id' => 'front', 'label' => __( 'Front view', 'flexible-product-customizer' ), 'rotation' => 0, 'enabled' => true ) );
	}

	/** @param \WP_REST_Request $request Request. @return \WP_REST_Response|\WP_Error */
	public function create_bridge_token( $request ) {
		$product_id = absint( $request->get_param( 'product_id' ) );
		if ( ! $this->products->get_product_config( $product_id ) ) {
			return new \WP_Error( 'fpcw_not_customizable', __( 'This product cannot be customized.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}
		$variation_id = absint( $request->get_param( 'variation_id' ) );
		if ( ! $this->valid_variation( $product_id, $variation_id ) ) {
			return new \WP_Error( 'fpcw_invalid_variation', __( 'The selected product options are invalid.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}
		$bridge = Bridge::create( $product_id, (string) $request->get_param( 'external_reference' ), 900, $variation_id );
		$url    = add_query_arg( array( 'fpc_webview' => '1', 'fpc_bridge' => $bridge['token'], 'fpc_variation_id' => $variation_id ), get_permalink( $product_id ) );
		return rest_ensure_response( array( 'token' => $bridge['token'], 'editor_url' => $url, 'expires_at' => gmdate( 'c', $bridge['payload']['exp'] ) ) );
	}

	/** @param \WP_REST_Request $request Request. @return true|\WP_Error */
	public function storefront_permission( $request ) {
		return $this->valid_nonce( $request );
	}

	/** @param \WP_REST_Request $request Request. @return true|\WP_Error */
	public function session_permission( $request ) {
		$nonce = $this->valid_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}
		$session = $this->repository->find( $request['token'] );
		if ( ! $session ) {
			return new \WP_Error( 'fpcw_session_missing', __( 'The customization session no longer exists.', 'flexible-product-customizer' ), array( 'status' => 404 ) );
		}
		if ( $this->repository->is_expired( $session ) ) {
			return new \WP_Error( 'fpcw_session_expired', __( 'This customization has expired. Please create a new one.', 'flexible-product-customizer' ), array( 'status' => 410 ) );
		}
		if ( 'ordered' === $session['status'] || ! Session_Identity::owns( $session ) ) {
			return new \WP_Error( 'fpcw_session_forbidden', __( 'You cannot modify this customization.', 'flexible-product-customizer' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** @param \WP_REST_Request $request Request. @return true|\WP_Error */
	private function valid_nonce( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'fpcw_invalid_nonce', __( 'The security token has expired. Refresh the page and try again.', 'flexible-product-customizer' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** @param int $product_id Product ID. @param int $variation_id Variation ID. @return bool */
	private function valid_variation( $product_id, $variation_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}
		if ( ! $product->is_type( 'variable' ) ) {
			return 0 === $variation_id;
		}
		$variation = $variation_id ? wc_get_product( $variation_id ) : null;
		return $variation instanceof \WC_Product_Variation && (int) $variation->get_parent_id() === (int) $product_id && $variation->is_purchasable();
	}

	/** @param array $session Session. @param string $color_id Color ID. @param int $variation_id Variation ID. @return bool */
	private function color_matches_variation( array $session, $color_id, $variation_id ) {
		$snapshot  = $session['payload']['template_snapshot'];
		$attribute = isset( $snapshot['color_attribute'] ) ? sanitize_title( $snapshot['color_attribute'] ) : '';
		if ( ! $attribute || ! $variation_id ) {
			return true;
		}
		$variation = wc_get_product( $variation_id );
		if ( ! $variation instanceof \WC_Product_Variation ) {
			return false;
		}
		$attributes = $variation->get_variation_attributes();
		$key        = 'attribute_' . $attribute;
		if ( ! array_key_exists( $key, $attributes ) ) {
			return false;
		}
		$value = sanitize_title( $attributes[ $key ] );
		if ( '' === $value ) {
			return true;
		}
		foreach ( $snapshot['colors'] as $color ) {
			if ( $color['id'] === $color_id ) {
				return $value && $value === sanitize_title( $color['variation_value'] );
			}
		}
		return false;
	}

	/** @param array $session Session. @return array */
	private function session_response( array $session ) {
		$payload = $this->storage->hydrate_payload_urls( $session['payload'], $session );
		return array(
			'token'       => $session['token'],
			'cart_proof'  => $this->repository->cart_proof( $session['token'] ),
			'status'      => $session['status'],
			'product_id'  => $session['product_id'],
			'variation_id'=> $session['variation_id'],
			'expires_at'  => $this->repository->expiration_iso( $session ),
			'expires_display' => $this->repository->expiration_display( $session ),
			'payload'     => $payload,
		);
	}
}
