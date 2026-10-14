<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Группа проектов. При удалении группы её проекты остаются без группы.
 */
#[Fillable(['name'])]
class ProjectGroup extends Model
{
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'group_id');
    }
}
