<?php

namespace Custom\GoogleMerchant\Enums;

enum Operation: string
{
    case Upsert = 'upsert';
    case Delete = 'delete';
    case Status = 'status';
}
