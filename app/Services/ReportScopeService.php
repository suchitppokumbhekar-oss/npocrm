<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Team;
use App\Models\TeamMember;

class ReportScopeService
{
    public function userId(): ?int   { return session('user_id'); }
    public function role(): string   { return (string) session('user_role'); }

    public function isAdmin(): bool       { return $this->role() === 'admin'; }
    public function isTeamManager(): bool { return $this->role() === 'team_manager'; }
    public function isAgent(): bool       { return $this->role() === 'agent'; }

    /** Can this user see CRM-wide health reports? */
    public function canSeeCrmHealth(): bool { return $this->isAdmin(); }

    /** Can this user see team / agent reports at all? */
    public function canSeeReports(): bool
    {
        return in_array($this->role(), ['admin', 'team_manager'], true);
    }

    /** Team IDs visible to the current user. */
    public function teamIds(): array
    {
        if ($this->isAdmin()) {
            return Team::where('is_active', true)->pluck('id')->all();
        }

        if ($this->isTeamManager()) {
            return Team::where('manager_user_id', $this->userId())
                ->where('is_active', true)
                ->pluck('id')
                ->all();
        }

        return [];
    }

    /** Agent IDs visible to the current user. */
    public function agentIds(): array
    {
        if ($this->isAdmin()) {
            return Agent::pluck('id')->all();
        }

        if ($this->isTeamManager()) {
            $teamIds = $this->teamIds();
            if (empty($teamIds)) return [];

            $ids = TeamMember::whereIn('team_id', $teamIds)
                ->where('is_active', true)
                ->pluck('agent_id')
                ->unique()
                ->values()
                ->all();

            // Include the manager's own Agent record
            $self = Agent::where('user_id', $this->userId())->value('id');
            if ($self && ! in_array($self, $ids, true)) {
                $ids[] = (int) $self;
            }

            return $ids;
        }

        if ($this->isAgent()) {
            $self = Agent::where('user_id', $this->userId())->value('id');
            return $self ? [(int) $self] : [];
        }

        return [];
    }
}