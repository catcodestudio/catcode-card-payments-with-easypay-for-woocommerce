<?php
/**
 * EasyPay Merchant API client.
 *
 * Every merchant call carries three identities in headers: PartnerKey (the
 * merchant), AppId (a "point", valid for months and extended by use) and
 * PageId (a session, ~20 minutes, and it has to be fresh for every
 * createOrder). The AppId is cached; a PageId is taken per call.
 *
 * Signature for POST calls: Sign = base64(sha256(secretKey . body)), computed
 * over the exact bytes that go on the wire.
 *
 * @package CatCodeEasyPayFree
 */

namespace CatCode\EasyPayFreeWC\Api;

defined( 'ABSPATH' ) || exit;

class Client {

	const BASE_URL = 'https://merchantapi.easypay.ua';

	/** Public test merchant published in the EasyPay Merchant API documentation. */
	const TEST_PARTNER_KEY = 'easypay-test';
	const TEST_SERVICE_KEY = 'MERCHANT-TEST';
	const TEST_SECRET_KEY  = 'test';

	private const TIMEOUT = 25;

	/** @var string */
	private $partner_key;

	/** @var string */
	private $secret_key;

	/** @var callable|null */
	private $logger;

	public function __construct( string $partner_key, string $secret_key, ?callable $logger = null ) {
		$this->partner_key = $partner_key;
		$this->secret_key  = $secret_key;
		$this->logger      = $logger;
	}

	public static function base_url(): string {
		return defined( 'CATCODE_EASYPAY_API' ) ? rtrim( (string) constant( 'CATCODE_EASYPAY_API' ), '/' ) : self::BASE_URL;
	}

	// -----------------------------------------------------------------------
	// Merchant calls
	// -----------------------------------------------------------------------

	/**
	 * @param array $body createOrder payload.
	 * @return array Normalised response, see request().
	 */
	public function create_order( array $body ): array {
		return $this->merchant( '/api/merchant/createOrder', $body );
	}

	public function order_state( string $service_key, string $order_ref ): array {
		return $this->merchant(
			'/api/merchant/orderState',
			array(
				'serviceKey' => $service_key,
				'orderId'    => $order_ref,
			)
		);
	}

	// -----------------------------------------------------------------------
	// Signing
	// -----------------------------------------------------------------------

