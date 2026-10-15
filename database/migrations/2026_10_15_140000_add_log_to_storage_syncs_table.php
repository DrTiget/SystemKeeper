<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал синхронизации хранилища: по каждому файлу — способ загрузки, размер и время.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_syncs', function (Blueprint $table) {
            $table->json('log')->nullable()->after('current');
        });
    }

    public function down(): void
    {
        Schema::table('storage_syncs', function (Blueprint $table) {
            $table->dropColumn('log');
        });
    }
};
