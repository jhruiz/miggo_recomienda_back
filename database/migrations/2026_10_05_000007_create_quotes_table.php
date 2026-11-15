<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();
            // Nullable for direct quotes without a prior request
            $table->foreignId('quote_request_id')->nullable()->constrained('quote_requests')->nullOnDelete();
            $table->enum('type', ['publica', 'directa']);
            $table->text('service_description');
            $table->decimal('total_price', 14, 2)->default(0);
            $table->unsignedInteger('delivery_days');
            $table->date('expires_at');
            $table->enum('status', [
                'pendiente',
                'aceptada',
                'rechazada',
                'expirada',
                'no_seleccionada',
                'solicitada',
                'sin_respuesta',
            ])->default('pendiente');
            $table->timestamps();

            $table->index('status');
            $table->index('quote_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
