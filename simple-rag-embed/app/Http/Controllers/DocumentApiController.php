<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\IOFactory;
//install library pdf "composer require smalot/pdfparser"
//install library word "composer require phpoffice/phpword"

class DocumentApiController extends Controller
{
    public function uploadFile(Request $request)
    {
        set_time_limit(0);

        $validator = Validator::make($request->all(), [
            'rag_file' => 'required|file|mimes:pdf,docx,txt'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->all(),
                'success' => false
            ], 422);
        }

        $file = $request->file('rag_file');

        $extension = strtolower($file->getClientOriginalExtension());
        $filename = "rag_" . time() . "." . $extension;

        $path = $file->storeAs('files', $filename, 'public');

        $document = Document::create([
            'filename' => $filename
        ]);

        // 🔥 EXTRACT TEXT BASED ON FILE TYPE
        $rawText = $this->extractText(storage_path('app/public/' . $path), $extension);

        // 🔥 PIPELINE CLEANING
        $text = $this->normalizeText($rawText);
        $text = $this->fixStructure($text);
        $text = $this->finalClean($text);
        // dd($text);

        // 🔥 CHUNKING
        $chunks = $this->chunkText($text);

        foreach ($chunks as $chunk) {

            $response = Http::timeout(300)->post("http://localhost:11434/api/embeddings", [
                'model' => "nomic-embed-text",
                'prompt' => $chunk
            ]);

            DocumentChunk::create([
                'document_id' => $document->id,
                'content' => $chunk,
                'embedding' => json_encode($response['embedding'])
            ]);
        }

