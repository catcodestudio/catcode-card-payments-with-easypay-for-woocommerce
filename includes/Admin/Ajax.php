<?php
/**
 * Admin AJAX: the live credentials check.
 *
 * @package CatCodeEasyPayFree
 */

namespace CatCode\EasyPayFreeWC\Admin;

use CatCode\EasyPayFreeWC\Core\Plugin;

defined( 'ABSPATH' ) || exit;

class Ajax {

	public function register(): void {
		add_action( 'wp_ajax_catcode_easypay_check', array( $this, 'check' ) );
	}

	private function guard(): void {
		check_ajax_referer( 'catcode_easypay_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'catcode-card-payments-with-easypay-for-woocommerce' ) ), 403 );
		}
	}

	/**
	 * Credentials check without creating anything: a signed orderState for an
	 * order number that cannot exist. EasyPay checks the merchant, the service
	 * and the signature before it looks for the order, so "order not found"
	 * means all three are right.
	 */
	public function check(): void {
		$this->guard();

		$gw = Plugin::gateway();
		if ( ! $gw ) {
			wp_send_json_error( array( 'message' => __( 'The gateway is not loaded.', 'catcode-card-payments-with-easypay-for-woocommerce' ) ) );
		}
		if ( ! $gw->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Save PartnerKey, ServiceKey and SecretKey first (or turn the sandbox on).', 'catcode-card-payments-with-easypay-for-woocommerce' ) ) );
		}

		$resp = $gw->client()->order_state( $gw->service_key(), 'catcode-check-' . wp_generate_password( 10, false, false ) );
		$code = (string) $resp['error_code'];

		$messages = array(
			'INVALID_MERCHANTKEY'  => __( 'EasyPay does not know this PartnerKey.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
			'SERVICE_NOT_FOUND'    => __( 'EasyPay does not know this ServiceKey for your PartnerKey.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
			'EASYPAY_INVALID_SIGN' => __( 'The signature is rejected — the SecretKey is wrong.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
			'network'              => __( 'EasyPay could not be reached from this server: ', 'catcode-card-payments-with-easypay-for-woocommerce' ) . $resp['error'],
		);

		if ( 'MERCHANT_ORDERID_NOT_FOUND' === $code || $resp['ok'] ) {
			wp_send_json_success(
				array(
					'message' => $gw->is_sandbox()
						? __( 'The EasyPay sandbox answers — the test merchant is ready for a test payment.', 'catcode-card-payments-with-easypay-for-woocommerce' )
						: __( 'EasyPay accepts PartnerKey, ServiceKey and the signature.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => isset( $messages[ $code ] ) ? $messages[ $code ] : 'EasyPay: ' . $resp['error'] . ( '' !== $code ? ' [' . $code . ']' : '' ),
			)
		);
	}
}
