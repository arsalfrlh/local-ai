<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class QdrantApiController extends Controller
{
    public function index(Request $request){
        $search = null;
        if($request->get('search')){
            $response = Http::post("http://host.docker.internal:11434/api/embeddings",[
                'model' => 'nomic-embed-text',
                'prompt' => $request->search
            ]);
            $embedding = $response->json()['embedding'];

            $search = Http::post("http://qdrant:6333/collections/barangs/points/search", [
                'vector' => $embedding,
                'limit' => 3
            ]);
            $ids = collect($search->json()['result'])->pluck('id')->toArray(); //isi data dari qdrant aray result adalah list dan di ubah jadi collection lalu di mapping hanya ambil id nya saja contoh akhir [20,21,23]
            $data = Barang::select(['id','nama_barang','kategori','deskripsi'])->whereIn('id', $ids)->get();
        }else{
            $data = Barang::select(['id','nama_barang','kategori','deskripsi'])->get();
        }
        return response()->json(['message' => "Menampilkan semua data barang", 'success' => true, 'data' => $data, 'search' => !is_null($search) ? $search->json() : null], 200);
    }
    
    public function store(Request $request){
        $validator = Validator::make($request->all(),[
            'nama_barang' => 'required',
            'kategori' => 'required',
            'deskripsi' => 'required'
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $text = $request->nama_barang . ' ' . $request->kategori . ' ' . $request->deskripsi;
        $response = Http::post("http://host.docker.internal:11434/api/embeddings", [
            'model' => 'nomic-embed-text',
            'prompt' => $text
        ]);

        $embedding = $response->json()['embedding'];
        $vectorString = '[' . implode(',', $embedding) . ']';
        $barang = Barang::create($request->only(['nama_barang','kategori','deskripsi']));
        DB::statement("UPDATE barangs SET embedding = ? WHERE id = ?",[$vectorString,$barang->id]);

        //kirim vector ke qdrant
        Http::put("http://qdrant:6333/collections/barangs/points",[
            'points' => [
                [
                    'id' => $barang->id,
                    'vector' => $embedding,
                    'payload' => [
                        'kategori' => $barang->kategori
                    ]
                ]
            ]
        ]);

        return response()->json(['message' => "Barang berhasil ditambahkan", 'success' => true, 'data' => $barang], 201);
    }

    public function recommendation($id){
        $barang = Barang::find($id);
        if(!$barang){
            return response()->json(['message' => "Barang tidak ditemukan", 'success' => false], 401);
        }
        
        $point = Http::get("http://qdrant:6333/collections/barangs/points/$id");
        $vector = $point->json()['result']['vector'];
        $search = Http::post("http://qdrant:6333/collections/barangs/points/search", [
            'vector' => $vector,
            'limit' => 5
        ]);
        $ids = collect($search->json()['result'])->pluck('id')->filter(fn($item) => $item != $id)->toArray();
        $data = Barang::select(['id','nama_barang','kategori','deskripsi'])->whereIn('id', $ids)->get();

        return response()->json(['message' => "Menampilkan rekomendasi barang", 'success' => true, 'data' => $data], 200);
    }
}

// contoh isi data dari qdrant
// "search": {
//     "result": [
//         {
//             "id": 24,
//             "version": 4,
//             "score": 0.494449
//         },
//         {
//             "id": 25,
//             "version": 5,
//             "score": 0.46370816
//         },
//         {
//             "id": 26,
//             "version": 6,
//             "score": 0.4633152
//         }
//     ],
//     "status": "ok",
//     "time": 0.002317322
// }


// DELETE /collections/barangs/points/21