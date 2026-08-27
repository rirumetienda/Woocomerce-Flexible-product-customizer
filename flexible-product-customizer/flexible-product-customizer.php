<?php
/**
 * Plugin Name: Flexible Product Customizer for WooCommerce
 * Description: Visual product personalization with reusable templates, secure uploads, cart previews, and production files.
 * Version:     1.6.3
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 9.6
 * Author:      Community Contributors
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: flexible-product-customizer
 * Domain Path: /languages
 *
 * @package FlexibleProductCustomizer
 */

defined( 'ABSPATH' ) || exit;

define( 'FPCW_VERSION', '1.6.3' );
define( 'FPCW_FILE', __FILE__ );
define( 'FPCW_PATH', plugin_dir_path( __FILE__ ) );
define( 'FPCW_URL', plugin_dir_url( __FILE__ ) );
define( 'FPCW_BASENAME', plugin_basename( __FILE__ ) );

$fpcw_files = array(
	'class-session-identity.php',
	'class-settings.php',
	'class-repository.php',
	'class-file-storage.php',
	'class-template-manager.php',
	'class-template-library.php',
	'class-product-settings.php',
	'class-validator.php',
	'class-bridge.php',
	'class-rest-controller.php',
	'class-frontend.php',
	'class-cart-integration.php',
	'class-order-integration.php',
	'class-cleanup.php',
	'class-activator.php',
	'class-plugin.php',
);

foreach ( $fpcw_files as $fpcw_file ) {
	require_once FPCW_PATH . 'includes/' . $fpcw_file;
}

register_activation_hook( __FILE__, array( 'FPCW\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FPCW\\Activator', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		FPCW\Settings::register_language_filter();
		load_plugin_textdomain( 'flexible-product-customizer', false, dirname( FPCW_BASENAME ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Flexible Product Customizer requires WooCommerce to be installed and active.', 'flexible-product-customizer' ) . '</p></div>';
					}
				}
			);
			return;
		}

		FPCW\Plugin::instance()->boot();
	}
);
