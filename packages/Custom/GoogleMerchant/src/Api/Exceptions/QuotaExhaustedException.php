<?php

namespace Custom\GoogleMerchant\Api\Exceptions;

use Custom\GoogleMerchant\Enums\ErrorKind;

/** Daily quota used up: retry after a long pause. */
class QuotaExhaustedException extends TransientApiException
{
    public function kind(): ErrorKind
    {
        return ErrorKind::Quota;
    }
}
