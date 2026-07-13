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

## Requirements

- Magento 2.4.9, PHP 8.3+
- `stripe/stripe-payments` (or at minimum `stripe/stripe-php`) installed via Composer
- A Stripe account (test or live) with API keys

## Installation

This module lives in `app/code/ShopWhizzy/StripeHostedCheckout` and is also wired up
as a Composer path-repository package (`shopwhizzy/stripehostedcheckout`), so it
installs like any other package while the real source stays in `app/code` for
development:

```bash
composer config repositories.shopwhizzy-stripehostedcheckout path app/code/ShopWhizzy/StripeHostedCheckout
composer require shopwhizzy/stripehostedcheckout:@dev
bin/magento module:enable ShopWhizzy_StripeHostedCheckout
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

If you're pulling this module from its own Git/Packagist repository into a fresh
install instead of developing in `app/code`, just `composer require
shopwhizzy/stripehostedcheckout` per your normal repository configuration and run
the same `setup:upgrade` / `setup:di:compile` / `cache:flush` steps.

## Setup

Go to **Stores > Configuration > Sales > Stripe Hosted Checkout**:

1. **Stripe Secret Key** / **Stripe Publishable Key** — from your Stripe Dashboard.
   This module keeps its own copy of these keys rather than reusing
   `stripe/module-payments`'s configuration, since that module's internals are not a
   public extension point.
2. **Webhook Signing Secret** — create a webhook endpoint in the Stripe Dashboard (or
   via the API) pointing at:

   ```
   https://<your-domain>/shopwhizzystripehostedcheckout/webhook/index
   ```

   subscribed to `checkout.session.completed` and `checkout.session.expired`. Paste
   the endpoint's signing secret (`whsec_...`) into this field. This is a **separate**
   endpoint from `stripe/module-payments`'s own `/stripe/webhooks`, so processing
   never interferes with that module's event handling.
3. **Enable Stripe Hosted Checkout Redirect** — set to Yes to activate the redirect.
   This toggle is independent of `stripe/module-payments`'s own "Payment Flow"
   setting — that setting only changes payment behavior *within* Magento's native
   checkout and has no effect on this module.

With the toggle off, the store behaves exactly as stock Magento — nothing is
patched or overridden, only a plugin that no-ops.

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

## Caveats / known limitations (v1.0.0)

- **Region-required countries without a Stripe state field.** Magento can require a
  region/state for a country (`general/region/state_required`) even though Stripe's
  hosted page doesn't collect a state field for every country (confirmed for
  Portugal). When this happens, the module assigns a placeholder region so the order
  can still be placed, and discloses this on the order via a status history comment.
  These orders should have their address verified with the customer before shipping.
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
