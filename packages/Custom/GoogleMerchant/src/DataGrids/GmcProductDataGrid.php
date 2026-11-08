<?php

namespace Custom\GoogleMerchant\DataGrids;

use Custom\GoogleMerchant\Enums\GoogleStatus;
use Custom\GoogleMerchant\Enums\SyncState;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class GmcProductDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public const BADGES = [
        'synced'   => 'gmc-ok',
        'pending'  => 'gmc-info',
        'deleting' => 'gmc-info',
        'failed'   => 'gmc-bad',
        'invalid'  => 'gmc-warn',
        'excluded' => 'gmc-muted',
        'deleted'  => 'gmc-muted',
    ];

    public function prepareQueryBuilder()
    {
        $channel = core()->getDefaultChannelCode();
        $locale = core()->getDefaultLocaleCodeFromDefaultChannel();

        $queryBuilder = DB::table('gmc_products')
            ->join('gmc_targets', 'gmc_targets.id', '=', 'gmc_products.gmc_target_id')
            ->leftJoin('product_flat as pf', function ($join) use ($channel, $locale) {
                $join->on('pf.product_id', '=', 'gmc_products.product_id')
                    ->where('pf.channel', $channel)
                    ->where('pf.locale', $locale);
            })
            ->where('gmc_products.state', '!=', SyncState::Deleted->value)
            ->select(
                'gmc_products.id',
                'gmc_products.product_id',
                'gmc_products.offer_id',
                'gmc_products.state',
                'gmc_products.google_status',
                'gmc_products.last_synced_at',
                'gmc_products.last_error',
                'gmc_targets.feed_label',
                'pf.name as product_name',
            );

        $this->addFilter('id', 'gmc_products.id');
        $this->addFilter('offer_id', 'gmc_products.offer_id');
        $this->addFilter('state', 'gmc_products.state');
        $this->addFilter('google_status', 'gmc_products.google_status');
        $this->addFilter('feed_label', 'gmc_targets.feed_label');
        $this->addFilter('product_name', 'pf.name');
        $this->addFilter('last_synced_at', 'gmc_products.last_synced_at');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $t = fn ($key) => trans("google_merchant::app.products.datagrid.$key");

        $this->addColumn(['index' => 'id', 'label' => $t('id'), 'type' => 'integer', 'sortable' => true, 'filterable' => true]);

        $this->addColumn([
            'index'      => 'product_name',
            'label'      => $t('product'),
            'type'       => 'string',
            'searchable' => true,
            'sortable'   => true,
            'filterable' => true,
            'closure'    => fn ($row) => $row->product_name ?? trans('google_merchant::app.products.deleted-product'),
        ]);

        $this->addColumn(['index' => 'offer_id', 'label' => $t('offer-id'), 'type' => 'string', 'searchable' => true, 'sortable' => true, 'filterable' => true]);

        $this->addColumn(['index' => 'feed_label', 'label' => $t('market'), 'type' => 'string', 'sortable' => true, 'filterable' => true]);

        $this->addColumn([
            'index'              => 'state',
            'label'              => $t('state'),
            'type'               => 'string',
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => collect(SyncState::cases())->reject(fn ($s) => $s === SyncState::Deleted)
                ->map(fn ($s) => ['label' => trans("google_merchant::app.states.{$s->value}"), 'value' => $s->value])->values()->all(),
            'closure'            => fn ($row) => '<span class="gmc-badge '.(self::BADGES[$row->state] ?? 'gmc-muted').'">'.e(trans("google_merchant::app.states.{$row->state}")).'</span>',
        ]);

        $this->addColumn([
            'index'              => 'google_status',
            'label'              => $t('google-status'),
            'type'               => 'string',
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => collect(GoogleStatus::cases())
                ->map(fn ($s) => ['label' => trans("google_merchant::app.google-status.{$s->value}"), 'value' => $s->value])->all(),
            'closure'            => fn ($row) => $row->google_status
                ? e(trans("google_merchant::app.google-status.{$row->google_status}"))
                : ($row->state === 'synced' ? e(trans('google_merchant::app.google-status.awaiting')) : '—'),
        ]);

        $this->addColumn(['index' => 'last_synced_at', 'label' => $t('last-sync'), 'type' => 'datetime', 'sortable' => true, 'filterable' => true]);

        $this->addColumn([
            'index'   => 'last_error',
            'label'   => $t('problem'),
            'type'    => 'string',
            'closure' => fn ($row) => $row->last_error ? e(mb_strimwidth($row->last_error, 0, 160, '…')) : '',
        ]);
    }

    public function prepareActions()
    {
        $t = fn ($key) => trans("google_merchant::app.products.actions.$key");

        foreach ([
            ['sync', 'icon-sort-right', 'POST', 'products.sync'],
            ['retry', 'icon-reset', 'POST', 'products.retry'],
            ['exclude', 'icon-cancel', 'POST', 'products.exclude'],
            ['include', 'icon-done', 'POST', 'products.include'],
            ['remove', 'icon-delete', 'DELETE', 'products.remove'],
        ] as [$key, $icon, $method, $route]) {
            if (bouncer()->hasPermission("marketing.google_merchant.$route")) {
                $this->addAction([
                    'index'  => $key,
                    'icon'   => $icon,
                    'title'  => $t($key),
                    'method' => $method,
                    'url'    => fn ($row) => route("admin.google_merchant.$route", $row->id),
                ]);
            }
        }
    }

    public function prepareMassActions()
    {
        $t = fn ($key) => trans("google_merchant::app.products.actions.$key");

        foreach ([['mass-sync', 'products.mass_sync'], ['mass-retry', 'products.mass_retry'], ['mass-exclude', 'products.mass_exclude']] as [$key, $route]) {
            if (bouncer()->hasPermission("marketing.google_merchant.$route")) {
                $this->addMassAction([
                    'title'  => $t($key),
                    'method' => 'POST',
                    'url'    => route("admin.google_merchant.$route"),
                ]);
            }
        }
    }
}
