<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Domain\Permission;
use App\Models\CashShift;
use App\Models\User;
use App\Services\Shared\AuditLogger;
use App\Services\Shared\Transactions;
use App\Support\AccessControl;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CashShiftService
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function open(User $actor, string $branchId, string $openingCash): CashShift
    {
        $this->authorize($actor, Permission::CashShiftManage, $branchId);
        $opening = $this->wholeRupiah($openingCash, 'opening_cash');

        return Transactions::run(function () use ($actor, $branchId, $opening): CashShift {
            $branch = DB::table('branches')->where('id', $branchId)->lockForUpdate()->first();

            if ($branch === null) {
                throw (new ModelNotFoundException)->setModel('Branch', [$branchId]);
            }

            if (! $branch->is_active || ! $branch->pos_enabled) {
                throw ValidationException::withMessages(['branch' => 'POS tidak aktif di cabang ini.']);
            }

            $openShift = CashShift::query()
                ->where('cashier_id', $actor->getKey())
                ->where('status', 'OPEN')
                ->lockForUpdate()
                ->exists();

            if ($openShift) {
                throw ValidationException::withMessages(['shift' => 'Kasir sudah memiliki shift yang terbuka.']);
            }

            $shift = CashShift::query()->create([
                'branch_id' => $branchId,
                'cashier_id' => $actor->getKey(),
                'status' => 'OPEN',
                'opening_cash' => (string) $opening,
                'created_by' => $actor->getKey(),
                'opened_at' => now(),
            ]);

            if ($opening->isPositive()) {
                $this->recordCashMovement(
                    $actor,
                    $branchId,
                    $shift->getKey(),
                    'SHIFT_OPENING',
                    $opening,
                    'IN',
                    $shift::class,
                    $shift->getKey(),
                );
            }

            $this->auditLogger->record($actor, 'cash_shift.opened', $shift, $branchId, [
                'opening_cash' => (string) $opening,
            ]);

            return $shift;
        }, 'cash_shift.open');
    }

    public function close(
        User $actor,
        string $shiftId,
        string $closingCashCounted,
        ?string $differenceReason = null,
    ): CashShift {
        $shift = CashShift::query()->find($shiftId);

        if ($shift === null) {
            throw (new ModelNotFoundException)->setModel(CashShift::class, [$shiftId]);
        }

        $this->authorize($actor, Permission::CashShiftManage, $shift->branch_id);
        $counted = $this->wholeRupiah($closingCashCounted, 'closing_cash_counted');

        return Transactions::run(function () use ($actor, $shiftId, $counted, $differenceReason): CashShift {
            $shift = CashShift::query()->whereKey($shiftId)->lockForUpdate()->firstOrFail();

            if ($shift->status !== 'OPEN') {
                throw ValidationException::withMessages(['shift' => 'Shift ini sudah ditutup.']);
            }

            $movements = DB::table('cash_movements')
                ->where('shift_id', $shift->getKey())
                ->where('method_code', 'CASH')
                ->get(['amount', 'direction']);
            $expected = $movements->reduce(
                fn (BigDecimal $total, object $movement): BigDecimal => $movement->direction === 'IN'
                    ? $total->plus((string) $movement->amount)
                    : $total->minus((string) $movement->amount),
                BigDecimal::zero(),
            );
            $difference = $counted->minus($expected);

            if (! $difference->isZero() && trim((string) $differenceReason) === '') {
                throw ValidationException::withMessages(['difference_reason' => 'Alasan wajib diisi bila kas berbeda.']);
            }

            $shift->forceFill([
                'status' => 'CLOSED',
                'closing_cash_counted' => (string) $counted,
                'expected_cash' => (string) $expected,
                'difference' => (string) $difference,
                'difference_reason' => $differenceReason,
                'closed_by' => $actor->getKey(),
                'closed_at' => now(),
            ])->save();

            if (! $difference->isZero()) {
                $this->recordCashMovement(
                    $actor,
                    $shift->branch_id,
                    $shift->getKey(),
                    'SHIFT_CLOSING_DIFFERENCE',
                    $difference->abs(),
                    $difference->isPositive() ? 'IN' : 'OUT',
                    $shift::class,
                    $shift->getKey(),
                );
            }

            $this->auditLogger->record($actor, 'cash_shift.closed', $shift, $shift->branch_id, [
                'expected_cash' => (string) $expected,
                'closing_cash_counted' => (string) $counted,
                'difference' => (string) $difference,
            ]);

            return $shift;
        }, 'cash_shift.close');
    }

    private function authorize(User $actor, Permission $permission, string $branchId): void
    {
        if (! $this->accessControl->can($actor, $permission, $branchId)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin kasir untuk cabang ini.');
        }
    }

    private function wholeRupiah(string $amount, string $field): BigDecimal
    {
        $value = BigDecimal::of($amount);

        if ($value->isNegative() || $value->stripTrailingZeros()->getScale() > 0) {
            throw ValidationException::withMessages([$field => 'Nilai harus rupiah penuh dan tidak boleh negatif.']);
        }

        return $value->toScale(2, RoundingMode::UNNECESSARY);
    }

    private function recordCashMovement(
        User $actor,
        string $branchId,
        ?string $shiftId,
        string $type,
        BigDecimal $amount,
        string $direction,
        string $referenceType,
        string $referenceId,
    ): void {
        DB::table('cash_movements')->insert([
            'id' => (string) Str::uuid(),
            'branch_id' => $branchId,
            'shift_id' => $shiftId,
            'movement_type' => $type,
            'method_code' => 'CASH',
            'amount' => (string) $amount->toScale(2, RoundingMode::UNNECESSARY),
            'direction' => $direction,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'actor_id' => $actor->getKey(),
            'occurred_at' => now(),
        ]);
    }
}
