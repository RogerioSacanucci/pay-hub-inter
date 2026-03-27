<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'aapanel_config_id',
    'label',
    'external_url',
    'file_path',
])]
class UserLink extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aapanelConfig(): BelongsTo
    {
        return $this->belongsTo(UserAapanelConfig::class, 'aapanel_config_id');
    }
}
