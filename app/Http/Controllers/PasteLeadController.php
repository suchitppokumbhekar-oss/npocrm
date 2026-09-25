<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\LeadLabel;
use App\Models\Project;
use App\Services\LeadAssignmentService;
use App\Services\LeadTagService;
use App\Services\SettingsService;
use App\Services\AccessService;
use App\Services\LeadDuplicateGuard;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PasteLeadController extends Controller
{
    public function __construct(
        private AccessService $access,
        private LeadDuplicateGuard $duplicateGuard,
    ) {}
    private function requireAdmin(): void
    {
        if (! session('user_id')) {
            abort(401, 'Session expired.');
        }
        if (! $this->access->can('leads.manage')) {
            abort(403, 'Admin only.');
        }
    }

    /* ============================================================
       SHOW the paste + preview page
       ============================================================ */
    public function show()
    {
        $this->requireAdmin();

        $labels = LeadLabel::active()->ordered()->get();
        $tags   = app(LeadTagService::class)->tags();

        $isDelegatedAdmin = session('user_role') === 'admin'
            && ! $this->access->isUnrestrictedAdmin((int) session('user_id'));

        if ($isDelegatedAdmin) {
            $allowedAgentIds = $this->access->visibleAgentIds();
            $agents = Agent::with('user')
                ->where('status', 'active')
                ->whereIn('id', $allowedAgentIds ?: [-1])
                ->orderBy('id')
                ->get();

            $directProjectIds = DB::table('project_agent')
                ->whereIn('agent_id', $allowedAgentIds ?: [-1])
                ->where('is_active', 1)
                ->pluck('project_id');
            $teamProjectIds = DB::table('project_team as pt')
                ->join('team_members as tm', function ($join) {
                    $join->on('tm.team_id', '=', 'pt.team_id')
                        ->where('tm.is_active', 1);
                })
                ->whereIn('tm.agent_id', $allowedAgentIds ?: [-1])
                ->where('pt.is_active', 1)
                ->pluck('pt.project_id');

            $projectIds = $directProjectIds->merge($teamProjectIds)->unique()->values();
            $projects = Project::active()
                ->whereIn('id', $projectIds->all() ?: [-1])
                ->orderBy('name')
                ->get(['id', 'name']);
        } else {
            $projects = Project::active()->orderBy('name')->get(['id', 'name']);
            $agents = Agent::with('user')->where('status', 'active')->orderBy('id')->get();
        }

        return view('admin.paste-lead', compact('projects', 'labels', 'tags', 'agents'));
    }

    /* ============================================================
       STORE the parsed lead
       ============================================================ */
    public function store(Request $request)
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'phone'         => 'required|string|max:20',
            'email'         => 'nullable|email|max:255',
            'source'        => 'required|string|max:255',
            'project_id'    => 'required|integer|exists:projects,id',
            'budget'        => 'nullable|numeric|min:0',
            'agent_id'      => 'nullable|integer|exists:agents,id',
            'tag_id'        => 'nullable|integer|exists:lead_tags,id',
            'label_ids'     => 'nullable|array',
            'label_ids.*'   => 'integer|exists:lead_labels,id',
            'notes'         => 'nullable|string|max:5000',
            'campaign'      => 'nullable|string|max:255',
            'adset'         => 'nullable|string|max:255',
            'ad'            => 'nullable|string|max:255',
            'visit_pref'    => 'nullable|string|max:255',
            'raw_text'      => 'nullable|string|max:20000',
        ]);

        // One customer + one project = one enquiry. This protects the manual
        // paste path and also catches older leads missing customer_id.
        $existing = $this->duplicateGuard->findExisting(
            (int) $validated['project_id'],
            null,
            $validated['phone'],
            $validated['email'] ?? null,
        );
        if ($existing) {
            return redirect('/leads/' . $existing->id)
                ->with('error', '⚠️ ' . $this->duplicateGuard->message($existing));
        }

        $customer = app(CustomerService::class)->findOrCreateByPhone($validated['phone'], [
            'name' => $validated['customer_name'],
            'email' => $validated['email'] ?? null,
        ]);

        $existing = $this->duplicateGuard->findExisting(
            (int) $validated['project_id'],
            $customer?->id,
            $validated['phone'],
            $validated['email'] ?? null,
        );
        if ($existing) {
            return redirect('/leads/' . $existing->id)
                ->with('error', '⚠️ ' . $this->duplicateGuard->message($existing));
        }

        $isDelegatedAdmin = session('user_role') === 'admin' && ! $this->access->isUnrestrictedAdmin((int) session('user_id'));
        if ($isDelegatedAdmin) {
            $allowedAgents = $this->access->visibleAgentIds();
            $requestedAgent = isset($validated['agent_id']) ? (int) $validated['agent_id'] : null;
            if ($requestedAgent !== null && ! in_array($requestedAgent, $allowedAgents, true)) {
                abort(403, 'The selected agent is outside your delegated scope.');
            }
            if ($requestedAgent === null && empty($allowedAgents)) {
                abort(403, 'No agent is available in your delegated scope for this lead.');
            }

            $projectHasAllowedAgent = DB::table('project_agent')
                ->where('project_id', (int) $validated['project_id'])
                ->whereIn('agent_id', $allowedAgents ?: [-1])
                ->where('is_active', 1)
                ->exists()
                || DB::table('project_team as pt')
                    ->join('team_members as tm', function ($join) {
                        $join->on('tm.team_id', '=', 'pt.team_id')
                            ->where('tm.is_active', 1);
                    })
                    ->join('agents as a', 'a.id', '=', 'tm.agent_id')
                    ->where('pt.project_id', (int) $validated['project_id'])
                    ->where('pt.is_active', 1)
                    ->where('a.status', 'active')
                    ->whereIn('tm.agent_id', $allowedAgents ?: [-1])
                    ->exists();
            if (! $projectHasAllowedAgent) {
                abort(403, 'The selected project has no active agent in your delegated scope.');
            }
        }

        $lead = DB::transaction(function () use ($validated, $customer) {

            // Build the intake note that appears on the timeline
            $noteLines = ['🌐 New lead from: admin paste'];

            if (! empty($validated['campaign']))   $noteLines[] = '📢 Campaign: ' . $validated['campaign'];
            if (! empty($validated['adset']))      $noteLines[] = '📢 Adset: ' . $validated['adset'];
            if (! empty($validated['ad']))         $noteLines[] = '📢 Ad: ' . $validated['ad'];

            if (! empty($validated['visit_pref'])) {
                $noteLines[] = '🏠 Visit preference: ' . str_replace('_', ' ', $validated['visit_pref']);
            }

            if (! empty($validated['notes'])) {
                $noteLines[] = '';
                $noteLines[] = 'Notes: ' . $validated['notes'];
            }

            // Create the lead
            $lead = Lead::create([
                'customer_id'      => $customer?->id,
                'customer_name'    => $validated['customer_name'],
                'phone'            => $validated['phone'],
                'email'            => $validated['email'] ?? null,
                'source'           => $validated['source'],
                'budget'           => $validated['budget'] ?? null,
                'project_id'       => $validated['project_id'],
                'status'           => 'new',
                'tag_id'           => $validated['tag_id'] ?? null,
                'intake_source'    => 'admin_paste',
                'raw_payload'      => $validated['raw_text'] ?? null,
                'last_activity_at' => now(),
            ]);

            // Apply labels (multi-select)
            if (! empty($validated['label_ids'])) {
                $lead->labels()->sync(
                    array_values(array_unique(array_map('intval', $validated['label_ids'])))
                );
            }

            // Assign agent — specific if chosen, else auto-route
            if (! empty($validated['agent_id'])) {
                $agent = Agent::find($validated['agent_id']);
                if ($agent) {
                    app(LeadAssignmentService::class)->assignTo($lead, $agent, session('user_id'));
                }
            } else {
                if ($isDelegatedAdmin) {
                    app(LeadAssignmentService::class)->assignLeastLoaded($lead, $allowedAgents);
                } else {
                    app(LeadAssignmentService::class)->assign($lead);
                }
            }

            // Log the intake activity on the timeline
            Activity::create([
            'action_source' => 'manual',
                'lead_id'     => $lead->id,
                'agent_id'    => $lead->resolveLoggingAgentId(),
                'type'        => 'note',
                'outcome'     => ucfirst(str_replace('_', ' ', $validated['source'])) . ' lead captured',
                'outcome_key' => null,
                'notes'       => implode("\n", $noteLines),
                'logged_at'   => now(),
            ]);

            // A genuinely new assigned lead starts with an immediate Call.
            if ($lead->agent_id) {
                app(\App\Services\FollowupService::class)->scheduleNewLeadCall($lead);
            }

            return $lead;
        });

        return redirect('/leads/' . $lead->id)
            ->with('success', '✅ Lead created from paste. First-contact follow-up scheduled.');
    }
}