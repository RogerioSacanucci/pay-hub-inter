<?php

namespace App\Models;

use Database\Factories\UserBalanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'balance_pending', 'balance_reserve', 'balance_released', 'currency'])]
class UserBalance extends Model
{
    /** @use HasFactory<UserBalanceFactory> */
    use HasFactory;

    public $timestamps = false;

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
            'balance_pending' => 'decimal:6',
            'balance_reserve' => 'decimal:6',
            'balance_released' => 'decimal:6',
            'updated_at' => 'datetime',
        ];
    }
}
