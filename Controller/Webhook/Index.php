<?php

namespace ShopWhizzy\StripeHostedCheckout\Controller\Webhook;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;
use ShopWhizzy\StripeHostedCheckout\Helper\Config;
use ShopWhizzy\StripeHostedCheckout\Model\OrderCreationService;
use ShopWhizzy\StripeHostedCheckout\Model\ResourceModel\Session as SessionResource;
use ShopWhizzy\StripeHostedCheckout\Model\Session as SessionModel;
use ShopWhizzy\StripeHostedCheckout\Model\SessionFactory;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Dedicated webhook endpoint for this module, separate from stripe/module-payments' own
 * /stripe/webhooks endpoint, so processing our sessions never interferes with the base
 * module's own event handling. Configure this URL + a matching signing secret as its own
 * webhook endpoint in the Stripe Dashboard, subscribed to checkout.session.completed,
 * checkout.session.expired, checkout.session.async_payment_succeeded, and
 * checkout.session.async_payment_failed (the last two matter for delayed/voucher payment
 * methods like Multibanco and MB WAY, where the customer completes checkout before the
 * payment itself actually clears).
 */
class Index implements CsrfAwareActionInterface
{
    private $response;
    private $request;

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly OrderCreationService $orderCreationService,
        private readonly SessionFactory $sessionFactory,
        private readonly SessionResource $sessionResource,
        private readonly LoggerInterface $logger
    ) {
        $this->response = $context->getResponse();
        $this->request = $context->getRequest();
    }

    public function execute()
    {
        $payload = $this->request->getContent();
        $signatureHeader = $this->request->getHeader('Stripe-Signature');
        $webhookSecret = $this->config->getWebhookSecret();

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $webhookSecret);
        } catch (\UnexpectedValueException | SignatureVerificationException $e) {
            $this->logger->error('ShopWhizzy_StripeHostedCheckout: invalid webhook payload/signature', ['exception' => $e]);
            $this->response->setHttpResponseCode(400);

            return $this->response;
        }

        $stripeSession = $event->data->object;

        if (empty($stripeSession->metadata['shopwhizzy_hosted_checkout'])) {
            $this->response->setHttpResponseCode(200);

            return $this->response;
        }

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                $this->orderCreationService->createFromSession($this->retrieveFullSession($stripeSession->id));
                break;
            case 'checkout.session.async_payment_failed':
                $this->orderCreationService->cancelFromFailedAsyncPayment($this->retrieveFullSession($stripeSession->id));
                break;
            case 'checkout.session.expired':
                $this->markExpired($stripeSession->id);
                break;
        }

        $this->response->setHttpResponseCode(200);

        return $this->response;
    }

    private function retrieveFullSession(string $sessionId): \Stripe\Checkout\Session
    {
        return $this->config->getStripeClient()->checkout->sessions->retrieve($sessionId, [
            'expand' => ['payment_intent', 'shipping_cost.shipping_rate'],
        ]);
    }

    private function markExpired(string $sessionId): void
    {
        $mapping = $this->sessionFactory->create();
        $this->sessionResource->loadBySessionId($mapping, $sessionId);
        if ($mapping->getId() && $mapping->getStatus() === SessionModel::STATUS_PENDING) {
            $mapping->setStatus(SessionModel::STATUS_EXPIRED);
            $this->sessionResource->save($mapping);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
