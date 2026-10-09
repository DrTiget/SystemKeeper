<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            // Проверять ли TLS-сертификат MySQL в режиме direct (у многих серверов он самоподписанный)
            $table->boolean('db_ssl_verify')->default(true)->after('db_password');
        });
    }

    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropColumn('db_ssl_verify');
        });
    }
};
