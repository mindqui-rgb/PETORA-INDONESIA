<?php

declare(strict_types=1);

use App\Domain\PetSpecies;
use App\Domain\Role;
use App\Models\User;
use App\Services\Clinic\MedicalRecordService;
use App\Services\Shared\CustomerPetService;
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

    $this->doctor = User::factory()->create();
    $this->doctor->forceFill(['role' => Role::Doctor, 'mfa_enabled' => true])->save();
    $this->withSession(['mfa_verified_at' => now()->toIso8601String()]);
    DB::table('doctor_profiles')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $this->doctor->getKey(),
        'license_number' => 'STR-UTM-002',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('user_branch_access')->insert([
        'user_id' => $this->doctor->getKey(),
        'branch_id' => $this->branchId,
    ]);

    $customerService = app(CustomerPetService::class);
    $this->customer = $customerService->createCustomer($this->owner, $this->branchId, ['name' => 'Pemilik Hewan']);
    $this->pet = $customerService->createPet($this->owner, $this->branchId, $this->customer->getKey(), [
        'name' => 'Milo',
        'species' => PetSpecies::Cat,
    ]);
    $this->medical = app(MedicalRecordService::class);
});

it('signs a medical record and appends amendments by the original doctor', function () {
    $visit = $this->medical->startVisit(
        $this->doctor,
        $this->pet->getKey(),
        $this->branchId,
        'Pemilik Hewan',
        'IN_PERSON',
    );
    $draft = $this->medical->saveDraft($this->doctor, $visit->getKey(), [
        'chief_complaint' => 'Nafsu makan menurun',
        'history' => 'Sejak kemarin',
        'physical_exam' => 'Suhu normal',
        'diagnosis' => 'Observasi',
        'treatment_plan' => 'Kontrol kembali',
        'clinical_data' => ['hydration' => 'normal'],
        'internal_note' => 'Catatan internal klinik',
    ]);

    $signed = $this->medical->sign($this->doctor, $draft->getKey());

    expect($signed->status->value)->toBe('SIGNED')
        ->and($signed->signed_by)->toBe($this->doctor->getKey())
        ->and($signed->doctor_license_snapshot)->toBe('STR-UTM-002');

    expect(fn () => $this->medical->saveDraft($this->doctor, $visit->getKey(), [
        'diagnosis' => 'Tidak boleh diedit setelah tanda tangan',
    ]))->toThrow(ValidationException::class);

    $amendment = $this->medical->amend($this->doctor, $signed->getKey(), 'Hasil laboratorium diterima', [
        'diagnosis' => 'Diagnosis diperbarui',
        'treatment_plan' => 'Terapi lanjutan',
    ]);

    expect($amendment->previous_content['diagnosis'])->toBe('Observasi')
        ->and($amendment->new_content['diagnosis'])->toBe('Diagnosis diperbarui')
        ->and(DB::table('medical_records')->where('id', $signed->getKey())->value('status'))->toBe('AMENDED')
        ->and(DB::table('medical_records')->where('id', $signed->getKey())->value('diagnosis'))->toBe('Observasi')
        ->and(DB::table('medical_record_amendments')->where('medical_record_id', $signed->getKey())->count())->toBe(1);
});

it('rejects amendment by a doctor other than the original signer', function () {
    $visit = $this->medical->startVisit(
        $this->doctor,
        $this->pet->getKey(),
        $this->branchId,
        'Pemilik Hewan',
        'PHONE',
    );
    $draft = $this->medical->saveDraft($this->doctor, $visit->getKey(), ['diagnosis' => 'Awal']);
    $signed = $this->medical->sign($this->doctor, $draft->getKey());

    $otherDoctor = User::factory()->create();
    $otherDoctor->forceFill(['role' => Role::Doctor, 'mfa_enabled' => true])->save();
    DB::table('doctor_profiles')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $otherDoctor->getKey(),
        'license_number' => 'STR-UTM-003',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('user_branch_access')->insert([
        'user_id' => $otherDoctor->getKey(),
        'branch_id' => $this->branchId,
    ]);

    expect(fn () => $this->medical->amend($otherDoctor, $signed->getKey(), 'Koreksi', ['diagnosis' => 'Lain']))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('medical_record_amendments')->count())->toBe(0)
        ->and(DB::table('medical_records')->where('id', $signed->getKey())->value('status'))->toBe('SIGNED');
});

it('requires a consent record before opening a visit', function () {
    expect(fn () => $this->medical->startVisit(
        $this->doctor,
        $this->pet->getKey(),
        $this->branchId,
        '',
        'IN_PERSON',
    ))->toThrow(ValidationException::class);

    expect(DB::table('visits')->count())->toBe(0);
});
