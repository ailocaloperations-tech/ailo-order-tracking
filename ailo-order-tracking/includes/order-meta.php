<?php
/**
 * HPOS-safe order meta access.
 *
 * Everything in this plugin goes through here. The point is that no caller ever
 * touches get_post_meta(): on a store with High-Performance Order Storage the
 * order is not a post, so post meta silently reads nothing and writes to a row
 * nobody looks at. Routing through WC_Order means the same call lands in
 * wc_orders_meta or postmeta depending on what the store has enabled.
 *
 * @package AiloOrderTracking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read one meta value from an order.
 *
 * @param WC_Order|int $order_or_id Order or order ID.
 * @param string       $key         Meta key.
 * @return string Empty string when the order does not exist.
 */
function ailo_track_get_meta( $order_or_id, $key ) {
	$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
	if ( ! $order ) {
		return '';
	}
	return (string) $order->get_meta( $key, true );
}

/**
 * Write one meta value to an order.
 *
 * @param WC_Order|int $order_or_id Order or order ID.
 * @param string       $key         Meta key.
 * @param string       $value       Value; an empty string deletes the key.
 * @return bool True when the order existed and was saved.
 */
function ailo_track_update_meta( $order_or_id, $key, $value ) {
	$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
	if ( ! $order ) {
		return false;
	}
	if ( '' === $value ) {
		$order->delete_meta_data( $key );
	} else {
		$order->update_meta_data( $key, $value );
	}
	$order->save_meta_data();
	return true;
}

/**
 * Normalise a tracking number for storage and comparison.
 *
 * Carriers print tracking numbers with spaces and dashes that customers copy
 * inconsistently, so both the stored value and anything typed into the lookup
 * form are reduced to the same shape before they are compared.
 *
 * @param string $value Raw value.
 * @return string Uppercase alphanumeric, max 64 characters.
 */
function ailo_track_normalize_number( $value ) {
	$value = preg_replace( '/[^A-Za-z0-9]/', '', (string) $value );
	return strtoupper( substr( (string) $value, 0, 64 ) );
}
