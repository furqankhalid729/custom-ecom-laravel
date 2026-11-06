@php
    $business = $s['business'];
    $privacy = $s['privacy'];
    $privacyEmail = $privacy['contact_email'] ?: $business['email'];
@endphp
<h1>Privacy Policy</h1>
<p>This policy explains how {{ $business['legal_name'] }} ("we", "us") collects, uses and protects your personal information when you visit or buy from {{ $business['website_url'] ?? 'our website' }}. We are responsible for your personal information as the data controller.</p>
<h2>Information we collect</h2>
<ul>
    @foreach ($privacy['data_collected'] ?? [] as $item)
        <li>{{ $item }}</li>
    @endforeach
</ul>
<h2>How we use your information</h2>
<ul>
    <li>To process, ship and support your orders</li>
    <li>To communicate with you about your orders and requests</li>
    <li>To prevent fraud and keep our store secure</li>
    <li>To meet our legal and accounting obligations</li>
    <li>To send marketing emails, only where you have agreed to receive them</li>
</ul>
<h2>Who we share your information with</h2>
<p>We share personal information only as needed to run our store, with:</p>
<ul>
    @foreach ($privacy['third_parties'] ?? [] as $party)
        <li>{{ $party }}</li>
    @endforeach
</ul>
@if (empty($privacy['sells_or_shares']))
    <p>We do not sell your personal information.</p>
@else
    <p>We share some personal information, such as device identifiers and browsing activity, with advertising partners to show you relevant ads. You can opt out of this at any time by emailing <a href="mailto:{{ $privacyEmail }}">{{ $privacyEmail }}</a>.</p>
@endif
@if (! empty($privacy['uses_cookies']))
    <h2>Cookies</h2>
    <p>We use cookies to keep our store working, remember your preferences and understand how the site is used. You can manage non-essential cookies through the cookie banner and your browser settings.</p>
@endif
<h2>How long we keep your information</h2>
<p>{{ $privacy['retention'] ?: 'We keep personal information only for as long as needed for the purposes above, including to meet legal, tax and accounting requirements.' }}</p>
@if (isset($regions['EU']))
    <h2>If you are in the European Union (GDPR)</h2>
    <p>We process your personal information on these legal bases:</p>
    <ul>
        <li>Performance of a contract: to process and deliver your orders</li>
        <li>Legal obligation: to keep tax and accounting records</li>
        <li>Legitimate interests: to prevent fraud and improve our store</li>
        <li>Consent: for marketing emails and non-essential cookies; you can withdraw consent at any time</li>
    </ul>
    <p>You have the right to:</p>
    <ul>
        <li>Access the personal information we hold about you</li>
        <li>Have inaccurate information corrected (rectification)</li>
        <li>Have your information deleted (erasure)</li>
        <li>Restrict how we process your information</li>
        <li>Receive your information in a portable format (data portability)</li>
        <li>Object to processing based on legitimate interests or for direct marketing</li>
    </ul>
    <p>To exercise these rights, email <a href="mailto:{{ $privacyEmail }}">{{ $privacyEmail }}</a>. You also have the right to lodge a complaint with the data protection supervisory authority in the EU country where you live or work.</p>
    <p>Where your information is transferred outside the European Economic Area, we rely on appropriate safeguards such as the European Commission's Standard Contractual Clauses or an adequacy decision.</p>
    @if (! empty($privacy['eu_representative']))
        <p>Our representative or data protection officer in the EU: {{ $privacy['eu_representative'] }}</p>
    @endif
@endif
@if (isset($regions['US']))
    <h2>Your California privacy rights</h2>
    <p>If you are a California resident, the California Consumer Privacy Act (as amended by the CPRA) gives you the right to:</p>
    <ul>
        <li>Know what personal information we collect, use and disclose</li>
        <li>Request deletion of your personal information</li>
        <li>Correct inaccurate personal information</li>
        <li>Opt out of the sale or sharing of your personal information</li>
        <li>Not be discriminated against for exercising these rights</li>
    </ul>
    @if (empty($privacy['sells_or_shares']))
        <p>We do not sell or share your personal information for cross-context behavioural advertising.</p>
    @else
        <p>We share personal information with advertising partners for cross-context behavioural advertising, which California law may treat as "selling" or "sharing". You can opt out of the sale or sharing of your personal information by emailing <a href="mailto:{{ $privacyEmail }}">{{ $privacyEmail }}</a>; we also honour Global Privacy Control signals.</p>
    @endif
    <p>To make a request, email <a href="mailto:{{ $privacyEmail }}">{{ $privacyEmail }}</a>. We will verify your identity before responding.</p>
    <h3>Do Not Track</h3>
    <p>Our website does not currently respond to Do Not Track browser signals.</p>
@endif
<h2>Contact about privacy</h2>
<p>For any privacy question or request, email <a href="mailto:{{ $privacyEmail }}">{{ $privacyEmail }}</a>.</p>
@include('store_policy::templates.en.partials.contact-block')
@include('store_policy::templates.en.partials.last-updated')
