<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $t) {
            $t->unsignedInteger('reward_active_limit')->default(3);
            $t->unsignedInteger('reward_management_limit')->default(6);
            $t->string('reward_sync_status')->default('idle');
            $t->timestamp('reward_sync_started_at')->nullable();
        });
        Schema::table('group_members', fn (Blueprint $t) => $t->unique(['group_id', 'id']));
        Schema::create('rewards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained()->restrictOnDelete();
            $t->enum('status', ['draft', 'active', 'stopped', 'deleted'])->default('draft');
            $t->date('start_month');
            $t->unsignedSmallInteger('cycle_months')->default(1);
            $t->string('timezone')->default('UTC');
            $t->unsignedInteger('revision')->default(1);
            $t->date('prepared_period')->nullable();
            $t->timestamps();
            $t->unique(['group_id', 'id']);
            $t->index(['group_id', 'status']);
        });
        Schema::create('reward_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('reward_id');
            $t->date('effective_from');
            $t->string('name', 50);
            $t->string('description', 500);
            $t->enum('metric', ['activities', 'likes']);
            $t->enum('color', ['gold', 'silver', 'bronze']);
            $t->unsignedInteger('required_count');
            $t->unsignedBigInteger('category_id')->nullable();
            $t->string('category_name')->nullable();
            $t->timestamps();
            $t->unique(['group_id', 'id']);
            $t->unique(['reward_id', 'effective_from']);
            $t->unique(['group_id', 'reward_id', 'id'], 'reward_versions_identity');
            $t->foreign(['group_id', 'reward_id'])->references(['group_id', 'id'])->on('rewards')->restrictOnDelete();
            $t->foreign(['group_id', 'category_id'])->references(['group_id', 'id'])->on('categories')->restrictOnDelete();
        });
        Schema::create('reward_targets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('reward_version_id');
            $t->unsignedBigInteger('group_member_id');
            $t->unique(['reward_version_id', 'group_member_id']);
            $t->foreign(['group_id', 'reward_version_id'])->references(['group_id', 'id'])->on('reward_versions')->restrictOnDelete();
            $t->foreign(['group_id', 'group_member_id'])->references(['group_id', 'id'])->on('group_members')->restrictOnDelete();
        });
        Schema::create('reward_periods', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('reward_id');
            $t->unsignedBigInteger('reward_version_id');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('timezone');
            $t->timestamps();
            $t->unique(['reward_id', 'starts_on']);
            $t->unique(['group_id', 'reward_id', 'id'], 'reward_periods_identity');
            $t->foreign(['group_id', 'reward_id'])->references(['group_id', 'id'])->on('rewards')->restrictOnDelete();
            $t->foreign(['group_id', 'reward_id', 'reward_version_id'])->references(['group_id', 'reward_id', 'id'])->on('reward_versions')->restrictOnDelete();
        });
        Schema::create('reward_progress', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('reward_id');
            $t->unsignedBigInteger('group_member_id');
            $t->date('starts_on');
            $t->unsignedInteger('count')->default(0);
            $t->timestamp('updated_at');
            $t->unique(['reward_id', 'group_member_id', 'starts_on']);
            $t->foreign(['group_id', 'reward_id'])->references(['group_id', 'id'])->on('rewards')->restrictOnDelete();
            $t->foreign(['group_id', 'group_member_id'])->references(['group_id', 'id'])->on('group_members')->restrictOnDelete();
        });
        Schema::create('reward_awards', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('reward_id');
            $t->unsignedBigInteger('reward_period_id');
            $t->unsignedBigInteger('group_member_id');
            $t->unsignedInteger('achieved_count');
            $t->json('snapshot');
            $t->timestamp('awarded_at');
            $t->timestamp('read_at')->nullable();
            $t->unique(['reward_period_id', 'group_member_id']);
            $t->index(['group_id', 'group_member_id', 'awarded_at']);
            $t->foreign(['group_id', 'reward_id', 'reward_period_id'], 'reward_awards_period_fk')->references(['group_id', 'reward_id', 'id'])->on('reward_periods')->restrictOnDelete();
            $t->foreign(['group_id', 'group_member_id'])->references(['group_id', 'id'])->on('group_members')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE groups ADD CONSTRAINT reward_limits_valid CHECK (reward_active_limit > 0 AND reward_management_limit >= reward_active_limit)');
            DB::statement('ALTER TABLE rewards ADD CONSTRAINT reward_cycle_valid CHECK (cycle_months BETWEEN 1 AND 12)');
            DB::statement('ALTER TABLE reward_versions ADD CONSTRAINT reward_count_valid CHECK (required_count BETWEEN 1 AND 10000)');
        }
    }

    public function down(): void
    {
        foreach (['reward_awards', 'reward_progress', 'reward_periods', 'reward_targets', 'reward_versions', 'rewards'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE groups DROP CONSTRAINT reward_limits_valid');
        }
        Schema::table('group_members', fn (Blueprint $t) => $t->dropUnique(['group_id', 'id']));
        Schema::table('groups', fn (Blueprint $t) => $t->dropColumn(['reward_active_limit', 'reward_management_limit', 'reward_sync_status', 'reward_sync_started_at']));
    }
};
