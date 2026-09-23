<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Followup;
use App\Models\Lead;
use App\Services\FollowupService;
use App\Services\AccessService;
use App\Services\LeadActivityProcessor;
use App\Services\SettingsService;
use App\Services\LeadStatusService;
use App\Services\WorkActionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FollowupController extends Controller
{
    public function __construct(
        private FollowupService $followups,
        private SettingsService $settings,
        private LeadActivityProcessor $processor,
        private AccessService $access,
        private WorkActionService $workActions,
        private LeadStatusService $statusService,
    ) {}

    /* ============================================================
       MANUAL SCHEDULE (admin-only via UI)
       ============================================================ */
    public function store(Request $request)
    {
        if (! $this->access->can('followups.manage')) {
            abort(403, 'Only admins can manually schedule follow-ups.');
        }

        $validated = $request->validate([
            'lead_id'       => 'required|integer|exists:leads,id',
            'scheduled_for' => 'required|date',
            'action_type'   => 'required|string|max:100',
            'return_to'     => 'nullable|string|max:1000',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);

        if (! $this->access->canViewLead($lead)) {
            abort(403, 'This lead is outside your scope.');
        }

        try {
            app(\App\Services\WorkflowPresentationService::class)
                ->assertFollowupActionTypeAllowedForUser($validated['action_type'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['action_type' => $e->getMessage()])->withInput();
        }

        DB::transaction(function () use ($lead, $validated) {
            // Cancel any existing pending task of the same type
            Followup::where('lead_id', $lead->id)
                ->where('status', 'pending')
                ->where('action_type', $validated['action_type'])
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            $this->followups->schedule(
                $lead,
                $validated['scheduled_for'],
                $validated['action_type'],
            );

            $actionModel = $this->settings->actionTypeByKey($validated['action_type']);
            $actionLabel = $actionModel?->label ?? $validated['action_type'];

            $when   = \Carbon\Carbon::parse($validated['scheduled_for']);
            $byName = session('user_name', 'System');

            Activity::create([
            'action_source' => 'manual',
                'lead_id'     => $lead->id,
                'agent_id'    => $lead->resolveLoggingAgentId(),
                'type'        => 'note',
                'outcome'     => '📆 Follow-up scheduled: ' . $actionLabel,
                'outcome_key' => null,
                'notes'       => "By: {$byName}\n"
                               . "Scheduled for: " . $when->format('d M Y, H:i') . "\n"
                               . "Task type: " . $actionLabel,
                'logged_at'   => now(),
            ]);

            $lead->update(['last_activity_at' => now()]);
        });

        $returnTo = (string) ($validated['return_to'] ?? '');
        $isSafeReturn = $returnTo !== ''
            && str_starts_with($returnTo, '/')
            && ! str_starts_with($returnTo, '//')
            && ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnTo);

        $redirectUrl = $isSafeReturn ? url($returnTo) : url('/');
        return redirect($redirectUrl)->with('success', '⏰ Follow-up scheduled!');
    }

    /* ============================================================
       ✅ DONE — complete a task with activity
       ============================================================ */
    public function completeWithActivity(Request $request)
    {
        $validated = $request->validate([
            'followup_id'           => 'required|integer|exists:followups,id',
            'type'                  => 'required|string|max:50',
            'outcome_key'           => 'nullable|string',
            'notes'                 => 'nullable|string|max:2000',
            'also_whatsapp'         => 'nullable|boolean',
            'whatsapp_sent_kind'    => 'nullable|in:intro,details',
            'custom_next_at'        => 'nullable|date|after:now',
            'visit_scheduled_at'    => 'nullable|date|after:now',
            'lost_reason'           => 'nullable|string|max:2000',
            'lost_reason_key'       => 'nullable|string|max:80',
            'property_area_sqft'    => 'nullable|numeric|min:0',
            'rate_per_sqft'         => 'nullable|numeric|min:0',
            'booking_amount'        => 'nullable|numeric|min:0',
            'booking_unit'          => 'nullable|string|max:100',
            'booking_payment_mode'  => 'nullable|string|max:50',
            'booking_date'          => 'nullable|date',
            'brokerage_percentage'  => 'nullable|numeric|min:0|max:100',
            'brokerage_amount'      => 'nullable|numeric|min:0',
            'brokerage_expected_at' => 'nullable|date',
            'co_broker_name'        => 'nullable|string|max:100',
            'return_to'             => 'nullable|string|max:1000',
            'allow_early'            => 'nullable|boolean',
        ]);

        $followup = Followup::with('lead')->findOrFail($validated['followup_id']);
        $lead     = $followup->lead;

        if (! $lead) {
            return back()->withErrors(['followup_id' => 'Lead not found for this task.']);
        }

        if (! $this->access->canWorkLead($lead)) {
            abort(403, 'You are not allowed to work on this follow-up.');
        }

        if ($lead->isLost()) {
            return back()->withErrors([
                'lead_id' => 'This lead is Lost. Revive it first.',
            ])->withInput();
        }

        try {
            $this->workActions->assertCanAct(
                $followup,
                filter_var($validated['allow_early'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['followup_id' => $e->getMessage()])->withInput();
        }

        try {
            $forcedSystemType = in_array($followup->action_type, ['check_shared_agent', 'check_site_team'], true);
            if (! $forcedSystemType) {
                app(\App\Services\WorkflowPresentationService::class)
                    ->assertActivityTypeAllowedForUser($validated['type'] ?? null);
            }

            app(\App\Services\WorkflowPresentationService::class)
                ->assertOutcomeAllowedForUser($validated['outcome_key'] ?? null);

            app(\App\Services\WorkflowPresentationService::class)
                ->assertLostReasonAllowedForUser($validated['lost_reason_key'] ?? null);

            $result = $this->processor->process($lead, $followup, $validated);
        } catch (\DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }
        // Next scheduled followup — appended to the flash so the user sees it
        $nextFollowup = \App\Models\Followup::where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_for')
            ->first();

        $flashMessage = $this->processor->buildFlashMessage(
            $result,
            '✅ Task completed & activity logged!'
        );

        if ($nextFollowup && $nextFollowup->scheduled_for) {
            $when = $nextFollowup->scheduled_for->isToday()
                ? 'Today at ' . $nextFollowup->scheduled_for->format('g:i A')
                : ($nextFollowup->scheduled_for->isTomorrow()
                    ? 'Tomorrow at ' . $nextFollowup->scheduled_for->format('g:i A')
                    : $nextFollowup->scheduled_for->format('d M, g:i A'));

            $flashMessage .= ' · Next action scheduled ' . $when;
        }

        // Work-first return: when the agent completed a task after opening the
        // lead from My Work, go straight back to that work queue. Only relative
        // internal paths are accepted, preventing an open redirect.
        $returnTo = (string) ($validated['return_to'] ?? '');
        $isSafeReturn = $returnTo !== ''
            && str_starts_with($returnTo, '/')
            && ! str_starts_with($returnTo, '//')
            && ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnTo);

        if ($isSafeReturn) {
            $redirectUrl = url($returnTo);
        } else {
            // Normal lead-page completion retains the existing completion context.
            $redirectUrl = url('/leads/' . $lead->id)
                . '?task_completed=1'
                . '&completed_followup_id=' . $followup->id;

            if ($nextFollowup) {
                $redirectUrl .= '&next_followup_id=' . $nextFollowup->id;
            }
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok'           => true,
                'message'      => $flashMessage,
                'redirect_url' => $redirectUrl,
            ]);
        }

        return redirect($redirectUrl)->with('success', $flashMessage);
    }

    /* ============================================================
       COMPLETE BY SHARING TO SITE WHATSAPP GROUP
       ============================================================ */
    public function shareToSiteTeam(Request $request)
    {
        $validated = $request->validate([
            'followup_id' => 'required|integer|exists:followups,id',
            'group_name'  => 'nullable|string|max:150',
            'notes'       => 'nullable|string|max:2000',
            'allow_early' => 'nullable|boolean',
        ]);

        $followup = Followup::with('lead.project')->findOrFail($validated['followup_id']);
        $lead = $followup->lead;

        if (! $lead) return response()->json(['ok' => false, 'message' => 'Lead not found.'], 422);
        if (! $this->access->canWorkLead($lead)) return response()->json(['ok' => false, 'message' => 'You are not allowed to work on this lead.'], 403);
        if ($followup->status !== 'pending') return response()->json(['ok' => false, 'message' => 'This follow-up has already been completed.'], 422);
        if ($lead->isLost()) return response()->json(['ok' => false, 'message' => 'This lead is Lost. Revive it first.'], 422);
        if ($followup->scheduled_for && $followup->scheduled_for->isFuture() && ! filter_var($validated['allow_early'] ?? false, FILTER_VALIDATE_BOOLEAN)) return response()->json(['ok' => false, 'message' => 'This follow-up is not due yet. Use Start Early if you intentionally want to act now.'], 422);

        $userId = (int) session('user_id');
        $sharer = \App\Models\Agent::where('user_id', $userId)->first();
        $sharerName = session('user_name', 'System');
        $groupName = trim((string) ($validated['group_name'] ?? ''));
        $notes = trim((string) ($validated['notes'] ?? ''));

        DB::transaction(function () use ($followup, $lead, $userId, $sharer, $sharerName, $groupName, $notes) {
            \App\Models\LeadExternalShare::create([
                'lead_id' => $lead->id,
                'shared_by_user_id' => $userId,
                'group_name' => $groupName !== '' ? $groupName : null,
                'reason_key' => 'follow_up',
                'reason_label' => '📲 Shared with Site WhatsApp Group for follow-up',
                'extra_notes' => $notes !== '' ? $notes : null,
            ]);

            $followup->update(['status' => 'done', 'updated_at' => now()]);

            $lines = ['Shared with Site WhatsApp Group', 'By: ' . $sharerName];
            if ($groupName !== '') $lines[] = 'Group: ' . $groupName;
            $lines[] = 'Purpose: Site/sales team to call and follow up.';
            if ($notes !== '') $lines[] = 'Notes: ' . $notes;
            $lines[] = 'Completed follow-up: ' . ($followup->action_type ?: 'task');

            $movedToExternalShared = $this->statusService->markExternallyShared($lead);
            if ($movedToExternalShared) {
                $lines[] = 'Lifecycle: New → Shared Externally';
            }

            Activity::create([
            'action_source' => 'manual',
                'lead_id' => $lead->id,
                'agent_id' => $sharer ? (int) $sharer->id : $lead->resolveLoggingAgentId(),
                'type' => 'external_share',
                'outcome' => $groupName !== '' ? '📲 Shared with Site WhatsApp Group: ' . $groupName : '📲 Shared with Site WhatsApp Group',
                'outcome_key' => null,
                'notes' => implode("\n", $lines),
                'logged_at' => now(),
            ]);

            $lead->update(['last_activity_at' => now()]);

            if ($sharer) {
                Followup::where('lead_id', $lead->id)->where('status', 'pending')->where('action_type', 'check_site_team')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
                $this->followups->scheduleForAgent($lead, (int) $sharer->id, now()->addDays(2)->setTime(10, 0), 'check_site_team', 'high', true);
            }
        });

        $redirectUrl = url('/leads/' . $lead->id) . '?task_completed=1&completed_followup_id=' . $followup->id;
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'message' => '📲 Lead shared with Site WhatsApp Group. Follow-up completed.', 'redirect_url' => $redirectUrl]);
        }
        return redirect($redirectUrl)->with('success', '📲 Lead shared with Site WhatsApp Group. Follow-up completed.');
    }

    /* ============================================================
       HAND OVER TO ANOTHER COMPANY TEAM MEMBER FOR ANOTHER PROJECT
       ============================================================ */
    public function handoverToAgent(Request $request)
    {
        $validated = $request->validate([
            'followup_id' => 'required|integer|exists:followups,id',
            'agent_id' => 'required|integer|exists:agents,id',
            'team_id' => 'required|integer|exists:teams,id',
            'project_id' => 'required|integer|exists:projects,id',
            'reason' => 'required|string|max:1000',
            'allow_early' => 'nullable|boolean',
            'return_to' => 'nullable|string|max:1000',
        ]);

        $followup = Followup::with('lead.project')->findOrFail($validated['followup_id']);
        $lead = $followup->lead;
        if (! $lead) return response()->json(['ok' => false, 'message' => 'Lead not found.'], 422);
        if (! $this->access->canWorkLead($lead)) return response()->json(['ok' => false, 'message' => 'You are not allowed to work on this lead.'], 403);
        if ($followup->status !== 'pending') return response()->json(['ok' => false, 'message' => 'This follow-up has already been completed.'], 422);
        if ($followup->scheduled_for && $followup->scheduled_for->isFuture() && ! filter_var($validated['allow_early'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(['ok' => false, 'message' => 'This follow-up is not due yet. Use Start Early if you intentionally want to act now.'], 422);
        }
        if ($lead->isLost()) return response()->json(['ok' => false, 'message' => 'This lead is Lost. Revive it first.'], 422);

        $target = Agent::with('user')->where('status', 'active')->findOrFail($validated['agent_id']);
        $newProject = \App\Models\Project::findOrFail($validated['project_id']);
        $team = \App\Models\Team::findOrFail($validated['team_id']);
        if ((int) $target->id === (int) $lead->agent_id) return response()->json(['ok' => false, 'message' => 'That person is already the primary owner.'], 422);
        if ((int) $newProject->id === (int) $lead->project_id) return response()->json(['ok' => false, 'message' => 'Choose a different destination project.'], 422);
        if (! $lead->agentHandlesProject((int) $target->id, (int) $newProject->id)) return response()->json(['ok' => false, 'message' => 'That team member is not routed to the selected project.'], 422);

        $teamEligible = DB::table('project_team as pt')
            ->join('team_members as tm', function ($join) {
                $join->on('tm.team_id', '=', 'pt.team_id')->where('tm.is_active', 1);
            })
            ->where('pt.is_active', 1)
            ->where('pt.project_id', $newProject->id)
            ->where('pt.team_id', $team->id)
            ->where('tm.agent_id', $target->id)
            ->exists();
        if (! $teamEligible) return response()->json(['ok' => false, 'message' => 'The selected agent is not an active member of the selected team for this project.'], 422);

        $role = session('user_role');
        if ($role === 'admin' && ! $this->access->isUnrestrictedAdmin((int) session('user_id'))) {
            $allowed = $this->access->visibleAgentIds();
            if (! in_array((int) $target->id, $allowed, true)) return response()->json(['ok' => false, 'message' => 'That team member is outside your delegated scope.'], 403);
        } elseif ($role === 'team_manager') {
            $allowed = $this->access->visibleAgentIds();
            if (! in_array((int) $target->id, $allowed, true)) return response()->json(['ok' => false, 'message' => 'That team member is outside your team scope.'], 403);
        } elseif (! in_array($role, ['admin', 'agent'], true)) {
            return response()->json(['ok' => false, 'message' => 'You are not allowed to hand over leads.'], 403);
        }

        $reason = trim($validated['reason']);
        $reason = 'Team: ' . $team->name . '. ' . $reason;
        $newLead = app(\App\Services\LeadTransferService::class)->createCrossProjectLead(
            $lead,
            $target,
            (int) $newProject->id,
            $reason,
            $followup
        );

        $returnTo = (string) ($validated['return_to'] ?? '');
        $isSafeReturn = $returnTo !== ''
            && str_starts_with($returnTo, '/')
            && ! str_starts_with($returnTo, '//')
            && ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnTo);

        $redirectUrl = $isSafeReturn
            ? url($returnTo)
            : url('/leads/' . $newLead->id);
        $message = "🔄 New lead #{$newLead->id} created for {$newProject->name} and assigned to " . ($target->user?->name ?? 'the receiving agent') . '. Original lead remains with its original project/owner.';

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'redirect_url' => $redirectUrl,
                'new_lead_id' => $newLead->id,
                'source_lead_id' => $lead->id,
            ]);
        }

        return redirect($redirectUrl)->with('success', $message);
    }

    /* ============================================================
       DISPOSE FUTURE TASK
       ============================================================ */
    public function dispose(Request $request)
    {
        $validated = $request->validate([
            'followup_id' => 'required|integer|exists:followups,id',
            'reason' => 'required|string|max:1000',
        ]);

        $followup = Followup::with('lead')->findOrFail($validated['followup_id']);
        $lead = $followup->lead;
        if (! $lead) return response()->json(['ok' => false, 'message' => 'Lead not found.'], 422);
        if (! $this->access->canWorkLead($lead)) return response()->json(['ok' => false, 'message' => 'You are not allowed to dispose this follow-up.'], 403);
        if ($followup->status !== 'pending') return response()->json(['ok' => false, 'message' => 'This follow-up is no longer pending.'], 422);
        if (! $followup->scheduled_for || ! $followup->scheduled_for->isFuture()) return response()->json(['ok' => false, 'message' => 'Only future follow-ups can be disposed here.'], 422);

        $actorAgentId = (int) (\App\Models\Agent::where('user_id', session('user_id'))->value('id') ?? ($lead->agent_id ?: 1));
        $actorName = session('user_name', 'System');
        $reason = trim($validated['reason']);

        DB::transaction(function () use ($followup, $lead, $actorAgentId, $actorName, $reason) {
            $followup->update(['status' => 'cancelled', 'updated_at' => now()]);
            Activity::create([
            'action_source' => 'manual',
                'lead_id' => $lead->id,
                'agent_id' => $actorAgentId ?: ($lead->agent_id ?: 1),
                'type' => 'followup_disposed',
                'outcome' => 'Future follow-up disposed',
                'outcome_key' => null,
                'notes' => implode("\n", [
                    'Follow-up #' . $followup->id,
                    'Action: ' . $followup->action_type,
                    'Scheduled: ' . ($followup->scheduled_for?->format('Y-m-d H:i:s') ?? '—'),
                    "By: {$actorName}",
                    "Reason: {$reason}",
                    'When: ' . now()->format('Y-m-d H:i:s'),
                ]),
                'logged_at' => now(),
            ]);
            $lead->update(['last_activity_at' => now()]);
        });

        $message = '🗑 Future follow-up disposed and recorded in the timeline.';
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'message' => $message, 'redirect_url' => url('/leads/' . $lead->id)]);
        }
        return redirect('/leads/' . $lead->id)->with('success', $message);
    }

    /* ============================================================
       LEGACY MARK DONE — intentionally disabled
       ============================================================ */
    public function markDone(Request $request)
    {
        return response()->json([
            'ok' => false,
            'message' => 'Use Done — Log Now so the activity, outcome, status and next action are recorded consistently.',
        ], 410);
    }
}