<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Role;
use App\Models\User;
use App\Services\Shared\AuditLogger;
use App\Support\Errors\DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class CreateOwner extends Command
{
    protected $signature = 'app:create-owner';

    protected $description = 'Buat akun pemilik pertama untuk lingkungan ini.';

    public function handle(AuditLogger $auditLogger): int
    {
        if (User::query()->where('role', Role::Owner->value)->exists()
            || DB::table('audit_logs')->where('action', 'owner.initialized')->exists()) {
            $this->error('Akun pemilik pertama sudah pernah dibuat di lingkungan ini.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Nama pemilik'));
        $email = mb_strtolower(trim((string) $this->ask('Email pemilik')));
        $password = (string) $this->secret('Kata sandi (minimal 10 karakter)');
        $passwordConfirmation = (string) $this->secret('Ulangi kata sandi');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(10)->uncompromised(), 'confirmed'],
        ]);

        $validator->after(function ($validator) use ($email): void {
            if ($email !== '' && User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $validator->errors()->add('email', 'Email sudah digunakan.');
            }
        });

        try {
            $validator->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($name, $email, $password, $auditLogger): void {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("SELECT pg_advisory_xact_lock(hashtext('petora:create-owner'))");
                }

                if (User::query()->where('role', Role::Owner->value)->exists()
                    || DB::table('audit_logs')->where('action', 'owner.initialized')->exists()) {
                    throw new DomainException('CONFLICT', 'Akun pemilik pertama sudah dibuat oleh proses lain.', 409);
                }

                $owner = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]);
                $owner->forceFill([
                    'role' => Role::Owner,
                    'email_verified_at' => now(),
                ])->save();

                $auditLogger->record(null, 'owner.initialized', $owner, details: [
                    'environment' => app()->environment(),
                    'email' => $email,
                ]);
            });
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Akun pemilik berhasil dibuat.');

        return self::SUCCESS;
    }
}
