<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_item_id')->constrained('portfolio_items')->cascadeOnDelete();
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('type', 30);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at', 'id'], 'feed_events_user_history_idx');
            $table->index(['user_id', 'portfolio_item_id', 'type', 'created_at'], 'feed_events_dedupe_idx');
            $table->index('created_at', 'feed_events_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_events');
    }
};
