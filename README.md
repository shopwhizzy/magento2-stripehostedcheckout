# ShopWhizzy_StripeHostedCheckout

**Version 1.0.0**

Redirects Magento 2 checkout straight to Stripe's hosted Checkout page — address
collection, shipping method selection, coupons, and payment all happen on Stripe's
page instead of Magento's native checkout. A Magento order is created, invoiced, and
marked `processing` only after Stripe confirms payment.

## What it does

When enabled, every checkout entry point skips Magento's own checkout pages and
redirects to a Stripe Checkout Session built from the current cart:

- Cart page "Proceed to Checkout" button
- Mini-cart "Proceed to Checkout" button
- Direct navigation to `/checkout`
- Product page "Buy Now" button (added by this module, next to Add to Cart)

On Stripe's page the customer enters their shipping/billing address, picks a shipping
method, and pays using whichever payment methods are enabled in your Stripe account.
On successful payment, the module converts the quote into a real Magento order,
invoices it, and redirects the customer to Magento's normal order-success page.

Order creation is triggered by a dedicated Stripe webhook (authoritative) and, as a
UX convenience, by the browser's return from Stripe — whichever happens first wins;
the other is a no-op.

The payment method used (e.g. "Visa •••• 4242", "Multibanco", "PayPal") is shown as
its own column in **Sales > Orders**, and the order view's Payment Information panel
includes the Payment Intent ID with a direct link to view it in the Stripe Dashboard.

## Demo

