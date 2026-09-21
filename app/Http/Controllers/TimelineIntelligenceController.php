<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\SuperAdminService;
use App\Services\TimelineIntelligenceService;
use Illuminate\Http\Request;
use App\Models\Activity;

class TimelineIntelligenceController extends Controller
{
    public function __construct(
        private SuperAdminService $superAdmins,
        private TimelineIntelligenceService $intelligence,
    ) {}

    public function index(Request $request)
    {
        $this->superAdmins->requireSuperAdmin();

        $query = Lead::with(['project', 'agent.user'])
            ->whereNotIn('status', ['booking', 'lost'])
            ->orderByDesc('last_activity_at');

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(function ($q) use ($term) {
                $q->where('customer_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        $leads = $query->paginate(25)->withQueryString();
        $rows = $leads->getCollection()->map(function (Lead $lead) {
            $analysis = $this->intelligence->analyze($lead);
            $primary = $analysis['next_action'];
            $urgent = collect($analysis['signals'])->contains(fn (array $s) => in_array($s['severity'], ['urgent', 'warning'], true));

            return [
                'lead' => $lead,
                'analysis' => $analysis,
                'urgent' => $urgent,
                'priority' => $urgent ? 0 : 1,
                'action' => $primary['label'],
            ];
        })->sortBy(fn (array $row) => [$row['priority'], -strtotime((string) ($row['lead']->last_activity_at?->toIso8601String() ?? '1970-01-01'))])->values();

        $leads->setCollection($rows);
        return view('admin.timeline-intelligence', compact('rows', 'leads'));
    }

    public function lead(int $id)
    {
        $this->superAdmins->requireSuperAdmin();

        $lead = Lead::with(['project', 'agent.user', 'customer'])->findOrFail($id);
        $analysis = $this->intelligence->analyze($lead);
        $activities = Activity::query()->where('lead_id', $lead->id)->orderByDesc('logged_at')->paginate(20, ['*'], 'timeline_page')->withQueryString();

        return view('admin.timeline-intelligence-lead', compact('lead', 'analysis', 'activities'));
    }
}
