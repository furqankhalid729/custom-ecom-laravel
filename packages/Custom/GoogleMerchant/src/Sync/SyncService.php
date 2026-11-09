<?php

namespace Custom\GoogleMerchant\Sync;

use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Api\Exceptions\GmcApiException;
use Custom\GoogleMerchant\Api\Exceptions\NotFoundException;
use Custom\GoogleMerchant\Api\Exceptions\PermanentApiException;
use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Api\Exceptions\UnregisteredProjectException;
use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Enums\ErrorKind;
use Custom\GoogleMerchant\Enums\Operation;
use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Mapping\MappedOffer;
use Custom\GoogleMerchant\Mapping\ProductMapper;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Models\GmcTargetProxy;
use Custom\GoogleMerchant\Repositories\GmcProductRepository;
use Custom\GoogleMerchant\Support\ProductInputName;
use Custom\GoogleMerchant\Validation\OfferValidator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductProxy;

/**
 * Brings Google in line with one store "root" product (simple, bundle or
 * configurable parent) for one target. Everything the job does lives here.
 */
class SyncService
{
    /** Changes limited to these fields are sent as a partial update (productInputs.patch). */
    public const PATCHABLE_FIELDS = ['availability', 'price', 'salePrice', 'salePriceEffectiveDate'];

    public function __construct(
        protected GmcProductRepository $ledger,
        protected ProductMapper $mapper,
        protected OfferValidator $validator,
        protected Eligibility $eligibility,
        protected MerchantApiClient $client,
        protected SyncLogger $logger,
    ) {}

    /**
     * @throws TransientApiException|AuthException when the job should back off and retry
     */
    public function syncRoot(int $rootProductId, int $targetId, bool $force = false, ?int $runId = null): SyncResult
    {
        $target = GmcTargetProxy::modelClass()::with('account')->findOrFail($targetId);

        if (! $target->account?->isConnected()) {
            throw new AuthException('The Merchant Center account is not connected.');
        }

        return $this->locked($targetId, $rootProductId, fn () => $this->run($rootProductId, $target, $force, $runId));
    }

    /**
     * Removes one offer that was marked `deleting`. A row reclaimed by another
     * product in the meantime is left alone.
     */
    public function deleteOffer(int $gmcProductId, ?int $runId = null): SyncOutcome
    {
        $row = $this->ledger->query()->with('target.account')->find($gmcProductId);

        if (! $row) {
            return SyncOutcome::Skipped;
        }

        return $this->locked($row->gmc_target_id, $row->root_product_id ?? 0, function () use ($row, $runId) {
            $row->refresh();

            if ($row->state !== SyncState::Deleting->value) {
                return SyncOutcome::Skipped;
            }

            $this->removeFromGoogle($row, $row->target, SyncState::Deleted, null, $runId);

            return SyncOutcome::Deleted;
        });
    }

    protected function run(int $rootId, GmcTarget $target, bool $force, ?int $runId): SyncResult
    {
        $startRevision = $this->ledger->maxRevision($rootId, $target->id);

        $root = ProductProxy::modelClass()::with(['attribute_values', 'images'])->find($rootId);

        if (! $root) {
            foreach ($this->ledger->forRoot($rootId, $target->id) as $row) {
                $this->removeFromGoogle($row, $target, SyncState::Deleted, null, $runId);
            }

            return $this->finish($rootId, $target, $startRevision, SyncOutcome::Deleted);
        }

        $reason = $this->eligibility->reason($root, $target);

        $mapped = $reason ? [] : $this->mapper->map($root, $target);
        $eligible = $reason ? [] : $this->eligibility->eligibleOffers($mapped, $target);

        $reason ??= $this->eligibility->stockReason($eligible);

        if ($reason) {
            foreach ($this->ledger->forRoot($rootId, $target->id) as $row) {
                $this->removeFromGoogle($row, $target, SyncState::Excluded, $reason, $runId);
            }

            return $this->finish($rootId, $target, $startRevision, SyncOutcome::Excluded);
        }

        $keep = array_map(fn (MappedOffer $o) => $o->offerId, $eligible);
        $dropped = array_diff(array_map(fn (MappedOffer $o) => $o->offerId, $mapped), $keep);

        // Deletes first (removed variant, old SKU, disabled variant), then inserts, all under this root's lock.
        foreach ($this->ledger->forRoot($rootId, $target->id) as $row) {
            if (! in_array($row->offer_id, $keep, true)) {
                $dropReason = in_array($row->offer_id, $dropped, true) ? 'variant_excluded' : null;

                $this->removeFromGoogle($row, $target, $dropReason ? SyncState::Excluded : SyncState::Deleted, $dropReason, $runId);
            }
        }

        $outcome = SyncOutcome::Skipped;

        foreach ($eligible as $offer) {
            $result = $this->upsert($offer, $target, $force, $runId);

            if ($result->severity() > $outcome->severity()) {
                $outcome = $result;
            }
        }

        return $this->finish($rootId, $target, $startRevision, $outcome);
    }