[Demo Product](https://app-83914d.shopwhizzyapps.com/demo-product.html) — click
**Buy Now** to see the redirect straight into Stripe's hosted Checkout page. The same
redirect happens from the cart page and mini-cart "Proceed to Checkout" buttons once
enabled.

## Requirements

- Magento 2.4.9, PHP 8.3+
- `stripe/stripe-payments` (or at minimum `stripe/stripe-php`) installed via Composer
- A Stripe account (test or live) with API keys

## Installation

```bash
composer require shopwhizzy/stripehostedcheckout
bin/magento module:enable ShopWhizzy_StripeHostedCheckout
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Setup

Go to **Stores > Configuration > ShopWhizzy > Stripe Hosted Checkout**:

1. **Stripe Secret Key** / **Stripe Publishable Key** — from your Stripe Dashboard.
   This module keeps its own copy of these keys rather than reusing
   `stripe/module-payments`'s configuration, since that module's internals are not a
   public extension point.
2. **Generate Webhook** — click this after pasting your Secret Key (works even before
   clicking "Save Config" — it reads the key straight out of the form). It creates a
   webhook endpoint in Stripe pointed at this module's handler, subscribed to
   `checkout.session.completed`, `checkout.session.expired`,
   `checkout.session.async_payment_succeeded`, and
   `checkout.session.async_payment_failed`, and fills in the **Webhook Signing
   Secret** field below with the result. Click "Save Config" afterward to persist it.
   Re-clicking replaces the previous endpoint rather than creating duplicates. This is
   a **separate** endpoint from `stripe/module-payments`'s own `/stripe/webhooks`, so
   processing never interferes with that module's event handling.
3. **Enable Stripe Hosted Checkout Redirect** — set to Yes to activate the redirect.
   This toggle is independent of `stripe/module-payments`'s own "Payment Flow"
   setting — that setting only changes payment behavior *within* Magento's native
   checkout and has no effect on this module.

With the toggle off, the store behaves exactly as stock Magento — nothing is
patched or overridden, only a plugin that no-ops.

If you'd rather create the webhook endpoint yourself (e.g. in the Stripe Dashboard),
point it at:

```
https://<your-domain>/stripehostedcheckout/webhooks
```

subscribed to the same four `checkout.session.*` events listed above.

## How shipping is handled

- If the quote already has a shipping address (logged-in customer's default, or
  entered via the cart's shipping estimator), the module fetches Magento's real
  shipping rates for that address — including table rates — and offers all of them
  as Stripe shipping options.
- If no address is known yet (e.g. checkout clicked straight from an empty cart
  estimate), it falls back to Flat Rate and Free Shipping only, computed from store
  config with no destination required. Table rates can't be included in this case
  since they need a real destination.
- Whichever method the customer already had selected in Magento (if any) is placed
  **first** in the list sent to Stripe, since Stripe pre-selects the first shipping
  option shown on its page.

## How coupons are handled

The customer applies their Magento coupon code on the cart page as usual — existing
validation, usage limits, and reporting are untouched. The resulting discount amount
is passed to Stripe as a one-off, single-use Stripe Coupon applied to the session.
Stripe's own native promo-code entry box is not shown, to avoid running two separate
coupon systems.

## Delayed / asynchronous payment methods (Multibanco, MB WAY, OXXO, bank transfers...)

Some payment methods don't complete at checkout — the customer gets a voucher or
reference and pays later (Multibanco can take hours or days). For these, Stripe marks
the Checkout Session `status` as `complete` (checkout itself is done) while
`payment_status` stays `unpaid` until the money actually arrives, which can happen
long after the browser has left the site.

The module handles this by placing the order immediately in Magento's normal `new` /
`pending` state (not invoiced) as soon as checkout completes, then invoicing it and
moving it to `processing` only when `checkout.session.async_payment_succeeded` fires —
or cancelling it if `checkout.session.async_payment_failed` fires instead (voucher
expired / payment failed). **Do not ship orders still in `pending` state** — they
haven't been paid yet. This logic mirrors `stripe/module-payments`'s own return
controller, which gates on session `status` rather than `payment_status` for exactly
this reason.

## Caveats / known limitations (v1.0.0)

- **Region-required countries without a Stripe state field.** Stripe's hosted page
  only shows a state/region input for a handful of countries where that's part of
  standard postal addressing (US, CA, AU, IT, MX, JP, CN, IN, ID, ...) — this follows
  real-world address formats and isn't something the Checkout Session API can
  override per country. A one-time install patch
  (`Setup/Patch/Data/SetStripeCompatibleRegionRequiredCountries`) trims Magento's
  `general/region/state_required` down to that same set, since Magento's much broader
  default list (~39 countries) would otherwise force a guess for every order from a
  country Stripe doesn't collect a state for (confirmed live for Portugal). If a
  country outside that trimmed set still ends up requiring a region in your store's
  config, the module falls back to a placeholder region so the order can still be
  placed, and discloses this on the order via a status history comment — those orders
  should have their address verified with the customer before shipping.
- **Shipping total on the fallback path.** When the fallback shipping options were
  used (no address known at redirect time) and the real address the customer enters
  on Stripe turns out to need a different table rate, the order's shipping amount is
  set to whatever Stripe actually charged (funds are already captured and can't be
  changed retroactively) — the carrier/method code recorded on the order reflects
  what the customer picked, which may not perfectly match Magento's own recomputed
  rate for that exact address.
- **"Buy Now" bypasses client-side option validation.** The button submits the
  standard add-to-cart form via a native DOM submit (to guarantee a real page
  navigation instead of being intercepted by AJAX add-to-cart JS), which skips
  Magento's client-side required-option validation for products with custom options.
  The server still validates and will show a normal error page if something's
  missing — no data corruption risk, just a less polished error path for products
  with required options.
- **Single currency/store assumptions.** Built and tested against a single-store,
  single-currency setup. Multi-currency/multi-website behavior hasn't been
  exercised.
- **No gift cards, store credit, or multi-shipping-address orders.** Out of scope
  for this version.
- **Own payment method, not a gateway integration.** `shopwhizzy_stripehostedcheckout`
  is a record-keeping payment method only — it never calls Stripe itself (payment is
  already captured via Checkout before the order exists), so it has no
  authorize/capture/refund support. Refunds should be issued from the Stripe
  Dashboard (or via `stripe/module-payments` if applicable) and reflected on the
  Magento order manually.
