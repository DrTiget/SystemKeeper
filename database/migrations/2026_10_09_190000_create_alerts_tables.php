<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 16);
            $table->text('settings')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('event', 32);
            $table->string('target_type', 16)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('quiet_hours_start', 5)->nullable();
            $table->string('quiet_hours_end', 5)->nullable();
            $table->unsignedInteger('throttle_minutes')->default(15);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['event', 'enabled']);
        });

        Schema::create('alert_channel_alert_rule', function (Blueprint $table) {
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_channel_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_rule_id', 'alert_channel_id']);
        });

        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('alert_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 32);
            $table->string('object_key')->nullable();
            $table->string('title');
            $table->text('body');
            $table->string('url', 2048)->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['alert_rule_id', 'object_key', 'status', 'created_at'], 'alert_deliveries_throttle_index');
            $table->index('created_at');
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('p256dh');
            $table->string('auth');
            $table->timestamps();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 8);
            $table->string('token', 512);
            $table->char('token_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('alert_channel_alert_rule');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('alert_channels');
    }
};
