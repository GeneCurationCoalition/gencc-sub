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
        if (Schema::hasTable('submitter_user')) {
            return;
        }

        Schema::create('submitter_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submitter_id')->constrained('submitters')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_contact')->default(false);
            $table->timestamps();

            $table->unique(['submitter_id', 'user_id']);
        });

        // Migrate existing user-submitter relationships from users.submitter_id
        DB::table('users')
            ->whereNotNull('submitter_id')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('submitter_user')->insert([
                    'submitter_id' => $user->submitter_id,
                    'user_id' => $user->id,
                    'is_contact' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submitter_user');
    }
};
