<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'channel_id',
        'sender_user_id',
        'content',
        'sent_at_utc',
    ];

    protected function casts(): array
    {
        return [
            'sent_at_utc' => 'datetime',
        ];
    }

    /**
     * The channel this message belongs to.
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'channel_id');
    }

    /**
     * The user who sent this message.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