    protected function upsert(MappedOffer $offer, GmcTarget $target, bool $force, ?int $runId): SyncOutcome
    {
        $row = $this->ledger->claim($target->id, $offer->offerId, $offer->productId, $offer->rootProductId, $target->channel_id);

        if ($row instanceof IdentityConflict) {
            $conflict = $this->ledger->query()->where('gmc_target_id', $target->id)->where('offer_id', $offer->offerId)->first();

            $this->logger->log($conflict, Operation::Upsert, 'error', message: trans('google_merchant::app.validation.identity-conflict', [
                'sku' => $offer->offerId, 'id' => $row->ownerProductId,
            ]));

            return SyncOutcome::Invalid;
        }

        $errors = $this->validator->validate($offer, $target);

        if ($errors) {
            // An offer Google still shows would keep stale data (e.g. an old price): take it down until fixed.
            if ($row->last_synced_at && $row->product_input_name) {
                $this->removeFromGoogle($row, $target, SyncState::Invalid, null, $runId);
            }

            $row->forceFill([
                'state'             => SyncState::Invalid->value,
                'payload_hash'      => $offer->hash, // lets the nightly refresh skip unchanged invalid offers
                'validation_errors' => $errors,
                'last_error'        => implode(' ', $errors),
                'error_kind'        => null,
                'last_attempt_at'   => now(),
            ])->save();

            $this->logger->log($row, Operation::Upsert, 'error', runId: $runId, message: implode(' ', $errors));

            return SyncOutcome::Invalid;
        }

        $fresh = $row->state === SyncState::Synced->value
            && $row->payload_hash === $offer->hash
            && $row->last_synced_at?->greaterThan(now()->subDays((int) config('google_merchant.resubmit_days', 25)));

        if (! $force && $fresh) {
            $this->logger->log($row, Operation::Upsert, 'skipped', runId: $runId);

            return SyncOutcome::Skipped;
        }

        $started = hrtime(true);

        if (! $force && ($fields = $this->patchableChanges($row, $offer))) {
            $patched = $this->patch($row, $offer, $target, $fields, $runId);

            if ($patched !== null) {
                return $patched;
            }
            // Google no longer has the offer: fall through to a full insert.
        }

        try {
            $row->forceFill(['last_attempt_at' => now(), 'attempts' => $row->attempts + 1])->save();

            $this->client->insertProductInput($target->account, $target->data_source_name, $offer->productInput);
        } catch (PermanentApiException|NotFoundException|UnregisteredProjectException $e) {
            $this->fail($row, $e, $this->duration($started), $runId);

            return SyncOutcome::Failed;
        } catch (GmcApiException $e) {
            $row->forceFill(['error_kind' => $e->kind()->value, 'last_error' => $e->getMessage()])->save();

            $this->logger->log($row, Operation::Upsert, 'error', $e, $this->duration($started), $runId);

            throw $e;
        }

        $row->forceFill([
            'state'              => SyncState::Synced->value,
            'payload_hash'       => $offer->hash,
            'field_hashes'       => $offer->fieldHashes,
            'product_input_name' => ProductInputName::encode($target->account->merchant_id, $target->content_language, $target->feed_label, $offer->offerId),
            'last_synced_at'     => now(),
            'attempts'           => 0,
            'retry_cycles'       => 0,
            'last_error'         => null,
            'error_kind'         => null,
            'validation_errors'  => null,
        ])->save();

        $this->logger->log($row, Operation::Upsert, 'success', durationMs: $this->duration($started), runId: $runId);

        return SyncOutcome::Synced;
    }

    /**
     * Price/stock-only change on an offer Google has (and that is not due for a full resubmit).
     *
     * @return string[] changed fields, or [] when a full insert is needed
     */
    protected function patchableChanges(GmcProduct $row, MappedOffer $offer): array
    {
        if ($row->state !== SyncState::Synced->value || ! $row->field_hashes || ! $row->product_input_name
            || ! $row->last_synced_at?->greaterThan(now()->subDays((int) config('google_merchant.resubmit_days', 25)))) {
            return [];
        }

        $changed = $offer->changedFields($row->field_hashes);

        if (! $changed || array_diff($changed, self::PATCHABLE_FIELDS)) {
            return [];
        }

        return array_values(array_intersect(self::PATCHABLE_FIELDS, $changed));
    }

