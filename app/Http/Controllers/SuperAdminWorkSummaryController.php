<?php

namespace App\Http\Controllers;

use App\Services\SuperAdminWorkSummaryService;
use Illuminate\Http\Request;

class SuperAdminWorkSummaryController extends Controller
{
    public function __construct(private SuperAdminWorkSummaryService $work) {}

    public function index(Request $request)
    {
        $this->work->requireAccess();
        $period = $request->input('period', 'today');
        if (! in_array($period, SuperAdminWorkSummaryService::PERIODS, true)) $period = 'today';

        return view('admin.super-admin-work-summary', [
            'period' => $period,
            'summary' => $this->work->summary($period),
            'metrics' => SuperAdminWorkSummaryService::METRICS,
            'from' => $this->work->period($period)[0],
            'to' => $this->work->period($period)[1],
        ]);
    }

    public function reconciliation(Request $request, int $agentId)
    {
        $this->work->requireAccess();
        $period = $request->input('period', 'today');
        return view('admin.super-admin-work-reconciliation', array_merge([
            'period' => $period,
            'summary' => $this->work->summary($period),
            'metrics' => SuperAdminWorkSummaryService::METRICS,
            'from' => $this->work->period($period)[0],
            'to' => $this->work->period($period)[1],
            'reconciliationAgentId' => $agentId,
        ], ['reconciliation' => $this->work->reconciliation($agentId, $period)]));
    }

    public function detail(Request $request, int $agentId, string $metric)
    {
        $this->work->requireAccess();
        return view('admin.super-admin-work-detail', $this->work->details($agentId, $metric, $request->input('period', 'today')));
    }
}
