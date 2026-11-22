<?php
/**
 * EasyPay notification receiver — REST route wc-easypay/v1/notify.
 *
 * Two layers of trust:
 *  1. the Sign header must match base64(sha256(secretKey . raw body));
 *  2. the body is used only to find the order — its status is then asked from
 *     EasyPay (orderState), so a notification can never mark an order paid on
 *     its own word.
 *
 * EasyPay repeats a payment notification until it gets HTTP 200, so a
 * temporary failure to reach EasyPay answers 503 and waits for the retry.
 *
 * @package CatCodeEasyPayFree
 */

namespace CatCode\EasyPayFreeWC\Webhook;

use CatCode\EasyPayFreeWC\Api\Client;
use CatCode\EasyPayFreeWC\Core\Plugin;
use CatCode\EasyPayFreeWC\Gateway\Method;

defined( 'ABSPATH' ) || exit;

class Handler {

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			'wc-easypay/v1',
			'/notify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function receive( \WP_REST_Request $request ) {
		$gateway = Plugin::gateway();
		if ( ! $gateway || ! $gateway->is_configured() ) {
			return new \WP_REST_Response( array( 'error' => 'gateway unavailable' ), 503 );
		}

		if ( 'yes' === $gateway->get_option( 'notify_ip_check' ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( ! self::ip_allowed( $ip ) ) {
				$gateway->log( 'notify rejected: source ' . $ip . ' is not an EasyPay address' );
				return new \WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
			}
		}

		$body = (string) $request->get_body();
		$sign = (string) $request->get_header( 'sign' );

		if ( ! Client::verify( $gateway->secret_key(), $body, $sign ) ) {
			$gateway->log( 'notify signature INVALID; sign=' . $sign . '; body=' . substr( $body, 0, 2000 ) );
			return new \WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}
		$gateway->log( 'notify ok: ' . substr( $body, 0, 2000 ) );

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( array( 'error' => 'bad payload' ), 400 );
		}

		$ref   = self::order_ref( $data );
		$order = '' !== $ref ? self::find_order( $ref ) : null;
		if ( ! $order ) {
			// 200 so EasyPay stops repeating a notification this shop knows nothing about.
			return new \WP_REST_Response( array( 'ignored' => true ), 200 );
		}

		$state = $gateway->client()->order_state( $gateway->order_service_key( $order ), $ref );
		if ( ! $state['ok'] ) {
			if ( in_array( $state['error_code'], array( 'network', 'bad_response' ), true ) || 0 === strpos( $state['error_code'], 'http_5' ) ) {
				return new \WP_REST_Response( array( 'error' => 'status unavailable, retry' ), 503 );
			}
			return new \WP_REST_Response( array( 'ignored' => $state['error_code'] ), 200 );
		}

		$gateway->apply_status( $order, $state['data'], $ref );
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** Both documented notification shapes: the current and the legacy one. */
	public static function order_ref( array $data ): string {
		foreach ( array( 'MerchantOrderId', 'order_id', 'orderId', 'OrderId' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				return (string) $data[ $key ];
			}
		}
		return '';
	}

	/** The order that created this EasyPay order number (any attempt). */
	public static function find_order( string $ref ): ?\WC_Order {
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,80}$/', $ref ) ) {
			return null;
		}
		$orders = wc_get_orders(
			array(
				'limit'          => 1,
				'payment_method' => Method::ID,
				'meta_key'       => '_easypay_ref', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $ref,           // phpcs:ignore WordPress.DB.SlowDBQuery
				'status'         => array_keys( wc_get_order_statuses() ),
			)
		);
		return $orders ? $orders[0] : null;
	}

	public static function ip_allowed( string $ip ): bool {
		$long = ip2long( $ip );
		if ( false === $long ) {
			return false;
		}
		foreach ( Method::NOTIFY_NETWORKS as $cidr ) {
			list( $subnet, $bits ) = explode( '/', $cidr );
			$mask                  = -1 << ( 32 - (int) $bits );
			if ( ( $long & $mask ) === ( ip2long( $subnet ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}
}
