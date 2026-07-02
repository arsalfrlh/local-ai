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
        Schema::create('chat_images', function (Blueprint $table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->integer('message_id');
            $table->string('image_name');
            $table->string('image_path');
            $table->timestamps();

            $table->foreign('message_id')->on('messages')->references('id')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_images');
    }
};
