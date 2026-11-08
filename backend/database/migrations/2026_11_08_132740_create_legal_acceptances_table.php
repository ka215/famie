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
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_id', 16);
            $table->string('document_version', 64);
            $table->string('document_sha256', 64);
            $table->string('action', 16);
            $table->string('locale', 2);
            $table->timestampTz('recorded_at');
            $table->unique(['user_id', 'document_id', 'document_version', 'action'], 'legal_acceptances_user_document_version_action_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Legal acceptance history must be retained. Disable acceptance enforcement instead of dropping this table.');
    }
};
