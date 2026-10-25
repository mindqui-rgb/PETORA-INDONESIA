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
        Schema::create('branch_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->unique()->restrictOnDelete();
            $table->string('invoice_template')->nullable();
            $table->boolean('invoice_show_tax_note')->default(true);
            $table->text('invoice_footer')->nullable();
            $table->foreignUuid('logo_media_id')->nullable();
            $table->timestamps();
        });

        Schema::create('branch_payment_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->string('method_code', 30);
            $table->string('provider_name');
            $table->string('account_number');
            $table->string('account_name');
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'method_code', 'account_number']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('role', 20);
            $table->string('token_hash', 64)->unique();
            $table->json('branch_ids');
            $table->foreignUuid('invited_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->foreignUuid('used_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['email', 'role']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 60);
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestampTz('read_at')->nullable();
            $table->foreignUuid('read_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'is_read', 'created_at']);
        });

        Schema::create('job_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('job_name', 80);
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->string('status', 16);
            $table->json('detail')->nullable();
            $table->timestamps();
            $table->index(['job_name', 'started_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE branch_settings ADD CONSTRAINT branch_settings_invoice_show_tax_note_check CHECK (invoice_show_tax_note IN (true, false))");
            DB::statement("ALTER TABLE branch_payment_accounts ADD CONSTRAINT branch_payment_accounts_method_check CHECK (length(trim(method_code)) > 0)");
            DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('OWNER','DOCTOR','ADMIN','CUSTOMER'))");
            DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_expires_check CHECK (expires_at > created_at)");
            DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_is_read_check CHECK (is_read IN (true, false))");
            DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_read_consistency_check CHECK ((is_read = TRUE AND read_at IS NOT NULL) OR (is_read = FALSE AND read_at IS NULL))");
            DB::statement("ALTER TABLE job_runs ADD CONSTRAINT job_runs_status_check CHECK (status IN ('SUCCESS','FAILED'))");
            DB::statement('CREATE TRIGGER job_runs_append_only BEFORE UPDATE OR DELETE ON job_runs FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS job_runs_append_only ON job_runs');
        }

        Schema::dropIfExists('job_runs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('branch_payment_accounts');
        Schema::dropIfExists('branch_settings');
    }
};