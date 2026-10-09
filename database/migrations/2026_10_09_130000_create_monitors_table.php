<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 16);
            $table->string('target')->nullable();
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->unsignedSmallInteger('expected_status')->nullable();
            $table->string('keyword')->nullable();
            $table->unsignedSmallInteger('ssl_expiry_days')->default(14);
            $table->string('heartbeat_token', 64)->nullable()->unique();
            $table->unsignedInteger('heartbeat_grace_seconds')->default(60);
            $table->unsignedSmallInteger('failure_threshold')->default(3);
            $table->boolean('enabled')->default(true);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('last_status', 8)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['enabled', 'next_check_at']);
        });

        Schema::create('monitor_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('status', 8);
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('checked_at');

            $table->index(['monitor_id', 'checked_at']);
            $table->index('checked_at');
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['monitor_id', 'resolved_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('monitor_checks');
        Schema::dropIfExists('monitors');
    }
};
