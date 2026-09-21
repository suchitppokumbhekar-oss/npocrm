<?php

namespace App\Http\Controllers;

use App\Models\LeadImportBatch;
use App\Services\CsvImportService;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function __construct(private CsvImportService $csv) {}

    private function requireAdmin(): void
    {
        if (session('user_role') !== 'admin') {
            abort(403, 'Admin only.');
        }
    }

    /* ============================================================
       LANDING PAGE
       ============================================================ */
    public function index()
    {
        $this->requireAdmin();

        $recentBatches = LeadImportBatch::with('user')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('import.index', compact('recentBatches'));
    }

    /* ============================================================
       STEP 1 — Upload & preview
       ============================================================ */
    public function preview(Request $request)
    {
        $this->requireAdmin();

        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:10240', // 10MB max
        ]);

        $file = $request->file('csv_file');

        try {
            $parsed = $this->csv->parse($file);
        } catch (\Throwable $e) {
            return back()->withErrors(['csv_file' => $e->getMessage()]);
        }

        if (empty($parsed['rows'])) {
            return back()->withErrors(['csv_file' => 'The file has no data rows.']);
        }

        $mapping = $this->csv->detectMapping($parsed['headers']);

                if (! array_key_exists('name', $mapping)) {
            return back()->withErrors([
                'csv_file' => 'Could not find a "Name" column. Please include a header called "Name", "Customer", or "Full Name".',
            ]);
        }
        if (! array_key_exists('phone', $mapping)) {
            return back()->withErrors([
                'csv_file' => 'Could not find a "Phone" column. Please include a header called "Phone" or "Mobile".',
            ]);
        }

        // Store parsed data in session so it survives the next request
        session([
            'import_headers' => $parsed['headers'],
            'import_rows'    => array_slice($parsed['rows'], 0, 2000), // cap at 2000 rows for safety
            'import_filename'=> $file->getClientOriginalName(),
            'import_mapping' => $mapping,
        ]);

        $preview = array_slice($parsed['rows'], 0, 10);

        return view('import.preview', [
            'headers'  => $parsed['headers'],
            'mapping'  => $mapping,
            'preview'  => $preview,
            'totalRows'=> count($parsed['rows']),
            'filename' => $file->getClientOriginalName(),
        ]);
    }

    /* ============================================================
       STEP 2 — Confirm & import
       ============================================================ */
    public function execute(Request $request)
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'skip_duplicates' => 'nullable|boolean',
            'mapping'         => 'nullable|array',
        ]);

        $headers = session('import_headers');
        $rows    = session('import_rows');
        $filename= session('import_filename');
        $autoMap = session('import_mapping', []);

        if (! is_array($headers) || ! is_array($rows) || empty($rows)) {
            return redirect('/import')->withErrors(['csv_file' => 'Session expired. Please upload the CSV again.']);
        }

        // Manual mapping overrides (from the form)
        $mapping = array_filter(array_merge(
            $autoMap,
            array_map('intval', $validated['mapping'] ?? [])
        ), fn ($v) => $v !== null && $v !== '');

        $skipDuplicates = $request->boolean('skip_duplicates', true);

        // Validate rows
        $result = $this->csv->validateRows($rows, $mapping, $skipDuplicates);

        // Import
        $batch = $this->csv->import(
            $result['valid'],
            $filename,
            (int) session('user_id'),
            count($rows),
            count($result['skipped']),
            count($result['failed']),
            $result['failed']
        );

        app(\App\Services\AuditLogService::class)->record(
            'data_import',
            'Imported leads CSV',
            $request,
            [
                'filename' => $filename,
                'total_rows' => count($rows),
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'skip_duplicates' => $skipDuplicates,
            ],
            'LeadImportBatch',
            $batch->id
        );

        // Clear session
        session()->forget(['import_headers', 'import_rows', 'import_filename', 'import_mapping']);

        return redirect('/import/summary/' . $batch->id);
    }

    /* ============================================================
       STEP 3 — Summary
       ============================================================ */
    public function summary(int $id)
    {
        $this->requireAdmin();

        $batch = LeadImportBatch::with('user')->findOrFail($id);

        return view('import.summary', compact('batch'));
    }

    /* ============================================================
       Download error log
       ============================================================ */
    public function downloadErrors(int $id)
    {
        $this->requireAdmin();

        $batch = LeadImportBatch::findOrFail($id);

        if (! $batch->error_log) {
            return back()->withErrors(['errors' => 'No error log for this batch.']);
        }

        $errors = json_decode($batch->error_log, true) ?? [];
        $filename = 'import_errors_' . $batch->id . '.csv';

        return response()->streamDownload(function () use ($errors) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
            fputcsv($out, ['Row #', 'Reason', 'Raw Data']);
            foreach ($errors as $e) {
                fputcsv($out, [
                    $e['row'] ?? '',
                    $e['reason'] ?? '',
                    is_array($e['data'] ?? null) ? implode(' | ', $e['data']) : '',
                ]);
            }
            fclose($out);
        }, $filename);
    }
}