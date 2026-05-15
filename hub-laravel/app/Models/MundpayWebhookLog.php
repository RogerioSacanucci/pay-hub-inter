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
    const UPDATED_AT = null;

    public $timestamps = false;

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];
}
