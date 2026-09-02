<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Which side is at the table right now. Null until someone breaks
            // or the operator hands it over.
            $table->enum('shooting_side', ['a', 'b'])->nullable()->after('break_side');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('shooting_side');
        });
    }
};
