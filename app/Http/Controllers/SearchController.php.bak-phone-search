<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadAgent;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamMember;
use App\Services\AccessService;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function __construct(private TeamService $teams, private AccessService $access) {}

    public function index(Request $request)
    {
        if (! session('user_id')) return redirect('/login');

        $q          = trim((string) $request->input('q', ''));
        $typeFilter = $request->input('type', 'all');
        $createdFrom = $request->input('created_from');
        $createdTo = $request->input('created_to');
        if ($createdFrom && !strtotime($createdFrom)) $createdFrom = null;
        if ($createdTo && !strtotime($createdTo)) $createdTo = null;

        $results = [
            'customers' => ['items' => collect(), 'total' => 0],
            'leads'     => ['items' => collect(), 'total' => 0],
            'contacts'  => ['items' => collect(), 'total' => 0],
            'projects'  => ['items' => collect(), 'total' => 0],
            'agents'    => ['items' => collect(), 'total' => 0],
            'teams'     => ['items' => collect(), 'total' => 0],
        ];

        $total = 0;

        if (mb_strlen($q) >= 2) {
            $results = $this->search($q, $typeFilter, $createdFrom, $createdTo);
            $total   = array_sum(array_map(fn ($r) => $r['total'], $results));
        }

        return view('search.index', compact('q', 'typeFilter', 'results', 'total', 'createdFrom', 'createdTo'));
    }

    private function search(string $q, string $typeFilter, ?string $createdFrom = null, ?string $createdTo = null): array
    {
        $role   = (string) session('user_role');
        $userId = (int) session('user_id');

        $out = [
            'customers' => ['items' => collect(), 'total' => 0],
            'leads'     => ['items' => collect(), 'total' => 0],
            'contacts'  => ['items' => collect(), 'total' => 0],
            'projects'  => ['items' => collect(), 'total' => 0],
            'agents'    => ['items' => collect(), 'total' => 0],
            'teams'     => ['items' => collect(), 'total' => 0],
        ];

        $want = fn (string $key) => $typeFilter === 'all' || $typeFilter === $key;

        if ($want('customers') && in_array($role, ['admin', 'team_manager'], true)) {
            $out['customers'] = $this->searchCustomers($q, $role, $userId, $createdFrom, $createdTo);
        }

        if ($want('leads')) {
            $out['leads'] = $this->searchLeads($q, $role, $userId, $createdFrom, $createdTo);
        }

        if ($want('contacts')) {
            $out['contacts'] = $this->searchContacts($q, $role, $userId);
        }

        if ($want('projects')) {
            $out['projects'] = $this->searchProjects($q, $role, $userId);
        }

        if ($want('agents')) {
            $out['agents'] = $this->searchAgents($q, $role, $userId);
        }

        if ($want('teams')) {
            $out['teams'] = $this->searchTeams($q, $role, $userId);
        }

        return $out;
    }

    /* ============================================================ */

    private function searchCustomers(string $q, string $role, int $userId, ?string $createdFrom = null, ?string $createdTo = null): array
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $phoneLike = null;
        $normalized = Customer::normalizePhone($q);
        if (strlen($normalized) >= 3) $phoneLike = '%' . $normalized . '%';

        $base = Customer::query()->where(function ($qq) use ($like, $phoneLike) {
            $qq->where('name', 'like', $like)
               ->orWhere('email', 'like', $like);
            if ($phoneLike) $qq->orWhere('phone', 'like', $phoneLike);
        });

        if (($role === 'admin' && ! $this->access->isUnrestrictedAdmin($userId))
            || $role === 'team_manager' || $role === 'agent') {
            $agentIds = $this->access->visibleAgentIds();
            $customerIds = empty($agentIds)
                ? []
                : LeadAgent::whereIn('lead_agents.agent_id', $agentIds)
                    ->where('lead_agents.is_active', true)
                    ->join('leads', 'leads.id', '=', 'lead_agents.lead_id')
                    ->whereNotNull('leads.customer_id')
                    ->distinct()
                    ->pluck('leads.customer_id')
                    ->all();

            $base->whereIn('id', $customerIds ?: [-1]);
        }

        if ($createdFrom) $base->whereDate('created_at', '>=', $createdFrom);
        if ($createdTo) $base->whereDate('created_at', '<=', $createdTo);

        $total = (clone $base)->count();

        $items = $base
            ->withCount('leads')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        foreach ($items as $customer) {
            $customer->other_count = max(0, $customer->leads_count - 1);
        }

        return ['items' => $items, 'total' => $total];
    }

    private function searchLeads(string $q, string $role, int $userId, ?string $createdFrom = null, ?string $createdTo = null): array
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $phoneLike = null;
        $normalized = Customer::normalizePhone($q);
        if (strlen($normalized) >= 3) $phoneLike = '%' . $normalized . '%';

        $base = Lead::with([
                'agent.user',
                'project',
                'customer',
                'tag',
                'labels',
                'pendingFollowups',
            ])
            ->where(function ($qq) use ($like, $phoneLike) {
                $qq->where('customer_name', 'like', $like)
                   ->orWhere('email', 'like', $like);

                if ($phoneLike) {
                    $qq->orWhere('phone', 'like', $phoneLike);
                }

                // ---- Tag match: "hot", "Hot", etc. ----
                $qq->orWhereHas('tag', function ($tq) use ($like) {
                    $tq->where('label', 'like', $like)
                       ->orWhere('key', 'like', $like);
                });

                // ---- Label match: "2 BHK", "2bhk", "investor", etc. ----
                $qq->orWhereHas('labels', function ($lq) use ($like) {
                    $lq->where('label', 'like', $like)
                       ->orWhere('key', 'like', $like);
                });
            });

        // Search must use the same active-assignment firewall as the Lead Directory.
        // This prevents delegated Admins (and future roles) from discovering
        // leads outside their effective visibility scope.
        if (($role === 'admin' && ! $this->access->isUnrestrictedAdmin($userId))
            || $role === 'team_manager' || $role === 'agent') {
            $agentIds = $this->access->visibleAgentIds();
            $leadIds = empty($agentIds)
                ? []
                : LeadAgent::whereIn('lead_agents.agent_id', $agentIds)
                    ->where('lead_agents.is_active', true)
                    ->pluck('lead_id')->unique()->all();
            $base->whereIn('id', $leadIds ?: [-1]);
        }

        $total = (clone $base)->count();

        $items = $base->orderByDesc('id')->limit(25)->get();

        return ['items' => $items, 'total' => $total];
    }

    private function searchContacts(string $q, string $role, int $userId): array
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $phoneLike = null;
        $normalized = Customer::normalizePhone($q);
        if (strlen($normalized) >= 3) $phoneLike = '%' . $normalized . '%';

        $base = Contact::with(['project', 'agent.user'])
            ->where(function ($qq) use ($like, $phoneLike) {
                $qq->where('name', 'like', $like)
                   ->orWhere('email', 'like', $like);
                if ($phoneLike) $qq->orWhere('phone', 'like', $phoneLike);
            });

        if ($role === 'agent') {
            $agentId = Agent::where('user_id', $userId)->value('id');
            $base->where('assigned_to_agent_id', $agentId ?: -1);
        } elseif ($role === 'team_manager') {
            $agentIds = $this->access->visibleAgentIds();
            $base->whereIn('assigned_to_agent_id', $agentIds ?: [-1]);
        } elseif ($role === 'admin' && ! $this->access->isUnrestrictedAdmin($userId)) {
            // Global Contact Search must use the same delegated-agent firewall
            // as the Contact index. Explicitly excluded users therefore cannot
            // be discovered by name, phone, or email search.
            $agentIds = $this->access->visibleAgentIds();
            $base->where(function ($scope) use ($agentIds) {
                $scope->whereNull('assigned_to_agent_id');
                if ($agentIds) {
                    $scope->orWhereIn('assigned_to_agent_id', $agentIds);
                }
            });
        }

        $total = (clone $base)->count();
        $items = $base->orderByDesc('id')->limit(25)->get();

        return ['items' => $items, 'total' => $total];
    }

    private function searchProjects(string $q, string $role, int $userId): array
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        $base = Project::where(function ($qq) use ($like) {
            $qq->where('name', 'like', $like)
               ->orWhere('location', 'like', $like)
               ->orWhere('rera_number', 'like', $like);
        });

        // Project discovery must follow the same lead visibility firewall as
        // Lead Search. A project route alone is not enough: if the user has no
        // active lead assignment in the project, the project is not discoverable
        // from global search. This prevents restricted/empty projects from
        // leaking through name/location matches.
        if ($role === 'admin' && ! $this->access->isUnrestrictedAdmin($userId)) {
            $agentIds = $this->access->visibleAgentIds();

            if ($agentIds === []) {
                $base->whereRaw('1 = 0');
            } else {
                $base->whereExists(function ($q) use ($agentIds) {
                    $q->select(DB::raw(1))
                        ->from('leads')
                        ->join('lead_agents', 'lead_agents.lead_id', '=', 'leads.id')
                        ->whereColumn('leads.project_id', 'projects.id')
                        ->whereIn('lead_agents.agent_id', $agentIds)
                        ->where('lead_agents.is_active', true);
                });
            }
        } elseif ($role === 'team_manager' || $role === 'agent') {
            $agentIds = $this->access->visibleAgentIds();

            if ($agentIds === []) {
                $base->whereRaw('1 = 0');
            } else {
                $base->whereExists(function ($q) use ($agentIds) {
                    $q->select(DB::raw(1))
                        ->from('leads')
                        ->join('lead_agents', 'lead_agents.lead_id', '=', 'leads.id')
                        ->whereColumn('leads.project_id', 'projects.id')
                        ->whereIn('lead_agents.agent_id', $agentIds)
                        ->where('lead_agents.is_active', true);
                });
            }
        }

        $total = (clone $base)->count();
        $items = $base
            ->withCount('leads')
            ->orderBy('name')
            ->limit(25)
            ->get();

        return ['items' => $items, 'total' => $total];
    }

    private function searchAgents(string $q, string $role, int $userId): array
    {
        if ($role === 'agent') {
            return ['items' => collect(), 'total' => 0];
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        $base = Agent::with(['user', 'teams'])
            ->whereHas('user', function ($uq) use ($like) {
                $uq->where('name', 'like', $like)
                   ->orWhere('email', 'like', $like);
            });

        if ($role === 'team_manager') {
            $agentIds = $this->teams->agentIdsForManager($userId);
            $base->whereIn('id', $agentIds ?: [-1]);
        }

        $total = (clone $base)->count();
        $items = $base->orderBy('id')->limit(25)->get();

        return ['items' => $items, 'total' => $total];
    }

    private function searchTeams(string $q, string $role, int $userId): array
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        $base = Team::with('manager')->where('name', 'like', $like);

        if ($role === 'agent') {
            $agentId = Agent::where('user_id', $userId)->value('id');
            $teamIds = $agentId
                ? TeamMember::where('agent_id', $agentId)->where('is_active', true)->pluck('team_id')->all()
                : [];
            $base->whereIn('id', $teamIds ?: [-1]);
        } elseif ($role === 'team_manager') {
            $base->where('manager_user_id', $userId);
        }

        $total = (clone $base)->count();
        $items = $base->orderBy('name')->limit(25)->get();

        return ['items' => $items, 'total' => $total];
    }
}