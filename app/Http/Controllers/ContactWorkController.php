<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Config\CallOutcome;
use App\Models\Contact;
use App\Models\ContactFollowup;
use App\Services\AccessService;
use App\Services\ContactFollowupService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class ContactWorkController extends Controller
{
    public function __construct(
        private AccessService $access,
        private ContactFollowupService $followups,
        private SettingsService $settings,
    ) {}

    private function requireAccess(): void
    {
        if (! $this->access->canAccessContacts()) abort(404);
    }

    private function currentAgentId(): int
    {
        $id = Agent::where('user_id', session('user_id'))->value('id');
        if (! $id) abort(403, 'You have no agent record.');
        return (int) $id;
    }

    /** Complete a non-call Contact work item and let the outcome drive the next action. */
    public function complete(Request $request, int $id)
    {
        $this->requireAccess();

        $validated = $request->validate([
            'outcome_key' => 'required|string|max:80',
            'notes' => 'nullable|string|max:2000',
            'site_visit_scheduled_at' => 'nullable|date|after:now',
        ]);

        $followup = ContactFollowup::with('contact')->findOrFail($id);
        $contact = $followup->contact;
        if (! $contact || ! $this->access->canViewContact($contact)) {
            abort(403, 'You are not allowed to work on this Contact.');
        }

        $agentId = $this->currentAgentId();
        if ((int) $followup->agent_id !== $agentId) {
            abort(403, 'This Contact action belongs to another caller.');
        }

        // A stale/test-era work ID must never be allowed to bypass the current
        // outcome-driven work chain. Reconcile first, then only continue if this
        // exact followup remains the authoritative pending action.
        $canonical = $this->followups->reconcileContactWork($contact);
        if (! $canonical || (int) $canonical->id !== (int) $followup->id) {
            return redirect()->route('contacts.show', $contact->id)
                ->withErrors(['error' => 'This Contact work item was stale and has been synchronized. Please use the current action shown above.']);
        }

        // Calls have their own start/log lifecycle so that the call session and
        // call record cannot be bypassed. Non-call actions are completed here.
        if (in_array($followup->action_type, ['call', 'followup_call', 'retry_call', 'post_visit_call', 'negotiation_followup', 'confirm_site_visit', 'visit_reminder', 'visit_feedback_call', 'visit_outcome_call', 'booking_confirmation', 'thank_you_call', 'reactivation_call', 'verify_contact'], true)) {
            return back()->withErrors(['followup_id' => 'This action is a call. Use the Call button so the call is recorded correctly.'])->withInput();
        }

        try {
            app(\App\Services\WorkflowPresentationService::class)
                ->assertOutcomeAllowedForUser($validated['outcome_key']);
        } catch (\DomainException $e) {
            return back()->withErrors(['outcome_key' => $e->getMessage()])->withInput();
        }

        $availableOutcomes = $this->settings->callOutcomesForContactWork($contact, $followup->action_type);
        $outcome = $availableOutcomes->firstWhere('key', $validated['outcome_key']);
        if (! $outcome) {
            return back()->withErrors(['outcome_key' => 'That outcome is not valid for this action and Contact stage.'])->withInput();
        }

        $requiresVisit = (bool) ($outcome->requires_site_visit_datetime ?? false) || $validated['outcome_key'] === 'site_visit_scheduled';
        if ($requiresVisit && empty($validated['site_visit_scheduled_at'])) {
            return back()->withErrors(['site_visit_scheduled_at' => 'Site visit date and time are required for this outcome.'])->withInput();
        }

        try {
            $result = $this->followups->completeAction(
                $followup,
                $agentId,
                $validated['outcome_key'],
                $validated['notes'] ?? null,
                $validated['site_visit_scheduled_at'] ?? null
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        $message = '✅ Action completed and outcome recorded.';
        if ($result['next']) {
            $next = $result['next'];
            $action = $this->settings->actionTypeByKey($next->action_type);
            $when = $next->scheduled_for?->isToday()
                ? 'today at ' . $next->scheduled_for->format('g:i A')
                : ($next->scheduled_for?->isTomorrow()
                    ? 'tomorrow at ' . $next->scheduled_for->format('g:i A')
                    : $next->scheduled_for?->format('d M, g:i A'));
            $message .= ' Next: ' . ($action?->label ?? $next->action_type) . ' ' . $when . '.';
        }

        return redirect()->route('contacts.show', $contact->id)->with('success', $message);
    }
}
