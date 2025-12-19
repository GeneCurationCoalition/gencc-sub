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
        Schema::table('pubmeds', function (Blueprint $table) {
            // Add index on pmid column to speed up firstOrCreate() lookups
            // This significantly improves import:gencc performance when pubmeds table is not truncated
            $table->index('pmid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pubmeds', function (Blueprint $table) {
            $table->dropIndex(['pmid']);
        });
    }
};
