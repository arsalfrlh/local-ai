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
        Schema::create('messages', function (Blueprint $table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->integer('chat_room_id');
            $table->enum('role',['user','assistant'])->default('user');
            $table->longText('message');
            $table->timestamps();

            $table->foreign('chat_room_id')->on('chat_rooms')->references('id')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
