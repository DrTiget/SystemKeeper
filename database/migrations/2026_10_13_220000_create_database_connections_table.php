<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('engine', 16);
            // via=ssh — клиент запускается на сервере (опционально внутри Docker-контейнера), direct — из SystemKeeper
            $table->string('via', 16);
            $table->foreignId('server_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('docker_container')->nullable();
            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('username');
            $table->text('password')->nullable();
            $table->string('maintenance_database')->nullable();
            // Настройки бекапа
            $table->boolean('backup_enabled')->default(false);
            $table->string('schedule', 100);
            $table->json('databases');
            $table->json('exclude_tables');
            $table->json('schema_only_tables');
            $table->json('options');
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_status', 16)->nullable();
            $table->text('last_check_error')->nullable();
            $table->string('server_version')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_connections');
    }
};
