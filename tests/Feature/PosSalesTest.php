<?php

declare(strict_types=1);

use App\Domain\InventoryAdjustmentReason;
use App\Domain\ProductCategory;
use App\Domain\ProductUnitType;
use App\Domain\Role;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Pos\CashShiftService;
use App\Services\Pos\PosSalesService;
use App\Support\Errors\DomainException;
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

    $this->location = InventoryLocation::query()->create([
        'branch_id' => $this->branchId,
        'code' => 'RETAIL',
        'name' => 'Toko',
        'type' => 'RETAIL',
        'is_active' => true,
    ]);
    DB::table('branch_payment_methods')->insert([
        'branch_id' => $this->branchId,
        'method_code' => 'CASH',
        'is_active' => true,
    ]);

    $this->catalog = app(ProductCatalogService::class);
    $this->inventory = app(InventoryAdjustmentService::class);
    $this->shifts = app(CashShiftService::class);
    $this->pos = app(PosSalesService::class);

    $this->product = $this->catalog->createProduct(
        $this->owner,
        'FOOD-001',
        'Makanan hewan',
        ProductCategory::Food,
        ProductUnitType::Count,
        'PACK',
    );
    $this->catalog->setBranchPrice($this->owner, $this->product->getKey(), $this->branchId, '5000');
    $this->catalog->configureBranch($this->owner, $this->product->getKey(), $this->branchId, true, false);
    $this->inventory->apply(
        $this->owner,
        $this->product->getKey(),
        $this->location->getKey(),
        '10',
        InventoryAdjustmentReason::Found,
        '2500',
    );
    $this->shift = $this->shifts->open($this->owner, $this->branchId, '100000');
});

it('completes a cash sale with change, stock ledger, invoice, and idempotent replay', function () {
    $sale = $this->pos->startDraft($this->owner, $this->branchId);
    $this->pos->addItem($this->owner, $sale->getKey(), $this->product->getKey(), '2');

    $completed = $this->pos->complete($this->owner, $sale->getKey(), [[
        'method_code' => 'CASH',
        'amount' => '10000',
        'tendered_amount' => '20000',
    ]], '423e4567-e89b-42d3-a456-426614174001');

    expect($completed->status)->toBe('COMPLETED')
        ->and($completed->invoice_number)->toBe('UTM-POS-2026-000001')
        ->and($completed->grand_total)->toBe('10000.00')
        ->and(DB::table('stock_balances')->where('product_id', $this->product->getKey())->value('on_hand'))->toBe(8)
        ->and(DB::table('sale_payments')->where('sale_id', $sale->getKey())->value('change_amount'))->toBe(10000)
        ->and(DB::table('cash_movements')->where('movement_type', 'CASH_IN_SALE')->value('amount'))->toBe(10000)
        ->and(DB::table('cash_movements')->where('movement_type', 'CASH_OUT_CHANGE')->value('amount'))->toBe(10000)
        ->and(DB::table('inventory_movements')->where('movement_type', 'SALE_POS')->count())->toBe(1);

    $replayed = $this->pos->complete($this->owner, $sale->getKey(), [[
        'method_code' => 'CASH',
        'amount' => '10000',
        'tendered_amount' => '20000',
    ]], '423e4567-e89b-42d3-a456-426614174001');

    expect($replayed->getKey())->toBe($completed->getKey())
        ->and(DB::table('cash_movements')->where('movement_type', 'CASH_IN_SALE')->count())->toBe(1)
        ->and(DB::table('invoice_sequences')->where('document_type', 'POS')->value('last_number'))->toBe(1);
});

it('supports split cash and transfer payments while recording only cash movements for cash', function () {
    DB::table('branch_payment_methods')->insert([
        'branch_id' => $this->branchId,
        'method_code' => 'TRANSFER',
        'is_active' => true,
    ]);
    $sale = $this->pos->startDraft($this->owner, $this->branchId);
    $this->pos->addItem($this->owner, $sale->getKey(), $this->product->getKey(), '2');

    $completed = $this->pos->complete($this->owner, $sale->getKey(), [
        ['method_code' => 'CASH', 'amount' => '7000', 'tendered_amount' => '9000'],
        ['method_code' => 'TRANSFER', 'amount' => '3000'],
    ], '423e4567-e89b-42d3-a456-426614174002');

    expect($completed->status)->toBe('COMPLETED')
        ->and(DB::table('sale_payments')->where('sale_id', $sale->getKey())->count())->toBe(2)
        ->and(DB::table('cash_movements')->where('movement_type', 'CASH_IN_SALE')->value('amount'))->toBe(7000)
        ->and(DB::table('cash_movements')->where('movement_type', 'CASH_OUT_CHANGE')->value('amount'))->toBe(2000)
        ->and(DB::table('cash_movements')->where('method_code', 'TRANSFER')->count())->toBe(0);
});

it('rejects prescription products from POS drafts', function () {
    $prescription = $this->catalog->createProduct(
        $this->owner,
        'RX-001',
        'Obat resep',
        ProductCategory::Medicine,
        ProductUnitType::Count,
        'TABLET',
        isPrescription: true,
    );
    $this->catalog->setBranchPrice($this->owner, $prescription->getKey(), $this->branchId, '25000');

    $this->catalog->configureBranch(
        $this->owner,
        $prescription->getKey(),
        $this->branchId,
        false,
        false,
    );

    $sale = $this->pos->startDraft($this->owner, $this->branchId);

    expect(fn () => $this->pos->addItem($this->owner, $sale->getKey(), $prescription->getKey(), '1'))
        ->toThrow(ValidationException::class);
});

