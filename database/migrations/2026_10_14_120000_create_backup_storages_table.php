<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_storages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Хранилище по умолчанию — диск самого SystemKeeper
        DB::table('backup_storages')->insert(['name' => 'SystemKeeper', 'type' => 'local', 'created_at' => now(), 'updated_at' => now()]);

        foreach (['server_backup_settings', 'database_connections'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('storage_id')->nullable()->constrained('backup_storages')->nullOnDelete();
                $table->unsignedSmallInteger('keep_count')->default(7);
                $table->unsignedBigInteger('max_size')->nullable();
            });
        }

        Schema::table('backups', function (Blueprint $table) {
            $table->foreignId('storage_id')->nullable()->after('user_id')->constrained('backup_storages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backups', fn (Blueprint $table) => $table->dropConstrainedForeignId('storage_id'));
        foreach (['server_backup_settings', 'database_connections'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('storage_id');
                $table->dropColumn(['keep_count', 'max_size']);
            });
        }
        Schema::dropIfExists('backup_storages');
    }
};
