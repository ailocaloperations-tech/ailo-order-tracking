<?php
/**
 * Tracking box on the order edit screen.
 *
 * Registered twice on purpose: 'woocommerce_page_wc-orders' is the screen ID
 * when High-Performance Order Storage is on, 'shop_order' is the post type when
 * it is off. A plugin that registers only one of the two is invisible on half
 * the stores out there, and that is the single most common HPOS mistake.
 *
 * @package AiloOrderTracking
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'add_meta_boxes',
	static function () {
		$screen = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'ailo-track-box',
			__( 'Shipment tracking', 'ailo-order-tracking' ),
			'ailo_track_render_metabox',
			$screen,
			'side',
			'default'
		);
	}
);

/**
 * Render the box.
 *
 * @param WP_Post|WC_Order $post_or_order Post on legacy storage, order on HPOS.
 * @return void
 */
function ailo_track_render_metabox( $post_or_order ) {
	$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
	if ( ! $order ) {
		return;
	}

	$number   = ailo_track_get_meta( $order, AILO_TRACK_META_NUMBER );
	$carrier  = ailo_track_get_meta( $order, AILO_TRACK_META_CARRIER );
	$carriers = ailo_track_get_carriers();
	$url      = ailo_track_build_url( $carrier, $number );

	wp_nonce_field( 'ailo_track_save_' . $order->get_id(), 'ailo_track_nonce' );
	?>
	<p>
		<label for="ailo_track_number"><strong><?php esc_html_e( 'Tracking number', 'ailo-order-tracking' ); ?></strong></label>
		<input type="text" id="ailo_track_number" name="ailo_track_number" class="widefat"
			value="<?php echo esc_attr( $number ); ?>" autocomplete="off" />
	</p>
	<p>
		<label for="ailo_track_carrier"><strong><?php esc_html_e( 'Carrier', 'ailo-order-tracking' ); ?></strong></label>
		<select id="ailo_track_carrier" name="ailo_track_carrier" class="widefat">
			<option value=""><?php esc_html_e( '— none —', 'ailo-order-tracking' ); ?></option>
			<?php foreach ( $carriers as $slug => $data ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $carrier ); ?>>
					<?php echo esc_html( $data['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>
	<?php if ( empty( $carriers ) ) : ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to the plugin settings screen */
				esc_html__( 'No carriers configured yet. %s', 'ailo-order-tracking' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping&section=ailo_track' ) ) . '">' . esc_html__( 'Add one', 'ailo-order-tracking' ) . '</a>'
			);
			?>
		</p>
	<?php endif; ?>
	<?php if ( '' !== $url ) : ?>
		<p>
			<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Open tracking page', 'ailo-order-tracking' ); ?>
			</a>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Save the box. Both hooks fire the same handler for the same HPOS reason.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function ailo_track_save_metabox( $order_id ) {
	$order_id = absint( $order_id );
	if ( ! $order_id ) {
		return;
	}

	// Nonce first, then capability. A capability check alone is not CSRF
	// protection: a logged-in shop manager who visits a hostile page has the
	// capability, but did not intend the request.
	if (
		! isset( $_POST['ailo_track_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ailo_track_nonce'] ) ), 'ailo_track_save_' . $order_id )
	) {
		return;
	}
	// edit_shop_order is not a reliable meta cap on HPOS before WooCommerce 10.7:
	// the mapping can fail and the save then aborts silently, losing what the shop
	// manager just typed. WooCommerce core pairs it with manage_woocommerce for
	// exactly this reason, so we mirror that rather than inventing our own rule.
	if ( ! current_user_can( 'edit_shop_order', $order_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	// is_scalar guard: a crafted request can post an array here. Without it,
	// preg_replace inside the normaliser raises a PHP 8 warning and the order
	// ends up with the literal string "ARRAY" as its tracking number.
	$raw    = isset( $_POST['ailo_track_number'] ) ? wp_unslash( $_POST['ailo_track_number'] ) : '';
	$number = is_scalar( $raw ) ? ailo_track_normalize_number( (string) $raw ) : '';

	$carrier  = isset( $_POST['ailo_track_carrier'] ) ? sanitize_key( wp_unslash( $_POST['ailo_track_carrier'] ) ) : '';
	$carriers = ailo_track_get_carriers();
	if ( '' !== $carrier && ! isset( $carriers[ $carrier ] ) ) {
		$carrier = '';
	}

	// Through the shared writer, not two raw meta writes: it also raises
	// ailo_track_shipment_saved, and only when something really changed. Both
	// woocommerce_process_shop_order_meta and save_post_shop_order fire for one
	// save, so the second pass finds no change and stays quiet.
	ailo_track_set_shipment( $order_id, $number, $carrier );
}
add_action( 'woocommerce_process_shop_order_meta', 'ailo_track_save_metabox' );
add_action( 'save_post_shop_order', 'ailo_track_save_metabox' );
