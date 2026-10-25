<?php

declare(strict_types=1);

use App\Domain\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('creates the first owner once and records an audit event without secrets', function () {
    $verifier = Mockery::mock(UncompromisedVerifier::class);
    $verifier->shouldReceive('verify')->andReturn(true);
    $this->app->instance(UncompromisedVerifier::class, $verifier);

    $this->artisan('app:create-owner')
        ->expectsQuestion('Nama pemilik', 'Pemilik Utama')
        ->expectsQuestion('Email pemilik', 'OWNER@example.test')
        ->expectsQuestion('Kata sandi (minimal 10 karakter)', 'SangatAman!2026')
        ->expectsQuestion('Ulangi kata sandi', 'SangatAman!2026')
        ->expectsOutput('Akun pemilik berhasil dibuat.')
        ->assertExitCode(0);

    $owner = User::query()->firstOrFail();
    $audit = DB::table('audit_logs')->where('action', 'owner.initialized')->first();

    expect($owner->role)->toBe(Role::Owner)
        ->and($owner->email)->toBe('owner@example.test')
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->details)->not->toContain('SangatAman');

    $this->artisan('app:create-owner')
        ->expectsOutput('Akun pemilik pertama sudah pernah dibuat di lingkungan ini.')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(1);
});
