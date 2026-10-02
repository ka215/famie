<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('existing_data', function (Blueprint $table) {
            Schema::create('groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->enum('type', ['family'])->default('family');
                $table->timestamps();
            });

            Schema::create('group_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->enum('role', ['admin', 'member'])->default('member');
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();
                $table->unique(['group_id', 'user_id']);
                $table->unique('user_id');
            });

            Schema::table('categories', function (Blueprint $table) {
                $table->foreignId('group_id')->nullable()->after('id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('sort_order')->nullable()->after('color_code');
                $table->dropUnique(['name']);
            });

            Schema::table('activity_logs', function (Blueprint $table) {
                $table->foreignId('group_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            });

            if (DB::table('users')->exists() || DB::table('categories')->exists() || DB::table('activity_logs')->exists()) {
                $now = now();
                $groupId = DB::table('groups')->insertGetId([
                    'name' => '既存の家族',
                    'type' => 'family',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('users')->orderBy('id')->get(['id', 'role'])->each(function (object $user) use ($groupId, $now): void {
                    DB::table('group_members')->insert([
                        'group_id' => $groupId,
                        'user_id' => $user->id,
                        'role' => $user->role === 'parent' ? 'admin' : 'member',
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });

                DB::table('categories')->orderBy('id')->get(['id'])->values()->each(function (object $category, int $index) use ($groupId): void {
                    DB::table('categories')->where('id', $category->id)->update([
                        'group_id' => $groupId,
                        'sort_order' => $index + 1,
                    ]);
                });

                DB::table('activity_logs')->update(['group_id' => $groupId]);
            }

            Schema::table('categories', function (Blueprint $table) {
                $table->unsignedBigInteger('group_id')->nullable(false)->change();
                $table->unsignedInteger('sort_order')->nullable(false)->change();
                $table->unique(['group_id', 'name']);
                $table->unique(['group_id', 'id']);
            });

            Schema::table('activity_logs', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropForeign(['category_id']);
                $table->dropIndex(['activity_date', 'user_id']);
                $table->unsignedBigInteger('group_id')->nullable(false)->change();
                $table->foreign(['group_id', 'user_id'])->references(['group_id', 'user_id'])->on('group_members')->restrictOnDelete();
                $table->foreign(['group_id', 'category_id'])->references(['group_id', 'id'])->on('categories')->restrictOnDelete();
                $table->index(['group_id', 'activity_date', 'user_id']);
            });

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });

            DB::table('personal_access_tokens')->delete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('existing_data', function (Blueprint $table) {
            throw new RuntimeException('v0.10.0の家族単位データはv0.9.0へ安全に戻せません。バックアップから復元してください。');
        });
    }
};
