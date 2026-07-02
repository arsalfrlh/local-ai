<?php

namespace App\Http\Controllers;

use App\Models\ChatRoom;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Message;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

class MessageApicontroller extends Controller
{
    public function index(Request $request){
        $user = $request->user();
        $data = ChatRoom::where('user_id', $user->id)->get();
        return response()->json(['message' => "Menampilkan semua data Room anda", 'success' => true, 'data' => $data], 200);
    }

    public function update(Request $request, $title){
        $user = $request->user();
        $data = ChatRoom::create([
            'title' => $title,
            'user_id' => $user->id
        ]);

        return response()->json(['message' => "Room berhasil dibuat", 'success' => true, 'data' => $data], 201);
    }

    public function store(Request $request){
        set_time_limit(0);
        $validator = Validator::make($request->all(),[
            'chat_room_id' => 'required|numeric',
            'message' => 'required',
            'images' => 'nullable',
            'images.*' => 'image|mimes:png,jpg,jpeg',
            'documents' => 'nullable',
            'documents.*' => 'file|mimes:pdf,docx,txt'
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        $user = $request->user();
        if(!ChatRoom::where('user_id', $user->id)->where('id', $request->chat_room_id)->exists()){
            return response()->json(['message' => "Anda tidak bisa mengakses Room ini", 'success' => false], 401);
        }

        $message = Message::create([
            'chat_room_id' => $request->chat_room_id,
            'role' => 'user',
            'message' => $request->message
        ]);
        
        $history = Message::where('chat_room_id', $request->chat_room_id)->orderBy('id','desc')->limit(10)->get()->reverse()->map(function($message){
            return [
                'role' => $message->role,
                'content' => $message->message
            ];
        });

        $isUploadImage = false;
        $isUploadFile = Document::where('chat_room_id', $request->chat_room_id)->exists();
        $isRag = false;
        $images = [];
        if($request->hasFile('documents')){
            $isRag = true;
            foreach($request->file('documents') as $index => $file){
                $extension = $file->getClientOriginalExtension();
                $fileName = "document_" . time() . ($index + 1) . '.' . $extension;
                $filePath = $file->storeAs('documents/chat_' . $request->chat_room_id, $fileName, 'public');
                $document = Document::create([
                    'chat_room_id' => $request->chat_room_id,
                    'message_id' => $message->id,
                    'file_name' => $fileName,
                    'file_path' => $filePath,
                    'file_type' => $extension
                ]);

                $rawText = $this->extractText(storage_path('app/public/' . $filePath), $extension);
                $text = $this->normalizeText($rawText);
                $text = $this->fixStructure($text);
                $text = $this->finalClean($text);

                $chunks = $this->chunkText($text);
                $qdrantPoint = [];
                foreach($chunks as $indexChunk => $chunk){
                    $documentChunk = DocumentChunk::create([
                        'document_id' => $document->id,
                        'index_chunk' => $indexChunk + 1,
                        'content' => $chunk
                    ]);

                    $embedding = $this->embedding($chunk);
                    $qdrantPoint[] = [
                        'id' => $documentChunk->id,
                        'vector' => $embedding,
                        'payload' => [
                            'user_id' => $user->id,
                            'chat_room_id' => $request->chat_room_id,
                            'document_id' => $documentChunk->document_id,
                            'index_chunk' => $indexChunk + 1,
                            'file_name' => $document->file_name,
                            'file_path' => $document->file_path,
                            'extension' => $document->file_type,
                            'text' => $chunk
                        ]
                    ];
                }
                
                Http::timeout(300)->put("http://localhost:6333/collections/documents/points",[
                    'points' => $qdrantPoint
                ]);
            }
        }

        if($request->hasFile('images')){
            $isUploadImage = true;
            foreach($request->file('images') as $index => $image){
                $nmImage = "images_" . time() . ($index + 1) . '.' . $image->getClientOriginalExtension();
                $imagePath = $image->storeAs('images/chat_' . $request->chat_room_id,$nmImage,'public');
                $fileContent = Storage::disk('public')->get($imagePath);
                $base64Image = base64_encode($fileContent);
                $images[] = $base64Image;
            }
        }

        $moreTools = "";
        if($isUploadFile){
            $moreTools = "- search uploaded document";
        }
        $messages = [
            [
                'role' => 'system',
                'content' => "
                    You are Kwanza AI, developed by Arsal Fahrulloh.

                    You can answer general questions normally.

                    Use tools only when you need:
                    - search web
                    $moreTools

                    Do not use tools for general knowledge, casual conversation, or conceptual explanations.

                    Always use tool results when user asks for personal or database-related information.
                    Do not make up database information.
                "
            ],
            ...$history
        ];

        if($isRag){
            $messages[] = $this->contextRag($request->chat_room_id, $request->message);
        }

        if($isUploadImage){
            $response = Http::timeout(300)->post("http://localhost:11434/api/chat",[
                'model' => 'qwen3.5:4b',
                'stream' => false,
                'images' => $images,
                'messages' => $messages,
                'tools' => $this->tools($isUploadFile)
            ]);
        }else{
            $response = Http::timeout(300)->post("http://localhost:11434/api/chat",[
                'model' => 'qwen3.5:4b',
                'stream' => false,
                'messages' => $messages,
                'tools' => $this->tools($isUploadFile)
            ]);
        }

        $data = $response->json();
        $toolCalls = $data['message']['tool_calls'] ?? [];
        if(empty($toolCalls)){
            $responseAssistent = Message::create([
                'chat_room_id' => $request->chat_room_id,
                'role' => 'assistant',
                'message' => $data['message']['content']
            ]);

            return response()->json(['message' => $responseAssistent->message, 'success' => true], 200);
        }

        $toolMessages = [];
        foreach ($toolCalls as $toolCall) {
            $toolName = $toolCall['function']['name'];
            $arguments = $toolCall['function']['arguments'] ?? [];

            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true);
            }

            $result = $this->executeTools($toolName, $arguments, $request->chat_room_id);

            $toolMessages[] = [
                'role' => 'tool',
                'tool_name' => $toolName,
                'content' => json_encode($result)
            ];
        }

        $responseAssistent = [
            'role' => $data['message']['role'],
            'content' => $data['message']['content'],
            'thinking' => $data['message']['thinking'],
            'tool_calls' => $toolCalls
        ];
        $messages[] = $responseAssistent;
        $messages = array_merge($messages, $toolMessages);
        // dd($messages);
        $finalResponse = Http::timeout(300)->post("http://localhost:11434/api/chat", [
            'model' => 'qwen3.5:4b',
            'stream' => false,
            'messages' => $messages
        ]);

        $finalData = $finalResponse->json();
        $finalResponseAssistent = Message::create([
            'chat_room_id' => $request->chat_room_id,
            'role' => 'assistant',
            'message' => $finalData['message']['content']
        ]);
        return response()->json(['message' => $finalResponseAssistent->message, 'success' => true, 'tools' => $toolMessages], 200);
    }

