<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class DocumentController extends Controller
{
    public function index()
    {
        $db = \Config\Database::connect();

        $search = $this->request->getGet('search');

        $builder = $db->table('documents');

        if (!empty($search)) {
            $builder->groupStart()
                ->like('filename', $search)
                ->orLike('category', $search)
                ->groupEnd();
        }

        $documents = $builder
            ->orderBy('id', 'DESC')
            ->get()
            ->getResult();

        return view('documents/index', [
            'documents' => $documents,
            'search' => $search
        ]);
    }

    public function delete($id)
    {
        $db = \Config\Database::connect();

        // Optional: also delete the file from storage
        $document = $db->table('documents')->where('id', $id)->get()->getRow();

        if ($document) {
            $filePath = WRITEPATH . 'uploads/' . $document->filename;

            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $db->table('documents')->where('id', $id)->delete();
        }

        return redirect()->to('/documents');
    }
    public function view($id)
    {
        $db = \Config\Database::connect();

        $document = $db->table('documents')
            ->where('id', $id)
            ->get()
            ->getRow();

        if (!$document) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $filePath = WRITEPATH . 'uploads/' . $document->filename;

        if (!file_exists($filePath)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $fileName = $document->original_name ?? $document->filename;

        return $this->response->download($filePath, null)->setFileName($fileName);
    }

    public function ask()
    {
        // 1. Get Inputs (Ensures variables exist for the rest of the code)
        $question = $this->request->getPost('question');
        $documentId = (int) $this->request->getPost('document_id');
        $mode = $this->request->getPost('mode');

        // 2. Validation Guardrail
        if (!$question || !$documentId) {
            return $this->response->setJSON([
                'status' => 'error',
                'answer' => 'Missing question or document selection.'
            ]);
        }

        // 3. Initialize Service
        $aiService = new \App\Services\AiAssistantService();

        try {
            // --- START CHART MODE ---
            if ($mode === 'db_chart') {
                try {
                    // Try to get real data from Gemini
                    $chartData = $aiService->getChartData($question, $documentId);

                    // If Gemini throws a 503 error, force it to the catch block
                    if (isset($chartData['error'])) {
                        throw new \Exception("API Error");
                    }
                } catch (\Exception $e) {
                    // FALLBACK FOR YOUR DEMONSTRATION
                    // If Gemini is down, send this fake data so the chart still renders!
                    $chartData = [
                        'title' => 'Demo Fallback Data (API Overloaded)',
                        'type' => 'bar',
                        'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May'],
                        'data' => [1200, 1900, 3000, 5000, 2400]
                    ];
                }

                // Verify $chartData is a valid array and has labels before trying to use it
                if (is_array($chartData) && isset($chartData['labels']) && !empty($chartData['labels'])) {
                    return $this->response->setJSON([
                        'status' => 'success',
                        'mode' => 'chart',
                        'text' => $chartData['title'] ?? 'Data Analysis',
                        'chart' => [
                            'chart_type' => $chartData['type'] ?? 'bar',
                            'labels' => $chartData['labels'],
                            'datasets' => [
                                [
                                    'label' => $chartData['title'] ?? 'Value',
                                    'data' => $chartData['data'] ?? []
                                ]
                            ]
                        ]
                    ]);
                }

                // Fallback: If Chart fails, treat the response as a regular Q&A answer
                $fallbackAnswer = is_array($chartData) ? ($chartData['error'] ?? 'No data found.') : $chartData;
                return $this->response->setJSON([
                    'status' => 'success',
                    'mode' => 'qa',
                    'answer' => $fallbackAnswer
                ]);
            }
            // --- END CHART MODE ---

            // --- START STANDARD Q&A MODE ---
            $answer = $aiService->askQuestion($question, $documentId);

            return $this->response->setJSON([
                'status' => 'success',
                'mode' => 'qa',
                'answer' => $answer
            ]);
            // --- END Q&A MODE ---

        } catch (\Exception $e) {
            // This catches any coding errors so the Network Tab doesn't say "Failed to load"
            return $this->response->setJSON([
                'status' => 'error',
                'answer' => 'System Error: ' . $e->getMessage()
            ]);
        }
    }
}