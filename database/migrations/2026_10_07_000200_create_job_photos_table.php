<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->timestamp('conversions_ready_at')->nullable();
            $table->unsignedSmallInteger('thumb_width')->nullable();
            $table->unsignedSmallInteger('thumb_height')->nullable();
            $table->unsignedSmallInteger('medium_width')->nullable();
            $table->unsignedSmallInteger('medium_height')->nullable();
            $table->timestamps();
            $table->index(['job_id', 'id'], 'job_photos_job_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_photos');
    }
};
