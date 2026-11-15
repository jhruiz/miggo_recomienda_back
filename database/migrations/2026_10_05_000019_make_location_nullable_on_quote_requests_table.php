<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reutiliza la columna `location` de `quote_requests` como Dirección_Referencia
 * opcional (texto libre de hasta 255 caracteres). La ubicación canónica pasa a
 * ser la Ciudad (`city_id`), por lo que `location` deja de ser obligatoria
 * (Requisitos 4.11, 15.9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->string('location')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->string('location')->nullable(false)->change();
        });
    }
};
