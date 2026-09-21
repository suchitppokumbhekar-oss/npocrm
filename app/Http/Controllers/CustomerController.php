<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadAgent;
use App\Services\AccessService;
use App\Services\TeamService;
use App\Services\DuplicateReconciliationService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CustomerController extends Controller
{
    public function __construct(
        private TeamService $teams,
        private AccessService $access,
        private DuplicateReconciliationService $reconciliation,
    ) {}

    public function show(int $id)
    {
        if (! session('user_id')) return redirect('/login');

        $role   = (string) session('user_role');
        $userId = (int) session('user_id');

        $customer = Customer::findOrFail($id);

        // All leads (enquiries) for this customer, filtered by role scope
        $leadsQuery = Lead::with(['agent.user', 'project', 'parentLead', 'latestActivity.agent.user', 'pendingFollowup.agent.user', 'activeAgents.user'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at');

        // Customer pages must not become a visibility backdoor. Scope the
        // customer's enquiries through the same active lead-assignment firewall
        // used by the Lead Directory and Search.
        if (($role === 'admin' && ! $this->access->isUnrestrictedAdmin($userId))
            || $role === 'team_manager' || $role === 'agent') {
            $agentIds = $this->access->visibleAgentIds();
            $leadIds = empty($agentIds)
                ? []
                : LeadAgent::whereIn('agent_id', $agentIds)
                    ->where('is_active', true)
                    ->pluck('lead_id')->unique()->all();
            $leadsQuery->whereIn('id', $leadIds ?: [-1]);
        }

        $allScopedLeads = $leadsQuery->get();

        if ((($role === 'admin' && ! $this->access->isUnrestrictedAdmin($userId))
                || in_array($role, ['team_manager', 'agent'], true)) && $allScopedLeads->isEmpty()) {
            abort(403, 'You are not allowed to view this customer.');
        }

        // A reconciled duplicate remains in the database for history/audit, but
        // is no longer counted as a current enquiry. The surviving lead remains
        // the operational record.
        $reconciledLeads = $allScopedLeads
            ->filter(fn ($lead) => $lead->origin_type === 'duplicate_reconciled')
            ->values();
        $leads = $allScopedLeads
            ->reject(fn ($lead) => $lead->origin_type === 'duplicate_reconciled')
            ->values();

        // Same customer + same project is a duplicate candidate only when the
        // lead has no existing parent relationship. Any existing relationship is
        // protected and must be reviewed separately rather than rewritten by the
        // duplicate reconciler.
        $isProtectedRelationship = fn ($lead) => (bool) $lead->parent_lead_id;

        $duplicateGroups = $leads
            ->filter(fn ($lead) => ! empty($lead->project_id) && ! $isProtectedRelationship($lead))
            ->groupBy('project_id')
            ->filter(fn ($group) => $group->count() > 1)
            ->values();

        $protectedRelationshipGroups = $leads
            ->filter(fn ($lead) => ! empty($lead->project_id) && $isProtectedRelationship($lead))
            ->groupBy('project_id')
            ->filter(fn ($group) => $group->isNotEmpty())
            ->values();

        // Stats
        $stats = [
            'total'  => $leads->count(),
            'active' => $leads->whereNotIn('status', ['booking', 'lost'])->count(),
            'booked' => $leads->where('status', 'booking')->count(),
            'lost'   => $leads->where('status', 'lost')->count(),
        ];

        // Unified timeline: activities across all scoped leads, including
        // reconciled duplicates so historical work is never hidden.
        $timelineLeadIds = $allScopedLeads->pluck('id')->all();
        $timeline = empty($leadIds)
            ? collect()
            : Activity::with(['agent.user', 'lead'])
                ->whereIn('lead_id', $timelineLeadIds)
                ->orderByDesc('logged_at')
                ->limit(100)
                ->get();

        return view('customers.show', compact('customer', 'leads', 'reconciledLeads', 'duplicateGroups', 'protectedRelationshipGroups', 'stats', 'timeline'));
    }

    public function reconcileDuplicates(Request $request, int $id)
    {
        if (! session('user_id')) return redirect('/login');

        $userId = (int) session('user_id');
        if (session('user_role') !== 'admin' || ! $this->access->isUnrestrictedAdmin($userId)) {
            abort(403, 'Only Super Admin can reconcile duplicate enquiries.');
        }

        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
            'survivor_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $result = $this->reconciliation->reconcile(
                (int) $id,
                (int) $validated['project_id'],
                (int) $validated['survivor_id'],
                $validated['reason'],
                $request,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', '⚠️ ' . $e->getMessage());
        }

        return redirect('/customers/' . $id)
            ->with('success', '✅ Duplicate enquiry reconciliation completed. Lead #' . $result['survivor_id'] . ' is now the surviving enquiry.'
                . ($result['transferred_followups'] ? ' ' . $result['transferred_followups'] . ' pending action(s) were moved to the surviving lead.' : ''));
    }

}