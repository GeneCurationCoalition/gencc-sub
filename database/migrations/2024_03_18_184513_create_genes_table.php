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
        Schema::create('genes', function (Blueprint $table) {
            $table->id();
            $table->string('ident')->unique();
            $table->tinyInteger('type')->default(0);
            $table->string('hgnc_id');
            $table->string('symbol');
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('alias_symbols')->nullable();
            $table->jsonb('previous_symbols')->nullable();
            $table->jsonb('alias_names')->nullable();
            $table->json('previous_names')->nullable();
            $table->timestamp('date_symbol_changed')->nullable();
            $table->timestamp('date_name_changed')->nullable();
            $table->string('locus_group');
            $table->string('locus_type');
            $table->integer('gene_group_id')->nullable();
            $table->string('gene_group')->nullable();
            $table->string('location');
            $table->jsonb('coordinates')->default(new Expression('(JSON_ARRAY())'));
            $table->jsonb('xrefs')->default(new Expression('(JSON_ARRAY())'));
            $table->jsonb('scores')->default(new Expression('(JSON_ARRAY())'));
            $table->jsonb('counts')->default(new Expression('(JSON_ARRAY())'));
            $table->jsonb('activity')->default(new Expression('(JSON_ARRAY())'));
            $table->jsonb('events')->default(new Expression('(JSON_ARRAY())'));
            $table->text('notes')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // create some index keys
            $table->index('symbol');
            $table->index('hgnc_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('genes');
    }
};
