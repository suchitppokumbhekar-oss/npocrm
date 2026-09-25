<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Contact;
use App\Models\ContactProjectAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContactDuplicateCleanupService
{
    /**
     * Return duplicate groups using the CRM's normalized phone rules.
     * No data is changed.
     */
    public function duplicateGroups(): Collection
    {
        $groups = [];

        Contact::query()->with(['project', 'agent.user'])->orderBy('id')->chunkById(500, function ($contacts) use (&$groups) {
            foreach ($contacts as $contact) {
                $phone = $this->normalizePhone((string) $contact->phone);
                if (strlen($phone) < 10) continue;
                $groups[$phone][] = $contact;
            }
        });

        return collect($groups)
            ->filter(fn ($contacts) => count($contacts) > 1)
            ->map(function (array $contacts, string $phone) {
                $contacts = collect($contacts)->values();
                $promoted = $contacts->filter(fn ($c) => $c->promoted_to_lead_id !== null);
                $callCounts = Call::whereIn('contact_id', $contacts->pluck('id'))->selectRaw('contact_id, COUNT(*) as total')->groupBy('contact_id')->pluck('total', 'contact_id');
                $assignmentCounts = ContactProjectAssignment::whereIn('contact_id', $contacts->pluck('id'))->selectRaw('contact_id, COUNT(*) as total')->groupBy('contact_id')->pluck('total', 'contact_id');

                // A group with more than one promoted Lead is deliberately manual-review only.
                $safe = $promoted->count() <= 1;
                $survivor = $contacts->sortByDesc(function ($c) use ($callCounts, $assignmentCounts) {
                    return [
                        $c->promoted_to_lead_id !== null ? 1 : 0,
                        (int) ($assignmentCounts[$c->id] ?? 0),
                        (int) ($callCounts[$c->id] ?? 0),
                        $c->status === 'dnc' ? 1 : 0,
                        (int) $c->id,
                    ];
                })->first();

                return [
                    'phone' => $phone,
                    'contacts' => $contacts,
                    'survivor' => $survivor,
                    'safe' => $safe,
                    'promoted_count' => $promoted->count(),
                ];
            })->values();
    }

    /**
     * Merge one safe duplicate group. Never merges a group containing more than one
     * promoted Lead, because that could collapse legitimate Lead history.
     */
    public function merge(string $phone): array
    {
        return DB::transaction(function () use ($phone) {
            $contacts = Contact::query()->lockForUpdate()->orderBy('id')->get()
                ->filter(fn ($c) => $this->normalizePhone((string) $c->phone) === $phone)
                ->values();

            if ($contacts->count() < 2) {
                return ['merged' => false, 'reason' => 'No duplicate group found.'];
            }

            $promoted = $contacts->filter(fn ($c) => $c->promoted_to_lead_id !== null);
            if ($promoted->count() > 1) {
                return ['merged' => false, 'reason' => 'Manual review required: more than one Contact is already promoted to a Lead.'];
            }

            $survivor = $this->chooseSurvivor($contacts);
            $removed = [];

            foreach ($contacts as $duplicate) {
                if ((int) $duplicate->id === (int) $survivor->id) continue;

                // Re-home call history and project/caller assignment history before deletion.
                Call::where('contact_id', $duplicate->id)->update(['contact_id' => $survivor->id]);
                ContactProjectAssignment::where('contact_id', $duplicate->id)->update(['contact_id' => $survivor->id]);

                $removed[] = (int) $duplicate->id;
                $duplicate->delete();
            }

            // Keep the current/default Contact fields synchronized with the newest active
            // assignment, while preserving all other active project assignments.
            $replacement = ContactProjectAssignment::where('contact_id', $survivor->id)
                ->where('status', 'active')->orderByDesc('id')->first();
            if ($replacement) {
                $survivor->update([
                    'project_id' => $replacement->project_id,
                    'assigned_to_agent_id' => $replacement->assigned_to_agent_id,
                ]);
            }

            return ['merged' => true, 'survivor_id' => (int) $survivor->id, 'removed_ids' => $removed];
        });
    }

    private function chooseSurvivor(Collection $contacts): Contact
    {
        $ids = $contacts->pluck('id');
        $callCounts = Call::whereIn('contact_id', $ids)->selectRaw('contact_id, COUNT(*) as total')->groupBy('contact_id')->pluck('total', 'contact_id');
        $assignmentCounts = ContactProjectAssignment::whereIn('contact_id', $ids)->selectRaw('contact_id, COUNT(*) as total')->groupBy('contact_id')->pluck('total', 'contact_id');

        return $contacts->sortByDesc(function ($c) use ($callCounts, $assignmentCounts) {
            return [
                $c->promoted_to_lead_id !== null ? 1 : 0,
                (int) ($assignmentCounts[$c->id] ?? 0),
                (int) ($callCounts[$c->id] ?? 0),
                $c->status === 'dnc' ? 1 : 0,
                (int) $c->id,
            ];
        })->first();
    }

    public function normalizePhone(string $phone): string
    {
        $p = preg_replace('/\D/', '', $phone);
        if (str_starts_with($p, '91') && strlen($p) === 12) $p = substr($p, 2);
        if (str_starts_with($p, '0') && strlen($p) === 11) $p = substr($p, 1);
        return $p;
    }
}
