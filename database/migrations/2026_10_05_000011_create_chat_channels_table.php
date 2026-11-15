<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_channels', function (Blueprint $table) {
            $table->id();
            // job_id and quote_request_application_id are mutually exclusive by channel type
            $table->foreignId('job_id')->nullable()->constrained('service_jobs')->cascadeOnDelete();
            $table->foreignId('quote_request_application_id')->nullable()
                ->constrained('quote_request_applications')->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['activo', 'pre_aceptacion', 'cerrado'])->default('activo');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_channels');
    }
};
