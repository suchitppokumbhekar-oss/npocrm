<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Illuminate\Http\Request;
use App\Models\Followup;
use App\Services\AccessService;
use App\Services\DelegatedAccessService;
use App\Services\TeamService;

class TaskController extends Controller
{
    public function __construct(
        private DelegatedAccessService $delegatedAccess,
        private AccessService $access
    ) {}

    public function index(Request $request)
    {
        if (! session('user_id')) {
            return redirect('/login');
        }

        $userId   = (int) session('user_id');
        $userRole = session('user_role');
        $agentId  = Agent::where('user_id', $userId)->value('id');

        /* ============================================================
           SCOPE
           ============================================================ */

        $scopedAgentIds = null;

        /*
         * Delegated access takes priority over the user's broad role.
         *
         * AJ can therefore give an Admin access to selected users/teams
         * without giving that Admin access to every CRM task.
         */
        if ($this->delegatedAccess->hasProfile($userId)) {

            $scopedAgentIds = $this->delegatedAccess->visibleAgentIds($userId);

            /*
             * No permitted agents = no permitted tasks.
             */
            if (empty($scopedAgentIds)) {
                $scopedAgentIds = [-1];
            }

        } elseif ($userRole === 'team_manager') {

            /*
             * Existing Team Manager behaviour.
             */
            $scopedAgentIds = app(TeamService::class)
                ->agentIdsForManager($userId);

            if (empty($scopedAgentIds)) {
                $scopedAgentIds = [-1];
            }

        } elseif ($userRole === 'agent' && $agentId) {

            /*
             * Existing Agent behaviour.
             */
            $scopedAgentIds = [$agentId];
        }

        /* ============================================================
           TASKS
           ============================================================ */

        $query = Followup::with([
                'lead.project',
                'agent.user',
            ])
            ->where('status', 'pending')
            ->orderBy('scheduled_for', 'asc');

        /*
         * IMPORTANT:
         *
         * The scope is applied to the actual database query.
         * We are not merely hiding unauthorized tasks in the Blade.
         */
        if ($scopedAgentIds !== null) {
            $query->whereIn('agent_id', $scopedAgentIds);
        }

        // A task's assigned agent is not sufficient by itself: the underlying
        // lead must still be inside the actor's current lead-work scope.
        $allTasks = $query->get()
            ->filter(fn ($task) => $task->lead && $this->access->canWorkLead($task->lead))
            ->values();

        /* ============================================================
           GROUP BY DATE BUCKET
           ============================================================ */

        $now           = now();
        $endToday      = now()->endOfDay();
        $startTomorrow = now()->addDay()->startOfDay();
        $endTomorrow   = now()->addDay()->endOfDay();
        $endThisWeek   = now()->addDays(7)->endOfDay();

        $buckets = [

            /*
             * Future reactivation reminders are nurture, regardless
             * of whether they fall today, tomorrow, this week, or
             * months away.
             */
            'overdue' => $allTasks->filter(fn ($t) =>
                $t->scheduled_for
                && $t->scheduled_for->isPast()
                && ! ($t->action_type === 'reactivation_call')
            ),

            'today' => $allTasks->filter(fn ($t) =>
                $t->scheduled_for
                && $t->scheduled_for->isFuture()
                && $t->scheduled_for->lessThanOrEqualTo($endToday)
                && $t->action_type !== 'reactivation_call'
            ),

            'tomorrow' => $allTasks->filter(fn ($t) =>
                $t->scheduled_for
                && $t->scheduled_for->greaterThanOrEqualTo($startTomorrow)
                && $t->scheduled_for->lessThanOrEqualTo($endTomorrow)
                && $t->action_type !== 'reactivation_call'
            ),

            'this_week' => $allTasks->filter(fn ($t) =>
                $t->scheduled_for
                && $t->scheduled_for->greaterThan($endTomorrow)
                && $t->scheduled_for->lessThanOrEqualTo($endThisWeek)
                && $t->action_type !== 'reactivation_call'
            ),

            'later' => $allTasks->filter(fn ($t) =>
                $t->scheduled_for
                && $t->scheduled_for->greaterThan($endThisWeek)
                && $t->action_type !== 'reactivation_call'
            ),

            'reactivation' => $allTasks->filter(fn ($t) =>
                $t->scheduled_for
                && $t->scheduled_for->isFuture()
                && $t->action_type === 'reactivation_call'
            ),
        ];

        /* ============================================================
           COUNTS
           ============================================================ */

        $currentWorkCount = $allTasks->filter(function ($t) use ($now) {
            if (! $t->scheduled_for) {
                return false;
            }

            if (
                $t->action_type === 'reactivation_call'
                && $t->scheduled_for->isFuture()
            ) {
                return false;
            }

            return true;
        })->count();

        $nurtureCount = $allTasks->filter(fn ($t) =>
            $t->action_type === 'reactivation_call'
            && $t->scheduled_for
            && $t->scheduled_for->isFuture()
        )->count();

        $bucketFilter = $request->input('bucket');
        $allowedBuckets = ['overdue', 'today', 'tomorrow', 'this_week', 'later', 'reactivation'];
        if (in_array($bucketFilter, $allowedBuckets, true)) {
            foreach ($buckets as $key => $items) {
                if ($key !== $bucketFilter) {
                    $buckets[$key] = collect();
                }
            }
        }

        // The task page is an execution surface, so the default view is
        // deliberately "now" rather than a wall of every future task.
        $viewMode = $request->input('view');
        if (! in_array($viewMode, ['now', 'upcoming', 'nurture'], true)) {
            if ($bucketFilter === 'reactivation') {
                $viewMode = 'nurture';
            } elseif (in_array($bucketFilter, ['tomorrow', 'this_week', 'later'], true)) {
                $viewMode = 'upcoming';
            } else {
                $viewMode = 'now';
            }
        }

        $viewCounts = [
            'now' => $buckets['overdue']->count() + $buckets['today']->count(),
            'upcoming' => $buckets['tomorrow']->count() + $buckets['this_week']->count() + $buckets['later']->count(),
            'nurture' => $buckets['reactivation']->count(),
        ];

        // A bucket deep-link should open exactly that bucket while the
        // broader tab still explains where the user is in the work queue.
        if ($bucketFilter === 'overdue' || $bucketFilter === 'today') {
            $viewMode = 'now';
        }

        return view('tasks.index', compact(
            'buckets',
            'allTasks',
            'currentWorkCount',
            'nurtureCount',
            'viewMode',
            'viewCounts'
        ));
    }
}