    private function contextRag($chatRoomId, $question){
        $embedding = $this->embedding($question);
        $response = Http::timeout(300)->post("http://localhost:6333/collections/documents/points/search",[
            'vector' => $embedding,
            'limit' => 3,
            'with_payload' => true,
            'filter' => [
                'must' => [
                    [
                        'key' => 'chat_room_id',
                        'match' => [
                            'value' => $chatRoomId
                        ]
                    ]
                ]
            ]
        ]);

        $result = collect($response->json()['result'])->map(function ($item) {
            return $item['payload']['text'] ?? null;
        })->filter()->values()->toArray();
        $context = implode("\n\n", $result);
        $contextSystem = [
            'role' => 'system',
            'content' => "
                Use the provided document context to answer the user's question.

                Rules:
                - Use document context as the primary source of truth.
                - Answer only based on the provided context.
                - Do not invent or assume information.
                - If the answer is not available in the context, reply exactly:
                Information not found in the uploaded document.

                Document Context:
                {$context}
            "
        ];
        return $contextSystem;
    }

    private function tools($isUploadFile)
    {
        $tools = [
            [
                "type" => "function",
                "function" => [
                    "name" => "search_web",
                    "description" => "Search public internet information from websites and search engines. Use this tool when the user asks about real-time, external, or public information such as weather, news, cryptocurrency prices, stock market data, sports updates, current events, exchange rates, public company information, or general web knowledge requiring up-to-date data. Do not use this tool for internal ecommerce database or uploaded document searches.",
                    "parameters" => [
                        "type" => "object",
                        "required" => ["query"],
                        "properties" => [
                            "query" => [
                                "type" => "string",
                                "description" => "Search query for retrieving information from the web"
                            ]
                        ]
                    ]
                ]
            ]
        ];

        if($isUploadFile){
            $toolUploadfile = [
                "type" => "function",
                "function" => [
                    "name" => "search_uploaded_document",
                    "description" => "Search user uploaded documents inside the current chat room using semantic search. Use this tool when the user asks questions related to files they uploaded earlier in this conversation, such as PDF, DOCX, TXT, manuals, notes, reports, or study materials. Only use this tool when the answer depends on document content from uploaded files in the current chat session.",
                    "parameters" => [
                        "type" => "object",
                        "required" => ["query"],
                        "properties" => [
                            "query" => [
                                "type" => "string",
                                "description" => "Question or keyword used to search relevant chunks from uploaded documents"
                            ]
                        ]
                    ]
                ]
            ];
            $tools[] = $toolUploadfile;
        }
        return $tools;
    }

