{{-- Rendered after the product edit form (bagisto.admin.catalog.product.edit.after). --}}
@php
    $rows = \Custom\GoogleMerchant\Models\GmcProductProxy::modelClass()::query()
        ->where(fn ($q) => $q->where('product_id', $product->id)->orWhere('root_product_id', $product->id))
        ->where('state', '!=', 'deleted')
        ->orderBy('offer_id')
        ->limit(25)
        ->get();

    $badges = \Custom\GoogleMerchant\DataGrids\GmcProductDataGrid::BADGES;
@endphp

@include('google_merchant::admin.partials.styles')

<div v-pre class="box-shadow mt-4 rounded bg-white p-4 dark:bg-gray-900">
    <div class="mb-3 flex items-center justify-between gap-4">
        <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('google_merchant::app.panel.title')</p>

        @if (bouncer()->hasPermission('marketing.google_merchant.products.product_sync'))
            <form method="POST" action="{{ route('admin.google_merchant.product.sync', $product->id) }}">
                @csrf
                <button type="submit" class="secondary-button">@lang('google_merchant::app.panel.sync-now')</button>
            </form>
        @endif
    </div>

    @if ($rows->isEmpty())
        <p class="text-sm text-gray-600 dark:text-gray-300">@lang('google_merchant::app.panel.never-synced')</p>
    @else
        <table class="w-full text-left text-sm text-gray-700 dark:text-gray-300">
            <thead>
                <tr class="border-b dark:border-gray-800">
                    <th class="py-2 pr-3">@lang('google_merchant::app.products.datagrid.offer-id')</th>
                    <th class="py-2 pr-3">@lang('google_merchant::app.products.datagrid.state')</th>
                    <th class="py-2 pr-3">@lang('google_merchant::app.products.datagrid.google-status')</th>
                    <th class="py-2">@lang('google_merchant::app.products.datagrid.problem')</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b align-top dark:border-gray-800">
                        <td class="py-2 pr-3">{{ $row->offer_id }}</td>
                        <td class="py-2 pr-3"><span class="gmc-badge {{ $badges[$row->state] ?? 'gmc-muted' }}">@lang("google_merchant::app.states.{$row->state}")</span></td>
                        <td class="py-2 pr-3">
                            {{ $row->google_status ? trans("google_merchant::app.google-status.{$row->google_status}") : ($row->state === 'synced' ? trans('google_merchant::app.google-status.awaiting') : '—') }}
                        </td>
                        <td class="py-2">
                            @if ($row->validation_errors)
                                <ul class="list-disc pl-4">
                                    @foreach (array_slice($row->validation_errors, 0, 3) as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @elseif ($row->last_error)
                                {{ $row->last_error }}
                            @endif

                            @foreach (array_slice($row->google_issues ?? [], 0, 3) as $issue)
                                <p class="mt-1">{{ $issue['description'] ?? '' }}</p>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
