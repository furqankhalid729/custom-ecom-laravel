@php
    use Custom\StorePolicy\Support\Labels;
    use Custom\StorePolicy\Support\Money;
    use Custom\StorePolicy\Support\Regions as RegionList;
@endphp
<h1>Shipping Policy</h1>
<p>This policy explains where {{ $s['business']['trading_name'] ?: $s['business']['legal_name'] }} ships, what it costs and how long delivery takes.</p>
@foreach ($regions as $code => $region)
    @php($shipping = $region['shipping'])
    <h2>Customers in the {{ $region['label'] }}</h2>
    <p>We ship to {{ RegionList::countriesText($code, $region['countries']) }}. Prices are shown in {{ $region['currency'] }}.</p>
    <h3>Order processing</h3>
    <p>Orders are processed within {{ Labels::days($shipping['handling_min_days'], $shipping['handling_max_days']) }}, Monday to Friday, excluding public holidays.@if (! empty($shipping['cutoff_time'])) Orders placed before {{ $shipping['cutoff_time'] }} on a business day begin processing the same day.@endif</p>
    <h3>Shipping methods, costs and delivery times</h3>
    <ul>
        @foreach ($shipping['methods'] as $method)
            <li><strong>{{ $method['name'] }}</strong>@if (! empty($method['carrier'])) ({{ $method['carrier'] }})@endif: {{ Money::format($method['cost'], $region['currency']) }}@if (isset($method['free_over']) && $method['free_over'] !== '' && $method['free_over'] !== null), free on orders over {{ Money::format($method['free_over'], $region['currency']) }}@endif. Delivery in {{ Labels::days($method['min_days'], $method['max_days']) }} after dispatch.</li>
        @endforeach
    </ul>
    <p>Total delivery time is the processing time plus the delivery time of the method you choose.@if (! empty($shipping['tracking'])) You will receive a tracking number by email when your order ships.@endif</p>
    <p>@if (! empty($shipping['po_boxes']))We deliver to P.O. boxes.@else We do not deliver to P.O. boxes.@endif @if (! empty($shipping['excluded_areas'])) We do not currently ship to: {{ $shipping['excluded_areas'] }}.@endif</p>
    <h3>Lost or damaged parcels</h3>
    <p>{{ $shipping['lost_damaged_note'] ?: 'If your parcel arrives damaged or has not arrived within the expected delivery time, contact us and we will help, including sending a replacement or a refund where appropriate.' }}</p>
@endforeach
@include('store_policy::templates.en.partials.contact-block')
@include('store_policy::templates.en.partials.last-updated')
