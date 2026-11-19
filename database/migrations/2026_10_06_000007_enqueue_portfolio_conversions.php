<?php

use App\Models\PortfolioItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Artisan::call('media-library:regenerate', [
                'modelType' => PortfolioItem::class,
                '--only' => ['thumb', 'medium'],
                '--force' => true,
            ]);
        } catch (Throwable $e) {
            Log::error('No se pudieron encolar las conversiones de portafolio.', ['exception' => $e]);
        }
    }

    public function down(): void
    {
        // Sin efecto: las conversiones regeneradas no se revierten.
    }
};
