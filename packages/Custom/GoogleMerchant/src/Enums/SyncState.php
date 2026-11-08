<?php

namespace Custom\GoogleMerchant\Enums;

enum SyncState: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
    case Invalid = 'invalid';
    case Excluded = 'excluded';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
}
