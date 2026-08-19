<?php
/**
 * Tracking details in customer emails.
 *
 * Only customer-facing emails, and only when a tracking number exists — a block
 * that renders an empty "Tracking:" line in every order confirmation is worse
 * than no block at all.
 *
 * @package AiloOrderTracking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Append tracking details to the order email.
 *
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Whether this copy goes to the shop admin.
 * @param bool     $plain_text    Whether the email is plain text.
 * @return void
 */
function ailo_track_email_details( $order, $sent_to_admin, $plain_text = false ) {
	if ( $sent_to_admin || ! $order instanceof WC_Order ) {
		return;
	}

	$number = ailo_track_get_meta( $order, AILO_TRACK_META_NUMBER );
	if ( '' === $number ) {
		return;
	}

	$carrier_slug  = ailo_track_get_meta( $order, AILO_TRACK_META_CARRIER );
	$carrier_label = '' !== $carrier_slug ? ailo_track_carrier_label( $carrier_slug ) : '';
	$url           = ailo_track_build_url( $carrier_slug, $number );

	if ( $plain_text ) {
		echo "\n" . esc_html__( 'Shipment tracking', 'ailo-order-tracking' ) . "\n";
		if ( '' !== $carrier_label ) {
			echo esc_html( $carrier_label ) . "\n";
		}
		echo esc_html( $number ) . "\n";
		if ( '' !== $url ) {
			echo esc_url_raw( $url ) . "\n";
		}
		return;
	}
	?>
	<div style="margin:0 0 24px;padding:16px;border:1px solid #e0e0e0;border-radius:4px;">
		<p style="margin:0 0 8px;font-weight:bold;">
			<?php esc_html_e( 'Shipment tracking', 'ailo-order-tracking' ); ?>
		</p>
		<?php if ( '' !== $carrier_label ) : ?>
			<p style="margin:0 0 4px;"><?php echo esc_html( $carrier_label ); ?></p>
		<?php endif; ?>
		<p style="margin:0 0 8px;font-family:monospace;font-size:15px;">
			<?php echo esc_html( $number ); ?>
		</p>
		<?php if ( '' !== $url ) : ?>
			<p style="margin:0;">
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Track this shipment', 'ailo-order-tracking' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>
	<?php
}
add_action( 'woocommerce_email_order_details', 'ailo_track_email_details', 15, 4 );
