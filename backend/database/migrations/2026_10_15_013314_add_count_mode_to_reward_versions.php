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
        Schema::table('reward_versions', function (Blueprint $table) {
            $table->enum('count_mode', ['fixed', 'period_days'])->default('fixed');
            $table->unsignedInteger('required_count')->nullable()->change();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE reward_versions ADD CONSTRAINT reward_count_mode_valid CHECK ((count_mode = 'fixed' AND required_count IS NOT NULL AND required_count BETWEEN 1 AND 10000) OR (count_mode = 'period_days' AND required_count IS NULL))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('reward_versions')->where('count_mode', 'period_days')->exists()) {
            throw new LogicException('期間内日数の条件版があるため、このマイグレーションは巻き戻せません。');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reward_versions DROP CONSTRAINT reward_count_mode_valid');
        }
        Schema::table('reward_versions', function (Blueprint $table) {
            $table->dropColumn('count_mode');
            $table->unsignedInteger('required_count')->nullable(false)->change();
        });
    }
};
