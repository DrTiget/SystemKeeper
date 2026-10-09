<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->boolean('verify_restore')->default(false);
            // Периодичность проверки: каждый N-й успешный бекап и/или не реже раза в N суток (оба null — каждый бекап)
            $table->unsignedInteger('verify_every_n')->nullable();
            $table->unsignedInteger('verify_every_days')->nullable();
            // Необязательный проверочный SELECT и минимум для первого столбца его первой строки
            $table->text('verify_query')->nullable();
            $table->unsignedBigInteger('verify_query_min')->nullable();
            // Тестовый сервер БД (обязателен для postgres; для mysql пусто — используется MySQL проекта)
            $table->string('restore_host')->nullable();
            $table->unsignedSmallInteger('restore_port')->nullable();
            $table->string('restore_user')->nullable();
            $table->text('restore_password')->nullable();
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->string('restore_status', 16)->nullable()->after('log');
            $table->timestamp('restore_checked_at')->nullable()->after('restore_status');
            $table->text('restore_log')->nullable()->after('restore_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['restore_status', 'restore_checked_at', 'restore_log']);
        });
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'verify_restore', 'verify_every_n', 'verify_every_days', 'verify_query', 'verify_query_min',
                'restore_host', 'restore_port', 'restore_user', 'restore_password',
            ]);
        });
    }
};
