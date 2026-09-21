<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ManagedFile;
use App\Models\User;
use App\Services\FileVaultService;
use Illuminate\Http\Request;

class FileVaultController extends Controller
{
    public function __construct(private FileVaultService $files) {}

    private function requireSuperAdmin(): void
    {
        app(\App\Services\SuperAdminService::class)->requireSuperAdmin();
    }

    public function index(Request $request)
    {
        $this->requireSuperAdmin();

        $query = ManagedFile::with('uploader')->orderByDesc('id');

        if ($request->filled('user_id')) {
            $query->where('uploaded_by_user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('kind')) {
            $query->where('file_kind', $request->input('kind'));
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($builder) use ($q) {
                $builder->where('original_name', 'like', '%' . $q . '%')
                    ->orWhere('source_label', 'like', '%' . $q . '%')
                    ->orWhere('sha256', 'like', '%' . $q . '%');
            });
        }

        $files = $query->paginate(50)->withQueryString();
        $users = User::orderBy('name')->get(['id', 'name', 'email', 'role']);
        $downloadCounts = AuditLog::query()
            ->where('event_category', 'download')
            ->where('target_type', 'ManagedFile')
            ->whereNotNull('target_id')
            ->selectRaw('target_id, COUNT(*) as total')
            ->groupBy('target_id')
            ->pluck('total', 'target_id');

        return view('admin.file-center', compact('files', 'users', 'downloadCounts'));
    }

    public function download(int $id)
    {
        $this->requireSuperAdmin();

        $file = ManagedFile::findOrFail($id);
        $path = $this->files->path($file);
        $this->files->recordDownload($file, (int) session('user_id'));

        return response()->download(
            $path,
            $file->original_name,
            ['Content-Type' => $file->mime_type ?: 'application/octet-stream']
        );
    }

    public function generateSample(string $name)
    {
        $this->requireSuperAdmin();

        $allowed = [
            'leads_sample.csv' => 'Lead import sample',
            'contacts_sample.csv' => 'Contact import sample',
            'contacts_advanced.csv' => 'Advanced contact import sample',
        ];

        abort_unless(isset($allowed[$name]), 404);

        $existing = ManagedFile::query()
            ->where('original_name', $name)
            ->where('source_label', $allowed[$name])
            ->latest('id')
            ->first();

        if ($existing) {
            return redirect()->route('admin.file-center.download', $existing->id);
        }

        $path = resource_path('samples/' . $name);
        $file = $this->files->registerExistingPrivateFile(
            $path,
            $name,
            'text/csv; charset=UTF-8',
            $allowed[$name],
            (int) session('user_id')
        );

        abort_unless($file, 500, 'Could not prepare sample file.');

        return redirect()->route('admin.file-center.download', $file->id);
    }
}
