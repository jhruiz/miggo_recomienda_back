<?php

namespace App\Models;

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
    protected $table = 'service_jobs';

    public const STATUS_EN_PROGRESO = 'en_progreso';
    public const STATUS_COMPLETADO = 'completado';
    public const STATUS_CANCELADO = 'cancelado';

    protected $fillable = [
        'quote_id',
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
