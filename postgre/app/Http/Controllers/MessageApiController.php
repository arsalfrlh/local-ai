<?php

namespace App\Http\Controllers;

use App\Models\ChatRoom;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

class MessageApiController extends Controller
{
    public function index(Request $request){
        $user = $request->user();
        $data = ChatRoom::where('user_id', $user->id)->get();
        return response()->json(['message' => "Menampilkan semua data chat room", 'success' => true, 'data' => $data], 200);
    }

    public function createRoom(Request $request){
        $validator = Validator::make($request->all(),[
            'title' => 'required'
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $user = $request->user();
        $data = ChatRoom::create([
            'user_id' => $user->id,
            'title' => $request->title
        ]);

        return response()->json(['message' => "Chat Room berhasil dibuat", 'success' => true, 'data' => $data], 201);
    }

    public function show(Request $request, $id){
        $user = $request->user();

        $data = ChatRoom::with('message','document.documentChunk')->where('user_id', $user->id)->where('id', $id)->first();
        if(!$data){
            return response()->json(['message' => "Chat Room tidak dapat diakses", 'success' => false], 400);
        }
        return response()->json(['message' => "Menampilkan semua pesan", 'success' => true, 'data' => $data], 200);
    }

    public function store(Request $request){
        set_time_limit(0);
        $validator = Validator::make($request->all(),[
            'chat_room_id' => 'required|numeric',
            'message' => 'required',
            'documents' => 'nullable',
            'documents.*' => 'file|mimes:pdf,docx,txt',
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $user = $request->user();
        if(!ChatRoom::where('user_id', $user->id)->where('id', $request->chat_room_id)->exists()){
            return response()->json(['message' => "Anda tidak bisa akses chat room ini", 'success' => false], 400);
        }
        
        $isRag = false;
        if($request->hasFile('documents')){
            $isRag = true;
            foreach($request->file('documents') as $index => $ragFile){
                $extension = $ragFile->getClientOriginalExtension();
                $fileName = "rag_" . time() . ($index + 1) . '.' . $extension;
                $filePath = $ragFile->storeAs('files',$fileName,'public');

                $document = Document::create([
                    'user_id' => $user->id,
                    'chat_room_id' => $request->chat_room_id,
                    'file_name' => $fileName,
                    'file_path' => $filePath,
                    'file_type' => $extension
                ]);
                
                $rawText = $this->extractText(storage_path('app/public/' . $filePath), $extension);

                $text = $this->normalizeText($rawText);
                $text = $this->fixStructure($text);
                $text = $this->finalClean($text);

                $chunks = $this->chunkText($text);
                $qdrantPoints = [];
                foreach($chunks as $indexDoc => $chunk){
                    $responseEmbedding = Http::timeout(300)->post("http://host.docker.internal:11434/api/embeddings",[
                        'model' => 'nomic-embed-text',
                        'prompt' => $chunk
                    ]);

                    $embedding = $responseEmbedding->json()['embedding'];
                    $documentChunk = DocumentChunk::create([
                        'document_id' => $document->id,
                        'chunk_index' => $indexDoc + 1,
                        'content' => $chunk
                    ]);

                    $qdrantPoints[] = [
                        'id' => $documentChunk->id,
                        'vector' => $embedding,
                        'payload' => [
                            'user_id' => $user->id,
                            'chat_room_id' => $request->chat_room_id,
                            'document_id' => $document->id,
                            'document_chunk_id' => $documentChunk->id,
                            'chunk_index' => $indexDoc + 1,
                            'file_name' => $document->file_name,
                            'file_type' => $document->file_type,
                            'text' => $chunk
                        ]
                    ];
                }

                Http::timeout(300)->put("http://qdrant:6333/collections/documents/points",[
                    'points' => $qdrantPoints
                ]);
            }
        }
        // $isRag = Document::where('chat_room_id', $request->chat_room_id)->exists();

        $message = $request->message;
        if($isRag){
            $responseEmbeddingUser = Http::timeout(300)->post("http://host.docker.internal:11434/api/embeddings",[
                'model' => 'nomic-embed-text',
                'prompt' => $request->message
            ]);
            $embeddingUser = $responseEmbeddingUser->json()['embedding'];

            $responseQdrant = Http:: timeout(300)->post("http://qdrant:6333/collections/documents/points/search",[
                'vector' => $embeddingUser,
                'limit' => 3,
                'with_payload' => true,
                'filter' => [
                    'must' => [
                        [
                            'key' => 'user_id',
                            'match' => [
                                'value' => $user->id
                            ]
                        ],
                        [
                            'key' => 'chat_room_id',
                            'match' => [
                                'value' => $request->chat_room_id
                            ]
                        ]
                    ]
                ]
            ]);

            $result = collect($responseQdrant->json()['result'])->map(function ($item) {
                return $item['payload']['text'] ?? null;
            })->filter()->values()->toArray();
            $context = implode("\n\n", $result);

            $message = "
            Gunakan context berikut untuk menjawab pertanyaan user.

            Context:
            $context

            Question:
            {$request->message}

            Jika jawaban tidak ada di context, jawab:
            'Informasi tidak ditemukan dalam dokumen.'
            ";
        }

        $history = Message::where('chat_room_id', $request->chat_room_id)->orderBy('id', 'desc')->limit(10)->get()->reverse()->map(function ($message) {
            return [
                'role' => $message->role,
                'content' => $message->message,
            ];
        })->values()->toArray();

        Message::create([
            'chat_room_id' => $request->chat_room_id,
            'role' => 'user',
            'message' => $request->message,
            'is_rag' => $isRag
        ]);

        $response = Http::timeout(300)->post("http://host.docker.internal:11434/api/chat",[
            'model' => 'qwen2.5-coder:3b',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Kamu adalah Kwanza AI, sebuah asisten AI yang dibuat dan dikembangkan oleh Arsal Fahrulloh'
                ],
                ...$history,
                [
                    'role' => 'user',
                    'content' => $message
                ]
            ],
            'stream' => false
        ]);
        $assistentMessage = $response->json()['message']['content'] ?? "Tidak ada response AI";

        Message::create([
            'chat_room_id' => $request->chat_room_id,
            'role' => 'assistant',
            'message' => $assistentMessage,
            'is_rag' => $isRag
        ]);

        return response()->json(['message' => $assistentMessage, 'success' => true], 201);
    }

    private function extractText(string $path, string $extension): string
    {
        return match ($extension) {
            'pdf' => $this->extractPdf($path),
            'docx' => $this->extractDocx($path),
            'txt' => file_get_contents($path),
            default => throw new \Exception("Unsupported file type")
        };
    }
    
    private function extractPdf(string $path): string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($path);
        return $pdf->getText();
    }

    private function extractDocx(string $path): string
    {
        $phpWord = IOFactory::load($path);

        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->parseElement($element);
            }
        }

