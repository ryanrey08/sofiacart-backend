<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AdminAuditLogger
{
    public function log(
        string $action,
        ?User $actor = null,
        ?Model $subject = null,
        ?Request $request = null,
        ?string $description = null,
        array $metadata = [],
    ): AdminAuditLog {
        return AdminAuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
