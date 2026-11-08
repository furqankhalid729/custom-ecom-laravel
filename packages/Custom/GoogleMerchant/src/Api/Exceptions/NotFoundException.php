<?php

namespace Custom\GoogleMerchant\Api\Exceptions;

use Custom\GoogleMerchant\Enums\ErrorKind;

class NotFoundException extends GmcApiException
{
    public function kind(): ErrorKind
    {
        return ErrorKind::Permanent;
    }
}
