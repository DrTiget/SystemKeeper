<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Алерты: каналы уведомлений, правила, срабатывания и отправленные уведомления.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);
            $table->boolean('enabled')->default(true);
            // токен бота, секрет вебхука и адреса — зашифрованы целиком
            $table->text('config');
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->string('kind', 30);
            $table->string('severity', 10);
            $table->double('threshold')->nullable();
            // сколько минут условие должно держаться
            $table->unsignedSmallInteger('duration')->default(0);
            // правило действует только внутри проекта; удалили проект — удалилось и правило, а не расширилось на все
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            // [{type, id}] — пусто: все объекты нужного типа в области
            $table->json('targets');
            $table->unsignedInteger('repeat_every')->nullable();
            $table->boolean('notify_resolved')->default(true);
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_rule_channel', function (Blueprint $table) {
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_channel_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_rule_id', 'alert_channel_id']);
        });

        // с какого момента условие выполняется по паре «правило + объект» (для duration)
        Schema::create('alert_pendings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->string('object_type', 20);
            $table->unsignedBigInteger('object_id');
            $table->timestamp('since');
            $table->unique(['alert_rule_id', 'object_type', 'object_id'], 'alert_pendings_object_unique');
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->string('severity', 10);
            $table->string('status', 10);
            $table->string('object_type', 20);
            $table->unsignedBigInteger('object_id');
            // название и проект объекта на момент срабатывания: остаются в истории, когда объект удалён
            $table->string('object_name');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message', 1000);
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'started_at']);
            $table->index(['alert_rule_id', 'object_type', 'object_id', 'status'], 'alerts_object_status_index');
        });

        Schema::create('alert_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_channel_id')->constrained()->cascadeOnDelete();
            // firing, repeat, resolved
            $table->string('event', 10);
            $table->boolean('ok');
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('alert_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_notifications');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_pendings');
        Schema::dropIfExists('alert_rule_channel');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('alert_channels');
    }
};
