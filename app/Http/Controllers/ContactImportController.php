<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\ContactImportBatch;
use App\Models\Project;
use App\Services\AccessService;
use App\Services\ContactService;
use App\Services\CsvImportService;
use Illuminate\Http\Request;

class ContactImportController extends Controller
{
    public function __construct(
        private CsvImportService $csv,
        private ContactService $contacts,
        private AccessService $access,
    ) {}

    private function requireImportAccess(): void
    {
        abort_unless($this->access->canImportContacts(), 403);
    }

    private function canViewAllBatches(): bool
    {
        return $this->access->isUnrestrictedAdmin((int) session('user_id'));
    }

    private function requireOwnBatchOrUnrestricted(ContactImportBatch $batch): void
    {
        if ($this->canViewAllBatches()) {
            return;
        }

        abort_unless((int) $batch->user_id === (int) session('user_id'), 403);
    }

    private function visibleProjects()
    {
        $userId = (int) session('user_id');

        if ($this->access->isUnrestrictedAdmin($userId)) {
            return Project::orderBy('name')->get(['id', 'name']);
        }

        $agentIds = $this->access->visibleAgentIds();
        $teamIds = $this->access->visibleTeamIds($userId);

        return Project::where(function ($q) use ($agentIds, $teamIds) {
            if ($agentIds) {
                $q->whereExists(function ($x) use ($agentIds) {
                    $x->selectRaw('1')
                        ->from('project_agent')
                        ->whereColumn('project_agent.project_id', 'projects.id')
                        ->whereIn('project_agent.agent_id', $agentIds)
                        ->where('project_agent.is_active', 1);
                });
            }

            if ($teamIds) {
                $q->orWhereExists(function ($x) use ($teamIds) {
                    $x->selectRaw('1')
                        ->from('project_team')
                        ->whereColumn('project_team.project_id', 'projects.id')
                        ->whereIn('project_team.team_id', $teamIds)
                        ->where('project_team.is_active', 1);
                });
            }
        })->orderBy('name')->get(['id', 'name']);
    }

