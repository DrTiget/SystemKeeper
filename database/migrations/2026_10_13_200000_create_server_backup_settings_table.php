<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_backup_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->json('paths');
            $table->json('excludes');
            $table->string('schedule', 100);
            $table->text('pre_command')->nullable();
            $table->unsignedInteger('pre_command_timeout');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_backup_settings');
    }
};
