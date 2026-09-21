<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Project;
use App\Services\FacebookLeadService;
use App\Services\LeadAssignmentService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\LeadDuplicateGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchMetaLead
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $leadgenId,
        public ?string $pageId = null
    ) {}

        public function handle(
        LeadAssignmentService $assignment,
        SettingsService $settings,
        NotificationService $notifications,
        \App\Services\CustomerService $customers,
        LeadDuplicateGuard $duplicateGuard,
    ): void {
        // Resolve page-specific token from the multi-page map
        $pageToken = null;

        if ($this->pageId) {
            $fbSvc     = app(FacebookLeadService::class);
            $pageToken = $fbSvc->getPageToken($this->pageId);

            if (! $pageToken) {
                Log::warning('FetchMetaLead: no token registered for page', [
                    'page_id' => $this->pageId,
                    'leadgen' => $this->leadgenId,
                ]);
            }
        }

        // Legacy fallbacks for safety
        if (! $pageToken) {
            $pageToken = $settings->get('meta_system_user_token', '');
        }
        if (! $pageToken) {
            $pageToken = $settings->get('meta_page_access_token', '');
        }
        if (! $pageToken) {
            $pageToken = config('services.meta.page_access_token');
        }

        if (! $pageToken) {
            Log::error('FetchMetaLead: no access token available', [
                'page_id' => $this->pageId,
            ]);
            return;
        }

        $res = Http::get("https://graph.facebook.com/v23.0/{$this->leadgenId}", [
            'access_token' => $pageToken,
            'fields'       => 'id,created_time,form_id,field_data',
        ]);

        if (! $res->successful()) {
            Log::error("Meta lead fetch failed for {$this->leadgenId}", [
                'page_id' => $this->pageId,
                'body'    => $res->body(),
            ]);
            return;
        }

        $data = $res->json();

        $fields = collect($data['field_data'] ?? [])->mapWithKeys(function ($item) {
            return [$item['name'] => $item['values'][0] ?? null];
        });

        $fullName = $fields->get('full_name')
            ?? trim(($fields->get('first_name') ?? '') . ' ' . ($fields->get('last_name') ?? ''));

        $phone = $fields->get('phone_number')
            ?? $fields->get('phone')
            ?? $fields->get('mobile');

        $email = $fields->get('email');

        if (empty($fullName) || empty($phone)) {
            Log::warning("Meta lead {$this->leadgenId} missing name or phone", [
                'page_id' => $this->pageId,
                'fields'  => $fields->all(),
            ]);
            return;
        }

        // Normalize phone: strip non-digits, then strip country code
        $phoneClean = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($phoneClean, '91') && strlen($phoneClean) === 12) {
            $phoneClean = substr($phoneClean, 2);
        }
        if (str_starts_with($phoneClean, '0') && strlen($phoneClean) === 11) {
            $phoneClean = substr($phoneClean, 1);
        }

        // Duplicate check by phone
        $existing = Lead::whereRaw(
            'REPLACE(REPLACE(REPLACE(phone, " ", ""), "-", ""), "+", "") = ?',
            [$phoneClean]
        )->first();

        if ($existing) {
            Activity::create([
            'action_source' => 'automated',
                'lead_id'   => $existing->id,
                'agent_id'  => $existing->agent_id ?: 1,
                'type'      => 'note',
                'outcome'   => 'Meta re-enquiry',
                'notes'     => 'Duplicate Meta Ads enquiry (Leadgen ID: ' . $this->leadgenId . ')',
                'logged_at' => now(),
            ]);
            $existing->update(['last_activity_at' => now()]);
            Log::info("Meta lead {$this->leadgenId} was a duplicate of lead {$existing->id}");
            return;
        }

        // Resolve project from form → project map
        $formId    = $data['form_id'] ?? null;
        $projectId = null;

        if ($formId) {
            $fbSvc = app(FacebookLeadService::class);
            $map   = $fbSvc->getFormProjectMap();

            if (! empty($map[$formId])) {
                $projectId = (int) $map[$formId];
            } else {
                Log::warning('FetchMetaLead: no project mapping for form', [
                    'form_id' => $formId,
                    'page_id' => $this->pageId,
                    'leadgen' => $this->leadgenId,
                ]);
            }
        }

        // Fallback: first active project
        if (! $projectId) {
            $projectId = Project::active()->orderBy('id')->value('id');
        }

        if (! $projectId) {
            Log::error('FetchMetaLead: no active project found');
            return;
        }

        // Create the lead
                // Resolve or create the customer behind this phone
        $customer = $customers->findOrCreateByPhone($phoneClean, [
            'name'  => $fullName,
            'email' => $email,
        ]);

        if (! $customer) {
            Log::warning('FetchMetaLead: could not resolve customer', [
                'leadgen' => $this->leadgenId,
                'phone'   => $phoneClean,
            ]);
            // Continue — lead still created, just unlinked
        }

        // Meta/Facebook intake must obey the same one-customer + one-project rule.
        $existing = $duplicateGuard->findExisting(
            (int) $projectId,
            $customer?->id,
            $phoneClean,
            $email,
        );
        if ($existing) {
            Activity::create([
                'action_source' => 'automated',
                'lead_id' => $existing->id,
                'agent_id' => $existing->agent_id ?: 1,
                'type' => 'note',
                'outcome' => 'Duplicate Meta enquiry blocked',
                'notes' => 'Meta lead #' . $this->leadgenId . ' matched existing Lead #' . $existing->id . ' for the same customer and project. No duplicate lead was created.',
                'logged_at' => now(),
            ]);
            $existing->update(['last_activity_at' => now()]);
            Log::info('FetchMetaLead: duplicate blocked', [
                'leadgen' => $this->leadgenId,
                'existing_lead_id' => $existing->id,
                'project_id' => $projectId,
            ]);
            return;
        }

        // Create the lead
        $lead = Lead::create([
            'customer_id'   => $customer?->id,
            'customer_name' => mb_substr(trim($fullName), 0, 255),
            'phone'         => $phoneClean,
            'email'         => $email,
            'source'        => 'facebook',
            'intake_source' => 'facebook',
            'project_id'    => $projectId,
            'status'        => 'new',
            'last_activity_at' => now(),
        ]);

        // Refresh the customer's counters now that a new lead is linked
        if ($customer) {
            try {
                $customers->refreshCounters($customer);
            } catch (\Throwable $e) {
                Log::warning('FetchMetaLead: refreshCounters failed', [
                    'customer_id' => $customer->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // Project-aware assignment
        $assignment->assign($lead);

        // Schedule first contact
        if ($lead->agent_id) {
            $delayMinutes = max(1, (int) $settings->get('first_contact_delay_minutes', 15));

            Followup::create([
                'lead_id'        => $lead->id,
                'agent_id'       => $lead->agent_id,
                'scheduled_for'  => now()->addMinutes($delayMinutes),
                'action_type'    => 'followup_call',
                'priority'       => 'high',
                'status'         => 'pending',
                'escalated_flag' => false,
                'auto_created'   => true,
            ]);
        }

        // Log form data as a note
        $noteLines = ['📱 Meta Ads lead captured'];
        foreach ($fields as $key => $value) {
            if (in_array($key, ['full_name', 'first_name', 'last_name', 'phone_number', 'phone', 'mobile', 'email'], true)) continue;
            $noteLines[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
        }
        $noteLines[] = 'Leadgen ID: ' . $this->leadgenId;
        if ($this->pageId) $noteLines[] = 'Page ID: '   . $this->pageId;
        if ($formId)       $noteLines[] = 'Form ID: '   . $formId;

        Activity::create([
        'action_source' => 'automated',
            'lead_id'   => $lead->id,
            'agent_id'  => $lead->resolveLoggingAgentId(),
            'type'      => 'note',
            'outcome'   => 'Meta Ads lead',
            'notes'     => implode("\n", $noteLines),
            'logged_at' => now(),
        ]);

        try {
            $notifications->notifyLeadAssigned($lead->fresh(['agent.user']));
        } catch (\Throwable $e) {}

        Log::info("Meta lead created: {$lead->id} from leadgen {$this->leadgenId} (page {$this->pageId})");
    }
}