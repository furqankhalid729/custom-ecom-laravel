@php
    $returns = $region['returns'];
    $business = $s['business'];
@endphp
@if (empty($returns['accepted']))
    <h3>All sales are final</h3>
    <p>All sales are final. We do not accept returns or exchanges for change of mind.</p>
@else
    <h3>Return window</h3>
    <p>You can return eligible items within {{ (int) $returns['window_days'] }} days of delivery.@if (! empty($returns['conditions'])) Items must be {{ \Custom\StorePolicy\Support\Labels::conditions($returns['conditions']) }}.@endif</p>
    @if (! empty($returns['conditions_note']))
        <p>{{ $returns['conditions_note'] }}</p>
    @endif
    @if (! empty($returns['non_returnable']))
        <p>The following items cannot be returned: {{ $returns['non_returnable'] }}</p>
    @endif
    <h3>How to return an item</h3>
    <p>To start a return, email us at <a href="mailto:{{ $business['email'] }}">{{ $business['email'] }}</a> with your order number. {{ \Custom\StorePolicy\Support\Labels::RETURN_METHOD[$returns['method']] ?? '' }} {{ \Custom\StorePolicy\Support\Labels::RETURN_SHIPPING[$returns['return_shipping_paid_by']] ?? '' }}</p>
    @if (! empty($returns['exchanges']))
        <p>We offer exchanges for a different size or colour, subject to availability.</p>
    @endif
    @include('store_policy::templates.en.partials.refund-section')
@endif
<h3>Faulty or incorrect items</h3>
<p>If an item arrives faulty, damaged or not as described, please contact us @if (! empty($returns['defect_report_days'])) within {{ (int) $returns['defect_report_days'] }} days of delivery @endif and we will make it right at no cost to you.@if (! empty($returns['defect_resolution'])) {{ $returns['defect_resolution'] }}@endif</p>
