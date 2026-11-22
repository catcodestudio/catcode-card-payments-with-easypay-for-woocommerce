<?php
/**
 * Plugin bootstrap / wiring.
 *
 * @package CatCodeEasyPayFree
 */

namespace CatCode\EasyPayFreeWC\Core;

use CatCode\EasyPayFreeWC\Admin\Ajax;
use CatCode\EasyPayFreeWC\Gateway\Blocks;
use CatCode\EasyPayFreeWC\Gateway\Method;
use CatCode\EasyPayFreeWC\Webhook\Handler;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . CATCODE_EASYPAY_BASENAME, array( $this, 'action_links' ) );

		( new Handler() )->register();

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				if ( class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
					$registry->register( new Blocks() );
				}
			}
		);

		// Fallback reconciliation when the buyer lands on the thank-you page.
		add_action( 'woocommerce_thankyou', array( $this, 'on_thankyou' ), 10, 1 );

		if ( is_admin() ) {
			( new Ajax() )->register();
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		}
	}

	/** Admin CSS/JS for the "Check keys" button on the gateway settings screen. */
	public function admin_assets() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen to load assets on.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( Method::ID !== $section ) {
			return;
		}
		wp_enqueue_style( 'catcode-easypay-admin', CATCODE_EASYPAY_URL . 'assets/css/admin.css', array(), CATCODE_EASYPAY_VERSION );
		wp_enqueue_script( 'catcode-easypay-admin', CATCODE_EASYPAY_URL . 'assets/js/admin.js', array(), CATCODE_EASYPAY_VERSION, true );
		wp_localize_script(
			'catcode-easypay-admin',
			'catcodeEasyPayAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'catcode_easypay_admin' ),
				'i18n'    => array(
					'network' => __( 'Network error — please try again.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				),
			)
		);
	}

	public function on_thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== Method::ID || $order->is_paid() ) {
			return;
		}
		$gw = self::gateway();
		if ( $gw ) {
			$gw->sync_order( $order );
		}
	}

	public function register_gateway( array $gateways ): array {
		$gateways[] = Method::class;
		return $gateways;
	}

	public function action_links( array $links ): array {
		$url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Method::ID );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'catcode-card-payments-with-easypay-for-woocommerce' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/** The registered gateway instance, if WooCommerce has it loaded. */
	public static function gateway(): ?Method {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gw       = isset( $gateways[ Method::ID ] ) ? $gateways[ Method::ID ] : null;
		return $gw instanceof Method ? $gw : null;
	}
}
