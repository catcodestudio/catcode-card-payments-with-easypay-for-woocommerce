<?php
/**
 * WooCommerce payment gateway — EasyPay payment page.
 *
 * @package CatCodeEasyPayFree
 */

namespace CatCode\EasyPayFreeWC\Gateway;

use CatCode\EasyPayFreeWC\Api\Client;
use CatCode\EasyPayFreeWC\Support\Crypto;

defined( 'ABSPATH' ) || exit;

class Method extends \WC_Payment_Gateway {

	/** Prefixed so it never collides with another EasyPay plugin's gateway id. */
	const ID = 'catcode_easypay';

	/** EasyPay settles in hryvnia only. */
	const CURRENCY = 'UAH';

	const SECRET_FIELDS = array( 'secret_key' );

	/** Error code EasyPay returns when orderId was already used for this service. */
	const DUPLICATE_ORDER = 'PROVIDER_ERROR_DUBLICATED_ORDER_ID';

	/** Sources EasyPay documents for notifications. */
	const NOTIFY_NETWORKS = array( '77.88.192.216/29', '93.183.196.26/32' );

	private const LOCK_TTL = 30;

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'EasyPay', 'catcode-card-payments-with-easypay-for-woocommerce' );
		$this->method_description = __( 'Card, Apple Pay and Google Pay payments on the EasyPay payment page. The order is confirmed by a signed EasyPay notification and re-checked with EasyPay before it is marked paid.', 'catcode-card-payments-with-easypay-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'encrypt_secret_fields' ) );
		add_action( 'woocommerce_api_' . $this->id, array( $this, 'handle_return' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable EasyPay payments', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method name the buyer sees at checkout.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'default'     => __( 'Card, Apple Pay or Google Pay (EasyPay)', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'   => __( 'Description', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'You will be taken to the secure EasyPay page to pay by Visa/Mastercard, Apple Pay or Google Pay.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
			),
			'testmode'        => array(
				'title'       => __( 'Sandbox', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use the public EasyPay test merchant', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'description' => __( 'Works right away with the test credentials published in the EasyPay documentation (easypay-test / MERCHANT-TEST) — no contract needed, no real money moves. Test cards are listed in the EasyPay Merchant API documentation. Turn it off and enter your own keys to accept real payments.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'default'     => 'yes',
			),
			'partner_key'     => array(
				'title'             => __( 'PartnerKey', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'Merchant identifier issued by your EasyPay manager.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'default'           => '',
				'desc_tip'          => true,
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			'service_key'     => array(
				'title'             => __( 'ServiceKey', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'Identifier of your shop (service) in EasyPay.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'default'           => '',
				'desc_tip'          => true,
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			'secret_key'      => array(
				'title'       => __( 'SecretKey', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'        => 'ezpy_secret',
				'description' => __( 'Signs every request and verifies EasyPay notifications. Stored encrypted in the database.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'default'     => '',
			),
			'connection'      => array(
				'title' => __( 'Connection check', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'  => 'ezpy_check',
			),
			'order_prefix'    => array(
				'title'       => __( 'Order number prefix', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'        => 'text',
				'default'     => 'wc-',
				'description' => __( 'EasyPay needs a unique order number for every payment. The prefix keeps numbers of this shop apart from other shops on the same merchant; a repeated payment attempt gets a -2, -3… suffix automatically.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'payer_reminder'  => array(
				'title'       => __( 'Unpaid order reminder', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Pass the buyer’s phone so EasyPay can remind about an unpaid order (SMS or Viber)', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'description' => __( 'EasyPay sends the reminder 15–20 minutes after the payment page was created if it is still unpaid. Check the terms of this service in your EasyPay contract.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'default'     => 'no',
			),
			'complete_status' => array(
				'title'   => __( 'Status after payment', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'processing' => __( 'Processing', 'catcode-card-payments-with-easypay-for-woocommerce' ),
					'completed'  => __( 'Completed', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				),
				'default' => 'processing',
			),
			'notify_ip_check' => array(
				'title'       => __( 'Notification source', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Accept notifications only from EasyPay IP addresses', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'description' => __( 'Every notification is already signature-checked and re-confirmed with EasyPay. Leave this off behind Cloudflare or a proxy, where the visitor address is not the real one.', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'default'     => 'no',
			),
			'debug'           => array(
				'title'   => __( 'Logging', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Write requests and responses to the WooCommerce log (source: easypay)', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'default' => 'no',
			),
			'notify_info'     => array(
				'title'       => __( 'Notification URL', 'catcode-card-payments-with-easypay-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: notification URL */
					__( 'Sent to EasyPay with every order, nothing to configure:<br><code>%s</code>', 'catcode-card-payments-with-easypay-for-woocommerce' ),
					esc_html( self::notify_url() )
				),
			),
		);
	}

	public static function notify_url(): string {
		return rest_url( 'wc-easypay/v1/notify' );
	}

	// -----------------------------------------------------------------------
	// Admin rendering
	// -----------------------------------------------------------------------

	/**
	 * Secret key — rendered DECRYPTED. WooCommerce would print the stored blob,
	 * and saving the form again would encrypt it a second time.
	 */
	public function generate_ezpy_secret_html( $key, $data ): string {
		$stored = (string) $this->get_option( $key );
		$plain  = '' === $stored ? '' : Crypto::decrypt( $stored );

		$original               = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : null;
		$this->settings[ $key ] = $plain;

		$data['custom_attributes'] = array( 'autocomplete' => 'new-password' );
		$html                      = $this->generate_password_html( $key, $data );

		if ( null === $original ) {
			unset( $this->settings[ $key ] );
		} else {
			$this->settings[ $key ] = $original;
		}
		return $html;
	}

	/**
	 * Keep the secret exactly as typed: the stock text validation runs
	 * wp_kses_post(), which turns "&" into "&amp;" and breaks every signature.
	 */
	public function validate_ezpy_secret_field( $key, $value ) {
		return null === $value ? '' : trim( wp_unslash( (string) $value ) );
	}

	public function generate_ezpy_check_html( $key, $data ): string {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( isset( $data['title'] ) ? $data['title'] : '' ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="ezpy-check"><?php esc_html_e( 'Check keys', 'catcode-card-payments-with-easypay-for-woocommerce' ); ?></button>
				<p class="description" id="ezpy-check-result"><?php esc_html_e( 'Sends a signed status request with the saved keys. No order and no payment is created.', 'catcode-card-payments-with-easypay-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/** Encrypt the secret before WooCommerce stores the settings; idempotent. */
	public function encrypt_secret_fields( array $fields ): array {
		foreach ( self::SECRET_FIELDS as $name ) {
			if ( ! isset( $fields[ $name ] ) ) {
				continue;
			}
			$raw = (string) $fields[ $name ];
			if ( '' !== $raw && 0 !== strncmp( $raw, Crypto::PREFIX, strlen( Crypto::PREFIX ) ) ) {
				$fields[ $name ] = Crypto::encrypt( $raw );
			}
		}
		return $fields;
	}

	// -----------------------------------------------------------------------
	// Gateway plumbing
	// -----------------------------------------------------------------------

	public function is_sandbox(): bool {
		return 'yes' === $this->get_option( 'testmode' );
	}

	public function partner_key(): string {
		return $this->is_sandbox() ? Client::TEST_PARTNER_KEY : trim( (string) $this->get_option( 'partner_key' ) );
	}

	public function service_key(): string {
		return $this->is_sandbox() ? Client::TEST_SERVICE_KEY : trim( (string) $this->get_option( 'service_key' ) );
	}

	public function secret_key(): string {
		if ( $this->is_sandbox() ) {
			return Client::TEST_SECRET_KEY;
		}
		$raw = trim( (string) $this->get_option( 'secret_key' ) );
		return '' === $raw ? '' : Crypto::decrypt( $raw );
	}

	public function is_configured(): bool {
		return '' !== $this->partner_key() && '' !== $this->service_key() && '' !== $this->secret_key();
	}

	public function is_available() {
		if ( 'yes' !== $this->get_option( 'enabled' ) || ! $this->is_configured() ) {
			return false;
		}
		// A cart priced in another currency would be charged the same number in
		// hryvnia.
		if ( self::CURRENCY !== get_woocommerce_currency() ) {
			return false;
		}
		return parent::is_available();
	}

	public function client(): Client {
		return new Client( $this->partner_key(), $this->secret_key(), array( $this, 'log' ) );
	}

	public function log( string $message ) {
		if ( 'yes' !== $this->get_option( 'debug' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->debug( $message, array( 'source' => 'easypay' ) );
	}

	/** Phone in the shape EasyPay takes for PayerPhone: 380XXXXXXXXX. */
	public static function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( ! is_string( $digits ) || '' === $digits ) {
			return '';
		}
		if ( 9 === strlen( $digits ) ) {
			$digits = '380' . $digits;
		} elseif ( 10 === strlen( $digits ) && '0' === $digits[0] ) {
			$digits = '38' . $digits;
		} elseif ( 11 === strlen( $digits ) && '8' === $digits[0] ) {
			$digits = '3' . $digits;
		}
		return 12 === strlen( $digits ) && 0 === strncmp( $digits, '380', 3 ) ? $digits : '';
	}

	/**
	 * Prefix for EasyPay order numbers.
	 *
	 * The sandbox merchant is shared by every shop that tests against it, so
	 * there the prefix also carries a random per-install tag — otherwise order
	 * #100 of this shop collides with order #100 of somebody else's.
	 */
	public function order_prefix(): string {
		$prefix = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $this->get_option( 'order_prefix', 'wc-' ) );
		$prefix = is_string( $prefix ) ? substr( $prefix, 0, 20 ) : '';
		if ( $this->is_sandbox() ) {
			$uid = (string) get_option( 'catcode_easypay_uid', '' );
			if ( '' === $uid ) {
				$uid = strtolower( wp_generate_password( 6, false, false ) );
				update_option( 'catcode_easypay_uid', $uid, false );
			}
			$prefix = 'cc' . $uid . '-' . $prefix;
		}
		return $prefix;
	}

	/** EasyPay order number for the given attempt: prefix + id, then -2, -3… */
	public function order_ref( \WC_Order $order, int $attempt ): string {
		$ref = $this->order_prefix() . $order->get_id();
		return $attempt > 1 ? $ref . '-' . $attempt : $ref;
	}

	private function order_description( \WC_Order $order ): string {
		/* translators: 1: order number, 2: shop name */
		$text = sprintf( __( 'Order #%1$s, %2$s', 'catcode-card-payments-with-easypay-for-woocommerce' ), $order->get_order_number(), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 120 ) : substr( $text, 0, 120 );
	}

	private function return_url( \WC_Order $order, string $result ): string {
		// Query form, not /wc-api/<id>/: language plugins with a prefix for every
		// language (Polylang) drop the root wc-api rewrite rule and the pretty
		// URL lands on a 404.
		return add_query_arg(
			array(
				'wc-api'   => self::ID,
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
				'result'   => $result,
			),
			home_url( '/' )
		);
	}

	// -----------------------------------------------------------------------
	// Payment
	// -----------------------------------------------------------------------

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		if ( self::CURRENCY !== $order->get_currency() ) {
			wc_add_notice( __( 'EasyPay accepts payments in hryvnia only.', 'catcode-card-payments-with-easypay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// The buyer came back with the browser "Back" button and pressed Pay
		// again. If the previous page was actually paid, a second EasyPay order
		// would charge the card twice.
		$previous = (string) $order->get_meta( '_easypay_order_ref' );
		if ( '' !== $previous && ! $order->is_paid() ) {
			$this->sync_order( $order );
			$order = self::reload_order( $order->get_id(), $order );
		}
		if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$attempt = (int) $order->get_meta( '_easypay_attempt' );
		$resp    = array();
		$ref     = '';

		// A number already used on this merchant (reinstalled shop, shared
		// sandbox) is answered with DUBLICATED_ORDER_ID — move on to the next one.
		for ( $try = 0; $try < 5; $try++ ) {
			++$attempt;
			$ref  = $this->order_ref( $order, $attempt );
			$resp = $this->client()->create_order( $this->order_body( $order, $ref ) );
			if ( self::DUPLICATE_ORDER !== $resp['error_code'] ) {
				break;
			}
		}

		$order->update_meta_data( '_easypay_attempt', $attempt );

		if ( ! $resp['ok'] || empty( $resp['data']['forwardUrl'] ) ) {
			$order->save();
			$text = '' !== $resp['error'] ? $resp['error'] : __( 'EasyPay did not return a payment page.', 'catcode-card-payments-with-easypay-for-woocommerce' );
			wc_add_notice( __( 'Payment error: ', 'catcode-card-payments-with-easypay-for-woocommerce' ) . esc_html( $text ), 'error' );
			$order->add_order_note( 'EasyPay: ' . $text . ( '' !== $resp['error_code'] ? ' [' . $resp['error_code'] . ']' : '' ) );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_easypay_order_ref', $ref );
		$order->add_meta_data( '_easypay_ref', $ref );
		$order->update_meta_data( '_easypay_sandbox', $this->is_sandbox() ? '1' : '0' );
		$order->update_meta_data( '_easypay_service_key', $this->service_key() );
		/* translators: %s: EasyPay order number */
		$note = sprintf( __( 'EasyPay: waiting for payment (order %s).', 'catcode-card-payments-with-easypay-for-woocommerce' ), $ref );
		if ( $order->has_status( 'pending' ) ) {
			$order->add_order_note( $note );
		} else {
			$order->update_status( 'pending', $note );
		}
		$order->save();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => (string) $resp['data']['forwardUrl'],
		);
	}

	private function order_body( \WC_Order $order, string $ref ): array {
		$additional = array( 'Merchant.UrlNotify' => self::notify_url() );

		$email = (string) $order->get_billing_email();
		if ( '' !== $email && is_email( $email ) ) {
			$additional['PayerEmail'] = $email;
		}
		if ( 'yes' === $this->get_option( 'payer_reminder' ) ) {
			$phone = self::normalize_phone( (string) $order->get_billing_phone() );
			if ( '' !== $phone ) {
				$additional['PayerPhone'] = $phone;
			}
		}

		$body = array(
			'order' => array(
				'serviceKey'      => $this->service_key(),
				'orderId'         => $ref,
				'description'     => $this->order_description( $order ),
				'amount'          => round( (float) $order->get_total(), 2 ),
				'additionalItems' => $additional,
			),
			'urls'  => array(
				'success' => $this->return_url( $order, 'success' ),
				'failed'  => $this->return_url( $order, 'failed' ),
				'back'    => $order->get_checkout_payment_url(),
			),
		);

		/**
		 * Last chance to adjust the EasyPay createOrder payload.
		 *
		 * @param array     $body  Payload.
		 * @param \WC_Order $order Order.
		 */
		return (array) apply_filters( 'catcode_easypay_order_body', $body, $order );
	}

	/**
	 * The buyer is back from EasyPay. The GET parameters EasyPay appends are
	 * not trusted — the status is asked from EasyPay itself.
	 */
	public function handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a return URL from EasyPay, authorised by the order key.
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$result   = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';
		// phpcs:enable

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || $order->get_payment_method() !== self::ID ) {
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
			exit;
		}

		$this->sync_order( $order );
		// The notification may have landed while the buyer was coming back; the
		// object above still holds the pre-payment state.
		$order = self::reload_order( $order_id, $order );

		if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		// EasyPay sends the buyer to "success" only after a successful payment;
		// the status may lag a few seconds behind. The thank-you page checks
		// again instead of telling a paying buyer that the payment failed.
		if ( 'success' === $result && ! $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		wc_add_notice( __( 'The payment was not completed. Please try again or choose another payment method.', 'catcode-card-payments-with-easypay-for-woocommerce' ), 'error' );
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	/** Ask EasyPay for the current state of the order's payment and apply it. */
	public function sync_order( \WC_Order $order, string $ref = '' ): bool {
		$ref = '' !== $ref ? $ref : (string) $order->get_meta( '_easypay_order_ref' );
		if ( '' === $ref ) {
			return false;
		}
		$resp = $this->client()->order_state( $this->order_service_key( $order ), $ref );
		if ( ! $resp['ok'] ) {
			return false;
		}
		return $this->apply_status( $order, $resp['data'], $ref );
	}

	/** The service the order was created under — settings may change later. */
	public function order_service_key( \WC_Order $order ): string {
		$stored = (string) $order->get_meta( '_easypay_service_key' );
		return '' !== $stored ? $stored : $this->service_key();
	}

	// -----------------------------------------------------------------------
	// Status reconciliation
	// -----------------------------------------------------------------------

	/**
	 * Re-read an order past the per-request caches (HPOS keeps its own
	 * OrderCache, the classic cache groups are not enough).
	 */
	public static function reload_order( int $order_id, \WC_Order $fallback ): \WC_Order {
		wp_cache_delete( $order_id, 'orders' );
		wp_cache_delete( $order_id, 'order-items' );
		clean_post_cache( $order_id );
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		$fresh = wc_get_order( $order_id );
		return $fresh instanceof \WC_Order ? $fresh : $fallback;
	}

	/**
	 * Apply an orderState answer under a cross-request lock: the notification
	 * and the buyer's return arrive at the same moment, and without the lock
	 * both would call payment_complete().
	 */
	public function apply_status( \WC_Order $order, array $data, string $ref ): bool {
		$lock = '_easypay_apply_' . $order->get_id();
		for ( $i = 0; $i < 40; $i++ ) {
			if ( $this->lock_acquire( $lock ) ) {
				break;
			}
			usleep( 100000 );
		}
		$order = self::reload_order( $order->get_id(), $order );
		try {
			return $this->apply_status_locked( $order, $data, $ref );
		} finally {
			$this->lock_release( $lock );
		}
	}

	/** INSERT IGNORE on the unique option_name — add_option() is not atomic. */
	private function lock_acquire( string $name ): bool {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $name, time() - self::LOCK_TTL ) ); // phpcs:ignore WordPress.DB
		$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return (int) $rows > 0;
	}

	private function lock_release( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Map an EasyPay orderState payload onto the order. Idempotent.
	 *
	 * paymentState: accepted (final), declined (final — also what a fully
	 * cancelled payment turns into), pending / none (not final).
	 */
	private function apply_status_locked( \WC_Order $order, array $data, string $ref ): bool {
		$state    = strtolower( (string) ( isset( $data['paymentState'] ) ? $data['paymentState'] : '' ) );
		$tx       = isset( $data['transactionId'] ) ? (string) $data['transactionId'] : '';
		$amount   = isset( $data['amount'] ) ? round( (float) $data['amount'], 2 ) : 0.0;
		$refunded = self::refunded_amount( $data );
		$paid_ref = (string) $order->get_meta( '_easypay_paid_ref' );

		// A late answer about an older attempt must not touch an order that was
		// paid through a newer one.
		if ( '' !== $paid_ref && $paid_ref !== $ref ) {
			return false;
		}
		if ( '' === $paid_ref && $ref !== (string) $order->get_meta( '_easypay_order_ref' ) && 'accepted' !== $state ) {
			return false;
		}

		if ( '' !== $tx && '0' !== $tx ) {
			$order->update_meta_data( '_easypay_transaction_id', $tx );
		}
		if ( $amount > 0 ) {
			$order->update_meta_data( '_easypay_amount', $amount );
		}
		$order->update_meta_data( '_easypay_state', $state );
		$prev_refunded = (float) $order->get_meta( '_easypay_refunded' );
		$order->update_meta_data( '_easypay_refunded', $refunded );

		if ( $refunded > $prev_refunded + 0.009 ) {
			$order->add_order_note(
				/* translators: %s: refunded amount */
				sprintf( __( 'EasyPay: refunds on this payment now total %s.', 'catcode-card-payments-with-easypay-for-woocommerce' ), wc_price( $refunded, array( 'currency' => $order->get_currency() ) ) )
			);
		}

		if ( 'accepted' === $state ) {
			$order->update_meta_data( '_easypay_paid_ref', $ref );
			$order->update_meta_data( '_easypay_order_ref', $ref );
			return $this->mark_paid( $order, $tx, $amount );
		}

		if ( 'declined' === $state ) {
			$was_accepted = '' !== $paid_ref;
			if ( $was_accepted || $order->is_paid() ) {
				// A paid payment that EasyPay now reports as declined was cancelled
				// in full (from the EasyPay cabinet).
				if ( ! $order->has_status( 'refunded' ) ) {
					$order->update_status( 'refunded', __( 'EasyPay: the payment was cancelled, the money is returned to the card.', 'catcode-card-payments-with-easypay-for-woocommerce' ) );
				}
				$order->save();
				return true;
			}
			if ( ! $order->has_status( array( 'failed', 'cancelled' ) ) ) {
				$reason = '';
				if ( ! empty( $data['error'] ) && is_array( $data['error'] ) ) {
					$reason = ' ' . (string) ( isset( $data['error']['errorMessage'] ) ? $data['error']['errorMessage'] : '' );
				}
				$order->update_status( 'failed', __( 'EasyPay: the payment was declined.', 'catcode-card-payments-with-easypay-for-woocommerce' ) . $reason );
			}
			$order->save();
			return true;
		}

		$order->save();
		return false;
	}

	/** Sum of accepted refunds, from refundedAmount or the refunds list. */
	public static function refunded_amount( array $data ): float {
		if ( isset( $data['refundedAmount'] ) && is_numeric( $data['refundedAmount'] ) ) {
			return round( (float) $data['refundedAmount'], 2 );
		}
		$sum = 0.0;
		if ( ! empty( $data['refunds'] ) && is_array( $data['refunds'] ) ) {
			foreach ( $data['refunds'] as $row ) {
				if ( is_array( $row ) && 'accepted' === strtolower( (string) ( isset( $row['paymentState'] ) ? $row['paymentState'] : '' ) ) ) {
					$sum += (float) ( isset( $row['refundAmount'] ) ? $row['refundAmount'] : 0 );
				}
			}
		}
		return round( $sum, 2 );
	}

	/** Money confirmed. A payment for less than the order costs is not payment. */
	private function mark_paid( \WC_Order $order, string $tx, float $amount ): bool {
		if ( $order->is_paid() ) {
			$order->save();
			return false;
		}
		$due = round( (float) $order->get_total(), 2 );
		if ( $amount > 0 && $amount + 0.01 < $due ) {
			if ( ! $order->has_status( 'on-hold' ) ) {
				$order->update_status(
					'on-hold',
					/* translators: 1: paid amount, 2: order total */
					sprintf( __( 'EasyPay: paid %1$s, but the order total is %2$s — check before shipping.', 'catcode-card-payments-with-easypay-for-woocommerce' ), wc_price( $amount, array( 'currency' => $order->get_currency() ) ), wc_price( $due, array( 'currency' => $order->get_currency() ) ) )
				);
			}
			$order->save();
			return false;
		}

		$order->payment_complete( $tx );
		if ( 'completed' === $this->get_option( 'complete_status', 'processing' ) && ! $order->has_status( 'completed' ) ) {
			$order->update_status( 'completed' );
		}
		$order->add_order_note(
			/* translators: %s: EasyPay transaction id */
			sprintf( __( 'EasyPay: payment received, transaction %s.', 'catcode-card-payments-with-easypay-for-woocommerce' ), $tx )
		);
		$order->save();
		return true;
	}
}
