<?php

namespace App\Models;

use Database\Factories\JobSaveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobSave extends Model
{
    /** @use HasFactory<JobSaveFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'job_id',
    ];

    /**
     * The user who saved the job.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The saved job.
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}
