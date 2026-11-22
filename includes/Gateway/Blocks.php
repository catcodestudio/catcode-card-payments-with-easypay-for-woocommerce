<?php
/**
 * Block checkout integration.
 *
 * @package CatCodeEasyPayFree
 */

namespace CatCode\EasyPayFreeWC\Gateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use CatCode\EasyPayFreeWC\Core\Plugin;

defined( 'ABSPATH' ) || exit;

final class Blocks extends AbstractPaymentMethodType {

	/** @var string */
	protected $name = Method::ID;

	/** @var Method|null */
	private $gateway;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . Method::ID . '_settings', array() );
		$this->gateway  = Plugin::gateway();
	}

	public function is_active() {
		return $this->gateway ? (bool) $this->gateway->is_available() : false;
	}

	public function get_payment_method_script_handles() {
		$handle = 'catcode-easypay-blocks';
		wp_register_script(
			$handle,
			CATCODE_EASYPAY_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities' ),
			CATCODE_EASYPAY_VERSION,
			true
		);
		return array( $handle );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway ? $this->gateway->title : __( 'Card, Apple Pay or Google Pay (EasyPay)', 'catcode-card-payments-with-easypay-for-woocommerce' ),
			'description' => $this->gateway ? $this->gateway->description : '',
			'supports'    => array( 'products' ),
		);
	}
}
