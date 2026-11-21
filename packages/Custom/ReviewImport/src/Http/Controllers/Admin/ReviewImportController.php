<?php

namespace Custom\ReviewImport\Http\Controllers\Admin;

use Custom\ReviewImport\Services\ReviewCsvParser;
use Custom\ReviewImport\Services\ReviewImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Admin\Http\Controllers\Controller;

class ReviewImportController extends Controller
{
    /**
     * Uploaded files wait here between the preview and the confirmed import.
     */
    protected const DIRECTORY = 'review-imports';

    public function __construct(
        protected ReviewCsvParser $parser,
        protected ReviewImporter $importer,
    ) {}

    public function index(): View
    {
        return view('review_import::admin.index');
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ReviewCsvParser::COLUMNS);
            fputcsv($out, ['SKU-123', 'Jane D.', '5', 'Love it', 'Great quality and fast delivery.', 'approved', '2026-01-15', '']);
            fputcsv($out, ['SKU-123', 'Sam K.', '4', 'Good value', 'Fits well, colour slightly darker than the photos.', 'approved', '2026-02-03', '']);

            fclose($out);
        }, 'reviews-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $this->pruneOldUploads();

        $token = (string) Str::uuid();

        $request->file('file')->storeAs(self::DIRECTORY, $token.'.csv', 'local');

        $result = $this->parser->parse($this->path($token));

        if (! $result['rows']) {
            Storage::disk('local')->delete(self::DIRECTORY.'/'.$token.'.csv');
        }

        return view('review_import::admin.preview', [
            'token' => $result['rows'] ? $token : null,
            'result' => $result,
            'filename' => $request->file('file')->getClientOriginalName(),
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'uuid'],
        ]);

        $file = self::DIRECTORY.'/'.$request->input('token').'.csv';

        if (! Storage::disk('local')->exists($file)) {
            return redirect()
                ->route('admin.customers.reviews.import.index')
                ->with('error', trans('review_import::app.errors.expired'));
        }

        $result = $this->parser->parse($this->path($request->input('token')));

        $counts = $this->importer->import($result['rows']);

        Storage::disk('local')->delete($file);

        session()->flash('success', trans('review_import::app.done', [
            'imported' => $counts['imported'],
            'duplicates' => $counts['duplicates'],
            'skipped' => count($result['errors']),
        ]));

        return redirect()->route('admin.customers.customers.review.index');
    }

    protected function path(string $token): string
    {
        return Storage::disk('local')->path(self::DIRECTORY.'/'.$token.'.csv');
    }

    /**
     * Removes previews that were never confirmed.
     */
    protected function pruneOldUploads(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
}
