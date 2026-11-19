<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillCategories();
    }

    /**
     * Asigna la categoría a los Portafolios de Prestadores con exactamente una categoría.
     * Público para poder ejercitarlo desde los tests.
     */
    public function backfillCategories(): void
    {
        $single = DB::table('provider_categories')
            ->select('provider_profile_id')
            ->groupBy('provider_profile_id')
            ->havingRaw('COUNT(*) = 1');

        DB::table('portfolios')
            ->whereIn('provider_profile_id', $single)
            ->whereNull('service_category_id')
            ->update(['service_category_id' => DB::raw(
                '(SELECT pc.service_category_id FROM provider_categories pc
                  WHERE pc.provider_profile_id = portfolios.provider_profile_id LIMIT 1)'
            )]);
    }

    public function down(): void
    {
        // La columna se elimina en la migración 2026_10_06_000001.
    }
};
