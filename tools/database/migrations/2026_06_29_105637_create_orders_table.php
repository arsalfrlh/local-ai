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
        Schema::create('orders', function (Blueprint $table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->integer('user_id');
            $table->integer('product_id');
            $table->string('buyer_name');
            $table->string('product_name');
            $table->integer('quantity');
            $table->integer('subtotal');
            $table->enum('order_status',['waiting','success','cancel'])->default('waiting');
            $table->dateTime('order_at');
            $table->timestamps();

            $table->foreign('user_id')->on('users')->references('id')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('product_id')->on('products')->references('id')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
