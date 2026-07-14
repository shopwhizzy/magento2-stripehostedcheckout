<?php

namespace ShopWhizzy\StripeHostedCheckout\Block\Adminhtml;

use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Block\Info;
use ShopWhizzy\StripeHostedCheckout\Helper\Config;

/**
 * Renders the "Payment Information" panel on the admin order view page for orders
 * placed via Stripe Hosted Checkout, with a link out to the Stripe Dashboard.
 */
class PaymentInfo extends Info
{
    protected $_template = 'ShopWhizzy_StripeHostedCheckout::payment/info.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getPaymentMethodLabel(): string
    {
        return (string) $this->getInfo()->getAdditionalInformation('stripe_payment_method_label') ?: 'Stripe';
    }

    public function getPaymentIntentId(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('stripe_payment_intent_id')
            ?: $this->getInfo()->getLastTransId();
    }

    public function getDashboardUrl(): ?string
    {
        $paymentIntentId = $this->getPaymentIntentId();
        if (!$paymentIntentId) {
            return null;
        }

        $mode = $this->config->isTestMode() ? 'test/' : '';

        return 'https://dashboard.stripe.com/' . $mode . 'payments/' . $paymentIntentId;
    }
}
