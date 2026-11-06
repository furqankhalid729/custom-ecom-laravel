<h1>Payment Policy</h1>
<p>This policy explains how you can pay when you buy from {{ $s['business']['trading_name'] ?: $s['business']['legal_name'] }}.</p>
<h2>Accepted payment methods</h2>
@if (! empty($s['payment']['methods']))
    <ul>
        @foreach ($s['payment']['methods'] as $method)
            <li>{{ $method }}</li>
        @endforeach
    </ul>
@else
    <p>The available payment methods are shown at checkout.</p>
@endif
<h2>When you are charged</h2>
<p>{{ \Custom\StorePolicy\Support\Labels::CHARGED_AT[$s['payment']['charged_at'] ?? 'order'] ?? '' }}</p>
<h2>Currency</h2>
<ul>
    @foreach ($regions as $code => $region)
        <li>Customers in the {{ $region['label'] }} pay in {{ $region['currency'] }}.</li>
    @endforeach
</ul>
<p>Any taxes and duties that apply to your order are shown at checkout before you pay. Your bank may charge a fee if your card is in a different currency.</p>
<h2>Payment security</h2>
<p>Payments are processed securely by our payment providers. We do not store your full card details.</p>
@include('store_policy::templates.en.partials.contact-block')
@include('store_policy::templates.en.partials.last-updated')
