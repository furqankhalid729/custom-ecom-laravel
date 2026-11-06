@php($business = $s['business'])
<h1>Terms &amp; Conditions</h1>
<p>These terms apply when you buy from {{ $business['trading_name'] ?: $business['legal_name'] }}. Please read them before placing an order.</p>
<h2>About us</h2>
<p>This store is operated by {{ $business['legal_name'] }}.@if (! empty($business['registration_number'])) Company registration number: {{ $business['registration_number'] }}.@endif @if (! empty($business['vat_number'])) VAT number: {{ $business['vat_number'] }}.@endif</p>
<h2>Eligibility</h2>
<p>You must be at least {{ (int) ($s['terms']['minimum_age'] ?? 18) }} years old to place an order.</p>
<h2>Orders</h2>
<p>When you place an order, we will email you to confirm we have received it. A contract between you and us is formed when we email you to confirm that your order has been dispatched. We may decline an order, for example if an item is out of stock or a pricing error has occurred; if so, we will tell you and refund any payment taken.</p>
<h2>Prices and payment</h2>
<p>Prices are shown at checkout in your region's currency: {{ implode('; ', array_map(fn ($region) => $region['label'].' in '.$region['currency'], $regions)) }}. See our <a href="/page/payment-policy">Payment Policy</a> for accepted payment methods.</p>
<h2>Shipping and returns</h2>
<p>Delivery terms are set out in our <a href="/page/shipping-policy">Shipping Policy</a>. Returns and refunds are covered by our <a href="/page/return-policy">Return Policy</a> and <a href="/page/refund-policy">Refund Policy</a>.</p>
<h2>Governing law</h2>
<p>These terms are governed by the laws of {{ $s['terms']['governing_law'] }}.@if (isset($regions['EU'])) If you are a consumer living in the European Union, you also benefit from any mandatory provisions of the law of the country where you live, and you may bring proceedings in the courts of that country.@endif</p>
<h2>Complaints</h2>
<p>If you have a complaint, please contact us first and we will try to resolve it quickly.</p>
@include('store_policy::templates.en.partials.contact-block')
@include('store_policy::templates.en.partials.last-updated')
