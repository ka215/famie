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
        $representatives = [];
        DB::table('groups')->orderBy('id')->chunkById(100, function ($groups) use (&$representatives): void {
            foreach ($groups as $group) {
                $first = DB::table('group_members')->where('group_id', $group->id)->orderBy('user_id')->first();
                if (! $first || $first->role !== 'admin' || $first->status !== 'active') {
                    throw new RuntimeException('Representative migration requires an active first parent. Group ID: '.$group->id);
                }
                $representatives[$group->id] = $first->user_id;
            }
        });

        if (DB::getDriverName() === 'sqlite') {
            // SQLite supports this nullable reference without rebuilding the referenced groups table.
            DB::statement('ALTER TABLE groups ADD COLUMN representative_user_id INTEGER REFERENCES users(id) ON DELETE RESTRICT');
        } else {
            Schema::table('groups', function (Blueprint $table) {
                $table->foreignId('representative_user_id')->nullable()->constrained('users')->restrictOnDelete();
            });
        }

        foreach ($representatives as $groupId => $userId) {
            DB::table('groups')->where('id', $groupId)->update(['representative_user_id' => $userId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE groups DROP COLUMN representative_user_id');

            return;
        }
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('representative_user_id');
        });
    }
};
