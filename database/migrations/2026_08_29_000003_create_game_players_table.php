<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->enum('side', ['a', 'b']);
            $table->timestamps();

            $table->unique(['game_id', 'player_id']);
            $table->index(['player_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
