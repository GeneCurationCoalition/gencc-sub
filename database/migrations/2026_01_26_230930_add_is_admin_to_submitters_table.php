<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('submitters', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('status');
        });

        // Set "GenCC Administrator" submitter as admin
        DB::table('submitters')
            ->where('name', 'GenCC Administrator')
            ->update(['is_admin' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submitters', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
