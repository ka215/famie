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
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('images_enabled')->default(false);
            $table->unsignedBigInteger('image_quota_bytes')->default(1000000000);
        });
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->uuid('client_request_id')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unique(['group_id', 'user_id', 'client_request_id']);
        });
        Schema::create('activity_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('activity_log_id');
            $table->string('disk', 50);
            $table->string('path');
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('mime', 30)->default('image/webp');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['activity_log_id', 'position']);
            $table->index('group_id');
            $table->foreign(['group_id', 'activity_log_id'])->references(['group_id', 'id'])->on('activity_logs')->cascadeOnDelete();
        });
        Schema::create('activity_image_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 50);
            $table->string('path');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_image_deletions');
        Schema::dropIfExists('activity_images');
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropUnique(['group_id', 'user_id', 'client_request_id']);
            $table->dropColumn(['client_request_id', 'revision']);
        });
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['images_enabled', 'image_quota_bytes']);
        });
    }
};
