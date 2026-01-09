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
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('updated_at');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('released_at');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('released_at');
        });
    }
};
