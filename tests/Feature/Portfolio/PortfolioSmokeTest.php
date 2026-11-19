<?php

namespace Tests\Feature\Portfolio;

use App\Models\ProviderProfile;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Smoke test de extremo a extremo de los Portafolios múltiples:
 * crear Portafolio, listar, subir foto y verlo en el perfil público.
 */
class PortfolioSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function makeProvider(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['cliente', 'prestador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $user = User::factory()->create();
        $user->assignRole('prestador');
        ProviderProfile::create([
            'user_id' => $user->id,
            'professional_description' => 'Plomero con experiencia.',
            'location' => 'Santo Domingo',
            'is_active' => true,
        ]);

        return $user;
    }

    public function test_ownership_scoping_limits_duplicates_and_cascade(): void
    {
        Storage::fake('public');
        $base = '/api/v1/profile/provider/portfolios';
        $owner = $this->makeProvider();
        $other = $this->makeProvider();

        $categoryId = ServiceCategory::factory()->create()->id;

        $this->actingAs($owner, 'sanctum');
        $a = $this->postJson($base, ['name' => 'A', 'service_category_id' => $categoryId])->assertCreated()->json('data.portfolio.id');
        $b = $this->postJson($base, ['name' => 'B', 'service_category_id' => $categoryId])->assertCreated()->json('data.portfolio.id');

        // 422 por nombre duplicado (sin distinguir mayúsculas).
        $this->postJson($base, ['name' => ' a ', 'service_category_id' => $categoryId])->assertStatus(422)->assertJsonValidationErrors('name');

        $img = fn () => ['image' => UploadedFile::fake()->image('x.jpg', 10, 10)];
        $itemId = $this->post("{$base}/{$a}/items", $img(), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.portfolio_item.id');

        // 404 para un ítem bajo otro portafolio.
        $this->putJson("{$base}/{$b}/items/{$itemId}", ['description' => 'x'])->assertNotFound();
        $this->deleteJson("{$base}/{$b}/items/{$itemId}")->assertNotFound();

        // 409 al superar 10 fotos.
        for ($i = 1; $i < 10; $i++) {
            $this->post("{$base}/{$a}/items", $img(), ['Accept' => 'application/json'])->assertCreated();
        }
        $this->post("{$base}/{$a}/items", $img(), ['Accept' => 'application/json'])->assertStatus(409);

        // 409 al superar 20 portafolios (ya hay 2).
        for ($i = 3; $i <= 20; $i++) {
            $this->postJson($base, ['name' => "P{$i}", 'service_category_id' => $categoryId])->assertCreated();
        }
        $this->postJson($base, ['name' => 'Extra', 'service_category_id' => $categoryId])->assertStatus(409);

        // 403 para portafolio ajeno.
        $this->actingAs($other, 'sanctum');
        $this->getJson("{$base}/{$a}")->assertForbidden();
        $this->putJson("{$base}/{$a}", ['name' => 'Z'])->assertForbidden();
        $this->deleteJson("{$base}/{$a}")->assertForbidden();
        $this->post("{$base}/{$a}/items", $img(), ['Accept' => 'application/json'])->assertForbidden();

        // Borrado en cascada de ítems y media.
        $this->actingAs($owner, 'sanctum');
        $this->assertSame(10, Media::count());
        $this->deleteJson("{$base}/{$a}")->assertOk()->assertJsonPath('data.deleted_items_count', 10);
        $this->assertSame(0, Media::count());
        $this->assertDatabaseMissing('portfolio_items', ['portfolio_id' => $a]);
    }

    public function test_provider_can_create_list_upload_and_expose_public_portfolios(): void
    {
        Storage::fake('public');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['cliente', 'prestador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $user = User::factory()->create();
        $user->assignRole('prestador');
        $profile = ProviderProfile::create([
            'user_id' => $user->id,
            'professional_description' => 'Carpintero con experiencia.',
            'location' => 'Santo Domingo',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum');

        $create = $this->postJson('/api/v1/profile/provider/portfolios', [
            'name' => 'Cocinas',
            'description' => 'Trabajos de cocina',
            'service_category_id' => ServiceCategory::factory()->create()->id,
        ])->assertCreated();
        $id = $create->json('data.portfolio.id');
        $this->assertNotNull($id);

        $this->getJson('/api/v1/profile/provider/portfolios')
            ->assertOk()
            ->assertJsonCount(1, 'data.portfolios')
            ->assertJsonPath('data.portfolios.0.name', 'Cocinas')
            ->assertJsonPath('meta.count', 1);

        $this->post(
            "/api/v1/profile/provider/portfolios/{$id}/items",
            ['image' => UploadedFile::fake()->image('a.jpg', 10, 10), 'description' => 'Foto 1'],
            ['Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('data.portfolio_item.description', 'Foto 1');

        $this->getJson('/api/v1/profile/provider/portfolios')
            ->assertJsonPath('data.portfolios.0.photos_count', 1);

        $this->getJson("/api/v1/providers/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.provider.portfolios.0.name', 'Cocinas')
            ->assertJsonCount(1, 'data.provider.portfolios.0.items');
    }
}
