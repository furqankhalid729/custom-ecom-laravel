<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Repositories\GmcProductRepository;
use Custom\GoogleMerchant\Support\ProductInputName;
use Custom\GoogleMerchant\Sync\IdentityConflict;
use Custom\GoogleMerchant\Sync\SyncOutcome;
use Custom\GoogleMerchant\Sync\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());
    $this->sync = fn (int $rootId, bool $force = false) => app(SyncService::class)->syncRoot($rootId, $this->target->id, $force);
    $this->row = fn (string $offerId) => GmcProduct::where('gmc_target_id', $this->target->id)->where('offer_id', $offerId)->first();

    $this->fakeGoogle = function (int $insertStatus = 200, int $deleteStatus = 200, string $insertFixture = 'productinput-insert') {
        Http::fake([
            'merchantapi.googleapis.com/products/v1/*:insert*' => Http::response($insertStatus === 200 ? fixture('productinput-insert') : fixture($insertFixture), $insertStatus),
            'merchantapi.googleapis.com/products/v1/*'          => Http::response($deleteStatus === 200 ? '' : fixture('error-404'), $deleteStatus),
        ]);
    };
});

function inserts(): array
{
    return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), ':insert'))->values()->all();
}

function deletes(): array
{
    return collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'DELETE')->values()->all();
}

it('inserts a new product and marks synced', function () {
    ($this->fakeGoogle)();
    $product = simpleProduct();

    $result = ($this->sync)($product->id);

    $row = ($this->row)($product->sku);

    expect($result->outcome)->toBe(SyncOutcome::Synced)
        ->and(inserts())->toHaveCount(1)
        ->and($row->state)->toBe('synced')
        ->and($row->payload_hash)->toHaveLength(40)
        ->and($row->product_input_name)->toBe(ProductInputName::encode('123456', 'en', 'US', $product->sku))
        ->and($row->last_synced_at)->not->toBeNull()
        ->and(DB::table('gmc_sync_logs')->where('gmc_product_id', $row->id)->where('status', 'success')->count())->toBe(1);
});

it('skips an unchanged product', function () {
    ($this->fakeGoogle)();
    $product = simpleProduct();

    ($this->sync)($product->id);
    $result = ($this->sync)($product->id);

    expect($result->outcome)->toBe(SyncOutcome::Skipped)
        ->and(inserts())->toHaveCount(1)
        ->and(DB::table('gmc_sync_logs')->where('offer_id', $product->sku)->where('status', 'skipped')->count())->toBe(1);
});

it('resubmits after resubmit_days even if unchanged', function () {
    ($this->fakeGoogle)();
    $product = simpleProduct();

    ($this->sync)($product->id);

    ($this->row)($product->sku)->forceFill(['last_synced_at' => now()->subDays(26)])->save();

    ($this->sync)($product->id);

    expect(inserts())->toHaveCount(2);
});

it('forces a resubmit when asked', function () {
    ($this->fakeGoogle)();
    $product = simpleProduct();

    ($this->sync)($product->id);
    ($this->sync)($product->id, true);

    expect(inserts())->toHaveCount(2);
});

it('does not call google for invalid offers', function () {
    Http::fake();
    $product = simpleProduct();

    DB::table('product_images')->where('product_id', $product->id)->delete();

    $result = ($this->sync)($product->id);

    $row = ($this->row)($product->sku);

    expect($result->outcome)->toBe(SyncOutcome::Invalid)
        ->and($row->state)->toBe('invalid')
        ->and($row->validation_errors)->toHaveKey('imageLink')
        ->and($row->last_error)->toContain(trans('google_merchant::app.validation.image-missing'));

    Http::assertNothingSent();
});

