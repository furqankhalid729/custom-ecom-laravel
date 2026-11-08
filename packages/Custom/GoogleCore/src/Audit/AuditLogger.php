<?php

namespace Custom\GoogleCore\Audit;

use Custom\GoogleCore\Models\AuditLogProxy;

class AuditLogger
{
    /** Context keys containing any of these fragments are never stored. */
    protected const SECRET_FRAGMENTS = ['token', 'secret', 'json', 'password', 'private_key'];

    public function record(string $integration, string $action, array $context = []): void
    {
        $clean = array_filter(
            $context,
            fn ($value, $key) => ! $this->isSecretKey((string) $key),
            ARRAY_FILTER_USE_BOTH
        );

        AuditLogProxy::modelClass()::create([
            'integration' => $integration,
            'action'      => $action,
            'admin_id'    => auth()->guard('admin')->id(),
            'context'     => $clean ?: null,
            'ip'          => request()?->ip(),
        ]);
    }

    protected function isSecretKey(string $key): bool
    {
        foreach (self::SECRET_FRAGMENTS as $fragment) {
            if (str_contains(strtolower($key), $fragment)) {
                return true;
            }
        }

        return false;
    }
}
