<?php
/**
 * Server-side render for ailo/order-tracking-lookup.
 *
 * The markup is built and escaped here rather than in JavaScript, so a store
 * with JS disabled still gets a readable, accessible form, and so nothing the
 * shop owner typed into an attribute can reach the page unescaped.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks (unused).
 * @var WP_Block $block      Block instance.
 *
 * @package AiloOrderTracking
 */

defined( 'ABSPATH' ) || exit;

$ailo_mode    = isset( $attributes['mode'] ) ? (string) $attributes['mode'] : 'both';
$ailo_heading = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '';
$ailo_link    = ! empty( $attributes['showCarrierLink'] );
$ailo_place   = isset( $attributes['placeholderText'] ) ? (string) $attributes['placeholderText'] : '';

if ( ! in_array( $ailo_mode, array( 'tracking', 'order', 'both' ), true ) ) {
	$ailo_mode = 'both';
}
if ( '' === $ailo_place ) {
	$ailo_place = __( 'e.g. ABC123456789', 'ailo-order-tracking' );
}

$ailo_show_tracking = in_array( $ailo_mode, array( 'tracking', 'both' ), true );
$ailo_show_order    = in_array( $ailo_mode, array( 'order', 'both' ), true );

// Unique per instance so several blocks on one page keep their labels tied to
// the right inputs — otherwise duplicate ids break the label/for relationship
// and screen readers announce the wrong field.
$ailo_uid = wp_unique_id( 'ailo-track-' );

$ailo_wrapper = get_block_wrapper_attributes( array( 'class' => 'ailo-track' ) );
?>
<div <?php echo wp_kses_data( $ailo_wrapper ); ?>
	data-mode="<?php echo esc_attr( $ailo_mode ); ?>"
	data-carrier-link="<?php echo $ailo_link ? '1' : '0'; ?>">

	<?php if ( '' !== $ailo_heading ) : ?>
		<h2 class="ailo-track__heading"><?php echo esc_html( $ailo_heading ); ?></h2>
	<?php endif; ?>

	<form class="ailo-track__form" novalidate>
		<?php if ( $ailo_show_tracking ) : ?>
			<div class="ailo-track__field">
				<label class="ailo-track__label" for="<?php echo esc_attr( $ailo_uid ); ?>-number">
					<?php esc_html_e( 'Tracking number', 'ailo-order-tracking' ); ?>
				</label>
				<input class="ailo-track__input" type="text" inputmode="latin"
					id="<?php echo esc_attr( $ailo_uid ); ?>-number"
					name="tracking" autocomplete="off"
					placeholder="<?php echo esc_attr( $ailo_place ); ?>" />
			</div>
		<?php endif; ?>

		<?php if ( $ailo_show_tracking && $ailo_show_order ) : ?>
			<p class="ailo-track__or"><?php esc_html_e( 'or', 'ailo-order-tracking' ); ?></p>
		<?php endif; ?>

		<?php if ( $ailo_show_order ) : ?>
			<div class="ailo-track__field">
				<label class="ailo-track__label" for="<?php echo esc_attr( $ailo_uid ); ?>-order">
					<?php esc_html_e( 'Order number', 'ailo-order-tracking' ); ?>
				</label>
				<input class="ailo-track__input" type="text" inputmode="numeric"
					id="<?php echo esc_attr( $ailo_uid ); ?>-order"
					name="order_id" autocomplete="off" />
			</div>
			<div class="ailo-track__field">
				<label class="ailo-track__label" for="<?php echo esc_attr( $ailo_uid ); ?>-contact">
					<?php esc_html_e( 'Email or phone used on the order', 'ailo-order-tracking' ); ?>
				</label>
				<input class="ailo-track__input" type="text"
					id="<?php echo esc_attr( $ailo_uid ); ?>-contact"
					name="contact" autocomplete="off" />
			</div>
		<?php endif; ?>

		<button class="ailo-track__submit" type="submit">
			<?php esc_html_e( 'Track', 'ailo-order-tracking' ); ?>
		</button>

		<?php // aria-live so the result is announced without moving focus away from the form. ?>
		<div class="ailo-track__result" role="status" aria-live="polite"></div>
	</form>

	<noscript>
		<p class="ailo-track__noscript">
			<?php esc_html_e( 'Looking up a shipment needs JavaScript. Your tracking number is also in your order confirmation email.', 'ailo-order-tracking' ); ?>
		</p>
	</noscript>
</div>
