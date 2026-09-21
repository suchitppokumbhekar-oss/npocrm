<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Call;
use App\Models\Config\CallOutcome;
use App\Models\Contact;
use App\Services\CallService;
use App\Services\AccessService;
use Illuminate\Http\Request;

class ContactCallController extends Controller
{
    public function __construct(private CallService $calls, private AccessService $access) {}

        private function requireAccess(): void
    {
        if (! app(\App\Services\AccessService::class)->canAccessContacts()) {
            abort(404);
        }
    }

    private function currentAgentId(): int
    {
        $id = Agent::where('user_id', session('user_id'))->value('id');
        if (! $id) abort(403, 'You have no agent record.');
        return (int) $id;
    }

    /* ============================================================
       DIALER — one contact at a time, sequential queue
       ============================================================ */
    public function dialer(Request $request)
    {
        $this->requireAccess();

        $agentId = $this->currentAgentId();
        $status  = $request->input('status', 'new');
        $role    = session('user_role');

        // Team Managers use the same existing Contact visibility scope as the
        // Contact directory: their team agents + their own Agent record.
        // Regular Agents remain restricted to their own/unassigned queue.
        $visibleAgentIds = $role === 'team_manager'
            ? $this->access->visibleAgentIds()
            : [$agentId];

        // Queue only Contacts the current user is authorized to work.
        // Unassigned Contacts remain available because they are explicitly
        // assignable within the existing Contact workflow.
        $query = Contact::query()
            ->whereNotIn('status', ['dnc', 'invalid', 'converted'])
            ->where(function ($q) use ($visibleAgentIds) {
                $q->whereIn('assigned_to_agent_id', $visibleAgentIds ?: [-1])
                  ->orWhereNull('assigned_to_agent_id');
            });

        if ($status && $status !== 'any') {
            $query->where('status', $status);
        }

        $queue = $query->orderBy('attempts', 'asc')
            ->orderBy('last_called_at', 'asc')
            ->orderBy('id', 'asc')
            ->limit(200)
            ->get();

        $outcomes = CallOutcome::active()->orderBy('sort_order')->get();

        return view('contacts.dialer', compact('queue', 'outcomes', 'status'));
    }

    /* ============================================================
       LOG CALL
       ============================================================ */
    public function log(Request $request)
    {
        $this->requireAccess();

        $validated = $request->validate([
            'contact_id'       => 'required|integer|exists:contacts,id',
            'outcome_key'      => 'required|string|max:50',
            'duration_seconds' => 'required|integer|min:0|max:86400',
            'notes'            => 'nullable|string|max:2000',
            'next_contact_id'  => 'nullable|integer|exists:contacts,id',
        ]);

        $agentId = $this->currentAgentId();
        $contact = Contact::findOrFail($validated['contact_id']);

        if (! $this->access->canViewContact($contact)) {
            return response()->json(['success' => false, 'message' => 'You are not allowed to call this contact.'], 403);
        }

        if ($contact->isPromoted()) {
            return response()->json([
                'success' => false,
                'message' => 'This contact has been promoted to a lead.',
            ], 422);
        }

        $call = $this->calls->log(
            $agentId,
            $contact->id,
            null,
            $validated['outcome_key'],
            (int) $validated['duration_seconds'],
            $validated['notes'] ?? null
        );

        // Return next contact in queue if provided
        $nextContact = null;
        if (! empty($validated['next_contact_id'])) {
            $candidate = Contact::find($validated['next_contact_id']);
            if ($candidate && $this->access->canViewContact($candidate)) {
                $nextContact = $candidate;
            }
        }

        return response()->json([
            'success'    => true,
            'call_id'    => $call->id,
            'connected'  => $call->connected,
            'message'    => 'Call logged.',
            'next'       => $nextContact ? [
                'id'    => $nextContact->id,
                'name'  => $nextContact->name,
                'phone' => $nextContact->phone,
            ] : null,
        ]);
    }

    /* ============================================================
       RECENT CALLS (for the dialer sidebar)
       ============================================================ */
    public function recent()
    {
        $this->requireAccess();

        $agentId = $this->currentAgentId();

        $calls = Call::with(['contact', 'lead'])
            ->where('agent_id', $agentId)
            ->orderByDesc('called_at')
            ->limit(25)
            ->get()
            ->filter(fn ($call) => ! $call->lead || $this->access->canViewLead($call->lead))
            ->take(10)
            ->values();

        return response()->json([
            'success' => true,
            'calls'   => $calls->map(fn ($c) => [
                'id'         => $c->id,
                'name'       => $c->contact?->name ?? $c->lead?->customer_name ?? '—',
                'phone'      => $c->contact?->phone ?? $c->lead?->phone ?? '—',
                'connected'  => $c->connected,
                'duration'   => $c->duration_label,
                'called_at'  => $c->called_at?->diffForHumans(),
            ]),
        ]);
    }
}