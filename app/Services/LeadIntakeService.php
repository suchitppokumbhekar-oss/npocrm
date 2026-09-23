<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

class LeadIntakeService
{
    public function __construct(
        private LeadAssignmentService $assignment,
        private SettingsService $settings,
        private CustomerService $customers,
        private NotificationService $notifications,
    ) {}

    /**
     * Universal intake. Handles website / facebook / google / manual / import.
     */
    public function intake(array $payload, string $channel = 'website', array $rawPayload = []): array
    {
        $result = [
            'success'   => false,
            'lead'      => null,
            'created'   => false,
            'duplicate' => false,
            'revived'   => false,
            'message'   => '',
            'errors'    => [],
        ];

        /* ============================================================
           0. SPECIAL ACTION — customer denied enquiry
           ============================================================ */
        if (($payload['action'] ?? '') === 'mark_dead') {
            $phoneClean = phone_canonical((string) ($payload['phone'] ?? ''), $payload['country_code'] ?? null);
            $phoneVariants = [$phoneClean];
            if (strlen($phoneClean) === 12 && str_starts_with($phoneClean, '91')) {
                $local = substr($phoneClean, 2);
                $phoneVariants[] = $local;
                $phoneVariants[] = '0' . $local;
            }

            $lead = Lead::whereRaw(
                'REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, " ", ""), "-", ""), "+", ""), "(", ""), ")", "") IN (' . implode(',', array_fill(0, count(array_unique($phoneVariants)), '?')) . ')',
                array_values(array_unique($phoneVariants))
            )->whereNotIn('status', ['booking', 'lost'])
              ->orderByDesc('id')
              ->first();

            if ($lead) {
                $lead->update([
                    'status'           => 'lost',
                    'previous_status'  => $lead->status,
                    'lost_reason'      => 'Customer denied enquiry via WhatsApp',
                    'last_activity_at' => now(),
                ]);

                Followup::where('lead_id', $lead->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);

                Activity::create([
                'action_source' => 'automated',
                    'lead_id'   => $lead->id,
                    'agent_id'  => $lead->agent_id ?: 1,
                    'type'      => 'note',
                    'outcome'   => '🚫 Customer denied enquiry',
                    'notes'     => "Customer replied on WhatsApp that they did not make this enquiry.\n"
                                 . 'Lead marked Lost. All pending follow-ups cancelled.',
                    'logged_at' => now(),
                ]);

                return [
                    'success'   => true,
                    'lead'      => $lead->fresh(),
                    'created'   => false,
                    'duplicate' => false,
                    'revived'   => false,
                    'message'   => 'Lead marked lost — customer denied enquiry.',
                    'errors'    => [],
                ];
            }

            return [
                'success'   => true,
                'lead'      => null,
                'created'   => false,
                'duplicate' => false,
                'revived'   => false,
                'message'   => 'No matching lead to mark as lost.',
                'errors'    => [],
            ];
        }

        /* ============================================================
           1. VALIDATE CORE FIELDS
           ============================================================ */
        $name  = trim((string) ($payload['name']  ?? ''));
        $rawPhone = trim((string) ($payload['phone'] ?? ''));
        $cc = array_key_exists('country_code', $payload) ? trim((string) $payload['country_code']) : null;
        $phone = phone_canonical($rawPhone, $cc ?: null);

        if ($name === '') {
            $result['errors']['name'] = 'Name is required.';
        }


        if (strlen($phone) < 8) {
            $result['errors']['phone'] = 'A valid phone number is required.';
        }

        if (! empty($result['errors'])) {
            $result['message'] = 'Validation failed.';
            return $result;
        }

        /* ============================================================
           2. RESOLVE OR CREATE PROJECT
           ============================================================ */
        $project   = null;
        $projectId = null;

        if (! empty($payload['project_id'])) {
            $project = Project::find($payload['project_id']);
        }

        if (! $project && ! empty($payload['project'])) {
            $project = Project::findByNameCI($payload['project']);
        }

        if (! $project && ! empty($payload['project'])) {
            $autoCreate = $this->settings->get($channel . '_intake_auto_create_project', '1') === '1'
                       || $this->settings->get('website_intake_auto_create_project', '1') === '1';

            if ($autoCreate) {
                $project = Project::create([
                    'name'     => trim($payload['project']),
                    'location' => trim($payload['location'] ?? 'Auto-created from ' . $channel),
                    'status'   => 'active',
                ]);
                Log::info("Auto-created project '{$project->name}' (id {$project->id}) from {$channel} lead");
            }
        }

        if (! $project && empty($payload['project']) && empty($payload['project_id'])) {
            $project = Project::active()->orderBy('id')->first();
        }

        if (! $project) {
            $result['errors']['project'] = 'Project is required and could not be created.';
            $result['message'] = 'Project missing.';
            return $result;
        }

        $projectId = $project->id;

        /* ============================================================
           3. CUSTOMER RESOLUTION
           ============================================================ */
        $customer = $this->customers->findOrCreateByPhone($phone, [
            'name'  => $name,
            'email' => $payload['email'] ?? null,
        ]);

        if (! $customer) {
            $result['errors']['phone'] = 'Could not resolve a customer for this phone.';
            $result['message'] = 'Customer resolution failed.';
            return $result;
        }

        /* ============================================================
           4. RE-ENQUIRY DETECTION — same customer, same project
           ============================================================ */
        // Canonical duplicate protection: same customer + same project is one
        // enquiry. The guard also catches legacy leads whose customer_id is empty.
        $existingSameProject = app(LeadDuplicateGuard::class)->findExisting(
            (int) $projectId,
            $customer->id,
            $phone,
            $payload['email'] ?? null,
        );

        if ($existingSameProject) {

            /* ---------- Rule A: active lead → log activity, return ---------- */
            if (! $existingSameProject->isFinal()) {
                if ($channel === 'whatsapp_waba') {
                    $enquiryText = trim((string) ($payload['enquiry'] ?? ''));
                    $comment     = trim((string) ($payload['comment'] ?? ''));
                    $projName    = $existingSameProject->project?->name ?? '—';

                    $noteLines = [];
                    $noteLines[] = 'WhatsApp interaction on existing lead #' . $existingSameProject->id;
                    if ($enquiryText) $noteLines[] = 'Action: ' . $enquiryText;
                    if ($comment)     $noteLines[] = 'Detail: ' . $comment;
                    $noteLines[] = 'Project: ' . $projName;

                    Activity::create([
                    'action_source' => 'automated',
                        'lead_id'   => $existingSameProject->id,
                        'agent_id'  => $existingSameProject->agent_id ?: 1,
                        'type'      => 'note',
                        'outcome'   => '💬 WhatsApp · ' . ($enquiryText ?: 'Customer interacted'),
                        'notes'     => implode("\n", $noteLines),
                        'logged_at' => now(),
                    ]);
                } else {
                    Activity::create([
                    'action_source' => 'automated',
                        'lead_id'   => $existingSameProject->id,
                        'agent_id'  => $existingSameProject->agent_id ?: 1,
                        'type'      => 'note',
                        'outcome'   => ucfirst($channel) . ' re-enquiry',
                        'notes'     => "Duplicate enquiry from {$channel}.\n"
                                     . "Enquiry: " . trim((string) ($payload['enquiry'] ?? $payload['comment'] ?? '—')),
                        'logged_at' => now(),
                    ]);
                }

                $existingSameProject->update(['last_activity_at' => now()]);
                $this->customers->refreshCounters($customer);

                $result['success']   = true;
                $result['duplicate'] = true;
                $result['lead']      = $existingSameProject;
                $result['message']   = 'Duplicate enquiry — logged against existing lead.';
                return $result;
            }

            /* ---------- Rule B: final lead → revive ---------- */
            $revivable = $existingSameProject;

            $revivable->update([
                'status'           => 'new',
                'previous_status'  => $revivable->status,
                'lost_reason'      => null,
                'last_activity_at' => now(),
                'agent_id'         => null,
                'assigned_at'      => null,
            ]);

            $agent = $this->assignment->assign($revivable->fresh());

            Activity::create([
            'action_source' => 'automated',
                'lead_id'     => $revivable->id,
                'agent_id'    => $revivable->resolveLoggingAgentId(),
                'type'        => 'note',
                'outcome'     => '🔄 Lead revived via new enquiry',
                'outcome_key' => 'revived',
                'notes'       => "New {$channel} enquiry arrived for the same project.\n"
                               . "Enquiry: " . trim((string) ($payload['enquiry'] ?? $payload['comment'] ?? '—')),
                'logged_at'   => now(),
            ]);

            if ($revivable->agent_id) {
                $delayMinutes = max(1, (int) $this->settings->get('first_contact_delay_minutes', 15));

                Followup::create([
                    'lead_id'        => $revivable->id,
                    'agent_id'       => $revivable->agent_id,
                    'scheduled_for'  => app(\App\Services\BusinessHoursService::class)->clamp(now()->addMinutes($delayMinutes)),
                    'action_type'    => 'followup_call',
                    'priority'       => 'high',
                    'status'         => 'pending',
                    'escalated_flag' => false,
                    'auto_created'   => true,
                ]);
            }

            try {
                $this->notifications->notifyLeadRevived($revivable->fresh());
            } catch (\Throwable $e) {}

            $this->customers->refreshCounters($customer);

            $result['success']   = true;
            $result['revived']   = true;
            $result['lead']      = $revivable->fresh(['agent.user', 'project', 'customer']);
            $result['message']   = 'Lead revived via new enquiry.';
            return $result;
        }

        /* ============================================================
           5. NO PRIOR LEAD FOR THIS PROJECT → CREATE ONE
           ============================================================ */
        $classifiedChannel = $this->classifyChannel($payload, $channel);

        $lead = Lead::create([
            'customer_id'   => $customer->id,
            'customer_name' => mb_substr($name, 0, 255),
            'phone'         => mb_substr($phone, 0, 20),
            'email'         => mb_substr(trim((string) ($payload['email'] ?? '')), 0, 255) ?: null,
            'source'        => mb_substr(trim((string) ($payload['source'] ?? $channel)), 0, 50),
            'intake_source' => $channel,
            'intake_ref'    => mb_substr(trim((string) ($payload['form_id'] ?? $payload['campaign_id'] ?? '')), 0, 255) ?: null,
            'budget'        => isset($payload['budget']) && is_numeric($payload['budget']) ? (float) $payload['budget'] : null,
            'project_id'    => $projectId,
            'status'        => 'new',
            'last_activity_at' => now(),
            'utm_source'    => mb_substr(trim((string) ($payload['utm_source']   ?? '')), 0, 100) ?: null,
            'utm_medium'    => mb_substr(trim((string) ($payload['utm_medium']   ?? '')), 0, 100) ?: null,
            'utm_campaign'  => mb_substr(trim((string) ($payload['utm_campaign'] ?? '')), 0, 200) ?: null,
            'utm_content'   => mb_substr(trim((string) ($payload['utm_content']  ?? '')), 0, 200) ?: null,
            'utm_term'      => mb_substr(trim((string) ($payload['utm_term']     ?? '')), 0, 200) ?: null,
            'click_id'      => mb_substr(trim((string) ($payload['click_id']     ?? '')), 0, 255) ?: null,
            'referrer_url'  => mb_substr(trim((string) ($payload['referrer_url'] ?? '')), 0, 500) ?: null,
            'raw_payload'   => ! empty($rawPayload) ? json_encode($rawPayload) : null,
        ]);

        $agent = $this->assignment->assign($lead);

        if ($lead->agent_id) {
            $delayMinutes = max(1, (int) $this->settings->get('first_contact_delay_minutes', 15));

            Followup::create([
                'lead_id'        => $lead->id,
                'agent_id'       => $lead->agent_id,
                'scheduled_for'  => app(\App\Services\BusinessHoursService::class)->clamp(now()->addMinutes($delayMinutes)),
                'action_type'    => 'followup_call',
                'priority'       => 'high',
                'status'         => 'pending',
                'escalated_flag' => false,
                'auto_created'   => true,
            ]);
        }

        try {
            $otherActiveLeads = Lead::where('customer_id', $customer->id)
                ->where('id', '!=', $lead->id)
                ->whereNotIn('status', ['booking', 'lost'])
                ->with(['agent.user', 'project'])
                ->get();

            if ($otherActiveLeads->isNotEmpty()) {
                $this->notifications->notifyCrossProjectEnquiry(
                    $lead->fresh(['agent.user', 'project']),
                    $customer,
                    $otherActiveLeads
                );
            }
        } catch (\Throwable $e) {}

        $enquiryLines = [];
        $enquiryLines[] = "🌐 New lead from: {$channel}";
        if (! empty($payload['enquiry']))     $enquiryLines[] = "Enquiry: "  . trim($payload['enquiry']);
        if (! empty($payload['comment']))     $enquiryLines[] = "Comment: "  . trim($payload['comment']);
        if (! empty($payload['project']))     $enquiryLines[] = "Project: "  . trim($payload['project']);
        if (! empty($payload['campaign_id'])) $enquiryLines[] = "Campaign: " . trim($payload['campaign_id']);
        if (! empty($payload['form_id']))     $enquiryLines[] = "Form: "     . trim($payload['form_id']);

        Activity::create([
        'action_source' => 'automated',
            'lead_id'     => $lead->id,
            'agent_id'    => $lead->resolveLoggingAgentId(),
            'type'        => 'note',
            'outcome'     => ucfirst($channel) . ' lead captured',
            'notes'       => implode("\n", $enquiryLines),
            'logged_at'   => now(),
        ]);

        $this->customers->refreshCounters($customer);

        $result['success'] = true;
        $result['lead']    = $lead->fresh(['agent.user', 'project', 'customer']);
        $result['created'] = true;
        $result['message'] = $agent
            ? 'Lead created and assigned to ' . ($agent->user?->name ?? 'agent')
            : 'Lead created (awaiting assignment).';

        return $result;
    }

