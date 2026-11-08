<?php

namespace Custom\GoogleMerchant\Sync;

enum SyncOutcome: string
{
    case Synced = 'synced';
    case Skipped = 'skipped';
    case Invalid = 'invalid';
    case Excluded = 'excluded';
    case Failed = 'failed';
    case Deleted = 'deleted';

    /** Higher = worse; a root's result is its worst offer. */
    public function severity(): int
    {
        return match ($this) {
            self::Skipped  => 0,
            self::Deleted  => 1,
            self::Excluded => 1,
            self::Synced   => 2,
            self::Invalid  => 3,
            self::Failed   => 4,
        };
    }
}
