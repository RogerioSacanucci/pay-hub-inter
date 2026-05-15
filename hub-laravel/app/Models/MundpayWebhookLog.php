<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'event',
    'mundpay_order_id',
    'status',
    'status_reason',
    'payload',
    'ip_address',
])]
class MundpayWebhookLog extends Model
{
    public const UPDATED_AT = null;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
