<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue');
    });
});

it('reports healthy when the database, queue, and private disk are ready', function () {
    $this->getJson('/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('reports unavailable when the pending queue exceeds its limit', function () {
    DB::table('jobs')->insert(array_fill(0, 501, ['queue' => 'default']));

    $this->getJson('/health')
        ->assertServiceUnavailable()
        ->assertExactJson(['status' => 'unavailable']);
});
