<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every event the pool-vision camera sends, whether or not it changed the
     * score: an audit trail for disputes, and the key that makes retries safe.
     */
    public function up(): void
    {
        Schema::create('vision_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique(); // chosen by pool-vision; a retry repeats it
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->string('table_label', 40)->nullable();
            $table->string('type', 20); // pot
            $table->string('kind', 20); // cue | eight | solid | stripe | unknown
            $table->string('pocket', 40)->nullable();
            $table->decimal('kind_conf', 4, 2)->nullable();
            $table->decimal('video_t', 10, 2)->nullable(); // seconds, pool-vision's clock
            $table->boolean('applied')->default(false);
            $table->string('outcome'); // what the server did with it, in words
            $table->timestamps();

            $table->index(['game_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_events');
    }
};
