@inject('merchantJsonLd', 'Custom\StorePolicy\Seo\MerchantJsonLd')
@php($storePolicySettings = $merchantJsonLd->currentSettings())
@if ($storePolicySettings)
    <script type="application/ld+json">{!! json_encode($merchantJsonLd->organization($storePolicySettings), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endif
