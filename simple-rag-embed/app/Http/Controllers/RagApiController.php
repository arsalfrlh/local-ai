<?php

namespace App\Http\Controllers;

use App\Models\Knowledge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class RagApiController extends Controller
{
    public function storeKnowLedge(Request $request){
        $validator = Validator::make($request->all(),[
            'context' => 'required',
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $response = Http::post("http://localhost:11434/api/embeddings",[
            'model' => "nomic-embed-text",
            "prompt" => $request->context
        ]);
        $json = $response->json();

        $knowledge = Knowledge::create([
            'context' => $request->context,
            'embedding' => json_encode($json['embedding'])
        ]);

        return response()->json(['message'=> 'Pengetahuan berhasil ditambahkan', 'success' => true, 'data' => $knowledge], 201);
    }

    public function ask(Request $request){
        set_time_limit(0);
        $validator = Validator::make($request->all(),[
            'question' => 'required',
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $response = Http::post("http://localhost:11434/api/embeddings",[
            'model' => "nomic-embed-text",
            "prompt" => $request->question
        ]);

        // dd($response->json());
        $queryEmbedding = $response->json()['embedding'];
        $knowledge = Knowledge::all();
        $result = [];
        foreach($knowledge as $item){
            $embedding = json_decode($item->embedding, true);

            $score = $this->cosineSimilarity($queryEmbedding, $embedding);
            $result[] = [
                'content' => $item->context,
                'score' => $score
            ];
        }

        usort($result, fn($a, $b) => $b['score'] <=> $a['score']);
        $bestContext = $result[0]['content'];

        $prompt = "
            Context:
            $bestContext

            Question:
            {$request->question}

            Jawab pertanyaan berdasarkan context di atas.
            ";

            $llmResponse = Http::timeout(300)->post(
                'http://localhost:11434/api/generate',
                [
                    'model' => 'qwen2.5-coder:3b',
                    'prompt' => $prompt,
                    'stream' => false
                ]
            );

            return response()->json(['context' => $bestContext, 'answer' => $llmResponse['response']]);
    }

    private function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        $dotProduct = 0;
        $normA = 0;
        $normB = 0;

        foreach ($vectorA as $i => $value) {
            $dotProduct += $value * $vectorB[$i];
            $normA += $value * $value;
            $normB += $vectorB[$i] * $vectorB[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0;
        }

        return $dotProduct / ($normA * $normB);
    }
}
