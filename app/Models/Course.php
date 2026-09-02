<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ТЗ раздел 9 (Учебный центр) -- the course OFFERING (name, tuition price,
 * length in lessons). Deliberately separate from CourseGroup: one course
 * ("Английский B1") can run as several concurrent groups, each with its own
 * teacher/room/schedule/capacity.
 */
#[Fillable(['tenant_id', 'company_id', 'name', 'description', 'category', 'price', 'duration_lessons', 'is_active'])]
class Course extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['price' => 'float', 'duration_lessons' => 'integer', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(CourseGroup::class);
    }
}
