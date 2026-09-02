<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // Swatch a player fills the lineup wedge with, one per side.
            $table->char('side_a_color', 7)->default('#3a6f96')->after('nickname');
            $table->char('side_b_color', 7)->default('#c1483f')->after('side_a_color');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['side_a_color', 'side_b_color']);
        });
    }
};
