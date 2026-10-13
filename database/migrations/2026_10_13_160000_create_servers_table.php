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
            $table->text('description')->nullable();
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username');
            $table->string('auth_type', 16);
            // Учётные данные SSH — зашифрованы (cast encrypted), наружу не отдаются
            $table->text('private_key')->nullable();
            $table->text('passphrase')->nullable();
            $table->text('password')->nullable();
            $table->string('host_fingerprint')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_status', 16)->nullable();
            $table->text('last_check_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
