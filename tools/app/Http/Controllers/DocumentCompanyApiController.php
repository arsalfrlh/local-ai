<?php

namespace App\Http\Controllers;

use App\Models\DocumentCompany;
use App\Models\DocumentCompanyChunk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

class DocumentCompanyApiController extends Controller
{
    public function index(){
        $data = DocumentCompany::with('documentCompanyChunk')->get();
        return response()->json(['message' => "Menampilkan semua dokumen", 'success' => true, 'data' => $data], 200);
    }

    public function store(Request $request){
        set_time_limit(0);
        $validator = Validator::make($request->all(),[
            'documents' => 'required',
            'documents.*' => 'file|mimes:pdf,docx,txt'
        ]);

        if($validator->fails()){
            return response()->json(['message' => $validator->errors()->all(), 'success' => false], 422);
        }

        foreach($request->file('documents') as $index => $document){
            $extension = $document->getClientOriginalExtension();
            $fileName = "document_" . time() . ($index + 1) . '.' . $extension;
            $filePath = $document->storeAs('documents/company',$fileName,'public');
            
            $document = DocumentCompany::create([
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
            foreach($chunks as $indexChunk => $chunk){
                $embedding = $this->embedding($chunk);
                $documentChunk = DocumentCompanyChunk::create([
                    'document_company_id' => $document->id,
                    'chunk_index' => $indexChunk + 1,
                    'content' => $chunk
                ]);
                
                $qdrantPoints[] = [
                    'id' => $documentChunk->id,
                    'vector' => $embedding,
                    'payload' => [
                        'document_company_id' => $document->id,
                        'chunk_index' => $indexChunk + 1,
                        'content' => $chunk,
                        'file_name' => $document->file_name,
                        'file_path' => $document->file_path,
                        'file_type' => $document->file_type
                    ]
                ];
            }

            Http::timeout(300)->put("http://localhost:6333/collections/document-companies/points",[
                'points' => $qdrantPoints
            ]);
        }

        return response()->json(['message' => "Dokument perusahan berhasil di upload", 'success' => true], 201);
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
}
