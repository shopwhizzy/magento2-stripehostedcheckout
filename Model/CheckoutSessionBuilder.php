<?php

namespace ShopWhizzy\StripeHostedCheckout\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\ShippingMethodManagementInterface;
use Magento\Store\Model\ScopeInterface;
use ShopWhizzy\StripeHostedCheckout\Helper\Config;
use Stripe\Checkout\Session as StripeCheckoutSession;

/**
 * Builds and creates a Stripe Checkout Session from a Magento quote, and persists the
 * session-to-quote mapping needed to reconcile the order once Stripe confirms payment.
 */
class CheckoutSessionBuilder
{
    public function __construct(
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ShippingMethodManagementInterface $shippingMethodManagement,
        private readonly UrlInterface $urlBuilder,
        private readonly SessionFactory $sessionFactory,
        private readonly \ShopWhizzy\StripeHostedCheckout\Model\ResourceModel\Session $sessionResource
    ) {
    }

    public function buildFromQuote(CartInterface $quote): StripeCheckoutSession
    {
        $quote->collectTotals();

        $storeId = (int) $quote->getStoreId();
        $currency = strtolower((string) $quote->getQuoteCurrencyCode());

        $params = [
            'mode' => 'payment',
            'line_items' => $this->buildLineItems($quote, $currency),
            'shipping_address_collection' => [
                'allowed_countries' => $this->getAllowedCountries($storeId),
            ],
            'phone_number_collection' => [
                'enabled' => true,
            ],
            'success_url' => $this->urlBuilder->getUrl('shopwhizzystripehostedcheckout/redirect/success')
                . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->urlBuilder->getUrl('shopwhizzystripehostedcheckout/redirect/cancel')
                . '?session_id={CHECKOUT_SESSION_ID}',
            'metadata' => [
                'magento_quote_id' => (string) $quote->getId(),
                'shopwhizzy_hosted_checkout' => '1',
            ],
        ];

        $shippingOptions = $this->buildShippingOptions($quote, $currency);
        if (!empty($shippingOptions)) {
            $params['shipping_options'] = $shippingOptions;
        }

        $discounts = $this->buildDiscounts($quote, $currency, $storeId);
        if (!empty($discounts)) {
            $params['discounts'] = $discounts;
        }

        $email = $quote->getCustomerEmail();
        if ($email) {
            $params['customer_email'] = $email;
        }

        $session = $this->config->getStripeClient($storeId)->checkout->sessions->create($params);

        $this->persistMapping($session->id, (int) $quote->getId(), $storeId);

        return $session;
    }

