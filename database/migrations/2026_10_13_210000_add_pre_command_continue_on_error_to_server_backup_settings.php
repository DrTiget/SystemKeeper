<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_backup_settings', function (Blueprint $table) {
            // true — делать бекап, даже если команда перед ним завершилась с ошибкой
            $table->boolean('pre_command_continue_on_error')->default(false)->after('pre_command_timeout');
        });
    }

    public function down(): void
    {
        Schema::table('server_backup_settings', function (Blueprint $table) {
            $table->dropColumn('pre_command_continue_on_error');
        });
    }
};
