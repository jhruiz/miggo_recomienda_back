<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_request_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_request_id')->constrained('quote_requests')->cascadeOnDelete();
            $table->foreignId('provider_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamps();

            // A provider can apply to a given request only once
            $table->unique(['quote_request_id', 'provider_user_id'], 'quote_request_application_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_request_applications');
    }
};
