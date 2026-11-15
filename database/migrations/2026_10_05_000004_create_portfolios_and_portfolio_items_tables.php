<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMysql = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::create('portfolios', function (Blueprint $table) use ($isMysql) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained('provider_profiles')->cascadeOnDelete();
            $table->string('name', 100);
            // Nombre normalizado (minúsculas, sin espacios laterales) para unicidad sin distinguir mayúsculas.
            // En MySQL se usa utf8mb4_bin para no ignorar acentos; SQLite ya compara de forma binaria.
            $column = $table->string('name_normalized', 100);
            if ($isMysql) {
                $column->collation('utf8mb4_bin');
            }
            $table->string('description', 1000)->nullable();
            $table->timestamps();

            $table->unique(['provider_profile_id', 'name_normalized']);
            $table->index(['provider_profile_id', 'created_at']);
        });

        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained('portfolios')->cascadeOnDelete();
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->index(['portfolio_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_items');
        Schema::dropIfExists('portfolios');
    }
};
