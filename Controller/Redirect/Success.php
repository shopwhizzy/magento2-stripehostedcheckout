<?php

namespace ShopWhizzy\StripeHostedCheckout\Controller\Redirect;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Psr\Log\LoggerInterface;
use ShopWhizzy\StripeHostedCheckout\Helper\Config;
use ShopWhizzy\StripeHostedCheckout\Model\OrderCreationService;

/**
 * Stripe Checkout success_url target. The webhook (Controller\Webhook\Index) is the
 * authoritative order-creation trigger; this controller mainly provides the pleasant
 * redirect-to-success-page UX and idempotently no-ops if the webhook already ran.
 */
class Success extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly OrderCreationService $orderCreationService,
        private readonly CheckoutSession $checkoutSession,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $sessionId = (string) $this->getRequest()->getParam('session_id');
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$sessionId) {
            $resultRedirect->setPath('checkout/cart');

            return $resultRedirect;
        }

        try {
            $stripeSession = $this->config->getStripeClient()->checkout->sessions->retrieve($sessionId, [
                'expand' => ['payment_intent.payment_method', 'shipping_cost.shipping_rate'],
            ]);
        } catch (\Exception $e) {
            $this->logger->error(
                'ShopWhizzy_StripeHostedCheckout: failed to retrieve session ' . $sessionId,
                ['exception' => $e]
            );
            $this->messageManager->addErrorMessage(
                __('We could not confirm your payment. Please contact us if you were charged.')
            );
            $resultRedirect->setPath('checkout/cart');

            return $resultRedirect;
        }

        $order = $this->orderCreationService->createFromSession($stripeSession);

        if (!$order) {
            $this->messageManager->addErrorMessage(
                __('Your payment could not be confirmed yet. Please contact us if you were charged.')
            );
            $resultRedirect->setPath('checkout/cart');

            return $resultRedirect;
        }

        $this->checkoutSession->clearHelperData();
        $this->checkoutSession->setLastQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastOrderId($order->getEntityId());
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
        $this->checkoutSession->setLastOrderStatus($order->getStatus());

        $resultRedirect->setPath('checkout/onepage/success');

        return $resultRedirect;
    }
}
