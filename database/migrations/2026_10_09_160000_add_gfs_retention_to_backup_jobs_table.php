<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            // Схема GFS: сколько последних дней/недель/месяцев хранить по одному (самому свежему) бекапу
            $table->unsignedSmallInteger('keep_daily')->nullable()->after('retention_days');
            $table->unsignedSmallInteger('keep_weekly')->nullable()->after('keep_daily');
            $table->unsignedSmallInteger('keep_monthly')->nullable()->after('keep_weekly');
        });
    }

    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropColumn(['keep_daily', 'keep_weekly', 'keep_monthly']);
        });
    }
};
