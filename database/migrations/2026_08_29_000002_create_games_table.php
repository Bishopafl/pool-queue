<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->enum('format', ['1v1', '2v1', '2v2']);
            $table->enum('status', ['in_progress', 'completed', 'abandoned'])->default('in_progress');
            $table->string('table_label', 40)->nullable();

            $table->enum('break_side', ['a', 'b'])->nullable();
            $table->enum('side_a_ball_group', ['stripes', 'solids'])->nullable();
            $table->enum('side_b_ball_group', ['stripes', 'solids'])->nullable();

            $table->unsignedSmallInteger('side_a_score')->default(0);
            $table->unsignedSmallInteger('side_b_score')->default(0);
            $table->enum('winner_side', ['a', 'b'])->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
