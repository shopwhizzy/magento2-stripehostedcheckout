<?php

namespace ShopWhizzy\StripeHostedCheckout\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Stripe\StripeClient;

class Config
{
    private const XML_PATH_ENABLED = 'stripehostedcheckout/general/enabled';
    private const XML_PATH_SECRET_KEY = 'stripehostedcheckout/general/secret_key';
    private const XML_PATH_PUBLISHABLE_KEY = 'stripehostedcheckout/general/publishable_key';
    private const XML_PATH_WEBHOOK_SECRET = 'stripehostedcheckout/general/webhook_secret';

    private ?StripeClient $stripeClient = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getSecretKey(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_SECRET_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value ? $this->encryptor->decrypt($value) : null;
    }

    public function getPublishableKey(?int $storeId = null): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_PUBLISHABLE_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getWebhookSecret(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_WEBHOOK_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value ? $this->encryptor->decrypt($value) : null;
    }

    public function getStripeClient(?int $storeId = null): StripeClient
    {
        if ($this->stripeClient === null) {
            $this->stripeClient = new StripeClient($this->getSecretKey($storeId) ?: '');
        }

        return $this->stripeClient;
    }
}
