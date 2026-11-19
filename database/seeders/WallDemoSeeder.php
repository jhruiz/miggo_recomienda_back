<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Job;
use App\Models\JobPhoto;
use App\Models\Portfolio;
use App\Models\PortfolioItem;
use App\Models\ProviderProfile;
use App\Models\Quote;
use App\Models\Rating;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Datos de demostración para la verificación visual del Muro (Trabajos y Portafolios).
 *
 * No se ejecuta desde DatabaseSeeder. Uso manual:
 * php artisan db:seed --class=WallDemoSeeder
 *
 * Guardas: no corre en producción y no hace nada si ya existe el Cliente demo
 * (DEMO_CLIENT_EMAIL). Todos los usuarios demo usan el dominio DEMO_EMAIL_DOMAIN
 * y la contraseña "password".
 */
class WallDemoSeeder extends Seeder
{
    public const DEMO_EMAIL_DOMAIN = 'walldemo.test';

    public const DEMO_CLIENT_EMAIL = 'cliente@walldemo.test';

    private const RATING_REVEALED = 'revealed';

    private const RATING_REVEALED_NO_COMMENT = 'revealed_no_comment';

    private const RATING_HIDDEN = 'hidden';

    private const RATING_EXPIRED = 'expired';

    private const RATING_OPEN = 'open';

