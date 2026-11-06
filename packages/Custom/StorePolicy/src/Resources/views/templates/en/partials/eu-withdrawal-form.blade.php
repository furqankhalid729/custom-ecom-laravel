@php
    $business = $s['business'];
    $address = implode(', ', array_filter([
        $business['address']['street'] ?? null,
        $business['address']['city'] ?? null,
        $business['address']['postcode'] ?? null,
        $business['address']['country'] ?? null,
    ]));
@endphp
<h3>Model withdrawal form</h3>
<p>(Complete and return this form only if you wish to withdraw from the contract.)</p>
<p>
    To: {{ $business['legal_name'] }}, {{ $address }}, {{ $business['email'] }}<br>
    I/We (*) hereby give notice that I/We (*) withdraw from my/our (*) contract of sale of the following goods (*):<br>
    Ordered on (*) / received on (*):<br>
    Name of consumer(s):<br>
    Address of consumer(s):<br>
    Signature of consumer(s) (only if this form is notified on paper):<br>
    Date:<br>
    (*) Delete as appropriate.
</p>
