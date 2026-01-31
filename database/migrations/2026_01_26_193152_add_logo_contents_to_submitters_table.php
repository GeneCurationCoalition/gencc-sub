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
            // Store logo binary contents (MEDIUMBLOB supports up to 16MB)
            $table->mediumText('logo_contents')->nullable()->after('logo');
            // Store the MIME type for proper content-type header
            $table->string('logo_mime_type', 50)->nullable()->after('logo_contents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submitters', function (Blueprint $table) {
            $table->dropColumn(['logo_contents', 'logo_mime_type']);
        });
    }
};
