<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type', 32);
            $table->text('token')->nullable();
            $table->string('base_path')->default('/');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_status', 16)->nullable();
            $table->text('last_check_error')->nullable();
            $table->timestamps();
        });

        Schema::create('storage_copies', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('uploadable');
            $table->foreignId('storage_target_id')->constrained('storage_targets')->cascadeOnDelete();
            $table->string('remote_path', 1024);
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('size')->nullable();
            $table->string('md5', 32)->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['storage_target_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_copies');
        Schema::dropIfExists('storage_targets');
    }
};
