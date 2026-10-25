<?php

declare(strict_types=1);

use App\Domain\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $verifier = Mockery::mock(UncompromisedVerifier::class);
    $verifier->shouldReceive('verify')->andReturn(true);
    $this->app->instance(UncompromisedVerifier::class, $verifier);
});

it('registers customer accounts only and requires email verification for the portal', function () {
    Notification::fake();

    $response = $this->post('/daftar', [
        'name' => 'Rina Pemilik',
        'email' => 'RINA@example.test',
        'password' => 'C0mplex!Passw0rd',
        'password_confirmation' => 'C0mplex!Passw0rd',
        'role' => Role::Owner->value,
    ]);

    $user = User::query()->where('email', 'rina@example.test')->firstOrFail();

    $response->assertRedirect();
    $this->assertAuthenticatedAs($user);
    expect($user->role)->toBe(Role::Customer)
        ->and($user->email_verified_at)->toBeNull();

    $this->get('/akun')->assertRedirect(route('verification.notice'));
});

it('rejects email addresses already registered with different letter casing', function () {
    User::factory()->create(['email' => 'rina@example.test']);

    $this->from('/daftar')->post('/daftar', [
        'name' => 'Rina',
        'email' => 'RINA@example.test',
        'password' => 'C0mplex!Passw0rd',
        'password_confirmation' => 'C0mplex!Passw0rd',
    ])->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(1);
});

it('requires a long password and rejects passwords returned as compromised', function () {
    $this->from('/daftar')->post('/daftar', [
        'name' => 'Rina',
        'email' => 'rina@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('password');

    $verifier = Mockery::mock(UncompromisedVerifier::class);
    $verifier->shouldReceive('verify')->andReturn(false);
    $this->app->instance(UncompromisedVerifier::class, $verifier);

    $this->from('/daftar')->post('/daftar', [
        'name' => 'Rina',
        'email' => 'rina@example.test',
        'password' => 'C0mplex!Passw0rd',
        'password_confirmation' => 'C0mplex!Passw0rd',
    ])->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe(0);
});

it('does not authenticate inactive users and locks an email after five failures', function () {
    $user = User::factory()->create([
        'email' => 'inactive@example.test',
        'password' => 'C0mplex!Passw0rd',
    ]);
    $user->forceFill(['is_active' => false])->save();

    $this->post('/masuk', [
        'email' => $user->email,
        'password' => 'C0mplex!Passw0rd',
    ]);

    $this->assertGuest();

    $email = 'locked@example.test';
    $user = User::factory()->create([
        'email' => $email,
        'password' => 'C0mplex!Passw0rd',
    ]);

    foreach (range(1, 5) as $_) {
        $this->post('/masuk', [
            'email' => $email,
            'password' => 'wrong-password',
        ]);
    }

    $this->post('/masuk', [
        'email' => $email,
        'password' => 'C0mplex!Passw0rd',
    ]);

    $this->assertGuest();
    expect(RateLimiter::tooManyAttempts(
        'login-failures:'.hash('sha256', $email),
        5,
    ))->toBeTrue();
});

it('allows only verified customer accounts to use the customer portal', function () {
    $customer = User::factory()->create();
    $customer->forceFill(['role' => Role::Customer])->save();

    $this->actingAs($customer)->get('/akun')->assertOk();

    $staff = User::factory()->create();
    $staff->forceFill(['role' => Role::Admin])->save();

    $this->actingAs($staff)->get('/akun')->assertForbidden();
});
