<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'mundpay_order_id',
    'mundpay_ref',
    'user_id',
    'amount',
    'reserve_amount',
    'chargeback_penalty',
    'currency',
    'status',
    'event',
    'payment_method',
    'payer_email',
    'payer_name',
    'payer_phone',
    'payer_document',
    'paid_at',
    'chargeback_at',
    'release_eligible_at',
    'released_at',
    'payload',
])]
class MundpayOrder extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const TERMINAL_STATUSES = ['COMPLETED', 'FAILED', 'DECLINED', 'REFUNDED'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

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
            'paid_at' => 'datetime',
            'chargeback_at' => 'datetime',
            'released_at' => 'datetime',
            'release_eligible_at' => 'datetime',
        ];
    }
}
