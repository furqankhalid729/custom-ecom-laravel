<?php

namespace Custom\GoogleMerchant\Api\Exceptions;

use Custom\GoogleMerchant\Enums\ErrorKind;

/** The Google Cloud project is not registered with this Merchant Center account. */
class UnregisteredProjectException extends AuthException
{
    public function kind(): ErrorKind
    {
        return ErrorKind::Setup;
    }
}
