<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Синхронизация хранилища: копирование в него бекапов, которые уже лежат в других местах.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_id')->constrained('backup_storages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->boolean('add_to_sources')->default(false);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedBigInteger('bytes_total')->default(0);
            $table->unsignedBigInteger('bytes_done')->default(0);
            $table->string('current')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_syncs');
    }
};
