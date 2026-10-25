<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuditLogger
{
    /** @param array<string, mixed>|null $details */
    public function record(
        ?User $actor,
        string $action,
        Model $subject,
        ?string $branchId = null,
        ?array $details = null,
    ): void {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'branch_id' => $branchId,
            'details' => $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }
}
