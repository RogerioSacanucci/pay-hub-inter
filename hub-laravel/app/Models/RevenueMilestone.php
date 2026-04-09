<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['value', 'order'])]
class RevenueMilestone extends Model
{
    public function achievements(): HasMany
    {
        return $this->hasMany(UserMilestoneAchievement::class, 'milestone_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'order' => 'integer',
        ];
    }
}
