<?php

namespace Custom\GoogleMerchant\Enums;

enum AccountStatus: string
{
    case Connected = 'connected';
    case NeedsReauth = 'needs_reauth';
    case Disconnected = 'disconnected';
}
