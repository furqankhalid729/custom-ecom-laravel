<?php

namespace Custom\GoogleMerchant\Enums;

enum RunStatus: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
}
