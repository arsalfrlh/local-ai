<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class AiApiController extends Controller
{
    public function ask(Request $request){
        set_time_limit(0);
        $validator = Validator::make($request->all(),[
            'question' => 'required'
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $user = $request->user();
        $response = Http::timeout(300)->post("http://localhost:11434/api/chat",[
            'model' => 'qwen3.5:4b',
            'stream' => false,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "
                        You are Kwanza AI ecommerce assistant, developed by Arsal Fahrulloh.

                        You can answer general questions normally.

                        Use tools only when you need:
                        - user profile data
                        - product data
                        - order data
                        - search document

                        Do not use tools for general knowledge, casual conversation, or conceptual explanations.

                        Always use tool results when user asks for personal or database-related information.
                        Do not make up database information.
                    "
                ],
                [
                    'role' => 'user',
                    'content' => $request->question
                ]
            ],
            'tools' => $this->tools() //function ini adalah daftar tools yg bisa di pakai oleh AI
        ]);

        $data = $response->json();
        $toolCalls = $data['message']['tool_calls'] ?? []; //cek ada tidak tools yg ingin digunakan oleh AI
        if(empty($toolCalls)){
            return response()->json(['message' => $data['message']['content'], 'success' => true], 200);
        }

        $toolMessages = [];
        foreach ($toolCalls as $toolCall) {
            $toolName = $toolCall['function']['name'];
            $arguments = $toolCall['function']['arguments'] ?? [];

            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true);
            }

            $result = $this->executeTools($toolName, $arguments, $user);

            $toolMessages[] = [ //ini hasil dari tools yg sudah di eksekusi dan siap dipakai oleh AI
                'role' => 'tool', //role tool
                'tool_name' => $toolName, //nama tool yang di pakainya
                'content' => json_encode($result) //isi dari tool
            ];
        }

        $messages = [
            [
                'role' => 'system',
                'content' => "
                    You are Kwanza AI ecommerce assistant, developed by Arsal Fahrulloh.

                    You can answer general questions normally.

                    Use tools only when you need:
                    - user profile data
                    - product data
                    - order data
                    - search document

                    Do not use tools for general knowledge, casual conversation, or conceptual explanations.

                    Always use tool results when user asks for personal or database-related information.
                    Do not make up database information.
                "
            ],
            [
                'role' => 'user',
                'content' => $request->question
            ],
            $data['message'] //ini adalah hasil response AI pertama yg paggil tools calling
            //contoh isi datanya seperti ini
            // "message": {
            //     "role": "assistant",
            //     "content": "",
        ];
        $messages = array_merge($messages, $toolMessages); //ini menambahkan data array kedalam list
        $finalResponse = Http::timeout(300)->post("http://localhost:11434/api/chat", [
            'model' => 'qwen3.5:4b',
            'stream' => false,
            'messages' => $messages
        ]);

        $finalData = $finalResponse->json();

        return response()->json(['success' => true, 'message' => $finalData['message']['content']], 200);
    }

    private function tools()
    {
        return [
            [
                "type" => "function",
                "function" => [
                    "name" => "get_profile",
                    "description" => "Retrieve current authenticated user profile including id, name, and email. Use this when user asks about their account information, profile, name, or email.",
                    "parameters" => [
                        "type" => "object",
                        "properties" => new \stdClass() //ini isinya jadi {} kalo di dart
                    ]
                ]
            ],
            [
                "type" => "function",
                "function" => [
                    "name" => "search_product",
                    "description" => "Search products from product database using semantic search. Use this when user asks about products, recommendations, prices, categories, or product details.",
                    "parameters" => [
                        "type" => "object",
                        "required" => ["query"], //ini artinya query itu wajib di return di response AI(['function']['arguments']) jika menggunakan tools ini
                        "properties" => [ //di properti ini bisa di custome isi list dan array keynya
                            "query" => [
                                "type" => "string" //tipe data si argument string
                            ],
                            // "is_find" => [ //bisa banyak data juga
                            //     "type" => "boolean"
                            // ]
                        ]
                    ]
                ]
            ],
            [
                "type" => "function",
                "function" => [
                    "name" => "get_order",
                    "description" => "Retrieve authenticated user orders and transaction history. Can optionally filter by order status: waiting, success, or cancel. Use this when user asks about orders, purchases, transaction history, or order status.",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "status" => [
                                "type" => "string", //tipe data status hasil argument dari response AI
                                "enum" => ["waiting", "success", "cancel"], //hasil response argumentnya itu harus enum seperti ini
                                "description" => "Filter orders by status. Valid values: waiting, success, cancel" //deskripsi dari isi argument agar AI bisa memahami context yg harus di return nantinya
                            ]
                        ]
                    ]
                ]
            ],
            [
                "type" => "function",
                "function" => [
                    "name" => "search_document",
                    "description" => "Search company documents using semantic search. Use this when user asks about company SOP, FAQ, policies, return rules, refund terms, shipping rules, internal guidelines, company regulations, or any document-based information.",
                    "parameters" => [
                        "type" => "object",
                        "required" => ["query"],
                        "properties" => [
                            "query" => [
                                "type" => "string",
                                "description" => "Question or keyword used to search relevant document chunks"
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    private function executeTools($toolName, $argument, $user){
        if($toolName == "get_profile"){
            return $this->toolsCurrentUser($user);
        }
        
        if($toolName == "search_product"){
            return $this->toolsSearchProduct($argument['query']);
        }

        if($toolName == "get_order"){
            return $this->toolsGetOrder($user, $argument['status'] ?? null);
        }

        if($toolName == "search_document"){
            return $this->toolsSearchDocument($argument['query'] ?? "");
        }
        
        return [
            'error' => 'Unknown tool'
        ];
    }

    private function toolsSearchDocument($text){
        $embedding = $this->embedding($text);
        $response = Http::post("http://localhost:6333/collections/document-companies/points/search",[
            'vector' => $embedding,
            'limit' => 3,
            'with_payload' => true
        ]);

        $result = collect($response->json()['result'])->map(function($item){
            return $item['payload']['content'] ?? null;
        })->toArray();
        $context = implode("\n\n", $result);
        return $context;
    }

    private function toolsCurrentUser($user){
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email
        ];
    }

    private function toolsGetOrder($user, $status = null){
        $data = Order::with('user','product')->where('user_id', $user->id);
        $data->when(!is_null($status), function($query) use ($status){
            $query->where('order_status',$status);
        });
        $data = $data->get()->toArray();
        return $data;
    }

    private function toolsSearchProduct($text){
        $embedding = $this->embedding($text);
        $response = Http::timeout(300)->post("http://localhost:6333/collections/products/points/search",[
            'vector' => $embedding,
            'limit' => 5
        ]);
        $ids = collect($response->json()['result'])->pluck('id')->toArray();
        $data = Product::whereIn('id', $ids)->get()->keyBy('id')->toArray();
        return $data;
    }

    private function embedding($text){
        set_time_limit(0);
        $response = Http::timeout(300)->post("http://localhost:11434/api/embeddings",[
            'model' => 'mxbai-embed-large',
            'prompt' => $text
        ]);
        return $response->json()['embedding'];
    }
}

// contoh response AI tools calling
// {
//     "model": "qwen3.5:4b",
//     "created_at": "2026-06-29T12:26:43.547318Z",
//     "message": {
//         "role": "assistant",
//         "content": "",
//         "thinking": "The user is asking me to search for affordable/gaming laptops in Indonesian language (\"murah\" means cheap/affordable). I need to use the search_product function with this query translated or kept as it is since the system supports multiple languages.\n\nLet me translate \"laptop gaming murah\" - this would be a product search for:\n- laptop (gaming) that is affordable/cheap in price\n\nI'll use the Indonesian terms directly since that's how the user asked, which should work fine with semantic search.",
//         "tool_calls": [
//             {
//                 "id": "call_p9t5fsvk",
//                 "function": {
//                     "index": 0,
//                     "name": "search_product",
//                     "arguments": {
//                         "query": "laptop gaming murah"
//                     }
//                 }
//             }
//         ]
//     },
//     "done": true,
//     "done_reason": "stop",
//     "total_duration": 48054473200,
//     "load_duration": 12019761800,
//     "prompt_eval_count": 407,
//     "prompt_eval_duration": 18461841000,
//     "eval_count": 137,
//     "eval_duration": 17533109000
// }