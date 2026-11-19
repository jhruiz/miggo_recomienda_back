<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase destructiva: retira las interacciones y eventos de fotos sueltas.
 * `down()` recrea la estructura vacía, sin recuperar datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('feed_events')->where('subject_type', 'portfolio_item')->delete();

        Schema::table('feed_events', function (Blueprint $table) {
            $table->dropIndex('feed_events_dedupe_idx');
        });

        Schema::table('feed_events', function (Blueprint $table) {
            $table->dropForeign(['portfolio_item_id']);
        });

        Schema::table('feed_events', function (Blueprint $table) {
            $table->dropColumn('portfolio_item_id');
        });

        Schema::table('feed_events', function (Blueprint $table) {
            $table->string('subject_type', 20)->change();
        });

        Schema::dropIfExists('portfolio_item_saves');
        Schema::dropIfExists('portfolio_item_likes');

        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropColumn('likes_count');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->unsignedInteger('likes_count')->default(0);
        });

        Schema::create('portfolio_item_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_item_id')->constrained('portfolio_items')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'portfolio_item_id']);
            $table->index('portfolio_item_id');
        });

        Schema::create('portfolio_item_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_item_id')->constrained('portfolio_items')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'portfolio_item_id']);
            $table->index(['user_id', 'created_at', 'id'], 'portfolio_item_saves_user_order_idx');
            $table->index('portfolio_item_id');
        });

        Schema::table('feed_events', function (Blueprint $table) {
            $table->string('subject_type', 20)->default('portfolio_item')->change();
        });

        Schema::table('feed_events', function (Blueprint $table) {
            $table->foreignId('portfolio_item_id')->nullable()->after('subject_type')
                ->constrained('portfolio_items')->cascadeOnDelete();
        });

        Schema::table('feed_events', function (Blueprint $table) {
            $table->index(['user_id', 'portfolio_item_id', 'type', 'created_at'], 'feed_events_dedupe_idx');
        });
    }
};
