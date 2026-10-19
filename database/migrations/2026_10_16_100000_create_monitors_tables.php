<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Мониторинг доступности: мониторы, их проверки (heartbeat) и сбои (инциденты).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 10);
            $table->boolean('enabled')->default(true);
            $table->string('url', 2048)->nullable();
            $table->string('hostname')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('method', 10)->default('GET');
            $table->json('accepted_statuses');
            $table->string('keyword')->nullable();
            $table->boolean('keyword_invert')->default(false);
            // заголовки запроса (в них бывают токены) — зашифрованы
            $table->text('headers')->nullable();
            $table->text('body')->nullable();
            $table->boolean('follow_redirects')->default(true);
            $table->boolean('ignore_tls')->default(false);
            $table->unsignedSmallInteger('ssl_expiry_days')->nullable()->default(14);
            $table->string('dns_record_type', 10)->default('A');
            $table->string('dns_resolver')->nullable();
            $table->string('dns_expected', 1000)->nullable();
            $table->unsignedInteger('interval')->default(60);
            $table->unsignedSmallInteger('timeout')->default(30);
            $table->unsignedTinyInteger('retries')->default(1);
            $table->unsignedInteger('retry_interval')->default(60);

            // состояние
            $table->string('status', 10)->default('pending');
            $table->timestamp('status_since')->nullable();
            // неудачных проверок подряд
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('last_check_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('ssl_valid_to')->nullable();
            $table->string('ssl_issuer')->nullable();
            $table->timestamps();
        });

        Schema::create('monitor_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);
            $table->unsignedInteger('response_ms')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('message', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['monitor_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('monitor_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('error', 1000)->nullable();
            $table->index(['monitor_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_incidents');
        Schema::dropIfExists('monitor_heartbeats');
        Schema::dropIfExists('monitors');
    }
};
