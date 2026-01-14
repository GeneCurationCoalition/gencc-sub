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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('ident')->unique();
            $table->tinyInteger('type')->default(0);
            $table->foreignId('user_id');
            $table->foreignId('submitter_id');
            $table->foreignId('job_id');
            $table->string('file_name');
            $table->string('extension')->nullable();
            $table->string('mime_type');
            $table->integer('size');
            $table->string('original_path')->nullable();
            $table->string('local_path')->nullable();
            $table->string('disk')->default('local');
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
