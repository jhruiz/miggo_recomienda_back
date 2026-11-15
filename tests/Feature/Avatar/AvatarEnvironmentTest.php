<?php

declare(strict_types=1);

namespace Tests\Feature\Avatar;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Feature: foto-de-perfil, task 1.2: verificación del entorno (GD con WEBP,
 * ampliación de Fit::Crop, formato de la miniatura y borrado de archivos).
 *
 * Validates: Requirements 1.2, 1.7
 */
class AvatarEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /** Crea una imagen real con GD y la devuelve como UploadedFile. */
    private function makeImage(string $format, int $width, int $height): UploadedFile
    {
        $im = imagecreatetruecolor($width, $height);
        imagefilledrectangle($im, 0, 0, intdiv($width, 2), $height, imagecolorallocate($im, 200, 30, 30));
        imagefilledrectangle($im, intdiv($width, 2), 0, $width, $height, imagecolorallocate($im, 30, 30, 200));

        $path = tempnam(sys_get_temp_dir(), 'av');
        match ($format) {
            'jpg' => imagejpeg($im, $path),
            'png' => imagepng($im, $path),
            'webp' => imagewebp($im, $path),
        };
        imagedestroy($im);

        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$format];

        return new UploadedFile($path, "foto.$format", $mime, null, true);
    }

    private function uploadAvatar(User $user, UploadedFile $file): Media
    {
        return $user->addMedia($file)->toMediaCollection(User::AVATAR_COLLECTION);
    }

    public function test_gd_soporta_webp(): void
    {
        $info = function_exists('gd_info') ? gd_info() : [];

        $this->assertTrue(
            (bool) ($info['WebP Support'] ?? false),
            'GD no tiene soporte WEBP: habilita WEBP en la extensión GD de PHP (extension=gd con libwebp) para usar la foto de perfil.'
        );
    }

    public function test_imagen_pequena_se_amplia_a_miniatura_256x256(): void
    {
        $user = User::factory()->create();

        $media = $this->uploadAvatar($user, $this->makeImage('png', 100, 50));

        $thumb = $media->getPath('thumb');
        $this->assertFileExists($thumb);
        $size = getimagesize($thumb);
        $this->assertSame([256, 256], [$size[0], $size[1]]);
    }

    /**
     * @dataProvider formatProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('formatProvider')]
    public function test_miniatura_conserva_formato_y_mide_256x256(string $format, int $imageType): void
    {
        $user = User::factory()->create();

        $media = $this->uploadAvatar($user, $this->makeImage($format, 600, 600));

        $thumb = $media->getPath('thumb');
        $this->assertFileExists($thumb);
        $size = getimagesize($thumb);
        $this->assertSame([256, 256], [$size[0], $size[1]]);
        $this->assertSame($imageType, $size[2]);
    }

    public static function formatProvider(): array
    {
        return [
            'jpg' => ['jpg', IMAGETYPE_JPEG],
            'png' => ['png', IMAGETYPE_PNG],
            'webp' => ['webp', IMAGETYPE_WEBP],
        ];
    }

    public function test_eliminar_el_medio_borra_original_y_conversion_del_disco(): void
    {
        $user = User::factory()->create();
        $media = $this->uploadAvatar($user, $this->makeImage('jpg', 300, 300));

        $original = $media->getPath();
        $thumb = $media->getPath('thumb');
        $this->assertFileExists($original);
        $this->assertFileExists($thumb);

        $media->delete();

        $this->assertFileDoesNotExist($original);
        $this->assertFileDoesNotExist($thumb);
    }
}
