<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Domain\Permission;
use App\Domain\ProductUnitType;
use App\Domain\Role;
use App\Models\CashShift;
use App\Models\InventoryLocation;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBranchCost;
use App\Models\ProductBranchSetting;
use App\Models\Sale;
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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PosSalesService
{
    private const OPERATION = 'pos.sale.complete';

    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly AuditLogger $auditLogger,
        private readonly IdempotencyGuard $idempotencyGuard,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    public function startDraft(User $actor, string $branchId, ?string $customerId = null): Sale
    {
        $this->authorize($actor, $branchId);

        return Transactions::run(function () use ($actor, $branchId, $customerId): Sale {
            $branch = DB::table('branches')->where('id', $branchId)->first();

            if ($branch === null || ! $branch->is_active || ! $branch->pos_enabled) {
                throw new ModelNotFoundException('Cabang tidak ditemukan atau POS tidak aktif.');
            }

            $shift = CashShift::query()
                ->where('branch_id', $branchId)
                ->where('cashier_id', $actor->getKey())
                ->where('status', 'OPEN')
                ->first();

            if ($shift === null) {
                throw ValidationException::withMessages(['shift' => 'Buka shift kasir sebelum membuat penjualan.']);
            }

            $sale = Sale::query()->create([
                'branch_id' => $branchId,
                'shift_id' => $shift->getKey(),
                'customer_id' => $customerId,
                'status' => 'DRAFT',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->auditLogger->record($actor, 'pos.sale.draft_created', $sale, $branchId);

            return $sale;
        });
    }

    public function addItem(User $actor, string $saleId, string $productId, string $quantity): Sale
    {
        $sale = $this->findSale($saleId, $actor);
        $this->authorize($actor, $sale->branch_id);
        $amount = BigDecimal::of($quantity);

        if (! $amount->isPositive() || $amount->stripTrailingZeros()->getScale() > 3) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah harus positif dan maksimal tiga desimal.']);
        }

        return Transactions::run(function () use ($actor, $saleId, $productId, $amount): Sale {
            $sale = Sale::query()->whereKey($saleId)->lockForUpdate()->firstOrFail();
            $this->assertDraftOwner($actor, $sale);

            $product = Product::query()->whereKey($productId)->first();
            $settings = ProductBranchSetting::query()
                ->where('product_id', $productId)
                ->where('branch_id', $sale->branch_id)
                ->where('is_active', true)
                ->where('is_pos_sellable', true)
                ->first();

            if ($product === null || ! $product->is_active || $product->is_prescription || $settings === null) {
                throw ValidationException::withMessages(['product' => 'Produk tidak tersedia untuk dijual di cabang ini.']);
            }

            if ($product->unit_type === ProductUnitType::Count && $amount->stripTrailingZeros()->getScale() > 0) {
                throw ValidationException::withMessages(['quantity' => 'Produk satuan hitung harus memakai jumlah bulat.']);
            }

            $price = DB::table('product_prices')
                ->where('product_id', $productId)
                ->where('branch_id', $sale->branch_id)
                ->where('effective_from', '<=', now())
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();

            if ($price === null) {
                throw ValidationException::withMessages(['price' => 'Harga produk belum diatur untuk cabang ini.']);
            }

            $lineGross = $amount->multipliedBy((string) $price->price)->toScale(0, RoundingMode::HALF_UP);

            DB::table('sale_items')->insert([
                'id' => (string) Str::uuid(),
                'sale_id' => $sale->getKey(),
                'product_id' => $product->getKey(),
                'name_snapshot' => $product->name,
                'sku_snapshot' => $product->sku,
                'quantity' => (string) $amount->toScale(3, RoundingMode::UNNECESSARY),
                'unit_price' => (string) BigDecimal::of((string) $price->price)->toScale(2, RoundingMode::UNNECESSARY),
                'discount_type' => 'AMOUNT',
                'discount_value' => '0.00',
                'line_gross' => (string) $lineGross->toScale(2, RoundingMode::UNNECESSARY),
                'line_discount' => '0.00',
                'line_total' => (string) $lineGross->toScale(2, RoundingMode::UNNECESSARY),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->recalculate($sale, $actor);
        });
    }

    public function applyDiscount(
        User $actor,
        string $saleId,
        string $saleItemId,
        string $type,
        string $value,
        ?string $ownerPassword = null,
        ?string $overrideReason = null,
    ): Sale {
        $sale = $this->findSale($saleId, $actor);
        $this->authorize($actor, $sale->branch_id);

        if (! $this->accessControl->can($actor, Permission::PosDiscountApply, $sale->branch_id)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin diskon POS.');
        }

        $type = strtoupper(trim($type));

        if (! in_array($type, ['PERCENT', 'AMOUNT'], true)) {
            throw ValidationException::withMessages(['discount_type' => 'Jenis diskon tidak valid.']);
        }

        $discountValue = BigDecimal::of($value);

        if ($discountValue->isNegative()) {
            throw ValidationException::withMessages(['discount_value' => 'Diskon tidak boleh negatif.']);
        }

        return Transactions::run(function () use ($actor, $saleId, $saleItemId, $type, $discountValue, $ownerPassword, $overrideReason): Sale {
            $sale = Sale::query()->whereKey($saleId)->lockForUpdate()->firstOrFail();
            $this->assertDraftOwner($actor, $sale);
            $item = DB::table('sale_items')
                ->where('id', $saleItemId)
                ->where('sale_id', $sale->getKey())
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                throw (new ModelNotFoundException)->setModel('SaleItem', [$saleItemId]);
            }

            if (($type === 'PERCENT' && $discountValue->compareTo(100) > 0)
                || ($type === 'AMOUNT' && $discountValue->stripTrailingZeros()->getScale() > 0)) {
                throw ValidationException::withMessages(['discount_value' => 'Nilai diskon tidak valid.']);
            }

            $gross = BigDecimal::of((string) $item->line_gross);
            $discount = $type === 'PERCENT'
                ? $gross->multipliedBy($discountValue)->dividedBy(100, 0, RoundingMode::HALF_UP)
                : $discountValue->toScale(0, RoundingMode::UNNECESSARY);

            if ($discount->compareTo($gross) > 0) {
                throw ValidationException::withMessages(['discount_value' => 'Diskon tidak boleh melebihi nilai kotor baris.']);
            }

            $requiresOverride = $discount->multipliedBy(100)->compareTo($gross->multipliedBy(10)) > 0;
            $overrideBy = null;
            $overrideAt = null;
            $storedReason = null;

            if ($requiresOverride) {
                if (! $this->accessControl->can($actor, Permission::PosDiscountOverride, $sale->branch_id)
                    || ! Hash::check((string) $ownerPassword, $actor->getAuthPassword())
                    || trim((string) $overrideReason) === '') {
                    throw new AuthorizationException('Diskon di atas 10% memerlukan kata sandi dan alasan pemilik.');
                }

                $overrideBy = $actor->getKey();
                $overrideAt = now();
                $storedReason = trim($overrideReason);
            }

            DB::table('sale_items')->where('id', $item->id)->update([
                'discount_type' => $type,
                'discount_value' => (string) $discountValue->toScale(2, RoundingMode::HALF_UP),
                'line_discount' => (string) $discount->toScale(2, RoundingMode::UNNECESSARY),
                'line_total' => (string) $gross->minus($discount)->toScale(2, RoundingMode::UNNECESSARY),
                'discount_override_by' => $overrideBy,
                'discount_override_at' => $overrideAt,
                'discount_override_reason' => $storedReason,
                'updated_at' => now(),
            ]);

            $this->auditLogger->record($actor, 'pos.discount.applied', $sale, $sale->branch_id, [
                'sale_item_id' => $item->id,
                'discount_type' => $type,
                'discount_value' => (string) $discountValue,
                'line_discount' => (string) $discount,
                'override' => $requiresOverride,
                'reason' => $storedReason,
            ]);

            return $this->recalculate($sale, $actor);
        });
    }

    /** @param list<array{method_code: string, amount: string, tendered_amount?: string}> $payments */
    public function complete(User $actor, string $saleId, array $payments, string $idempotencyKey): Sale
    {
        $sale = $this->findSale($saleId, $actor);
        $this->authorize($actor, $sale->branch_id);

        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempotensi wajib diisi maksimal 128 karakter.']);
        }

        if ($payments === []) {
            throw ValidationException::withMessages(['payments' => 'Minimal satu metode pembayaran harus dipilih.']);
        }

        usort($payments, fn (array $left, array $right): int => $left['method_code'] <=> $right['method_code']);
        $payloadHash = IdempotencyGuard::hashPayload([
            'sale_id' => $saleId,
            'payments' => $payments,
        ]);

        return $this->idempotencyGuard->run($actor, self::OPERATION, $idempotencyKey, $payloadHash, function () use ($actor, $saleId, $payments): Sale {
            $sale = Sale::query()->whereKey($saleId)->lockForUpdate()->firstOrFail();

            $this->assertDraftOwner($actor, $sale);
            $shift = CashShift::query()->whereKey($sale->shift_id)->lockForUpdate()->firstOrFail();

            if ($shift->status !== 'OPEN' || (string) $shift->cashier_id !== (string) $actor->getKey()) {
                throw ValidationException::withMessages(['shift' => 'Shift kasir sudah ditutup atau bukan milik kasir ini.']);
            }

            $items = DB::table('sale_items')->where('sale_id', $sale->getKey())->orderBy('product_id')->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Penjualan harus memiliki minimal satu produk.']);
            }

            $this->refreshLinePrices($sale, $items);
            $items = DB::table('sale_items')->where('sale_id', $sale->getKey())->orderBy('product_id')->orderBy('id')->get();
            $sale = $this->recalculate($sale, $actor);
            $paymentRows = $this->validatePayments($sale, $payments);
            $this->allocateStock($actor, $sale, $items);
            $this->recordPayments($actor, $sale, $shift, $paymentRows);

            $branchTimezone = DB::table('branches')->where('id', $sale->branch_id)->value('timezone');
            $invoiceNumber = $this->documentNumbers->next(
                $sale->branch_id,
                'POS',
                now()->timezone($branchTimezone)->year,
            );
            $sale->forceFill([
                'status' => 'COMPLETED',
                'invoice_number' => $invoiceNumber,
                'completed_by' => $actor->getKey(),
                'completed_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->auditLogger->record($actor, 'pos.sale.completed', $sale, $sale->branch_id, [
                'invoice_number' => $invoiceNumber,
                'grand_total' => $sale->grand_total,
            ]);

            return $sale->refresh();
        });
    }

    private function refreshLinePrices(Sale $sale, iterable $items): void
    {
        foreach ($items as $item) {
            $product = Product::query()->whereKey($item->product_id)->first();
            $settings = ProductBranchSetting::query()
                ->where('product_id', $item->product_id)
                ->where('branch_id', $sale->branch_id)
                ->where('is_active', true)
                ->where('is_pos_sellable', true)
                ->first();

            if ($product === null || ! $product->is_active || $product->is_prescription || $settings === null) {
                throw ValidationException::withMessages(['product' => 'Produk tidak lagi tersedia untuk dijual di cabang ini.']);
            }

            $price = DB::table('product_prices')
                ->where('product_id', $item->product_id)
                ->where('branch_id', $sale->branch_id)
                ->where('effective_from', '<=', now())
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->value('price');

            if ($price === null) {
                throw ValidationException::withMessages(['price' => 'Harga produk belum diatur untuk cabang ini.']);
            }

            $quantity = BigDecimal::of((string) $item->quantity);

            if ($product->unit_type === ProductUnitType::Count && $quantity->stripTrailingZeros()->getScale() > 0) {
                throw ValidationException::withMessages(['quantity' => 'Produk satuan hitung harus memakai jumlah bulat.']);
            }

            $gross = $quantity->multipliedBy((string) $price)->toScale(0, RoundingMode::HALF_UP);
            $discountValue = BigDecimal::of((string) $item->discount_value);
            $discount = $item->discount_type === 'PERCENT'
                ? $gross->multipliedBy($discountValue)->dividedBy(100, 0, RoundingMode::HALF_UP)
                : $discountValue->toScale(0, RoundingMode::UNNECESSARY);

            if ($discount->compareTo($gross) > 0) {
                throw ValidationException::withMessages(['discount' => 'Diskon melebihi harga produk terbaru.']);
            }

            if ($discount->multipliedBy(100)->compareTo($gross->multipliedBy(10)) > 0
                && $item->discount_override_by === null) {
                throw ValidationException::withMessages(['discount' => 'Harga berubah sehingga diskon memerlukan persetujuan pemilik.']);
            }

            DB::table('sale_items')->where('id', $item->id)->update([
                'unit_price' => (string) BigDecimal::of((string) $price)->toScale(2, RoundingMode::UNNECESSARY),
                'line_gross' => (string) $gross->toScale(2, RoundingMode::UNNECESSARY),
                'line_discount' => (string) $discount->toScale(2, RoundingMode::UNNECESSARY),
                'line_total' => (string) $gross->minus($discount)->toScale(2, RoundingMode::UNNECESSARY),
                'updated_at' => now(),
            ]);
        }
    }

    /** @param list<array{method_code: string, amount: string, tendered_amount?: string}> $payments
     * @return list<array{method_code: string, amount: BigDecimal, tendered: BigDecimal, change: BigDecimal, is_cash: bool}>
     */
    private function validatePayments(Sale $sale, array $payments): array
    {
        $rows = [];
        $totalPaid = BigDecimal::zero();
        $seenMethods = [];

        foreach ($payments as $payment) {
            $methodCode = strtoupper(trim($payment['method_code']));

            if (isset($seenMethods[$methodCode])) {
                throw ValidationException::withMessages(['payments' => 'Setiap metode pembayaran hanya boleh dimasukkan satu kali.']);
            }

            $seenMethods[$methodCode] = true;
            $method = DB::table('branch_payment_methods')
                ->join('payment_methods', 'payment_methods.code', '=', 'branch_payment_methods.method_code')
                ->where('branch_payment_methods.branch_id', $sale->branch_id)
                ->where('branch_payment_methods.method_code', $methodCode)
                ->where('branch_payment_methods.is_active', true)
                ->where('payment_methods.is_active', true)
                ->first(['payment_methods.is_cash']);

            if ($method === null) {
                throw ValidationException::withMessages(['payments' => 'Metode pembayaran tidak aktif di cabang ini.']);
            }

            $amount = $this->wholeRupiah($payment['amount'], 'payments');
            $tendered = $this->wholeRupiah($payment['tendered_amount'] ?? $payment['amount'], 'payments');

            if (! $amount->isPositive() || $tendered->compareTo($amount) < 0) {
                throw ValidationException::withMessages(['payments' => 'Pembayaran harus positif dan uang diterima tidak boleh kurang.']);
            }

            $isCash = (bool) $method->is_cash;

            if (! $isCash && $tendered->compareTo($amount) !== 0) {
                throw ValidationException::withMessages(['payments' => 'Pembayaran non-tunai harus sama dengan nilai alokasi.']);
            }

            $change = $isCash ? $tendered->minus($amount) : BigDecimal::zero();
            $totalPaid = $totalPaid->plus($amount);
            $rows[] = [
                'method_code' => $methodCode,
                'amount' => $amount,
                'tendered' => $tendered,
                'change' => $change,
                'is_cash' => $isCash,
            ];
        }

        if ($totalPaid->compareTo((string) $sale->grand_total) !== 0) {
            throw ValidationException::withMessages(['payments' => 'Jumlah pembayaran harus sama dengan total penjualan.']);
        }

        return $rows;
    }

    /** @param iterable<object> $items */
    private function allocateStock(User $actor, Sale $sale, iterable $items): void
    {
        StockWriteGuard::enable();
        $branch = DB::table('branches')->where('id', $sale->branch_id)->first();
        $location = InventoryLocation::query()
            ->where('branch_id', $sale->branch_id)
            ->where('type', 'RETAIL')
            ->where('is_active', true)
            ->first();

        if ($branch === null || $location === null) {
            throw ValidationException::withMessages(['stock' => 'Lokasi stok retail belum tersedia di cabang ini.']);
        }

        $productIds = collect($items)->pluck('product_id')->unique()->sort()->values();
        $balances = DB::table('stock_balances')
            ->where('location_id', $location->getKey())
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->orderBy('location_id')
            ->orderBy('lot_id')
            ->lockForUpdate()
            ->get();
        $lots = InventoryLot::query()->whereIn('id', $balances->pluck('lot_id'))->get()->keyBy('id');
        $costs = ProductBranchCost::query()
            ->where('branch_id', $sale->branch_id)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');
        $today = Carbon::now($branch->timezone)->toDateString();

        foreach ($items as $item) {
            $cost = $costs->get($item->product_id);

            if ($cost === null) {
                throw ValidationException::withMessages(['stock' => 'Biaya rata-rata produk belum tersedia.']);
            }

            $candidateBalances = $balances->where('product_id', $item->product_id)->values()->all();
            usort($candidateBalances, function (object $left, object $right) use ($lots): int {
                $leftLot = $lots->get($left->lot_id);
                $rightLot = $lots->get($right->lot_id);
                $leftExpiry = $leftLot->expiry_date ?? '9999-12-31';
                $rightExpiry = $rightLot->expiry_date ?? '9999-12-31';

                return [$leftExpiry, $leftLot->received_at ?? '', $left->id]
                    <=> [$rightExpiry, $rightLot->received_at ?? '', $right->id];
            });

            $remaining = BigDecimal::of((string) $item->quantity);
            $lineAllocation = [];

            foreach ($candidateBalances as $balance) {
                $lot = $lots->get($balance->lot_id);

                if ($lot === null || ($lot->expiry_date !== null && $lot->expiry_date < $today)) {
                    continue;
                }

                $available = BigDecimal::of((string) $balance->on_hand)->minus((string) $balance->reserved);

                if (! $available->isPositive()) {
                    continue;
                }

                $allocated = $available->compareTo($remaining) < 0 ? $available : $remaining;
                $newOnHand = BigDecimal::of((string) $balance->on_hand)->minus($allocated);

                DB::table('stock_balances')->where('id', $balance->id)->update([
                    'on_hand' => (string) $newOnHand->toScale(3, RoundingMode::UNNECESSARY),
                    'updated_at' => now(),
                ]);
                DB::table('sale_item_lots')->insert([
                    'id' => (string) Str::uuid(),
                    'sale_item_id' => $item->id,
                    'lot_id' => $lot->getKey(),
                    'quantity' => (string) $allocated->toScale(3, RoundingMode::UNNECESSARY),
                    'created_at' => now(),
                ]);
                InventoryMovement::query()->create([
                    'product_id' => $item->product_id,
                    'location_id' => $location->getKey(),
                    'lot_id' => $lot->getKey(),
                    'quantity_delta' => (string) $allocated->negated()->toScale(3, RoundingMode::UNNECESSARY),
                    'movement_type' => 'SALE_POS',
                    'unit_cost_snapshot' => (string) BigDecimal::of($cost->avg_unit_cost)->toScale(4, RoundingMode::HALF_UP),
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->getKey(),
                    'reason_code' => null,
                    'actor_id' => $actor->getKey(),
                    'occurred_at' => now(),
                ]);

                $lineAllocation[] = (string) $allocated;
                $remaining = $remaining->minus($allocated);

                if ($remaining->isZero()) {
                    break;
                }
            }

            if ($remaining->isPositive()) {
                throw ValidationException::withMessages([
                    'stock' => sprintf('Stok tidak mencukupi untuk produk %s.', $item->sku_snapshot),
                ]);
            }

            DB::table('sale_items')->where('id', $item->id)->update([
                'unit_cost_snapshot' => (string) BigDecimal::of($cost->avg_unit_cost)->toScale(4, RoundingMode::HALF_UP),
                'updated_at' => now(),
            ]);
        }
    }

    /** @param list<array{method_code: string, amount: BigDecimal, tendered: BigDecimal, change: BigDecimal, is_cash: bool}> $payments */
    private function recordPayments(User $actor, Sale $sale, CashShift $shift, array $payments): void
    {
        foreach ($payments as $payment) {
            $salePaymentId = (string) Str::uuid();
            DB::table('sale_payments')->insert([
                'id' => $salePaymentId,
                'sale_id' => $sale->getKey(),
                'method_code' => $payment['method_code'],
                'amount' => (string) $payment['amount']->toScale(2, RoundingMode::UNNECESSARY),
                'tendered_amount' => (string) $payment['tendered']->toScale(2, RoundingMode::UNNECESSARY),
                'change_amount' => (string) $payment['change']->toScale(2, RoundingMode::UNNECESSARY),
                'recorded_by' => $actor->getKey(),
                'created_at' => now(),
            ]);

            if (! $payment['is_cash']) {
                continue;
            }

            $this->recordCashMovement($actor, $sale, $shift, 'CASH_IN_SALE', $payment['amount'], 'IN');

            if ($payment['change']->isPositive()) {
                $this->recordCashMovement($actor, $sale, $shift, 'CASH_OUT_CHANGE', $payment['change'], 'OUT');
            }
        }
    }

    private function recordCashMovement(
        User $actor,
        Sale $sale,
        CashShift $shift,
        string $type,
        BigDecimal $amount,
        string $direction,
    ): void {
        DB::table('cash_movements')->insert([
            'id' => (string) Str::uuid(),
            'branch_id' => $sale->branch_id,
            'shift_id' => $shift->getKey(),
            'movement_type' => $type,
            'method_code' => 'CASH',
            'amount' => (string) $amount->toScale(2, RoundingMode::UNNECESSARY),
            'direction' => $direction,
            'reference_type' => Sale::class,
            'reference_id' => $sale->getKey(),
            'actor_id' => $actor->getKey(),
            'occurred_at' => now(),
        ]);
    }

    private function recalculate(Sale $sale, User $actor): Sale
    {
        $items = DB::table('sale_items')->where('sale_id', $sale->getKey())->get(['line_gross', 'line_discount', 'line_total']);
        $subtotal = BigDecimal::zero();
        $discount = BigDecimal::zero();
        $grandTotal = BigDecimal::zero();

        foreach ($items as $item) {
            $subtotal = $subtotal->plus((string) $item->line_gross);
            $discount = $discount->plus((string) $item->line_discount);
            $grandTotal = $grandTotal->plus((string) $item->line_total);
        }

        $sale->forceFill([
            'subtotal' => (string) $subtotal->toScale(2, RoundingMode::UNNECESSARY),
            'discount_total' => (string) $discount->toScale(2, RoundingMode::UNNECESSARY),
            'grand_total' => (string) $grandTotal->toScale(2, RoundingMode::UNNECESSARY),
            'updated_by' => $actor->getKey(),
        ])->save();

        return $sale;
    }

    private function wholeRupiah(string $amount, string $field): BigDecimal
    {
        $value = BigDecimal::of($amount);

        if ($value->isNegative() || $value->stripTrailingZeros()->getScale() > 0) {
            throw ValidationException::withMessages([$field => 'Nilai harus rupiah penuh dan tidak boleh negatif.']);
        }

        return $value->toScale(2, RoundingMode::UNNECESSARY);
    }

    private function findSale(string $saleId, ?User $actor = null): Sale
    {
        $sale = Sale::query()->find($saleId);

        if ($sale === null) {
            throw (new ModelNotFoundException)->setModel(Sale::class, [$saleId]);
        }

        if ($actor !== null) {
            $branchId = (string) $sale->branch_id;
            if ($actor->role !== Role::Owner && ! DB::table('user_branch_access')
                ->where('user_id', $actor->getKey())
                ->where('branch_id', $branchId)
                ->exists()) {
                throw (new ModelNotFoundException)->setModel(Sale::class, [$saleId]);
            }
        }

        return $sale;
    }

    private function authorize(User $actor, string $branchId): void
    {
        if (! $this->accessControl->can($actor, Permission::PosSell, $branchId)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin POS untuk cabang ini.');
        }
    }

    private function assertDraftOwner(User $actor, Sale $sale): void
    {
        if ($sale->status !== 'DRAFT' || (string) $sale->created_by !== (string) $actor->getKey()) {
            throw new AuthorizationException('Draft hanya dapat diubah kasir pembuatnya.');
        }
    }
}