    private function buildLineItems(CartInterface $quote, string $currency): array
    {
        $lineItems = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $unitAmount = (int) round((float) $item->getPriceInclTax() * 100);
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $unitAmount,
                    'product_data' => [
                        'name' => $item->getName(),
                        'metadata' => ['sku' => $item->getSku()],
                    ],
                ],
                'quantity' => (int) $item->getQty(),
            ];
        }

        return $lineItems;
    }

    private function buildShippingOptions(CartInterface $quote, string $currency): array
    {
        $shippingAddress = $quote->getShippingAddress();

        if ($shippingAddress && $shippingAddress->getCountryId()) {
            try {
                $rates = $this->shippingMethodManagement->getList($quote->getId());
            } catch (\Exception $e) {
                $rates = [];
            }

            if (!empty($rates)) {
                $selectedMethod = $shippingAddress->getShippingMethod();
                $options = [];
                foreach ($rates as $rate) {
                    $option = [
                        'shipping_rate_data' => [
                            'type' => 'fixed_amount',
                            'fixed_amount' => [
                                'amount' => (int) round((float) $rate->getAmount() * 100),
                                'currency' => $currency,
                            ],
                            'display_name' => trim($rate->getCarrierTitle() . ' - ' . $rate->getMethodTitle()),
                            'metadata' => [
                                'carrier_code' => $rate->getCarrierCode(),
                                'method_code' => $rate->getMethodCode(),
                            ],
                        ],
                    ];

                    // Stripe pre-selects whichever option is first in the array, so the method
                    // already chosen in Magento (e.g. via the cart's shipping estimator) must be
                    // moved to the front - otherwise Stripe silently defaults to a different one.
                    if ($selectedMethod && $rate->getCarrierCode() . '_' . $rate->getMethodCode() === $selectedMethod) {
                        array_unshift($options, $option);
                    } else {
                        $options[] = $option;
                    }
                }

                return $options;
            }
        }

        return $this->buildFallbackShippingOptions($quote, $currency);
    }

    /**
     * Used when the quote has no shipping address yet: table rates can't be computed
     * without a destination, so only address-independent methods are offered here.
     * The real rate is recomputed once Stripe returns the actual address (see
     * OrderCreationService); see plan's "Reconciliation note" for the tradeoff.
     */
    private function buildFallbackShippingOptions(CartInterface $quote, string $currency): array
    {
        $storeId = (int) $quote->getStoreId();
        $options = [];

        if ($this->scopeConfig->isSetFlag('carriers/flatrate/active', ScopeInterface::SCOPE_STORE, $storeId)) {
            $price = (float) $this->scopeConfig->getValue('carriers/flatrate/price', ScopeInterface::SCOPE_STORE, $storeId);
            $title = (string) $this->scopeConfig->getValue('carriers/flatrate/title', ScopeInterface::SCOPE_STORE, $storeId);
            $options[] = [
                'shipping_rate_data' => [
                    'type' => 'fixed_amount',
                    'fixed_amount' => ['amount' => (int) round($price * 100), 'currency' => $currency],
                    'display_name' => $title ?: 'Flat Rate',
                    'metadata' => ['carrier_code' => 'flatrate', 'method_code' => 'flatrate'],
                ],
            ];
        }

        if ($this->scopeConfig->isSetFlag('carriers/freeshipping/active', ScopeInterface::SCOPE_STORE, $storeId)) {
            $threshold = (float) $this->scopeConfig->getValue(
                'carriers/freeshipping/free_shipping_subtotal',
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            if ((float) $quote->getSubtotal() >= $threshold) {
                $name = (string) $this->scopeConfig->getValue('carriers/freeshipping/name', ScopeInterface::SCOPE_STORE, $storeId);
                $options[] = [
                    'shipping_rate_data' => [
                        'type' => 'fixed_amount',
                        'fixed_amount' => ['amount' => 0, 'currency' => $currency],
                        'display_name' => $name ?: 'Free Shipping',
                        'metadata' => ['carrier_code' => 'freeshipping', 'method_code' => 'freeshipping'],
                    ],
                ];
            }
        }

        return $options;
    }

    private function buildDiscounts(CartInterface $quote, string $currency, int $storeId): array
    {
        $couponCode = $quote->getCouponCode();
        if (!$couponCode) {
            return [];
        }

        $discountAmount = round((float) $quote->getSubtotal() - (float) $quote->getSubtotalWithDiscount(), 2);
        if ($discountAmount <= 0) {
            return [];
        }

        $coupon = $this->config->getStripeClient($storeId)->coupons->create([
            'amount_off' => (int) round($discountAmount * 100),
            'currency' => $currency,
            'duration' => 'once',
            'name' => 'Discount: ' . $couponCode,
        ]);

        return [['coupon' => $coupon->id]];
    }

    /**
     * Countries Stripe Checkout's shipping_address_collection will accept, per
     * https://stripe.com/docs/api/checkout/sessions/create#create_checkout_session-shipping_address_collection-allowed_countries
     * Magento's own country list includes deprecated/sanctioned codes (e.g. AN, FX, CU, KP, SY)
     * that Stripe rejects outright, so the two lists must be intersected.
     */
    private const STRIPE_SUPPORTED_COUNTRIES = [
        'AC', 'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO',
        'CR', 'CV', 'CW', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER',
        'ES', 'ET', 'FI', 'FJ', 'FK', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL',
        'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HN', 'HR', 'HT', 'HU', 'ID',
        'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IS', 'IT', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI',
        'KM', 'KN', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV',
        'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MK', 'ML', 'MM', 'MN', 'MO', 'MQ', 'MR', 'MS', 'MT',
        'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU',
        'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PY', 'QA',
        'RE', 'RO', 'RS', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL',
        'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SZ', 'TA', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ',
        'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'US', 'UY', 'UZ', 'VA',
        'VC', 'VE', 'VG', 'VN', 'VU', 'WF', 'WS', 'XK', 'YE', 'YT', 'ZA', 'ZM', 'ZW', 'ZZ',
    ];

    private function getAllowedCountries(int $storeId): array
    {
        $allow = (string) $this->scopeConfig->getValue('general/country/allow', ScopeInterface::SCOPE_STORE, $storeId);
        $countries = $allow ? explode(',', $allow) : ['US'];
        $countries = array_values(array_intersect($countries, self::STRIPE_SUPPORTED_COUNTRIES));

        return $countries ?: ['US'];
    }

    private function persistMapping(string $sessionId, int $quoteId, int $storeId): void
    {
        $session = $this->sessionFactory->create();
        $session->setData([
            'session_id' => $sessionId,
            'quote_id' => $quoteId,
            'store_id' => $storeId,
            'status' => \ShopWhizzy\StripeHostedCheckout\Model\Session::STATUS_PENDING,
        ]);
        $this->sessionResource->save($session);
    }
}
