<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('activity_image_deletions')->exists()) {
            throw new RuntimeException('既存の画像削除待ちがあります。家族・容量を照合してから再実行してください。');
        }

        Schema::create('account_lifecycle_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('group_id')->constrained('groups')->restrictOnDelete();
            $table->foreignId('target_membership_id')->nullable()->constrained('group_members')->restrictOnDelete();
            $table->string('kind', 30);
            $table->string('state', 20)->default('prepared');
            $table->string('cleanup_state', 20)->default('pending');
            $table->timestampTz('prepared_expires_at');
            $table->timestampTz('committed_at')->nullable();
            $table->timestampTz('retention_until')->nullable();
            $table->unsignedBigInteger('content_cursor')->default(0);
            $table->string('last_error_code', 100)->nullable();
            $table->timestampsTz();
            $table->index(['group_id', 'state']);
            $table->index(['cleanup_state', 'updated_at']);
        });

        Schema::create('account_lifecycle_subjects', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('membership_id')->constrained('group_members')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['operation_id', 'user_id']);
            $table->foreign('operation_id')->references('id')->on('account_lifecycle_operations')->cascadeOnDelete();
        });

        Schema::create('account_lifecycle_credentials', function (Blueprint $table) {
            $table->uuid('operation_id')->primary();
            $table->char('code_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->foreign('operation_id')->references('id')->on('account_lifecycle_operations')->cascadeOnDelete();
            $table->index('expires_at');
        });

        Schema::create('account_lifecycle_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id');
            $table->string('scope', 30);
            $table->string('reference', 100);
            $table->timestampTz('started_at');
            $table->timestampTz('review_at');
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->foreign('operation_id')->references('id')->on('account_lifecycle_operations')->cascadeOnDelete();
            $table->index(['operation_id', 'released_at']);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE groups ADD COLUMN withdrawn_at datetime NULL');
            DB::statement('ALTER TABLE groups ADD COLUMN lifecycle_operation_id varchar(36) NULL');
            DB::statement('CREATE INDEX groups_withdrawn_at_index ON groups (withdrawn_at)');
        } else {
            Schema::table('groups', function (Blueprint $table) {
                $table->timestampTz('withdrawn_at')->nullable();
                $table->uuid('lifecycle_operation_id')->nullable();
                $table->foreign('lifecycle_operation_id')->references('id')->on('account_lifecycle_operations')->restrictOnDelete();
                $table->index('withdrawn_at');
            });
        }
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE users ADD COLUMN deleted_at datetime NULL');
            DB::statement('ALTER TABLE users ADD COLUMN lifecycle_operation_id varchar(36) NULL');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->softDeletesTz();
                $table->uuid('lifecycle_operation_id')->nullable();
                $table->foreign('lifecycle_operation_id')->references('id')->on('account_lifecycle_operations')->restrictOnDelete();
            });
        }
        Schema::table('group_members', function (Blueprint $table) {
            $table->softDeletesTz();
            $table->uuid('lifecycle_operation_id')->nullable();
            $table->foreign('lifecycle_operation_id')->references('id')->on('account_lifecycle_operations')->restrictOnDelete();
            $table->index(['group_id', 'deleted_at']);
        });
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->softDeletesTz();
            $table->timestampTz('content_erased_at')->nullable();
            $table->uuid('lifecycle_operation_id')->nullable();
            $table->foreign('lifecycle_operation_id')->references('id')->on('account_lifecycle_operations')->restrictOnDelete();
            $table->index(['lifecycle_operation_id', 'id']);
        });
        Schema::table('activity_images', function (Blueprint $table) {
            $table->timestampTz('retired_at')->nullable();
            $table->uuid('lifecycle_operation_id')->nullable();
            $table->foreign('lifecycle_operation_id')->references('id')->on('account_lifecycle_operations')->restrictOnDelete();
            $table->index(['group_id', 'retired_at']);
        });
        Schema::table('activity_images', function (Blueprint $table) {
            $table->dropUnique(['activity_log_id', 'position']);
        });
        DB::statement('CREATE UNIQUE INDEX activity_images_active_position_unique ON activity_images (activity_log_id, position) WHERE retired_at IS NULL');
        Schema::table('activity_image_deletions', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('groups')->restrictOnDelete();
            $table->foreignId('activity_image_id')->unique()->constrained('activity_images')->restrictOnDelete();
            $table->uuid('operation_id')->nullable();
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->foreign('operation_id')->references('id')->on('account_lifecycle_operations')->restrictOnDelete();
            $table->index(['next_attempt_at', 'id']);
            $table->index(['group_id', 'id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('確定済みの退会・削除記録を失うため、このmigrationは安全に戻せません。前進修正してください。');
    }
};
