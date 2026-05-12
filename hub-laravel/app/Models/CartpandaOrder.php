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
    'reserve_amount',
    'chargeback_penalty',
    'currency',
    'status',
    'event',
    'payer_email',
    'payer_name',
    'payload',
    'shop_id',
    'released_at',
    'release_eligible_at',
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
     * Liquid amount = amount - reserve_amount. The portion that flows through
     * pending/released balance buckets. Reserve stays in balance_reserve.
     */
    public function liquidAmount(): float
    {
        return (float) $this->amount - (float) $this->reserve_amount;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'reserve_amount' => 'decimal:6',
            'chargeback_penalty' => 'decimal:6',
            'payload' => 'array',
            'released_at' => 'datetime',
            'release_eligible_at' => 'datetime',
        ];
    }
}