    /**
     * Sends only the changed fields. Fields in the mask but absent from the body
     * (e.g. an ended sale price) are removed by Google. Returns null on 404 so
     * the caller re-inserts the whole offer.
     */
    protected function patch(GmcProduct $row, MappedOffer $offer, GmcTarget $target, array $fields, ?int $runId): ?SyncOutcome
    {
        $attributes = array_intersect_key($offer->attributes(), array_flip($fields));

        $body = [
            'offerId'           => $offer->offerId,
            'contentLanguage'   => $offer->productInput['contentLanguage'],
            'feedLabel'         => $offer->productInput['feedLabel'],
            'productAttributes' => $attributes ?: new \stdClass,
        ];

        $started = hrtime(true);

        try {
            $row->forceFill(['last_attempt_at' => now(), 'attempts' => $row->attempts + 1])->save();

            $this->client->patchProductInput(
                $target->account,
                $row->product_input_name,
                $target->data_source_name,
                $body,
                array_map(fn ($f) => "productAttributes.$f", $fields),
            );
        } catch (NotFoundException) {
            return null;
        } catch (PermanentApiException|UnregisteredProjectException $e) {
            $this->fail($row, $e, $this->duration($started), $runId);

            return SyncOutcome::Failed;
        } catch (GmcApiException $e) {
            $row->forceFill(['error_kind' => $e->kind()->value, 'last_error' => $e->getMessage()])->save();

            $this->logger->log($row, Operation::Upsert, 'error', $e, $this->duration($started), $runId);

            throw $e;
        }

        // last_synced_at stays at the last full insert: that is what keeps the offer from expiring.
        $row->forceFill([
            'payload_hash'      => $offer->hash,
            'field_hashes'      => $offer->fieldHashes,
            'attempts'          => 0,
            'retry_cycles'      => 0,
            'last_error'        => null,
            'error_kind'        => null,
            'validation_errors' => null,
        ])->save();

        $this->logger->log($row, Operation::Upsert, 'success', durationMs: $this->duration($started), runId: $runId, message: 'partial update: '.implode(', ', $fields));

        return SyncOutcome::Synced;
    }

    /**
     * Deletes the offer in Google when it was ever sent (using the stored name), then sets the final state.
     */
    protected function removeFromGoogle(GmcProduct $row, GmcTarget $target, SyncState $final, ?string $reason, ?int $runId): void
    {
        if (in_array($row->state, [SyncState::Deleted->value, SyncState::Excluded->value]) && ! $row->last_synced_at) {
            $row->forceFill(['state' => $final->value, 'last_error' => $reason])->save();

            return;
        }

        if ($row->product_input_name && $row->last_synced_at) {
            $started = hrtime(true);

            try {
                $row->forceFill(['last_attempt_at' => now(), 'attempts' => $row->attempts + 1])->save();

                $this->client->deleteProductInput($target->account, $row->product_input_name, $target->data_source_name);
            } catch (NotFoundException) {
                // Already gone in Google.
            } catch (PermanentApiException $e) {
                $this->fail($row, $e, $this->duration($started), $runId, Operation::Delete);

                return;
            } catch (GmcApiException $e) {
                $row->forceFill(['error_kind' => $e->kind()->value, 'last_error' => $e->getMessage()])->save();

                $this->logger->log($row, Operation::Delete, 'error', $e, $this->duration($started), $runId);

                throw $e;
            }

            $this->logger->log($row, Operation::Delete, 'success', durationMs: $this->duration($started), runId: $runId, message: $reason);
        }

        $row->forceFill([
            'state'          => $final->value,
            'last_error'     => $reason,
            'error_kind'     => null,
            'attempts'       => 0,
            'last_synced_at' => null,
            'payload_hash'   => null,
        ])->save();
    }

    protected function fail(GmcProduct $row, GmcApiException $e, int $durationMs, ?int $runId, Operation $operation = Operation::Upsert): void
    {
        $fields = $e instanceof PermanentApiException && $e->fieldViolations
            ? ' ('.collect($e->fieldViolations)->map(fn ($m, $f) => "$f: $m")->implode('; ').')'
            : '';

        $row->forceFill([
            'state'      => SyncState::Failed->value,
            'error_kind' => $e->kind()->value,
            'last_error' => $e->getMessage().$fields,
        ])->save();

        $this->logger->log($row, $operation, 'error', $e, $durationMs, $runId);
    }

    protected function finish(int $rootId, GmcTarget $target, int $startRevision, SyncOutcome $outcome): SyncResult
    {
        $this->ledger->query()
            ->where('root_product_id', $rootId)
            ->where('gmc_target_id', $target->id)
            ->where('synced_revision', '<', $startRevision)
            ->update(['synced_revision' => $startRevision]);

        return new SyncResult($outcome, $this->ledger->maxRevision($rootId, $target->id) > $startRevision);
    }

    protected function locked(int $targetId, int $rootId, callable $callback): mixed
    {
        try {
            return Cache::lock("gmc:sync:{$targetId}:{$rootId}", 120)->block(30, $callback);
        } catch (LockTimeoutException $e) {
            throw new TransientApiException('Another sync of this product is still running.', null, null, 30, $e);
        }
    }

    protected function duration(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
