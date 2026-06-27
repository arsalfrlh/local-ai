<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class BarangApiController extends Controller
{
    public function index(Request $request){
        if($request->get('search')){
            $response = Http::post("http://host.docker.internal:11434/api/embeddings",[
                'model' => 'nomic-embed-text',
                'prompt' => $request->search
            ]);
            $embedding = $response->json()['embedding'];
            $vectorString = '[' . implode(',', $embedding) . ']';
            $data = DB::select("
                SELECT id, nama_barang, kategori, deskripsi,
                    embedding <=> ? AS distance
                FROM barangs
                ORDER BY distance ASC
                LIMIT 10
            ", [$vectorString]);
        }else{
            $data = Barang::select(['id','nama_barang','kategori','deskripsi'])->get();
        }
        return response()->json(['message' => "Menampilkan semua data barang", 'success' => true, 'data' => $data], 200);
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

        // dd($response->json());
        $embedding = $response->json()['embedding'];
        $vectorString = '[' . implode(',', $embedding) . ']';
        $barang = Barang::create($request->only(['nama_barang','kategori','deskripsi']));
        DB::statement("UPDATE barangs SET embedding = ? WHERE id = ?",[$vectorString,$barang->id]);

        return response()->json(['message' => "Barang berhasil ditambahkan", 'success' => true, 'data' => $barang], 201);
    }

    public function recommendation($id){
        $barang = Barang::find($id);
        if(!$barang){
            return response()->json(['message' => "Barang tidak ditemukan", 'success' => false], 401);
        }
        
        $data = DB::select("
            SELECT id, nama_barang, kategori, deskripsi,
                embedding <=> (
                        SELECT embedding FROM barangs WHERE id = ?
                ) AS distance
            FROM barangs
            WHERE id != ?
            ORDER BY distance ASC
            LIMIT 5
        ", [$id, $id]);

        return response()->json(['message' => "Menampilkan rekomendasi barang", 'success' => true, 'data' => $data], 200);
    }
}
