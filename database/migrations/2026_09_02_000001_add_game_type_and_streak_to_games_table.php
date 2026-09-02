<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Which cue-sport ruleset: decides how many balls end the rack.
            $table->enum('game_type', ['eight_ball', 'nine_ball'])->default('eight_ball')->after('format');
            // Balls a side must pocket to win. Seeded from game_type, editable per game.
            $table->unsignedTinyInteger('target_score')->default(8)->after('game_type');

            // Winner-stays streak carried into this game (0 = a fresh game, no crown).
            $table->unsignedInteger('win_streak')->default(0)->after('winner_side');
            // Which side of THIS game holds the crown, when win_streak >= 1.
            $table->enum('champion_side', ['a', 'b'])->nullable()->after('win_streak');
            // The game the champion carried over from.
            $table->foreignId('previous_game_id')->nullable()->after('champion_side')
                ->constrained('games')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_game_id');
            $table->dropColumn(['game_type', 'target_score', 'win_streak', 'champion_side']);
        });
    }
};
