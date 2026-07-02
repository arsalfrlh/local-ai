<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ProductApiController extends Controller
{
    public function index(Request $request){
        $data = Product::all();
        return response()->json(['message' => "Menampilkan data product", 'success' => true, 'data' => $data], 200);
    }

    public function store(Request $request){
        set_time_limit(0);
        $validator = Validator::make($request->all(),[
            'product_name' => 'required',
            'category_name' => 'required',
            'description' => 'required',
            'stock' => 'required|numeric',
            'price' => 'required|numeric',
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $product = Product::create($request->all());
        $text = $request->category_name . ' ' . $request->product_name . ' ' . $request->description;
        $response = Http::timeout(300)->post("http://localhost:11434/api/embeddings",[
            'model' => 'mxbai-embed-large',
            'prompt' => $text
        ]);
        $embedding = $response->json()['embedding'];
        Http::timeout(300)->put("http://localhost:6333/collections/products/points",[
            'points' => [
                [
                    'id' => $product->id,
                    'vector' => $embedding,
                    'payload' => [
                        'product_name' => $product->product_name,
                        'category_name' => $product->category_name,
                        'description' => $product->description
                    ]
                ]
            ]
        ]);

        return response()->json(['message' => "Product berhasil di tambahkan", 'success' => true, 'data' => $product], 201);
    }
}
