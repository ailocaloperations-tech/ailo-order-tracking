=== Ailo Order Tracking ===
Contributors: ailo
Tags: woocommerce, shipment tracking, order tracking, delivery, shipping
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Carrier-agnostic shipment tracking for WooCommerce. No external service, no API key, no account.

== Description ==

Add a tracking number and carrier to any WooCommerce order, show it in the customer's email, and let customers look the shipment up from a block on your site.

The plugin makes no outbound requests at all. There is no API, no key, no registration and no account. You define carriers yourself as a name plus a URL template, so it works with a national post office, a local courier, or a freight company that has never heard of WordPress.

**What it adds**

* A tracking box on the order edit screen (tracking number + carrier).
* A tracking column in the orders list.
* A tracking line in customer order emails, with a link to the carrier's tracking page when a URL template is set.
* An **Order Tracking Lookup** block customers use to find their shipment.
* A settings section at **WooCommerce → Settings → Shipping → Order tracking** where carriers are defined.

**How the lookup works**

The block gives customers two ways to find a shipment:

* By **tracking number**. The number itself is the secret: the customer got it from the shop, and it is not guessable. Nothing else is needed.
* By **order number plus the billing email or phone used on the order**. Order numbers are sequential, so without proof of ownership anyone could walk them and read other people's shipping data. The email must match, or the phone number in full once both sides are reduced to their national form.

A successful response contains only the tracking number, the carrier name and the carrier link. Never a name, address, email, phone or order total.

Failed attempts are rate limited twice over: per caller address and per order number, so guessing the contact behind one order is capped no matter how many addresses the guesses come from. Only failures count: a customer who checks the same valid tracking number ten times is not doing anything wrong. Proxy headers are ignored unless the site opts in with `define( 'AILO_TRACK_TRUST_PROXY', true )`; a store behind a CDN or reverse proxy should do so, or every visitor shares one bucket.

**Works with High-Performance Order Storage**

All order data is read and written through `WC_Order`. The admin box, the orders-list column and the settings screen are registered for both HPOS (custom order tables) and the legacy post store. Compatibility with custom order tables and cart/checkout blocks is declared on `before_woocommerce_init`. Nothing to configure.

**Carrier URL templates**

A tracking URL is a template, for example `https://example.com/track?code={tracking}`. The template is stored as entered and validated by the plugin's own checker: it must start with `http://` or `https://` and include a domain.

**The block**

`Order Tracking Lookup` renders on the server and inherits colour, spacing and typography from the theme rather than shipping its own design. It can go in a page or in a Full Site Editing template part. Block options: which search modes are offered (tracking number, order number, or both), an optional heading, whether to show the carrier link, and the placeholder text.

**For developers**

Two hooks exist so an add-on can automate what the plugin does by hand:

* `ailo_track_carriers` (filter): register carriers from code. Whatever the filter returns goes through the same shape check as stored carriers, and the URL template is validated again before it reaches an `href`.
* `ailo_track_set_shipment( $order, $number, $carrier )`: the one supported way to write shipment data from code.
* `ailo_track_shipment_saved` (action, 4 arguments: order ID, number, carrier, previous number): fires only when something actually changed.

Order meta keys are `_ailo_track_number` and `_ailo_track_carrier`. The REST namespace is `ailo-track/v1`.

**Uninstall**

Uninstalling removes the plugin's own carrier option. Tracking numbers stored on orders are deliberately left in place: they are the shop's business record.

Source code, block source and build setup: https://github.com/ailocaloperations-tech/ailo-order-tracking

== Installation ==

1. Install and activate the plugin. WooCommerce must be installed and active (the plugin declares `Requires Plugins: woocommerce`, which WordPress 6.5+ enforces on activation).
2. Go to **WooCommerce → Settings → Shipping → Order tracking** and add your carriers. The tracking URL is a template: put `{tracking}` where the carrier expects the number, for example `https://example.com/track?code={tracking}`.
3. Add the **Order Tracking Lookup** block to a page or template part.
4. On any order, fill in the tracking number and pick the carrier. The customer's order email will include it.

== Frequently Asked Questions ==

= Does this send anything to an external server? =

No. The plugin makes no outbound requests at all. Carrier links are plain links your customer clicks; the plugin never calls them.

= Can customers see other people's orders? =

Looking up by order number always requires the billing email or the phone number on that order, and repeated failures are rate limited. Successful responses never include a name, address, email, phone or order total.

= Which carriers are supported? =

All of them, because none are built in. You add a name and a URL template, so any carrier with a public tracking page works. Put `{tracking}` where the carrier expects the number.

= Does it work with HPOS (High-Performance Order Storage)? =

Yes. The plugin declares compatibility with custom order tables and uses `WC_Order` throughout, so the same code works on both the legacy post store and the custom tables.

= Can I add carriers or tracking numbers from code? =

Yes. Use the `ailo_track_carriers` filter to register carriers, `ailo_track_set_shipment()` to write a tracking number, and the `ailo_track_shipment_saved` action to be told when one changed.

= What happens to tracking numbers when I uninstall? =

They stay on the orders. Uninstall removes only the plugin's carrier option.

== Screenshots ==

1. The tracking box on the WooCommerce order edit screen: the tracking number field and the carrier dropdown, with a saved value shown as a link to the carrier's tracking page.
2. The orders list with the tracking column showing the number and carrier for each shipped order.
3. WooCommerce → Settings → Shipping → Order tracking: the carriers table with a carrier name and a URL template containing `{tracking}`.
4. The Order Tracking Lookup block in the block editor, with the block sidebar showing the mode, heading, carrier link and placeholder options.
5. The Order Tracking Lookup block on the front end, styled by the active theme, after a successful lookup: tracking number, carrier name and carrier link.
6. A customer order email with the tracking line and carrier link.

== Changelog ==

= 1.1.0 =
* Added the `ailo_track_carriers` filter so an add-on can register carriers instead of the shop owner typing them.
* Added `ailo_track_set_shipment()` and the `ailo_track_shipment_saved` action, so writing a tracking number from code raises one signal, and only when something changed.
* Added `languages/ailo-order-tracking.pot`.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.1.0 =
Adds developer hooks and the translation template. No settings or data changes.
