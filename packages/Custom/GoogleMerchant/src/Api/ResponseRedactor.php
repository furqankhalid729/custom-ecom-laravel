<?php

namespace Custom\GoogleMerchant\Api;

/**
 * Makes API response bodies safe to store in sync logs.
 */
class ResponseRedactor
{
    public const MAX_LENGTH = 2048;

    public function redact(?string $body): string
    {
        $body = (string) $body;

        $body = preg_replace(
            '/("(?:access_token|refresh_token|id_token|private_key|client_secret|assertion)"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
            '$1"[redacted]"',
            $body
        );

        $body = preg_replace('/Bearer\s+[A-Za-z0-9._\-~+\/]+=*/i', 'Bearer [redacted]', $body);

        $body = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted]', $body);

        return mb_strcut($body, 0, self::MAX_LENGTH);
    }
}