	public static function sign( string $secret, string $body ): string {
		return base64_encode( hash( 'sha256', $secret . $body, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function verify( string $secret, string $body, string $signature ): bool {
		$signature = trim( $signature );
		if ( '' === $secret || '' === $signature ) {
			return false;
		}
		return hash_equals( self::sign( $secret, $body ), $signature );
	}

	// -----------------------------------------------------------------------
	// Plumbing
	// -----------------------------------------------------------------------

	/**
	 * A signed merchant call with a fresh PageId. When EasyPay no longer knows
	 * the cached AppId the point is registered again and the call is repeated
	 * once.
	 */
	private function merchant( string $path, array $body ): array {
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$session = $this->session( $attempt > 0 );
			if ( empty( $session['ok'] ) ) {
				return $session;
			}
			$raw  = (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$resp = $this->request(
				$path,
				$raw,
				array(
					'AppId'  => $session['app_id'],
					'PageId' => $session['page_id'],
					'Sign'   => self::sign( $this->secret_key, $raw ),
				)
			);
			if ( 'APPID_NOT_FOUND' !== $resp['error_code'] ) {
				return $resp;
			}
			delete_transient( $this->app_cache_key() );
		}

		return $resp;
	}

	/**
	 * AppId + PageId for the next call.
	 *
	 * @param bool $fresh Ignore the cached AppId.
	 * @return array{ok:bool,app_id?:string,page_id?:string,error?:string,error_code?:string,http?:int,data?:array}
	 */
	private function session( bool $fresh ): array {
		$app_id = $fresh ? '' : (string) get_transient( $this->app_cache_key() );

		if ( '' !== $app_id ) {
			$page = $this->request( '/api/system/createPage', '', array( 'AppId' => $app_id ) );
			if ( $page['ok'] && ! empty( $page['data']['pageId'] ) ) {
				return array(
					'ok'      => true,
					'app_id'  => $app_id,
					'page_id' => (string) $page['data']['pageId'],
				);
			}
		}

		$app = $this->request( '/api/system/createApp', '' );
		if ( ! $app['ok'] || empty( $app['data']['appId'] ) || empty( $app['data']['pageId'] ) ) {
			if ( $app['ok'] ) {
				$app['ok']         = false;
				$app['error_code'] = 'bad_response';
				$app['error']      = 'createApp returned no appId';
			}
			return $app;
		}
		set_transient( $this->app_cache_key(), (string) $app['data']['appId'], 60 * DAY_IN_SECONDS );

		return array(
			'ok'      => true,
			'app_id'  => (string) $app['data']['appId'],
			'page_id' => (string) $app['data']['pageId'],
		);
	}

	private function app_cache_key(): string {
		return 'catcode_easypay_app_' . substr( md5( self::base_url() . '|' . $this->partner_key ), 0, 12 );
	}

	/**
	 * One HTTP POST, normalised.
	 *
	 * @return array{ok:bool,http:int,data:array,error_code:string,error:string}
	 */
	private function request( string $path, string $raw, array $headers = array() ): array {
		$headers = array_merge(
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'PartnerKey'   => $this->partner_key,
				'locale'       => 'ua',
				'User-Agent'   => 'CatCode-EasyPay-WooCommerce/' . CATCODE_EASYPAY_VERSION,
			),
			$headers
		);

		/**
		 * Short-circuit an EasyPay call (tests, staging). Return an array
		 * {http:int, body:string} to use it instead of the network.
		 *
		 * @param array|null $pre     Null to go to the network.
		 * @param string     $path    API path.
		 * @param string     $raw     Request body.
		 * @param array      $headers Request headers.
		 */
		$pre = apply_filters( 'catcode_easypay_pre_request', null, $path, $raw, $headers );
		if ( is_array( $pre ) ) {
			$http = (int) ( isset( $pre['http'] ) ? $pre['http'] : 200 );
			$body = (string) ( isset( $pre['body'] ) ? $pre['body'] : '' );
		} else {
			$response = wp_remote_post(
				self::base_url() . $path,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => $headers,
					'body'    => $raw,
				)
			);
			if ( is_wp_error( $response ) ) {
				$this->log( $path . ' transport error: ' . $response->get_error_message() );
				return array(
					'ok'         => false,
					'http'       => 0,
					'data'       => array(),
					'error_code' => 'network',
					'error'      => $response->get_error_message(),
				);
			}
			$http = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
		}

		$this->log( $path . ' ' . $http . ' <- ' . $this->mask( $raw ) . ' -> ' . substr( $body, 0, 2000 ) );

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return array(
				'ok'         => false,
				'http'       => $http,
				'data'       => array(),
				'error_code' => 'bad_response',
				'error'      => 'HTTP ' . $http,
			);
		}

		$code    = '';
		$message = '';
		if ( ! empty( $data['error'] ) && is_array( $data['error'] ) ) {
			$code    = (string) ( isset( $data['error']['errorCode'] ) ? $data['error']['errorCode'] : 'error' );
			$message = (string) ( isset( $data['error']['errorMessage'] ) ? $data['error']['errorMessage'] : $code );
			if ( ! empty( $data['error']['fieldErrors'] ) && is_array( $data['error']['fieldErrors'] ) ) {
				foreach ( $data['error']['fieldErrors'] as $field ) {
					if ( is_array( $field ) && ! empty( $field['fieldName'] ) ) {
						$message .= ' (' . $field['fieldName'] . ': ' . ( isset( $field['errorMessage'] ) ? $field['errorMessage'] : '' ) . ')';
					}
				}
			}
		} elseif ( $http < 200 || $http >= 300 ) {
			$code    = 'http_' . $http;
			$message = 'HTTP ' . $http;
		}

		// orderState / cancelOrder describe a declined payment with paymentState
		// AND an error block (the decline reason). That is a valid answer about
		// the payment, not a failed call — otherwise a declined payment would
		// never reach the order.
		if ( '' !== $code && isset( $data['paymentState'] ) && is_string( $data['paymentState'] ) && '' !== $data['paymentState'] && $http >= 200 && $http < 300 ) {
			return array(
				'ok'         => true,
				'http'       => $http,
				'data'       => $data,
				'error_code' => '',
				'error'      => $message,
			);
		}

		return array(
			'ok'         => '' === $code,
			'http'       => $http,
			'data'       => $data,
			'error_code' => $code,
			'error'      => $message,
		);
	}

	/** Card data never goes through this client, but keep the log free of e-mails and phones. */
	private function mask( string $raw ): string {
		return (string) preg_replace( '/("(?:PayerEmail|PayerPhone)"\s*:\s*")[^"]*"/', '$1***"', $raw );
	}

	private function log( string $message ): void {
		if ( $this->logger ) {
			call_user_func( $this->logger, $message );
		}
	}
}
