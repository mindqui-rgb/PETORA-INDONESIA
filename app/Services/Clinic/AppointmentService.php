<?php

declare(strict_types=1);

namespace App\Services\Clinic;

use App\Domain\Permission;
use App\Domain\Role;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Pet;
use App\Models\User;
use App\Services\Shared\AuditLogger;
use App\Services\Shared\Transactions;
use App\Support\AccessControl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AppointmentService
{
    private const ACTIVE_STATUSES = ['SCHEDULED', 'CHECKED_IN', 'IN_CONSULTATION'];

    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function createDoctorSchedule(
        User $actor,
        string $doctorId,
        string $branchId,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        int $slotMinutes,
    ): string {
        $this->authorize($actor, Permission::ScheduleManage, $branchId);

        if ($dayOfWeek < 0 || $dayOfWeek > 6 || $slotMinutes < 1 || $endsAt <= $startsAt) {
            throw ValidationException::withMessages(['schedule' => 'Hari, jam, atau durasi slot tidak valid.']);
        }

        $doctorHasBranch = DB::table('user_branch_access')
            ->where('user_id', $doctorId)
            ->where('branch_id', $branchId)
            ->exists();
        $doctorIsClinician = DB::table('doctor_profiles')
            ->join('users', 'users.id', '=', 'doctor_profiles.user_id')
            ->where('doctor_profiles.user_id', $doctorId)
            ->where('doctor_profiles.is_active', true)
            ->whereIn('users.role', [Role::Doctor->value, Role::Owner->value])
            ->exists();

        if (! $doctorHasBranch || ! $doctorIsClinician) {
            throw ValidationException::withMessages(['doctor' => 'Dokter aktif tidak ditugaskan ke cabang ini.']);
        }

        $schedule = DoctorSchedule::query()->create([
            'doctor_id' => $doctorId,
            'branch_id' => $branchId,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'slot_minutes' => $slotMinutes,
            'is_active' => true,
            'created_by' => $actor->getKey(),
        ]);

        $this->auditLogger->record($actor, 'doctor_schedule.created', $schedule, $branchId, [
            'doctor_id' => $doctorId,
            'day_of_week' => $dayOfWeek,
        ]);

        return $schedule->getKey();
    }

    public function book(
        User $actor,
        string $petId,
        string $customerId,
        string $doctorId,
        string $branchId,
        string $startsAt,
        string $endsAt,
        ?string $reason = null,
    ): Appointment {
        $authorizationContext = new Appointment([
            'doctor_id' => $doctorId,
            'branch_id' => $branchId,
        ]);

        if (! $this->accessControl->can($actor, Permission::AppointmentManage, $branchId, $authorizationContext)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin membuat janji temu di cabang ini.');
        }

        $branch = DB::table('branches')->where('id', $branchId)->first();

        if ($branch === null || ! $branch->is_active) {
            throw (new ModelNotFoundException)->setModel('Branch', [$branchId]);
        }

        $start = Carbon::parse($startsAt, $branch->timezone);
        $end = Carbon::parse($endsAt, $branch->timezone);

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['starts_at' => 'Waktu selesai harus setelah waktu mulai.']);
        }

        return Transactions::run(function () use ($actor, $petId, $customerId, $doctorId, $branchId, $start, $end, $reason, $branch): Appointment {
            $profile = DB::table('doctor_profiles')
                ->where('user_id', $doctorId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                throw ValidationException::withMessages(['doctor' => 'Profil dokter tidak aktif.']);
            }

            $pet = Pet::query()->whereKey($petId)->first();

            if ($pet === null) {
                throw (new ModelNotFoundException)->setModel(Pet::class, [$petId]);
            }

            if ($pet->is_deceased) {
                throw ValidationException::withMessages(['pet' => 'Hewan yang telah meninggal tidak dapat menerima janji baru.']);
            }

            $isGuardian = DB::table('pet_guardians')
                ->where('pet_id', $petId)
                ->where('customer_id', $customerId)
                ->whereNull('ended_at')
                ->exists();

            if (! $isGuardian) {
                throw ValidationException::withMessages(['customer' => 'Pelanggan bukan wali aktif hewan ini.']);
            }

            $startLocal = $start->copy()->setTimezone($branch->timezone);
            $endLocal = $end->copy()->setTimezone($branch->timezone);
            $schedule = DB::table('doctor_schedules')
                ->where('doctor_id', $doctorId)
                ->where('branch_id', $branchId)
                ->where('day_of_week', $startLocal->dayOfWeek)
                ->where('is_active', true)
                ->where('starts_at', '<=', $startLocal->format('H:i:s'))
                ->where('ends_at', '>=', $endLocal->format('H:i:s'))
                ->first();

            if ($schedule === null) {
                throw ValidationException::withMessages(['starts_at' => 'Waktu janji berada di luar jadwal praktik dokter.']);
            }

            $overlaps = Appointment::query()
                ->where('doctor_id', $doctorId)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->where('starts_at', '<', $end->utc())
                ->where('ends_at', '>', $start->utc())
                ->lockForUpdate()
                ->exists();

            if ($overlaps) {
                throw ValidationException::withMessages(['starts_at' => 'Jadwal dokter bertabrakan dengan janji aktif lain.']);
            }

            $appointment = Appointment::query()->create([
                'pet_id' => $petId,
                'doctor_id' => $doctorId,
                'branch_id' => $branchId,
                'customer_id' => $customerId,
                'status' => 'SCHEDULED',
                'starts_at' => $start->utc(),
                'ends_at' => $end->utc(),
                'booked_by' => $actor->getKey(),
                'reason' => $reason,
            ]);
            $this->auditLogger->record($actor, 'appointment.created', $appointment, $branchId, [
                'pet_id' => $petId,
                'doctor_id' => $doctorId,
                'starts_at' => $start->utc()->toIso8601String(),
            ]);

            return $appointment;
        }, 'appointment.book');
    }

    public function cancel(User $actor, string $appointmentId, string $reason): Appointment
    {
        $appointment = Appointment::query()->find($appointmentId);

        if ($appointment === null) {
            throw (new ModelNotFoundException)->setModel(Appointment::class, [$appointmentId]);
        }

        $context = new Appointment([
            'doctor_id' => $appointment->doctor_id,
            'branch_id' => $appointment->branch_id,
        ]);

        if (! $this->accessControl->can($actor, Permission::AppointmentManage, $appointment->branch_id, $context)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin membatalkan janji ini.');
        }

        return Transactions::run(function () use ($actor, $appointmentId, $reason): Appointment {
            $appointment = Appointment::query()->whereKey($appointmentId)->lockForUpdate()->firstOrFail();

            if (! in_array($appointment->status, self::ACTIVE_STATUSES, true) || trim($reason) === '') {
                throw ValidationException::withMessages(['appointment' => 'Janji aktif dan alasan pembatalan wajib diisi.']);
            }

            $appointment->forceFill([
                'status' => 'CANCELLED',
                'cancelled_by' => $actor->getKey(),
                'cancellation_reason' => trim($reason),
                'cancelled_at' => now(),
            ])->save();
            $this->auditLogger->record($actor, 'appointment.cancelled', $appointment, $appointment->branch_id, [
                'reason' => trim($reason),
            ]);

            return $appointment;
        });
    }

    private function authorize(User $actor, Permission $permission, string $branchId): void
    {
        if (! $this->accessControl->can($actor, $permission, $branchId)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin jadwal dokter untuk cabang ini.');
        }
    }
}
