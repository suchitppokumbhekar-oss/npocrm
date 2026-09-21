<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Project;
use App\Services\LeadAssignmentService;
use App\Services\NotificationService;
use App\Services\SettingsService;
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
    ): void {
                // Prefer the permanent System User token (bypasses App Review).
        // Fall back to the OAuth-issued Page token if it's not set.
        $pageToken = $settings->get('meta_system_user_token', '');
        if (! $pageToken) {
            $pageToken = $settings->get('meta_page_access_token', '');
        }
        if (! $pageToken) {
            $pageToken = config('services.meta.page_access_token');
        }

        if (! $pageToken) {
            Log::error('FetchMetaLead: no access token available');
            return;
        }

                $res = Http::get("https://graph.facebook.com/v20.0/{$this->leadgenId}", [
            'access_token' => $pageToken,
            'fields'       => 'id,created_time,form_id,field_data',
        ]);

        if (! $res->successful()) {
            Log::error("Meta lead fetch failed for {$this->leadgenId}", ['body' => $res->body()]);
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
                'fields' => $fields->all(),
            ]);
            return;
        }

        // Duplicate check by phone
        $phoneClean = preg_replace('/\D/', '', (string) $phone);

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

        // Default project (first active)
                // Resolve project: form → project mapping first, then fallback
        $formId    = $data['form_id'] ?? null;
        $projectId = null;

        if ($formId) {
            $fbSvc = app(\App\Services\FacebookLeadService::class);
            $map   = $fbSvc->getFormProjectMap();

            if (! empty($map[$formId])) {
                $projectId = (int) $map[$formId];
            } else {
                Log::warning('FetchMetaLead: no project mapping for form', [
                    'form_id' => $formId,
                    'leadgen' => $this->leadgenId,
                ]);
            }
        }

        if (! $projectId) {
            $projectId = Project::active()->orderBy('id')->value('id');
        }

        if (! $projectId) {
            Log::error('FetchMetaLead: no active project found');
            return;
        }

        // Create the lead
        $lead = Lead::create([
            'customer_name' => mb_substr(trim($fullName), 0, 255),
            'phone'         => $phoneClean,
            'email'         => $email,
            'source'        => 'facebook',
            'project_id'    => $projectId,
            'status'        => 'new',
        ]);

        // Auto-assign + schedule first contact
        $assignment->assign($lead);

        if ($lead->agent_id) {
            Followup::where('lead_id', $lead->id)
                ->where('status', 'pending')
                ->where('auto_created', true)
                ->update(['status' => 'cancelled']);

            Followup::create([
                'lead_id'        => $lead->id,
                'agent_id'       => $lead->agent_id,
                'scheduled_for'  => now()->addMinutes(15),
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

        Log::info("Meta lead created: {$lead->id} from leadgen {$this->leadgenId}");
    }
}