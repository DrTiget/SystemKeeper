<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Подробности бекапа: откуда снят, что вошло, команда перед бекапом, исходный объём
    public function up(): void
    {
        Schema::table('backups', fn (Blueprint $table) => $table->json('meta')->nullable()->after('files'));
    }

    public function down(): void
    {
        Schema::table('backups', fn (Blueprint $table) => $table->dropColumn('meta'));
    }
};
