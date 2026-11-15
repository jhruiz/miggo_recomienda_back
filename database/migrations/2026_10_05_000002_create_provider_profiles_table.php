<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('professional_description')->nullable();
            $table->string('location')->nullable();
            // Cached/computed fields
            $table->decimal('average_rating', 3, 1)->nullable();
            $table->unsignedInteger('compliance_index')->nullable();
            $table->unsignedInteger('completed_jobs_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_profiles');
    }
};
