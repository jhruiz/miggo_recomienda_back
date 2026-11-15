<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rating extends Model
{
    protected $fillable = [
        'job_id',
        'rater_user_id',
        'rated_user_id',
        'score',
        'comment',
        'is_visible',
        'rated_at',
        'enabled_at',
        'window_closes_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'is_visible' => 'boolean',
            'rated_at' => 'datetime',
            'enabled_at' => 'datetime',
            'window_closes_at' => 'datetime',
        ];
    }

    /**
     * The job this rating belongs to.
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * The user who emitted this rating.
     */
    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_user_id');
    }

    /**
     * The user who received this rating.
     */
    public function rated(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_user_id');
    }
}
