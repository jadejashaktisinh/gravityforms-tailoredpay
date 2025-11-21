<?php
/*
Plugin Name: Gravity Forms TailoredPay Gateway
Description: Integrates Gravity Forms with the TailoredPay gateway using Collect.js.
Version: 1.0
Author: Your Name
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GF_TAILOREDPAY_VERSION', '1.0' );
define( 'GF_TAILOREDPAY_PLUGIN_URL', plugins_url( '', __FILE__ ) );

add_action( 'gform_loaded', array( 'GF_TailoredPay_Bootstrap', 'load' ), 5 );

class GF_TailoredPay_Bootstrap {
	public static function load() {
		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'gravity_forms_missing_notice' ) );
			return;
		}

		require_once 'class-gf-tailoredpay-gateway.php';
		require_once 'class-gf-tailoredpay-pay-later.php';

		GFAddOn::register( 'GF_TailoredPay_Gateway' );
		
		// Initialize pay-later functionality
		GF_TailoredPay_Pay_Later::get_instance();
	}

	public static function gravity_forms_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo 'TailoredPay Gateway requires Gravity Forms to be installed and activated.';
		echo '</p></div>';
	}
}
