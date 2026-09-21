<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Contact;
use App\Models\ContactCallSession;
use App\Models\ContactFollowup;
use App\Models\Team;
use App\Services\AccessService;
use App\Services\SuperAdminService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ContactOperationsController extends Controller
{
    public function __construct(
        private AccessService $access,
        private SuperAdminService $superAdmins,
    ) {}

    public function index(Request $request)
    {
        $role = (string) session('user_role');
        $userId = (int) session('user_id');

        if (! in_array($role, ['admin', 'team_manager'], true)) {
            abort(403, 'This work-control screen is for managers and administrators.');
        }

        if (! $this->access->canAccessContacts()) {
            abort(404);
        }

        $agentIds = array_values(array_unique(array_map('intval', $this->access->visibleAgentIds())));
        $agents = Agent::with('user')->whereIn('id', $agentIds)->orderBy('id')->get();

        $teamIds = $this->access->visibleTeamIds($userId);
        $teams = Team::with('manager')->whereIn('id', $teamIds)->where('is_active', true)->orderBy('name')->get();

        $teamNamesByAgent = [];
        foreach ($teams as $team) {
            foreach ($team->members()->where('is_active', true)->pluck('agent_id') as $agentId) {
                $teamNamesByAgent[(int) $agentId][] = $team->name;
            }
        }

        $totalByAgent = Contact::query()
            ->whereIn('assigned_to_agent_id', $agentIds ?: [-1])
            ->selectRaw('assigned_to_agent_id, COUNT(*) AS total')
            ->groupBy('assigned_to_agent_id')
            ->pluck('total', 'assigned_to_agent_id');

        $activeByAgent = Contact::query()
            ->whereIn('assigned_to_agent_id', $agentIds ?: [-1])
            ->whereNull('promoted_to_lead_id')
            ->whereNotIn('status', ['dnc', 'invalid', 'converted'])
            ->selectRaw('assigned_to_agent_id, COUNT(*) AS total')
            ->groupBy('assigned_to_agent_id')
            ->pluck('total', 'assigned_to_agent_id');

        $convertedByAgent = Contact::query()
            ->whereIn('assigned_to_agent_id', $agentIds ?: [-1])
            ->whereNotNull('promoted_to_lead_id')
            ->selectRaw('assigned_to_agent_id, COUNT(*) AS total')
            ->groupBy('assigned_to_agent_id')
            ->pluck('total', 'assigned_to_agent_id');

        $convertedTodayByAgent = Contact::query()
            ->whereIn('assigned_to_agent_id', $agentIds ?: [-1])
            ->whereNotNull('promoted_to_lead_id')
            ->whereDate('promoted_at', Carbon::today())
            ->selectRaw('assigned_to_agent_id, COUNT(*) AS total')
            ->groupBy('assigned_to_agent_id')
            ->pluck('total', 'assigned_to_agent_id');

        $followupsByAgent = ContactFollowup::query()
            ->whereIn('agent_id', $agentIds ?: [-1])
            ->where('status', 'pending')
            ->where('scheduled_for', '<=', now()->endOfDay())
            ->whereHas('contact', fn ($q) => $q
                ->whereNull('promoted_to_lead_id')
                ->whereNotIn('status', ['dnc', 'invalid', 'converted']))
            ->selectRaw('agent_id, COUNT(*) AS total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $unfinishedByAgent = ContactCallSession::query()
            ->whereIn('agent_id', $agentIds ?: [-1])
            ->whereNull('completed_at')
            ->where('started_at', '<=', now()->subMinutes(2))
            ->whereHas('contact', fn ($q) => $q
                ->whereNull('promoted_to_lead_id')
                ->whereNotIn('status', ['dnc', 'invalid', 'converted']))
            ->selectRaw('agent_id, COUNT(*) AS total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $rows = $agents->map(function (Agent $agent) use (
            $totalByAgent, $activeByAgent, $convertedByAgent, $convertedTodayByAgent,
            $followupsByAgent, $unfinishedByAgent, $teamNamesByAgent
        ) {
            $id = (int) $agent->id;
            $total = (int) ($totalByAgent[$id] ?? 0);
            $converted = (int) ($convertedByAgent[$id] ?? 0);

            return [
                'agent_id' => $id,
                'name' => $agent->user?->name ?? ('Caller #' . $id),
                'teams' => $teamNamesByAgent[$id] ?? [],
                'total' => $total,
                'active' => (int) ($activeByAgent[$id] ?? 0),
                'converted' => $converted,
                'converted_today' => (int) ($convertedTodayByAgent[$id] ?? 0),
                'needs_work' => (int) ($followupsByAgent[$id] ?? 0) + (int) ($unfinishedByAgent[$id] ?? 0),
                'followups' => (int) ($followupsByAgent[$id] ?? 0),
                'unfinished' => (int) ($unfinishedByAgent[$id] ?? 0),
                'conversion_rate' => $total > 0 ? round(($converted / $total) * 100, 1) : 0,
            ];
        });

        $summary = [
            'total' => $rows->sum('total'),
            'active' => $rows->sum('active'),
            'converted' => $rows->sum('converted'),
            'converted_today' => $rows->sum('converted_today'),
            'needs_work' => $rows->sum('needs_work'),
        ];

        $isSuperAdmin = $role === 'admin' && $this->superAdmins->isSuperAdmin($userId);
        $isDelegatedAdmin = $role === 'admin' && $this->access->hasDelegatedProfile($userId);
        $screenTitle = $isSuperAdmin ? 'Super Admin Contact Control' : ($isDelegatedAdmin ? 'Delegated Admin Contact Control' : 'Team Contact Control');

        return view('contacts.operations', compact(
            'rows', 'summary', 'teams', 'screenTitle', 'isSuperAdmin', 'isDelegatedAdmin', 'role'
        ));
    }
}