    private function executeTools($toolName, $argument, $chatRoomId){
        if($toolName == "search_web"){
            return $this->toolsWebSearchPython($argument['query'] ?? "");
        }

        if($toolName == "search_uploaded_document"){
            return $this->toolsSearchUploadedDocument($argument['query'] ?? "", $chatRoomId);
        }
        
        return [
            'error' => 'Unknown tool'
        ];
    }

    private function toolsSearchUploadedDocument($query, $chatRoomId){
        $embedding = $this->embedding($query);
        $response = Http::timeout(300)->post("http://localhost:6333/collections/documents/points/search",[
            'vector' => $embedding,
            'limit' => 3,
            'with_payload' => true,
            'filter' => [
                'must' => [
                    [
                        'key' => 'chat_room_id',
                        'match' => [
                            'value' => $chatRoomId
                        ]
                    ]
                ]
            ]
        ]);

        $result = collect($response->json()['result'])->map(function ($item) {
            return $item['payload']['text'] ?? null;
        })->filter()->values()->toArray();
        $context = implode("\n\n", $result);
        return $context;
    }

    private function toolsWebSearchPython($query){
        try{
            $response = Http::timeout(120)->post("http://127.0.0.1:8001/search-web",[
                'query' => $query
            ]);
            return $response->json();
        }catch (Exception $e){
            return [
                'query' => $query,
                'results' => "Error"
            ];
        }
    }

    private function embedding($text){
        $response = Http::timeout(300)->post("http://localhost:11434/api/embeddings",[
            'model' => 'mxbai-embed-large',
            'prompt' => $text
        ]);

        return $response->json()['embedding'];
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

        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
            foreach ($element->getElements() as $child) {
                $text .= $this->parseElement($child);
            }
            return $text . ' ';
        }

        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
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

    //old tools
    private function toolsWebSearch($query)
    {
        try {
            $searchResponse = Http::timeout(60)->get("http://localhost:8085/search", [
                'q' => $query,
                'format' => 'json'
            ]);
            if (!$searchResponse->successful()) {
                return [
                    'error' => 'Failed search from SearXNG'
                ];
            }
            $searchData = $searchResponse->json();
            $results = collect($searchData['results'] ?? [])->take(3)->values();
            if ($results->isEmpty()) {
                return [
                    'query' => $query,
                    'results' => []
                ];
            }
            $finalResults = [];
            foreach ($results as $item) {
                $url = $item['url'] ?? null;
                if (!$url) {
                    continue;
                }
                $content = $this->scrapeWebContent($url);
                if($this->isBadContent($content)){
                    continue;
                }
                $finalResults[] = [
                    'title' => $item['title'] ?? null,
                    'content' => $content
                ];
            }
            return [
                'query' => $query,
                'results' => $finalResults
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    private function isBadContent($content)
    {
        if (!$content) return true;
        $badWords = [
            'captcha',
            'cloudflare',
            'access denied',
            'security verification',
            'just a moment',
            'enable javascript',
            'cookies'
        ];
        $lower = strtolower($content);
        foreach ($badWords as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }
        return false;
    }

    private function scrapeWebContent($url)
    {
        try {
            $readerUrl = "https://r.jina.ai/http://" . preg_replace('#^https?://#', '', $url);
            $response = Http::timeout(120)->get($readerUrl);
            if (!$response->successful()) {
                return null;
            }
            $content = $response->body();
            return $this->cleanWebText($content);
        } catch (Exception $e) {
            return null;
        }
    }

    private function cleanWebText($text)
    {
        if (!$text) return null;
        $text = preg_replace('/\s+/', ' ', $text);
        $removePatterns = [
            '/cookie/i',
            '/privacy policy/i',
            '/terms of use/i',
            '/advertisement/i'
        ];
        foreach ($removePatterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
        $text = trim($text);
        if(strlen($text) < 300){
            return null;
        }
        return substr($text, 0, 1500);
    }
}
