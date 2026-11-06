@php($returns = $region['returns'])
<h3>Refunds</h3>
<p>Once we receive and inspect your return, we will email you to confirm whether your refund is approved. Approved refunds are issued {{ \Custom\StorePolicy\Support\Labels::REFUND_METHOD[$returns['refund_method']] ?? '' }} within {{ (int) $returns['refund_days'] }} days.@if ((int) ($returns['restocking_fee_percent'] ?? 0) > 0) A restocking fee of {{ (int) $returns['restocking_fee_percent'] }}% of the item price is deducted from the refund.@endif @if (($returns['return_shipping_paid_by'] ?? '') === 'customer') Original shipping charges and return shipping costs are not refunded unless the item was faulty or sent in error.@endif</p>
<p>Your bank or card issuer may take a few additional days to show the refund on your statement.</p>
