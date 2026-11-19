<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_id')->constrained('portfolios')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'portfolio_id']);
            $table->index(['user_id', 'created_at', 'id'], 'portfolio_saves_user_order_idx');
            $table->index('portfolio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_saves');
    }
};
