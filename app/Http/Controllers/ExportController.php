<?php

namespace App\Http\Controllers;

use App\Services\CsvExportService;
use App\Services\DelegatedAccessService;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function __construct(
        private CsvExportService $csv,
        private DelegatedAccessService $delegatedAccess,
    ) {}

    /**
     * GET /export/leads?status=new&agent_id=1&from=2026-01-01&to=2026-12-31
     */
    public function leads(Request $request)
    {
        if (session('user_role') !== 'admin') {
            abort(403, 'Only admins can export.');
        }

        $userId = (int) session('user_id');

        $filters = $request->only([
            'status',
            'agent_id',
            'from',
            'to',
        ]);

        /*
         * ============================================================
         * DELEGATED ADMIN SCOPE
         * ============================================================
         *
         * A delegated Admin must never be able to export leads
         * outside the agents assigned to their delegated profile.
         *
         * The service will apply this against ACTIVE lead_agents
         * records so shared leads are included correctly.
         */
        if ($this->delegatedAccess->hasProfile($userId)) {

            $allowedAgentIds =
                $this->delegatedAccess->visibleAgentIds(
                    $userId
                );

            /*
             * Empty delegated scope must mean NO accessible leads,
             * never unrestricted access.
             */
            if ($allowedAgentIds === []) {
                $allowedAgentIds = [-1];
            }

            $filters['access_agent_ids'] = $allowedAgentIds;
        }

        return $this->csv->exportLeads($filters);
    }
}