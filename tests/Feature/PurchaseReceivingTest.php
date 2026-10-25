<?php

declare(strict_types=1);

use App\Domain\ProductCategory;
use App\Domain\ProductUnitType;
use App\Domain\Role;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\PurchaseReceivingService;
use Database\Seeders\DatabaseSeeder;
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

    $this->location = InventoryLocation::query()->create([
        'branch_id' => $this->branchId,
        'code' => 'CLINIC',
        'name' => 'Apotek Klinik',
        'type' => 'CLINIC_PHARMACY',
        'is_active' => true,
    ]);
    $this->supplier = app(PurchaseReceivingService::class)->createSupplier($this->owner, ['name' => 'Supplier Uji']);
    $this->catalog = app(ProductCatalogService::class);
    $this->purchasing = app(PurchaseReceivingService::class);
    $this->product = $this->catalog->createProduct(
        $this->owner,
        'MED-001',
        'Obat uji',
        ProductCategory::Medicine,
        ProductUnitType::Count,
        'TABLET',
    );
});

it('receives a purchase order in parts and updates stock, ledger, and weighted cost', function () {
    $order = $this->purchasing->createOrder($this->owner, $this->branchId, $this->supplier->getKey(), [[
        'product_id' => $this->product->getKey(),
        'quantity' => '10',
        'unit_cost' => '1000',
    ]]);
    $itemId = DB::table('purchase_order_items')->where('purchase_order_id', $order->getKey())->value('id');

    expect($order->purchase_number)->toBe('UTM-PO-2026-000001');
    $this->purchasing->submit($this->owner, $order->getKey());

    $firstReceipt = $this->purchasing->receive($this->owner, $order->getKey(), [[
        'purchase_order_item_id' => $itemId,
        'location_id' => $this->location->getKey(),
        'quantity' => '4',
        'unit_cost' => '1000',
        'lot_number' => 'BATCH-A',
        'expiry_date' => '2027-02-01',
    ]], '423e4567-e89b-42d3-a456-426614174101');

    expect(DB::table('purchase_orders')->where('id', $order->getKey())->value('status'))->toBe('PARTIALLY_RECEIVED')
        ->and(DB::table('stock_balances')->where('product_id', $this->product->getKey())->value('on_hand'))->toBe(4);

    $secondInput = [[
        'purchase_order_item_id' => $itemId,
        'location_id' => $this->location->getKey(),
        'quantity' => '6',
        'unit_cost' => '2000',
        'lot_number' => 'BATCH-A',
        'expiry_date' => '2027-02-01',
    ]];
    $secondReceipt = $this->purchasing->receive($this->owner, $order->getKey(), $secondInput, '423e4567-e89b-42d3-a456-426614174102');
    $replayedReceipt = $this->purchasing->receive($this->owner, $order->getKey(), $secondInput, '423e4567-e89b-42d3-a456-426614174102');

    expect(DB::table('purchase_orders')->where('id', $order->getKey())->value('status'))->toBe('RECEIVED')
        ->and(DB::table('stock_balances')->where('product_id', $this->product->getKey())->value('on_hand'))->toBe(10)
        ->and(DB::table('product_branch_costs')->where('product_id', $this->product->getKey())->value('avg_unit_cost'))->toBe(1600)
        ->and(DB::table('inventory_movements')->where('movement_type', 'PURCHASE_RECEIPT')->count())->toBe(2)
        ->and($firstReceipt->getKey())->not->toBe($secondReceipt->getKey())
        ->and($replayedReceipt->getKey())->toBe($secondReceipt->getKey());
});

it('requires valid batch details and rejects over-receipt without partial writes', function () {
    $order = $this->purchasing->createOrder($this->owner, $this->branchId, $this->supplier->getKey(), [[
        'product_id' => $this->product->getKey(),
        'quantity' => '5',
        'unit_cost' => '1000',
    ]]);
    $itemId = DB::table('purchase_order_items')->where('purchase_order_id', $order->getKey())->value('id');
    $this->purchasing->submit($this->owner, $order->getKey());

    expect(fn () => $this->purchasing->receive($this->owner, $order->getKey(), [[
        'purchase_order_item_id' => $itemId,
        'location_id' => $this->location->getKey(),
        'quantity' => '2',
        'unit_cost' => '1000',
    ]], '423e4567-e89b-42d3-a456-426614174103'))->toThrow(ValidationException::class);

    expect(fn () => $this->purchasing->receive($this->owner, $order->getKey(), [[
        'purchase_order_item_id' => $itemId,
        'location_id' => $this->location->getKey(),
        'quantity' => '6',
        'unit_cost' => '1000',
        'lot_number' => 'BATCH-B',
        'expiry_date' => '2027-02-01',
    ]], '423e4567-e89b-42d3-a456-426614174104'))->toThrow(ValidationException::class);

    expect(DB::table('goods_receipts')->count())->toBe(0)
        ->and(DB::table('stock_balances')->count())->toBe(0)
        ->and(DB::table('inventory_movements')->where('movement_type', 'PURCHASE_RECEIPT')->count())->toBe(0)
        ->and(DB::table('purchase_order_items')->where('id', $itemId)->value('quantity_received'))->toBe(0);
});

it('counts duplicate receipt rows cumulatively against the ordered quantity', function () {
    $order = $this->purchasing->createOrder($this->owner, $this->branchId, $this->supplier->getKey(), [[
        'product_id' => $this->product->getKey(),
        'quantity' => '5',
        'unit_cost' => '1000',
    ]]);
    $itemId = DB::table('purchase_order_items')->where('purchase_order_id', $order->getKey())->value('id');
    $this->purchasing->submit($this->owner, $order->getKey());

    expect(fn () => $this->purchasing->receive($this->owner, $order->getKey(), [
        [
            'purchase_order_item_id' => $itemId,
            'location_id' => $this->location->getKey(),
            'quantity' => '3',
            'unit_cost' => '1000',
            'lot_number' => 'BATCH-C',
            'expiry_date' => '2027-02-01',
        ],
        [
            'purchase_order_item_id' => $itemId,
            'location_id' => $this->location->getKey(),
            'quantity' => '3',
            'unit_cost' => '1000',
            'lot_number' => 'BATCH-D',
            'expiry_date' => '2027-03-01',
        ],
    ], '423e4567-e89b-42d3-a456-426614174105'))->toThrow(ValidationException::class);

    expect(DB::table('goods_receipts')->count())->toBe(0)
        ->and(DB::table('stock_balances')->count())->toBe(0)
        ->and(DB::table('purchase_order_items')->where('id', $itemId)->value('quantity_received'))->toBe(0);
});
