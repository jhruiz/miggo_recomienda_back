<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QuoteRequestApplication extends Model
{
    protected $fillable = [
        'quote_request_id',
        'provider_user_id',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
        ];
    }

    /**
     * The quote request this application belongs to.
     */
    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    /**
     * The provider (user) who applied.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    /**
     * The pre-acceptance chat channel for this application.
     */
    public function chatChannel(): HasOne
    {
        return $this->hasOne(ChatChannel::class);
    }
}
