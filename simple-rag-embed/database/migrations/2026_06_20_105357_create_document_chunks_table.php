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
        Schema::create('document_chunk', function (Blueprint $table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->integer('document_id');
            $table->longText('content');
            $table->longText('embedding');
            $table->timestamps();

            $table->foreign('document_id')->on('document')->references('id')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_chunk');
    }
};
