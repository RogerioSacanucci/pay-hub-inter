<?php

namespace App\Models;

use Database\Factories\CartpandaShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['cartpanda_shop_id', 'shop_slug', 'name', 'default_checkout_template', 'daily_cap'])]
class CartpandaShop extends Model
{
    /** @use HasFactory<CartpandaShopFactory> */
    use HasFactory;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cartpanda_shop_user', 'shop_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(CartpandaOrder::class, 'shop_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_cap' => 'decimal:2',
        ];
    }
}
