<?php

namespace ShopWhizzy\StripeHostedCheckout\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class GenerateWebhookButton extends Field
{
    protected $_template = 'ShopWhizzy_StripeHostedCheckout::system/config/generate_webhook.phtml';

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getAjaxUrl(): string
    {
        return $this->getUrl('stripehostedcheckout/webhook/generate');
    }
}
