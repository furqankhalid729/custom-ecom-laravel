<?php

namespace Custom\GoogleMerchant\Api\Exceptions;

use Custom\GoogleMerchant\Enums\ErrorKind;

/** Rate limit, 5xx, timeout: retry later. */
class TransientApiException extends GmcApiException
{
    public function __construct(
        string $message,
        ?int $httpStatus = null,
        ?string $excerpt = null,
        public readonly int $retryAfter = 60,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $excerpt, $previous);
    }

    public function kind(): ErrorKind
    {
        return ErrorKind::Transient;
    }
}
