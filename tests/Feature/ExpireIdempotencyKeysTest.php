<?php

declare(strict_types=1);

use App\Domain\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('deletes expired idempotency keys and keeps unexpired keys', function () {
    $this->seed(DatabaseSeeder::class);
    $user = User::factory()->create();
    $user->forceFill(['role' => Role::Owner])->save();
    $now = now();

    foreach ([
        ['key' => '423e4567-e89b-42d3-a456-426614174401', 'expires_at' => $now->copy()->subSecond()],
        ['key' => '423e4567-e89b-42d3-a456-426614174402', 'expires_at' => $now->copy()->addHour()],
    ] as $entry) {
        DB::table('idempotency_keys')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'scope' => 'test.expiration',
            'key' => $entry['key'],
            'request_hash' => hash('sha256', $entry['key']),
            'status' => 'DONE',
            'result_type' => User::class,
            'result_id' => $user->getKey(),
            'created_at' => $now->copy()->subDay(),
            'expires_at' => $entry['expires_at'],
        ]);
    }

    $this->artisan('idempotency:expire')->assertSuccessful();

    expect(DB::table('idempotency_keys')->count())->toBe(1)
        ->and(DB::table('idempotency_keys')->value('key'))->toBe('423e4567-e89b-42d3-a456-426614174402');
});
