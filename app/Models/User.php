<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasMedia, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, InteractsWithMedia, Notifiable;

    public const AVATAR_COLLECTION = 'avatar';

    /**
     * Single-file avatar collection (JPG/PNG/WEBP).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::AVATAR_COLLECTION)
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * 256x256 center-cropped thumbnail, generated synchronously, avatar only.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections(self::AVATAR_COLLECTION)
            ->fit(Fit::Crop, 256, 256)
            ->keepOriginalImageFormat()
            ->nonQueued();
    }

    /**
     * URL of the original avatar, or null when there is none.
     */
    public function avatarUrl(): ?string
    {
        $media = $this->getFirstMedia(self::AVATAR_COLLECTION);

        if ($media === null) {
            return null;
        }

        $url = $media->getUrl();

        return $url !== '' ? $url : null;
    }

    /**
     * URL of the avatar thumbnail, or null when missing or not generated.
     */
    public function avatarThumbUrl(): ?string
    {
        $media = $this->getFirstMedia(self::AVATAR_COLLECTION);

        if ($media === null || ! $media->hasGeneratedConversion('thumb')) {
            return null;
        }

        $url = $media->getUrl('thumb');

        return $url !== '' ? $url : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The provider profile for this user (when acting as prestador).
     */
    public function providerProfile(): HasOne
    {
        return $this->hasOne(ProviderProfile::class);
    }

    /**
     * Quote requests created by this user as a client.
     */
    public function quoteRequests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class, 'client_user_id');
    }

    /**
     * Applications this user submitted as a provider.
     */
    public function quoteRequestApplications(): HasMany
    {
        return $this->hasMany(QuoteRequestApplication::class, 'provider_user_id');
    }

    /**
     * Quotes this user created as a provider.
     */
    public function providerQuotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'provider_user_id');
    }

    /**
     * Quotes this user received as a client.
     */
    public function clientQuotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'client_user_id');
    }

    /**
     * Jobs where this user is the client.
     */
    public function clientJobs(): HasMany
    {
        return $this->hasMany(Job::class, 'client_user_id');
    }

    /**
     * Jobs where this user is the provider.
     */
    public function providerJobs(): HasMany
    {
        return $this->hasMany(Job::class, 'provider_user_id');
    }

    /**
     * Ratings emitted by this user.
     */
    public function ratingsGiven(): HasMany
    {
        return $this->hasMany(Rating::class, 'rater_user_id');
    }

    /**
     * Ratings received by this user.
     */
    public function ratingsReceived(): HasMany
    {
        return $this->hasMany(Rating::class, 'rated_user_id');
    }

    /**
     * Chat messages sent by this user.
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'sender_user_id');
    }

    /**
     * In-app notifications received by this user.
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
