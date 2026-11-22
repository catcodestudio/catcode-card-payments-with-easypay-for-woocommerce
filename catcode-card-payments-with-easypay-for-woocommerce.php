<?php
/**
 * Plugin Name: CatCode Card Payments with EasyPay for WooCommerce
 * Plugin URI: https://catcode.com.ua/modules/easypay-for-woocommerce/
 * Description: Accept card, Apple Pay and Google Pay payments through the EasyPay payment page: the order is confirmed by a signed notification and re-checked with EasyPay, the status is reconciled when the buyer returns, sandbox with the public EasyPay test merchant.
 * Version: 1.0.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: CatCode
 * Author URI: https://catcode.com.ua
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: catcode-card-payments-with-easypay-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 6.0
 * WC tested up to: 10.7
 *
 * @package CatCodeEasyPayFree
 */

defined( 'ABSPATH' ) || exit;

define( 'CATCODE_EASYPAY_VERSION', '1.0.1' );
define( 'CATCODE_EASYPAY_FILE', __FILE__ );
define( 'CATCODE_EASYPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'CATCODE_EASYPAY_URL', plugin_dir_url( __FILE__ ) );
define( 'CATCODE_EASYPAY_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'CatCode\\EasyPayFreeWC\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = CATCODE_EASYPAY_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( '\\CatCode\\EasyPayFreeWC\\Core\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\CatCode\\EasyPayFreeWC\\Core\\Installer', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Prints an admin notice on the Plugins screen only, for users who can
 * manage plugins — the notice is about plugin activation, so it is not shown
 * anywhere else in the dashboard.
 *
 * @param string   $type    Notice type: warning or error.
 * @param callable $message Returns the plain-text message (translated when the notice renders, not earlier).
 */
function catcode_easypay_plugins_screen_notice( $type, $message ) {
	add_action(
		'admin_notices',
		static function () use ( $type, $message ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( call_user_func( $message ) ) . '</p></div>';
		}
	);
}

add_action(
	'plugins_loaded',
	static function () {
		// The catcode.com.ua edition of this gateway is active too: it owns the
		// same gateway id and settings, so this copy stays idle instead of
		// registering a second EasyPay method.
		if ( defined( 'EZPY_VERSION' ) ) {
			catcode_easypay_plugins_screen_notice(
				'warning',
				static function () {
					return __( 'Another copy of the CatCode EasyPay gateway is active. This copy stays idle — deactivate one of them.', 'catcode-card-payments-with-easypay-for-woocommerce' );
				}
			);
			return;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			catcode_easypay_plugins_screen_notice(
				'error',
				static function () {
					return __( 'CatCode Card Payments with EasyPay for WooCommerce requires an active WooCommerce.', 'catcode-card-payments-with-easypay-for-woocommerce' );
				}
			);
			return;
		}
		\CatCode\EasyPayFreeWC\Core\Plugin::instance()->boot();
	}
);
