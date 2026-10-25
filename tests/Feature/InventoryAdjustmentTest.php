<?php

declare(strict_types=1);

use App\Domain\InventoryAdjustmentReason;
use App\Domain\ProductCategory;
use App\Domain\ProductUnitType;
use App\Domain\Role;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\ProductCatalogService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $this->location = InventoryLocation::query()->create([
        'branch_id' => $this->branchId,
        'code' => 'RETAIL',
        'name' => 'Toko',
        'type' => 'RETAIL',
        'is_active' => true,
    ]);

    $this->catalog = app(ProductCatalogService::class);
    $this->inventory = app(InventoryAdjustmentService::class);
});

function makeInventoryProduct(
    ProductCatalogService $catalog,
    User $owner,
    string $sku = 'FOOD-001',
    ProductCategory $category = ProductCategory::Food,
    bool $trackLot = false,
): Product {
    return $catalog->createProduct(
        $owner,
        $sku,
        'Produk uji',
        $category,
        ProductUnitType::Weight,
        'KG',
        trackLot: $trackLot,
    );
}

it('records positive stock adjustments in the balance and ledger', function () {
    $product = makeInventoryProduct($this->catalog, $this->owner);

    $this->inventory->apply(
        $this->owner,
        $product->getKey(),
        $this->location->getKey(),
        '10.250',
        InventoryAdjustmentReason::Found,
        '2500.0000',
    );

    expect(DB::table('stock_balances')->where('product_id', $product->getKey())->value('on_hand'))->toBe(10.25)
        ->and(DB::table('inventory_movements')->where('product_id', $product->getKey())->value('quantity_delta'))->toBe(10.25)
        ->and(DB::table('product_branch_costs')->where('product_id', $product->getKey())->value('avg_unit_cost'))->toBe(2500);
});

it('removes stock by FEFO across lots without consuming reserved units', function () {
    $product = makeInventoryProduct(
        $this->catalog,
        $this->owner,
        'MED-001',
        ProductCategory::Medicine,
        true,
    );

    $this->inventory->apply(
        $this->owner,
        $product->getKey(),
        $this->location->getKey(),
        '2',
        InventoryAdjustmentReason::Found,
        '12000',
        'LOT-EARLY',
        '2026-11-20',
    );
    $this->inventory->apply(
        $this->owner,
        $product->getKey(),
        $this->location->getKey(),
        '3',
        InventoryAdjustmentReason::Found,
        '15000',
        'LOT-LATE',
        '2027-01-20',
    );

    $this->inventory->apply(
        $this->owner,
        $product->getKey(),
        $this->location->getKey(),
        '-4',
        InventoryAdjustmentReason::CountCorrection,
    );

    $balances = DB::table('stock_balances')
        ->join('inventory_lots', 'inventory_lots.id', '=', 'stock_balances.lot_id')
        ->where('stock_balances.product_id', $product->getKey())
        ->orderBy('inventory_lots.expiry_date')
        ->pluck('stock_balances.on_hand')
        ->map(fn ($quantity): string => (string) $quantity)
        ->all();

    expect($balances)->toBe(['0', '1'])
        ->and(DB::table('inventory_movements')->where('product_id', $product->getKey())->count())->toBe(4);
});

it('rolls back an adjustment when only reserved stock would cover it', function () {
    $product = makeInventoryProduct($this->catalog, $this->owner);

    $this->inventory->apply(
        $this->owner,
        $product->getKey(),
        $this->location->getKey(),
        '5',
        InventoryAdjustmentReason::Found,
        '1000',
    );
    DB::table('stock_balances')->where('product_id', $product->getKey())->update(['reserved' => 4]);

    expect(fn () => $this->inventory->apply(
        $this->owner,
        $product->getKey(),
        $this->location->getKey(),
        '-2',
        InventoryAdjustmentReason::Lost,
    ))->toThrow(ValidationException::class);

    expect(DB::table('stock_balances')->where('product_id', $product->getKey())->value('on_hand'))->toBe(5)
        ->and(DB::table('inventory_movements')->where('product_id', $product->getKey())->count())->toBe(1)
        ->and(DB::table('inventory_adjustments')->where('product_id', $product->getKey())->count())->toBe(1);
});

it('updates weighted average cost on positive adjustments', function () {
    $product = makeInventoryProduct($this->catalog, $this->owner);

    $this->inventory->apply(
        $this->owner,
        $product->getKey(),
        $this->location->getKey(),
        '10',
        InventoryAdjustmentReason::Found,
        '2000',
    );
    $this->inventory->apply(
        $this->owner,
        $product->getKey(),
        $this->location->getKey(),
        '10',
        InventoryAdjustmentReason::Found,
        '4000',
    );

    expect(DB::table('product_branch_costs')->where('product_id', $product->getKey())->value('avg_unit_cost'))->toBe(3000)
        ->and(DB::table('cost_history')->where('product_id', $product->getKey())->count())->toBe(2);
});

it('requires a note for OTHER and whole quantities for COUNT products', function () {
    expect(fn () => $this->inventory->apply(
        $this->owner,
        makeInventoryProduct($this->catalog, $this->owner)->getKey(),
        $this->location->getKey(),
        '1',
        InventoryAdjustmentReason::Other,
        '1000',
    ))->toThrow(ValidationException::class);

    $countProduct = $this->catalog->createProduct(
        $this->owner,
        'COUNT-001',
        'Produk hitung',
        ProductCategory::Accessory,
        ProductUnitType::Count,
        'PCS',
    );

    expect(fn () => $this->inventory->apply(
        $this->owner,
        $countProduct->getKey(),
        $this->location->getKey(),
        '1.5',
        InventoryAdjustmentReason::Found,
        '1000',
    ))->toThrow(ValidationException::class);
});

it('denies stock adjustments to roles without the permission', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['role' => Role::Admin])->save();
    $product = makeInventoryProduct($this->catalog, $this->owner);

    expect(fn () => $this->inventory->apply(
        $admin,
        $product->getKey(),
        $this->location->getKey(),
        '1',
        InventoryAdjustmentReason::Found,
        '1000',
    ))->toThrow(AuthorizationException::class);
});
