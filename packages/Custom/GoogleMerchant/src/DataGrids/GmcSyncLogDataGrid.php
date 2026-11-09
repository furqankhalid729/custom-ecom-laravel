<?php

namespace Custom\GoogleMerchant\DataGrids;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class GmcSyncLogDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    protected $sortColumn = 'id';

    protected $sortOrder = 'desc';

    protected const BADGES = ['success' => 'gmc-ok', 'error' => 'gmc-bad', 'skipped' => 'gmc-muted'];

    public function prepareQueryBuilder()
    {
        $channel = core()->getDefaultChannelCode();
        $locale = core()->getDefaultLocaleCodeFromDefaultChannel();

        $queryBuilder = DB::table('gmc_sync_logs')
            ->leftJoin('product_flat as pf', function ($join) use ($channel, $locale) {
                $join->on('pf.product_id', '=', 'gmc_sync_logs.product_id')
                    ->where('pf.channel', $channel)
                    ->where('pf.locale', $locale);
            })
            ->select(
                'gmc_sync_logs.id',
                'gmc_sync_logs.created_at',
                'gmc_sync_logs.product_id',
                'gmc_sync_logs.offer_id',
                'gmc_sync_logs.operation',
                'gmc_sync_logs.status',
                'gmc_sync_logs.http_status',
                'gmc_sync_logs.error_message',
                'gmc_sync_logs.attempt',
                'gmc_sync_logs.duration_ms',
                'pf.name as product_name',
            );

        foreach (['id', 'created_at', 'product_id', 'offer_id', 'operation', 'status', 'http_status'] as $column) {
            $this->addFilter($column, "gmc_sync_logs.$column");
        }

        $this->addFilter('product_name', 'pf.name');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $t = fn ($key) => trans("google_merchant::app.logs.datagrid.$key");

        $this->addColumn(['index' => 'id', 'label' => $t('id'), 'type' => 'integer', 'sortable' => true, 'filterable' => true]);
        $this->addColumn(['index' => 'created_at', 'label' => $t('time'), 'type' => 'datetime', 'sortable' => true, 'filterable' => true]);
        $this->addColumn(['index' => 'product_id', 'label' => $t('product-id'), 'type' => 'integer', 'sortable' => true, 'filterable' => true]);
        $this->addColumn([
            'index'      => 'product_name',
            'label'      => $t('product'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'closure'    => fn ($row) => e($row->product_name ?? '—'),
        ]);
        $this->addColumn(['index' => 'offer_id', 'label' => $t('offer-id'), 'type' => 'string', 'searchable' => true, 'filterable' => true, 'sortable' => true]);
        $this->addColumn([
            'index'              => 'operation',
            'label'              => $t('operation'),
            'type'               => 'string',
            'filterable'         => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => collect(['upsert', 'delete', 'status'])->map(fn ($o) => ['label' => trans("google_merchant::app.logs.operation.$o"), 'value' => $o])->all(),
            'closure'            => fn ($row) => e(trans("google_merchant::app.logs.operation.{$row->operation}")),
        ]);
        $this->addColumn([
            'index'              => 'status',
            'label'              => $t('status'),
            'type'               => 'string',
            'sortable'           => true,
            'filterable'         => true,
            'filterable_type'    => 'dropdown',
            'filterable_options' => collect(['success', 'error', 'skipped'])->map(fn ($s) => ['label' => trans("google_merchant::app.logs.status.$s"), 'value' => $s])->all(),
            'closure'            => fn ($row) => '<span class="gmc-badge '.(self::BADGES[$row->status] ?? 'gmc-muted').'">'.e(trans("google_merchant::app.logs.status.{$row->status}")).'</span>',
        ]);
        $this->addColumn(['index' => 'http_status', 'label' => $t('http'), 'type' => 'integer', 'filterable' => true, 'sortable' => true]);
        $this->addColumn([
            'index'   => 'error_message',
            'label'   => $t('message'),
            'type'    => 'string',
            'closure' => fn ($row) => e(mb_strimwidth((string) $row->error_message, 0, 140, '…')),
        ]);
        $this->addColumn(['index' => 'attempt', 'label' => $t('attempt'), 'type' => 'integer', 'sortable' => true]);
        $this->addColumn(['index' => 'duration_ms', 'label' => $t('duration'), 'type' => 'integer', 'sortable' => true]);
    }

    public function prepareActions()
    {
        if (bouncer()->hasPermission('marketing.google_merchant.logs.show')) {
            $this->addAction([
                'index'  => 'view',
                'icon'   => 'icon-view',
                'title'  => trans('google_merchant::app.logs.view'),
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.google_merchant.logs.show', $row->id),
            ]);
        }
    }
}
