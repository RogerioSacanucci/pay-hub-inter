<?php

namespace App\Models;

use Database\Factories\TiktokEventLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'tiktok_pixel_id',
    'cartpanda_order_id',
    'event',
    'http_status',
    'tiktok_code',
    'tiktok_message',
    'request_id',
    'payload',
    'response',
])]
class TiktokEventLog extends Model
{
    /** @use HasFactory<TiktokEventLogFactory> */
    use HasFactory;

    protected $table = 'tiktok_events_log';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pixel(): BelongsTo
    {
        return $this->belongsTo(TiktokPixel::class, 'tiktok_pixel_id');
    }
}
