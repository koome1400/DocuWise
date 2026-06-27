<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class UploadController extends Controller
{
    public function index()
    {
        return view('upload/index');
    }

    public function upload()
    {
        $rules = [
            'pdf_file' => 'uploaded[pdf_file]|max_size[pdf_file,20480]|ext_in[pdf_file,pdf]',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->with('error', $this->validator->getErrors());
        }

        $file = $this->request->getFile('pdf_file');

        $category = $this->request->getPost('category');

        if (!$file->isValid() || $file->hasMoved()) {
            return redirect()->back()->with('error', 'Invalid file upload.');
        }

        // ✅ Move file
        $newName = $file->getRandomName();
        $file->move(WRITEPATH . 'uploads', $newName);
        $uploadedPath = WRITEPATH . 'uploads/' . $newName;

        // ✅ Extract text FIRST
        $extractedText = $this->extractTextFromPdf($uploadedPath);

        if (empty(trim($extractedText))) {
            return redirect()->back()->with('error', 'Could not extract text from this PDF.');
        }


        // ✅ Connect DB FIRST
        $db = \Config\Database::connect();

        // ✅ Save document FIRST
        $db->table('documents')->insert([
            'filename' => $newName, // stored file name
            'original_name' => $file->getClientName(), // real name user uploaded
            'content' => $extractedText,
            'category' => $category
        ]);

        $documentId = $db->insertID();


        // ✅ Split text
        $chunks = $this->splitText($extractedText, 500);

        // ✅ Save chunks
        foreach ($chunks as $i => $chunk) {
            $insert = $db->table('document_chunks')->insert([
                'document_id' => $documentId,
                'content' => $chunk,
                'chunk_index' => $i
            ]);

            if (!$insert) {
                die($db->error()['message']);
            }
        }

        // Change the existing return to this:
        return redirect()->to('/chat/' . $documentId)->with('success', 'Document analysed! 1 token has been deducted.');
    }
    private function splitText($text, $wordsPerChunk = 500, $overlap = 100)
    {
        // Faster: Use regex to split words
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $totalWords = count($words);
        $chunks = [];

        if ($totalWords <= $wordsPerChunk) {
            return [$text];
        }

        $step = $wordsPerChunk - $overlap;

        for ($i = 0; $i < $totalWords; $i += $step) {
            $chunkArray = array_slice($words, $i, $wordsPerChunk);
            $chunks[] = implode(" ", $chunkArray);

            if ($i + $wordsPerChunk >= $totalWords) {
                break;
            }
        }

        return $chunks;
    }
    private function extractTextFromPdf($path)
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($path);

        $fullText = "";
        $pages = $pdf->getPages();

        foreach ($pages as $page) {
            // ✅ Correct way to get text from a page
            $fullText .= $page->getText() . "\n";
        }

        // 1. Handle Encoding (Fixes the ?????)
        $fullText = iconv("UTF-8", "UTF-8//IGNORE", $fullText);

        // 2. The "Glue" Logic: Fixes "C h a p t e r  1" -> "Chapter 1"
        // This looks for single letters separated by spaces and joins them
        $fullText = preg_replace('/(?<=\b\w)\s(?=\w\b)/u', '', $fullText);

        // 3. Clean up non-printable junk
        $fullText = preg_replace('/[\x00-\x1F\x7F]/u', '', $fullText);

        // 4. Normalize multiple spaces into one
        $fullText = preg_replace('/\s+/', ' ', $fullText);

        return trim($fullText);
    }
    public function clear()
    {
        session()->destroy();
        return redirect()->to('/');
    }
}