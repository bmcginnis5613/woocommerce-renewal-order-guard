<?php
/**
 * Plugin Name: Woocommerce - Subscriptions Renewal Order Guard
 * Description: Prevent duplicate scheduled renewal orders when an unpaid renewal already exists for selected subscription products or variations.
 * Version: 1.0.0
 * Author: FirstTracks Marketing
 * Author URI: https://firsttracksmarketing.com
 * Requires Plugins: woocommerce, woocommerce-subscriptions
 */

defined( 'ABSPATH' ) || exit;

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

require_once __DIR__ . '/includes/class-rog-guard.php';
require_once __DIR__ . '/includes/class-rog-admin.php';

add_action( 'plugins_loaded', static function () {
	ROG_Admin::init( __FILE__ );
	if ( class_exists( 'WC_Subscription' ) && class_exists( 'WC_Subscriptions_Payment_Gateways' ) ) {
		// WCS registers its payment callbacks during its own initialization.
		add_action( 'init', array( 'ROG_Guard', 'install' ), 100 );
	}
}, 100 );
