<?php

namespace Tests\Feature\Concerns;

use App\Models\Job;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fixtures compartidas por las pruebas de exploración del spec
 * flujo-cotizaciones-fixes (mismo estilo que QuoteTest / QuoteRequestTest).
 */
trait QuoteFlowFixtures
{
    protected function setUpQuoteFlow(): void
    {
        Storage::fake('public');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['cliente', 'prestador'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    protected function category(string $slug = 'plomeria'): ServiceCategory
    {
        return ServiceCategory::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
    }

    protected function client(): User
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        return $user;
    }

    /** Usuario con ambos roles (Cliente + Prestador). */
    protected function provider(): User
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');
        $user->assignRole('prestador');

        return $user;
    }

    protected function actingAsProvider(): User
    {
        $user = $this->provider();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function openRequest(?User $owner = null, string $title = 'Instalación eléctrica'): QuoteRequest
    {
        $owner ??= $this->client();

        return QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $this->category()->id,
            'title' => $title,
            'description' => 'Instalar tomas en la cocina',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);
    }

    protected function makeQuote(
        User $provider,
        ?QuoteRequest $request = null,
        string $status = Quote::STATUS_PENDIENTE,
        ?User $client = null,
    ): Quote {
        $request ??= $this->openRequest($client);

        return Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $request->client_user_id,
            'quote_request_id' => $request->id,
            'type' => Quote::TYPE_PUBLICA,
            'service_description' => 'Servicio completo',
            'total_price' => 302.00,
            'delivery_days' => 10,
            'expires_at' => now()->addDays(7),
            'status' => $status,
        ]);
    }

    protected function makeJob(Quote $quote, string $status = Job::STATUS_EN_PROGRESO): Job
    {
        return Job::create([
            'quote_id' => $quote->id,
            'client_user_id' => $quote->client_user_id,
            'provider_user_id' => $quote->provider_user_id,
            'status' => $status,
            'start_date' => Carbon::today(),
            'estimated_delivery_date' => Carbon::today()->addDays(10),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validQuotePayload(QuoteRequest $request, array $overrides = []): array
    {
        return array_merge([
            'quote_request_id' => $request->id,
            'service_description' => 'Instalación eléctrica completa con materiales.',
            'items' => [
                ['description' => 'Mano de obra', 'unit_price' => 100.50, 'quantity' => 2],
            ],
            'delivery_days' => 10,
            'expiration_days' => 7,
        ], $overrides);
    }
}
