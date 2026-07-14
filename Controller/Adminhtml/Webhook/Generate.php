<?php

namespace ShopWhizzy\StripeHostedCheckout\Controller\Adminhtml\Webhook;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use ShopWhizzy\StripeHostedCheckout\Helper\Config;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Creates a Stripe webhook endpoint for this module using whatever secret key is
 * currently in the admin form - saved or not - and returns the signing secret so the
 * JS can fill in the Webhook Signing Secret field before the admin clicks Save Config.
 */
class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'ShopWhizzy_StripeHostedCheckout::config';

    private const WEBHOOK_PATH = 'stripehostedcheckout/webhooks';
    private const WEBHOOK_EVENTS = [
        'checkout.session.completed',
        'checkout.session.expired',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
    ];

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();

        $secretKey = trim((string) $this->getRequest()->getParam('secret_key'));
        if ($secretKey === '') {
            $secretKey = (string) $this->config->getSecretKey();
        }

        if ($secretKey === '') {
            return $result->setData([
                'success' => false,
                'message' => (string) __('Enter a Stripe Secret Key above first.'),
            ]);
        }

        try {
            $stripeClient = new StripeClient($secretKey);
            $webhookUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/') . '/' . self::WEBHOOK_PATH;

            $this->removeExistingEndpoints($stripeClient, $webhookUrl);

            $endpoint = $stripeClient->webhookEndpoints->create([
                'url' => $webhookUrl,
                'enabled_events' => self::WEBHOOK_EVENTS,
                'description' => 'ShopWhizzy StripeHostedCheckout',
            ]);

            return $result->setData([
                'success' => true,
                'webhook_secret' => $endpoint->secret,
                'message' => (string) __(
                    'Webhook created for %1. Click "Save Config" below to store the signing secret.',
                    $webhookUrl
                ),
            ]);
        } catch (ApiErrorException $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => (string) __('Unexpected error: %1', $e->getMessage()),
            ]);
        }
    }

    /**
     * Stripe doesn't return an existing endpoint's signing secret on subsequent list/get
     * calls (only at creation), so re-running this always needs a fresh endpoint. Remove
     * any prior one at the same URL first so repeated clicks don't leave duplicates.
     */
    private function removeExistingEndpoints(StripeClient $stripeClient, string $webhookUrl): void
    {
        $endpoints = $stripeClient->webhookEndpoints->all(['limit' => 100]);
        foreach ($endpoints->data as $endpoint) {
            if ($endpoint->url === $webhookUrl) {
                $stripeClient->webhookEndpoints->delete($endpoint->id);
            }
        }
    }
}
