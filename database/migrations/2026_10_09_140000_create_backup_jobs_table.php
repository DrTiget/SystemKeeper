<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('type', 16);

            // files: что архивировать
            $table->json('paths')->nullable();
            $table->json('excludes')->nullable();

            // mysql / postgres: ssh — дамп на сервере, direct — SystemKeeper подключается к БД сам
            $table->string('db_mode', 16)->nullable();
            $table->string('db_host')->nullable();
            $table->unsignedSmallInteger('db_port')->nullable();
            $table->string('db_name')->nullable();
            $table->string('db_user')->nullable();
            $table->text('db_password')->nullable();

            $table->unsignedTinyInteger('compression_level')->default(3);
            $table->string('schedule', 100)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_scheduled_at')->nullable();

            // Под ротацию и выгрузку в хранилища (реализуются отдельно)
            $table->unsignedInteger('retention_count')->nullable();
            $table->unsignedInteger('retention_days')->nullable();
            $table->json('storage_target_ids')->nullable();

            $table->timestamps();

            $table->index(['enabled', 'schedule']);
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_job_id')->constrained('backup_jobs')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('trigger', 16)->default('manual');
            $table->string('file_path', 1024)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->char('md5', 32)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->text('log')->nullable();
            $table->timestamps();

            $table->index(['backup_job_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
        Schema::dropIfExists('backup_jobs');
    }
};
