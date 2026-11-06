@php
    $returns = $region['returns'];
    $business = $s['business'];
@endphp
<h3>Your right to cancel (right of withdrawal)</h3>
<p>You have the right to withdraw from your purchase within {{ (int) $returns['window_days'] }} days without giving any reason. The withdrawal period expires {{ (int) $returns['window_days'] }} days after the day on which you, or a third party other than the carrier and indicated by you, take physical possession of the goods. If you ordered multiple items delivered separately, the period runs from receipt of the last item.</p>
<p>To exercise your right of withdrawal, you must tell us of your decision by a clear statement, for example by email to <a href="mailto:{{ $business['email'] }}">{{ $business['email'] }}</a>. You may use the model withdrawal form below, but it is not obligatory. To meet the deadline, it is enough to send your notice before the withdrawal period has expired.</p>
<h3>Sending items back</h3>
<p>Please send the goods back to us without undue delay and no later than 14 days from the day you tell us you are withdrawing. {{ \Custom\StorePolicy\Support\Labels::RETURN_METHOD[$returns['method']] ?? '' }} {{ \Custom\StorePolicy\Support\Labels::RETURN_SHIPPING[$returns['return_shipping_paid_by']] ?? '' }}</p>
@if (! empty($returns['conditions']))
    <p>Where possible, please return items {{ \Custom\StorePolicy\Support\Labels::conditions($returns['conditions']) }}. You are only liable for any reduced value of the goods resulting from handling beyond what is necessary to establish their nature, characteristics and functioning.</p>
@endif
@if (! empty($returns['conditions_note']))
    <p>{{ $returns['conditions_note'] }}</p>
@endif
@include('store_policy::templates.en.partials.refund-eu')
@if (! empty($returns['exchanges']))
    <p>If you would prefer an exchange instead of a refund, tell us when you contact us and we will arrange it subject to availability.</p>
@endif
<h3>Exceptions</h3>
<p>The right of withdrawal does not apply to goods made to your specifications or clearly personalised, goods that are liable to deteriorate or expire rapidly, and sealed goods that are not suitable for return for health protection or hygiene reasons once unsealed after delivery.@if (! empty($returns['non_returnable'])) Examples of goods in our store covered by these exceptions: {{ $returns['non_returnable'] }}@endif</p>
<h3>Faulty or incorrect items</h3>
<p>Your right of withdrawal does not affect your statutory rights. Under EU law you are entitled to a legal guarantee of conformity of at least two years from delivery. If an item is faulty, damaged or not as described, please contact us and we will repair or replace it, or give you a refund, at no cost to you.@if (! empty($returns['defect_resolution'])) {{ $returns['defect_resolution'] }}@endif</p>
@include('store_policy::templates.en.partials.eu-withdrawal-form')
