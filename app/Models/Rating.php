<?php

namespace App\Models;

use Database\Factories\RatingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rating extends Model
{
    /** @use HasFactory<RatingFactory> */
    use HasFactory;

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
     * Whether the rating can still be submitted: not yet emitted and the
     * submission window (if any) has not closed. Mirrors RatingService::submitRating.
     */
    public function isOpenForSubmission(): bool
    {
        if ($this->rated_at !== null) {
            return false;
        }

        return $this->window_closes_at === null || ! now()->greaterThan($this->window_closes_at);
    }

    /**
     * Ratings that are still open for submission (same rule as isOpenForSubmission()).
     *
     * @param  Builder<Rating>  $query
     */
    public function scopeOpenForSubmission(Builder $query): void
    {
        $query->whereNull('rated_at')
            ->where(function (Builder $window): void {
                $window->whereNull('window_closes_at')
                    ->orWhere('window_closes_at', '>=', now());
            });
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
