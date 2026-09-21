<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadDeletionService;
use Illuminate\Http\Request;

class DeleteLeadController extends Controller
{
    public function __construct(
        private LeadDeletionService $deletion,
        private \App\Services\AccessService $access,
    ) {}

    /* ============================================================ */
    private function requireAdmin(): void
    {
        if (! $this->access->can('leads.manage')) {
            abort(403, 'Only admins can delete leads.');
        }
    }

    /* ============================================================
       STEP 1 — Input the lead number
       ============================================================ */
    public function index(Request $request)
    {
        $this->requireAdmin();

        $leadId  = (int) $request->input('lead_id', 0);
        $preview = $leadId > 0 ? $this->deletion->preview($leadId) : null;

        if ($preview && ($preview['found'] ?? false) && ! $this->access->canManageLead($preview['lead'])) {
            abort(403, 'This lead is outside your management scope.');
        }

        return view('admin.delete-lead', compact('leadId', 'preview'));
    }

    /* ============================================================
       STEP 2 — Execute after name confirmation
       ============================================================ */
    public function confirm(Request $request)
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'lead_id'      => 'required|integer|min:1',
            'confirm_name' => 'required|string',
            'acknowledge'  => 'required|accepted',
        ]);

        $leadId = (int) $validated['lead_id'];

        // Double-check the lead still exists and remains inside the actor's scope.
        $preview = $this->deletion->preview($leadId);
        if (! $preview['found']) {
            return back()->with('error', "Lead #{$leadId} no longer exists.");
        }

        if (! $this->access->canManageLead($preview['lead'])) {
            abort(403, 'This lead is outside your management scope.');
        }

        // Require the admin to have typed the lead's exact name
        $expectedName = trim((string) $preview['lead']->customer_name);
        $typedName    = trim((string) $validated['confirm_name']);

        if (strcasecmp($expectedName, $typedName) !== 0) {
            return back()->with('error', 'Lead name does not match. Please type it exactly as shown.');
        }

        // Execute
        try {
            $counts = $this->deletion->delete($leadId);
        } catch (\Throwable $e) {
            return back()->with('error', 'Deletion failed: ' . $e->getMessage());
        }

        // Log it (best-effort — even if it fails, deletion is done)
        try {
            \App\Models\Activity::create([
            'action_source' => 'manual',
                'lead_id'     => 1, // dummy — the real lead is gone; keep for audit trail
                'agent_id'    => 1,
                'type'        => 'note',
                'outcome'     => '🗑️ Lead deleted by admin',
                'notes'       => "Lead #{$leadId} ({$expectedName}) deleted by user " . session('user_name'),
                'logged_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            // ignore — the deletion already succeeded
        }

        $summary = collect($counts)
            ->map(fn ($n, $k) => "{$k}: {$n}")
            ->implode(', ');

        return redirect()
            ->route('admin.delete-lead')
            ->with('success', "✅ Lead #{$leadId} ({$expectedName}) permanently deleted. ({$summary})");
    }
}