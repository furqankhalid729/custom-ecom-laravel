<?php

namespace Custom\GoogleMerchant\Support;

class ProductInputName
{
    /**
     * accounts/{id}/productInputs/{unpadded base64url of contentLanguage~feedLabel~offerId}
     */
    public static function encode(string $merchantId, string $contentLanguage, string $feedLabel, string $offerId): string
    {
        $id = rtrim(strtr(base64_encode("{$contentLanguage}~{$feedLabel}~{$offerId}"), '+/', '-_'), '=');

        return "accounts/{$merchantId}/productInputs/{$id}";
    }
}
