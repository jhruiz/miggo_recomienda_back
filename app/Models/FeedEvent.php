<?php

namespace App\Models;

use App\Enums\FeedEventType;
use App\Enums\FeedSubjectType;
use Database\Factories\FeedEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedEvent extends Model
{
    /** @use HasFactory<FeedEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'subject_type',
        'portfolio_id',
        'job_id',
        'service_category_id',
        'type',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FeedEventType::class,
            'subject_type' => FeedSubjectType::class,
        ];
    }

    /**
     * The user who generated the event.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The portfolio the event refers to (subject_type = portfolio).
     */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    /**
     * The job the event refers to (subject_type = job).
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * Category of the item's portfolio at the time of the event (may be null).
     */
    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }
}
