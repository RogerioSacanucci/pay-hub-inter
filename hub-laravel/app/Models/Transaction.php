<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transaction_id',
    'user_id',
    'amount',
    'currency',
    'method',
    'status',
    'payer_email',
    'payer_name',
    'payer_document',
    'payer_phone',
    'reference_entity',
    'reference_number',
    'reference_expires_at',
    'callback_data',
])]
class Transaction extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const TERMINAL_STATUSES = ['COMPLETED', 'FAILED', 'EXPIRED', 'DECLINED'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'callback_data' => 'array',
        ];
    }
}
