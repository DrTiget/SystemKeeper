<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_targets', function (Blueprint $table) {
            // sftp: сервер, на который кладутся файлы (учётные данные и отпечаток хоста — от сервера)
            $table->foreignId('server_id')->nullable()->after('type')->constrained('servers')->restrictOnDelete();
            // s3: несекретные настройки (endpoint, region, bucket, access_key, path_style, storage_class)
            $table->json('config')->nullable()->after('base_path');
            // s3: secret_key (зашифрован)
            $table->text('secret')->nullable()->after('token');
        });

        Schema::table('storage_copies', function (Blueprint $table) {
            $table->string('sha256', 64)->nullable()->after('md5');
        });
    }

    public function down(): void
    {
        Schema::table('storage_copies', function (Blueprint $table) {
            $table->dropColumn('sha256');
        });

        Schema::table('storage_targets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_id');
            $table->dropColumn(['config', 'secret']);
        });
    }
};
