<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    public function log(
        string $action,
        string $entityType,
        ?int $entityId,
        array $oldValues = [],
        array $newValues = [],
        ?Request $request = null
    ): void {
        AuditLog::create([
            'user_id'     => Auth::id(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => !empty($oldValues) ? $oldValues : null,
            'new_values'  => !empty($newValues) ? $newValues : null,
            'ip_address'  => $request?->ip(),
            'user_agent'  => $request?->userAgent(),
            'created_at'  => now(),
        ]);
    }
}
