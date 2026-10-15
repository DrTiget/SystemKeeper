<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ссылки-хуки: POST на секретный адрес запускает бекап сервера или базы данных (из CI/CD).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_hooks', function (Blueprint $table) {
            $table->id();
            $table->morphs('source');
            $table->unique(['source_type', 'source_id']);
            // сам токен — зашифрован (чтобы показывать ссылку), поиск — по SHA-256
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_hooks');
    }
};
