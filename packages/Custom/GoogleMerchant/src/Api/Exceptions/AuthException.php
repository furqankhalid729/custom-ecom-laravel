<?php

namespace Custom\GoogleMerchant\Api\Exceptions;

use Custom\GoogleMerchant\Enums\ErrorKind;

/** Credentials rejected or revoked: the account needs attention. */
class AuthException extends GmcApiException
{
    public function kind(): ErrorKind
    {
        return ErrorKind::Auth;
    }
}
