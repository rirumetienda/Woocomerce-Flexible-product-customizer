<?php
/**
 * Immutable WooCommerce order metadata and production downloads.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Order_Integration {
	/** @var Repository */ private $repository;
	/** @var File_Storage */ private $storage;
	/** @var Product_Settings */ private $products;

	public function __construct( Repository $repository, File_Storage $storage, Product_Settings $products ) {
		$this->repository = $repository;
		$this->storage    = $storage;
		$this->products   = $products;
	}

	/** @return void */
	public function register_hooks() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'copy_line_item_data' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'finalize_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'finalize_order' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_admin_item' ), 10, 3 );
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render_customer_item' ), 10, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_internal_meta' ) );
	}

	/** @param \WC_Order_Item_Product $item Item. @param string $cart_item_key Cart key. @param array $values Cart values. @param \WC_Order $order Order. @return void */
	public function copy_line_item_data( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['fpcw_token'] ) ) {
			return;
		}
		$session = $this->repository->find( $values['fpcw_token'] );
		if ( ! $session || ! in_array( $session['status'], array( 'active', 'cart' ), true ) ) {
			return;
		}
		$item->add_meta_data( '_fpcw_session_token', $session['token'], true );
		$item->add_meta_data( '_fpcw_customization', $session['payload'], true );
		$item->add_meta_data( '_fpcw_template_name', $session['payload']['template_name'], true );
		$item->add_meta_data( '_fpcw_surface_surcharge', isset( $values['fpcw_surface_surcharge'] ) ? (float) $values['fpcw_surface_surcharge'] : 0, true );
	}

	/** @param \WC_Order|int $order Order object or ID. @return void */
	public function finalize_order( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order instanceof \WC_Order || ! $order->get_id() ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$token = $item->get_meta( '_fpcw_session_token', true );
			if ( ! $token ) {
				continue;
			}
			$session = $this->repository->find( $token );
			if ( ! $session ) {
				continue;
			}
			if ( 'ordered' === $session['status'] && (int) $session['order_id'] === (int) $order->get_id() ) {
				continue;
			}

			$finalized = $this->storage->finalize_for_order( $session, $order->get_id() );
			$payload   = $finalized['payload'];
			if ( ! empty( $finalized['warnings'] ) ) {
				$order->add_order_note( __( 'Customization files were preserved, but could not be moved to their final order directory. Check server file permissions.', 'flexible-product-customizer' ) );
			}
			$this->repository->update( $token, array( 'payload' => $payload, 'status' => 'ordered', 'order_id' => $order->get_id(), 'expires_at' => null ) );
			$item->update_meta_data( '_fpcw_customization', $payload );
			$item->save();
		}
		$order->save();
	}

	/** @param int $item_id Item ID. @param \WC_Order_Item $item Item. @param \WC_Product|null $product Product. @return void */
	public function render_admin_item( $item_id, $item, $product ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		$order = $item->get_order();
		$this->render_files( $item, $order, true, false );
	}

	/** @param int $item_id Item ID. @param \WC_Order_Item $item Item. @param \WC_Order $order Order. @param bool $plain_text Plain email. @return void */
	public function render_customer_item( $item_id, $item, $order, $plain_text = false ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		$this->render_files( $item, $order, false, $plain_text );
	}

	/** @param \WC_Order_Item_Product $item Item. @param \WC_Order $order Order. @param bool $admin Admin mode. @param bool $plain Plain text. @return void */
	private function render_files( $item, $order, $admin, $plain ) {
		$payload = $item->get_meta( '_fpcw_customization', true );
		$token   = $item->get_meta( '_fpcw_session_token', true );
		if ( ! is_array( $payload ) || ! $token ) {
			return;
		}
		$session = $this->repository->find( $token );
		if ( ! $session ) {
			return;
		}

		$used_surfaces = $this->products->used_surface_labels( $payload );
		$surcharge     = (float) $item->get_meta( '_fpcw_surface_surcharge', true );
		if ( $plain ) {
			echo "\n" . esc_html__( 'Customization:', 'flexible-product-customizer' ) . ' ' . esc_html( $payload['template_name'] ) . "\n";
			if ( $used_surfaces ) {
				echo esc_html__( 'Used surfaces', 'flexible-product-customizer' ) . ': ' . esc_html( implode( ', ', $used_surfaces ) ) . "\n";
			}
			if ( $surcharge > 0 ) {
				echo esc_html__( 'Customization surcharge', 'flexible-product-customizer' ) . ': ' . esc_html( wp_strip_all_tags( wc_price( $surcharge, array( 'currency' => $order->get_currency() ) ) ) ) . "\n";
			}
			return;
		}

		echo '<div class="fpcw-order-customization" style="margin:10px 0">';
		echo '<strong>' . esc_html__( 'Customization', 'flexible-product-customizer' ) . ': ' . esc_html( $payload['template_name'] ) . '</strong>';
		if ( $used_surfaces ) {
			echo '<div style="margin-top:6px"><span>' . esc_html__( 'Used surfaces', 'flexible-product-customizer' ) . ':</span> ' . esc_html( implode( ', ', $used_surfaces ) ) . '</div>';
		}
		if ( $surcharge > 0 ) {
			echo '<div style="margin-top:6px"><span>' . esc_html__( 'Customization surcharge', 'flexible-product-customizer' ) . ':</span> ' . wp_kses_post( wc_price( $surcharge, array( 'currency' => $order->get_currency() ) ) ) . '</div>';
		}
		if ( ! empty( $payload['previews'] ) ) {
			echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">';
			foreach ( $payload['previews'] as $preview ) {
				$url = $this->storage->public_url( $preview['relative_path'] );
				$label = ! empty( $preview['view_label'] ) ? $preview['view_label'] : __( 'Customization preview', 'flexible-product-customizer' );
				echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $label ) . '"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $label ) . '" style="width:96px;height:96px;object-fit:contain;border:1px solid #ddd"></a>';
			}
			echo '</div>';
		}

		$collections = array( 'uploads' => __( 'Source files', 'flexible-product-customizer' ) );
		if ( $admin ) {
			$collections['production_files'] = __( 'Production PNG files', 'flexible-product-customizer' );
		}
		foreach ( $collections as $key => $label ) {
			if ( empty( $payload[ $key ] ) ) {
				continue;
			}
			echo '<div style="margin-top:6px"><span>' . esc_html( $label ) . ':</span> ';
			$links = array();
			foreach ( $payload[ $key ] as $file ) {
				$links[] = '<a href="' . esc_url( $this->storage->private_url( $session, $file, $order, true ) ) . '" target="_blank" rel="noopener">' . esc_html( $file['original_name'] ) . '</a>';
			}
			echo wp_kses_post( implode( ' &middot; ', $links ) );
			echo '</div>';
		}
		echo '</div>';
	}

	/** @param array $keys Hidden keys. @return array */
	public function hide_internal_meta( $keys ) {
		$keys[] = '_fpcw_session_token';
		$keys[] = '_fpcw_customization';
		$keys[] = '_fpcw_template_name';
		$keys[] = '_fpcw_surface_surcharge';
		return $keys;
	}
}
