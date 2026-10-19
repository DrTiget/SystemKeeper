<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Приложения — интеграции со статичным API-токеном (хранится только SHA-256) и правами по разделам.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_applications', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('scopes');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('token_prefix', 16)->nullable();
            $table->timestamp('token_created_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_applications');
    }
};