it('removes a previously synced offer from google when it becomes invalid', function () {
    ($this->fakeGoogle)();
    $product = simpleProduct();

    ($this->sync)($product->id);

    setProductAttribute($product, 'price', 0);

    expect(($this->sync)($product->id)->outcome)->toBe(SyncOutcome::Invalid)
        ->and(deletes())->toHaveCount(1)
        ->and(($this->row)($product->sku)->state)->toBe('invalid')
        ->and(($this->row)($product->sku)->last_synced_at)->toBeNull();

    // Fixed again → inserted again.
    setProductAttribute($product, 'price', 21);

    expect(($this->sync)($product->id)->outcome)->toBe(SyncOutcome::Synced)
        ->and(inserts())->toHaveCount(2);
});

it('records permanent errors as failed with the field message', function () {
    ($this->fakeGoogle)(400, 200, 'error-400-invalid-argument');
    $product = simpleProduct();

    $result = ($this->sync)($product->id);

    $row = ($this->row)($product->sku);

    expect($result->outcome)->toBe(SyncOutcome::Failed)
        ->and($row->state)->toBe('failed')
        ->and($row->error_kind)->toBe('permanent')
        ->and($row->last_error)->toContain('Invalid GTIN value.');
});

it('rethrows transient errors and keeps the row pending', function () {
    Http::fake(['merchantapi.googleapis.com/*' => Http::response(fixture('error-503'), 503)]);
    $product = simpleProduct();

    try {
        ($this->sync)($product->id);
        $this->fail('expected TransientApiException');
    } catch (TransientApiException) {
        $row = ($this->row)($product->sku);

        expect($row->state)->toBe('pending')
            ->and($row->attempts)->toBe(1)
            ->and($row->error_kind)->toBe('transient');
    }
});

it('deletes offers no longer produced', function () {
    ($this->fakeGoogle)();
    $parent = configurableProduct(3);

    ($this->sync)($parent->id);

    $removed = $parent->variants()->orderBy('id')->first();
    DB::table('products')->where('id', $removed->id)->delete();

    ($this->sync)($parent->id);

    $row = ($this->row)($removed->sku);

    expect(deletes())->toHaveCount(1)
        ->and(deletes()[0][0]->url())->toContain('/productInputs/'.explode('/', $row->product_input_name)[3].'?')
        ->and($row->state)->toBe('deleted');
});

it('deletes from google when the product becomes excluded', function () {
    ($this->fakeGoogle)();
    $product = simpleProduct();

    ($this->sync)($product->id);

    setProductAttribute($product, 'gmc_sync', 0);

    $result = ($this->sync)($product->id);

    expect($result->outcome)->toBe(SyncOutcome::Excluded)
        ->and(deletes())->toHaveCount(1)
        ->and(($this->row)($product->sku)->state)->toBe('excluded');
});

it('does not call google to exclude a product that was never sent', function () {
    Http::fake();
    $product = simpleProduct(['gmc_sync' => 0]);

    expect(($this->sync)($product->id)->outcome)->toBe(SyncOutcome::Excluded);

    Http::assertNothingSent();
});

it('treats 404 on delete as success', function () {
    ($this->fakeGoogle)(200, 404);
    $product = simpleProduct();

    ($this->sync)($product->id);

    $row = ($this->row)($product->sku);
    $row->forceFill(['state' => 'deleting'])->save();

    expect(app(SyncService::class)->deleteOffer($row->id))->toBe(SyncOutcome::Deleted)
        ->and($row->fresh()->state)->toBe('deleted');
});

it('changes sku with delete before insert', function () {
    ($this->fakeGoogle)();
    $product = simpleProduct();
    $oldSku = $product->sku;

    ($this->sync)($product->id);

    DB::table('products')->where('id', $product->id)->update(['sku' => 'renamed-'.$product->id]);

    ($this->sync)($product->id);

    $calls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->method().' '.(str_contains($pair[0]->url(), ':insert') ? 'insert' : 'delete'))->all();

    expect($calls)->toBe(['POST insert', 'DELETE delete', 'POST insert'])
        ->and(($this->row)($oldSku)->state)->toBe('deleted')
        ->and(($this->row)('renamed-'.$product->id)->state)->toBe('synced');
});

