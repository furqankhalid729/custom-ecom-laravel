<?php

namespace Custom\GoogleMerchant\Sync;

final class SyncResult
{
    public function __construct(
        public readonly SyncOutcome $outcome,
        /** The product changed while this sync ran: sync it again. */
        public readonly bool $dirty = false,
    ) {}
}
