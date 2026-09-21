<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->comment('投稿者ID');
            $table->foreignId('category_id')->constrained()->restrictOnDelete()->comment('カテゴリID');
            $table->date('activity_date')->comment('実施日');
            $table->time('activity_time')->nullable()->comment('実施時刻/時間帯（任意）');
            $table->text('content')->comment('活動内容');
            $table->text('note')->nullable()->comment('補足メモ');
            $table->timestamps();

            $table->index(['activity_date', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
