<?php

namespace App\Models;

use Database\Factories\TiktokOauthConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'bc_id',
    'bc_name',
    'access_token',
    'refresh_token',
    'expires_at',
    'scope',
    'advertiser_ids',
    'status',
])]
#[Hidden(['access_token', 'refresh_token'])]
class TiktokOauthConnection extends Model
{
    /** @use HasFactory<TiktokOauthConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'scope' => 'array',
            'advertiser_ids' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pixels(): HasMany
    {
        return $this->hasMany(TiktokPixel::class);
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
