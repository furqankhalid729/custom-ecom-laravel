<?php

namespace Custom\GoogleMerchant\Jobs\Concerns;

/**
 * Package jobs always use their own connection/queue so the store's global
 * QUEUE_CONNECTION (often `sync`) never runs Google calls inside a request.
 */
trait RunsOnGmcQueue
{
    protected function useGmcQueue(): void
    {
        $this->onConnection(config('google_merchant.queue.connection', 'database'));
        $this->onQueue(config('google_merchant.queue.name', 'gmc'));
    }
}
