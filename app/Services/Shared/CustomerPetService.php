<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Domain\Permission;
use App\Domain\PetSex;
use App\Domain\PetSpecies;
use App\Models\Customer;
use App\Models\Pet;
use App\Models\PetGuardian;
use App\Models\User;
use App\Support\AccessControl;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class CustomerPetService
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array{name: string, phone?: ?string, email?: ?string, address?: ?string, user_id?: ?string} $data */
    public function createCustomer(User $actor, string $branchId, array $data): Customer
    {
        $this->authorize($actor, Permission::CustomerManage, $branchId);

        if (trim($data['name'] ?? '') === '') {
            throw ValidationException::withMessages(['name' => 'Nama pelanggan wajib diisi.']);
        }

        return Transactions::run(function () use ($actor, $data, $branchId): Customer {
            $customer = Customer::query()->create([
                ...$data,
                'name' => trim($data['name']),
                'is_active' => true,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->auditLogger->record($actor, 'customer.created', $customer, $branchId);

            return $customer;
        });
    }

    /** @param array{name: string, species: PetSpecies, sex?: PetSex, breed?: ?string, is_neutered?: bool, birth_date?: ?string, birth_date_is_estimate?: bool, weight_kg?: ?string, color?: ?string, microchip?: ?string} $data */
    public function createPet(User $actor, string $branchId, string $customerId, array $data): Pet
    {
        $this->authorize($actor, Permission::PetManage, $branchId);

        if (trim($data['name'] ?? '') === '') {
            throw ValidationException::withMessages(['name' => 'Nama hewan wajib diisi.']);
        }

        $customer = Customer::query()->whereKey($customerId)->where('is_active', true)->first();

        if ($customer === null) {
            throw (new ModelNotFoundException)->setModel(Customer::class, [$customerId]);
        }

        $weight = isset($data['weight_kg']) ? BigDecimal::of($data['weight_kg']) : null;

        if ($weight !== null && (! $weight->isPositive() || $weight->stripTrailingZeros()->getScale() > 3)) {
            throw ValidationException::withMessages(['weight_kg' => 'Berat harus positif dan maksimal tiga desimal.']);
        }

        return Transactions::run(function () use ($actor, $branchId, $customer, $data, $weight): Pet {
            $pet = Pet::query()->create([
                'name' => trim($data['name']),
                'species' => $data['species'],
                'sex' => $data['sex'] ?? PetSex::Unknown,
                'breed' => $data['breed'] ?? null,
                'is_neutered' => $data['is_neutered'] ?? false,
                'birth_date' => $data['birth_date'] ?? null,
                'birth_date_is_estimate' => $data['birth_date_is_estimate'] ?? false,
                'weight_kg' => $weight === null ? null : (string) $weight->toScale(3),
                'color' => $data['color'] ?? null,
                'microchip' => $data['microchip'] ?? null,
                'is_deceased' => false,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $guardian = PetGuardian::query()->create([
                'pet_id' => $pet->getKey(),
                'customer_id' => $customer->getKey(),
                'is_primary' => true,
                'started_at' => now(),
                'created_by' => $actor->getKey(),
            ]);

            $this->auditLogger->record($actor, 'pet.created', $pet, $branchId, [
                'species' => $data['species']->value,
                'primary_guardian_id' => $guardian->customer_id,
            ]);

            return $pet;
        });
    }

    public function addGuardian(
        User $actor,
        string $branchId,
        string $petId,
        string $customerId,
        bool $makePrimary = false,
    ): PetGuardian {
        $this->authorize($actor, Permission::PetManage, $branchId);

        return Transactions::run(function () use ($actor, $branchId, $petId, $customerId, $makePrimary): PetGuardian {
            $pet = Pet::query()->whereKey($petId)->lockForUpdate()->first();
            $customer = Customer::query()->whereKey($customerId)->where('is_active', true)->first();

            if ($pet === null || $customer === null) {
                throw new ModelNotFoundException('Hewan atau pelanggan tidak ditemukan.');
            }

            $existing = PetGuardian::query()
                ->where('pet_id', $petId)
                ->where('customer_id', $customerId)
                ->whereNull('ended_at')
                ->first();

            if ($existing !== null) {
                if ($makePrimary && ! $existing->is_primary) {
                    return $this->promoteGuardian($actor, $branchId, $pet, $existing);
                }

                return $existing;
            }

            $hasPrimary = PetGuardian::query()->where('pet_id', $petId)->where('is_primary', true)->whereNull('ended_at')->exists();
            $becomesPrimary = $makePrimary || ! $hasPrimary;

            if ($becomesPrimary) {
                PetGuardian::query()
                    ->where('pet_id', $petId)
                    ->where('is_primary', true)
                    ->whereNull('ended_at')
                    ->update(['is_primary' => false]);
            }

            $guardian = PetGuardian::query()->create([
                'pet_id' => $petId,
                'customer_id' => $customerId,
                'is_primary' => $becomesPrimary,
                'started_at' => now(),
                'created_by' => $actor->getKey(),
            ]);

            $this->auditLogger->record($actor, 'pet.guardian_added', $guardian, $branchId, [
                'customer_id' => $customerId,
                'is_primary' => $guardian->is_primary,
            ]);

            return $guardian;
        });
    }

    public function setPrimaryGuardian(User $actor, string $branchId, string $petId, string $guardianId): PetGuardian
    {
        $this->authorize($actor, Permission::PetManage, $branchId);

        return Transactions::run(function () use ($actor, $branchId, $petId, $guardianId): PetGuardian {
            $pet = Pet::query()->whereKey($petId)->lockForUpdate()->first();
            $guardian = PetGuardian::query()
                ->whereKey($guardianId)
                ->where('pet_id', $petId)
                ->whereNull('ended_at')
                ->first();

            if ($pet === null || $guardian === null) {
                throw new ModelNotFoundException('Hewan atau wali aktif tidak ditemukan.');
            }

            return $this->promoteGuardian($actor, $branchId, $pet, $guardian);
        });
    }

    public function markDeceased(User $actor, string $branchId, string $petId, ?Carbon $deceasedAt = null): Pet
    {
        $this->authorize($actor, Permission::PetManage, $branchId);

        return Transactions::run(function () use ($actor, $branchId, $petId, $deceasedAt): Pet {
            $pet = Pet::query()->whereKey($petId)->lockForUpdate()->first();

            if ($pet === null) {
                throw (new ModelNotFoundException)->setModel(Pet::class, [$petId]);
            }

            if ($pet->is_deceased) {
                throw ValidationException::withMessages(['pet' => 'Hewan sudah ditandai meninggal.']);
            }

            $pet->forceFill([
                'is_deceased' => true,
                'deceased_at' => $deceasedAt ?? now(),
                'updated_by' => $actor->getKey(),
            ])->save();
            $this->auditLogger->record($actor, 'pet.marked_deceased', $pet, $branchId, [
                'deceased_at' => $pet->deceased_at?->toIso8601String(),
            ]);

            return $pet;
        });
    }

    private function promoteGuardian(User $actor, string $branchId, Pet $pet, PetGuardian $guardian): PetGuardian
    {
        PetGuardian::query()
            ->where('pet_id', $pet->getKey())
            ->where('is_primary', true)
            ->whereNull('ended_at')
            ->update(['is_primary' => false]);

        $guardian->forceFill(['is_primary' => true])->save();
        $this->auditLogger->record($actor, 'pet.primary_guardian_changed', $guardian, $branchId, [
            'customer_id' => $guardian->customer_id,
        ]);

        return $guardian;
    }

    private function authorize(User $actor, Permission $permission, string $branchId): void
    {
        if (! $this->accessControl->can($actor, $permission, $branchId)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin pelanggan atau hewan untuk cabang ini.');
        }
    }
}
