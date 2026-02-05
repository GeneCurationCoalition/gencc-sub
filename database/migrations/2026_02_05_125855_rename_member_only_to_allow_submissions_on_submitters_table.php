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
            $table->renameColumn('member_only', 'allow_submissions');
        });

        // Invert the boolean values (member_only=true means can't submit, allow_submissions=true means CAN submit)
        DB::statement('UPDATE submitters SET allow_submissions = NOT allow_submissions');

        // Change default to true (most submitters should be allowed to submit)
        Schema::table('submitters', function (Blueprint $table) {
            $table->boolean('allow_submissions')->default(true)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submitters', function (Blueprint $table) {
            $table->boolean('allow_submissions')->default(false)->change();
        });

        // Invert back
        DB::statement('UPDATE submitters SET allow_submissions = NOT allow_submissions');

        Schema::table('submitters', function (Blueprint $table) {
            $table->renameColumn('allow_submissions', 'member_only');
        });
    }
};
