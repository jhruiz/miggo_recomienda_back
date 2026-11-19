<?php

namespace App\Models;

use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Quote extends Model implements HasMedia
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory, InteractsWithMedia;

    public const TYPE_PUBLICA = 'publica';

    public const TYPE_DIRECTA = 'directa';

    public const STATUS_PENDIENTE = 'pendiente';

    public const STATUS_ACEPTADA = 'aceptada';

    public const STATUS_RECHAZADA = 'rechazada';

    public const STATUS_EXPIRADA = 'expirada';

    public const STATUS_NO_SELECCIONADA = 'no_seleccionada';

    public const STATUS_SOLICITADA = 'solicitada';

    public const STATUS_SIN_RESPUESTA = 'sin_respuesta';

    /** Nombre de la colección de Media Library para los adjuntos (Requisito 5.3). */
    public const ATTACHMENTS_MEDIA_COLLECTION = 'attachments';

    protected $fillable = [
        'provider_user_id',
        'client_user_id',
        'quote_request_id',
        'service_category_id',
        'type',
        'service_description',
        'total_price',
        'delivery_days',
        'expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'total_price' => 'decimal:2',
            'delivery_days' => 'integer',
            'expires_at' => 'date',
        ];
    }

    /**
     * The provider (user) who created this quote.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    /**
     * The client (user) who receives this quote.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    /**
     * The quote request this quote responds to (nullable for direct quotes).
     */
    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    /**
     * The service category of this quote (null for legacy quotes).
     */
    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    /**
     * Line items that make up this quote.
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    /**
     * The job originated when this quote is accepted.
     */
    public function job(): HasOne
    {
        return $this->hasOne(Job::class);
    }

    /**
     * Register the media collection for quote attachments (up to 5 images).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENTS_MEDIA_COLLECTION)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Register a thumbnail conversion for quote attachments.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(400)
            ->height(400)
            ->nonQueued();
    }
}
