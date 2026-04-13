<?php

namespace App\Models;

use Database\Factories\CheckoutChangeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'message', 'status'])]
class CheckoutChangeRequest extends Model
{
    /** @use HasFactory<CheckoutChangeRequestFactory> */
    use HasFactory;

    public $timestamps = true;

    const UPDATED_AT = null;

    /** @var list<string> */
    public const STATUSES = ['pending', 'done'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
