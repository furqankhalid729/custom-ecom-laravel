@php($business = $s['business'])
<h1>About {{ $business['trading_name'] ?: $business['legal_name'] }}</h1>
@if (! empty($business['logo_url']))
    <p><img src="{{ $business['logo_url'] }}" alt="{{ $business['trading_name'] ?: $business['legal_name'] }}"></p>
@endif
<p>{{ $business['trading_name'] ?: $business['legal_name'] }} is an online store operated by {{ $business['legal_name'] }}.@if (! empty($business['registration_number'])) Company registration number: {{ $business['registration_number'] }}.@endif @if (! empty($business['vat_number'])) VAT number: {{ $business['vat_number'] }}.@endif</p>
<p>We ship to customers in {{ implode(' and ', array_map(fn ($region) => 'the '.$region['label'], $regions)) ?: 'our selling regions' }}. Our <a href="/page/shipping-policy">Shipping Policy</a>, <a href="/page/return-policy">Return Policy</a> and <a href="/page/privacy-policy">Privacy Policy</a> explain how we work.</p>
@include('store_policy::templates.en.partials.contact-block')
