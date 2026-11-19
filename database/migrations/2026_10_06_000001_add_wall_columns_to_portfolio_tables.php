<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->foreignId('service_category_id')
                ->nullable()
                ->constrained('service_categories')
                ->nullOnDelete();
            $table->index('service_category_id');
        });

        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->unsignedInteger('likes_count')->default(0);
            $table->timestamp('conversions_ready_at')->nullable();
            $table->unsignedSmallInteger('thumb_width')->nullable();
            $table->unsignedSmallInteger('thumb_height')->nullable();
            $table->unsignedSmallInteger('medium_width')->nullable();
            $table->unsignedSmallInteger('medium_height')->nullable();
            $table->index(['conversions_ready_at', 'created_at', 'id'], 'portfolio_items_wall_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropIndex('portfolio_items_wall_idx');
            $table->dropColumn([
                'likes_count',
                'conversions_ready_at',
                'thumb_width',
                'thumb_height',
                'medium_width',
                'medium_height',
            ]);
        });

        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_category_id');
        });
    }
};
