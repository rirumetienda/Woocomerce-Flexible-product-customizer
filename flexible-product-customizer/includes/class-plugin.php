<?php
/**
 * Plugin composition root.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	/** @var self|null */
	private static $instance;

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all bounded components.
	 *
	 * @return void
	 */
	public function boot() {
		Activator::maybe_upgrade();
		add_action( 'admin_notices', array( $this, 'upload_limit_notice' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy_content' ) );
		add_filter( 'plugin_action_links_' . FPCW_BASENAME, array( $this, 'action_links' ) );

		$repository = new Repository();
		$storage    = new File_Storage();
		$templates  = new Template_Manager();
		$products   = new Product_Settings( $templates );
		$validator  = new Validator( $templates );
		$settings   = new Settings();

		add_action( 'init', array( 'FPCW\\Session_Identity', 'ensure_cookie' ), 1 );

		$templates->register_hooks();
		add_action( 'init', array( 'FPCW\\Activator', 'complete_deferred_upgrade' ), 20 );
		$products->register_hooks();
		$settings->register_hooks();
		$storage->register_hooks( $repository );

		( new Rest_Controller( $repository, $storage, $templates, $products, $validator ) )->register_hooks();
		( new Frontend( $repository, $templates, $products, $storage ) )->register_hooks();
		( new Cart_Integration( $repository, $products, $storage ) )->register_hooks();
		( new Order_Integration( $repository, $storage, $products ) )->register_hooks();
		( new Cleanup( $repository, $storage ) )->register_hooks();
	}

	/** @return void */
	public function upload_limit_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || wp_max_upload_size() >= File_Storage::MAX_UPLOAD_BYTES ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: current server upload limit. */
				__( 'Flexible Product Customizer accepts images up to 10 MB, but this server currently allows only %s. Increase upload_max_filesize and post_max_size to use the full limit.', 'flexible-product-customizer' ),
				size_format( wp_max_upload_size() )
			)
		);
		echo '</p></div>';
	}

	/** @param array $links Plugin links. @return array */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Template_Manager::POST_TYPE ) ) . '">' . esc_html__( 'Templates', 'flexible-product-customizer' ) . '</a>' );
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Settings::PAGE ) ) . '">' . esc_html__( 'Settings', 'flexible-product-customizer' ) . '</a>' );
		return $links;
	}

	/** @return void */
	public function privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Flexible Product Customizer', 'flexible-product-customizer' ),
			wp_kses_post(
				__( '<p>When customers customize a product, the store temporarily saves uploaded images, entered text, generated previews, product identifiers, and an anonymous browser ownership token. Draft customization data that is not added to the cart expires after one hour of inactivity. Once added to the cart, unordered customization data expires seven days later. When an order is created, these files and design data are retained with the order according to the store retention policy.</p>', 'flexible-product-customizer' )
			)
		);
	}
}
