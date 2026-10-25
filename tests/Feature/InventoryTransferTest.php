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
use App\Services\Inventory\InventoryTransferService;
use App\Services\Inventory\ProductCatalogService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->create();
    $this->owner->forceFill(['role' => Role::Owner])->save();
    $this->branchId = (string) Str::uuid();

    DB::table('branches')->insert([
        'id' => $this->branchId,
        'code' => 'UTM',
        'name' => 'Cabang Utama',
        'timezone' => 'Asia/Jakarta',
        'delivery_fee' => 0,
        'pos_enabled' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->source = InventoryLocation::query()->create([
        'branch_id' => $this->branchId,
        'code' => 'RETAIL',
        'name' => 'Toko',
        'type' => 'RETAIL',
        'is_active' => true,
    ]);
    $this->catalog = app(ProductCatalogService::class);
    $this->inventory = app(InventoryAdjustmentService::class);
    $this->transfers = app(InventoryTransferService::class);
    $this->product = $this->catalog->createProduct(
        $this->owner,
        'FOOD-001',
        'Makanan hewan',
        ProductCategory::Food,
        ProductUnitType::Count,
        'PACK',
    );
    $this->inventory->apply(
        $this->owner,
        $this->product->getKey(),
        $this->source->getKey(),
        '10',
        InventoryAdjustmentReason::Found,
        '2500',
    );
});

it('dispatches by FEFO and requires a different user to receive the transfer', function () {
    $destination = InventoryLocation::query()->create([
        'branch_id' => $this->branchId,
        'code' => 'CLINIC',
        'name' => 'Apotek Klinik',
        'type' => 'CLINIC_PHARMACY',
        'is_active' => true,
    ]);
    $transfer = $this->transfers->createDraft(
        $this->owner,
        $this->source->getKey(),
        $destination->getKey(),
        [['product_id' => $this->product->getKey(), 'quantity' => '10']],
    );

    $this->transfers->dispatch($this->owner, $transfer->getKey(), '423e4567-e89b-42d3-a456-426614174201');

    expect(DB::table('stock_balances')->where('location_id', $this->source->getKey())->value('on_hand'))->toBe(0);
    $allocationId = DB::table('inventory_transfer_lots')->where('transfer_item_id', DB::table('inventory_transfer_items')->where('transfer_id', $transfer->getKey())->value('id'))->value('id');

    expect(fn () => $this->transfers->receive($this->owner, $transfer->getKey(), [[
        'transfer_lot_id' => $allocationId,
        'quantity_received' => '10',
    ]], '423e4567-e89b-42d3-a456-426614174202'))->toThrow(AuthorizationException::class);

    $receiver = User::factory()->create();
    $receiver->forceFill(['role' => Role::Owner])->save();
    $received = $this->transfers->receive($receiver, $transfer->getKey(), [[
        'transfer_lot_id' => $allocationId,
        'quantity_received' => '8',
        'loss_reason' => 'Kemasan rusak dalam pengiriman',
    ]], '423e4567-e89b-42d3-a456-426614174203');
    $replayed = $this->transfers->receive($receiver, $transfer->getKey(), [[
        'transfer_lot_id' => $allocationId,
        'quantity_received' => '8',
        'loss_reason' => 'Kemasan rusak dalam pengiriman',
    ]], '423e4567-e89b-42d3-a456-426614174203');

    expect($received->status)->toBe('RECEIVED')
        ->and($replayed->getKey())->toBe($received->getKey())
        ->and(DB::table('stock_balances')->where('location_id', $destination->getKey())->value('on_hand'))->toBe(8)
        ->and(DB::table('inventory_transfer_items')->where('transfer_id', $transfer->getKey())->value('quantity_lost'))->toBe(2)
        ->and(DB::table('inventory_movements')->where('movement_type', 'TRANSFER_OUT')->count())->toBe(1)
        ->and(DB::table('inventory_movements')->where('movement_type', 'TRANSFER_IN')->count())->toBe(1);
});