        return $text;
    }

    private function parseElement($element): string
    {
        $text = '';

        if (method_exists($element, 'getText') && is_string($element->getText())) {
            return $element->getText() . ' ';
        }

        if ($element instanceof TextRun) {
            foreach ($element->getElements() as $child) {
                $text .= $this->parseElement($child);
            }
            return $text . ' ';
        }

        if ($element instanceof Table) {
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $child) {
                        $text .= $this->parseElement($child);
                    }
                }
            }
            return $text . ' ';
        }
        return '';
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace('•', "\n", $text);

        return trim($text);
    }

    private function fixStructure(string $text): string
    {
        $text = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $text);
        $text = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $text);
        $text = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $text);
        $text = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $text);

        return $text;
    }

    private function finalClean(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function chunkText(string $text, int $chunkSize = 1000, int $overlap = 150): array
    {
        $sentences = preg_split('/(?<=[\.!?])\s+/', $text);

        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {

            if (strlen($current . ' ' . $sentence) < $chunkSize) {
                $current .= ' ' . $sentence;
            } else {
                $chunks[] = trim($current);
                $current = substr($current, -$overlap) . ' ' . $sentence;
            }
        }

        if (!empty(trim($current))) {
            $chunks[] = trim($current);
        }

        return $chunks;
    }
}

// jika di qdrant pointnya ditambahkan ini saat post data atau ambil data
// 'with_payload' => true
// {
//   "id": 501,
//   "score": 0.92,
//   "payload": {
//     "text": "Laravel menggunakan MVC"
//   }
// }

// 'with_vector' => true
// {
//   "id": 501,
//   "vector": [0.1, 0.2, 0.3],
//   "payload": {...}
// }

// 'filter' => [...]
// 'must' => [
//    user_id = 1
//    chat_room_id = 5
// ]
// user_id harus 1
// DAN
// chat_room_id harus 5

// 'should' => [...]
// kategori = backend
// ATAU
// kategori = ai

// 'must_not' => [...]
// jangan ambil document_id = 20

// 'score_threshold' => 0.7
// score < 0.7 → dibuang
// score >= 0.7 → diterima

// 'params' => [
//    'hnsw_ef' => 128
// ]

// $search = Http::post("http://qdrant:6333/collections/documents/points/search", [
//     'vector' => $queryEmbedding,
//     'limit' => 3,
//     'with_payload' => true,
//     'filter' => [
//         'must' => [
//             [
//                 'key' => 'user_id',
//                 'match' => [
//                     'value' => $user->id
//                 ]
//             ],
//             [
//                 'key' => 'chat_room_id',
//                 'match' => [
//                     'value' => $request->chat_room_id
//                 ]
//             ]
//         ]
//     ]
// ]);