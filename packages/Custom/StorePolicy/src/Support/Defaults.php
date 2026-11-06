<?php

namespace Custom\StorePolicy\Support;

use Webkul\Core\Contracts\Channel;
use Webkul\Payment\Payment;

class Defaults
{
    public static function forChannel(Channel $channel): array
    {
        return [
            'business' => [
                'legal_name'          => $channel->name,
                'trading_name'        => $channel->name,
                'logo_url'            => $channel->logo ? $channel->logo_url() : null,
                'address'             => ['street' => null, 'city' => null, 'region' => null, 'postcode' => null, 'country' => null],
                'email'               => null,
                'phone'               => null,
                'hours'               => null,
                'registration_number' => null,
                'vat_number'          => null,
                'website_url'         => self::websiteUrl($channel),
            ],
            'payment' => [
                'methods'    => self::paymentMethodTitles(),
                'charged_at' => 'order',
            ],
            'privacy' => [
                'data_collected'    => ['Name', 'Email address', 'Phone number', 'Billing and shipping address', 'Order history', 'Device and usage data'],
                'third_parties'     => ['Payment processors', 'Shipping carriers', 'Analytics providers'],
                'uses_cookies'      => (bool) core()->getConfigData('general.gdpr.cookie.enabled'),
                'sells_or_shares'   => false,
                'retention'         => null,
                'contact_email'     => null,
                'eu_representative' => null,
            ],
            'terms' => [
                'governing_law'  => null,
                'minimum_age'    => 18,
                'effective_date' => null,
            ],
            'regions' => [
                'EU' => self::region('EU', windowDays: 14, refundDays: 14),
                'US' => self::region('US', windowDays: 30, refundDays: 10),
            ],
        ];
    }

    protected static function region(string $code, int $windowDays, int $refundDays): array
    {
        $definition = Regions::definitions()[$code];

        return [
            'enabled'   => true,
            'countries' => $definition['countries'],
            'currency'  => $definition['currency'],
            'returns'   => [
                'accepted'                => true,
                'window_days'             => $windowDays,
                'conditions'              => ['unused', 'original_packaging'],
                'conditions_note'         => null,
                'non_returnable'          => null,
                'method'                  => 'mail',
                'return_shipping_paid_by' => 'customer',
                'restocking_fee_percent'  => 0,
                'refund_method'           => 'original',
                'refund_days'             => $refundDays,
                'exchanges'               => false,
                'defect_report_days'      => 30,
                'defect_resolution'       => null,
            ],
            'shipping' => [
                'handling_min_days' => 1,
                'handling_max_days' => 3,
                'cutoff_time'       => null,
                'methods'           => [],
                'tracking'          => true,
                'po_boxes'          => false,
                'excluded_areas'    => null,
                'lost_damaged_note' => null,
            ],
        ];
    }

    protected static function websiteUrl(Channel $channel): string
    {
        if (! $channel->hostname) {
            return config('app.url');
        }

        return str_starts_with($channel->hostname, 'http') ? $channel->hostname : 'https://'.$channel->hostname;
    }

    protected static function paymentMethodTitles(): array
    {
        try {
            return array_values(array_map(
                fn ($method) => $method['method_title'],
                app(Payment::class)->getPaymentMethods()
            ));
        } catch (\Throwable) {
            return [];
        }
    }
}