    /**
     * Normalize any incoming payload into our standard shape.
     */
    public function normalize(array $input): array
    {
        // Accept both 'phone' and 'mobile'
        $phone = $input['phone'] ?? $input['mobile'] ?? '';
        $cc    = $input['country_code'] ?? '';
        if ($cc === '' && ! empty($input['fullMobileNo'])) {
            $full   = preg_replace('/\D/', '', $input['fullMobileNo']);
            $local  = preg_replace('/\D/', '', $phone);
            if ($full && $local && str_ends_with($full, $local)) {
                $cc = '+' . substr($full, 0, strlen($full) - strlen($local));
            }
        }

        return [
            'action'          => $input['action'] ?? null,
            'name'            => $input['name'] ?? $input['full_name'] ?? $input['first_name'] ?? '',
            'phone'           => $phone,
            'country_code'    => $cc ?: '+91',
            'email'           => $input['email'] ?? null,
            'project'         => $input['project'] ?? null,
            'project_id'      => $input['project_id'] ?? null,
            'location'        => $input['location'] ?? null,
            'source'          => $input['source'] ?? null,
            'budget'          => $input['budget'] ?? null,
            'enquiry'         => $input['enquiry'] ?? null,
            'comment'         => $input['comment'] ?? $input['message'] ?? null,
            'utm_source'      => $input['utm_source']   ?? null,
            'utm_medium'      => $input['utm_medium']   ?? null,
            'utm_campaign'    => $input['utm_campaign'] ?? null,
            'utm_content'     => $input['utm_content']  ?? null,
            'utm_term'        => $input['utm_term']     ?? null,
            'click_id'        => $input['click_id']     ?? $input['gclid'] ?? $input['fbclid'] ?? null,
            'referrer_url'    => $input['referrer_url'] ?? null,
            'form_id'         => $input['form_id']      ?? null,
            'campaign_id'     => $input['campaign_id']  ?? null,
            'ad_id'           => $input['ad_id']        ?? null,
            'page_id'         => $input['page_id']      ?? null,
            'leadgen_id'      => $input['leadgen_id']   ?? null,
        ];
    }

