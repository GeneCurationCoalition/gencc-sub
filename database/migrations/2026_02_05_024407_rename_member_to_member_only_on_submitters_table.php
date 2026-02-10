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
        Schema::table('submitters', function (Blueprint $table) {
            $table->renameColumn('member', 'member_only');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submitters', function (Blueprint $table) {
            $table->renameColumn('member_only', 'member');
        });
    }
};
