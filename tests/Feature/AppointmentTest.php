<?php

declare(strict_types=1);

use App\Domain\PetSpecies;
use App\Domain\Role;
use App\Models\User;
use App\Services\Clinic\AppointmentService;
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
    $this->doctor->forceFill(['role' => Role::Doctor])->save();
    DB::table('doctor_profiles')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $this->doctor->getKey(),
        'license_number' => 'STR-UTM-001',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('user_branch_access')->insert([
        'user_id' => $this->doctor->getKey(),
        'branch_id' => $this->branchId,
    ]);

    $customers = app(CustomerPetService::class);
    $this->customer = $customers->createCustomer($this->owner, $this->branchId, ['name' => 'Pemilik Milo']);
    $this->pet = $customers->createPet($this->owner, $this->branchId, $this->customer->getKey(), [
        'name' => 'Milo',
        'species' => PetSpecies::Cat,
    ]);
    $this->appointments = app(AppointmentService::class);
    $this->appointments->createDoctorSchedule(
        $this->owner,
        $this->doctor->getKey(),
        $this->branchId,
        1,
        '09:00:00',
        '12:00:00',
        30,
    );
});

it('rejects overlapping appointments and makes a cancelled slot available', function () {
    $appointment = $this->appointments->book(
        $this->owner,
        $this->pet->getKey(),
        $this->customer->getKey(),
        $this->doctor->getKey(),
        $this->branchId,
        '2026-10-26 10:00:00',
        '2026-10-26 10:30:00',
        'Pemeriksaan rutin',
    );

    expect(fn () => $this->appointments->book(
        $this->owner,
        $this->pet->getKey(),
        $this->customer->getKey(),
        $this->doctor->getKey(),
        $this->branchId,
        '2026-10-26 10:15:00',
        '2026-10-26 10:45:00',
    ))->toThrow(ValidationException::class);

    $this->appointments->cancel($this->owner, $appointment->getKey(), 'Pemilik meminta jadwal baru');
    $replacement = $this->appointments->book(
        $this->owner,
        $this->pet->getKey(),
        $this->customer->getKey(),
        $this->doctor->getKey(),
        $this->branchId,
        '2026-10-26 10:15:00',
        '2026-10-26 10:45:00',
    );

    expect($appointment->status)->toBe('SCHEDULED')
        ->and(DB::table('appointments')->where('id', $appointment->getKey())->value('status'))->toBe('CANCELLED')
        ->and($replacement->status)->toBe('SCHEDULED');
});

it('requires the appointment to fit a doctor schedule and use an active guardian', function () {
    expect(fn () => $this->appointments->book(
        $this->owner,
        $this->pet->getKey(),
        $this->customer->getKey(),
        $this->doctor->getKey(),
        $this->branchId,
        '2026-10-26 12:00:00',
        '2026-10-26 12:30:00',
    ))->toThrow(ValidationException::class);

    $otherCustomer = app(CustomerPetService::class)->createCustomer($this->owner, $this->branchId, ['name' => 'Bukan wali']);

    expect(fn () => $this->appointments->book(
        $this->owner,
        $this->pet->getKey(),
        $otherCustomer->getKey(),
        $this->doctor->getKey(),
        $this->branchId,
        '2026-10-26 11:00:00',
        '2026-10-26 11:30:00',
    ))->toThrow(ValidationException::class);
});

it('does not book appointments for deceased pets and restricts doctors to their own assignment', function () {
    app(CustomerPetService::class)->markDeceased($this->owner, $this->branchId, $this->pet->getKey());

    expect(fn () => $this->appointments->book(
        $this->owner,
        $this->pet->getKey(),
        $this->customer->getKey(),
        $this->doctor->getKey(),
        $this->branchId,
        '2026-10-26 11:00:00',
        '2026-10-26 11:30:00',
    ))->toThrow(ValidationException::class);

    $activeCustomer = app(CustomerPetService::class)->createCustomer($this->owner, $this->branchId, ['name' => 'Pemilik Lain']);
    $activePet = app(CustomerPetService::class)->createPet($this->owner, $this->branchId, $activeCustomer->getKey(), [
        'name' => 'Oyen',
        'species' => PetSpecies::Cat,
    ]);

    expect(fn () => $this->appointments->book(
        $this->doctor,
        $activePet->getKey(),
        $activeCustomer->getKey(),
        $this->owner->getKey(),
        $this->branchId,
        '2026-10-26 11:00:00',
        '2026-10-26 11:30:00',
    ))->toThrow(AuthorizationException::class);
});
