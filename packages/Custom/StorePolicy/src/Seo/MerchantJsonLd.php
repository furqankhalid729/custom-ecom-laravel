<?php

namespace Custom\StorePolicy\Seo;

use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Custom\StorePolicy\Support\Regions;

/**
 * Schema.org data that Google Merchant Center reads alongside the policy pages.
 */
class MerchantJsonLd
{
    public function __construct(protected PolicySettingRepository $settingRepository) {}

    /**
     * Saved settings for the current channel, or null when none have been saved yet.
     */
    public function currentSettings(): ?array
    {
        $channel = core()->getCurrentChannel();

        if (! $channel || ! $this->settingRepository->existsForChannel($channel->id)) {
            return null;
        }

        return $this->settingRepository->forChannel($channel->id)->toSettingsArray();
    }

    public function returnPolicies(array $settings): array
    {
        $policies = [];

        foreach (Regions::enabled($settings) as $region) {
            $returns = $region['returns'];

            $policy = [
                '@type'             => 'MerchantReturnPolicy',
                'applicableCountry' => array_values($region['countries']),
            ];

            if (empty($returns['accepted'])) {
                $policy['returnPolicyCategory'] = 'https://schema.org/MerchantReturnNotPermitted';
            } else {
                $policy['returnPolicyCategory'] = 'https://schema.org/MerchantReturnFiniteReturnWindow';
                $policy['merchantReturnDays'] = (int) $returns['window_days'];
                $policy['returnMethod'] = match ($returns['method'] ?? 'mail') {
                    'store' => 'https://schema.org/ReturnInStore',
                    'both'  => ['https://schema.org/ReturnByMail', 'https://schema.org/ReturnInStore'],
                    default => 'https://schema.org/ReturnByMail',
                };
                $freeReturn = ($returns['return_shipping_paid_by'] ?? 'customer') !== 'customer'
                    && (int) ($returns['restocking_fee_percent'] ?? 0) === 0;

                $policy['returnFees'] = $freeReturn
                    ? 'https://schema.org/FreeReturn'
                    : 'https://schema.org/ReturnFeesCustomerResponsibility';
                $policy['refundType'] = ($returns['refund_method'] ?? 'original') === 'store_credit'
                    ? 'https://schema.org/StoreCreditRefund'
                    : 'https://schema.org/FullRefund';
            }

            $policies[] = $policy;
        }

        return $policies;
    }

    /**
     * @param  float|null  $price  Offer price; with $priceCurrency, applies free-shipping thresholds.
     */
    public function shippingDetails(array $settings, ?float $price = null, ?string $priceCurrency = null): array
    {
        $details = [];

        foreach (Regions::enabled($settings) as $region) {
            $shipping = $region['shipping'];

            $destinations = array_map(
                fn ($country) => ['@type' => 'DefinedRegion', 'addressCountry' => $country],
                array_values($region['countries'])
            );

            foreach ($shipping['methods'] ?? [] as $method) {
                $free = $price !== null
                    && $priceCurrency === $region['currency']
                    && isset($method['free_over']) && $method['free_over'] !== ''
                    && $price >= (float) $method['free_over'];

                $details[] = [
                    '@type'        => 'OfferShippingDetails',
                    'shippingRate' => [
                        '@type'    => 'MonetaryAmount',
                        'value'    => $free ? 0.0 : (float) $method['cost'],
                        'currency' => $region['currency'],
                    ],
                    'shippingDestination' => $destinations,
                    'deliveryTime'        => [
                        '@type'        => 'ShippingDeliveryTime',
                        'handlingTime' => $this->days($shipping['handling_min_days'], $shipping['handling_max_days']),
                        'transitTime'  => $this->days($method['min_days'], $method['max_days']),
                    ],
                ];
            }
        }

        return $details;
    }

    public function organization(array $settings): array
    {
        $business = $settings['business'];
        $address = $business['address'] ?? [];

        return array_filter([
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $business['legal_name'],
            'alternateName' => $business['trading_name'] !== $business['legal_name'] ? $business['trading_name'] : null,
            'url'       => $business['website_url'] ?? null,
            'logo'      => $business['logo_url'] ?? null,
            'email'     => $business['email'] ?? null,
            'telephone' => $business['phone'] ?? null,
            'vatID'     => $business['vat_number'] ?? null,
            'address'   => array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $address['street'] ?? null,
                'addressLocality' => $address['city'] ?? null,
                'addressRegion'   => $address['region'] ?? null,
                'postalCode'      => $address['postcode'] ?? null,
                'addressCountry'  => $address['country'] ?? null,
            ]),
            'contactPoint' => array_filter([
                '@type'       => 'ContactPoint',
                'contactType' => 'customer service',
                'email'       => $business['email'] ?? null,
                'telephone'   => $business['phone'] ?? null,
            ]),
            'hasMerchantReturnPolicy' => $this->returnPolicies($settings) ?: null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function days(int|string|null $min, int|string|null $max): array
    {
        return [
            '@type'    => 'QuantitativeValue',
            'minValue' => (int) $min,
            'maxValue' => (int) $max,
            'unitCode' => 'DAY',
        ];
    }
}
