<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase aditiva: `feed_events` pasa a admitir como sujeto un Portafolio o un
 * Trabajo, además de la foto suelta (`portfolio_item_id`, que se retirará en la
 * migración destructiva final). Los eventos existentes quedan con
 * `subject_type = 'portfolio_item'` mediante el valor por defecto temporal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_events', function (Blueprint $table) {
            $table->string('subject_type', 20)->default('portfolio_item')->after('user_id');
            $table->foreignId('portfolio_id')->nullable()->after('subject_type')
                ->constrained('portfolios')->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->after('portfolio_id')
                ->constrained('service_jobs')->cascadeOnDelete();
        });

        Schema::table('feed_events', function (Blueprint $table) {
            $table->unsignedBigInteger('portfolio_item_id')->nullable()->change();
        });

        Schema::table('feed_events', function (Blueprint $table) {
            $table->index(['user_id', 'portfolio_id', 'type', 'created_at'], 'feed_events_dedupe_portfolio_idx');
            $table->index(['user_id', 'job_id', 'type', 'created_at'], 'feed_events_dedupe_job_idx');
        });

        DB::table('feed_events')->whereNull('subject_type')->update(['subject_type' => 'portfolio_item']);
    }

    public function down(): void
    {
        DB::table('feed_events')->whereNull('portfolio_item_id')->delete();

        Schema::table('feed_events', function (Blueprint $table) {
            $table->dropIndex('feed_events_dedupe_portfolio_idx');
            $table->dropIndex('feed_events_dedupe_job_idx');
        });

        Schema::table('feed_events', function (Blueprint $table) {
            $table->dropForeign(['portfolio_id']);
            $table->dropForeign(['job_id']);
        });

        Schema::table('feed_events', function (Blueprint $table) {
            $table->dropColumn(['subject_type', 'portfolio_id', 'job_id']);
        });

        Schema::table('feed_events', function (Blueprint $table) {
            $table->unsignedBigInteger('portfolio_item_id')->nullable(false)->change();
        });
    }
};
