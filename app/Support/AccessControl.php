<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Permission;
use App\Domain\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AccessControl
{
    public function can(
        User $user,
        Permission|string $permission,
        ?string $branchId = null,
        ?Model $object = null,
    ): bool {
        $permission = is_string($permission) ? Permission::tryFrom($permission) : $permission;
        $role = $user->role instanceof Role ? $user->role : Role::tryFrom((string) $user->role);

        if (! $user->is_active || $permission === null || $role === null) {
            return false;
        }

        if (! $this->hasPermission($user, $role, $permission)) {
            return false;
        }

        if ($permission->requiresClinician() && ! $this->hasActiveDoctorProfile($user)) {
            return false;
        }

        if ($permission->requiresAssignment($role) && ! $this->isAssigned($user, $object, $permission)) {
            return false;
        }

        $objectBranchId = $this->objectBranchId($object);

        if ($branchId !== null && $objectBranchId !== null && (string) $objectBranchId !== $branchId) {
            return false;
        }

        $branchId ??= $objectBranchId === null ? null : (string) $objectBranchId;

        if ($branchId !== null && $role !== Role::Owner && ! $this->hasBranchAccess($user, $branchId)) {
            return false;
        }

        return ! $permission->requiresMfa() || $this->hasRecentMfa($user);
    }

    private function hasPermission(User $user, Role $role, Permission $permission): bool
    {
        $roleHasPermission = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role', $role->value)
            ->where('permissions.code', $permission->value)
            ->exists();

        if ($roleHasPermission) {
            return true;
        }

        return DB::table('user_permission_grants')
            ->join('permissions', 'permissions.id', '=', 'user_permission_grants.permission_id')
            ->where('user_permission_grants.user_id', $user->getKey())
            ->where('permissions.code', $permission->value)
            ->where(function ($query): void {
                $query->whereNull('user_permission_grants.expires_at')
                    ->orWhere('user_permission_grants.expires_at', '>', now());
            })
            ->exists();
    }

    private function hasActiveDoctorProfile(User $user): bool
    {
        return DB::table('doctor_profiles')
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->exists();
    }

    private function objectBranchId(?Model $object): ?string
    {
        if ($object === null) {
            return null;
        }

        $branchId = $object->getAttribute('branch_id');

        if ($branchId === null && method_exists($object, 'visit')) {
            $branchId = $object->visit?->branch_id;
        }

        return $branchId === null ? null : (string) $branchId;
    }

    private function isAssigned(User $user, ?Model $object, Permission $permission): bool
    {
        if ($object === null) {
            return false;
        }

        $assignedUserId = $permission === Permission::MedicalRecordAmend
            ? $object->getAttribute('signed_by')
            : $object->getAttribute('doctor_id') ?? $object->getAttribute('assigned_to');

        if ($assignedUserId === null && method_exists($object, 'visit')) {
            $assignedUserId = $object->visit?->doctor_id;
        }

        return $assignedUserId !== null && (string) $assignedUserId === (string) $user->getKey();
    }

    private function hasBranchAccess(User $user, string $branchId): bool
    {
        return DB::table('user_branch_access')
            ->where('user_id', $user->getKey())
            ->where('branch_id', $branchId)
            ->exists();
    }

    private function hasRecentMfa(User $user): bool
    {
        if (! $user->mfa_enabled) {
            return false;
        }

        $verifiedAt = session('mfa_verified_at');

        return $verifiedAt !== null
            && Carbon::parse($verifiedAt)->greaterThan(now()->subHours(12));
    }
}
