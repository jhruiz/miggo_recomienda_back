<?php

namespace App\Models;

use Database\Factories\FeedPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedPreference extends Model
{
    /** @use HasFactory<FeedPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'behavior_logging',
        'privacy_notice_answered_at',
        'consented_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'behavior_logging' => 'boolean',
            'privacy_notice_answered_at' => 'datetime',
            'consented_at' => 'datetime',
        ];
    }

    /**
     * The user these preferences belong to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
