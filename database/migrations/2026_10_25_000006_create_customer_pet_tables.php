<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['phone', 'email']);
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('species', 20);
            $table->string('breed')->nullable();
            $table->string('sex', 12)->default('UNKNOWN');
            $table->boolean('is_neutered')->default(false);
            $table->date('birth_date')->nullable();
            $table->boolean('birth_date_is_estimate')->default(false);
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->string('color')->nullable();
            $table->string('microchip', 100)->nullable()->unique();
            $table->boolean('is_deceased')->default(false);
            $table->timestampTz('deceased_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('pet_guardians', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('ended_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['pet_id', 'ended_at']);
            $table->unique(['pet_id', 'customer_id', 'ended_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE pets ADD CONSTRAINT pets_species_check CHECK (species IN ('DOG','CAT','BIRD','RABBIT','REPTILE','OTHER'))");
            DB::statement("ALTER TABLE pets ADD CONSTRAINT pets_sex_check CHECK (sex IN ('MALE','FEMALE','UNKNOWN'))");
            DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_deceased_check CHECK ((is_deceased = TRUE AND deceased_at IS NOT NULL) OR (is_deceased = FALSE AND deceased_at IS NULL))');
            DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_weight_check CHECK (weight_kg IS NULL OR weight_kg > 0)');
        }

        DB::statement('CREATE UNIQUE INDEX pet_guardians_one_active_primary ON pet_guardians (pet_id) WHERE is_primary = '.(DB::getDriverName() === 'pgsql' ? 'TRUE' : '1').' AND ended_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pet_guardians_one_active_primary');
        Schema::dropIfExists('pet_guardians');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('customers');
    }
};
