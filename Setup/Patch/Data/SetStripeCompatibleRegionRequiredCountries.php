<?php

namespace ShopWhizzy\StripeHostedCheckout\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Stripe Checkout's hosted page only shows a state/region input for a small, well-known
 * set of countries (US, CA, AU, IT, MX, JP, CN, IN, ID and similar) - this follows real
 * international postal-address conventions and isn't something the Checkout Session API
 * can override per country. Magento ships general/region/state_required with ~39
 * countries by default (including many, like Portugal, that Stripe never collects a
 * state for), which forces OrderCreationService to guess a placeholder region on every
 * such order. This one-time patch trims that list to the countries Stripe actually
 * supports, so real hosted-checkout orders don't hit the placeholder-region fallback.
 *
 * Deliberately does not merge with whatever is already configured, since a fresh value
 * that doesn't cause fallback guessing is safer than an unpredictable union - if a store
 * has since customized this list themselves, that happened after this patch already ran
 * once and won't be touched again.
 */
class SetStripeCompatibleRegionRequiredCountries implements DataPatchInterface
{
    private const CONFIG_PATH = 'general/region/state_required';
    private const STATE_REQUIRED_COUNTRIES = 'US,CA,AU,IT,MX,JP,CN,IN,ID';

    public function __construct(
        private readonly WriterInterface $configWriter
    ) {
    }

    public function apply(): void
    {
        $this->configWriter->save(self::CONFIG_PATH, self::STATE_REQUIRED_COUNTRIES);
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
