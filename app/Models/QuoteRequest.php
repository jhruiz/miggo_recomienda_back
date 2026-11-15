<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class QuoteRequest extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const STATUS_ABIERTA = 'abierta';

    public const STATUS_EN_PROCESO = 'en_proceso';

    public const STATUS_CERRADA = 'cerrada';

    /** Nombre de la colección de Media Library para las imágenes de referencia (Requisito 4.13). */
    public const REFERENCE_IMAGES_MEDIA_COLLECTION = 'reference_images';

    /** Máximo de imágenes de referencia por solicitud (Requisito 4.13). */
    public const MAX_REFERENCE_IMAGES = 5;

    protected $fillable = [
        'client_user_id',
        'service_category_id',
        'title',
        'description',
        'location',
        'city_id',
        'estimated_budget',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'estimated_budget' => 'decimal:2',
        ];
    }

    /**
     * The client (user) who created this request.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    /**
     * The service category this request is classified under.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * The city this request is located in.
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Applications submitted by providers to this request.
     */
    public function applications(): HasMany
    {
        return $this->hasMany(QuoteRequestApplication::class);
    }

    /**
     * Quotes generated for this request.
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * Register the media collection for the request reference images.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::REFERENCE_IMAGES_MEDIA_COLLECTION)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Register a thumbnail conversion for the reference images.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(400)
            ->height(400)
            ->nonQueued();
    }
}
