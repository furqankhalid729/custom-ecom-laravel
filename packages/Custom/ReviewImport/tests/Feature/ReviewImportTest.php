<?php

use Custom\ReviewImport\Services\ReviewCsvParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Product\Models\ProductReview;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

function reviewCsv(array $rows, string $delimiter = ','): UploadedFile
{
    $lines = array_map(
        fn ($row) => implode($delimiter, array_map(fn ($cell) => '"'.str_replace('"', '""', (string) $cell).'"', $row)),
        $rows
    );

    return UploadedFile::fake()->createWithContent('reviews.csv', implode("\n", $lines));
}

function previewToken($response): string
{
    preg_match('/name="token" value="([0-9a-f-]{36})"/', $response->getContent(), $match);

    return $match[1] ?? '';
}

beforeEach(function () {
    Storage::fake('local');

    $this->product = (new ProductFaker)->getSimpleProductFactory()->create();
});

it('shows the import page and serves the template', function () {
    $this->loginAsAdmin();

    get(route('admin.customers.reviews.import.index'))
        ->assertOk()
        ->assertSeeText(trans('review_import::app.index.title'));

    $template = get(route('admin.customers.reviews.import.template'))->assertOk();

    expect($template->streamedContent())->toContain(implode(',', ReviewCsvParser::COLUMNS));
});

it('previews without saving, then imports valid rows and skips bad ones', function () {
    $this->loginAsAdmin();

    $sku = $this->product->sku;

    $file = reviewCsv([
        ReviewCsvParser::COLUMNS,
        [$sku, 'Jane D.', '5', 'Love it', 'Great quality.', '', '2026-01-15', ''],
        [$sku, 'Sam K.', '4', 'Good', 'Fits well.', 'pending', '15/02/2026', ''],
        ['NO-SUCH-SKU', 'Ann', '5', '', '', '', '', ''],
        [$sku, 'Bob', '9', '', '', '', '', ''],
    ]);

    $preview = post(route('admin.customers.reviews.import.preview'), ['file' => $file])->assertOk();

    $preview->assertSeeText('NO-SUCH-SKU');
    expect(ProductReview::count())->toBe(0);

    Event::fake(['customer.review.update.after']);

    post(route('admin.customers.reviews.import.store'), ['token' => previewToken($preview)])
        ->assertRedirect(route('admin.customers.customers.review.index'));

    $reviews = ProductReview::orderBy('id')->get();

    expect($reviews)->toHaveCount(2)
        ->and($reviews[0]->name)->toBe('Jane D.')
        ->and($reviews[0]->status)->toBe('approved')
        ->and($reviews[0]->created_at->toDateString())->toBe('2026-01-15')
        ->and($reviews[1]->status)->toBe('pending')
        ->and($reviews[1]->created_at->toDateString())->toBe('2026-02-15');

    Event::assertDispatched('customer.review.update.after');
});

it('does not duplicate reviews when the same file is imported twice', function () {
    $this->loginAsAdmin();

    $rows = [
        ReviewCsvParser::COLUMNS,
        [$this->product->sku, 'Jane D.', '5', 'Love it', 'Great quality.', '', '', ''],
        [$this->product->sku, 'Jane D.', '5', 'Love it', 'Great quality.', '', '', ''],
    ];

    foreach ([1, 2] as $run) {
        $preview = post(route('admin.customers.reviews.import.preview'), ['file' => reviewCsv($rows)]);

        post(route('admin.customers.reviews.import.store'), ['token' => previewToken($preview)]);
    }

    expect(ProductReview::count())->toBe(1);
});

it('accepts semicolon separated files with a byte order mark', function () {
    $this->loginAsAdmin();

    $content = "\xEF\xBB\xBFsku;name;rating\n{$this->product->sku};Jane;3\n";

    $preview = post(route('admin.customers.reviews.import.preview'), [
        'file' => UploadedFile::fake()->createWithContent('reviews.csv', $content),
    ]);

    post(route('admin.customers.reviews.import.store'), ['token' => previewToken($preview)]);

    expect(ProductReview::first())->rating->toBe(3)->name->toBe('Jane');
});

it('rejects a file without the required columns', function () {
    $this->loginAsAdmin();

    $preview = post(route('admin.customers.reviews.import.preview'), [
        'file' => reviewCsv([['product', 'stars'], ['x', '5']]),
    ])->assertOk();

    $preview->assertSeeText('sku, name, rating');
    expect(previewToken($preview))->toBe('');
});

it('refuses an import with an unknown token', function () {
    $this->loginAsAdmin();

    post(route('admin.customers.reviews.import.store'), ['token' => '9f1c2b7e-1111-4222-8333-444455556666'])
        ->assertRedirect(route('admin.customers.reviews.import.index'));

    expect(ProductReview::count())->toBe(0);
});
