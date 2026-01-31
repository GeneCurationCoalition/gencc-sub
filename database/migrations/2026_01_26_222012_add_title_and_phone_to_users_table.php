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
        // Add the new columns
        Schema::table('users', function (Blueprint $table) {
            $table->string('title', 100)->nullable()->after('last_name');
            $table->string('phone', 50)->nullable()->after('title');
        });

        // Migrate data from profile JSON to new columns
        DB::table('users')->whereNotNull('profile')->orderBy('id')->each(function ($user) {
            $profile = json_decode($user->profile, true);
            if ($profile && (isset($profile['title']) || isset($profile['phone']))) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'title' => $profile['title'] ?? null,
                        'phone' => $profile['phone'] ?? null,
                    ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Migrate data back to profile JSON before dropping columns
        DB::table('users')->orderBy('id')->each(function ($user) {
            if ($user->title || $user->phone) {
                $profile = $user->profile ? json_decode($user->profile, true) : [];
                $profile['title'] = $user->title;
                $profile['phone'] = $user->phone;
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['profile' => json_encode($profile)]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['title', 'phone']);
        });
    }
};
