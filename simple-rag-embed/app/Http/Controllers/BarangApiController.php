<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class BarangApiController extends Controller
{
    public function store(Request $request){
        $validator = Validator::make($request->all(),[
            'name' => 'required'
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $response = Http::post("http://localhost:11434/api/embeddings",[
            'model' => 'nomic-embed-text',
            'prompt' => $request->name
        ]);

        $data = Barang::create([
            'name' => $request->name,
            'embedding' => json_encode($response['embedding'])
        ]);

        return response()->json(['message' => "Barang berhasil ditambahkan", 'success' => true, 'data' => $data], 200);
    }

    public function search(Request $request){
        $validator = Validator::make($request->all(),[
            'query' => 'required'
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $response = Http::post("http://localhost:11434/api/embeddings",[
            'model' => 'nomic-embed-text',
            'prompt' => $request->input('query')
        ]);
        $queryEmbedding = $response->json()['embedding'];

        $barang = Barang::all();
        $data = [];
        foreach($barang as $item){
            $barangEmbedding = json_decode($item->embedding, true);
            $score = $this->cosineSimilarity($queryEmbedding, $barangEmbedding);

            $data[] = [
                'id' => $item->id,
                'name' => $item->name,
                'score' => $score
            ];
        }

        usort($data, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        return response()->json(['message' => "Menampilkan hasil pencarian", 'success' => true, 'data' => $data], 200);
    }

    private function cosineSimilarity(array $vectorA,array $vectorB): float {

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
