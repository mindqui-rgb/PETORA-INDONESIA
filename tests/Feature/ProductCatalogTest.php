<?php

declare(strict_types=1);

use App\Domain\ProductCategory;
use App\Domain\ProductUnitType;
use App\Domain\Role;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->owner = User::factory()->create();
    $this->owner->forceFill(['role' => Role::Owner])->save();

    $this->branchId = (string) Str::uuid();
    DB::table('branches')->insert([
        'id' => $this->branchId,
        'code' => 'JKT',
        'name' => 'Cabang Jakarta',
        'timezone' => 'Asia/Jakarta',
        'delivery_fee' => 0,
        'pos_enabled' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->catalog = app(ProductCatalogService::class);
});

it('automatically enables lot tracking for medicine', function () {
    $product = $this->catalog->createProduct(
        $this->owner,
        'MED-001',
        'Obat A',
        ProductCategory::Medicine,
        ProductUnitType::Count,
        'TABLET',
    );

    expect($product->track_lot)->toBeTrue();
});

it('prevents prescription products from being sold at a branch', function () {
    $product = $this->catalog->createProduct(
        $this->owner,
        'RX-001',
        'Obat resep',
        ProductCategory::Medicine,
        ProductUnitType::Count,
        'TABLET',
        isPrescription: true,
    );

    expect(fn () => $this->catalog->configureBranch(
        $this->owner,
        $product->getKey(),
        $this->branchId,
        true,
        false,
    ))->toThrow(ValidationException::class);

    expect(DB::table('product_branch_settings')->count())->toBe(0);
});

it('requires an effective price before activating branch sales', function () {
    $product = $this->catalog->createProduct(
        $this->owner,
        'FOOD-002',
        'Makanan kucing',
        ProductCategory::Food,
        ProductUnitType::Weight,
        'KG',
    );

    expect(fn () => $this->catalog->configureBranch(
        $this->owner,
        $product->getKey(),
        $this->branchId,
        true,
        false,
    ))->toThrow(ValidationException::class);

    expect(DB::table('product_branch_settings')->count())->toBe(0);
});

it('appends branch prices and resolves the latest effective price', function () {
    $product = $this->catalog->createProduct(
        $this->owner,
        'FOOD-001',
        'Makanan hewan',
        ProductCategory::Food,
        ProductUnitType::Weight,
        'KG',
    );

    $this->catalog->setBranchPrice(
        $this->owner,
        $product->getKey(),
        $this->branchId,
        '25000',
        Carbon::parse('2026-01-01 00:00:00'),
    );
    $this->catalog->setBranchPrice(
        $this->owner,
        $product->getKey(),
        $this->branchId,
        '27500',
        Carbon::parse('2026-02-01 00:00:00'),
    );

    expect(DB::table('product_prices')->where('product_id', $product->getKey())->count())->toBe(2)
        ->and($this->catalog->activePrice($product->getKey(), $this->branchId, Carbon::parse('2026-03-01'))->price)->toBe('27500.00');
});

it('rejects a non-owner price change', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['role' => Role::Admin])->save();

    $product = $this->catalog->createProduct(
        $this->owner,
        'ACC-001',
        'Kalung',
        ProductCategory::Accessory,
        ProductUnitType::Count,
        'PCS',
    );

    expect(fn () => $this->catalog->setBranchPrice(
        $admin,
        $product->getKey(),
        $this->branchId,
        '12000',
    ))->toThrow(AuthorizationException::class);
});

it('rejects fractional rupiah prices', function () {
    $product = $this->catalog->createProduct(
        $this->owner,
        'ACC-002',
        'Mainan',
        ProductCategory::Accessory,
        ProductUnitType::Count,
        'PCS',
    );

    expect(fn () => $this->catalog->setBranchPrice(
        $this->owner,
        $product->getKey(),
        $this->branchId,
        '1250.50',
    ))->toThrow(ValidationException::class);
});
