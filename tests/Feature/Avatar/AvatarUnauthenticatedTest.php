<?php

namespace Tests\Feature\Avatar;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Requisitos 7.2, 7.6, 8.4: sin token o con token revocado, los endpoints de
 * foto de perfil responden 401 con mensaje en español y no cambian la foto.
 */
class AvatarUnauthenticatedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function userWithAvatar(): User
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('a.jpg', 300, 300);
        $user->addMedia($file->getPathname())
            ->usingFileName('original.jpg')
            ->preservingOriginal()
            ->toMediaCollection(User::AVATAR_COLLECTION);

        return $user->refresh();
    }

    private function assertUnchanged(User $user, int $mediaId): void
    {
        $media = $user->refresh()->getMedia(User::AVATAR_COLLECTION);
        $this->assertCount(1, $media);
        $this->assertSame($mediaId, $media->first()->id);
    }

    public function test_sin_token_responde_401_en_espanol_y_no_cambia_la_foto(): void
    {
        $user = $this->userWithAvatar();
        $mediaId = $user->getFirstMedia(User::AVATAR_COLLECTION)->id;

        $post = $this->postJson('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('n.png', 100, 100),
        ]);
        $post->assertStatus(401)->assertJsonPath('message', 'No autenticado.');

        $this->deleteJson('/api/v1/profile/avatar')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->assertUnchanged($user, $mediaId);
    }

    public function test_con_token_revocado_responde_401_en_espanol_y_no_cambia_la_foto(): void
    {
        $user = $this->userWithAvatar();
        $mediaId = $user->getFirstMedia(User::AVATAR_COLLECTION)->id;

        $token = $user->createToken('test')->plainTextToken;
        $user->tokens()->delete();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('n.png', 100, 100),
        ])->assertStatus(401)->assertJsonPath('message', 'No autenticado.');

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->deleteJson('/api/v1/profile/avatar')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->assertUnchanged($user, $mediaId);
    }
}
