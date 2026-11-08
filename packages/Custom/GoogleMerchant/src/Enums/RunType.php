<?php

namespace Custom\GoogleMerchant\Enums;

enum RunType: string
{
    case Bulk = 'bulk';
    case Refresh = 'refresh';
    case Reconcile = 'reconcile';
    case Statuses = 'statuses';
    case DeleteAll = 'delete_all';
}
