<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('private_key');
            $table->text('passphrase')->nullable();
            $table->text('public_key');
            $table->string('fingerprint');
            $table->string('type', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_keys');
    }
};
