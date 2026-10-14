<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Проект — группа серверов и баз данных. При удалении проекта они остаются без проекта.
 */
#[Fillable(['name', 'description'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(DatabaseConnection::class);
    }
}
