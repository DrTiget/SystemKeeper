<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ресурсы серверов: настройки сбора и последний снимок (по строке на сервер) и история точек.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();

            // настройки
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('interval')->default(60);
            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->json('disks_exclude');

            // состояние
            $table->timestamp('next_collect_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();
            // когда сняты показатели последнего удачного сбора и они сами
            $table->timestamp('collected_at')->nullable();
            $table->json('snapshot')->nullable();
            // ошибка последнего сбора; пусто — он удался
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('server_metric_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->decimal('cpu', 5, 2)->nullable();
            $table->decimal('memory', 5, 2)->nullable();
            $table->decimal('swap', 5, 2)->nullable();
            $table->decimal('load1', 8, 2)->nullable();
            $table->decimal('load5', 8, 2)->nullable();
            $table->decimal('load15', 8, 2)->nullable();
            $table->unsignedBigInteger('rx')->nullable();
            $table->unsignedBigInteger('tx')->nullable();
            // заполненность дисков, %: {"/": 41.5}
            $table->json('disks')->nullable();
            $table->index(['server_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metric_points');
        Schema::dropIfExists('server_metrics');
    }
};
