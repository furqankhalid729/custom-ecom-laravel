<?php

namespace Custom\GoogleMerchant\Enums;

enum GoogleStatus: string
{
    case Approved = 'approved';
    case Disapproved = 'disapproved';
    case Pending = 'pending';
    case Partial = 'partial';
}
