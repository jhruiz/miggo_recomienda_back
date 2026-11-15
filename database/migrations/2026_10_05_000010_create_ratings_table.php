<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->foreignId('rater_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('rated_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->text('comment')->nullable();
            $table->boolean('is_visible')->default(false);
            $table->timestamp('rated_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('window_closes_at')->nullable();
            $table->timestamps();

            // Only one rating per (job, rater)
            $table->unique(['job_id', 'rater_user_id'], 'rating_job_rater_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
