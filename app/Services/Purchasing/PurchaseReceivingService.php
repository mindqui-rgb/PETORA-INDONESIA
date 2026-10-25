<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Permission;
use App\Domain\ProductUnitType;
use App\Models\CostHistory;
use App\Models\GoodsReceipt;
use App\Models\InventoryLocation;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBranchCost;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Shared\AuditLogger;
use App\Services\Shared\DocumentNumberService;
use App\Services\Shared\IdempotencyGuard;
use App\Services\Shared\StockWriteGuard;
use App\Services\Shared\Transactions;
use App\Support\AccessControl;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PurchaseReceivingService
{
    private const RECEIVE_OPERATION = 'purchase.receive';

    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly AuditLogger $auditLogger,
        private readonly IdempotencyGuard $idempotencyGuard,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /** @param array{name: string, contact_name?: ?string, phone?: ?string, email?: ?string, address?: ?string, tax_id?: ?string, payment_terms_days?: int} $data */
    public function createSupplier(User $actor, array $data): Supplier
    {
        $this->authorize($actor, Permission::PurchaseManage);

        if (trim($data['name'] ?? '') === '') {
            throw ValidationException::withMessages(['name' => 'Nama supplier wajib diisi.']);
        }

        return Transactions::run(function () use ($actor, $data): Supplier {
            $supplier = Supplier::query()->create([
                ...$data,
                'name' => trim($data['name']),
                'payment_terms_days' => max(0, (int) ($data['payment_terms_days'] ?? 0)),
                'is_active' => true,
                'created_by' => $actor->getKey(),
            ]);

            $this->auditLogger->record($actor, 'supplier.created', $supplier);

            return $supplier;
        });
    }

    /** @param list<array{product_id: string, quantity: string, unit_cost: string}> $items */
    public function createOrder(
        User $actor,
        string $branchId,
        string $supplierId,
        array $items,
        ?string $notes = null,
    ): PurchaseOrder {
        $this->authorize($actor, Permission::PurchaseManage, $branchId);

        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Pesanan pembelian harus memiliki minimal satu item.']);
        }

        return Transactions::run(function () use ($actor, $branchId, $supplierId, $items, $notes): PurchaseOrder {
            $branch = DB::table('branches')->where('id', $branchId)->lockForUpdate()->first();
            $supplier = Supplier::query()->whereKey($supplierId)->where('is_active', true)->first();

            if ($branch === null || ! $branch->is_active || $supplier === null) {
                throw ValidationException::withMessages(['supplier' => 'Cabang atau supplier aktif tidak ditemukan.']);
            }

            $order = PurchaseOrder::query()->create([
                'branch_id' => $branchId,
                'supplier_id' => $supplierId,
                'purchase_number' => $this->documentNumbers->next(
                    $branchId,
                    'PO',
                    Carbon::now($branch->timezone)->year,
                ),
                'status' => 'DRAFT',
                'notes' => $notes,
                'created_by' => $actor->getKey(),
            ]);

            foreach ($items as $item) {
                $product = Product::query()->whereKey($item['product_id'])->first();
                $quantity = BigDecimal::of($item['quantity']);
                $unitCost = BigDecimal::of($item['unit_cost']);

                if ($product === null || ! $product->is_active || ! $quantity->isPositive()
                    || $quantity->stripTrailingZeros()->getScale() > 3 || $unitCost->isNegative()) {
                    throw ValidationException::withMessages(['items' => 'Item pesanan atau nilainya tidak valid.']);
                }

                if ($product->unit_type === ProductUnitType::Count && $quantity->stripTrailingZeros()->getScale() > 0) {
                    throw ValidationException::withMessages(['items' => 'Jumlah produk satuan hitung harus bilangan bulat.']);
                }

                DB::table('purchase_order_items')->insert([
                    'id' => (string) Str::uuid(),
                    'purchase_order_id' => $order->getKey(),
                    'product_id' => $product->getKey(),
                    'quantity_ordered' => (string) $quantity->toScale(3, RoundingMode::UNNECESSARY),
                    'quantity_received' => '0.000',
                    'unit_cost' => (string) $unitCost->toScale(4, RoundingMode::HALF_UP),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->auditLogger->record($actor, 'purchase_order.created', $order, $branchId, [
                'purchase_number' => $order->purchase_number,
                'item_count' => count($items),
            ]);

            return $order;
        }, 'purchase_order.create');
    }

    public function submit(User $actor, string $orderId): PurchaseOrder
    {
        $order = $this->findOrder($orderId);
        $this->authorize($actor, Permission::PurchaseManage, $order->branch_id);

        return Transactions::run(function () use ($actor, $orderId): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($order->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Hanya pesanan DRAFT yang dapat diajukan.']);
            }

            $order->forceFill([
                'status' => 'SUBMITTED',
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
            ])->save();
            $this->auditLogger->record($actor, 'purchase_order.submitted', $order, $order->branch_id);

            return $order;
        });
    }

    /** @param list<array{purchase_order_item_id: string, location_id: string, quantity: string, unit_cost: string, lot_number?: ?string, expiry_date?: ?string}> $items */
    public function receive(User $actor, string $orderId, array $items, string $idempotencyKey): GoodsReceipt
    {
        $order = $this->findOrder($orderId);
        $this->authorize($actor, Permission::PurchaseManage, $order->branch_id);

        if ($items === [] || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['receipt' => 'Item penerimaan dan kunci idempotensi wajib diisi.']);
        }

        usort($items, fn (array $left, array $right): int => $left['purchase_order_item_id'] <=> $right['purchase_order_item_id']);
        $payloadHash = IdempotencyGuard::hashPayload(['order_id' => $orderId, 'items' => $items]);

        return $this->idempotencyGuard->run($actor, self::RECEIVE_OPERATION, $idempotencyKey, $payloadHash, function () use ($actor, $orderId, $items): GoodsReceipt {
            $order = PurchaseOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, ['SUBMITTED', 'PARTIALLY_RECEIVED'], true)) {
                throw ValidationException::withMessages(['status' => 'Pesanan harus diajukan dan belum selesai diterima.']);
            }

            StockWriteGuard::enable();

            $orderItems = DB::table('purchase_order_items')
                ->where('purchase_order_id', $order->getKey())
                ->orderBy('product_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $productIds = collect($items)->map(fn (array $item): mixed => $orderItems->get($item['purchase_order_item_id'])?->product_id)
                ->filter()
                ->unique()
                ->sort()
                ->values();
            Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();

            $branch = DB::table('branches')->where('id', $order->branch_id)->first();
            $receipt = GoodsReceipt::query()->create([
                'purchase_order_id' => $order->getKey(),
                'branch_id' => $order->branch_id,
                'received_by' => $actor->getKey(),
                'received_at' => now(),
                'created_at' => now(),
            ]);

            foreach ($items as $input) {
                $orderItem = $orderItems->get($input['purchase_order_item_id']);

                if ($orderItem === null) {
                    throw ValidationException::withMessages(['items' => 'Item penerimaan bukan bagian dari pesanan ini.']);
                }

                $product = Product::query()->whereKey($orderItem->product_id)->firstOrFail();
                $location = InventoryLocation::query()
                    ->whereKey($input['location_id'])
                    ->where('branch_id', $order->branch_id)
                    ->where('is_active', true)
                    ->first();
                $quantity = BigDecimal::of($input['quantity']);
                $unitCost = BigDecimal::of($input['unit_cost']);
                $remaining = BigDecimal::of((string) $orderItem->quantity_ordered)
                    ->minus((string) $orderItem->quantity_received);

                if ($location === null || ! $quantity->isPositive() || $quantity->compareTo($remaining) > 0 || $unitCost->isNegative()) {
                    throw ValidationException::withMessages(['items' => 'Jumlah, lokasi, atau biaya penerimaan tidak valid.']);
                }

                if ($product->unit_type === ProductUnitType::Count && $quantity->stripTrailingZeros()->getScale() > 0) {
                    throw ValidationException::withMessages(['items' => 'Jumlah produk satuan hitung harus bilangan bulat.']);
                }

                $lot = $this->lotForReceipt($product, $location, $branch->timezone, $input['lot_number'] ?? null, $input['expiry_date'] ?? null);
                $this->increaseStock($actor, $receipt, $order, $product, $location, $lot, $quantity, $unitCost);

                DB::table('purchase_order_items')->where('id', $orderItem->id)->update([
                    'quantity_received' => (string) BigDecimal::of((string) $orderItem->quantity_received)
                        ->plus($quantity)
                        ->toScale(3, RoundingMode::UNNECESSARY),
                    'updated_at' => now(),
                ]);
                $orderItem->quantity_received = (string) BigDecimal::of((string) $orderItem->quantity_received)
                    ->plus($quantity)
                    ->toScale(3, RoundingMode::UNNECESSARY);
                DB::table('goods_receipt_items')->insert([
                    'id' => (string) Str::uuid(),
                    'goods_receipt_id' => $receipt->getKey(),
                    'purchase_order_item_id' => $orderItem->id,
                    'product_id' => $product->getKey(),
                    'location_id' => $location->getKey(),
                    'lot_id' => $lot->getKey(),
                    'quantity_received' => (string) $quantity->toScale(3, RoundingMode::UNNECESSARY),
                    'unit_cost' => (string) $unitCost->toScale(4, RoundingMode::HALF_UP),
                    'created_at' => now(),
                ]);
            }

            $hasRemaining = DB::table('purchase_order_items')
                ->where('purchase_order_id', $order->getKey())
                ->whereColumn('quantity_received', '<', 'quantity_ordered')
                ->exists();
            $order->forceFill(['status' => $hasRemaining ? 'PARTIALLY_RECEIVED' : 'RECEIVED'])->save();

            $this->auditLogger->record($actor, 'purchase_order.received', $receipt, $order->branch_id, [
                'purchase_number' => $order->purchase_number,
                'status' => $order->status,
            ]);

            return $receipt;
        });
    }

    private function increaseStock(
        User $actor,
        GoodsReceipt $receipt,
        PurchaseOrder $order,
        Product $product,
        InventoryLocation $location,
        InventoryLot $lot,
        BigDecimal $quantity,
        BigDecimal $unitCost,
    ): void {
        $cost = ProductBranchCost::query()
            ->where('product_id', $product->getKey())
            ->where('branch_id', $order->branch_id)
            ->lockForUpdate()
            ->first();
        $oldCost = $cost === null ? null : BigDecimal::of($cost->avg_unit_cost);
        $oldQuantity = DB::table('stock_balances')
            ->join('inventory_locations', 'inventory_locations.id', '=', 'stock_balances.location_id')
            ->where('stock_balances.product_id', $product->getKey())
            ->where('inventory_locations.branch_id', $order->branch_id)
            ->pluck('stock_balances.on_hand')
            ->reduce(
                fn (BigDecimal $total, string|int|float $value): BigDecimal => $total->plus((string) $value),
                BigDecimal::zero(),
            );
        $newCost = $oldQuantity->isPositive() && $oldCost !== null
            ? $oldQuantity->multipliedBy($oldCost)
                ->plus($quantity->multipliedBy($unitCost))
                ->dividedBy($oldQuantity->plus($quantity), 4, RoundingMode::HALF_UP)
            : $unitCost->toScale(4, RoundingMode::HALF_UP);

        ProductBranchCost::query()->updateOrCreate(
            ['product_id' => $product->getKey(), 'branch_id' => $order->branch_id],
            ['avg_unit_cost' => (string) $newCost],
        );
        CostHistory::query()->create([
            'product_id' => $product->getKey(),
            'branch_id' => $order->branch_id,
            'old_cost' => $oldCost === null ? null : (string) $oldCost,
            'new_cost' => (string) $newCost,
            'reference_type' => GoodsReceipt::class,
            'reference_id' => $receipt->getKey(),
            'created_at' => now(),
        ]);

        $balance = DB::table('stock_balances')
            ->where('product_id', $product->getKey())
            ->where('location_id', $location->getKey())
            ->where('lot_id', $lot->getKey())
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            DB::table('stock_balances')->insert([
                'id' => (string) Str::uuid(),
                'product_id' => $product->getKey(),
                'location_id' => $location->getKey(),
                'lot_id' => $lot->getKey(),
                'on_hand' => (string) $quantity->toScale(3, RoundingMode::UNNECESSARY),
                'reserved' => '0.000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('stock_balances')->where('id', $balance->id)->update([
                'on_hand' => (string) BigDecimal::of((string) $balance->on_hand)->plus($quantity)->toScale(3, RoundingMode::UNNECESSARY),
                'updated_at' => now(),
            ]);
        }

        InventoryMovement::query()->create([
            'product_id' => $product->getKey(),
            'location_id' => $location->getKey(),
            'lot_id' => $lot->getKey(),
            'quantity_delta' => (string) $quantity->toScale(3, RoundingMode::UNNECESSARY),
            'movement_type' => 'PURCHASE_RECEIPT',
            'unit_cost_snapshot' => (string) $unitCost->toScale(4, RoundingMode::HALF_UP),
            'reference_type' => GoodsReceipt::class,
            'reference_id' => $receipt->getKey(),
            'reason_code' => null,
            'actor_id' => $actor->getKey(),
            'occurred_at' => now(),
        ]);
    }

    private function lotForReceipt(
        Product $product,
        InventoryLocation $location,
        string $timezone,
        ?string $lotNumber,
        ?string $expiryDate,
    ): InventoryLot {
        if ($product->track_lot) {
            if (trim((string) $lotNumber) === '' || $expiryDate === null) {
                throw ValidationException::withMessages(['items' => 'Nomor batch dan tanggal kedaluwarsa wajib diisi.']);
            }

            $expiry = Carbon::parse($expiryDate, $timezone);

            if ($expiry->toDateString() < Carbon::now($timezone)->toDateString()) {
                throw ValidationException::withMessages(['items' => 'Batch yang sudah kedaluwarsa tidak dapat diterima.']);
            }

            $lot = InventoryLot::query()->firstOrCreate(
                [
                    'product_id' => $product->getKey(),
                    'location_id' => $location->getKey(),
                    'lot_number' => trim($lotNumber),
                ],
                [
                    'expiry_date' => $expiry->toDateString(),
                    'received_at' => now(),
                    'created_at' => now(),
                ],
            );

            if ($lot->expiry_date?->toDateString() !== $expiry->toDateString()) {
                throw ValidationException::withMessages(['items' => 'Tanggal kedaluwarsa batch tidak boleh berubah.']);
            }

            return $lot;
        }

        return InventoryLot::query()->firstOrCreate(
            [
                'product_id' => $product->getKey(),
                'location_id' => $location->getKey(),
                'lot_number' => 'DEFAULT',
            ],
            ['created_at' => now()],
        );
    }

    private function findOrder(string $orderId): PurchaseOrder
    {
        $order = PurchaseOrder::query()->find($orderId);

        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(PurchaseOrder::class, [$orderId]);
        }

        return $order;
    }

    private function authorize(User $actor, Permission $permission, ?string $branchId = null): void
    {
        if (! $this->accessControl->can($actor, $permission, $branchId)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin pembelian untuk cabang ini.');
        }
    }
}
