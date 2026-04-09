<?php

namespace App\Models;

use Database\Factories\PayoutLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'admin_user_id', 'shop_id', 'amount', 'type', 'note'])]
class PayoutLog extends Model
{
    /** @use HasFactory<PayoutLogFactory> */
    use HasFactory;

    public $timestamps = true;

    const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(CartpandaShop::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
        ];
    }
}
