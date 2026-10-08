<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->unique(['group_id', 'id']);
        });
        Schema::create('activity_likes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('activity_log_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->unique(['group_id', 'activity_log_id', 'user_id']);
            $table->index(['group_id', 'user_id']);
            $table->foreign(['group_id', 'activity_log_id'])->references(['group_id', 'id'])->on('activity_logs')->cascadeOnDelete();
            $table->foreign(['group_id', 'user_id'])->references(['group_id', 'user_id'])->on('group_members')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_likes');
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropUnique(['group_id', 'id']);
        });
    }
};
