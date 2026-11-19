<?php

namespace App\Models;

use Database\Factories\JobLikeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobLike extends Model
{
    /** @use HasFactory<JobLikeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'job_id',
    ];

    /**
     * The user who liked the job.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The liked job.
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}
