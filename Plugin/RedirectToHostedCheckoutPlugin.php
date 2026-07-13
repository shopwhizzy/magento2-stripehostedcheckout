<?php

namespace ShopWhizzy\StripeHostedCheckout\Plugin;

use Magento\Checkout\Controller\Index\Index;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\RedirectFactory;
use Psr\Log\LoggerInterface;
use ShopWhizzy\StripeHostedCheckout\Helper\Config;
use ShopWhizzy\StripeHostedCheckout\Model\CheckoutSessionBuilder;

/**
 * Intercepts all navigation to Magento's native checkout (cart button, mini-cart button,
 * and direct /checkout URL all resolve here) and redirects to Stripe's hosted Checkout
 * page instead, when the module is enabled.
 */
class RedirectToHostedCheckoutPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly CheckoutSession $checkoutSession,
        private readonly CheckoutSessionBuilder $sessionBuilder,
        private readonly RedirectFactory $redirectFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function aroundExecute(Index $subject, callable $proceed)
    {
        if (!$this->config->isEnabled()) {
            return $proceed();
        }

        $quote = $this->checkoutSession->getQuote();
        if (!$quote->hasItems() || $quote->getHasError()) {
            return $proceed();
        }

        try {
            $stripeSession = $this->sessionBuilder->buildFromQuote($quote);
        } catch (\Exception $e) {
            $this->logger->error(
                'ShopWhizzy_StripeHostedCheckout: failed to create Stripe Checkout Session',
                ['exception' => $e]
            );
            return $proceed();
        }

        $result = $this->redirectFactory->create();
        $result->setUrl($stripeSession->url);

        return $result;
    }
}
