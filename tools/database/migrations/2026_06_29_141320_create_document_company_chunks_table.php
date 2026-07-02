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
        Schema::create('document_company_chunks', function (Blueprint $table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->integer('document_company_id');
            $table->integer('chunk_index');
            $table->string('content');
            $table->timestamps();
            
            $table->foreign('document_company_id')->on('document_companies')->references('id')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_company_chunks');
    }
};
