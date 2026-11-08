<?php

namespace Custom\GoogleMerchant\Api\Exceptions;

use Custom\GoogleMerchant\Enums\ErrorKind;

/**
 * Base for every classified Merchant API failure.
 */
abstract class GmcApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $excerpt = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    abstract public function kind(): ErrorKind;
}
