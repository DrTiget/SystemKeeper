<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        foreach (['servers', 'database_connections'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['servers', 'database_connections'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('project_id'));
        }
        Schema::dropIfExists('projects');
    }
};