        return response()->json([
            'message' => "Document berhasil di upload",
            'success' => true,
            'data' => $document->load('documentChunk')
        ], 201);
    }

    // =========================
    // TEXT EXTRACTOR (CORE)
    // =========================
    private function extractText(string $path, string $extension): string
    {
        return match ($extension) {

            // 📄 PDF
            'pdf' => $this->extractPdf($path),

            // 📝 DOCX
            'docx' => $this->extractDocx($path),

            // 📄 TXT
            'txt' => file_get_contents($path),

            default => throw new \Exception("Unsupported file type")
        };
    }

    // =========================
    // PDF
    // =========================
    private function extractPdf(string $path): string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($path);
        return $pdf->getText();
    }

    // =========================
    // DOCX (IMPORTANT PART)
    // =========================
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

        // Case 1: Text biasa
        if (method_exists($element, 'getText') && is_string($element->getText())) {
            return $element->getText() . ' ';
        }

        // Case 2: TextRun (INI YANG ERROR KAMU)
        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
            foreach ($element->getElements() as $child) {
                $text .= $this->parseElement($child);
            }
            return $text . ' ';
        }

        // Case 3: Table
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

        // fallback
        return '';
    }

    // =========================
    // NORMALIZE
    // =========================
    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace('•', "\n", $text);

        return trim($text);
    }

    // =========================
    // FIX STRUCTURE
    // =========================
    private function fixStructure(string $text): string
    {
        $text = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $text);
        $text = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $text);
        $text = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $text);
        $text = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $text);

        return $text;
    }

    // =========================
    // FINAL CLEAN
    // =========================
    private function finalClean(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    // =========================
    // CHUNKING (RAG READY)
    // =========================
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

    // public function uploadPdf(Request $request){
    //     set_time_limit(0);
    //     $validator = Validator::make($request->all(),[
    //         'pdf' => 'required|file|mimes:pdf'
    //     ]);

    //     if($validator->fails()){
    //         return response()->json(['message' => $validator->errors()->all() , 'success' => false], 422);
    //     }

    //     $file = $request->file('pdf');
    //     $filename = "pdf_" . time() . "." . $file->getClientOriginalExtension();
    //     $path = $file->storeAs('pdf',$filename,'public');
    //     $document = Document::create([
    //         'filename' => $filename
    //     ]);

    //     $parser = new Parser();
    //     $pdf = $parser->parseFile(storage_path('app/public/' . $path));

    //     $rawText = $pdf->getText();
    //     // 🔥 1. PIPELINE CLEANING
    //     $text = $this->normalizePdfText($rawText);
    //     // 🔥 2. STRUCTURE FIX
    //     $text = $this->fixPdfStructure($text);
    //     // 🔥 3. FINAL CLEAN
    //     $text = $this->finalClean($text);

    //     // debug
    //     // dd($text);

    //     $chunks = $this->chunkText($text);
    //     foreach($chunks as $chunk){
    //         $response = Http::timeout(300)->post("http://localhost:11434/api/embeddings",[
    //             'model' => "nomic-embed-text",
    //             'prompt' => $chunk
    //         ]);

    //         DocumentChunk::create([
    //             'document_id' => $document->id,
    //             'content' => $chunk,
    //             'embedding' => json_encode($response['embedding'])
    //         ]);
    //     }

    //     $document->load('documentChunk');
    //     return response()->json(['message' => "Document berhasil di upload", 'success' => true, 'data' => $document], 201);
    // }

    // // =========================
    // // 1. NORMALIZE BASIC TEXT
    // // =========================
    // private function normalizePdfText(string $text): string
    // {
    //     $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    //     $text = preg_replace('/\s+/', ' ', $text);

    //     // fix bullet spacing
    //     $text = str_replace('•', "\n", $text);

    //     return trim($text);
    // }

    // // =========================
    // // 2. FIX BROKEN WORDS
    // // =========================
    // private function fixPdfStructure(string $text): string
    // {
    //     // split camelCase
    //     $text = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $text);

    //     // split ALLCAPS merge cases
    //     $text = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $text);

    //     // split number-word
    //     $text = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $text);
    //     $text = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $text);

    //     // normalize multiple spaces
    //     $text = preg_replace('/\s+/', ' ', $text);

    //     return trim($text);
    // }

    // // =========================
    // // 3. FINAL CLEAN
    // // =========================
    // private function finalClean(string $text): string
    // {
    //     $text = str_replace('•', "\n", $text);

    //     // convert broken sentence spacing
    //     $text = preg_replace('/\s{2,}/', ' ', $text);

    //     return trim($text);
    // }

    // // =========================
    // // 4. SMART CHUNKING (IMPORTANT)
    // // =========================
    // private function chunkText(string $text, int $chunkSize = 1000, int $overlap = 150): array
    // {
    //     $sentences = preg_split('/(?<=[\.!?])\s+/', $text);

    //     $chunks = [];
    //     $current = '';

    //     foreach ($sentences as $sentence) {

    //         if (strlen($current . ' ' . $sentence) < $chunkSize) {
    //             $current .= ' ' . $sentence;
    //         } else {
    //             $chunks[] = trim($current);

    //             // overlap context (IMPORTANT FOR RAG)
    //             $current = substr($current, -$overlap) . ' ' . $sentence;
    //         }
    //     }

    //     if (!empty(trim($current))) {
    //         $chunks[] = trim($current);
    //     }

    //     return $chunks;
    // }

    public function askPdf(Request $request){
        set_time_limit(0);
        $validator = Validator::make($request->all(),[
            'question' => 'required'
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all() , 'success' => false], 422);
        }

        $response = Http::post("http://localhost:11434/api/embeddings",[
            'model' => "nomic-embed-text",
            'prompt' => $request->question
        ]);

        $queryEmbedding = $response->json()['embedding'];
        $chunks = DocumentChunk::all();
        $result = [];
        
        foreach($chunks as $chunk){
            $embedding = json_decode($chunk->embedding, true);

            $score = $this->cosineSimilarity($queryEmbedding, $embedding);

            $result[] = [
                'content' => $chunk->content,
                'score' => $score
            ];
        }

        usort($result, fn($a, $b) => $b['score'] <=> $a['score']);

        $topChunks = array_slice($result, 0, 3);
        $context = implode("\n\n", array_column($topChunks, 'content'));

            $prompt = "
            Gunakan context berikut untuk menjawab pertanyaan user.

            Context:
            $context

            Question:
            {$request->question}

            Jika jawaban tidak ada di context, jawab:
            'Informasi tidak ditemukan dalam dokumen.'
            ";

            $llmResponse = Http::timeout(300)->post("http://localhost:11434/api/generate", [
                'model' => 'qwen2.5-coder:3b',
                'prompt' => $prompt,
                'stream' => false
            ]);

            $answer = $llmResponse->json()['response'];

            return response()->json([
                'message' => 'Berhasil',
                'success' => true,
                'context' => $context,
                'answer' => $answer
            ]);
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