    public function downloadSample()
    {
        $this->requireImportAccess();

        $path = resource_path('samples/contacts_sample.csv');
        abort_unless(is_file($path), 404, 'Contact import sample is unavailable.');

        return response()->download(
            $path,
            'contacts_sample.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    public function index()
    {
        $this->requireImportAccess();

        $query = ContactImportBatch::with('user')->orderByDesc('id')->limit(10);
        if (! $this->canViewAllBatches()) {
            $query->where('user_id', (int) session('user_id'));
        }

        $recent = $query->get();

        return view('contacts.import.index', [
            'recent' => $recent,
            'projects' => $this->visibleProjects(),
            'agents' => Agent::with('user')
                ->whereIn('id', $this->access->visibleAgentIds())
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function preview(Request $request)
    {
        $this->requireImportAccess();

        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:20480',
            'pitch_project_id' => 'required|integer|exists:projects,id',
            'assigned_agent_id' => 'nullable|integer|exists:agents,id',
            'original_filename' => 'nullable|string|max:255',
            'source_sheet' => 'nullable|string|max:255',
        ]);

        try {
            $parsed = $this->csv->parse($request->file('csv_file'));
        } catch (\Throwable $e) {
            return back()->withErrors(['csv_file' => $e->getMessage()]);
        }

        if (empty($parsed['rows'])) {
            return back()->withErrors(['csv_file' => 'No data rows in file.']);
        }

        if (count($parsed['rows']) > 20000) {
            return back()->withErrors(['csv_file' => 'This import contains more than 20,000 data rows. Please split the source file into smaller lists before importing.']);
        }

        $mapping = $this->csv->detectMapping($parsed['headers'], $parsed['rows']);

        if (! $this->visibleProjects()->contains('id', (int) $request->input('pitch_project_id'))) {
            abort(403, 'Pitch project is outside your scope.');
        }

        if ($request->filled('assigned_agent_id') && ! in_array((int) $request->input('assigned_agent_id'), $this->access->visibleAgentIds(), true)) {
            abort(403, 'Caller is outside your scope.');
        }

        session([
            'c_import_pitch_project_id' => (int) $request->input('pitch_project_id'),
            'c_import_assigned_agent_id' => $request->filled('assigned_agent_id') ? (int) $request->input('assigned_agent_id') : null,
            'c_import_headers' => $parsed['headers'],
            'c_import_rows' => array_slice($parsed['rows'], 0, 20000),
            'c_import_filename' => trim((string) $request->input('original_filename')) ?: $request->file('csv_file')->getClientOriginalName(),
            'c_import_source_sheet' => trim((string) $request->input('source_sheet')) ?: null,
            'c_import_mapping' => $mapping,
        ]);

        return view('contacts.import.preview', [
            'headers' => $parsed['headers'],
            'mapping' => $mapping,
            'preview' => array_slice($parsed['rows'], 0, 10),
            'totalRows' => count($parsed['rows']),
            'filename' => trim((string) $request->input('original_filename')) ?: $request->file('csv_file')->getClientOriginalName(),
            'sourceSheet' => trim((string) $request->input('source_sheet')) ?: null,
            'pitchProject' => $this->visibleProjects()->firstWhere('id', (int) $request->input('pitch_project_id')),
            'assignedAgent' => $request->filled('assigned_agent_id') ? Agent::with('user')->find((int) $request->input('assigned_agent_id')) : null,
        ]);
    }

    public function execute(Request $request)
    {
        $this->requireImportAccess();

        $headers = session('c_import_headers');
        $rows = session('c_import_rows');
        $filename = session('c_import_filename');
        $autoMap = session('c_import_mapping', []);

        if (! is_array($rows) || empty($rows)) {
            return redirect('/contacts/import')->withErrors(['csv_file' => 'Session expired. Upload again.']);
        }

        $mapping = $autoMap;
        foreach ((array) $request->input('mapping', []) as $field => $value) {
            if ($value === '' || $value === null) {
                unset($mapping[$field]);
                continue;
            }
            if (! is_numeric($value) || (int) $value < 0) {
                return back()->withErrors(['mapping' => 'Invalid column mapping for ' . $field . '.']);
            }
            $mapping[$field] = (int) $value;
        }

        foreach (['name' => 'Name', 'phone' => 'Phone'] as $requiredField => $label) {
            if (! array_key_exists($requiredField, $mapping)) {
                return back()->withErrors(['mapping' => $label . ' must be mapped before importing.']);
            }
        }

        $headerCount = is_array($headers) ? count($headers) : 0;
        $mappedColumns = [];
        foreach ($mapping as $field => $columnIndex) {
            if ($columnIndex >= $headerCount) {
                return back()->withErrors(['mapping' => 'The selected column for ' . $field . ' does not exist.']);
            }
            if (isset($mappedColumns[$columnIndex])) {
                return back()->withErrors(['mapping' => 'The same source column cannot be mapped to both ' . $mappedColumns[$columnIndex] . ' and ' . $field . '.']);
            }
            $mappedColumns[$columnIndex] = $field;
        }

        $pitchProjectId = (int) session('c_import_pitch_project_id');
        $assignedAgentId = session('c_import_assigned_agent_id');
        $assignedAgentId = $assignedAgentId !== null ? (int) $assignedAgentId : null;

        if (! $pitchProjectId || ! $this->visibleProjects()->contains('id', $pitchProjectId)) {
            return back()->withErrors(['pitch_project_id' => 'Select a pitch project within your scope.']);
        }

        if ($assignedAgentId !== null && ! in_array($assignedAgentId, $this->access->visibleAgentIds(), true)) {
            return back()->withErrors(['assigned_agent_id' => 'That caller is outside your scope.']);
        }

        if ($assignedAgentId === null && session('user_role') === 'agent') {
            $assignedAgentId = Agent::where('user_id', session('user_id'))->value('id');
        }

        $batch = ContactImportBatch::create([
            'user_id' => (int) session('user_id'),
            'filename' => $filename,
            'total_rows' => count($rows),
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
        ]);

        $result = $this->contacts->importBatch($rows, $mapping, (int) session('user_id'), $batch->id, $pitchProjectId, $assignedAgentId);

        $batch->update([
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'error_log' => ! empty($result['errors']) ? json_encode($result['errors']) : null,
        ]);

        app(\App\Services\AuditLogService::class)->record(
            'data_import',
            'Imported contacts CSV',
            $request,
            [
                'filename' => $filename,
                'source_sheet' => session('c_import_source_sheet'),
                'total_rows' => count($rows),
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
            ],
            'ContactImportBatch',
            $batch->id
        );

        session()->forget([
            'c_import_headers',
            'c_import_rows',
            'c_import_filename',
            'c_import_source_sheet',
            'c_import_mapping',
            'c_import_pitch_project_id',
            'c_import_assigned_agent_id',
        ]);

        return redirect('/contacts/import/summary/' . $batch->id);
    }

    public function summary(int $id)
    {
        $this->requireImportAccess();
        $batch = ContactImportBatch::with('user')->findOrFail($id);
        $this->requireOwnBatchOrUnrestricted($batch);
        return view('contacts.import.summary', compact('batch'));
    }

    public function downloadErrors(int $id)
    {
        $this->requireImportAccess();
        $batch = ContactImportBatch::findOrFail($id);
        $this->requireOwnBatchOrUnrestricted($batch);

        if (! $batch->error_log) {
            return back()->withErrors(['errors' => 'No error log.']);
        }

        $errors = json_decode($batch->error_log, true) ?? [];

        return response()->streamDownload(function () use ($errors) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Row #', 'Reason', 'Raw Data']);
            foreach ($errors as $e) {
                fputcsv($out, [
                    $e['row'] ?? '',
                    $e['reason'] ?? '',
                    is_array($e['data'] ?? null) ? implode(' | ', $e['data']) : '',
                ]);
            }
            fclose($out);
        }, 'contact_import_errors_' . $batch->id . '.csv');
    }
}
