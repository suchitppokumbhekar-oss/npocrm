<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Followup;
use Illuminate\Support\Collection;

class NudgeService
{
    public function __construct(
        private AccessService $access,
        private DelegatedAccessService $delegated,
        private TeamService $teams,
    ) {}

    public function canManageNudges(): bool
    {
        return in_array(session('user_role'), ['admin', 'team_manager'], true)
            && (bool) session('user_id');
    }

    /**
     * Return the agent ids the current manager/admin is allowed to nudge.
     * This is deliberately the same scope used by Team Status.
     */
    public function visibleAgentIds(): array
    {
        $userId = (int) session('user_id');
        $role = session('user_role');

        if (! $userId || ! in_array($role, ['admin', 'team_manager'], true)) {
            return [];
        }

        if ($this->delegated->hasProfile($userId)) {
            return $this->delegated->visibleAgentIds($userId);
        }

        if ($role === 'admin') {
            return Agent::active()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->teams->agentIdsForManager($userId);
    }

    public function buildForLeadAttention(\App\Models\Lead $lead): array
    {
        $lead->loadMissing(["project", "agent.user"]);
        $agent = $lead->agent;

        if (! in_array($lead->status, ['new', 'external_shared'], true)) {
            throw new \DomainException("This lead no longer requires a first-contact intervention.");
        }

        $since = $lead->assigned_at ?: $lead->created_at;
        $hasHumanActivity = $since && Activity::where('lead_id', $lead->id)
            ->where('logged_at', '>=', $since)
            ->whereNotIn('type', [
                'lead_shared',
                'lead_reassigned',
                'status_change',
                'shared_agent_report',
                'site_team_report',
            ])
            ->where(function ($q) {
                $q->whereNull('action_source')
                    ->orWhereNotIn('action_source', ['system', 'automation', 'scheduled_job']);
            })
            ->exists();

        if ($hasHumanActivity) {
            throw new \DomainException("This lead already has meaningful human activity and no longer requires a first-contact intervention.");
        }

        if (! $agent) {
            throw new \DomainException("This lead no longer has a responsible agent.");
        }

        if (! in_array((int) $agent->id, $this->visibleAgentIds(), true)) {
            abort(403, "You do not have access to nudge this agent.");
        }

        $phone = phone_wa($agent->phone);
        if ($phone === "") {
            throw new \DomainException("This agent has no WhatsApp number configured.");
        }

        $first = explode(" ", trim($agent->user?->name ?: "Agent"))[0];
        $project = $lead->project?->name;
        $message = "Hi {$first},

"
            . "Please attend this new lead now. The configured first-contact window has been breached for {$lead->customer_name}"
            . ($project ? " ({$project})" : "")
            . ".

Open lead and act: " . url("/leads/" . $lead->id)
            . "

— " . session("user_name", "Manager");

        return [
            "phone" => $phone,
            "message" => $message,
            "target_type" => "lead_attention",
            "target_id" => (int) $lead->id,
            "lead_id" => (int) $lead->id,
            "lead_name" => (string) $lead->customer_name,
            "agent_id" => (int) $agent->id,
            "agent_name" => (string) ($agent->user?->name ?? "Agent"),
        ];
    }

    public function buildForFollowup(Followup $followup): array
    {
        $followup->loadMissing(['lead.project', 'agent.user']);
        $lead = $followup->lead;
        $agent = $followup->agent;

        if (! $lead || ! $agent) {
            throw new \DomainException('The task no longer has a valid lead or assigned agent.');
        }

        if (! in_array((int) $agent->id, $this->visibleAgentIds(), true)) {
            abort(403, 'You do not have access to nudge this agent.');
        }

        if ($followup->status !== 'pending') {
            throw new \DomainException('This task is no longer pending.');
        }

        if (! $followup->scheduled_for || $followup->scheduled_for->isFuture()) {
            throw new \DomainException('Nudge is available only for overdue or escalated work.');
        }

        $phone = phone_wa($agent->phone);
        if ($phone === '') {
            throw new \DomainException('This agent has no WhatsApp number configured.');
        }

        $first = explode(' ', trim($agent->user?->name ?: 'Agent'))[0];
        $project = $lead->project?->name;
        $when = $followup->scheduled_for->diffForHumans(null, true) . ' ago';
        $message = "Hi {$first},\n\n"
            . "Please attend this overdue follow-up for {$lead->customer_name}"
            . ($project ? " ({$project})" : '')
            . " — {$when}.\n\n"
            . "Open lead and act: " . url('/leads/' . $lead->id) . "\n\n"
            . "— " . session('user_name', 'Manager');

        return [
            'phone' => $phone,
            'message' => $message,
            'target_type' => 'followup',
            'target_id' => (int) $followup->id,
            'lead_id' => (int) $lead->id,
            'lead_name' => (string) $lead->customer_name,
            'agent_id' => (int) $agent->id,
            'agent_name' => (string) ($agent->user?->name ?? 'Agent'),
        ];
    }

    public function buildForAgent(int $agentId): array
    {
        if (! in_array($agentId, $this->visibleAgentIds(), true)) {
            abort(403, 'You do not have access to nudge this agent.');
        }

        $agent = Agent::with('user')->findOrFail($agentId);
        $phone = phone_wa($agent->phone);
        if ($phone === '') {
            throw new \DomainException('This agent has no WhatsApp number configured.');
        }

        $tasks = Followup::with(['lead.project'])
            ->where('agent_id', $agentId)
            ->where('status', 'pending')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<', now())
            ->orderBy('scheduled_for')
            ->get();

        if ($tasks->isEmpty()) {
            throw new \DomainException('This agent has no overdue pending follow-ups to nudge.');
        }

        $first = explode(' ', trim($agent->user?->name ?: 'Agent'))[0];
        $lines = ["Hi {$first},", '', 'You have ' . $tasks->count() . ' overdue follow-up' . ($tasks->count() === 1 ? '' : 's') . ':'];

        foreach ($tasks->take(10) as $task) {
            $leadName = $task->lead?->customer_name ?? 'Lead';
            $project = $task->lead?->project?->name;
            $age = $task->scheduled_for->diffForHumans(null, true) . ' ago';
            $line = "• {$leadName}" . ($project ? " ({$project})" : '') . " — {$age}";
            if ($task->lead_id) {
                $line .= "\n  🔗 Open lead and act: " . url('/leads/' . $task->lead_id);
            }
            $lines[] = $line;
        }

        if ($tasks->count() > 10) {
            $lines[] = '… and ' . ($tasks->count() - 10) . ' more.';
        }

        $lines[] = '';
        $lines[] = 'Please attend these today.';
        $lines[] = '';
        $lines[] = '— ' . session('user_name', 'Manager');

        return [
            'phone' => $phone,
            'message' => implode("\n", $lines),
            'target_type' => 'agent',
            'target_id' => $agentId,
            'lead_id' => null,
            'lead_name' => null,
            'agent_id' => $agentId,
            'agent_name' => (string) ($agent->user?->name ?? 'Agent'),
            'task_count' => $tasks->count(),
            'lead_ids' => $tasks->pluck('lead_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    public function recordInitiated(array $payload, string $accountType): void
    {
        app(AuditLogService::class)->record(
            'team_management',
            'nudge_initiated',
            request(),
            [
                'nudge_type' => $payload['target_type'],
                'whatsapp_account_type' => $accountType,
                'lead_id' => $payload['lead_id'] ?? null,
                'lead_name' => $payload['lead_name'] ?? null,
                'agent_id' => $payload['agent_id'] ?? null,
                'agent_name' => $payload['agent_name'] ?? null,
                'task_count' => $payload['task_count'] ?? 1,
                'lead_ids' => $payload['lead_ids'] ?? null,
                'note' => 'WhatsApp nudge opened for sending; CRM cannot confirm external WhatsApp delivery.',
            ],
            $payload['target_type'],
            $payload['target_id'] ?? null,
        );
    }

    public function countForFollowup(int $followupId): int
    {
        return AuditLog::query()
            ->where('event_category', 'team_management')
            ->where('action', 'nudge_initiated')
            ->where('target_type', 'followup')
            ->where('target_id', $followupId)
            ->count();
    }

    public function countForAgent(int $agentId): int
    {
        return AuditLog::query()
            ->where('event_category', 'team_management')
            ->where('action', 'nudge_initiated')
            ->where(function ($query) use ($agentId) {
                $query->where(function ($q) use ($agentId) {
                    $q->where('target_type', 'agent')->where('target_id', $agentId);
                })->orWhere(function ($q) use ($agentId) {
                    $q->where('target_type', 'followup')
                        ->whereJsonContains('details->agent_id', $agentId);
                });
            })
            ->count();
    }

    public function escalationEventsForFollowups(array $followupIds): Collection
    {
        if (empty($followupIds)) return collect();

        return AuditLog::query()
            ->where('event_category', 'followup')
            ->where('action', 'followup_escalated')
            ->where('target_type', 'followup')
            ->whereIn('target_id', $followupIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn ($log) => (int) $log->target_id);
    }
    public function recentEscalationsForAgents(array $agentIds, int $limit = 20): Collection
    {
        if (empty($agentIds)) return collect();

        $logs = AuditLog::query()
            ->where('event_category', 'followup')
            ->where('action', 'followup_escalated')
            ->where('target_type', 'followup')
            ->orderByDesc('created_at')
            ->limit(max(1, $limit * 3))
            ->get();

        $followupIds = $logs->pluck('target_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (empty($followupIds)) return collect();

        $followups = Followup::with(['lead.project', 'agent.user'])
            ->whereIn('id', $followupIds)
            ->whereIn('agent_id', $agentIds)
            ->get()
            ->keyBy('id');

        $out = collect();
        foreach ($logs as $log) {
            $followup = $followups->get((int) $log->target_id);
            if (! $followup || $out->count() >= $limit) continue;

            $activities = \App\Models\Activity::with('agent.user')
                ->where('lead_id', $followup->lead_id)
                ->where('logged_at', '>', $log->created_at)
                ->orderBy('logged_at')
                ->limit(5)
                ->get();

            $out->push((object) [
                'log' => $log,
                'followup' => $followup,
                'activities' => $activities,
            ]);
        }

        return $out;
    }

}
