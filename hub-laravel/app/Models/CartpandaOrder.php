<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cartpanda_order_id',
    'user_id',
    'amount',
    'currency',
    'status',
    'event',
    'payer_email',
    'payer_name',
    'payload',
    'shop_id',
])]
class CartpandaOrder extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const TERMINAL_STATUSES = ['COMPLETED', 'FAILED', 'DECLINED', 'REFUNDED'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(CartpandaShop::class, 'shop_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'payload' => 'array',
        ];
    }
}
