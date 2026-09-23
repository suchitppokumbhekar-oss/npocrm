<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Call;
use App\Models\Config\CallOutcome;
use App\Models\Contact;
use App\Models\ContactFollowup;
use App\Services\AccessService;
use App\Services\CallService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class ContactCallController extends Controller
{
    public function __construct(
        private CallService $calls,
        private AccessService $access
    ) {}

    private function requireAccess(): void
    {
        if (! $this->access->canAccessContacts()) {
            abort(404);
        }
    }

    private function currentAgentId(): int
    {
        $id = Agent::where('user_id', session('user_id'))->value('id');

        if (! $id) {
            abort(403, 'You have no agent record.');
        }

        return (int) $id;
    }

    /*
    |--------------------------------------------------------------------------
    | CONTACT DIALER
    |--------------------------------------------------------------------------
    |
    | Supports both:
    |   /contacts/dialer
    |   /contacts/dialer?contact_id=123
    |
    | The second form is what "Work this call" uses.
    |
    */
    public function dialer(Request $request)
    {
        $this->requireAccess();

        $agentId = $this->currentAgentId();
        $role = session('user_role');

        $visibleAgentIds = $role === 'team_manager'
            ? $this->access->visibleAgentIds()
            : [$agentId];

        $selectedContactId = $request->integer('contact_id') ?: null;

        /*
         * The current production database/backend does not have the newer
         * unfinished-call-session model that the Blade was originally written
         * against. Keep this collection intentionally empty instead of allowing
         * the view to crash on an undefined variable.
         */
        $unfinishedCalls = collect();
        $selectedUnfinishedCall = null;

        /*
         * Existing Contact follow-up work that is due for the current caller.
         */
        $dueFollowups = ContactFollowup::with(['contact'])
            ->where('agent_id', $agentId)
            ->where('status', 'pending')
            ->where('scheduled_for', '<=', now())
            ->whereHas('contact', function ($q) use ($visibleAgentIds) {
                $q->whereNotIn('status', ['dnc', 'invalid', 'converted'])
                    ->where(function ($scope) use ($visibleAgentIds) {
                        $scope->whereIn(
                            'assigned_to_agent_id',
                            $visibleAgentIds ?: [-1]
                        )->orWhereNull('assigned_to_agent_id');
                    });
            })
            ->orderBy('scheduled_for')
            ->limit(100)
            ->get();

        /*
         * A selected Contact must be explicitly loaded even if its current
         * status is no longer "new". This is the key repair for Work this call.
         */
        if ($selectedContactId) {
            $contact = Contact::findOrFail($selectedContactId);

            if (! $this->access->canViewContact($contact)) {
                abort(403, 'You are not allowed to work this contact.');
            }

            if ($contact->isPromoted()) {
                return redirect()
                    ->route('contacts.show', $contact->id)
                    ->with('success', 'This contact has already been promoted to a lead.');
            }

            $queue = collect([$contact]);

            /*
             * Use the same action-aware outcome catalogue that CallService
             * validates when the result is saved.
             */
            $activeWork = $contact->followups()
                ->where('status', 'pending')
                ->orderBy('scheduled_for')
                ->first();

            $actionKey = $activeWork?->action_type ?: 'call';

            $outcomes = app(\App\Services\WorkflowPresentationService::class)
                ->presentOutcomes(
                    app(SettingsService::class)->callOutcomesForContactWork($contact, $actionKey),
                    (int) session('user_id')
                );

            return view('contacts.dialer', compact(
                'queue',
                'outcomes',
                'selectedContactId',
                'unfinishedCalls',
                'dueFollowups',
                'selectedUnfinishedCall'
            ));
        }

        /*
         * No selected Contact: show today's work landing page.
         */
        $status = $request->input('status', 'new');

        $query = Contact::query()
            ->whereNotIn('status', ['dnc', 'invalid', 'converted'])
            ->where(function ($q) use ($visibleAgentIds) {
                $q->whereIn(
                    'assigned_to_agent_id',
                    $visibleAgentIds ?: [-1]
                )->orWhereNull('assigned_to_agent_id');
            });

        if ($status && $status !== 'any') {
            $query->where('status', $status);
        }

        $queue = $query
            ->orderBy('attempts')
            ->orderBy('last_called_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $outcomes = CallOutcome::active()
            ->orderBy('sort_order')
            ->get();

        return view('contacts.dialer', compact(
            'queue',
            'outcomes',
            'status',
            'selectedContactId',
            'unfinishedCalls',
            'dueFollowups',
            'selectedUnfinishedCall'
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | START CALL
    |--------------------------------------------------------------------------
    |
    | The Blade expects this endpoint before opening the phone application.
    | Production currently has no separate CallSession persistence layer, so
    | this endpoint performs authorization and returns the customer's phone.
    | The actual CRM call is written only when an outcome is submitted.
    |
    */
    public function startCall(int $id)
    {
        $this->requireAccess();

        $contact = Contact::findOrFail($id);

        if (! $this->access->canViewContact($contact)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to call this contact.',
            ], 403);
        }

        if ($contact->isPromoted()) {
            return response()->json([
                'success' => false,
                'message' => 'This contact has already been promoted to a lead.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'session_id' => null,
            'phone' => $contact->phone,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | LOG CALL
    |--------------------------------------------------------------------------
    */
    public function log(Request $request)
    {
        $this->requireAccess();

        $validated = $request->validate([
            'contact_id' => 'required|integer|exists:contacts,id',
            'outcome_key' => 'required|string|max:50',
            'duration_seconds' => 'nullable|integer|min:0|max:86400',
            'notes' => 'nullable|string|max:2000',
            'next_contact_id' => 'nullable|integer|exists:contacts,id',
            'site_visit_scheduled_at' => 'nullable|date',
        ]);

        $agentId = $this->currentAgentId();
        $contact = Contact::findOrFail($validated['contact_id']);

        if (! $this->access->canViewContact($contact)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to call this contact.',
                ], 403);
            }

            abort(403, 'You are not allowed to call this contact.');
        }

        if ($contact->isPromoted()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This contact has been promoted to a lead.',
                ], 422);
            }

            return redirect()
                ->route('contacts.show', $contact->id)
                ->with('success', 'This contact has already been promoted to a lead.');
        }

        try {
            app(\App\Services\WorkflowPresentationService::class)
                ->assertOutcomeAllowedForUser($validated['outcome_key']);

            $call = $this->calls->log(
                $agentId,
                $contact->id,
                null,
                $validated['outcome_key'],
                (int) ($validated['duration_seconds'] ?? 0),
                $validated['notes'] ?? null,
                'manual',
                $validated['site_visit_scheduled_at'] ?? null
            );
        } catch (\DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()
                ->withInput()
                ->withErrors(['outcome_key' => $e->getMessage()]);
        }

        $nextContact = null;

        if (! empty($validated['next_contact_id'])) {
            $candidate = Contact::find($validated['next_contact_id']);

            if ($candidate && $this->access->canViewContact($candidate)) {
                $nextContact = $candidate;
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'call_id' => $call->id,
                'connected' => $call->connected,
                'message' => 'Call logged.',
                'next' => $nextContact ? [
                    'id' => $nextContact->id,
                    'name' => $nextContact->name,
                    'phone' => $nextContact->phone,
                ] : null,
            ]);
        }

        /*
         * Normal dialer forms are browser POSTs, not AJAX. Return the caller to
         * the Contact so they can immediately see the newly-created next work.
         */
        return redirect()
            ->route('contacts.show', $contact->id)
            ->with('success', 'Call recorded and next work updated.');
    }

    /*
    |--------------------------------------------------------------------------
    | RECENT CALLS
    |--------------------------------------------------------------------------
    */
    public function recent()
    {
        $this->requireAccess();

        $agentId = $this->currentAgentId();

        $calls = Call::with(['contact', 'lead'])
            ->where('agent_id', $agentId)
            ->orderByDesc('called_at')
            ->limit(25)
            ->get()
            ->filter(
                fn ($call) =>
                    ! $call->lead || $this->access->canViewLead($call->lead)
            )
            ->take(10)
            ->values();

        return response()->json([
            'success' => true,
            'calls' => $calls->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->contact?->name
                    ?? $c->lead?->customer_name
                    ?? '—',
                'phone' => $c->contact?->phone
                    ?? $c->lead?->phone
                    ?? '—',
                'connected' => $c->connected,
                'duration' => $c->duration_label,
                'called_at' => $c->called_at?->diffForHumans(),
            ]),
        ]);
    }
}
