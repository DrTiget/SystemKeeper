<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Бекап можно сохранять сразу в несколько мест: у сервера и базы — список хранилищ `storage_ids`,
 * у бекапа — копии в каждом из них (`backup_copies`) со своим статусом.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['server_backup_settings', 'database_connections'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->json('storage_ids')->nullable()->after('storage_id'));
            DB::table($name)->whereNotNull('storage_id')->orderBy('id')->each(
                fn ($row) => DB::table($name)->where('id', $row->id)->update(['storage_ids' => json_encode([(int) $row->storage_id])]),
            );
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('storage_id'));
        }

        Schema::create('backup_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_id')->constrained('backup_storages')->restrictOnDelete();
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['backup_id', 'storage_id']);
        });

        $now = now();
        DB::table('backups')->whereNotNull('storage_id')->where('status', 'success')->orderBy('id')->each(
            fn ($b) => DB::table('backup_copies')->insert(['backup_id' => $b->id, 'storage_id' => $b->storage_id, 'status' => 'success', 'created_at' => $now, 'updated_at' => $now]),
        );
        Schema::table('backups', fn (Blueprint $table) => $table->dropConstrainedForeignId('storage_id'));
    }

    public function down(): void
    {
        Schema::table('backups', fn (Blueprint $table) => $table->foreignId('storage_id')->nullable()->after('user_id')->constrained('backup_storages')->nullOnDelete());
        foreach (DB::table('backup_copies')->get() as $c) {
            DB::table('backups')->where('id', $c->backup_id)->update(['storage_id' => $c->storage_id]);
        }
        Schema::dropIfExists('backup_copies');
        foreach (['server_backup_settings', 'database_connections'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('storage_id')->nullable()->constrained('backup_storages')->nullOnDelete());
            foreach (DB::table($name)->whereNotNull('storage_ids')->get() as $row) {
                DB::table($name)->where('id', $row->id)->update(['storage_id' => json_decode($row->storage_ids, true)[0] ?? null]);
            }
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('storage_ids'));
        }
    }
};
