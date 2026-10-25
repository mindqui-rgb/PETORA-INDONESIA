<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('timezone', 32)->default('Asia/Jakarta');
            $table->decimal('delivery_fee', 14, 2)->default(0);
            $table->boolean('pos_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('doctor_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('license_number')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->string('role', 20);
            $table->foreignUuid('permission_id')->constrained()->restrictOnDelete();
            $table->primary(['role', 'permission_id']);
        });

        Schema::create('user_branch_access', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->primary(['user_id', 'branch_id']);
        });

        Schema::create('user_permission_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('permission_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->foreignUuid('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_grants');
        Schema::dropIfExists('user_branch_access');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('doctor_profiles');
        Schema::dropIfExists('branches');
    }
};
