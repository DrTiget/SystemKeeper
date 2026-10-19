<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Группа проектов. При удалении группы её проекты остаются без группы.
 */
#[Fillable(['name'])]
class ProjectGroup extends Model
{
    use Auditable;

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'group_id');
    }
}
