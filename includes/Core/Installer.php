<?php
/**
 * Activation / deactivation.
 *
 * @package CatCodeEasyPayFree
 */

namespace CatCode\EasyPayFreeWC\Core;

defined( 'ABSPATH' ) || exit;

class Installer {

	public static function activate() {
		// No default texts are written to the database either: translations may
		// not be loaded yet, and an English string would freeze in the option.
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}
}
