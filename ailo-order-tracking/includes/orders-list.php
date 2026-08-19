<?php
/**
 * Tracking column in the orders list.
 *
 * Two hook families again, for the same reason as the metabox: HPOS renders the
 * list through woocommerce_shop_order_list_table_*, legacy through the WP posts
 * table hooks. Registering only one leaves the column missing on half of stores.
 *
 * @package AiloOrderTracking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Insert the column right after the order status.
 *
 * @param array<string,string> $columns Existing columns.
 * @return array<string,string>
 */
function ailo_track_add_column( $columns ) {
	if ( ! is_array( $columns ) ) {
		return $columns;
	}
	$out = array();
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'order_status' === $key ) {
			$out['ailo_track'] = __( 'Tracking', 'ailo-order-tracking' );
		}
	}
	if ( ! isset( $out['ailo_track'] ) ) {
		$out['ailo_track'] = __( 'Tracking', 'ailo-order-tracking' );
	}
	return $out;
}
add_filter( 'woocommerce_shop_order_list_table_columns', 'ailo_track_add_column' );
add_filter( 'manage_edit-shop_order_columns', 'ailo_track_add_column' );

/**
 * Cell contents.
 *
 * @param WC_Order|int $order_or_id Order (HPOS) or order ID (legacy).
 * @return void
 */
function ailo_track_render_cell( $order_or_id ) {
	$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
	if ( ! $order ) {
		return;
	}
	$number = ailo_track_get_meta( $order, AILO_TRACK_META_NUMBER );
	if ( '' === $number ) {
		echo '<span aria-hidden="true">—</span><span class="screen-reader-text">'
			. esc_html__( 'No tracking number', 'ailo-order-tracking' ) . '</span>';
		return;
	}
	$url = ailo_track_build_url( ailo_track_get_meta( $order, AILO_TRACK_META_CARRIER ), $number );
	if ( '' !== $url ) {
		printf(
			'<a href="%s" target="_blank" rel="noopener noreferrer"><code>%s</code></a>',
			esc_url( $url ),
			esc_html( $number )
		);
		return;
	}
	echo '<code>' . esc_html( $number ) . '</code>';
}

add_action(
	'woocommerce_shop_order_list_table_custom_column',
	static function ( $column, $order ) {
		if ( 'ailo_track' === $column ) {
			ailo_track_render_cell( $order );
		}
	},
	10,
	2
);

add_action(
	'manage_shop_order_posts_custom_column',
	static function ( $column, $post_id ) {
		if ( 'ailo_track' === $column ) {
			ailo_track_render_cell( $post_id );
		}
	},
	10,
	2
);
