<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->enum('side', ['Buy', 'Sell']);
            $table->decimal('entry_price', 18, 12)->nullable();
            $table->decimal('exit_price', 18, 12)->nullable();
            $table->decimal('qty', 18, 1)->default(0);
            $table->decimal('pnl_usd', 18, 12)->nullable();
            $table->decimal('pnl_percent', 8, 2)->nullable();
            $table->decimal('pnl_on_margin', 8, 2)->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('source')->nullable(); // например "ChatGPT"
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