it('deletes using the stored product input name', function () {
    ($this->fakeGoogle)();
    $product = simpleProduct();

    ($this->sync)($product->id);

    $stored = ($this->row)($product->sku)->product_input_name;

    $this->target->forceFill(['feed_label' => 'XX'])->save();
    setProductAttribute($product, 'gmc_sync', 0);

    ($this->sync)($product->id);

    $id = substr($stored, strrpos($stored, '/') + 1);

    expect(deletes())->toHaveCount(1)
        ->and(deletes()[0][0]->url())->toContain('/productInputs/'.$id.'?');
});

it('lets a new product reclaim a deleted sku and voids the stale delete', function () {
    ($this->fakeGoogle)();
    $old = simpleProduct();
    $sku = $old->sku;

    ($this->sync)($old->id);

    $row = ($this->row)($sku);
    $row->forceFill(['state' => 'deleting'])->save();      // what the delete listener does
    DB::table('products')->where('id', $old->id)->delete();

    $new = simpleProduct();
    DB::table('products')->where('id', $new->id)->update(['sku' => $sku]);

    ($this->sync)($new->id);

    expect(app(SyncService::class)->deleteOffer($row->id))->toBe(SyncOutcome::Skipped);

    $row->refresh();

    expect(inserts())->toHaveCount(2)
        ->and(deletes())->toHaveCount(0)
        ->and($row->product_id)->toBe($new->id)
        ->and($row->root_product_id)->toBe($new->id)
        ->and($row->state)->toBe('synced');
});

it('reclaims an offer whose owner no longer uses that sku', function () {
    ($this->fakeGoogle)();
    $owner = simpleProduct();
    $sku = $owner->sku;

    ($this->sync)($owner->id);

    DB::table('products')->where('id', $owner->id)->update(['sku' => 'moved-'.$owner->id]);

    $claimer = simpleProduct();
    DB::table('products')->where('id', $claimer->id)->update(['sku' => $sku]);

    ($this->sync)($claimer->id);

    expect(($this->row)($sku)->product_id)->toBe($claimer->id);

    // The former owner's next sync must not delete the offer the claimer now owns.
    ($this->sync)($owner->id);

    expect(collect(deletes())->filter(fn ($pair) => str_contains($pair[0]->url(), explode('/', ($this->row)($sku)->product_input_name)[3])))->toBeEmpty();
});

it('refuses an identity owned by another live product with that sku', function () {
    $owner = simpleProduct();

    GmcProduct::create(['gmc_target_id' => $this->target->id, 'offer_id' => $owner->sku, 'product_id' => $owner->id, 'root_product_id' => $owner->id, 'state' => 'synced']);

    $other = simpleProduct();

    $claim = app(GmcProductRepository::class)->claim($this->target->id, $owner->sku, $other->id, $other->id, $this->target->channel_id);

    expect($claim)->toBeInstanceOf(IdentityConflict::class)
        ->and($claim->ownerProductId)->toBe($owner->id);
});

it('reports dirty when the revision moves during sync', function () {
    $product = simpleProduct();

    app(GmcProductRepository::class)->markPending($product->id, $this->target->id);

    $bumped = false;

    // An admin saves the product while the first insert is in flight.
    Http::fake(function () use ($product, &$bumped) {
        if (! $bumped) {
            $bumped = true;
            DB::table('gmc_products')->where('root_product_id', $product->id)->increment('revision');
        }

        return Http::response(fixture('productinput-insert'));
    });

    expect(($this->sync)($product->id)->dirty)->toBeTrue()
        ->and(($this->sync)($product->id, true)->dirty)->toBeFalse();
});

it('marks pending rows and bumps the revision', function () {
    $parent = configurableProduct(2);

    $repo = app(GmcProductRepository::class);

    $repo->markPending($parent->id, $this->target->id);
    $repo->markPending($parent->id, $this->target->id);

    $rows = GmcProduct::where('root_product_id', $parent->id)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('state')->unique()->all())->toBe(['pending'])
        ->and($rows->pluck('revision')->unique()->all())->toBe([2]);
});
