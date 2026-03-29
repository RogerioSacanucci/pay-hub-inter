<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'email',
    'password_hash',
    'payer_email',
    'payer_name',
    'success_url',
    'failed_url',
    'pushcut_url',
    'role',
    'pushcut_notify',
    'cartpanda_param',
    'active',
])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    const UPDATED_AT = null;

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function cartpandaOrders(): HasMany
    {
        return $this->hasMany(CartpandaOrder::class);
    }

    public function aapanelConfigs(): HasMany
    {
        return $this->hasMany(UserAapanelConfig::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(UserLink::class);
    }

    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(CartpandaShop::class, 'cartpanda_shop_user');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'active' => 'boolean',
        ];
    }
}
