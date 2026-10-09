<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->unsignedInteger('last_response_time_ms')->nullable()->after('last_status');
            $table->text('last_error')->nullable()->after('last_response_time_ms');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn(['last_response_time_ms', 'last_error']);
        });
    }
};
