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
        Schema::table('users', function (Blueprint $table) {
            $table->string('ident')->unique()->after('id');
            $table->tinyInteger('type')->default(0)->after('ident');
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('credentials')->nullable()->after('last_name');
            $table->jsonb('profile')->nullable()->after('email_verified_at');
            $table->jsonb('preferences')->nullable()->after('profile');
            $table->foreignId('submitter_id')->nullable()->after('preferences');
            $table->foreignId('team_id')->nullable()->after('submitter_id');
            $table->string('clingen_id')->nullable()->after('team_id');
            $table->string('api_token')->nullable()->after('remember_token');
            $table->timestamp('api_token_renewed_at')->nullable()->after('api_token');
            $table->string('device_token')->nullable()->after('api_token_renewed_at');
            $table->string('activation_token')->nullable()->after('device_token');
            $table->tinyInteger('role')->default(0)->after('activation_token');
            $table->tinyInteger('status')->default(0)->after('role');

            // create some index keys
            $table->index('api_token');
            $table->index('clingen_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
