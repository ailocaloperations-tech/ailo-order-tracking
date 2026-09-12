# Ailo Order Tracking

Carrier-agnostic shipment tracking for WooCommerce. No external service, no API key, no account.

Add a tracking number and carrier to any WooCommerce order, show it in the customer's
email, and let customers look the shipment up from a block on your site.

The plugin makes **no outbound requests at all**. You define carriers yourself as a name
plus a URL template, so it works with a national post office, a local courier, or a
freight company that has never heard of WordPress.

- **Requires:** WordPress 6.5+, WooCommerce, PHP 7.4+
- **License:** GPL-2.0-or-later
- **Status:** 1.1.0

---

## Why it is built this way

The interesting decisions are not in the feature list, so they are written down here.

### Order-number lookup requires proof of ownership

The block lets a customer search two ways, and they are not equally safe:

- **By tracking number.** The number itself is the secret — the customer got it from the
  shop, and it is not guessable. Nothing else is needed.
- **By order number.** Order numbers are *sequential*. Without proof of ownership anyone
  could walk them and read other people's shipping data. So this path always requires the
  billing email, or the phone number in full once both sides are reduced to their national
  form.

A successful response contains only the tracking number, the carrier name and the carrier
link. Never a name, address, email, phone or order total.

Failed attempts are rate limited, and two details matter:

- **Only failures count.** A customer who checks the same valid tracking number ten times
  is not doing anything wrong. Someone trying ten different contacts against one order
  number is.
- **Two buckets, and proxy headers are opt-in.** Failures are counted per caller address
  and, separately, per order number, so guessing the contact behind one order is capped no
  matter how many addresses the guesses come from. The caller address is `REMOTE_ADDR`
  unless the site opts in with `define( 'AILO_TRACK_TRUST_PROXY', true )` or the
  `ailo_track_trust_proxy_headers` filter, because `WC_Geolocation::get_ip_address()`
  believes `X-Forwarded-For` unconditionally, and on a store that is not behind a proxy a
  caller could forge it and mint a fresh bucket per request. Behind a real CDN or reverse
  proxy, opt in: otherwise every visitor shares the edge address and one bucket.

### One codebase for both order storage backends

WooCommerce keeps orders in High-Performance Order Storage (custom tables) or in the
legacy post store, and a shop can be on either. All order data here is read and written
through `WC_Order` rather than `get_post_meta()`, and the admin box, the orders-list column
and the settings screen are registered for both.

Compatibility is declared on `before_woocommerce_init`:

```php
FeaturesUtil::declare_compatibility( 'custom_order_tables', AILO_TRACK_FILE, true );
FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', AILO_TRACK_FILE, true );
```

Declaring later has no effect, and WooCommerce then marks the plugin incompatible — which
silently hides it from every store that has HPOS switched on.

There is a second, subtler trap this code avoids. `meta_query` is **not supported** by the
legacy order data store: it is accepted and then dropped, so a query meant to find one
order returns whatever came first. Lookups here use `meta_key` / `meta_value`, which are
portable across both stores, and then re-verify the returned order with `hash_equals`
before answering.

### Carrier URLs are stored raw and validated

A tracking URL is a template — `https://example.com/track?code={tracking}`. Running it
through `esc_url_raw()` on save strips the braces, so the template is stored as entered and
validated by its own checker instead.

### The block is server-rendered

`Order Tracking Lookup` renders on the server and inherits colour, spacing and typography
from the theme rather than shipping its own design. It can go in a page or in a Full Site
Editing template part.

---

## Extending

Two hooks exist so an add-on can automate what the free plugin does by hand.

**Register carriers from code.** The stored option holds carriers the shop owner typed in.
An add-on that talks to a courier API has no reason to make them type anything:

```php
add_filter( 'ailo_track_carriers', function ( $carriers ) {
    $carriers['my-courier'] = array(
        'label' => 'My Courier',
        'url'   => 'https://mycourier.example/track?code={tracking}',
    );
    return $carriers;
} );
```

Whatever a filter returns is put through the same shape check as stored carriers, and the
URL template is validated again before it reaches an `href`.

**Write a tracking number, and hear about it.** `ailo_track_set_shipment()` is the one
supported way to write shipment data, from the order screen or from code:

```php
ailo_track_set_shipment( $order, 'ABC123456', 'my-courier' );

add_action( 'ailo_track_shipment_saved', function ( $order_id, $number, $carrier, $prev_number ) {
    // fires only when something actually changed
}, 10, 4 );
```

The action fires **only on change**. WooCommerce runs both
`woocommerce_process_shop_order_meta` and `save_post_shop_order` for a single save of one
order, so an unconditional action would report every shipment twice.

---

## Install

1. Activate the plugin. WooCommerce must be active.
2. **WooCommerce → Settings → Shipping → Order tracking** — add your carriers. Put
   `{tracking}` where the carrier expects the number.
3. Add the **Order Tracking Lookup** block to a page.
4. On any order, fill in the tracking number and pick the carrier.

## Develop

```bash
cd ailo-order-tracking
npm install
npm run start     # watch
npm run build     # production
```

The block source lives in `src/`. `npm run build` emits the compiled asset and
`style-index.css`, which is what `src/block.json` points at — not `style.css`.

Translations: `languages/ailo-order-tracking.pot` carries all 47 translatable strings.

## Layout

```
ailo-order-tracking.php      bootstrap, constants, HPOS declaration
includes/
  admin-order.php            the tracking box on the order screen
  orders-list.php            the orders-list column
  order-meta.php             reading and writing through WC_Order
  carriers.php               carrier storage and URL-template validation
  rest.php                   the lookup endpoint, ownership checks, rate limiting
  emails.php                 tracking block in customer emails
  block.php                  block registration
  settings.php               the settings screen
src/                         block source and server-side render
uninstall.php                cleanup
```

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
