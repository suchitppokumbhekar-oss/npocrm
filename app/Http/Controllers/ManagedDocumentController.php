<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\BookingBrokerageReceipt;
use App\Models\ManagedFile;
use App\Models\Project;
use App\Services\AccessService;
use App\Services\ManagedDocumentService;
use App\Services\SuperAdminService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagedDocumentController extends Controller
{
    public function __construct(
        private AccessService $access,
        private ManagedDocumentService $documents,
        private SuperAdminService $superAdmins,
    ) {}

    public function upload(Request $request)
    {
        $this->requireLogin();

        $data = $request->validate([
            'file' => 'required|file|max:25600',
            'entity_type' => 'required|in:lead,project',
            'entity_id' => 'required|integer|min:1',
            'document_category' => 'required|string|max:60',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:50000',
            'workflow_stage' => 'nullable|string|max:60',
            'context_type' => 'nullable|in:communication,site_visit,site_visit_project,booking,brokerage,project_media,general',
            'context_id' => 'nullable|integer|min:1',
            'customer_shareable' => 'nullable|boolean',
            'return_to' => 'nullable|string|max:2000',
        ]);

        $this->authorizeEntity(
            $data['entity_type'],
            (int) $data['entity_id'],
            true
        );
        if (($data['context_type'] ?? null) === 'site_visit') {
            abort_unless($data['entity_type'] === 'lead' && ! empty($data['context_id']), 422);

            $visitBelongsToLead = DB::table('site_visits')
                ->where('id', (int) $data['context_id'])
                ->where('lead_id', (int) $data['entity_id'])
                ->exists();

            abort_unless($visitBelongsToLead, 422);
        }

        if (($data['context_type'] ?? null) === 'site_visit_project') {
            abort_unless($data['entity_type'] === 'lead' && ! empty($data['context_id']), 422);

            $visitProject = DB::table('site_visit_projects as svp')
                ->join('site_visits as sv', 'sv.id', '=', 'svp.site_visit_id')
                ->where('svp.id', (int) $data['context_id'])
                ->where('sv.lead_id', (int) $data['entity_id'])
                ->select('svp.id', 'svp.site_visit_id', 'svp.project_id')
                ->first();

            abort_unless($visitProject, 422, 'The selected site visit project does not belong to this lead.');
        }

        if ($data['entity_type'] === 'lead') {
            $allowedLeadCategories = [
                'communication_evidence',
                'site_visit_form',
                'booking_form',
                'payment_receipt',
                'general',
            ];

            abort_unless(
                in_array($data['document_category'], $allowedLeadCategories, true),
                422,
                'Unsupported lead document category.'
            );

            if ($data['document_category'] === 'site_visit_form') {
                abort_unless(
                    ($data['context_type'] ?? null) === 'site_visit_project'
                    && ! empty($data['context_id']),
                    422,
                    'A site visit form must belong to a project recorded in an actual site visit.'
                );

                $visitProject = DB::table('site_visit_projects as svp')
                    ->join('site_visits as sv', 'sv.id', '=', 'svp.site_visit_id')
                    ->where('svp.id', (int) $data['context_id'])
                    ->where('sv.lead_id', (int) $data['entity_id'])
                    ->select('svp.id', 'svp.project_id')
                    ->first();

                abort_unless($visitProject, 422);

                /*
                 * Cardinality rule:
                 * one current Site Visit Form for this Lead + Project,
                 * regardless of how many actual visit records exist.
                 *
                 * context_id stores site_visit_projects.id, which preserves
                 * the exact visit/project record for audit purposes.
                 */
                $duplicate = ManagedFile::query()
                    ->active()
                    ->where('document_category', 'site_visit_form')
                    ->whereHas('links', function ($query) use ($data, $visitProject) {
                        $query->where('entity_type', 'lead')
                            ->where('entity_id', (int) $data['entity_id'])
                            ->where('relationship', 'evidence')
                            ->where('context_type', 'site_visit_project')
                            ->whereIn(
                                'context_id',
                                DB::table('site_visit_projects')
                                    ->where('project_id', (int) $visitProject->project_id)
                                    ->select('id')
                            );
                    })
                    ->exists();

                abort_if(
                    $duplicate,
                    422,
                    'A current site visit form already exists for this lead and project. Replace the existing document instead.'
                );
            }

            if ($data['document_category'] === 'booking_form') {
                $duplicate = ManagedFile::query()
                    ->active()
                    ->where('document_category', 'booking_form')
                    ->whereHas('links', function ($query) use ($data) {
                        $query->where('entity_type', 'lead')
                            ->where('entity_id', (int) $data['entity_id'])
                            ->where('relationship', 'evidence');
                    })
                    ->exists();

                abort_if(
                    $duplicate,
                    422,
                    'A current booking form already exists for this lead and project. Replace the existing document instead.'
                );
            }
        }

        $file = $this->documents->store(
            $request->file('file'),
            [
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'document_category' => $data['document_category'],
                'workflow_stage' => $data['workflow_stage'] ?? null,
                'context_type' => $data['context_type'] ?? null,
                'context_id' => $data['context_id'] ?? null,
                'customer_shareable' => (bool) ($data['customer_shareable'] ?? false),
                'visibility' => 'internal',
                'relationship' => $data['entity_type'] === 'project'
                    ? 'project_media'
                    : 'evidence',
                'source_label' => $data['entity_type'] === 'project'
                    ? 'project_media'
                    : 'lead_evidence',
            ],
            $data['entity_type'],
            (int) $data['entity_id'],
            (int) session('user_id')
        );

        return $this->returnAfterAction($request, $file->id)
            ->with('success', $file->customer_shareable
                ? 'Document uploaded and is available for customer sharing.'
                : 'Document uploaded successfully.');
    }

    public function view(int $id): StreamedResponse
    {
        $this->requireLogin();

        $file = ManagedFile::with('links')->findOrFail($id);
        $this->authorizeFile($file);

        abort_if($file->removed_at, 404);
        abort_unless(Storage::disk($file->disk)->exists($file->storage_path), 404);

        $this->documents->recordEvent(
            $file,
            'viewed',
            (int) session('user_id'),
            'web'
        );

        return Storage::disk($file->disk)->response(
            $file->storage_path,
            $file->original_name,
            [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' .
                    addslashes($file->original_name) . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function download(int $id): StreamedResponse
    {
        $this->requireLogin();

        $file = ManagedFile::with('links')->findOrFail($id);
        $this->authorizeFile($file);

        $userId = (int) session('user_id');

        abort_unless(
            $this->superAdmins->isSuperAdmin($userId)
            || (
                session('user_role') !== 'team_manager'
                && $this->access->can('documents.download')
            ),
            403
        );

        abort_if($file->removed_at, 404);
        abort_unless(Storage::disk($file->disk)->exists($file->storage_path), 404);

        $this->documents->recordEvent($file, 'downloaded', $userId, 'web');

        return Storage::disk($file->disk)->download(
            $file->storage_path,
            $file->original_name
        );
    }

    public function updateMetadata(Request $request, int $id)
    {
        $this->requireLogin();

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:50000',
            'document_category' => 'required|string|max:60',
            'customer_shareable' => 'nullable|boolean',
            'return_to' => 'nullable|string|max:2000',
        ]);

        $file = ManagedFile::with('links')->findOrFail($id);
        $this->authorizeFile($file);

        abort_if($file->removed_at, 422, 'Removed media cannot be edited.');

        $projectLink = $file->links->first(fn ($link) =>
            $link->entity_type === 'project'
            && $link->relationship === 'project_media'
        );

        abort_unless($projectLink, 422, 'Only project media metadata can be edited here.');

        $userId = (int) session('user_id');

        $before = [
            'title' => $file->title,
            'description' => $file->description,
            'document_category' => $file->document_category,
            'customer_shareable' => (bool) $file->customer_shareable,
        ];

        $file->title = $data['title'] ?? null;
        $file->description = $data['description'] ?? null;
        $file->document_category = $data['document_category'];
        $file->customer_shareable = (bool) ($data['customer_shareable'] ?? false);

        $file->save();

        $this->documents->recordEvent(
            $file,
            'metadata_updated',
            $userId,
            'web',
            [
                'before' => $before,
                'after' => [
                    'title' => $file->title,
                    'description' => $file->description,
                    'document_category' => $file->document_category,
                    'customer_shareable' => (bool) $file->customer_shareable,
                ],
            ]
        );

        return $this->returnAfterAction($request, $file->id)
            ->with(
                'success',
                $file->customer_shareable
                    ? 'Project media updated and is available for customer sharing.'
                    : 'Project media updated.'
            );
    }

    public function replace(Request $request, int $id)
    {
        $this->requireLogin();

        $data = $request->validate([
            'file' => 'required|file|max:25600',
            'return_to' => 'nullable|string|max:2000',
        ]);

        $file = ManagedFile::with('links')->findOrFail($id);
        $this->authorizeFile($file);
        abort_if($file->removed_at, 422, 'Removed media cannot be replaced.');
        abort_if($file->valid_until, 422, 'This media version has already been superseded. Replace the current version instead.');

        $link = $file->links->first(fn ($link) =>
            ($link->entity_type === 'project' && $link->relationship === 'project_media')
            || ($link->entity_type === 'lead' && $link->relationship === 'evidence')
        );

        abort_unless($link, 422, 'This document cannot be replaced here.');

        $userId = (int) session('user_id');

        $isProjectMedia =
            $link->entity_type === 'project'
            && $link->relationship === 'project_media';

        $isLeadEvidence =
            $link->entity_type === 'lead'
            && $link->relationship === 'evidence';

        $allowed = $isProjectMedia
            || (
                $isLeadEvidence
                && $this->access->canWorkLead(
                    Lead::findOrFail((int) $link->entity_id)
                )
            );

        abort_unless($allowed, 403);

        $replacement = $this->documents->store(
            $request->file('file'),
            [
                'title' => $file->title,
                'description' => $file->description,
                'document_category' => $file->document_category,
                'context_type' => $link->context_type ?: ($isProjectMedia ? 'project_media' : 'general'),
                'context_id' => $link->context_id,
                'workflow_stage' => $link->workflow_stage,
                'relationship' => $link->relationship,
                'source_label' => $file->source_label ?: ($isProjectMedia ? 'project_media' : 'lead_evidence'),
                'visibility' => 'internal',
                'customer_shareable' => $file->customer_shareable,
                'version_number' => ((int) $file->version_number) + 1,
                'replaces_file_id' => $file->id,
            ],
            $link->entity_type,
            (int) $link->entity_id,
            $userId
        );

        $this->documents->retireReplacedVersion($file, $replacement, $userId);

        return $this->returnAfterAction($request, $replacement->id)
            ->with(
                'success',
                $isProjectMedia
                    ? ($replacement->customer_shareable
                        ? 'Replacement uploaded as a new version and is available for customer sharing.'
                        : 'Replacement uploaded as a new version.')
                    : 'Replacement uploaded successfully. The previous version remains in document history.'
            );
    }


    public function remove(Request $request, int $id)
    {
        $this->requireLogin();

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
            'return_to' => 'nullable|string|max:2000',
        ]);

        $file = ManagedFile::with('links')->findOrFail($id);
        $this->authorizeFile($file);

        $userId = (int) session('user_id');

        $projectMediaLink = $file->links->first(fn ($link) =>
            $link->entity_type === 'project'
            && $link->relationship === 'project_media'
        );

        $leadEvidenceLink = $file->links->first(fn ($link) =>
            $link->entity_type === 'lead'
            && $link->relationship === 'evidence'
        );

        $ownsRemovableDocument =
            (int) $file->uploaded_by_user_id === $userId
            && (
                session('user_role') === 'agent'
                || $this->access->can('documents.remove_own')
            );

        $allowed = (bool) $projectMediaLink
            || $this->superAdmins->isSuperAdmin($userId)
            || (
                $ownsRemovableDocument
                && $leadEvidenceLink
                && $this->access->canWorkLead(
                    Lead::findOrFail((int) $leadEvidenceLink->entity_id)
                )
            );

        abort_unless($allowed, 403);

        $this->documents->remove(
            $file,
            $userId,
            $data['reason'] ?? null
        );

        return $this->returnAfterAction($request, $file->id)
            ->with('success', 'Document removed from active use.');
    }

    private function returnAfterAction(Request $request, int $fileId)
    {
        $returnTo = trim((string) $request->input('return_to', ''));

        if ($returnTo !== '' && str_starts_with($returnTo, url('/'))) {
            return redirect()->to($returnTo);
        }

        return redirect()->to(url()->previous() . '#media-' . $fileId);
    }

    private function authorizeFile(ManagedFile $file): void
    {
        abort_if($file->links->isEmpty(), 403);

        foreach ($file->links as $link) {
            try {
                $this->authorizeEntity(
                    $link->entity_type,
                    (int) $link->entity_id,
                    false
                );

                return;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if ($e->getStatusCode() !== 403 && $e->getStatusCode() !== 404) {
                    throw $e;
                }
            }
        }

        abort(403);
    }

    private function authorizeEntity(
        string $entityType,
        int $entityId,
        bool $write
    ): void {
        if ($entityType === 'lead') {
            $lead = Lead::findOrFail($entityId);

            abort_unless(
                $write
                    ? $this->access->canWorkLead($lead)
                    : $this->access->canViewLead($lead),
                403
            );

            return;
        }

        if ($entityType === 'project') {
            $project = Project::query()
                ->visibleTo(
                    (int) session('user_id'),
                    (string) session('user_role')
                )
                ->findOrFail($entityId);


            return;
        }
        if ($entityType === 'booking_brokerage_receipt') {
            $receipt = BookingBrokerageReceipt::with('booking.lead')->findOrFail($entityId);
            $lead = $receipt->booking?->lead;
            abort_unless($lead, 404);
            abort_unless(
                $write
                    ? $this->access->canWorkLead($lead)
                    : $this->access->canViewLead($lead),
                403
            );
            return;
        }


        abort(404);
    }

    private function requireLogin(): void
    {
        abort_unless(session('user_id'), 403);
    }
}
