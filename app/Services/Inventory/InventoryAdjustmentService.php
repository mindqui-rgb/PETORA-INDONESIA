<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\InventoryAdjustmentReason;
use App\Domain\Permission;
use App\Domain\ProductUnitType;
use App\Models\CostHistory;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLocation;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBranchCost;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Shared\AuditLogger;
use App\Services\Shared\StockWriteGuard;
use App\Services\Shared\Transactions;
use App\Support\AccessControl;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryAdjustmentService
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function apply(
        User $actor,
        string $productId,
        string $locationId,
        string $quantityDelta,
        InventoryAdjustmentReason $reason,
        ?string $unitCost = null,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
        ?string $note = null,
    ): InventoryAdjustment {
        $quantity = BigDecimal::of($quantityDelta);

        if ($quantity->isZero() || $quantity->stripTrailingZeros()->getScale() > 3) {
            throw ValidationException::withMessages([
                'quantity' => 'Perubahan stok harus bukan nol dan maksimal tiga desimal.',
            ]);
        }

        if ($reason === InventoryAdjustmentReason::Other && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Catatan wajib diisi untuk alasan OTHER.']);
        }

        $location = InventoryLocation::query()->find($locationId);

        if ($location === null) {
            throw (new ModelNotFoundException)->setModel(InventoryLocation::class, [$locationId]);
        }

        if (! $this->accessControl->can($actor, Permission::InventoryAdjust, $location->branch_id)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin penyesuaian stok di cabang ini.');
        }

        return Transactions::run(function () use (
            $actor,
            $productId,
            $location,
            $quantity,
            $reason,
            $unitCost,
            $lotNumber,
            $expiryDate,
            $note,
        ): InventoryAdjustment {
            StockWriteGuard::enable();
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();

            if ($product === null) {
                throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
            }

            if ($product->unit_type === ProductUnitType::Count && $quantity->stripTrailingZeros()->getScale() > 0) {
                throw ValidationException::withMessages(['quantity' => 'Produk satuan hitung harus memakai jumlah bulat.']);
            }

            $branch = DB::table('branches')->where('id', $location->branch_id)->first();

            if ($branch === null) {
                throw (new ModelNotFoundException)->setModel(InventoryLocation::class, [$location->getKey()]);
            }

            $adjustment = InventoryAdjustment::query()->create([
                'branch_id' => $location->branch_id,
                'location_id' => $location->getKey(),
                'product_id' => $product->getKey(),
                'reason_code' => $reason->value,
                'note' => $note,
                'actor_id' => $actor->getKey(),
            ]);

            $cost = ProductBranchCost::query()
                ->where('product_id', $product->getKey())
                ->where('branch_id', $location->branch_id)
                ->lockForUpdate()
                ->first();

            if ($quantity->isPositive()) {
                $this->receiveAdjustment(
                    $actor,
                    $adjustment,
                    $product,
                    $location,
                    $branch->timezone,
                    $quantity,
                    $reason,
                    $unitCost,
                    $lotNumber,
                    $expiryDate,
                    $cost,
                );
            } else {
                $this->removeAdjustment(
                    $actor,
                    $adjustment,
                    $product,
                    $location,
                    $branch->timezone,
                    $quantity->abs(),
                    $reason,
                    $cost,
                );
            }

            $this->auditLogger->record($actor, 'inventory.adjusted', $adjustment, $location->branch_id, [
                'product_id' => $product->getKey(),
                'quantity_delta' => (string) $quantity,
                'reason_code' => $reason->value,
            ]);

            return $adjustment;
        }, 'inventory.adjustment');
    }

    private function receiveAdjustment(
        User $actor,
        InventoryAdjustment $adjustment,
        Product $product,
        InventoryLocation $location,
        string $timezone,
        BigDecimal $quantity,
        InventoryAdjustmentReason $reason,
        ?string $unitCost,
        ?string $lotNumber,
        ?string $expiryDate,
        ?ProductBranchCost $branchCost,
    ): void {
        $lot = $this->lotForReceipt($product, $location, $timezone, $lotNumber, $expiryDate);
        $receiptCost = $unitCost === null ? null : BigDecimal::of($unitCost);

        if ($receiptCost !== null && $receiptCost->isNegative()) {
            throw ValidationException::withMessages(['unit_cost' => 'Biaya satuan tidak boleh negatif.']);
        }

        $oldCost = $branchCost === null ? null : BigDecimal::of($branchCost->avg_unit_cost);
        $receiptCost ??= $oldCost;

        if ($receiptCost === null) {
            throw ValidationException::withMessages(['unit_cost' => 'Biaya satuan wajib diisi sebelum biaya rata-rata tersedia.']);
        }

        $oldQuantity = $this->branchOnHand($product->getKey(), $location->branch_id);
        $newCost = $oldQuantity->isPositive() && $oldCost !== null
            ? $oldQuantity->multipliedBy($oldCost)
                ->plus($quantity->multipliedBy($receiptCost))
                ->dividedBy($oldQuantity->plus($quantity), 4, RoundingMode::HALF_UP)
            : $receiptCost->toScale(4, RoundingMode::HALF_UP);

        $branchCost = ProductBranchCost::query()->updateOrCreate(
            ['product_id' => $product->getKey(), 'branch_id' => $location->branch_id],
            ['avg_unit_cost' => (string) $newCost],
        );

        CostHistory::query()->create([
            'product_id' => $product->getKey(),
            'branch_id' => $location->branch_id,
            'old_cost' => $oldCost === null ? null : (string) $oldCost,
            'new_cost' => (string) $newCost,
            'reference_type' => InventoryAdjustment::class,
            'reference_id' => $adjustment->getKey(),
            'created_at' => now(),
        ]);

        $balance = StockBalance::query()->firstOrCreate(
            [
                'product_id' => $product->getKey(),
                'location_id' => $location->getKey(),
                'lot_id' => $lot->getKey(),
            ],
            ['on_hand' => '0', 'reserved' => '0'],
        );
        $balance = StockBalance::query()->whereKey($balance->getKey())->lockForUpdate()->firstOrFail();
        $balance->on_hand = (string) BigDecimal::of($balance->on_hand)->plus($quantity)->toScale(3, RoundingMode::UNNECESSARY);
        $balance->save();

        $this->recordMovement($actor, $adjustment, $product, $location, $lot, $quantity, $receiptCost, $reason);
    }

    private function removeAdjustment(
        User $actor,
        InventoryAdjustment $adjustment,
        Product $product,
        InventoryLocation $location,
        string $timezone,
        BigDecimal $quantity,
        InventoryAdjustmentReason $reason,
        ?ProductBranchCost $branchCost,
    ): void {
        if ($branchCost === null) {
            throw ValidationException::withMessages(['quantity' => 'Biaya rata-rata belum tersedia untuk stok ini.']);
        }

        $remaining = $quantity;
        $today = Carbon::now($timezone)->toDateString();
        $balances = StockBalance::query()
            ->join('inventory_lots', 'inventory_lots.id', '=', 'stock_balances.lot_id')
            ->where('stock_balances.product_id', $product->getKey())
            ->where('stock_balances.location_id', $location->getKey())
            ->orderByRaw('inventory_lots.expiry_date IS NULL')
            ->orderBy('inventory_lots.expiry_date')
            ->orderBy('inventory_lots.received_at')
            ->orderBy('stock_balances.id')
            ->lockForUpdate()
            ->get([
                'stock_balances.*',
                'inventory_lots.expiry_date as lot_expiry_date',
            ]);

        foreach ($balances as $balance) {
            if ($balance->lot_expiry_date !== null && $balance->lot_expiry_date < $today) {
                continue;
            }

            $available = BigDecimal::of($balance->on_hand)->minus($balance->reserved);

            if (! $available->isPositive()) {
                continue;
            }

            $allocated = $available->compareTo($remaining) < 0 ? $available : $remaining;
            $balance->on_hand = (string) BigDecimal::of($balance->on_hand)
                ->minus($allocated)
                ->toScale(3, RoundingMode::UNNECESSARY);
            $balance->save();

            $lot = InventoryLot::query()->findOrFail($balance->lot_id);
            $this->recordMovement(
                $actor,
                $adjustment,
                $product,
                $location,
                $lot,
                $allocated->negated(),
                BigDecimal::of($branchCost->avg_unit_cost),
                $reason,
            );

            $remaining = $remaining->minus($allocated);

            if ($remaining->isZero()) {
                break;
            }
        }

        if ($remaining->isPositive()) {
            throw ValidationException::withMessages([
                'quantity' => 'Stok tersedia tidak mencukupi untuk penyesuaian ini.',
            ]);
        }
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
                throw ValidationException::withMessages([
                    'lot_number' => 'Nomor batch dan tanggal kedaluwarsa wajib untuk produk terlacak batch.',
                ]);
            }

            $expiry = Carbon::parse($expiryDate, $timezone)->startOfDay();

            if ($expiry->toDateString() < Carbon::now($timezone)->toDateString()) {
                throw ValidationException::withMessages(['expiry_date' => 'Batch kedaluwarsa tidak dapat ditambahkan.']);
            }

            $lotNumber = trim($lotNumber);
            $lot = InventoryLot::query()->firstOrCreate(
                [
                    'product_id' => $product->getKey(),
                    'location_id' => $location->getKey(),
                    'lot_number' => $lotNumber,
                ],
                [
                    'expiry_date' => $expiry->toDateString(),
                    'received_at' => now(),
                    'created_at' => now(),
                ],
            );

            if ($lot->expiry_date?->toDateString() !== $expiry->toDateString()) {
                throw ValidationException::withMessages(['expiry_date' => 'Tanggal kedaluwarsa batch tidak boleh berubah.']);
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

    private function branchOnHand(string $productId, string $branchId): BigDecimal
    {
        $quantities = DB::table('stock_balances')
            ->join('inventory_locations', 'inventory_locations.id', '=', 'stock_balances.location_id')
            ->where('stock_balances.product_id', $productId)
            ->where('inventory_locations.branch_id', $branchId)
            ->pluck('stock_balances.on_hand');

        return $quantities->reduce(
            fn (BigDecimal $total, string|int|float $quantity): BigDecimal => $total->plus((string) $quantity),
            BigDecimal::zero(),
        );
    }

    private function recordMovement(
        User $actor,
        InventoryAdjustment $adjustment,
        Product $product,
        InventoryLocation $location,
        InventoryLot $lot,
        BigDecimal $quantity,
        BigDecimal $unitCost,
        InventoryAdjustmentReason $reason,
    ): void {
        InventoryMovement::query()->create([
            'product_id' => $product->getKey(),
            'location_id' => $location->getKey(),
            'lot_id' => $lot->getKey(),
            'quantity_delta' => (string) $quantity->toScale(3, RoundingMode::UNNECESSARY),
            'movement_type' => 'ADJUSTMENT',
            'unit_cost_snapshot' => (string) $unitCost->toScale(4, RoundingMode::HALF_UP),
            'reference_type' => InventoryAdjustment::class,
            'reference_id' => $adjustment->getKey(),
            'reason_code' => $reason->value,
            'actor_id' => $actor->getKey(),
            'occurred_at' => now(),
        ]);
    }
}