it('requires approval before dispatching across branches', function () {
    $destinationBranchId = (string) Str::uuid();
    DB::table('branches')->insert([
        'id' => $destinationBranchId,
        'code' => 'BDG',
        'name' => 'Cabang Bandung',
        'timezone' => 'Asia/Jakarta',
        'delivery_fee' => 0,
        'pos_enabled' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $destination = InventoryLocation::query()->create([
        'branch_id' => $destinationBranchId,
        'code' => 'RETAIL',
        'name' => 'Toko Bandung',
        'type' => 'RETAIL',
        'is_active' => true,
    ]);
    $transfer = $this->transfers->createDraft(
        $this->owner,
        $this->source->getKey(),
        $destination->getKey(),
        [['product_id' => $this->product->getKey(), 'quantity' => '2']],
    );

    expect(fn () => $this->transfers->dispatch($this->owner, $transfer->getKey(), '423e4567-e89b-42d3-a456-426614174204'))
        ->toThrow(ValidationException::class);

    expect(DB::table('stock_balances')->where('location_id', $this->source->getKey())->value('on_hand'))->toBe(10);

    $this->transfers->approve($this->owner, $transfer->getKey(), 'Pemindahan stok cabang baru');
    $dispatched = $this->transfers->dispatch($this->owner, $transfer->getKey(), '423e4567-e89b-42d3-a456-426614174205');

    expect($dispatched->status)->toBe('IN_TRANSIT')
        ->and(DB::table('stock_balances')->where('location_id', $this->source->getKey())->value('on_hand'))->toBe(8);
});

it('rejects duplicate receipt allocations and rolls back all destination stock', function () {
    $destination = InventoryLocation::query()->create([
        'branch_id' => $this->branchId,
        'code' => 'CLINIC',
        'name' => 'Apotek Klinik',
        'type' => 'CLINIC_PHARMACY',
        'is_active' => true,
    ]);
    $this->catalog->createProduct(
        $this->owner,
        'MED-001',
        'Obat batch',
        ProductCategory::Medicine,
        ProductUnitType::Count,
        'TABLET',
    );
    $medicine = Product::query()->where('sku', 'MED-001')->firstOrFail();
    $this->inventory->apply($this->owner, $medicine->getKey(), $this->source->getKey(), '2', InventoryAdjustmentReason::Found, '1000', 'LOT-A', '2027-01-01');
    $this->inventory->apply($this->owner, $medicine->getKey(), $this->source->getKey(), '2', InventoryAdjustmentReason::Found, '1000', 'LOT-B', '2027-02-01');
    $transfer = $this->transfers->createDraft(
        $this->owner,
        $this->source->getKey(),
        $destination->getKey(),
        [['product_id' => $medicine->getKey(), 'quantity' => '4']],
    );
    $this->transfers->dispatch($this->owner, $transfer->getKey(), '423e4567-e89b-42d3-a456-426614174206');
    $allocations = DB::table('inventory_transfer_lots')->pluck('id')->all();
    $receiver = User::factory()->create();
    $receiver->forceFill(['role' => Role::Owner])->save();

    expect(fn () => $this->transfers->receive($receiver, $transfer->getKey(), [
        ['transfer_lot_id' => $allocations[0], 'quantity_received' => '2'],
        ['transfer_lot_id' => $allocations[0], 'quantity_received' => '2'],
    ], '423e4567-e89b-42d3-a456-426614174207'))->toThrow(ValidationException::class);

    expect(DB::table('stock_balances')->where('location_id', $destination->getKey())->count())->toBe(0)
        ->and(DB::table('inventory_transfers')->where('id', $transfer->getKey())->value('status'))->toBe('IN_TRANSIT')
        ->and(DB::table('inventory_movements')->where('movement_type', 'TRANSFER_IN')->count())->toBe(0);
});
