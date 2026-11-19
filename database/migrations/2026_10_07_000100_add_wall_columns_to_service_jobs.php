<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->index(['status', 'completed_at', 'id'], 'service_jobs_wall_idx');
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropIndex('service_jobs_wall_idx');
            $table->dropColumn(['completed_at', 'likes_count']);
        });
    }
};
