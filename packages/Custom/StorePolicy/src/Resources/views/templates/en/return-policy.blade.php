<h1>Return Policy</h1>
<p>This policy explains how to return items you bought from {{ $s['business']['trading_name'] ?: $s['business']['legal_name'] }}, operated by {{ $s['business']['legal_name'] }}. The terms that apply depend on where your order was delivered.</p>
@foreach ($regions as $code => $region)
    <h2>Customers in the {{ $region['label'] }}</h2>
    @if ($code === 'EU')
        @include('store_policy::templates.en.partials.returns-eu')
    @else
        @include('store_policy::templates.en.partials.returns-standard')
    @endif
@endforeach
@include('store_policy::templates.en.partials.contact-block')
@include('store_policy::templates.en.partials.last-updated')
