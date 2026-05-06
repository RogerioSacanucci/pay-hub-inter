<?php

namespace App\Models;

use Database\Factories\ShopPoolTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'shop_pool_id',
    'shop_id',
    'checkout_template',
    'priority',
    'daily_cap',
    'is_overflow',
    'active',
    'clicks',
])]
class ShopPoolTarget extends Model
{
    /** @use HasFactory<ShopPoolTargetFactory> */
    use HasFactory;

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ShopPool::class, 'shop_pool_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(CartpandaShop::class, 'shop_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_cap' => 'decimal:2',
            'is_overflow' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
