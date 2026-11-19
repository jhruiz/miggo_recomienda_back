<?php

namespace App\Models;

use Database\Factories\JobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Domain "Trabajo" entity. Mapped to the `service_jobs` table to avoid
 * colliding with Laravel's queue `jobs` table.
 */
class Job extends Model
{
    /** @use HasFactory<JobFactory> */
    use HasFactory;

    protected $table = 'service_jobs';

    public const STATUS_EN_PROGRESO = 'en_progreso';

    public const STATUS_COMPLETADO = 'completado';

    public const STATUS_CANCELADO = 'cancelado';

    protected $fillable = [
        'quote_id',
        'service_category_id',
        'client_user_id',
        'provider_user_id',
        'status',
        'start_date',
        'estimated_delivery_date',
        'actual_completion_date',
        'cancellation_reason',
        'rejection_reason',
        'client_requested_cancel',
        'provider_requested_cancel',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'estimated_delivery_date' => 'date',
            'actual_completion_date' => 'date',
            'completed_at' => 'datetime',
            'likes_count' => 'integer',
            'client_requested_cancel' => 'boolean',
            'provider_requested_cancel' => 'boolean',
            // Cancelación por mutuo acuerdo (tarea 9.4, Requisitos 7.7, 7.8):
            // `cancellation_reason` guarda el motivo de CADA parte en un objeto
            // con claves `client`/`provider`, para conservar ambos motivos (≤500
            // cada uno) tal como exige el Requisito 7.7. Se castea a array para
            // leerlo/escribirlo cómodamente; en BD es una columna `text` (JSON).
            'cancellation_reason' => 'array',
        ];
    }

    /**
     * Fija completed_at una sola vez al pasar a completado (DD2). Nunca lo sobrescribe.
     */
    protected static function booted(): void
    {
        static::saving(function (Job $job): void {
            if ($job->status === self::STATUS_COMPLETADO && $job->completed_at === null) {
                $job->completed_at = now();
            }
        });

        // Borra cada foto vía Eloquent para que Media Library elimine archivos y conversiones.
        static::deleting(function (Job $job): void {
            $job->photos()->get()->each(fn (JobPhoto $photo): ?bool => $photo->delete());
        });
    }

    /**
     * Jobs eligible for the wall: completed and with an active provider profile.
     *
     * @param  Builder<Job>  $query
     * @return Builder<Job>
     */
    public function scopeVisibleOnWall(Builder $query): Builder
    {
        return $query
            ->where('service_jobs.status', self::STATUS_COMPLETADO)
            ->whereHas('providerProfile', fn (Builder $profile) => $profile->where('is_active', true));
    }

    /**
     * The service category of this job (null for historical jobs without data).
     */
    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    /**
     * The provider profile of the provider (user) of this job.
     */
    public function providerProfile(): HasOne
    {
        return $this->hasOne(ProviderProfile::class, 'user_id', 'provider_user_id');
    }

    /**
     * Result photos of this job, ordered by id.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(JobPhoto::class)->orderBy('id');
    }

    /**
     * Likes given to this job.
     */
    public function likes(): HasMany
    {
        return $this->hasMany(JobLike::class);
    }

    /**
     * Saves of this job.
     */
    public function saves(): HasMany
    {
        return $this->hasMany(JobSave::class);
    }

    /**
     * The quote this job originated from.
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * The client (user) party of this job.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    /**
     * The provider (user) party of this job.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    /**
     * Ratings enabled by this job.
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * The chat channel attached to this job.
     */
    public function chatChannel(): HasOne
    {
        return $this->hasOne(ChatChannel::class);
    }
}
