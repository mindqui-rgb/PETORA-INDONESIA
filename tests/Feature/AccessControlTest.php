<?php

use App\Domain\Permission;
use App\Domain\Role;
use App\Models\User;
use App\Support\AccessControl;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires an active permission and branch assignment', function () {
    $user = User::factory()->create();
    $user->forceFill(['role' => Role::Admin])->save();

    $permissionId = (string) Str::uuid();
    $branchId = (string) Str::uuid();

    DB::table('permissions')->insert([
        'id' => $permissionId,
        'code' => Permission::InventoryRead->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_permissions')->insert([
        'role' => Role::Admin->value,
        'permission_id' => $permissionId,
    ]);
    DB::table('branches')->insert([
        'id' => $branchId,
        'code' => 'JKT',
        'name' => 'Cabang Jakarta',
        'timezone' => 'Asia/Jakarta',
        'delivery_fee' => 0,
        'pos_enabled' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $access = app(AccessControl::class);

    expect($access->can($user, Permission::InventoryRead, $branchId))->toBeFalse();

    DB::table('user_branch_access')->insert([
        'user_id' => $user->getKey(),
        'branch_id' => $branchId,
    ]);

    expect($access->can($user, Permission::InventoryRead, $branchId))->toBeTrue();

    $user->forceFill(['is_active' => false])->save();

    expect($access->can($user, Permission::InventoryRead, $branchId))->toBeFalse();
});

it('keeps permission identifiers stable when seeding repeatedly', function () {
    $this->seed(PermissionSeeder::class);
    $permissionId = DB::table('permissions')
        ->where('code', Permission::PosSell->value)
        ->value('id');

    $this->seed(PermissionSeeder::class);

    expect(DB::table('permissions')->count())->toBe(count(Permission::cases()))
        ->and(DB::table('permissions')->where('code', Permission::PosSell->value)->value('id'))->toBe($permissionId)
        ->and(DB::table('role_permissions')->where('role', Role::Owner->value)->count())->toBe(count(Permission::cases()));
});
