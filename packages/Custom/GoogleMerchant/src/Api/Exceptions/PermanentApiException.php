<?php

namespace Custom\GoogleMerchant\Api\Exceptions;

use Custom\GoogleMerchant\Enums\ErrorKind;

/** Google rejected the data (400 etc.); retrying the same payload will fail again. */
class PermanentApiException extends GmcApiException
{
    /**
     * @param  array<string, string>  $fieldViolations  field => Google's description
     */
    public function __construct(
        string $message,
        ?int $httpStatus = null,
        ?string $excerpt = null,
        public readonly array $fieldViolations = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $excerpt, $previous);
    }

    public function kind(): ErrorKind
    {
        return ErrorKind::Permanent;
    }
}
