<?php

namespace App\Models;

use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $table = 'leads';

    protected $fillable = [
        'customer_name', 'phone', 'email', 'source', 'budget',
        'status', 'previous_status', 'lost_reason', 'lost_reason_key', 'project_id', 'agent_id',
        'customer_id', 'parent_lead_id', 'origin_type', 'origin_note',
        'assigned_at', 'last_activity_at', 'unassigned_alerted_at', 'visit_scheduled_at',
        // Booking
        'booking_amount', 'property_area_sqft', 'rate_per_sqft',
        'booking_unit', 'booking_payment_mode', 'booking_date', 'tag_id',

        // Brokerage
        'brokerage_percentage', 'brokerage_amount', 'brokerage_status',
        'brokerage_expected_at', 'brokerage_received_at', 'co_broker_name',

        // Intake (from website / facebook / google)
        'intake_source', 'intake_ref',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'click_id', 'referrer_url', 'raw_payload',
    ];

    protected $casts = [
        'budget'                 => 'decimal:2',
        'booking_amount'         => 'decimal:2',
        'property_area_sqft'     => 'decimal:2',
        'rate_per_sqft'          => 'decimal:2',
        'booking_date'           => 'date',
        'brokerage_percentage'   => 'decimal:2',
        'brokerage_amount'       => 'decimal:2',
        'brokerage_expected_at'  => 'date',
        'brokerage_received_at'  => 'date',
        'assigned_at'            => 'datetime',
        'last_activity_at'       => 'datetime',
        'visit_scheduled_at'     => 'datetime',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    /* ============================================================
       RELATIONSHIPS
       ============================================================ */

    public function customer()         { return $this->belongsTo(Customer::class); }
    public function parentLead()       { return $this->belongsTo(self::class, 'parent_lead_id'); }
    public function childLeads()       { return $this->hasMany(self::class, 'parent_lead_id'); }
    public function agent()            { return $this->belongsTo(Agent::class); }
    public function project()          { return $this->belongsTo(Project::class); }
    public function activities()       { return $this->hasMany(Activity::class)->orderByDesc('logged_at'); }
    public function followups()        { return $this->hasMany(Followup::class); }
    public function bookingControl()   { return $this->hasOne(BookingControl::class); }
    public function pendingFollowups() { return $this->hasMany(Followup::class)->where('status', 'pending'); }
    public function pendingFollowup()  { return $this->hasOne(Followup::class)->where('status', 'pending')->ofMany('scheduled_for', 'min'); }

    public function assignments()
    {
        return $this->hasMany(LeadAgent::class);
    }

    public function agents()
    {
        return $this->belongsToMany(Agent::class, 'lead_agents')
            ->withPivot(['is_primary', 'is_active', 'added_by_user_id', 'note'])
            ->withTimestamps();
    }

    public function activeAgents()
    {
        return $this->agents()->wherePivot('is_active', true);
    }

    public function primaryAgent()
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function latestActivity()
    {
        return $this->hasOne(Activity::class)->latestOfMany('logged_at');
    }
    
    public function tag()
    {
        return $this->belongsTo(LeadTag::class, 'tag_id');
    }
    
    public function labels()
    {
        return $this->belongsToMany(LeadLabel::class, 'lead_label_pivot', 'lead_id', 'label_id')
            ->withTimestamps();
    }
    
    /* ============================================================
       SCOPES
       ============================================================ */

    public function scopeForAgent($query, ?int $agentId)
    {
        return $agentId ? $query->where('agent_id', $agentId) : $query;
    }
    public function scopeWithTag($q, $tagId)
    {
        return $tagId ? $q->where('tag_id', $tagId) : $q;
    }
    
    public function scopeWithAnyLabels($q, array $labelIds)
    {
        if (empty($labelIds)) return $q;
    
        return $q->whereHas('labels', function ($qq) use ($labelIds) {
            $qq->whereIn('lead_labels.id', $labelIds);
        });
    }
    /* ============================================================
       ASSIGNMENT HELPERS
       ============================================================ */

    /** All active assigned agent ids (primary + shared) */
    public function assignedAgentIds(): array
    {
        return $this->assignments()->active()->pluck('agent_id')->all();
    }

    /** Is the given agent assigned (primary or shared)? */
    public function isAssignedTo(?int $agentId): bool
    {
        if (! $agentId) return false;
        return $this->assignments()->active()->where('agent_id', $agentId)->exists();
    }

    public function isPrimaryAgent(?int $agentId): bool
    {
        return $agentId && (int) $this->agent_id === (int) $agentId;
    }

    /**
     * Whether an agent handles the given project directly or through an active team.
     * If $projectId is omitted, checks this lead's current project.
     */
    public function agentHandlesProject(?int $agentId, ?int $projectId = null): bool
    {
        if (! $agentId) {
            return false;
        }

        $projectId = $projectId ?? $this->project_id;
        if (! $projectId) {
            return false;
        }

        $direct = \DB::table('project_agent')
            ->where('project_id', $projectId)
            ->where('agent_id', $agentId)
            ->where('is_active', 1)
            ->exists();

        if ($direct) {
            return true;
        }

        return \DB::table('project_team as pt')
            ->join('team_members as tm', function ($join) {
                $join->on('tm.team_id', '=', 'pt.team_id')
                     ->where('tm.is_active', 1);
            })
            ->where('pt.project_id', $projectId)
            ->where('pt.is_active', 1)
            ->where('tm.agent_id', $agentId)
            ->exists();
    }

    /* ============================================================
       STATUS HELPERS
       ============================================================ */

    public function statusKey(): string
    {
        return (string) $this->status;
    }

    public function isFinal(): bool
    {
        return app(SettingsService::class)->statusIsFinal($this->statusKey());
    }

    public function isWon(): bool
    {
        return app(SettingsService::class)->statusIsWon($this->statusKey());
    }

    public function isLost(): bool
    {
        return app(SettingsService::class)->statusIsLost($this->statusKey());
    }

    public function isTerminal(): bool
    {
        return $this->isFinal();
    }

    public function getStatusLabelAttribute(): string
    {
        return app(SettingsService::class)->statusLabel($this->statusKey());
    }

    public function getStatusColorAttribute(): string
    {
        return app(SettingsService::class)->statusColor($this->statusKey());
    }

    /* ============================================================
       BOOKING / VISIT HELPERS
       ============================================================ */

    public function hasBooking(): bool
    {
        return $this->booking_amount !== null;
    }

    public function hasBrokerage(): bool
    {
        return $this->brokerage_amount !== null;
    }

    public function hasUpcomingVisit(): bool
    {
        return $this->visit_scheduled_at
            && $this->visit_scheduled_at->isFuture()
            && $this->statusKey() === 'visit_scheduled';
    }

    public function hasRecentActivity(int $minutes = 15): bool
    {
        if (! $this->last_activity_at) return false;
        return $this->last_activity_at->diffInMinutes(now()) <= $minutes;
    }

    /* ============================================================
       CUSTOMER HELPERS
       ============================================================ */

    /**
     * Other leads (enquiries) belonging to the same customer,
     * excluding this one. Useful for the "Other enquiries" panel.
     */
    public function otherEnquiries()
    {
        if (! $this->customer_id) return collect();

        return Lead::with(['project', 'agent.user'])
            ->where('customer_id', $this->customer_id)
            ->where('id', '!=', $this->id)
            ->orderByDesc('created_at')
            ->get();
    }

    /** Count of other leads from the same customer. */
    public function otherEnquiriesCount(): int
    {
        if (! $this->customer_id) return 0;

        return Lead::where('customer_id', $this->customer_id)
            ->where('id', '!=', $this->id)
            ->count();
    }

    /** Does this customer have an active enquiry on a *different* project? */
    public function hasCrossProjectEnquiry(): bool
    {
        if (! $this->customer_id) return false;

        return Lead::where('customer_id', $this->customer_id)
            ->where('id', '!=', $this->id)
            ->where('project_id', '!=', $this->project_id)
            ->whereNotIn('status', ['booking', 'lost'])
            ->exists();
    }

    /* ============================================================
       LOGGING ATTRIBUTION
       ============================================================ */

    public function resolveLoggingAgentId(int $fallback = 1): int
    {
        // If the current session user is an agent assigned to this lead,
        // attribute the activity/followup to THEM (works for shared agents).
        $sessionAgentId = Agent::where('user_id', session('user_id'))->value('id');

        if ($sessionAgentId && $this->isAssignedTo((int) $sessionAgentId)) {
            return (int) $sessionAgentId;
        }

        // Fall back to the primary agent
        if ($this->agent_id) {
            return (int) $this->agent_id;
        }

        return (int) ($sessionAgentId ?? $fallback);
    }
}