    /**
     * Classify the lead's channel based on click IDs and UTM parameters.
     */
    public function classifyChannel(array $payload, string $default = 'website'): string
    {
        $utmSource = strtolower(trim((string) ($payload['utm_source'] ?? '')));
        $gclid     = trim((string) ($payload['gclid']     ?? ''));
        $fbclid    = trim((string) ($payload['fbclid']    ?? ''));
        $msclkid   = trim((string) ($payload['msclkid']   ?? ''));

        $clickId = trim((string) ($payload['click_id'] ?? ''));
        if (! $gclid  && str_starts_with($clickId, 'gclid'))  $gclid  = $clickId;
        if (! $fbclid && str_starts_with($clickId, 'fbclid')) $fbclid = $clickId;

        if ($default === 'facebook') {
            return 'facebook_ads';
        }

        if ($gclid)   return 'google_ads';
        if ($fbclid)  return 'facebook_ads';
        if ($msclkid) return 'bing_ads';

        if ($utmSource === 'google')                            return 'google_organic';
        if (in_array($utmSource, ['facebook', 'fb'], true))     return 'facebook_organic';
        if (in_array($utmSource, ['instagram', 'ig'], true))    return 'instagram_organic';
        if ($utmSource === 'bing')                              return 'bing_organic';
        if ($utmSource === 'linkedin')                          return 'linkedin_organic';
        if (in_array($utmSource, ['whatsapp', 'wa'], true))     return 'whatsapp';
        if ($utmSource === 'referral')                          return 'referral';

        if ($utmSource !== '') return $utmSource;

        if ($default === 'website') {
            $referrer = strtolower((string) ($payload['referrer_url'] ?? ''));

            if (str_contains($referrer, 'google.'))   return 'google_organic';
            if (str_contains($referrer, 'bing.'))     return 'bing_organic';
            if (str_contains($referrer, 'facebook.')) return 'facebook_organic';

            return 'direct';
        }

        return $default;
    }
}