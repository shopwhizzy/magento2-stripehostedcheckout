<?php

namespace ShopWhizzy\StripeHostedCheckout\Model\Payment;

use Magento\Payment\Model\Method\AbstractMethod;

/**
 * Record-keeping payment method for orders placed via Stripe's hosted Checkout page.
 *
 * Payment has already been captured by Stripe before the order is created, so this method
 * never performs its own authorize/capture calls and is never shown as a selectable option
 * in Magento's native checkout (that page is bypassed entirely for this flow).
 */
class StripeHostedCheckoutMethod extends AbstractMethod
{
    public const CODE = 'shopwhizzy_stripehostedcheckout';

    protected $_code = self::CODE;

    protected $_isOffline = true;

    protected $_canUseCheckout = false;

    protected $_canUseInternal = false;

    protected $_canAuthorize = false;

    protected $_canCapture = false;

    protected $_canRefund = false;

    protected $_canVoid = false;
}
