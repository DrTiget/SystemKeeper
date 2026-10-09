<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('collected_at');
            $table->decimal('load1', 8, 2);
            $table->decimal('load5', 8, 2);
            $table->decimal('load15', 8, 2);
            $table->unsignedSmallInteger('cpu_count')->nullable();
            $table->unsignedBigInteger('mem_total');
            $table->unsignedBigInteger('mem_used');
            $table->unsignedBigInteger('mem_available');
            $table->unsignedBigInteger('swap_total')->default(0);
            $table->unsignedBigInteger('swap_used')->default(0);
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            // Диски по точкам монтирования: [{mount, device, fstype, total, used, avail, inodes_total, inodes_used, inodes_free}]
            $table->json('disks');

            $table->index(['server_id', 'collected_at']);
            $table->index('collected_at');
        });

        Schema::create('server_metric_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 16);
            $table->string('target')->default('');
            $table->string('state', 16)->default('ok');
            $table->unsignedSmallInteger('breach_streak')->default(0);
            $table->unsignedSmallInteger('ok_streak')->default(0);
            $table->decimal('value', 10, 2)->nullable();
            $table->decimal('threshold', 10, 2)->nullable();
            $table->timestamp('exceeded_since')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'metric', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metric_states');
        Schema::dropIfExists('server_metrics');
    }
};
