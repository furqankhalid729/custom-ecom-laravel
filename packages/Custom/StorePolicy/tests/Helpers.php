<?php

use Custom\StorePolicy\Support\Defaults;

if (! function_exists('validSettings')) {
    /**
     * A complete, valid settings payload with one shipping method per region.
     */
    function validSettings(): array
    {
        $settings = Defaults::forChannel(core()->getDefaultChannel());

        $settings['business']['legal_name'] = 'Acme Trading Ltd';
        $settings['business']['trading_name'] = 'Acme Store';
        $settings['business']['address'] = [
            'street'   => '1 Main Street',
            'city'     => 'Dublin',
            'region'   => 'Leinster',
            'postcode' => 'D01 F5P2',
            'country'  => 'IE',
        ];
        $settings['business']['email'] = 'support@acme.test';
        $settings['business']['phone'] = '+353 1 234 5678';
        $settings['business']['hours'] = 'Mon–Fri 9:00–17:00';

        $settings['privacy']['retention'] = '6 years for order records';
        $settings['privacy']['contact_email'] = 'privacy@acme.test';

        $settings['terms']['governing_law'] = 'Ireland';

        $settings['regions']['EU']['shipping']['methods'] = [[
            'name'      => 'EU Standard',
            'carrier'   => 'DHL',
            'cost'      => 4.95,
            'free_over' => 50,
            'min_days'  => 3,
            'max_days'  => 7,
        ]];

        $settings['regions']['US']['shipping']['methods'] = [[
            'name'      => 'US Standard',
            'carrier'   => 'UPS',
            'cost'      => 7.5,
            'free_over' => null,
            'min_days'  => 5,
            'max_days'  => 10,
        ]];

        return $settings;
    }
}
