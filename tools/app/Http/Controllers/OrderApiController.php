<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderApiController extends Controller
{
    public function index(Request $request){
        $user = $request->user();
        $data = Order::with('user','product')->where('user_id', $user->id)->get();
        return response()->json(['message' => "Menampilkan semua order", 'success' => true, 'data' => $data], 200);
    }

    public function store(Request $request){
        $validator = Validator::make($request->all(),[
            'product_id' => 'required|numeric',
            'quantity' => 'required|numeric',
            'order_status' => 'required'
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $user = $request->user();
        $product = Product::findOrFail($request->product_id);
        $subtotal = $request->quantity * $product->price;
        $data = Order::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'buyer_name' => $user->name,
            'product_name' => $product->product_name,
            'quantity' => $request->quantity,
            'subtotal' => $subtotal,
            'order_status' => $request->order_status,
            'order_at' => now()
        ]);

        return response()->json(['message' => "Order berhasil dibuat", 'success' => true, 'data' => $data], 201);
    }
}
