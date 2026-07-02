<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = "orders";
    protected $fillable = ['user_id','product_id','buyer_name','product_name','quantity','subtotal','order_status','order_at'];

    function user(){
        return $this->belongsTo(User::class,'user_id');
    }

    function product(){
        return $this->belongsTo(Product::class,'product_id');
    }
}
