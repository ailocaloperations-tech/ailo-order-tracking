=== Ailo Order Tracking ===
Tags: woocommerce, shipment tracking, order tracking, delivery
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Carrier-agnostic shipment tracking for WooCommerce. No external service, no API key, no account.

== Description ==

Add a tracking number and carrier to any WooCommerce order, show it in customer emails, and let customers look it up from a block on your site.

The plugin talks to nothing. There is no API, no key, no registration, and no account. You define your own carriers as a name plus a URL template, so it works with a national post office, a local courier, or a freight company that has never heard of WordPress.

**How looking up works**

The block gives customers two ways to find a shipment:

* By **tracking number** — the number itself is the secret, so nothing else is needed.
* By **order number plus the email or phone used on the order** — proof of ownership is always required here. Order numbers are sequential, so without that proof anyone could walk them and read other people's shipping data. The email must match, or the phone number in full once both are reduced to their national form.

The response contains only the tracking number, the carrier name and the carrier link. Never a name, address, email, phone or order total. Failed attempts are rate limited per IP.

**Works with High-Performance Order Storage**

All order data is read and written through `WC_Order`, and the admin box, orders-list column and settings are registered for both HPOS and legacy post storage. Nothing to configure.

**The block**

`Order Tracking Lookup` is a server-rendered block. It inherits colour, spacing and typography from your theme instead of shipping its own design, and it can be placed in a page or in a Full Site Editing template part. You choose what customers may search by, whether to link to the carrier, and the placeholder text.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Go to **WooCommerce → Settings → Shipping → Order tracking** and add your carriers. The tracking URL is a template: put `{tracking}` where the carrier expects the number, for example `https://example.com/track?code={tracking}`.
3. Add the **Order Tracking Lookup** block to a page.
4. On any order, fill in the tracking number and pick the carrier.

== Frequently Asked Questions ==

= Does this send anything to an external server? =

No. The plugin makes no outbound requests at all. Carrier links are plain links your customer clicks; the plugin never calls them.

= Can customers see other people's orders? =

Looking up by order number always requires the billing email or phone on that order, and repeated failures are rate limited. Successful responses never include personal data.

= Which carriers are supported? =

All of them, because none are built in. You add a name and a URL template, so any carrier with a public tracking page works.

= Does it work with HPOS? =

Yes. The plugin declares compatibility with custom order tables and uses `WC_Order` throughout.

= Where is the source code? =

https://github.com/ailocaloperations-tech/ailo-order-tracking — including the block source and the build setup.

== Changelog ==

= 1.1.0 =
* Added the `ailo_track_carriers` filter so an add-on can register carriers instead of the shop owner typing them.
* Added `ailo_track_set_shipment()` and the `ailo_track_shipment_saved` action, so writing a tracking number from code raises one signal, and only when something changed.
* Added `languages/ailo-order-tracking.pot`.

= 1.0.0 =
* First release.
