<?php

namespace App\Models;

use Database\Factories\TiktokPixelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pixel_code', 'access_token', 'label', 'test_event_code', 'enabled'])]
#[Hidden(['access_token'])]
class TiktokPixel extends Model
{
    /** @use HasFactory<TiktokPixelFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
