<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use App\Services\LeadTagService;
use App\Services\AccessService;
use Illuminate\Http\Request;

class LeadTagController extends Controller
{
    public function __construct(private LeadTagService $tags, private AccessService $access) {}

    /**
     * Save tag + labels for a lead.
     */
    public function save(Request $request)
    {
        if (! session('user_id')) {
            return response()->json(['success' => false, 'message' => 'Session expired.'], 401);
        }

        $validated = $request->validate([
            'lead_id'   => 'required|integer|exists:leads,id',
            'tag_id'    => 'nullable|integer|exists:lead_tags,id',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'integer|exists:lead_labels,id',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);

        if (! $this->access->canWorkLead($lead)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to modify this lead.',
            ], 403);
        }

        $oldTagId = $lead->tag_id ? (int) $lead->tag_id : null;
        $oldLabelIds = $lead->labels()->pluck('lead_labels.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $newTagId = isset($validated['tag_id']) && $validated['tag_id'] !== '' ? (int) $validated['tag_id'] : null;
        $newLabelIds = collect($validated['label_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        $labelsById = \App\Models\LeadLabel::whereIn('id', array_values(array_unique(array_merge($oldLabelIds, $newLabelIds))))
            ->get()->keyBy('id');
        $oldLabelsById = array_fill_keys($oldLabelIds, true);
        $newLabelsById = array_fill_keys($newLabelIds, true);

        DB::transaction(function () use ($lead, $newTagId, $newLabelIds, $oldTagId, $oldLabelIds, $labelsById, $oldLabelsById, $newLabelsById) {
            $this->tags->apply($lead, $newTagId, $newLabelIds);

            $agentId = $lead->resolveLoggingAgentId();
            $changes = [];

            if ($oldTagId !== $newTagId) {
                $oldTag = $oldTagId ? \App\Models\LeadTag::find($oldTagId) : null;
                $newTag = $newTagId ? \App\Models\LeadTag::find($newTagId) : null;
                $changes[] = 'Tag: ' . ($oldTag?->label ?? 'None') . ' → ' . ($newTag?->label ?? 'None');
            }

            foreach ($newLabelIds as $id) {
                if (! isset($oldLabelsById[$id])) {
                    $label = $labelsById->get($id);
                    if ($label) $changes[] = 'Label added: ' . $label->group_label . ' → ' . $label->label;
                }
            }
            foreach ($oldLabelIds as $id) {
                if (! isset($newLabelsById[$id])) {
                    $label = $labelsById->get($id);
                    if ($label) $changes[] = 'Label removed: ' . $label->group_label . ' → ' . $label->label;
                }
            }

            if ($changes) {
                Activity::create([
                    'lead_id' => $lead->id,
                    'agent_id' => $agentId,
                    'type' => 'note',
                    'outcome' => 'CRM classification updated',
                    'outcome_key' => null,
                    'notes' => "EVIDENCE · CRM CLASSIFICATION
" . implode("
", $changes) . "

This records a controlled Tag/Label change. It is CRM classification evidence, not a direct customer quote.",
                    'action_source' => 'crm_classification',
                    'logged_at' => now(),
                ]);
            }
        });

        $lead = $lead->fresh(['tag', 'labels']);

        return response()->json([
            'success'      => true,
            'message'      => '🏷️ Tags updated.',
            'tag'          => $lead->tag ? [
                'id' => $lead->tag->id,
                'label' => $lead->tag->label,
                'icon' => $lead->tag->icon,
                'color' => $lead->tag->color,
            ] : null,
            'labels'       => $lead->labels->map(fn ($l) => [
                'id' => $l->id, 'label' => $l->label, 'group_key' => $l->group_key,
            ])->values(),
        ]);
    }
    /** Record customer confirmation of the current controlled classifications. */
    public function confirm(Request $request)
    {
        if (! session('user_id')) {
            return back()->withErrors(['error' => 'Session expired.']);
        }

        $validated = $request->validate([
            'lead_id' => 'required|integer|exists:leads,id',
        ]);

        $lead = Lead::with('labels')->findOrFail((int) $validated['lead_id']);
        if (! $this->access->canWorkLead($lead)) abort(403, 'You are not allowed to work on this lead.');
        if ($lead->isLost()) return back()->withErrors(['error' => 'This lead is Lost. Revive it first, then confirm customer information.']);
        if ($lead->labels->isEmpty()) return back()->withErrors(['error' => 'No labels are set to confirm.']);

        $labels = $lead->labels->sortBy(fn ($l) => ($l->group_key ?? '') . '|' . ($l->sort_order ?? 0))
            ->map(fn ($l) => $l->group_label . ' → ' . $l->label)->values()->all();

        Activity::create([
            'lead_id' => $lead->id,
            'agent_id' => $lead->resolveLoggingAgentId(),
            'type' => 'note',
            'outcome' => 'Customer confirmed CRM classifications',
            'outcome_key' => null,
            'notes' => "EVIDENCE · CUSTOMER CONFIRMATION\nConfirmed current CRM labels with customer:\n" . implode("\n", $labels) . "\n\nThis confirms the classifications shown at the time of confirmation; it does not rewrite historical evidence.",
            'action_source' => 'customer_confirmation',
            'logged_at' => now(),
        ]);
        $lead->update(['last_activity_at' => now()]);

        return redirect()->to('/leads/' . $lead->id . '?lead_tab=customer')
            ->with('success', '✅ Current labels recorded as customer-confirmed evidence.');
    }

}