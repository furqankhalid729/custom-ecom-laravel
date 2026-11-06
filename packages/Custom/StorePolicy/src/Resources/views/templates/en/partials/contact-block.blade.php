@php
    $business = $s['business'];
    $address = array_filter([
        $business['address']['street'] ?? null,
        trim(($business['address']['city'] ?? '').' '.($business['address']['postcode'] ?? '')),
        $business['address']['region'] ?? null,
        $business['address']['country'] ?? null,
    ]);
@endphp
<h2>Contact us</h2>
<p>
    <strong>{{ $business['legal_name'] }}</strong><br>
    @foreach ($address as $line)
        {{ $line }}<br>
    @endforeach
    Email: <a href="mailto:{{ $business['email'] }}">{{ $business['email'] }}</a><br>
    Phone: {{ $business['phone'] }}
    @if (! empty($business['hours']))
        <br>Customer service hours: {{ $business['hours'] }}
    @endif
</p>
