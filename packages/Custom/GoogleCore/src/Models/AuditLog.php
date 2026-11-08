<?php

namespace Custom\GoogleCore\Models;

use Custom\GoogleCore\Contracts\AuditLog as AuditLogContract;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model implements AuditLogContract
{
    protected $table = 'google_audit_logs';

    const UPDATED_AT = null;

    protected $fillable = ['integration', 'action', 'admin_id', 'context', 'ip'];

    protected $casts = ['context' => 'array'];
}
