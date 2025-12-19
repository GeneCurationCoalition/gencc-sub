<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Query\Expression;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('ident')->unique();
            $table->tinyInteger('type')->default(0);
            $table->foreignId('user_id');
            $table->timestamp('submission_date');
            $table->jsonb('submission_data')->default(new Expression('(JSON_ARRAY())'));
            $table->jsonb('activity')->default(new Expression('(JSON_ARRAY())'));
            $table->tinyInteger('status')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // create some index keys
            $table->index('status');
            $table->index('submission_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
