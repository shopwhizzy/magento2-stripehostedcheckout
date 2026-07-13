<?php

namespace ShopWhizzy\StripeHostedCheckout\Block\Product;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use ShopWhizzy\StripeHostedCheckout\Helper\Config;

class BuyNow extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getCheckoutUrl(): string
    {
        return $this->getUrl('checkout');
    }
}
