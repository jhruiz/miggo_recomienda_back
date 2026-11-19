<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->unsignedInteger('likes_count')->default(0);
            $table->index(['created_at', 'id'], 'portfolios_wall_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropIndex('portfolios_wall_idx');
            $table->dropColumn('likes_count');
        });
    }
};
