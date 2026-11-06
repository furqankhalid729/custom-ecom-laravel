<h1>Refund Policy</h1>
<p>This policy explains when and how {{ $s['business']['legal_name'] }} issues refunds. For how to send items back, see our <a href="/page/return-policy">Return Policy</a>.</p>
@foreach ($regions as $code => $region)
    <h2>Customers in the {{ $region['label'] }}</h2>
    @if ($code === 'EU')
        @include('store_policy::templates.en.partials.refund-eu')
    @elseif (empty($region['returns']['accepted']))
        <p>All sales are final, so refunds are not given for change of mind. If an item arrives faulty, damaged or not as described, contact us and we will repair, replace or refund it.</p>
    @else
        @include('store_policy::templates.en.partials.refund-section')
    @endif
@endforeach
@include('store_policy::templates.en.partials.contact-block')
@include('store_policy::templates.en.partials.last-updated')
