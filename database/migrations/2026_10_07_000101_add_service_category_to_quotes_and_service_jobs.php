<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['quotes', 'service_jobs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('service_category_id')
                    ->nullable()
                    ->constrained('service_categories')
                    ->nullOnDelete();
            });
        }

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->index(['service_category_id', 'status', 'completed_at'], 'service_jobs_category_wall_idx');
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropIndex('service_jobs_category_wall_idx');
            $table->dropConstrainedForeignId('service_category_id');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_category_id');
        });
    }
};