    /**
     * Si las fotos llevan archivos reales con conversiones WebP (requiere GD con WebP).
     */
    private bool $withImages = false;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('WallDemoSeeder no se ejecuta en producción.');
        }

        if (User::query()->where('email', self::DEMO_CLIENT_EMAIL)->exists()) {
            $this->command?->warn('WallDemoSeeder: los datos demo ya existen ('.self::DEMO_CLIENT_EMAIL.'). No se hace nada.');

            return;
        }

        $this->call(RoleSeeder::class);

        if (ServiceCategory::query()->count() < 4) {
            $this->call(ServiceCategorySeeder::class);
        }

        $this->withImages = extension_loaded('gd') && function_exists('imagewebp') && function_exists('imagejpeg');

        if ($this->withImages) {
            // Conversiones en línea: cada foto queda lista sin depender de un worker.
            config(['media-library.queue_connection_name' => 'sync']);
        } else {
            $this->command?->warn('WallDemoSeeder: GD sin WebP; las fotos se marcan listas sin archivos.');
        }

        DB::transaction(function (): void {
            $this->seedDemoData();
        });

        $this->command?->info('WallDemoSeeder: datos demo creados. Cliente: '.self::DEMO_CLIENT_EMAIL.' / password');
    }

    private function seedDemoData(): void
    {
        /** @var Collection<int, ServiceCategory> $categories */
        $categories = ServiceCategory::query()->orderBy('id')->limit(4)->get();
        $cities = $this->cities();

        $client = $this->demoUser('cliente', 'Cliente Demo', ['cliente']);
        $otherClient = $this->demoUser('cliente2', 'Otra Clienta Demo', ['cliente']);

        $activeA = $this->provider('prestador1', 'Ana Prestadora', $cities[0], $categories->take(2), true);
        $activeB = $this->provider('prestador2', 'Bruno Prestador', $cities[1] ?? $cities[0], $categories->slice(2, 2), true);
        $inactive = $this->provider('inactivo', 'Inés Inactiva', $cities[0], $categories->take(1), false);

        $catA = $categories[0];
        $catB = $categories[1] ?? $catA;
        $catC = $categories[2] ?? $catA;

        // Trabajos visibles: combinaciones de fotos, calificación, comentario y categoría.
        $this->completedJob($activeA->user, $otherClient, $catA, 2, -2, 3, self::RATING_REVEALED);
        $this->completedJob($activeA->user, $otherClient, $catB, 4, 0, 1, self::RATING_REVEALED_NO_COMMENT);
        $this->completedJob($activeA->user, $otherClient, $catA, 6, 3, 0, self::RATING_REVEALED);
        $this->completedJob($activeB->user, $otherClient, null, 8, 0, 0, self::RATING_REVEALED_NO_COMMENT);
        $this->completedJob($activeB->user, $otherClient, null, 10, -1, 5, self::RATING_EXPIRED);
        $this->completedJob($activeB->user, $otherClient, $catC, 12, 1, 2, self::RATING_HIDDEN);

        // Trabajos por calificar del Cliente demo (ventana abierta), con y sin fotos.
        $this->completedJob($activeA->user, $client, $catA, 1, 0, 2, self::RATING_OPEN);
        $this->completedJob($activeB->user, $client, $catC, 3, 2, 0, self::RATING_OPEN);
        $this->completedJob($activeB->user, $client, null, 5, -3, 1, self::RATING_OPEN);

        // No visibles: Prestador inactivo y Trabajo en curso.
        $this->completedJob($inactive->user, $otherClient, $catA, 3, 0, 2, self::RATING_REVEALED);
        Job::factory()->inProgress()->create([
            'quote_id' => Quote::factory()->direct($catB)->create([
                'provider_user_id' => $activeA->user_id,
                'client_user_id' => $client->id,
            ])->id,
            'client_user_id' => $client->id,
            'provider_user_id' => $activeA->user_id,
            'service_category_id' => $catB->id,
        ]);

        // Portafolios con 1, 2, 3, 4 y 6 fotos listas (uno sin categoría y uno con una foto pendiente).
        $this->portfolio($activeA, 'Baño renovado', $catA, 1, 1);
        $this->portfolio($activeA, 'Cocina integral', $catB, 2, 3);
        $this->portfolio($activeB, 'Fachada y exteriores', $catC, 3, 5);
        $this->portfolio($activeB, 'Trabajos varios', null, 4, 7, pendingPhotos: 1);
        $this->portfolio($activeA, 'Remodelación completa', $catA, 6, 9);

        // No visibles: solo fotos pendientes y Prestador inactivo.
        $this->portfolio($activeB, 'Proyecto en preparación', $catC, 0, 2, pendingPhotos: 2);
        $this->portfolio($inactive, 'Portafolio de cuenta inactiva', $catA, 2, 4);
    }

    /**
     * @return list<City>
     */
    private function cities(): array
    {
        $cities = City::query()->orderBy('id')->limit(2)->get();

        if ($cities->isEmpty()) {
            $cities = City::factory()->count(2)->create();
        }

        return $cities->values()->all();
    }

    /**
     * @param  list<string>  $roles
     */
    private function demoUser(string $alias, string $name, array $roles): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $alias.'@'.self::DEMO_EMAIL_DOMAIN,
        ]);

        $user->assignRole($roles);

        return $user;
    }

    /**
     * @param  Collection<int, ServiceCategory>  $categories
     */
    private function provider(string $alias, string $name, City $city, Collection $categories, bool $isActive): ProviderProfile
    {
        $user = $this->demoUser($alias, $name, ['cliente', 'prestador']);

        $profile = ProviderProfile::factory()->create([
            'user_id' => $user->id,
            'professional_description' => 'Perfil de demostración para el Muro.',
            'location' => $city->name,
            'city_id' => $city->id,
            'average_rating' => fake()->randomFloat(2, 3.5, 5),
            'compliance_index' => fake()->numberBetween(60, 100),
            'is_active' => $isActive,
        ]);

        $profile->categories()->attach($categories->pluck('id')->all());

        return $profile;
    }

    /**
     * Trabajo completado hace $daysAgo días; $delayDays < 0 es antes de plazo, 0 a tiempo y > 0 con retraso.
     */
    private function completedJob(
        User $provider,
        User $client,
        ?ServiceCategory $category,
        int $daysAgo,
        int $delayDays,
        int $photos,
        string $ratingKind,
    ): Job {
        $completedAt = now()->subDays($daysAgo)->setTime(15, 0);
        $estimated = $completedAt->copy()->subDays($delayDays);

        $quote = Quote::factory()->direct($category)->create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $client->id,
        ]);

        $job = Job::factory()->completed()->create([
            'quote_id' => $quote->id,
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'service_category_id' => $category?->id,
            'start_date' => $estimated->copy()->subDays(7)->toDateString(),
            'estimated_delivery_date' => $estimated->toDateString(),
            'actual_completion_date' => $completedAt->toDateString(),
            'completed_at' => $completedAt,
        ]);

        for ($index = 1; $index <= $photos; $index++) {
            $this->jobPhoto($job, $index);
        }

        $this->ratings($job, $completedAt, $ratingKind);

        return $job;
    }

    /**
     * Calificación del Cliente hacia el Prestador y la recíproca, según el caso.
     */
    private function ratings(Job $job, Carbon $completedAt, string $kind): void
    {
        $enabledAt = $completedAt->copy();
        $window = [
            'enabled_at' => $enabledAt,
            'window_closes_at' => $kind === self::RATING_EXPIRED
                ? now()->subDay()
                : $enabledAt->copy()->addDays(30),
        ];

        $pairs = [
            [$job->client_user_id, $job->provider_user_id],
            [$job->provider_user_id, $job->client_user_id],
        ];

        foreach ($pairs as $position => [$raterId, $ratedId]) {
            $attributes = $window + ['job_id' => $job->id, 'rater_user_id' => $raterId, 'rated_user_id' => $ratedId];

            $factory = match ($kind) {
                self::RATING_REVEALED, self::RATING_REVEALED_NO_COMMENT => Rating::factory()->revealed(),
                self::RATING_HIDDEN => $position === 0 ? Rating::factory()->issued() : Rating::factory()->placeholder(),
                default => Rating::factory()->placeholder(),
            };

            if ($kind === self::RATING_REVEALED) {
                $attributes['comment'] = 'Excelente trabajo, muy puntual y ordenado. Lo recomiendo.';
            } elseif ($kind === self::RATING_REVEALED_NO_COMMENT) {
                $attributes['comment'] = null;
            }

            $factory->create($attributes);
        }
    }

    private function portfolio(
        ProviderProfile $profile,
        string $name,
        ?ServiceCategory $category,
        int $readyPhotos,
        int $daysAgo,
        int $pendingPhotos = 0,
    ): Portfolio {
        $portfolio = Portfolio::factory()->forProvider($profile)->create([
            'name' => $name,
            'description' => 'Portafolio de demostración.',
            'service_category_id' => $category?->id,
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);

        for ($index = 1; $index <= $readyPhotos; $index++) {
            $this->portfolioPhoto($portfolio, $index);
        }

        PortfolioItem::factory()->count($pendingPhotos)->pending()->create(['portfolio_id' => $portfolio->id]);

        return $portfolio;
    }

    private function jobPhoto(Job $job, int $index): void
    {
        if (! $this->withImages) {
            JobPhoto::factory()->ready()->create(['job_id' => $job->id]);

            return;
        }

        $photo = JobPhoto::factory()->pending()->create(['job_id' => $job->id]);
        $photo->addMediaFromString($this->demoImage("Trabajo {$job->id} - foto {$index}", $job->id + $index))
            ->usingFileName("demo-job-{$job->id}-{$index}.jpg")
            ->toMediaCollection('job_photo');
    }

    private function portfolioPhoto(Portfolio $portfolio, int $index): void
    {
        if (! $this->withImages) {
            PortfolioItem::factory()->ready()->create(['portfolio_id' => $portfolio->id]);

            return;
        }

        $item = PortfolioItem::factory()->pending()->create([
            'portfolio_id' => $portfolio->id,
            'description' => "Foto {$index} de {$portfolio->name}",
        ]);
        $item->addMediaFromString($this->demoImage("{$portfolio->name} - foto {$index}", $portfolio->id * 7 + $index))
            ->usingFileName("demo-portfolio-{$portfolio->id}-{$index}.jpg")
            ->toMediaCollection('portfolio');
    }

    /**
     * JPEG de color liso con una etiqueta; alterna orientación horizontal y vertical.
     */
    private function demoImage(string $label, int $seed): string
    {
        [$width, $height] = $seed % 3 === 0 ? [900, 1200] : [1200, 900];

        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 60 + ($seed * 47) % 160, 60 + ($seed * 83) % 160, 60 + ($seed * 29) % 160);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);
        imagestring($image, 5, 40, 40, $label, $white);

        ob_start();
        imagejpeg($image, null, 85);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }
}
