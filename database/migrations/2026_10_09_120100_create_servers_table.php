<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username');
            $table->string('auth_type', 16);
            $table->foreignId('ssh_key_id')->nullable()->constrained('ssh_keys')->restrictOnDelete();
            $table->text('password')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_status', 16)->nullable();
            $table->text('last_check_error')->nullable();
            $table->string('host_fingerprint')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
