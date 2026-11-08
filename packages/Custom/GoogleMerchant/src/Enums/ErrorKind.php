<?php

namespace Custom\GoogleMerchant\Enums;

enum ErrorKind: string
{
    case Transient = 'transient';
    case Auth = 'auth';
    case Permanent = 'permanent';
    case Setup = 'setup';
    case Quota = 'quota';
}
