<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Domain "Trabajo" entity. Named `service_jobs` to avoid colliding with
     * Laravel's queue `jobs` table (created by create_jobs_table). The Eloquent
     * model is `App\Models\Job` with its table explicitly set to `service_jobs`.
     */
    public function up(): void
    {
        Schema::create('service_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['en_progreso', 'completado', 'cancelado'])->default('en_progreso');
            $table->date('start_date');
            $table->date('estimated_delivery_date')->nullable();
            $table->date('actual_completion_date')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('client_requested_cancel')->default(false);
            $table->boolean('provider_requested_cancel')->default(false);
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_jobs');
    }
};
