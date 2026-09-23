<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadActivityProcessor;
use App\Services\AccessService;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function __construct(
        private LeadActivityProcessor $processor,
        private AccessService $access,
    ) {}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'lead_id'               => 'required|integer|exists:leads,id',
            'type'                  => 'required|string|max:50',
            'outcome_key'           => 'nullable|string',
            'notes'                 => 'nullable|string|max:2000',
            'also_whatsapp'         => 'nullable|boolean',
            'whatsapp_sent_kind'    => 'nullable|in:intro,details',
            'custom_next_at'        => 'nullable|date|after:now',
            'visit_scheduled_at'    => 'nullable|date',
            'lost_reason'           => 'nullable|string|max:2000',
            'lost_reason_key'       => 'nullable|string|max:80',
            'property_area_sqft'    => 'nullable|numeric|min:0',
            'rate_per_sqft'         => 'nullable|numeric|min:0',
            'booking_amount'        => 'nullable|numeric|min:0',
            'booking_unit'          => 'nullable|string|max:100',
            'booking_payment_mode'  => 'nullable|string|max:50',
            'booking_date'          => 'nullable|date',
            'brokerage_percentage'  => 'nullable|numeric|min:0|max:100',
            'brokerage_amount'      => 'nullable|numeric|min:0',
            'brokerage_expected_at' => 'nullable|date',
            'co_broker_name'        => 'nullable|string|max:100',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);

        if (! $this->access->canWorkLead($lead)) {
            abort(403, 'You are not allowed to work on this lead.');
        }

        // A cross-project shared agent must first move the lead to a project
        // they actually handle. This prevents work continuing under the old
        // project and records the handoff cleanly through the project-change flow.
        if (session('user_role') === 'agent') {
            $agentId = (int) \App\Models\Agent::where('user_id', session('user_id'))->value('id');
            $isSharedAgent = $agentId > 0 && $lead->assignments()
                ->where('agent_id', $agentId)
                ->where('is_primary', false)
                ->where('is_active', true)
                ->exists();

            if ($isSharedAgent && ! $lead->agentHandlesProject($agentId)) {
                return back()->withErrors([
                    'error' => 'This lead is associated with another project. The project can only be changed through the proper handover workflow or by an admin.',
                ])->withInput();
            }
        }

        if ($lead->isLost()) {
            return back()->withErrors([
                'lead_id' => 'This lead is Lost. Revive it first, then log activities.',
            ])->withInput();
        }

        try {
            app(\App\Services\WorkflowPresentationService::class)
                ->assertOutcomeAllowedForUser($validated['outcome_key'] ?? null);

            app(\App\Services\WorkflowPresentationService::class)
                ->assertActivityTypeAllowedForUser($validated['type'] ?? null);

            app(\App\Services\WorkflowPresentationService::class)
                ->assertLostReasonAllowedForUser($validated['lost_reason_key'] ?? null);

            $result = $this->processor->process($lead, null, $validated);
        } catch (\DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        return redirect('/')->with(
            'success',
            $this->processor->buildFlashMessage($result, '📝 Activity logged!')
        );
    }
    public function storeCustomerInformation(Request $request)
    {
        $validated = $request->validate([
            'lead_id' => 'required|integer|exists:leads,id',
            'budget' => 'nullable|string|max:150',
            'location' => 'nullable|string|max:120',
            'possession' => 'nullable|in:Ready / immediate,Within 6 months,Within 1 year,1–2 years,2–3 years,3+ years,Flexible',
            'timeline' => 'nullable|in:Immediate,Within 30 days,1–3 months,3–6 months,6–12 months,12+ months,Exploring',
            'decision_criteria' => 'nullable|array',
            'decision_criteria.*' => 'string|in:Price,Location,Possession,Configuration,Amenities,Developer,Connectivity,Payment plan,Investment return,Family preference',
            'objections' => 'nullable|array',
            'objections.*' => 'string|in:Price,Location,Possession,Configuration,Finance,Family approval,Developer concern,Legal / RERA,Amenities,Other concern',
        ]);

        $lead = Lead::findOrFail((int) $validated['lead_id']);

        if (! $this->access->canWorkLead($lead)) {
            abort(403, 'You are not allowed to work on this lead.');
        }

        if ($lead->isLost()) {
            return back()->withErrors(['error' => 'This lead is Lost. Revive it first, then record customer information.'])->withInput();
        }

        $location = trim((string) ($validated['location'] ?? ''));

        $values = [
            'Customer-stated budget range' => trim((string) ($validated['budget'] ?? '')),
            'Customer preferred area' => $location,
            'Possession requirement' => trim((string) ($validated['possession'] ?? '')),
            'Purchase / decision timeline' => trim((string) ($validated['timeline'] ?? '')),
            'Decision criteria' => implode(', ', array_values(array_unique($validated['decision_criteria'] ?? []))),
            'Concerns / objections' => implode(', ', array_values(array_unique($validated['objections'] ?? []))),
        ];

        $lines = [];
        foreach ($values as $label => $value) {
            if ($value !== '') $lines[] = $label . ': ' . $value;
        }

        if (empty($lines)) {
            return back()->withErrors(['error' => 'Select or enter at least one customer detail. Nothing is required if it was not discussed.'])->withInput();
        }

        $notes = "Customer information check-in — controlled agent entry\n";
        $notes .= implode("\n", $lines);
        $notes .= "\n\nEVIDENCE · CUSTOMER CONVERSATION\nThis records information the agent says was discussed with the customer. It does not change project facts or operational lead budget.";

        try {
            $this->processor->process($lead, null, [
                'type' => 'note',
                'action_source' => 'customer_conversation',
                'notes' => $notes,
            ]);
        } catch (\DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        return redirect()->to('/leads/' . $lead->id . '?lead_tab=customer')
            ->with('success', '✅ Customer information recorded as timeline evidence.');
    }

}