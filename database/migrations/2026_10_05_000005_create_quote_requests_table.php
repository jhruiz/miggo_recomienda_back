<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->restrictOnDelete();
            $table->string('title', 100);
            $table->text('description');
            $table->string('location');
            $table->decimal('estimated_budget', 12, 2)->nullable();
            $table->enum('status', ['abierta', 'en_proceso', 'cerrada'])->default('abierta');
            $table->timestamps();

            $table->index('status');
            $table->index('service_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_requests');
    }
};