it('rejects a changed payload for an already used idempotency key', function () {
    $sale = $this->pos->startDraft($this->owner, $this->branchId);
    $this->pos->addItem($this->owner, $sale->getKey(), $this->product->getKey(), '2');
    $payments = [['method_code' => 'CASH', 'amount' => '10000']];
    $this->pos->complete($this->owner, $sale->getKey(), $payments, '423e4567-e89b-42d3-a456-426614174003');

    expect(fn () => $this->pos->complete(
        $this->owner,
        $sale->getKey(),
        [['method_code' => 'CASH', 'amount' => '10000', 'tendered_amount' => '11000']],
        '423e4567-e89b-42d3-a456-426614174003',
    ))->toThrow(DomainException::class);
});

it('applies a discount up to ten percent and recalculates it using the active price', function () {
    $sale = $this->pos->startDraft($this->owner, $this->branchId);
    $this->pos->addItem($this->owner, $sale->getKey(), $this->product->getKey(), '2');
    $saleItemId = DB::table('sale_items')->where('sale_id', $sale->getKey())->value('id');
    $this->pos->applyDiscount($this->owner, $sale->getKey(), $saleItemId, 'PERCENT', '10');

    $this->catalog->setBranchPrice($this->owner, $this->product->getKey(), $this->branchId, '6000');
    $completed = $this->pos->complete($this->owner, $sale->getKey(), [[
        'method_code' => 'CASH',
        'amount' => '10800',
    ]], '423e4567-e89b-42d3-a456-426614174004');

    expect($completed->subtotal)->toBe('12000.00')
        ->and($completed->discount_total)->toBe('1200.00')
        ->and($completed->grand_total)->toBe('10800.00')
        ->and(DB::table('sale_items')->where('id', $saleItemId)->value('unit_price'))->toBe(6000);
});

it('requires owner password and reason for discounts above ten percent', function () {
    $sale = $this->pos->startDraft($this->owner, $this->branchId);
    $this->pos->addItem($this->owner, $sale->getKey(), $this->product->getKey(), '2');
    $saleItemId = DB::table('sale_items')->where('sale_id', $sale->getKey())->value('id');

    expect(fn () => $this->pos->applyDiscount(
        $this->owner,
        $sale->getKey(),
        $saleItemId,
        'PERCENT',
        '20',
    ))->toThrow(AuthorizationException::class);

    $this->pos->applyDiscount(
        $this->owner,
        $sale->getKey(),
        $saleItemId,
        'PERCENT',
        '20',
        'password',
        'Promo khusus pelanggan loyal',
    );

    $item = DB::table('sale_items')->where('id', $saleItemId)->first();

    expect($item->line_discount)->toBe(2000)
        ->and($item->discount_override_by)->toBe($this->owner->getKey())
        ->and($item->discount_override_reason)->toBe('Promo khusus pelanggan loyal')
        ->and(DB::table('audit_logs')->where('action', 'pos.discount.applied')->count())->toBe(1);
});

it('does not allow a discount above ten percent from a role without override permission', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['role' => Role::Admin])->save();
    DB::table('user_branch_access')->insert([
        'user_id' => $admin->getKey(),
        'branch_id' => $this->branchId,
    ]);
    $adminShift = $this->shifts->open($admin, $this->branchId, '0');
    $sale = $this->pos->startDraft($admin, $this->branchId);
    $this->pos->addItem($admin, $sale->getKey(), $this->product->getKey(), '2');
    $saleItemId = DB::table('sale_items')->where('sale_id', $sale->getKey())->value('id');

    expect(fn () => $this->pos->applyDiscount(
        $admin,
        $sale->getKey(),
        $saleItemId,
        'PERCENT',
        '20',
        'password',
        'Tidak cukup hanya password',
    ))->toThrow(AuthorizationException::class);

    expect($adminShift->status)->toBe('OPEN')
        ->and(DB::table('sale_items')->where('id', $saleItemId)->value('line_discount'))->toBe(0);
});

it('rolls back sale completion when available stock is insufficient', function () {
    $sale = $this->pos->startDraft($this->owner, $this->branchId);
    $this->pos->addItem($this->owner, $sale->getKey(), $this->product->getKey(), '8');
    DB::table('stock_balances')->where('product_id', $this->product->getKey())->update(['reserved' => 5]);

    expect(fn () => $this->pos->complete($this->owner, $sale->getKey(), [[
        'method_code' => 'CASH',
        'amount' => '40000',
    ]], '423e4567-e89b-42d3-a456-426614174005'))->toThrow(ValidationException::class);

    expect(DB::table('sales')->where('id', $sale->getKey())->value('status'))->toBe('DRAFT')
        ->and(DB::table('cash_movements')->where('movement_type', 'CASH_IN_SALE')->count())->toBe(0)
        ->and(DB::table('inventory_movements')->where('movement_type', 'SALE_POS')->count())->toBe(0)
        ->and(DB::table('idempotency_keys')->where('key', '423e4567-e89b-42d3-a456-426614174005')->count())->toBe(0);
});

it('requires a reason for a cash difference when closing a shift', function () {
    expect(fn () => $this->shifts->close($this->owner, $this->shift->getKey(), '95000'))
        ->toThrow(ValidationException::class);

    $closed = $this->shifts->close($this->owner, $this->shift->getKey(), '95000', 'Selisih hasil hitung');

    expect($closed->status)->toBe('CLOSED')
        ->and($closed->expected_cash)->toBe('100000.00')
        ->and($closed->difference)->toBe('-5000.00')
        ->and(DB::table('cash_movements')->where('movement_type', 'SHIFT_CLOSING_DIFFERENCE')->value('direction'))->toBe('OUT');
});
