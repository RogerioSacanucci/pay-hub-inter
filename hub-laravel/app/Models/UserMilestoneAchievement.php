<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'milestone_id', 'total_at_achievement', 'achieved_at'])]
class UserMilestoneAchievement extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(RevenueMilestone::class, 'milestone_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_at_achievement' => 'decimal:2',
            'achieved_at' => 'datetime',
        ];
    }
}
