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
            // audit_logs.user_id FKs the `users` (admin) table. A worker-auth
            // request authenticates against the `worker` guard, so Auth::id()
            // would be a WORKER id — not a valid users.id — and the FK would
            // blow up. Only record the id when the actor is a real admin
            // user; for worker-initiated actions leave it null (the column is
            // nullable / nullOnDelete, so this is valid and expected).
            'user_id'     => $this->adminUserId(),
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

    /**
     * The acting admin's users.id, or null if the actor isn't an admin
     * (e.g. a worker-guard request, or unauthenticated). audit_logs.user_id
     * FKs the `users` table, so we only return an id when the resolved
     * authenticated model is actually an App\Models\User — a Worker (worker
     * guard) returns null, which the nullable column accepts.
     */
    private function adminUserId(): ?int
    {
        $user = Auth::user();

        return $user instanceof \App\Models\User ? $user->getKey() : null;
    }
}
