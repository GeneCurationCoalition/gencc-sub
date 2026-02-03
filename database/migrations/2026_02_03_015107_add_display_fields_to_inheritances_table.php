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
        Schema::table('inheritances', function (Blueprint $table) {
            $table->string('hex_color')->nullable()->after('style_class');
            $table->string('css_class')->nullable()->after('hex_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inheritances', function (Blueprint $table) {
            $table->dropColumn(['hex_color', 'css_class']);
        });
    }
};
