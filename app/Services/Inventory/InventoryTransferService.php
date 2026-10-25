<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\Permission;
use App\Domain\ProductUnitType;
use App\Models\CostHistory;
use App\Models\InventoryLocation;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\ProductBranchCost;
use App\Models\User;
use App\Services\Shared\AuditLogger;
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

final class InventoryTransferService
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly AuditLogger $auditLogger,
        private readonly IdempotencyGuard $idempotencyGuard,
    ) {}

    /** @param list<array{product_id: string, quantity: string}> $items */
    public function createDraft(User $actor, string $sourceLocationId, string $destinationLocationId, array $items): InventoryTransfer
    {
        $source = $this->findLocation($sourceLocationId);
        $destination = $this->findLocation($destinationLocationId);
        $this->authorize($actor, Permission::InventoryTransferCreate, $source->branch_id);
        $this->authorize($actor, Permission::InventoryTransferCreate, $destination->branch_id);

        if ($sourceLocationId === $destinationLocationId || $items === []) {
            throw ValidationException::withMessages(['transfer' => 'Lokasi asal dan tujuan harus berbeda dan item wajib diisi.']);
        }

        return Transactions::run(function () use ($actor, $source, $destination, $items): InventoryTransfer {
            $transfer = InventoryTransfer::query()->create([
                'source_location_id' => $source->getKey(),
                'destination_location_id' => $destination->getKey(),
                'status' => 'DRAFT',
                'created_by' => $actor->getKey(),
            ]);

            foreach ($items as $item) {
                $product = Product::query()->whereKey($item['product_id'])->first();
                $quantity = BigDecimal::of($item['quantity']);

                if ($product === null || ! $quantity->isPositive() || $quantity->stripTrailingZeros()->getScale() > 3) {
                    throw ValidationException::withMessages(['items' => 'Produk atau jumlah transfer tidak valid.']);
                }

                if ($product->unit_type === ProductUnitType::Count && $quantity->stripTrailingZeros()->getScale() > 0) {
                    throw ValidationException::withMessages(['items' => 'Produk satuan hitung harus memakai jumlah bulat.']);
                }

                DB::table('inventory_transfer_items')->insert([
                    'id' => (string) Str::uuid(),
                    'transfer_id' => $transfer->getKey(),
                    'product_id' => $product->getKey(),
                    'quantity_requested' => (string) $quantity->toScale(3, RoundingMode::UNNECESSARY),
                    'quantity_sent' => '0.000',
                    'quantity_received' => '0.000',
                    'quantity_lost' => '0.000',
                    'created_at' => now(),
                ]);
            }

            $this->auditLogger->record($actor, 'inventory_transfer.created', $transfer, $source->branch_id, [
                'destination_branch_id' => $destination->branch_id,
                'item_count' => count($items),
            ]);

            return $transfer;
        });
    }

    public function approve(User $actor, string $transferId, string $reason): InventoryTransfer
    {
        $transfer = $this->findTransfer($transferId);
        $source = $this->findLocation($transfer->source_location_id);
        $destination = $this->findLocation($transfer->destination_location_id);

        if ($source->branch_id === $destination->branch_id) {
            throw ValidationException::withMessages(['transfer' => 'Transfer dalam cabang tidak memerlukan approval.']);
        }

        $this->authorize($actor, Permission::InventoryTransferApprove);

        return Transactions::run(function () use ($actor, $transferId, $reason): InventoryTransfer {
            $transfer = InventoryTransfer::query()->whereKey($transferId)->lockForUpdate()->firstOrFail();

            if ($transfer->status !== 'DRAFT' || trim($reason) === '') {
                throw ValidationException::withMessages(['transfer' => 'Transfer DRAFT dan alasan approval wajib diisi.']);
            }

            $transfer->forceFill([
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'approval_reason' => trim($reason),
            ])->save();
            $this->auditLogger->record($actor, 'inventory_transfer.approved', $transfer, details: [
                'reason' => trim($reason),
            ]);

            return $transfer;
        });
    }

    public function dispatch(User $actor, string $transferId, string $idempotencyKey, ?string $note = null): InventoryTransfer
    {
        $transfer = $this->findTransfer($transferId);
        $source = $this->findLocation($transfer->source_location_id);
        $destination = $this->findLocation($transfer->destination_location_id);
        $this->authorize($actor, Permission::InventoryTransferCreate, $source->branch_id);

        if (trim($idempotencyKey) === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempotensi wajib diisi.']);
        }

        $payloadHash = IdempotencyGuard::hashPayload(['transfer_id' => $transferId, 'note' => $note]);

        return $this->idempotencyGuard->run($actor, 'inventory.transfer.dispatch', $idempotencyKey, $payloadHash, function () use ($actor, $transferId, $source, $destination, $note): InventoryTransfer {
            $transfer = InventoryTransfer::query()->whereKey($transferId)->lockForUpdate()->firstOrFail();

            $isCrossBranch = $source->branch_id !== $destination->branch_id;

            if ($transfer->status !== 'DRAFT' || ($isCrossBranch && $transfer->approved_at === null)) {
                throw ValidationException::withMessages(['transfer' => 'Transfer belum siap dikirim atau belum disetujui.']);
            }

            StockWriteGuard::enable();

            $sourceBranch = DB::table('branches')->where('id', $source->branch_id)->first();
            $items = DB::table('inventory_transfer_items')
                ->where('transfer_id', $transfer->getKey())
                ->orderBy('product_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $productIds = $items->pluck('product_id')->unique()->sort()->values();
            Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
            $balances = DB::table('stock_balances')
                ->where('location_id', $source->getKey())
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->orderBy('location_id')
                ->orderBy('lot_id')
                ->lockForUpdate()
                ->get();
            $lots = InventoryLot::query()->whereIn('id', $balances->pluck('lot_id'))->get()->keyBy('id');
            $costs = ProductBranchCost::query()
                ->where('branch_id', $source->branch_id)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');
            $today = Carbon::now($sourceBranch->timezone)->toDateString();

            foreach ($items as $item) {
                $remaining = BigDecimal::of((string) $item->quantity_requested);
                $cost = $costs->get($item->product_id);

                if ($cost === null) {
                    throw ValidationException::withMessages(['transfer' => 'Biaya rata-rata produk belum tersedia.']);
                }

                $productBalances = $balances->where('product_id', $item->product_id)->values()->all();
                usort($productBalances, function (object $left, object $right) use ($lots): int {
                    $leftLot = $lots->get($left->lot_id);
                    $rightLot = $lots->get($right->lot_id);

                    return [
                        $leftLot->expiry_date ?? '9999-12-31',
                        $leftLot->received_at ?? '',
                        $left->id,
                    ] <=> [
                        $rightLot->expiry_date ?? '9999-12-31',
                        $rightLot->received_at ?? '',
                        $right->id,
                    ];
                });

                foreach ($productBalances as $balance) {
                    $lot = $lots->get($balance->lot_id);

                    if ($lot === null || ($lot->expiry_date !== null && $lot->expiry_date < $today)) {
                        continue;
                    }

                    $available = BigDecimal::of((string) $balance->on_hand)->minus((string) $balance->reserved);

                    if (! $available->isPositive()) {
                        continue;
                    }

                    $sent = $available->compareTo($remaining) < 0 ? $available : $remaining;
                    DB::table('stock_balances')->where('id', $balance->id)->update([
                        'on_hand' => (string) BigDecimal::of((string) $balance->on_hand)->minus($sent)
                            ->toScale(3, RoundingMode::UNNECESSARY),
                        'updated_at' => now(),
                    ]);
                    DB::table('inventory_transfer_lots')->insert([
                        'id' => (string) Str::uuid(),
                        'transfer_item_id' => $item->id,
                        'source_lot_id' => $lot->getKey(),
                        'quantity_sent' => (string) $sent->toScale(3, RoundingMode::UNNECESSARY),
                        'quantity_received' => '0.000',
                        'unit_cost' => (string) BigDecimal::of($cost->avg_unit_cost)->toScale(4, RoundingMode::HALF_UP),
                        'created_at' => now(),
                    ]);
                    InventoryMovement::query()->create([
                        'product_id' => $item->product_id,
                        'location_id' => $source->getKey(),
                        'lot_id' => $lot->getKey(),
                        'quantity_delta' => (string) $sent->negated()->toScale(3, RoundingMode::UNNECESSARY),
                        'movement_type' => 'TRANSFER_OUT',
                        'unit_cost_snapshot' => (string) BigDecimal::of($cost->avg_unit_cost)->toScale(4, RoundingMode::HALF_UP),
                        'reference_type' => InventoryTransfer::class,
                        'reference_id' => $transfer->getKey(),
                        'reason_code' => null,
                        'actor_id' => $actor->getKey(),
                        'occurred_at' => now(),
                    ]);
                    $remaining = $remaining->minus($sent);

                    if ($remaining->isZero()) {
                        break;
                    }
                }

                if ($remaining->isPositive()) {
                    throw ValidationException::withMessages(['transfer' => 'Stok tersedia tidak mencukupi untuk transfer ini.']);
                }

                DB::table('inventory_transfer_items')->where('id', $item->id)->update([
                    'quantity_sent' => $item->quantity_requested,
                ]);
            }

            $transfer->forceFill([
                'status' => 'IN_TRANSIT',
                'dispatched_by' => $actor->getKey(),
                'dispatched_at' => now(),
                'dispatch_note' => $note,
            ])->save();
            $this->auditLogger->record($actor, 'inventory_transfer.dispatched', $transfer, $source->branch_id);

            return $transfer;
        });
    }

    /** @param list<array{transfer_lot_id: string, quantity_received: string, loss_reason?: ?string}> $items */
    public function receive(User $actor, string $transferId, array $items, string $idempotencyKey): InventoryTransfer
    {
        $transfer = $this->findTransfer($transferId);
        $source = $this->findLocation($transfer->source_location_id);
        $destination = $this->findLocation($transfer->destination_location_id);
        $this->authorize($actor, Permission::InventoryTransferReceive, $destination->branch_id);

        if ($items === [] || trim($idempotencyKey) === '') {
            throw ValidationException::withMessages(['receipt' => 'Rincian penerimaan dan kunci idempotensi wajib diisi.']);
        }

        usort($items, fn (array $left, array $right): int => $left['transfer_lot_id'] <=> $right['transfer_lot_id']);
        $payloadHash = IdempotencyGuard::hashPayload(['transfer_id' => $transferId, 'items' => $items]);

        return $this->idempotencyGuard->run($actor, 'inventory.transfer.receive', $idempotencyKey, $payloadHash, function () use ($actor, $transferId, $items, $source, $destination): InventoryTransfer {
            $transfer = InventoryTransfer::query()->whereKey($transferId)->lockForUpdate()->firstOrFail();

            if ($transfer->status !== 'IN_TRANSIT'
                || (string) $transfer->dispatched_by === (string) $actor->getKey()) {
                throw new AuthorizationException('Transfer tidak dapat diterima oleh pengirim atau statusnya tidak valid.');
            }

            StockWriteGuard::enable();

            $allocated = DB::table('inventory_transfer_lots')
                ->join('inventory_transfer_items', 'inventory_transfer_items.id', '=', 'inventory_transfer_lots.transfer_item_id')
                ->where('inventory_transfer_items.transfer_id', $transfer->getKey())
                ->select('inventory_transfer_lots.*', 'inventory_transfer_items.product_id')
                ->orderBy('inventory_transfer_items.product_id')
                ->orderBy('inventory_transfer_lots.id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $inputLotIds = array_column($items, 'transfer_lot_id');

            if (count(array_unique($inputLotIds)) !== count($items)
                || $allocated->keys()->sort()->values()->all() !== collect($inputLotIds)->sort()->values()->all()) {
                throw ValidationException::withMessages(['receipt' => 'Setiap batch yang dikirim harus diterima atau dicatat hilang.']);
            }

            $destinationBranch = DB::table('branches')->where('id', $destination->branch_id)->first();
            foreach ($items as $input) {
                $sentLot = $allocated->get($input['transfer_lot_id']);

                if ($sentLot === null) {
                    throw ValidationException::withMessages(['receipt' => 'Batch bukan bagian dari transfer ini.']);
                }

                $quantity = BigDecimal::of($input['quantity_received']);
                $sent = BigDecimal::of((string) $sentLot->quantity_sent);
                $lost = $sent->minus($quantity);
                $lossReason = trim((string) ($input['loss_reason'] ?? ''));
                $product = Product::query()->findOrFail($sentLot->product_id);

                if ($quantity->isNegative() || $quantity->compareTo($sent) > 0 || ($lost->isPositive() && $lossReason === '')) {
                    throw ValidationException::withMessages(['receipt' => 'Jumlah penerimaan atau alasan kehilangan tidak valid.']);
                }

                if ($product->unit_type === ProductUnitType::Count && $quantity->stripTrailingZeros()->getScale() > 0) {
                    throw ValidationException::withMessages(['receipt' => 'Produk satuan hitung harus diterima dalam jumlah bulat.']);
                }

                $sourceLot = InventoryLot::query()->findOrFail($sentLot->source_lot_id);
                $destinationLot = $this->destinationLot($sourceLot, $destination, $actor);

                if ($quantity->isPositive()) {
                    $this->increaseDestinationStock(
                        $actor,
                        $transfer,
                        $source,
                        $destination,
                        $destinationBranch->timezone,
                        $sentLot->product_id,
                        $sourceLot,
                        $destinationLot,
                        $quantity,
                        BigDecimal::of((string) $sentLot->unit_cost),
                    );
                }

                DB::table('inventory_transfer_lots')->where('id', $sentLot->id)->update([
                    'destination_lot_id' => $destinationLot->getKey(),
                    'quantity_received' => (string) $quantity->toScale(3, RoundingMode::UNNECESSARY),
                    'loss_reason' => $lost->isPositive() ? $lossReason : null,
                ]);
                DB::table('inventory_transfer_items')->where('id', $sentLot->transfer_item_id)->update([
                    'quantity_received' => DB::raw('quantity_received + '.(string) $quantity->toScale(3, RoundingMode::UNNECESSARY)),
                    'quantity_lost' => DB::raw('quantity_lost + '.(string) $lost->toScale(3, RoundingMode::UNNECESSARY)),
                    'loss_reason' => $lost->isPositive() ? $lossReason : null,
                ]);
            }

            $transfer->forceFill([
                'status' => 'RECEIVED',
                'received_by' => $actor->getKey(),
                'received_at' => now(),
            ])->save();
            $this->auditLogger->record($actor, 'inventory_transfer.received', $transfer, $destination->branch_id);

            return $transfer;
        });
    }

    private function increaseDestinationStock(
        User $actor,
        InventoryTransfer $transfer,
        InventoryLocation $source,
        InventoryLocation $destination,
        string $timezone,
        string $productId,
        InventoryLot $sourceLot,
        InventoryLot $destinationLot,
        BigDecimal $quantity,
        BigDecimal $unitCost,
    ): void {
        $cost = ProductBranchCost::query()
            ->where('product_id', $productId)
            ->where('branch_id', $destination->branch_id)
            ->lockForUpdate()
            ->first();
        $oldCost = $cost === null ? null : BigDecimal::of($cost->avg_unit_cost);
        $oldQuantity = DB::table('stock_balances')
            ->join('inventory_locations', 'inventory_locations.id', '=', 'stock_balances.location_id')
            ->where('stock_balances.product_id', $productId)
            ->where('inventory_locations.branch_id', $destination->branch_id)
            ->pluck('stock_balances.on_hand')
            ->reduce(
                fn (BigDecimal $total, string|int|float $amount): BigDecimal => $total->plus((string) $amount),
                BigDecimal::zero(),
            );
        $newCost = $oldQuantity->isPositive() && $oldCost !== null
            ? $oldQuantity->multipliedBy($oldCost)
                ->plus($quantity->multipliedBy($unitCost))
                ->dividedBy($oldQuantity->plus($quantity), 4, RoundingMode::HALF_UP)
            : $unitCost->toScale(4, RoundingMode::HALF_UP);

        ProductBranchCost::query()->updateOrCreate(
            ['product_id' => $productId, 'branch_id' => $destination->branch_id],
            ['avg_unit_cost' => (string) $newCost],
        );
        CostHistory::query()->create([
            'product_id' => $productId,
            'branch_id' => $destination->branch_id,
            'old_cost' => $oldCost === null ? null : (string) $oldCost,
            'new_cost' => (string) $newCost,
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transfer->getKey(),
            'created_at' => now(),
        ]);

        $balance = DB::table('stock_balances')
            ->where('product_id', $productId)
            ->where('location_id', $destination->getKey())
            ->where('lot_id', $destinationLot->getKey())
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            DB::table('stock_balances')->insert([
                'id' => (string) Str::uuid(),
                'product_id' => $productId,
                'location_id' => $destination->getKey(),
                'lot_id' => $destinationLot->getKey(),
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
            'product_id' => $productId,
            'location_id' => $destination->getKey(),
            'lot_id' => $destinationLot->getKey(),
            'quantity_delta' => (string) $quantity->toScale(3, RoundingMode::UNNECESSARY),
            'movement_type' => 'TRANSFER_IN',
            'unit_cost_snapshot' => (string) $unitCost->toScale(4, RoundingMode::HALF_UP),
            'reference_type' => InventoryTransfer::class,
            'reference_id' => $transfer->getKey(),
            'reason_code' => null,
            'actor_id' => $actor->getKey(),
            'occurred_at' => now(),
        ]);
    }

    private function destinationLot(InventoryLot $sourceLot, InventoryLocation $destination, User $actor): InventoryLot
    {
        $attributes = [
            'product_id' => $sourceLot->product_id,
            'location_id' => $destination->getKey(),
            'lot_number' => $sourceLot->lot_number,
        ];
        $lot = InventoryLot::query()->firstOrCreate($attributes, [
            'expiry_date' => $sourceLot->expiry_date?->toDateString(),
            'received_at' => now(),
            'created_at' => now(),
        ]);

        if ($lot->expiry_date?->toDateString() !== $sourceLot->expiry_date?->toDateString()) {
            throw ValidationException::withMessages(['receipt' => 'Batch tujuan memiliki tanggal kedaluwarsa berbeda.']);
        }

        return $lot;
    }

    private function findTransfer(string $transferId): InventoryTransfer
    {
        $transfer = InventoryTransfer::query()->find($transferId);

        if ($transfer === null) {
            throw (new ModelNotFoundException)->setModel(InventoryTransfer::class, [$transferId]);
        }

        return $transfer;
    }

    private function findLocation(string $locationId): InventoryLocation
    {
        $location = InventoryLocation::query()->where('is_active', true)->find($locationId);

        if ($location === null) {
            throw (new ModelNotFoundException)->setModel(InventoryLocation::class, [$locationId]);
        }

        return $location;
    }

    private function authorize(User $actor, Permission $permission, ?string $branchId = null): void
    {
        if (! $this->accessControl->can($actor, $permission, $branchId)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin transfer untuk cabang ini.');
        }
    }
}
