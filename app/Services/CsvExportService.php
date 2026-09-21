<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadAgent;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExportService
{
    public function __construct(
        private SettingsService $settings
    ) {}

    /**
     * Stream a filtered CSV of leads to the browser.
     *
     * Supported filters:
     *
     * status
     * agent_id
     * from
     * to
     *
     * access_agent_ids
     *     Internal security scope supplied by a delegated-access
     *     controller. When present, ONLY leads having an ACTIVE
     *     lead_agents row for one of these agents are exported.
     */
    public function exportLeads(
        array $filters = []
    ): StreamedResponse {

        $filename =
            'leads_' .
            now()->format('Y-m-d_His') .
            '.csv';

        $query = Lead::with([
                'agent.user',
                'project',
            ])
            ->orderByDesc('id');

        /*
         * ============================================================
         * DELEGATED ACCESS SCOPE
         * ============================================================
         *
         * This is deliberately based on lead_agents rather than
         * leads.agent_id.
         *
         * That means an active shared lead is visible to the
         * delegated agent even when that agent is not the primary
         * agent on the lead.
         */
        if (
            array_key_exists(
                'access_agent_ids',
                $filters
            )
        ) {

            $accessAgentIds = collect(
                $filters['access_agent_ids'] ?? []
            )
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            /*
             * Empty scope is deliberately impossible to satisfy.
             */
            if ($accessAgentIds === []) {
                $accessAgentIds = [-1];
            }

            $query->whereExists(
                function ($subQuery) use ($accessAgentIds) {

                    $subQuery
                        ->selectRaw('1')
                        ->from('lead_agents')
                        ->whereColumn(
                            'lead_agents.lead_id',
                            'leads.id'
                        )
                        ->whereIn(
                            'lead_agents.agent_id',
                            $accessAgentIds
                        )
                        ->where(
                            'lead_agents.is_active',
                            true
                        );
                }
            );
        }

        /*
         * ============================================================
         * NORMAL FILTERS
         * ============================================================
         */

        if (! empty($filters['status'])) {

            $query->where(
                'status',
                $filters['status']
            );
        }

        /*
         * Agent filter.
         *
         * For delegated access this is an additional narrowing
         * filter. It can never expand the delegated scope because
         * the access_agent_ids condition above remains active.
         */
        if (! empty($filters['agent_id'])) {

            $agentId = (int) $filters['agent_id'];

            if (
                array_key_exists(
                    'access_agent_ids',
                    $filters
                )
            ) {

                $allowedAgentIds = collect(
                    $filters['access_agent_ids'] ?? []
                )
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                /*
                 * A manually supplied agent_id that isn't delegated
                 * must return no records.
                 */
                if (
                    ! in_array(
                        $agentId,
                        $allowedAgentIds,
                        true
                    )
                ) {
                    $query->whereRaw('1 = 0');
                } else {

                    /*
                     * Use the active lead_agents relationship here,
                     * so the selected delegated agent sees shared
                     * leads as well.
                     */
                    $query->whereExists(
                        function ($subQuery) use ($agentId) {

                            $subQuery
                                ->selectRaw('1')
                                ->from('lead_agents')
                                ->whereColumn(
                                    'lead_agents.lead_id',
                                    'leads.id'
                                )
                                ->where(
                                    'lead_agents.agent_id',
                                    $agentId
                                )
                                ->where(
                                    'lead_agents.is_active',
                                    true
                                );
                        }
                    );
                }

            } else {

                /*
                 * Preserve the original behaviour for normal Admins.
                 */
                $query->where(
                    'agent_id',
                    $agentId
                );
            }
        }

        if (! empty($filters['from'])) {

            $query->whereDate(
                'created_at',
                '>=',
                $filters['from']
            );
        }

        if (! empty($filters['to'])) {

            $query->whereDate(
                'created_at',
                '<=',
                $filters['to']
            );
        }

        $leads = $query->get();

        /*
         * ============================================================
         * LABELS
         * ============================================================
         */

        $statusLabels = $this->settings
            ->statuses(false)
            ->pluck('label', 'key')
            ->toArray();

        $sourceLabels = $this->settings
            ->sources(false)
            ->pluck('label', 'key')
            ->toArray();

        /*
         * ============================================================
         * CSV HEADERS
         * ============================================================
         */

        $headers = [
            'ID',
            'Customer Name',
            'Phone',
            'Email',
            'Source',
            'Budget (₹)',
            'Project',
            'Status',
            'Agent',
            'Created',
            'Assigned',
            'Last Activity',
            'Visit Scheduled',

            // Booking
            'Area (sq ft)',
            'Rate (₹ / sq ft)',
            'Property Value (₹)',
            'Unit',
            'Payment Mode',
            'Booking Date',

            // Brokerage
            'Brokerage %',
            'Brokerage Amount (₹)',
            'Brokerage Status',
            'Brokerage Expected',
            'Brokerage Received',
            'Co-broker',

            // Other
            'Lost Reason',
        ];

        /*
         * ============================================================
         * STREAM CSV
         * ============================================================
         */

        return response()->streamDownload(
            function () use (
                $leads,
                $headers,
                $statusLabels,
                $sourceLabels
            ) {

                $out = fopen(
                    'php://output',
                    'w'
                );

                /*
                 * UTF-8 BOM so Excel handles ₹ and emojis cleanly.
                 */
                fwrite(
                    $out,
                    "\xEF\xBB\xBF"
                );

                fputcsv(
                    $out,
                    $headers
                );

                foreach ($leads as $lead) {

                    fputcsv(
                        $out,
                        [
                            $lead->id,

                            $lead->customer_name,

                            $lead->phone,

                            $lead->email,

                            $sourceLabels[
                                $lead->source
                            ] ?? $lead->source,

                            $lead->budget,

                            $lead->project?->name,

                            $statusLabels[
                                $lead->statusKey()
                            ] ?? $lead->statusKey(),

                            $lead->agent?->user?->name
                                ?? 'Unassigned',

                            $lead->created_at?->format(
                                'Y-m-d H:i'
                            ),

                            $lead->assigned_at?->format(
                                'Y-m-d H:i'
                            ),

                            $lead->last_activity_at?->format(
                                'Y-m-d H:i'
                            ),

                            $lead->visit_scheduled_at?->format(
                                'Y-m-d H:i'
                            ),

                            // Booking
                            $lead->property_area_sqft,

                            $lead->rate_per_sqft,

                            $lead->booking_amount,

                            $lead->booking_unit,

                            $lead->booking_payment_mode,

                            $lead->booking_date?->format(
                                'Y-m-d'
                            ),

                            // Brokerage
                            $lead->brokerage_percentage,

                            $lead->brokerage_amount,

                            $lead->brokerage_status,

                            $lead->brokerage_expected_at?->format(
                                'Y-m-d'
                            ),

                            $lead->brokerage_received_at?->format(
                                'Y-m-d'
                            ),

                            $lead->co_broker_name,

                            // Other
                            $lead->lost_reason,
                        ]
                    );
                }

                fclose($out);
            },
            $filename,
            [
                'Content-Type' =>
                    'text/csv; charset=UTF-8',

                'Content-Disposition' =>
                    'attachment; filename="' .
                    $filename .
                    '"',
            ]
        );
    }
}