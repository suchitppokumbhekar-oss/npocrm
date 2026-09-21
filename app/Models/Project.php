<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Project extends Model
{
    protected $table = 'projects';

    protected $fillable = ['name', 'location', 'rera_number', 'status'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Per-instance cache for eligibleAgents() so calling
     * eligibleAgents() + eligibleAgentIds() in a row doesn't
     * hit the DB twice.
     */
    private ?array $eligibleAgentsCache = null;

    /* ============================================================
       RELATIONSHIPS
       ============================================================ */

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    /* ============================================================
       ROUTING RELATIONSHIPS
       ============================================================ */

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'project_team')
            ->withPivot(['priority', 'is_active'])
            ->withTimestamps();
    }

    public function activeTeams()
    {
        return $this->teams()
            ->wherePivot('is_active', true)
            ->orderByPivot('priority');
    }

    public function directAgents()
    {
        return $this->belongsToMany(Agent::class, 'project_agent')
            ->withPivot(['priority', 'is_active'])
            ->withTimestamps();
    }

    public function activeDirectAgents()
    {
        return $this->directAgents()
            ->wherePivot('is_active', true)
            ->orderByPivot('priority');
    }

    /* ============================================================
       SCOPES
       ============================================================ */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
 * Restrict projects to those visible to the given user.
 *   - Admin:           sees everything
 *   - Team Manager:    sees projects routed to any team they manage
 *                      OR any team they're a member of
 *   - Agent:           sees projects routed to any of their teams
 */
public function scopeVisibleTo($query, ?int $userId, ?string $role = null)
{
    if ($role === 'admin') return $query;
    if (! $userId) return $query->whereRaw('1 = 0');

    $agent = Agent::where('user_id', $userId)->first();
    $directAgentId = $agent ? (int) $agent->id : 0;
    $teamIds = $agent ? TeamMember::where('agent_id', $agent->id)->where('is_active', true)->pluck('team_id')->all() : [];

    if ($role === 'team_manager') {
        $teamIds = array_merge($teamIds, Team::where('manager_user_id', $userId)->where('is_active', true)->pluck('id')->all());
    }
    $teamIds = array_values(array_unique(array_map('intval', $teamIds)));

    if (! $directAgentId && empty($teamIds)) return $query->whereRaw('1 = 0');

    return $query->where(function ($scope) use ($teamIds, $directAgentId) {
        if ($directAgentId) {
            $scope->whereExists(function ($q) use ($directAgentId) {
                $q->select(DB::raw(1))->from('project_agent')->whereColumn('project_agent.project_id', 'projects.id')->where('project_agent.agent_id', $directAgentId)->where('project_agent.is_active', 1);
            });
        }
        if ($teamIds) {
            $scope->orWhereExists(function ($q) use ($teamIds) {
                $q->select(DB::raw(1))->from('project_team')->whereColumn('project_team.project_id', 'projects.id')->whereIn('project_team.team_id', $teamIds)->where('project_team.is_active', 1);
            });
        }
    });
}

    /* ============================================================
       ELIGIBLE AGENTS — for auto-assignment (excludes admins)
       ============================================================ */

    /**
     * Returns eligible agents in priority order:
     *   1. Direct agents (source = 'direct')
     *   2. Team members    (source = 'team')
     *
     * Admins are always excluded from auto-assignment.
     *
     * Result is memoised per Project instance — cheap to call
     * repeatedly (e.g. eligibleAgents() then eligibleAgentIds()).
     *
     * @return array<int, array{agent_id:int, source:string, priority:int}>
     */
    public function eligibleAgents(): array
    {
        if ($this->eligibleAgentsCache !== null) {
            return $this->eligibleAgentsCache;
        }

        $rows = [];

        /* -------- Direct agents (highest priority) -------- */
        foreach ($this->activeDirectAgents()->with('user')->get() as $agent) {
            if ($agent->isAdmin()) {
                continue;
            }

            $rows[$agent->id] = [
                'agent_id' => $agent->id,
                'source'   => 'direct',
                'priority' => (int) $agent->pivot->priority,
            ];
        }

        /* -------- Team members -------- */
        foreach ($this->activeTeams()->get() as $team) {
            $teamPriority = (int) $team->pivot->priority;

            foreach ($team->agents()->wherePivot('is_active', true)->with('user')->get() as $agent) {
                if ($agent->isAdmin()) {
                    continue;
                }

                // Direct agents take precedence — never overwrite
                if (! isset($rows[$agent->id])) {
                    $rows[$agent->id] = [
                        'agent_id' => $agent->id,
                        'source'   => 'team',
                        'priority' => $teamPriority,
                    ];
                }
            }
        }

        usort($rows, function ($a, $b) {
            if ($a['source'] !== $b['source']) {
                return $a['source'] === 'direct' ? -1 : 1;
            }
            return $a['priority'] <=> $b['priority'];
        });

        return $this->eligibleAgentsCache = array_values($rows);
    }

    public function eligibleAgentIds(): array
    {
        return array_column($this->eligibleAgents(), 'agent_id');
    }
    /**
     * Same as eligibleAgentIds() but keeps the two tiers separate, so
     * the assignment engine can enforce "direct agents first, teams only
     * as fallback" instead of merging them into one flat pool.
     *
     * @return array{direct: int[], team: int[]}
     */
    public function eligibleAgentIdsBySource(): array
    {
        $direct = [];
        $team   = [];
    
        foreach ($this->eligibleAgents() as $row) {
            if ($row['source'] === 'direct') {
                $direct[] = $row['agent_id'];
            } else {
                $team[] = $row['agent_id'];
            }
        }
    
        return ['direct' => $direct, 'team' => $team];
    }
    /**
     * Clear the memoised eligible agents.
     * Useful after mutating project_agent / project_team in the
     * same request (e.g. bulk routing) and then re-reading.
     */
    public function forgetEligibleAgentsCache(): void
    {
        $this->eligibleAgentsCache = null;
    }

    /* ============================================================
       LOOKUP
       ============================================================ */

    public static function findByNameCI(string $name): ?self
    {
        return static::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
            ->first();
    }
}