<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatChannel extends Model
{
    public const STATUS_ACTIVO = 'activo';
    public const STATUS_PRE_ACEPTACION = 'pre_aceptacion';
    public const STATUS_CERRADO = 'cerrado';

    protected $fillable = [
        'job_id',
        'quote_request_application_id',
        'client_user_id',
        'provider_user_id',
        'status',
    ];

    /**
     * The job this channel belongs to (nullable for pre-acceptance channels).
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * The application this channel belongs to (nullable for job channels).
     */
    public function quoteRequestApplication(): BelongsTo
    {
        return $this->belongsTo(QuoteRequestApplication::class);
    }

    /**
     * The client (user) party of this channel.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    /**
     * The provider (user) party of this channel.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    /**
     * Messages contained in this channel.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'channel_id');
    }
}
