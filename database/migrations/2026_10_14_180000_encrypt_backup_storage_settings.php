<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Настройки облачных хранилищ содержат ключи доступа — храним зашифрованными (text вместо json)
    public function up(): void
    {
        Schema::table('backup_storages', fn (Blueprint $table) => $table->text('settings')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('backup_storages', fn (Blueprint $table) => $table->json('settings')->nullable()->change());
    }
};
