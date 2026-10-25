<?php

declare(strict_types=1);

use App\Domain\PetSex;
use App\Domain\PetSpecies;
use App\Domain\Role;
use App\Models\User;
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
    $this->customers = app(CustomerPetService::class);
});

it('creates a pet with one primary guardian and supports changing that guardian', function () {
    $firstCustomer = $this->customers->createCustomer($this->owner, $this->branchId, [
        'name' => 'Pemilik Pertama',
        'phone' => '08123456789',
    ]);
    $pet = $this->customers->createPet($this->owner, $this->branchId, $firstCustomer->getKey(), [
        'name' => 'Milo',
        'species' => PetSpecies::Cat,
        'sex' => PetSex::Male,
        'weight_kg' => '4.250',
    ]);
    $secondCustomer = $this->customers->createCustomer($this->owner, $this->branchId, [
        'name' => 'Pemilik Kedua',
    ]);
    $secondGuardian = $this->customers->addGuardian(
        $this->owner,
        $this->branchId,
        $pet->getKey(),
        $secondCustomer->getKey(),
    );

    expect(DB::table('pet_guardians')->where('pet_id', $pet->getKey())->where('is_primary', true)->count())->toBe(1)
        ->and($secondGuardian->is_primary)->toBeFalse();

    $promoted = $this->customers->setPrimaryGuardian(
        $this->owner,
        $this->branchId,
        $pet->getKey(),
        $secondGuardian->getKey(),
    );

    expect($promoted->is_primary)->toBeTrue()
        ->and(DB::table('pet_guardians')->where('pet_id', $pet->getKey())->where('is_primary', true)->value('customer_id'))->toBe($secondCustomer->getKey())
        ->and(DB::table('audit_logs')->where('action', 'pet.primary_guardian_changed')->count())->toBe(1);
});

it('can add a new primary guardian without violating the unique primary constraint', function () {
    $firstCustomer = $this->customers->createCustomer($this->owner, $this->branchId, ['name' => 'Wali Lama']);
    $pet = $this->customers->createPet($this->owner, $this->branchId, $firstCustomer->getKey(), [
        'name' => 'Kiki',
        'species' => PetSpecies::Bird,
    ]);
    $newCustomer = $this->customers->createCustomer($this->owner, $this->branchId, ['name' => 'Wali Baru']);

    $newGuardian = $this->customers->addGuardian(
        $this->owner,
        $this->branchId,
        $pet->getKey(),
        $newCustomer->getKey(),
        makePrimary: true,
    );

    expect($newGuardian->is_primary)->toBeTrue()
        ->and(DB::table('pet_guardians')->where('pet_id', $pet->getKey())->where('is_primary', true)->whereNull('ended_at')->count())->toBe(1);
});

it('marks a pet deceased once and rejects invalid weight', function () {
    $customer = $this->customers->createCustomer($this->owner, $this->branchId, ['name' => 'Pemilik']);

    expect(fn () => $this->customers->createPet($this->owner, $this->branchId, $customer->getKey(), [
        'name' => 'Hewan Invalid',
        'species' => PetSpecies::Dog,
        'weight_kg' => '0',
    ]))->toThrow(ValidationException::class);

    $pet = $this->customers->createPet($this->owner, $this->branchId, $customer->getKey(), [
        'name' => 'Bobi',
        'species' => PetSpecies::Dog,
    ]);
    $deceased = $this->customers->markDeceased($this->owner, $this->branchId, $pet->getKey());

    expect($deceased->is_deceased)->toBeTrue()
        ->and($deceased->deceased_at)->not->toBeNull()
        ->and(fn () => $this->customers->markDeceased($this->owner, $this->branchId, $pet->getKey()))
        ->toThrow(ValidationException::class);
});

it('requires explicit customer and pet permissions', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['role' => Role::Admin])->save();
    expect(fn () => $this->customers->createCustomer($admin, $this->branchId, ['name' => 'Walk in']))
        ->toThrow(AuthorizationException::class);
});
