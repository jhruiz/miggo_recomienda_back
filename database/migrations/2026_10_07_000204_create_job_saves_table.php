<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'job_id']);
            $table->index(['user_id', 'created_at', 'id'], 'job_saves_user_order_idx');
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_saves');
    }
};
