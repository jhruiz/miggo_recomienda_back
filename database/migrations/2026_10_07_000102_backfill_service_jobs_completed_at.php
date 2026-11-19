<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillCompletedAt();
    }

    /**
     * Fills completed_at with updated_at for completed jobs. Idempotent, UPDATE only.
     */
    public function backfillCompletedAt(): void
    {
        DB::table('service_jobs')
            ->where('status', 'completado')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        // The column is dropped by the add_wall_columns_to_service_jobs migration.
    }
};
