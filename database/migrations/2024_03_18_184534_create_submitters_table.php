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
        Schema::create('submitters', function (Blueprint $table) {
            $table->id();
            $table->string('ident')->unique();
            $table->tinyInteger('type')->default(0);
            $table->string('curie');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->string('website')->nullable();
            $table->text('assertion')->nullable();
            $table->jsonb('counts')->default(new Expression('(JSON_ARRAY())'));
            $table->jsonb('activity')->default(new Expression('(JSON_ARRAY())'));
            $table->jsonb('contacts')->default(new Expression('(JSON_ARRAY())'));
            $table->text('notes')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // create some index keys
            $table->index('curie');
            $table->index('status');
            $table->index(['curie', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submitters');
    }
};
