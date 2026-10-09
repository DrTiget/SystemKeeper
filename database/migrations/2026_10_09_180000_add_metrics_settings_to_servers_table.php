<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('metrics_enabled')->default(true);
            $table->unsignedSmallInteger('metrics_interval_minutes')->default(5);
            // Пороги: null — проверка выключена
            $table->unsignedTinyInteger('threshold_disk_percent')->nullable()->default(90);
            $table->decimal('threshold_load_per_core', 5, 2)->nullable()->default(2);
            $table->unsignedTinyInteger('threshold_ram_percent')->nullable()->default(90);
            // Сколько замеров подряд нужно для срабатывания и для возврата в норму (антидребезг)
            $table->unsignedTinyInteger('threshold_confirmations')->default(2);
            $table->timestamp('metrics_next_at')->nullable()->index();
            $table->timestamp('metrics_collected_at')->nullable();
            $table->text('metrics_last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['metrics_next_at']);
            $table->dropColumn([
                'metrics_enabled', 'metrics_interval_minutes', 'threshold_disk_percent', 'threshold_load_per_core',
                'threshold_ram_percent', 'threshold_confirmations', 'metrics_next_at', 'metrics_collected_at', 'metrics_last_error',
            ]);
        });
    }
};
