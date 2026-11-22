<?php
/**
 * Uninstall — remove plugin options and cached data. Order meta stays: it is
 * the payment history of real orders.
 *
 * @package CatCodeEasyPayFree
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_catcode_easypay_settings' );
delete_option( 'catcode_easypay_uid' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_catcode\\_easypay\\_app\\_%' OR option_name LIKE '\\_transient\\_timeout\\_catcode\\_easypay\\_app\\_%'" ); // phpcs:ignore WordPress.DB
