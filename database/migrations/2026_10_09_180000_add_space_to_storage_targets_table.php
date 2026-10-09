<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_targets', function (Blueprint $table) {
            $table->unsignedBigInteger('last_total_space')->nullable()->after('last_check_error');
            $table->unsignedBigInteger('last_free_space')->nullable()->after('last_total_space');
        });
    }

    public function down(): void
    {
        Schema::table('storage_targets', function (Blueprint $table) {
            $table->dropColumn(['last_total_space', 'last_free_space']);
        });
    }
};
