<?php

declare(strict_types=1);

namespace App\Services\Clinic;

use App\Domain\MedicalRecordStatus;
use App\Domain\Permission;
use App\Domain\VisitStatus;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\MedicalRecordAmendment;
use App\Models\Pet;
use App\Models\User;
use App\Models\Visit;
use App\Services\Shared\AuditLogger;
use App\Services\Shared\Transactions;
use App\Support\AccessControl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MedicalRecordService
{
    private const CONTENT_FIELDS = [
        'chief_complaint',
        'history',
        'physical_exam',
        'diagnosis',
        'treatment_plan',
        'follow_up_date',
        'clinical_data',
        'internal_note',
    ];

    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function startVisit(
        User $actor,
        string $petId,
        string $branchId,
        string $consentGivenBy,
        string $consentMethod,
        ?string $appointmentId = null,
    ): Visit {
        $context = new Visit(['doctor_id' => $actor->getKey(), 'branch_id' => $branchId]);

        if (! $this->accessControl->can($actor, Permission::VisitManage, $branchId, $context)) {
            throw new AuthorizationException('Hanya dokter yang ditugaskan dan berwenang dapat membuka kunjungan.');
        }

        if (trim($consentGivenBy) === '' || ! in_array($consentMethod, ['IN_PERSON', 'PHONE'], true)) {
            throw ValidationException::withMessages(['consent' => 'Nama pemberi persetujuan dan metode persetujuan wajib diisi.']);
        }

        return Transactions::run(function () use ($actor, $petId, $branchId, $consentGivenBy, $consentMethod, $appointmentId): Visit {
            $pet = Pet::query()->whereKey($petId)->lockForUpdate()->first();

            if ($pet === null) {
                throw (new ModelNotFoundException)->setModel(Pet::class, [$petId]);
            }

            if ($pet->is_deceased) {
                throw ValidationException::withMessages(['pet' => 'Hewan yang telah meninggal tidak dapat membuka kunjungan baru.']);
            }

            $appointment = null;

            if ($appointmentId !== null) {
                $appointment = Appointment::query()->whereKey($appointmentId)->lockForUpdate()->first();

                if ($appointment === null
                    || $appointment->status !== 'SCHEDULED'
                    || (string) $appointment->doctor_id !== (string) $actor->getKey()
                    || (string) $appointment->pet_id !== $petId
                    || (string) $appointment->branch_id !== $branchId) {
                    throw ValidationException::withMessages(['appointment' => 'Janji tidak aktif atau tidak ditugaskan ke dokter ini.']);
                }

                $appointment->forceFill(['status' => 'IN_CONSULTATION'])->save();
            }

            $visit = Visit::query()->create([
                'pet_id' => $petId,
                'branch_id' => $branchId,
                'doctor_id' => $actor->getKey(),
                'appointment_id' => $appointment?->getKey(),
                'status' => 'OPEN',
                'started_at' => now(),
                'consent_given_by_name' => trim($consentGivenBy),
                'consent_method' => $consentMethod,
                'created_by' => $actor->getKey(),
            ]);
            $this->auditLogger->record($actor, 'visit.started', $visit, $branchId, [
                'pet_id' => $petId,
                'appointment_id' => $appointment?->getKey(),
            ]);

            return $visit;
        });
    }

    /** @param array<string, mixed> $content */
    public function saveDraft(User $actor, string $visitId, array $content): MedicalRecord
    {
        $visit = $this->findVisit($visitId);
        $this->authorize($actor, Permission::MedicalRecordWrite, $visit);
        $this->validateContent($content);

        return Transactions::run(function () use ($actor, $visitId, $content): MedicalRecord {
            $visit = Visit::query()->whereKey($visitId)->lockForUpdate()->firstOrFail();

            if ($visit->status !== VisitStatus::Open) {
                throw ValidationException::withMessages(['visit' => 'Rekam medis hanya dapat ditulis saat kunjungan terbuka.']);
            }

            $record = MedicalRecord::query()->where('visit_id', $visitId)->lockForUpdate()->first();

            if ($record !== null && $record->status !== MedicalRecordStatus::Draft) {
                throw ValidationException::withMessages(['medical_record' => 'Rekam medis yang sudah ditandatangani tidak dapat diubah.']);
            }

            $record ??= new MedicalRecord(['visit_id' => $visitId, 'status' => MedicalRecordStatus::Draft]);
            $record->fill($content)->save();
            $this->auditLogger->record($actor, 'medical_record.draft_saved', $record, $visit->branch_id);

            return $record;
        });
    }

    public function sign(User $actor, string $recordId): MedicalRecord
    {
        $record = $this->findRecord($recordId);
        $visit = $this->findVisit($record->visit_id);
        $this->authorize($actor, Permission::MedicalRecordSign, $visit);

        return Transactions::run(function () use ($actor, $recordId, $visit): MedicalRecord {
            $record = MedicalRecord::query()->whereKey($recordId)->lockForUpdate()->firstOrFail();

            if ($record->status !== MedicalRecordStatus::Draft) {
                throw ValidationException::withMessages(['medical_record' => 'Hanya draft yang dapat ditandatangani.']);
            }

            $profile = DB::table('doctor_profiles')
                ->where('user_id', $actor->getKey())
                ->where('is_active', true)
                ->first();

            if ($profile === null) {
                throw ValidationException::withMessages(['doctor' => 'Profil dokter aktif wajib untuk menandatangani rekam medis.']);
            }

            $record->forceFill([
                'status' => MedicalRecordStatus::Signed,
                'signed_by' => $actor->getKey(),
                'signed_at' => now(),
                'doctor_license_snapshot' => $profile->license_number,
            ])->save();
            $this->auditLogger->record($actor, 'medical_record.signed', $record, $visit->branch_id, [
                'doctor_license_snapshot' => $profile->license_number,
            ]);

            return $record;
        });
    }

    /** @param array<string, mixed> $changes */
    public function amend(User $actor, string $recordId, string $reason, array $changes): MedicalRecordAmendment
    {
        $record = $this->findRecord($recordId);
        $this->authorize($actor, Permission::MedicalRecordAmend, $record);
        $this->validateContent($changes);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan amendemen wajib diisi.']);
        }

        return Transactions::run(function () use ($actor, $recordId, $reason, $changes): MedicalRecordAmendment {
            $record = MedicalRecord::query()->whereKey($recordId)->lockForUpdate()->firstOrFail();

            if (! in_array($record->status, [MedicalRecordStatus::Signed, MedicalRecordStatus::Amended], true)
                || (string) $record->signed_by !== (string) $actor->getKey()) {
                throw new AuthorizationException('Hanya dokter penanda asli yang dapat mengamendemen rekam medis ini.');
            }

            $previous = $this->latestContent($record);
            $next = array_replace($previous, $changes);
            $amendment = MedicalRecordAmendment::query()->create([
                'medical_record_id' => $record->getKey(),
                'reason' => trim($reason),
                'previous_content' => $previous,
                'new_content' => $next,
                'amended_by' => $actor->getKey(),
                'amended_at' => now(),
            ]);

            $record->forceFill(['status' => MedicalRecordStatus::Amended])->save();
            $visit = $this->findVisit($record->visit_id);
            $this->auditLogger->record($actor, 'medical_record.amended', $amendment, $visit->branch_id, [
                'medical_record_id' => $record->getKey(),
                'reason' => trim($reason),
            ]);

            return $amendment;
        });
    }

    /** @return array<string, mixed> */
    private function latestContent(MedicalRecord $record): array
    {
        $latestAmendment = MedicalRecordAmendment::query()
            ->where('medical_record_id', $record->getKey())
            ->orderByDesc('amended_at')
            ->orderByDesc('id')
            ->first();

        if ($latestAmendment !== null) {
            return $latestAmendment->new_content;
        }

        $content = [];

        foreach (self::CONTENT_FIELDS as $field) {
            $content[$field] = $record->getAttribute($field);
        }

        return $content;
    }

    /** @param array<string, mixed> $content */
    private function validateContent(array $content): void
    {
        $unknown = array_diff(array_keys($content), self::CONTENT_FIELDS);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['content' => 'Field rekam medis tidak dikenal.']);
        }

        if (isset($content['follow_up_date']) && $content['follow_up_date'] !== null) {
            try {
                Carbon::parse($content['follow_up_date']);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['follow_up_date' => 'Tanggal kontrol tidak valid.']);
            }
        }

        foreach (['clinical_data'] as $field) {
            if (isset($content[$field]) && ! is_array($content[$field])) {
                throw ValidationException::withMessages([$field => 'Data klinis tambahan harus berbentuk objek.']);
            }
        }

        foreach (array_diff(self::CONTENT_FIELDS, ['clinical_data', 'follow_up_date']) as $field) {
            if (isset($content[$field]) && ! is_string($content[$field])) {
                throw ValidationException::withMessages([$field => 'Isi rekam medis harus berupa teks.']);
            }
        }
    }

    private function findVisit(string $visitId): Visit
    {
        $visit = Visit::query()->find($visitId);

        if ($visit === null) {
            throw (new ModelNotFoundException)->setModel(Visit::class, [$visitId]);
        }

        return $visit;
    }

    private function findRecord(string $recordId): MedicalRecord
    {
        $record = MedicalRecord::query()->find($recordId);

        if ($record === null) {
            throw (new ModelNotFoundException)->setModel(MedicalRecord::class, [$recordId]);
        }

        return $record;
    }

    private function authorize(User $actor, Permission $permission, Visit|MedicalRecord $object): void
    {
        if ($object instanceof MedicalRecord) {
            $visit = $this->findVisit($object->visit_id);
            $context = $object;
            $branchId = $visit->branch_id;
        } else {
            $context = $object;
            $branchId = $object->branch_id;
        }

        if (! $this->accessControl->can($actor, $permission, $branchId, $context)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin klinis untuk rekam medis ini.');
        }
    }
}
