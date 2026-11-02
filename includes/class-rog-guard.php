<?php
defined( 'ABSPATH' ) || exit;

final class ROG_Guard {
	const OPTION = 'rog_settings';
	private static $blocked = array();
	private static $installed = false;

	public static function settings() {
		return wp_parse_args( get_option( self::OPTION, array() ), array(
			'enabled' => 'no', 'products' => array(), 'statuses' => array( 'pending', 'on-hold', 'failed' ),
		) );
	}

	/** Wrap only WCS's own callbacks, keeping priority, argument count and other listeners. */
	public static function install() {
		global $wp_filter;
		$hook = 'woocommerce_scheduled_subscription_payment';
		if ( self::$installed || empty( $wp_filter[ $hook ] ) ) {
			return;
		}
		$targets = array(
			'wc_subscriptions_manager::prepare_renewal',
			'wc_subscriptions_payment_gateways::gateway_scheduled_subscription_payment',
			'wc_subscriptions_manager::maybe_process_failed_renewal_for_repair',
		);
		$found = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				$name = is_string( $fn ) ? strtolower( $fn ) : '';
				if ( is_array( $fn ) && is_string( $fn[0] ) ) {
					$name = strtolower( $fn[0] . '::' . $fn[1] );
				}
				if ( in_array( $name, $targets, true ) ) {
					$found[ $name ] = array( $priority, $callback );
				}
			}
		}
		// Never intercept preparation unless the payment callback can be guarded too.
		if ( ! isset( $found[ $targets[0] ], $found[ $targets[1] ] ) ) {
			return;
		}
		foreach ( $found as $entry ) {
			list( $priority, $callback ) = $entry;
			$original = $callback['function'];
			remove_action( $hook, $original, $priority );
			add_action( $hook, static function ( ...$args ) use ( $original ) {
				if ( ! ROG_Guard::should_skip( $args[0] ?? 0 ) ) {
					return call_user_func_array( $original, $args );
				}
			}, $priority, $callback['accepted_args'] );
		}
		self::$installed = true;
	}

	public static function installed() {
		return self::$installed;
	}

	public static function matches( $subscription, $ids ) {
		foreach ( $subscription->get_items() as $item ) {
			if ( in_array( (int) $item->get_product_id(), $ids, true ) || in_array( (int) $item->get_variation_id(), $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function should_skip( $subscription_id ) {
		$id = is_object( $subscription_id ) ? $subscription_id->get_id() : absint( $subscription_id );
		// Scope memoization to this firing of the hook, not the whole queue batch.
		$key = $id . ':' . did_action( 'woocommerce_scheduled_subscription_payment' );
		if ( isset( self::$blocked[ $key ] ) ) {
			return self::$blocked[ $key ];
		}
		// Cache negative decisions too: a normal preparation creates a new pending
		// order, which must NOT be mistaken for a pre-existing order at payment time.
		self::$blocked[ $key ] = false;
		$options = self::settings();
		if ( 'yes' !== $options['enabled'] || empty( $options['products'] ) || empty( $options['statuses'] ) ) {
			return false;
		}
		$subscription = wcs_get_subscription( $id );
		if ( ! $subscription || ! $subscription->has_status( array( 'active', 'on-hold' ) ) || $subscription->payment_method_supports( 'gateway_scheduled_payments' ) ) {
			return false;
		}
		$due = $subscription->get_time( 'next_payment', 'gmt' );
		if ( ! $due || $due > time() || ! self::matches( $subscription, array_map( 'absint', $options['products'] ) ) ) {
			return false;
		}
		foreach ( $subscription->get_related_orders( 'ids', 'renewal' ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || $order->is_paid() || $order->get_date_paid() || ! $order->has_status( $options['statuses'] ) ) {
				continue;
			}
			self::$blocked[ $key ] = true;
			$message = sprintf( 'Renewal Order Guard: scheduled renewal skipped because unpaid renewal order #%d (%s) already exists. No new renewal order or scheduled charge was initiated. Pay or resolve the existing order.', $order->get_id(), $order->get_status() );
			$fingerprint = $due . ':' . $order->get_id();
			if ( $subscription->has_status( 'active' ) ) {
				// Standard WCS due-but-unpaid state; preserve billing dates and order.
				$subscription->update_status( 'on-hold', $message );
			} elseif ( $fingerprint !== $subscription->get_meta( '_rog_last_skip', true ) ) {
				$subscription->add_order_note( $message );
			}
			if ( $fingerprint !== $subscription->get_meta( '_rog_last_skip', true ) ) {
				$subscription->update_meta_data( '_rog_last_skip', $fingerprint );
				$subscription->save();
				wc_get_logger()->info( $message, array( 'source' => 'renewal-order-guard', 'subscription_id' => $id, 'order_id' => $order->get_id() ) );
			}
			return true;
		}
		return false;
	}
}
