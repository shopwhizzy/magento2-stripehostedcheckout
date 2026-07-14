<?php

namespace ShopWhizzy\StripeHostedCheckout\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\RegionFactory;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\DB\TransactionFactory;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;
use ShopWhizzy\StripeHostedCheckout\Model\Payment\StripeHostedCheckoutMethod;
use ShopWhizzy\StripeHostedCheckout\Model\ResourceModel\Session as SessionResource;
use Stripe\Checkout\Session as StripeCheckoutSession;

/**
 * Idempotent conversion of a completed Stripe Checkout Session into a Magento order.
 * Called from both the success-URL return controller and the webhook handler; whichever
 * fires first creates the order, the other is a no-op guarded by the session map's status.
 */
class OrderCreationService
{
    public function __construct(
        private readonly SessionResource $sessionResource,
        private readonly SessionFactory $sessionFactory,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly RegionFactory $regionFactory,
        private readonly RegionCollectionFactory $regionCollectionFactory,
        private readonly DirectoryHelper $directoryHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Creates (or, on repeat calls, reconciles) the Magento order for a Checkout Session.
     *
     * Gating on session `status` (not `payment_status`) matters: for delayed/voucher
     * payment methods (Multibanco, MB WAY, OXXO, bank transfers, ...) the customer has
     * completed checkout and received their payment reference, but the money hasn't
     * cleared yet - `status` is already 'complete' while `payment_status` stays 'unpaid'
     * until a later async_payment_succeeded event arrives, sometimes hours or days later.
     * Waiting for 'paid' here (as this used to) meant those customers got no order and no
     * redirect at all. stripe/module-payments' own return controller has the same check
     * (`if ($session->status == "complete")`), confirming this is the correct signal.
     */
    public function createFromSession(StripeCheckoutSession $stripeSession): ?OrderInterface
    {
        $mapping = $this->sessionFactory->create();
        $this->sessionResource->loadBySessionId($mapping, $stripeSession->id);

        if (!$mapping->getId()) {
            return null;
        }

        if ($mapping->getStatus() === Session::STATUS_COMPLETED && $mapping->getOrderId()) {
            try {
                $order = $this->orderRepository->get($mapping->getOrderId());
            } catch (\Exception $e) {
                return null;
            }

            if ($stripeSession->payment_status === 'paid') {
                $this->ensureInvoiced($order, $stripeSession);
            }

            return $order;
        }

        if ($stripeSession->status !== 'complete') {
            return null;
        }

        try {
            $order = $this->buildOrder($stripeSession, (int) $mapping->getQuoteId());
        } catch (\Exception $e) {
            $this->logger->error(
                'ShopWhizzy_StripeHostedCheckout: order creation failed for session ' . $stripeSession->id,
                ['exception' => $e]
            );
            return null;
        }

        $mapping->setStatus(Session::STATUS_COMPLETED);
        $mapping->setOrderId($order->getEntityId());
        $this->sessionResource->save($mapping);

        return $order;
    }

    /**
     * Called for checkout.session.async_payment_failed: the voucher expired or the
     * delayed payment failed after the order was already placed in a pending state.
     */
    public function cancelFromFailedAsyncPayment(StripeCheckoutSession $stripeSession): void
    {
        $mapping = $this->sessionFactory->create();
        $this->sessionResource->loadBySessionId($mapping, $stripeSession->id);

        if (!$mapping->getId() || !$mapping->getOrderId()) {
            return;
        }

        try {
            $order = $this->orderRepository->get($mapping->getOrderId());
        } catch (\Exception $e) {
            return;
        }

        if ($order->canCancel()) {
            $order->cancel();
            $order->addCommentToStatusHistory(
                'Stripe async payment (e.g. Multibanco/MB WAY voucher) failed or expired. Order cancelled.'
            );
            $this->orderRepository->save($order);
        }
    }

    /**
     * Invoices a pending order once a delayed payment method's payment actually clears.
     */
    private function ensureInvoiced(OrderInterface $order, StripeCheckoutSession $stripeSession): void
    {
        if ($order->hasInvoices()) {
            return;
        }

        $paymentIntentId = $this->resolvePaymentIntentId($stripeSession);

        $this->invoiceOrder($order, $paymentIntentId);

        $order->setState(SalesOrder::STATE_PROCESSING);
        $order->setStatus(SalesOrder::STATE_PROCESSING);
        $order->addCommentToStatusHistory(
            'Stripe async payment confirmed. Payment Intent: ' . $paymentIntentId
        );
        $this->orderRepository->save($order);
    }

    private function buildOrder(StripeCheckoutSession $stripeSession, int $quoteId): OrderInterface
    {
        $quote = $this->cartRepository->get($quoteId);

        $shippingDetails = $stripeSession->collected_information->shipping_details ?? null;
        $customerDetails = $stripeSession->customer_details ?? null;
        $email = $customerDetails->email ?? $quote->getCustomerEmail();
        $phone = $customerDetails->phone ?? null;

        $quote->setCustomerEmail($email);
        $regionWasGuessed = false;

        if ($shippingDetails) {
            $shippingAddress = $quote->getShippingAddress();
            $regionWasGuessed = $this->applyAddressData(
                $shippingAddress,
                $shippingDetails->name ?? '',
                $shippingDetails->address,
                $email,
                $phone
            ) || $regionWasGuessed;

            $billingAddress = $quote->getBillingAddress();
            $billingSource = $customerDetails->address ?? $shippingDetails->address;
            $billingName = $customerDetails->name ?? ($shippingDetails->name ?? '');
            $regionWasGuessed = $this->applyAddressData(
                $billingAddress,
                $billingName,
                $billingSource,
                $email,
                $phone
            ) || $regionWasGuessed;

            [$carrierCode, $methodCode] = $this->resolveShippingMethod($stripeSession);
            $shippingAddress->setCollectShippingRates(true);
            $shippingAddress->setShippingMethod($carrierCode . '_' . $methodCode);
        }

        $quote->setPaymentMethod(StripeHostedCheckoutMethod::CODE);
        $quote->getPayment()->importData(['method' => StripeHostedCheckoutMethod::CODE]);
        $quote->setInventoryProcessed(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        $orderId = $this->cartManagement->placeOrder($quote->getId());
        $order = $this->orderRepository->get($orderId);

        $paymentIntentId = $this->resolvePaymentIntentId($stripeSession);
        $paymentMethodSummary = $this->extractPaymentMethodSummary($stripeSession);

        $payment = $order->getPayment();
        $payment->setLastTransId($paymentIntentId);
        $payment->setTransactionId($paymentIntentId);
        $payment->setAdditionalInformation('stripe_payment_intent_id', $paymentIntentId);
        $payment->setAdditionalInformation('stripe_payment_method_type', $paymentMethodSummary['type']);
        $payment->setAdditionalInformation('stripe_payment_method_label', $paymentMethodSummary['label']);

        // Also copied to sales_order_grid by Magento's own grid-sync, so it's visible on
        // Sales > Orders without opening each order (see etc/db_schema.xml).
        $order->setData('stripehostedcheckout_payment_method', $paymentMethodSummary['label']);

        if ($stripeSession->payment_status === 'paid') {
            $this->invoiceOrder($order, $paymentIntentId);
            $order->setState(SalesOrder::STATE_PROCESSING);
            $order->setStatus(SalesOrder::STATE_PROCESSING);
            $comment = 'Paid via Stripe Hosted Checkout. Payment Intent: ' . $paymentIntentId;
        } else {
            // Delayed payment method (Multibanco, MB WAY, OXXO, bank transfer, ...): the
            // customer has their voucher/reference but hasn't paid yet. Order is placed now
            // (Magento's default new/pending state - not invoiced) so nothing is lost if they
            // never come back to the site; ensureInvoiced() finishes the job once
            // checkout.session.async_payment_succeeded confirms the money actually arrived.
            $comment = 'Order placed via Stripe Hosted Checkout using a delayed payment method '
                . '(e.g. Multibanco/MB WAY). Awaiting payment confirmation - do not ship until invoiced. '
                . 'Payment Intent: ' . $paymentIntentId;
        }

        if ($regionWasGuessed) {
            $comment .= ' NOTE: Stripe did not collect a state/region for this address\'s country;'
                . ' a placeholder region was assigned so the order could be placed. Please verify the'
                . ' address with the customer before shipping.';
        }
        $order->addCommentToStatusHistory($comment);
        $this->orderRepository->save($order);

        return $order;
    }

    private function resolvePaymentIntentId(StripeCheckoutSession $stripeSession): string
    {
        return is_string($stripeSession->payment_intent)
            ? $stripeSession->payment_intent
            : ($stripeSession->payment_intent->id ?? $stripeSession->id);
    }

    /**
     * Human-readable label for the payment method used, e.g. "Visa •••• 4242" or
     * "Multibanco". Requires the session to have been retrieved with
     * `payment_intent.payment_method` expanded - falls back to a generic label
     * otherwise rather than making an extra API call here.
     */
    private function extractPaymentMethodSummary(StripeCheckoutSession $stripeSession): array
    {
        $paymentMethod = is_object($stripeSession->payment_intent)
            ? ($stripeSession->payment_intent->payment_method ?? null)
            : null;

        if (!$paymentMethod || is_string($paymentMethod)) {
            return ['type' => 'unknown', 'label' => 'Stripe'];
        }

        $type = $paymentMethod->type ?? 'unknown';

        if ($type === 'card' && !empty($paymentMethod->card)) {
            $brand = ucfirst((string) $paymentMethod->card->brand);
            $last4 = (string) $paymentMethod->card->last4;

            return ['type' => $type, 'label' => trim($brand . ' •••• ' . $last4)];
        }

        return ['type' => $type, 'label' => $this->humanizePaymentMethodType($type)];
    }

    private function humanizePaymentMethodType(string $type): string
    {
        $knownLabels = [
            'mb_way' => 'MB WAY',
            'multibanco' => 'Multibanco',
            'sepa_debit' => 'SEPA Direct Debit',
            'us_bank_account' => 'US Bank Account',
            'ideal' => 'iDEAL',
            'eps' => 'EPS',
            'paypal' => 'PayPal',
        ];

        return $knownLabels[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    /**
     * Applies Stripe's collected address onto a quote address.
     *
     * @return bool true if Magento requires a region for this country but Stripe didn't
     *              collect one (not all countries get a state field on Stripe's hosted
     *              page), meaning a placeholder region had to be guessed to satisfy
     *              Magento's own address validation.
     */
    private function applyAddressData(
        \Magento\Quote\Model\Quote\Address $address,
        string $fullName,
        object $stripeAddress,
        string $email,
        ?string $phone = null
    ): bool {
        [$firstName, $lastName] = $this->splitName($fullName);

        $countryId = $stripeAddress->country ?? '';
        $regionId = null;
        $regionText = $stripeAddress->state ?? '';
        $regionWasGuessed = false;

        if ($countryId && $regionText) {
            $region = $this->regionFactory->create()->loadByCode($regionText, $countryId);
            if (!$region->getId()) {
                $region = $this->regionFactory->create()->loadByName($regionText, $countryId);
            }
            if ($region->getId()) {
                $regionId = $region->getId();
                $regionText = $region->getName();
            }
        }

        if (!$regionId && $countryId && $this->directoryHelper->isRegionRequired($countryId)) {
            $fallbackRegion = $this->regionCollectionFactory->create()
                ->addCountryFilter($countryId)
                ->setPageSize(1)
                ->getFirstItem();
            if ($fallbackRegion->getId()) {
                $regionId = $fallbackRegion->getId();
                $regionText = $fallbackRegion->getName();
                $regionWasGuessed = true;
            }
        }

        $address->setFirstname($firstName);
        $address->setLastname($lastName);
        $address->setEmail($email);
        $address->setStreet(array_filter([$stripeAddress->line1 ?? '', $stripeAddress->line2 ?? '']));
        $address->setCity($stripeAddress->city ?? '');
        $address->setRegion($regionText);
        if ($regionId) {
            $address->setRegionId($regionId);
        }
        $address->setPostcode($stripeAddress->postal_code ?? '');
        $address->setCountryId($countryId);
        $address->setTelephone($phone ?: 'N/A');

        return $regionWasGuessed;
    }

    private function splitName(string $fullName): array
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            return ['Guest', 'Customer'];
        }
        $parts = explode(' ', $fullName, 2);

        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    private function resolveShippingMethod(StripeCheckoutSession $stripeSession): array
    {
        $shippingRate = $stripeSession->shipping_cost->shipping_rate ?? null;
        if ($shippingRate && !is_string($shippingRate) && !empty($shippingRate->metadata['carrier_code'])) {
            return [$shippingRate->metadata['carrier_code'], $shippingRate->metadata['method_code']];
        }

        return ['flatrate', 'flatrate'];
    }

    private function invoiceOrder(OrderInterface $order, string $transactionId): void
    {
        if (!$order->canInvoice()) {
            return;
        }

        /** @var Invoice $invoice */
        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->setTransactionId($transactionId);
        $invoice->register();

        $transactionSave = $this->transactionFactory->create();
        $transactionSave->addObject($invoice)->addObject($invoice->getOrder())->save();
    }
}
