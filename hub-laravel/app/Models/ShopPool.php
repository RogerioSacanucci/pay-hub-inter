<?php

namespace App\Models;

use Database\Factories\ShopPoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'name',
    'description',
    'cap_period',
])]
class ShopPool extends Model
{
    /** @use HasFactory<ShopPoolFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ShopPoolTarget::class);
    }

    public function affiliateCodes(): HasMany
    {
        return $this->hasMany(AffiliateCode::class);
    }
}
