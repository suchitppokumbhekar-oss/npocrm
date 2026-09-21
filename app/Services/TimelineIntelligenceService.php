<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Followup;
use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Timeline Intelligence — first, deterministic intelligence layer.
 *
 * This service deliberately does not invent customer facts and does not call
 * an external AI provider. It reads the existing CRM timeline, follow-ups and
 * site-visit records and turns documented events into an explainable snapshot,
 * signals and next-action guidance.
 *
 * Every customer-facing fact returned by this service carries evidence activity
 * ids where possible, so the UI can always point back to the source timeline.
 */
class TimelineIntelligenceService
{
    public function analyze(Lead $lead): array
    {
        $activities = Activity::query()
            ->where('lead_id', $lead->id)
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->get();

        $followups = Followup::query()
            ->where('lead_id', $lead->id)
            ->orderBy('scheduled_for')
            ->get();

        $siteVisits = $this->siteVisits($lead->id);
        $facts = $this->extractFacts($lead, $activities);
        $signals = $this->signals($lead, $activities, $followups, $siteVisits);
        $journey = $this->journey($lead, $activities, $followups, $siteVisits, $facts);
        $decisionDependencies = $this->decisionDependencies($lead, $activities, $siteVisits, $facts, $journey);
        $nextAction = $this->nextAction($lead, $activities, $followups, $siteVisits, $signals, $journey, $decisionDependencies, $facts);
        $objective = $this->conversationObjective($lead, $activities, $siteVisits, $facts, $journey, $nextAction);
        $actionObjective = $this->actionObjective($lead, $activities, $siteVisits, $facts, $journey, $decisionDependencies, $nextAction);
        $requirementMaturity = $this->requirementMaturity($lead, $facts, $activities, $siteVisits);
        $informationReadiness = $this->informationReadiness($lead, $facts, $activities, $siteVisits);

        $recent = $activities->take(8)->map(fn (Activity $activity) => [
            'id' => (int) $activity->id,
            'type' => (string) $activity->type,
            'outcome' => $activity->outcome,
            'outcome_key' => $activity->outcome_key,
            'notes' => trim((string) $activity->notes),
            'logged_at' => $activity->logged_at?->toIso8601String(),
            'label' => $activity->displayLabel(100),
        ])->values()->all();

        return [
            'lead' => [
                'id' => (int) $lead->id,
                'status' => (string) $lead->status,
                'status_label' => $lead->status_label,
                'customer_name' => (string) $lead->customer_name,
                'project' => $lead->project?->name,
                'created_at' => $lead->created_at?->toIso8601String(),
                'last_activity_at' => $lead->last_activity_at?->toIso8601String(),
            ],
            'snapshot' => $facts['current'],
            'snapshot_history' => $facts['history'],
            'changes' => $facts['changes'],
            'unknowns' => $this->unknowns($lead, $facts['current'], $activities, $siteVisits),
            'origin' => $this->origin($lead, $activities),
            'signals' => $signals,
            'next_action' => $nextAction,
            'journey' => $journey,
            'conversation_objective' => $objective,
            'action_objective' => $actionObjective,
            'requirement_maturity' => $requirementMaturity,
            'information_readiness' => $informationReadiness,
            'decision_dependencies' => $decisionDependencies,
            'evidence_hierarchy' => $this->evidenceHierarchy(),
            'recent_timeline' => $recent,
            'metrics' => [
                'activities' => $activities->count(),
                'completed_tasks' => $followups->where('status', 'done')->count(),
                'pending_tasks' => $followups->where('status', 'pending')->count(),
                'overdue_tasks' => $followups->filter(fn (Followup $f) => $f->isOverdue())->count(),
                'site_visits' => $siteVisits->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Extract only facts that have a clear textual signal in the existing data.
     */
    private function extractFacts(Lead $lead, Collection $activities): array
    {
        $current = [];
        $history = [];
        $patterns = [
            'configuration' => [
                '/\b([1-5])\s*(?:bhk|bedroom)s?\b/i',
                '/(?:configuration|config|property|unit)\s*(?:is|:|=)?\s*([1-5])[_ -]?bhk\b/i',
            ],
            'budget' => [
                '/(?:budget|price|pricing|worth)\s*(?:is|of|around|about|:)?\s*([^\n.;]{2,50}(?:cr|crore|lakh|lac|lacs|lakhs|\d{2,3}\s*[lk]))/i',
                '/\b(\d+(?:\.\d+)?)\s*(?:cr|crore|lakh|lac|lacs|lakhs)\b/i',
            ],
            'possession' => [
                '/(?:possession|possess)\s*(?:within|in|by|before)?\s*([^\n.;]{2,50})/i',
            ],
            'location' => [
                '/(?:preferred location|preferred area|preferred locality|looking (?:for|in)|want (?:to )?(?:buy|purchase) (?:in|at))\s*(?:is|:)?\s*([^\n.;]{2,60})/i',
            ],
            'timeline' => [
                '/(?:buy|purchase|decision|requirement|timeline)\s*(?:within|in|by|:)?\s*([^\n.;]{2,60})/i',
            ],
            // These are controlled Customer Information fields. They are
            // populated only from the canonical labelled customer-conversation
            // block below; never derive them from broad historical text.
            'decision_criteria' => [],
            'objections' => [],
        ];

        foreach ($patterns as $key => $regexes) {
            foreach ($activities as $activity) {
                $text = trim(implode("\n", array_filter([
                    (string) $activity->outcome,
                    (string) $activity->notes,
                ])));
                if ($text === '') continue;

                // Customer Information Check-in uses a canonical, controlled
                // note format. Read those exact labels back first so the
                // Customer panel reflects what was actually selected/saved
                // instead of relying on broad natural-language extraction.
                if ((string) $activity->action_source === 'customer_conversation') {
                    // Customer Information Check-in is a controlled, canonical
                    // source. Its labelled values must outrank and be isolated
                    // from broad historical text extraction (for example
                    // matching "buy" inside "Buyer Type"). Even when a
                    // particular field was not selected in this check-in, do
                    // not fall through to generic extraction for this activity.
                    $customerPatterns = [
                        'budget' => '/^Customer-stated budget range\s*:\s*(.+)$/mi',
                        'location' => '/^Customer preferred area\s*:\s*(.+)$/mi',
                        'possession' => '/^Possession requirement\s*:\s*(.+)$/mi',
                        'timeline' => '/^Purchase \/ decision timeline\s*:\s*(.+)$/mi',
                        'decision_criteria' => '/^Decision criteria\s*:\s*(.+)$/mi',
                        'objections' => '/^Concerns \/ objections\s*:\s*(.+)$/mi',
                    ];
                    $customerRegex = $customerPatterns[$key] ?? null;
                    if ($customerRegex && preg_match($customerRegex, $text, $customerMatch)) {
                        $value = $this->cleanFact((string) ($customerMatch[1] ?? ''));
                        if ($value !== '') {
                            $this->pushFact($history, $key, $value, (int) $activity->id, $activity->logged_at?->toIso8601String(), 'customer_conversation');
                        }
                    }
                    continue;
                }

                foreach ($regexes as $regex) {
                    if (! preg_match($regex, $text, $match)) continue;
                    $value = $this->cleanFact((string) ($match[1] ?? ''));
                    if ($value === '') continue;
                    $this->pushFact($history, $key, $value, (int) $activity->id, $activity->logged_at?->toIso8601String(), 'timeline');
                    break;
                }
            }
        }

        // Explicit structured CRM fields outrank free-text extraction.
        if ($lead->budget !== null && (string) $lead->budget !== '') {
            $this->pushFact($history, 'budget', (string) $lead->budget, null, $lead->updated_at?->toIso8601String(), 'lead', true);
        }

        foreach ($history as $key => $items) {
            $current[$key] = $items[0];
        }

        return [
            'current' => $current,
            'history' => $history,
            'changes' => $this->detectChanges($history),
        ];
    }

    private function pushFact(array &$history, string $key, string $value, ?int $activityId, ?string $at, string $source, bool $prefer = false): void
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($value)));
        foreach ($history[$key] ?? [] as $existing) {
            if (($existing['normalized'] ?? '') === $normalized) return;
        }
        $fact = [
            'value' => $value,
            'normalized' => $normalized,
            'activity_id' => $activityId,
            'at' => $at,
            'source' => $source,
            'confidence' => $source === 'lead' ? 'structured' : 'explicit_text',
            'evidence_level' => $source === 'lead' ? 1 : 3,
            'evidence_label' => $source === 'lead' ? 'FACT · STRUCTURED' : 'FACT · EXPLICIT TIMELINE',
        ];
        $history[$key] ??= [];
        if ($prefer) array_unshift($history[$key], $fact); else $history[$key][] = $fact;
    }

    private function detectChanges(array $history): array
    {
        $changes = [];
        foreach ($history as $key => $items) {
            if (count($items) < 2) continue;
            $latest = $items[0];
            $previous = $items[1];
            if ($latest['normalized'] === $previous['normalized']) continue;
            $changes[] = [
                'field' => $key,
                'label' => ucfirst(str_replace('_', ' ', $key)),
                'from' => $previous['value'],
                'to' => $latest['value'],
                'at' => $latest['at'],
                'activity_id' => $latest['activity_id'],
                'previous_activity_id' => $previous['activity_id'],
            ];
        }
        return $changes;
    }

    /**
     * Measure requirement maturity without inventing customer facts. Each
     * requirement is either confirmed from explicit evidence, or remains
     * unknown. Decision readiness is a deterministic workflow interpretation.
     */
    /**
     * Formal information-readiness map built only from fields already present
     * in the CRM. This is deliberately descriptive: it separates system facts,
     * channel-provided intake, customer evidence and agent/CRM events so later
     * intelligence cannot accidentally treat project data or agent actions as
     * customer preferences.
     */
    private function informationReadiness(Lead $lead, array $facts, Collection $activities, Collection $siteVisits): array
    {
        $current = $facts['current'] ?? [];
        $history = $facts['history'] ?? [];
        $payload = $this->decodeRawPayload($lead->raw_payload);

        $project = $lead->project;
        $source = strtolower(trim((string) ($lead->intake_source ?: $lead->source ?: '')));
        $channel = $this->readinessChannel($lead, $activities, $payload);
        $savedCustomerFields = $this->latestCustomerInformationFields($activities);

        $system = [
            $this->readinessItem('lead_id', 'Lead ID', $lead->id, 'SYSTEM_KNOWN', 'leads.id'),
            $this->readinessItem('customer_identity', 'Customer identity', $lead->customer_name ?: null, $lead->customer_name ? 'SYSTEM_KNOWN' : 'UNKNOWN', 'leads.customer_name / customers'),
            $this->readinessItem('customer_phone', 'Customer phone', $lead->phone ?: null, $lead->phone ? 'SYSTEM_KNOWN' : 'UNKNOWN', 'leads.phone / customers.phone'),
            $this->readinessItem('customer_email', 'Customer email', $lead->email ?: ($lead->customer?->email ?? null), ($lead->email || $lead->customer?->email) ? 'SYSTEM_KNOWN' : 'UNKNOWN', 'leads.email / customers.email'),
            $this->readinessItem('project', 'Enquiry project', $project?->name, $project ? 'SYSTEM_KNOWN' : 'UNKNOWN', 'leads.project_id → projects.name'),
            $this->readinessItem('project_location', 'Project location', $project?->location, $project?->location ? 'SYSTEM_KNOWN' : 'UNKNOWN', 'projects.location', [], 'PROJECT DATA — not customer preference'),
            $this->readinessItem('project_status', 'Project status', $project?->status, $project?->status ? 'SYSTEM_KNOWN' : 'UNKNOWN', 'projects.status'),
            $this->readinessItem('rera_number', 'Project RERA', $project?->rera_number, $project?->rera_number ? 'SYSTEM_KNOWN' : 'UNKNOWN', 'projects.rera_number'),
            $this->readinessItem('lead_source', 'Lead source', $lead->source ?: null, $lead->source ? 'SYSTEM_KNOWN' : 'UNKNOWN', 'leads.source'),
            $this->readinessItem('intake_source', 'Intake channel', $lead->intake_source ?: null, $lead->intake_source ? 'SYSTEM_KNOWN' : 'UNKNOWN', 'leads.intake_source'),
            $this->readinessItem('intake_reference', 'Intake reference', $lead->intake_ref ?: null, $lead->intake_ref ? 'SYSTEM_KNOWN' : 'UNKNOWN', 'leads.intake_ref'),
        ];

        $channelItems = [];
        $channelItems[] = $this->readinessItem('channel', 'Detected intake channel', $channel['label'], $channel['state'], $channel['basis'], $channel['evidence_activity_ids']);

        if ($channel['key'] === 'facebook') {
            foreach ($this->facebookIntakeFacts($activities, $payload) as $item) $channelItems[] = $item;
        } elseif ($channel['key'] === 'waba' || $channel['key'] === 'whatsapp') {
            foreach ($this->whatsappIntakeFacts($activities, $payload) as $item) $channelItems[] = $item;
        } else {
            foreach ($this->websiteIntakeFacts($lead, $activities, $payload) as $item) $channelItems[] = $item;
        }

        $customerItems = [];
        $customerKeys = [
            'configuration' => 'Preferred configuration',
            'budget' => 'Customer-stated budget',
            'location' => 'Customer preferred location',
            'possession' => 'Possession requirement',
            'timeline' => 'Purchase / decision timeline',
        ];
        foreach ($customerKeys as $key => $label) {
            $savedCustomerField = $savedCustomerFields[$key] ?? null;
            if (is_array($savedCustomerField)) {
                $customerItems[] = $this->readinessItem(
                    $key,
                    $label,
                    $savedCustomerField['value'],
                    $savedCustomerField['state'],
                    $savedCustomerField['basis'],
                    $savedCustomerField['evidence_activity_ids'],
                    $savedCustomerField['source_type']
                );
                continue;
            }
            $fact = $current[$key] ?? null;
            $state = 'UNKNOWN';
            $value = null;
            $evidence = [];
            $basis = 'No explicit customer requirement evidence is currently documented.';
            $sourceType = null;
            if (is_array($fact) && trim((string) ($fact['value'] ?? '')) !== '') {
                $value = (string) $fact['value'];
                $sourceType = $this->factSourceType($fact, $activities);
                $customerEvidence = in_array($sourceType, ['customer_conversation', 'customer_confirmation', 'facebook_form_answer', 'waba_interaction'], true);
                $state = ! $customerEvidence ? 'NEEDS_CONFIRMATION' : (count($history[$key] ?? []) > 1 ? 'CHANGED' : 'CUSTOMER_PROVIDED');
                $evidence = !empty($fact['activity_id']) ? [(int) $fact['activity_id']] : [];
                $basis = $fact['evidence_label'] ?? 'Explicit CRM evidence';
                if ($key === 'budget' && ($fact['source'] ?? '') === 'lead') {
                    $state = in_array($channel['key'], ['website','facebook','waba','whatsapp'], true)
                        ? 'CHANNEL_PROVIDED'
                        : 'NEEDS_CONFIRMATION';
                    $basis = 'Structured leads.budget is present; provenance is not stored at field level, so it is not treated as an independently verified customer budget.';
                }
            } elseif ($this->wasAsked($key, $activities)) {
                $state = 'ASKED_NO_RESPONSE';
                $basis = 'A requirement prompt/question is documented, but no explicit answer is currently available.';
            }
            $customerItems[] = $this->readinessItem($key, $label, $value, $state, $basis, $evidence, $sourceType);
        }

        foreach ([
            'purpose' => 'Investment / self-use purpose',
            'funding' => 'Funding / finance position',
            'decision_criteria' => 'Decision criteria',
            'objections' => 'Objections / concerns',
        ] as $key => $label) {
            // Controlled Customer Information entries are authoritative for
            // these fields and must be reflected exactly as saved.
            $savedCustomerField = $savedCustomerFields[$key] ?? null;
            if (is_array($savedCustomerField)) {
                $customerItems[] = $this->readinessItem(
                    $key,
                    $label,
                    $savedCustomerField['value'],
                    $savedCustomerField['state'],
                    $savedCustomerField['basis'],
                    $savedCustomerField['evidence_activity_ids'],
                    $savedCustomerField['source_type']
                );
                continue;
            }
            $fact = $current[$key] ?? null;
            if (is_array($fact) && trim((string) ($fact['value'] ?? '')) !== '') {
                $sourceType = $this->factSourceType($fact, $activities);
                $customerEvidence = in_array($sourceType, ['customer_conversation', 'customer_confirmation', 'facebook_form_answer', 'waba_interaction'], true);
                $state = ! $customerEvidence ? 'NEEDS_CONFIRMATION' : (count($history[$key] ?? []) > 1 ? 'CHANGED' : 'CUSTOMER_PROVIDED');
                $customerItems[] = $this->readinessItem($key, $label, $fact['value'], $state, $fact['evidence_label'] ?? 'Explicit timeline evidence.', !empty($fact['activity_id']) ? [(int) $fact['activity_id']] : [], $sourceType);
                continue;
            }

            $match = $this->findExplicitIntakeOrTimelineField($key, $activities, $payload);
            if ($match) {
                $customerItems[] = $this->readinessItem($key, $label, $match['value'], $match['state'], $match['basis'], $match['evidence_activity_ids'], $match['source_type']);
            } else {
                $customerItems[] = $this->readinessItem($key, $label, null, $this->wasAsked($key, $activities) ? 'ASKED_NO_RESPONSE' : 'UNKNOWN', $this->wasAsked($key, $activities) ? 'A related question is documented without an explicit answer.' : 'No explicit customer evidence is currently documented.');
            }
        }

        $events = [];
        $eventMap = [
            'assignment' => ['Assigned agent', ['lead_reassigned','lead_shared','shared_agent_report','site_team_report']],
            'site_visit' => ['Site visit progression', ['site_visit']],
            'cross_project' => ['Cross-project / additional project event', ['lead_reassigned','lead_shared']],
            'status' => ['Lifecycle status', ['status_change']],
            'followup' => ['CRM follow-up task', []],
        ];
        foreach ($eventMap as $key => [$label, $types]) {
            $ids = $types ? $activities->filter(fn (Activity $a) => in_array((string) $a->type, $types, true))->take(5)->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
            if ($key === 'followup') $value = $lead->pendingFollowups()->count() . ' pending task(s)';
            elseif ($key === 'status') $value = $lead->status_label;
            else $value = $ids ? count($ids) . ' documented event(s)' : null;
            $events[] = $this->readinessItem($key, $label, $value, $value !== null ? 'CRM_EVENT' : 'UNKNOWN', 'Operational CRM record; not customer preference evidence.', $ids, 'agent_or_crm_event');
        }

        $requirements = collect($customerItems)->keyBy('key');
        $coreKeys = array_keys($customerKeys);
        $customerProvided = collect($coreKeys)->filter(fn ($key) => in_array($requirements[$key]['state'] ?? 'UNKNOWN', ['CUSTOMER_PROVIDED','CHANNEL_PROVIDED','CHANGED'], true))->count();
        $needsConfirmation = collect($coreKeys)->filter(fn ($key) => in_array($requirements[$key]['state'] ?? 'UNKNOWN', ['NEEDS_CONFIRMATION','AMBIGUOUS','ASKED_NO_RESPONSE'], true))->count();
        $unknown = collect($coreKeys)->filter(fn ($key) => ($requirements[$key]['state'] ?? 'UNKNOWN') === 'UNKNOWN')->count();

        $gates = [
            [
                'key' => 'customer_understanding',
                'label' => 'Customer understanding',
                'state' => $customerProvided >= 1 ? 'READY_WITH_GAPS' : 'NOT_READY',
                'text' => $customerProvided >= 1 ? 'At least one core customer requirement is explicitly documented; remaining gaps stay visible.' : 'No core customer requirement is yet explicitly documented.',
            ],
            [
                'key' => 'project_evaluation',
                'label' => 'Project evaluation',
                'state' => ($project && ($requirements['configuration']['state'] ?? 'UNKNOWN') !== 'UNKNOWN' && ($requirements['budget']['state'] ?? 'UNKNOWN') !== 'UNKNOWN') ? 'READY_WITH_GAPS' : 'NOT_READY',
                'text' => ($project && ($requirements['configuration']['state'] ?? 'UNKNOWN') !== 'UNKNOWN' && ($requirements['budget']['state'] ?? 'UNKNOWN') !== 'UNKNOWN') ? 'Project identity plus configuration and budget information are available for an evidence-backed evaluation; other constraints may still be missing.' : 'Do not treat project evaluation as fully informed until project, configuration and budget information are sufficiently documented.',
            ],
            [
                'key' => 'decision_path',
                'label' => 'Decision path',
                'state' => ($requirements['timeline']['state'] ?? 'UNKNOWN') !== 'UNKNOWN' && $customerProvided >= 3 ? 'READY_WITH_GAPS' : 'NOT_READY',
                'text' => ($requirements['timeline']['state'] ?? 'UNKNOWN') !== 'UNKNOWN' && $customerProvided >= 3 ? 'A decision timeline and several core customer requirements are documented; decision criteria/objections may still need confirmation.' : 'Decision-path intelligence remains gated while the decision timeline or core customer requirements are insufficiently documented.',
            ],
            [
                'key' => 'post_visit_decision',
                'label' => 'Post-visit decision',
                'state' => $siteVisits->isNotEmpty() && $siteVisits->first()->client_attended ? 'REQUIRES_CAPTURE' : 'NOT_APPLICABLE',
                'text' => $siteVisits->isNotEmpty() && $siteVisits->first()->client_attended ? 'An attended visit exists; customer feedback, objection and next commitment should be captured explicitly.' : 'No attended site visit is currently recorded.',
            ],
        ];

        return [
            'channel' => $channel,
            'system_known' => $system,
            'channel_provided' => $channelItems,
            'customer_information' => $customerItems,
            'agent_crm_events' => $events,
            'summary' => [
                'core_customer_fields' => count($coreKeys),
                'customer_provided' => $customerProvided,
                'needs_confirmation' => $needsConfirmation,
                'unknown' => $unknown,
                'readiness_state' => $customerProvided >= 3 ? 'SUBSTANTIALLY_READY' : ($customerProvided >= 1 ? 'PARTIALLY_READY' : 'EARLY_DISCOVERY'),
            ],
            'gates' => $gates,
            'state_definitions' => $this->informationReadinessStates(),
            'provenance_model' => ['source', 'source_type', 'captured_at', 'evidence_id', 'confidence'],
        ];
    }

    private function readinessItem(string $key, string $label, $value, string $state, string $basis, array $evidenceActivityIds = [], ?string $sourceType = null, ?string $note = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value !== null && trim((string) $value) !== '' ? (string) $value : null,
            'state' => $state,
            'state_label' => ucwords(strtolower(str_replace('_', ' ', $state))),
            'basis' => $basis,
            'source_type' => $sourceType,
            'evidence_activity_ids' => array_values(array_unique(array_map('intval', $evidenceActivityIds))),
            'note' => $note,
        ];
    }

    private function informationReadinessStates(): array
    {
        return [
            ['key' => 'SYSTEM_KNOWN', 'label' => 'System known', 'description' => 'Known from structured CRM records; not a customer preference.'],
            ['key' => 'CUSTOMER_PROVIDED', 'label' => 'Customer provided', 'description' => 'Explicit customer information documented in the CRM.'],
            ['key' => 'CHANNEL_PROVIDED', 'label' => 'Channel provided', 'description' => 'Captured through website, Facebook or WhatsApp intake; provenance remains visible.'],
            ['key' => 'NEEDS_CONFIRMATION', 'label' => 'Needs confirmation', 'description' => 'A value exists, but its provenance or current validity is not sufficient for treating it as confirmed.'],
            ['key' => 'UNKNOWN', 'label' => 'Unknown', 'description' => 'No explicit value is currently documented.'],
            ['key' => 'ASKED_NO_RESPONSE', 'label' => 'Asked — no response', 'description' => 'The CRM records a prompt/question, but no explicit answer is available.'],
            ['key' => 'AMBIGUOUS', 'label' => 'Ambiguous', 'description' => 'Evidence exists but is not specific enough to safely normalize.'],
            ['key' => 'CHANGED', 'label' => 'Changed', 'description' => 'A later explicit value differs from an earlier documented value.'],
            ['key' => 'NOT_APPLICABLE', 'label' => 'Not applicable', 'description' => 'The field does not apply to the current documented workflow state.'],
            ['key' => 'CRM_EVENT', 'label' => 'CRM event', 'description' => 'Operational activity such as assignment, status, follow-up or visit progression; not customer preference evidence.'],
        ];
    }

    private function decodeRawPayload($raw): array
    {
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function readinessChannel(Lead $lead, Collection $activities, array $payload): array
    {
        $source = strtolower(trim((string) ($lead->intake_source ?: $lead->source ?: '')));
        $text = strtolower($activities->map(fn (Activity $a) => (string) $a->outcome . ' ' . (string) $a->notes)->implode("\n"));
        if (str_contains($text, 'meta ads lead') || str_contains($text, 'meta lead') || $source === 'facebook') return ['key' => 'facebook', 'label' => 'Facebook / Meta', 'state' => 'CHANNEL_PROVIDED', 'basis' => 'leads.intake_source/source or Meta capture activity', 'evidence_activity_ids' => $this->activityIdsMatching($activities, '/meta ads lead|meta lead/i')];
        if (str_contains($text, 'whatsapp') || str_contains($text, 'waba') || in_array($source, ['whatsapp','whatsapp_waba','waba'], true)) return ['key' => 'waba', 'label' => 'WhatsApp / WABA', 'state' => 'CHANNEL_PROVIDED', 'basis' => 'WhatsApp/WABA intake or interaction activity', 'evidence_activity_ids' => $this->activityIdsMatching($activities, '/whatsapp|waba/i')];
        if ($source === 'website' || str_contains($text, 'website lead captured') || str_contains($text, 'new lead from: website')) return ['key' => 'website', 'label' => 'Website', 'state' => 'CHANNEL_PROVIDED', 'basis' => 'leads.intake_source/source or website capture activity', 'evidence_activity_ids' => $this->activityIdsMatching($activities, '/website lead captured|new lead from: website/i')];
        return ['key' => 'other', 'label' => $source !== '' ? ucfirst(str_replace('_',' ', $source)) : 'Unknown', 'state' => $source !== '' ? 'SYSTEM_KNOWN' : 'UNKNOWN', 'basis' => 'Structured lead source fields', 'evidence_activity_ids' => []];
    }

    private function activityIdsMatching(Collection $activities, string $pattern): array
    {
        return $activities->filter(function (Activity $a) use ($pattern) {
            return preg_match($pattern, strtolower((string) $a->outcome . ' ' . (string) $a->notes));
        })->take(5)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function facebookIntakeFacts(Collection $activities, array $payload): array
    {
        $ids = $this->activityIdsMatching($activities, '/meta ads lead captured|meta ads lead/i');
        $items = [];
        foreach (['form_id' => 'Facebook form ID', 'campaign_id' => 'Facebook campaign ID', 'ad_id' => 'Facebook ad ID', 'page_id' => 'Facebook page ID', 'leadgen_id' => 'Facebook leadgen ID'] as $key => $label) {
            $value = $payload[$key] ?? null;
            if ($value === null && $ids) {
                foreach ($activities as $a) {
                    $text = (string) $a->notes;
                    if (preg_match('/'.preg_quote(ucfirst(str_replace('_',' ', $key)), '/').':\s*([^\n]+)/i', $text, $m)) { $value = trim($m[1]); break; }
                }
            }
            $items[] = $this->readinessItem($key, $label, $value, $value ? 'CHANNEL_PROVIDED' : 'UNKNOWN', $value ? 'Facebook intake metadata captured by CRM.' : 'No explicit Facebook metadata value is currently exposed.', $ids, 'facebook_intake');
        }
        $answerFields = [];
        foreach ($activities as $a) {
            if (!preg_match('/meta ads lead captured/i', (string) $a->notes)) continue;
            foreach (preg_split('/\R/', (string) $a->notes) as $line) {
                if (preg_match('/^([^:]{2,80}):\s*(.+)$/', trim($line), $m) && !preg_match('/^(leadgen id|page id|form id)$/i', trim($m[1]))) {
                    $answerFields[] = ['label' => trim($m[1]), 'value' => trim($m[2])];
                }
            }
        }
        if ($answerFields) {
            $items[] = $this->readinessItem('form_answers', 'Facebook form answers', json_encode($answerFields, JSON_UNESCAPED_UNICODE), 'CHANNEL_PROVIDED', 'Customer-supplied Facebook form fields are currently stored in the Meta capture Activity note.', $ids, 'facebook_form_answer');
        } else {
            $items[] = $this->readinessItem('form_answers', 'Facebook form answers', null, 'UNKNOWN', 'No non-identity Facebook form answer was found in the available activity data.', $ids, 'facebook_form_answer');
        }
        return $items;
    }

    private function whatsappIntakeFacts(Collection $activities, array $payload): array
    {
        $ids = $this->activityIdsMatching($activities, '/whatsapp|waba/i');
        $text = strtolower($activities->map(fn (Activity $a) => (string) $a->outcome . ' ' . (string) $a->notes)->implode("\n"));
        $verifiedYes = (bool) preg_match('/customer confirmed enquiry.*yes i did|yes i did/i', $text);
        $verifiedNo = (bool) preg_match('/customer denied enquiry|did not enquire|didn.t make this enquiry/i', $text);
        $button = null;
        if (preg_match('/(?:action|button):\s*(get brochure|get call back|book site visit)/i', $text, $m)) $button = trim($m[1]);
        $items = [
            $this->readinessItem('enquiry_verification', 'Enquiry verification', $verifiedYes ? 'Yes I did' : ($verifiedNo ? 'Did not enquire' : null), $verifiedYes || $verifiedNo ? 'CUSTOMER_PROVIDED' : 'UNKNOWN', $verifiedYes || $verifiedNo ? 'Explicit WhatsApp verification response documented.' : 'No explicit WhatsApp verification response found.', $ids, 'waba_verification'),
            $this->readinessItem('whatsapp_action', 'WhatsApp requested action', $button, $button ? 'CHANNEL_PROVIDED' : 'UNKNOWN', $button ? 'Explicit WhatsApp button/action captured.' : 'No explicit WhatsApp button action found.', $ids, 'waba_button'),
        ];
        return $items;
    }

    private function websiteIntakeFacts(Lead $lead, Collection $activities, array $payload): array
    {
        $items = [];
        foreach (['project' => 'Website enquiry project', 'configuration' => 'Website configuration', 'budget' => 'Website budget', 'purpose' => 'Website purpose', 'location' => 'Website location input', 'possession' => 'Website possession', 'timeline' => 'Website decision timeline'] as $key => $label) {
            $value = $payload[$key] ?? null;
            if ($value === null && $key === 'budget' && $lead->budget !== null) $value = (string) $lead->budget;
            $state = $value !== null && trim((string) $value) !== '' ? 'CHANNEL_PROVIDED' : 'UNKNOWN';
            $note = $key === 'location' ? 'Website location input is retained as intake data; it must not be treated as customer preferred location unless explicitly framed that way by the form.' : null;
            $items[] = $this->readinessItem($key, $label, $value, $state, $value !== null ? 'Website intake payload / structured lead data.' : 'No explicit website field value is currently exposed.', [], 'website_intake', $note);
        }
        return $items;
    }

    private function factSourceType(array $fact, Collection $activities): string
    {
        if (($fact['source'] ?? '') === 'lead') return 'structured_lead_field';
        $id = (int) ($fact['activity_id'] ?? 0);
        if (!$id) return 'timeline_text';
        $activity = $activities->firstWhere('id', $id);
        if (!$activity) return 'timeline_text';
        $text = strtolower((string) $activity->outcome . ' ' . (string) $activity->notes);
        if ($activity->action_source === 'customer_confirmation') return 'customer_confirmation';
        if ($activity->action_source === 'customer_conversation') return 'customer_conversation';
        if ($activity->action_source === 'crm_classification') return 'crm_classification';
        if (str_contains($text, 'meta ads lead') || str_contains($text, 'facebook')) return 'facebook_form_answer';
        if (str_contains($text, 'whatsapp') || str_contains($text, 'waba')) return 'waba_interaction';
        if ($activity->action_source === 'automated' && (string) $activity->type === 'note') return 'automated_intake_or_crm';
        return 'agent_or_crm_event';
    }

    /**
     * Read the controlled Customer Information Check-in as a separate evidence
     * source. These values must never be replaced by broad extraction from
     * unrelated timeline text (for example "Buyer Type" matching "buy").
     * The first occurrence per field is the latest saved customer value because
     * the activities collection is ordered newest-first.
     */
    private function latestCustomerInformationFields(Collection $activities): array
    {
        $patterns = [
            'budget' => '/^Customer-stated budget range\s*:\s*(.+)$/mi',
            'location' => '/^Customer preferred area\s*:\s*(.+)$/mi',
            'possession' => '/^Possession requirement\s*:\s*(.+)$/mi',
            'timeline' => '/^Purchase \/ decision timeline\s*:\s*(.+)$/mi',
            'decision_criteria' => '/^Decision criteria\s*:\s*(.+)$/mi',
            'objections' => '/^Concerns \/ objections\s*:\s*(.+)$/mi',
        ];
        $fields = [];
        $historyCounts = [];
        foreach ($activities as $activity) {
            if ((string) $activity->action_source !== 'customer_conversation') continue;
            $text = trim((string) $activity->outcome . "\n" . (string) $activity->notes);
            foreach ($patterns as $key => $regex) {
                if (!preg_match($regex, $text, $match)) continue;
                $value = $this->cleanFact((string) ($match[1] ?? ''));
                if ($value === '') continue;
                $normalized = strtolower(preg_replace('/\s+/', ' ', trim($value)));
                $historyCounts[$key] ??= [];
                if (!in_array($normalized, $historyCounts[$key], true)) {
                    $historyCounts[$key][] = $normalized;
                }
                if (isset($fields[$key])) continue;
                $fields[$key] = [
                    'key' => $key,
                    'value' => $value,
                    'state' => 'CUSTOMER_PROVIDED',
                    'source_type' => 'customer_conversation',
                    'basis' => 'Controlled Customer Information entry.',
                    'evidence_activity_ids' => [(int) $activity->id],
                ];
            }
        }
        foreach ($fields as $key => &$field) {
            if (count($historyCounts[$key] ?? []) > 1) $field['state'] = 'CHANGED';
        }
        unset($field);
        return $fields;
    }

    private function wasAsked(string $key, Collection $activities): bool
    {
        $patterns = [
            'configuration' => '/configuration|config|bhk|bedroom/i',
            'budget' => '/budget|price range|afford/i',
            'location' => '/preferred location|which area|locality|location/i',
            'possession' => '/possession/i',
            'timeline' => '/when.*(?:buy|purchase|decide)|purchase.*when|decision timeline/i',
            'purpose' => '/self use|investment|purpose/i',
            'funding' => '/loan|finance|funding/i',
            'decision_criteria' => '/important factor|decision criteria|what.*most important/i',
            'objections' => '/concern|objection|dislike|issue|problem/i',
        ];
        $pattern = $patterns[$key] ?? null;
        if (!$pattern) return false;
        return $activities->contains(function (Activity $a) use ($pattern) {
            $text = (string) $a->outcome . ' ' . (string) $a->notes;
            return str_contains($text, '?') && preg_match($pattern, $text);
        });
    }

    private function findExplicitIntakeOrTimelineField(string $key, Collection $activities, array $payload): ?array
    {
        if (isset($payload[$key]) && trim((string) $payload[$key]) !== '') {
            return ['value' => (string) $payload[$key], 'state' => 'CHANNEL_PROVIDED', 'basis' => 'Structured intake payload field.', 'evidence_activity_ids' => [], 'source_type' => 'channel_intake'];
        }
        $customerPatterns = [
            'decision_criteria' => '/^Decision criteria\s*:\s*(.+)$/mi',
            'objections' => '/^Concerns \/ objections\s*:\s*(.+)$/mi',
        ];
        foreach ($activities as $a) {
            if ((string) $a->action_source !== 'customer_conversation') continue;
            $text = trim((string) $a->outcome . ' ' . (string) $a->notes);
            $customerRegex = $customerPatterns[$key] ?? null;
            if (!$customerRegex || !preg_match($customerRegex, $text, $m)) continue;
            $value = $this->cleanFact((string) ($m[1] ?? ''));
            if ($value === '') continue;
            return [
                'value' => $value,
                'state' => 'CUSTOMER_PROVIDED',
                'basis' => 'Controlled Customer Information entry.',
                'evidence_activity_ids' => [(int) $a->id],
                'source_type' => 'customer_conversation',
            ];
        }

        $patterns = [
            'purpose' => '/(?:purpose|investment|self use|self-use)\s*(?:is|:|=)?\s*([^\n.;]+)/i',
            'funding' => '/(?:funding|finance|financing|loan|self[- ]funded)\s*(?:is|:|=)?\s*([^\n.;]+)/i',
            'decision_criteria' => '/(?:decision criteria|important factor|most important)\s*(?:is|:|=)?\s*([^\n.;]+)/i',
            'objections' => '/(?:objection|concern|issue|dislike)\s*(?:is|:|=)?\s*([^\n.;]+)/i',
        ];
        $regex = $patterns[$key] ?? null;
        if (!$regex) return null;
        foreach ($activities as $a) {
            $text = trim((string) $a->outcome . ' ' . (string) $a->notes);
            if ($text === '' || !preg_match($regex, $text, $m)) continue;
            $value = $this->cleanFact((string) ($m[1] ?? ''));
            if ($value === '') continue;
            $sourceType = $this->factSourceType(['source' => 'timeline', 'activity_id' => $a->id], $activities);
            return [
                'value' => $value,
                'state' => $sourceType === 'agent_or_crm_event' ? 'NEEDS_CONFIRMATION' : 'CUSTOMER_PROVIDED',
                'basis' => $sourceType === 'agent_or_crm_event' ? 'Explicit text exists, but the activity is not clearly attributable to a customer channel.' : 'Explicit timeline evidence.',
                'evidence_activity_ids' => [(int) $a->id],
                'source_type' => $sourceType,
            ];
        }
        return null;
    }

    private function requirementMaturity(Lead $lead, array $facts, Collection $activities, Collection $siteVisits): array
    {
        $current = $facts['current'];
        $requirements = [];
        $evidenceIds = [];
        $checks = [
            'configuration' => 'Configuration',
            'budget' => 'Budget',
            'location' => 'Preferred location',
            'possession' => 'Possession requirement',
            'timeline' => 'Purchase / decision timeline',
        ];

        foreach ($checks as $key => $label) {
            $fact = $current[$key] ?? null;
            $confirmed = is_array($fact) && trim((string) ($fact['value'] ?? '')) !== '';
            $requirements[] = [
                'key' => $key,
                'label' => $label,
                'state' => $confirmed ? 'confirmed' : 'unknown',
                'state_label' => $confirmed ? 'Confirmed' : 'Unknown',
                'value' => $confirmed ? (string) $fact['value'] : null,
                'evidence_activity_ids' => $confirmed && !empty($fact['activity_id']) ? [(int) $fact['activity_id']] : [],
                'evidence_label' => $confirmed ? ($fact['evidence_label'] ?? 'FACT · EXPLICIT TIMELINE') : 'INFERENCE · REQUIRES CONFIRMATION',
            ];
            if ($confirmed && !empty($fact['activity_id'])) $evidenceIds[] = (int) $fact['activity_id'];
        }

        $confirmedCount = collect($requirements)->where('state', 'confirmed')->count();
        $unknownCount = count($requirements) - $confirmedCount;
        $comparisonEvidence = $this->competingOptionEvidence($activities);
        $hasComparison = !empty($comparisonEvidence);
        $decisionTimelineKnown = isset($current['timeline']['value']) && trim((string) $current['timeline']['value']) !== '';

        if ($lead->isFinal()) {
            $state = 'final';
            $stateLabel = 'Final lifecycle state';
        } elseif ($hasComparison && $confirmedCount >= 2) {
            $state = 'comparison_ready';
            $stateLabel = 'Comparison / decision qualification';
        } elseif ($confirmedCount >= 3) {
            $state = 'substantially_documented';
            $stateLabel = 'Requirements substantially documented';
        } elseif ($confirmedCount > 0) {
            $state = 'partially_documented';
            $stateLabel = 'Requirements partially documented';
        } else {
            $state = 'early_discovery';
            $stateLabel = 'Early requirement discovery';
        }

        $readinessText = match ($state) {
            'comparison_ready' => 'Core requirements are documented and a competing option is evidenced; the next conversation should qualify the comparison and decision path.',
            'substantially_documented' => 'Several core requirements are documented; resolve the remaining gaps before treating the lead as decision-ready.',
            'partially_documented' => 'Some requirements are documented, but important gaps remain.',
            'early_discovery' => 'Core customer requirements are not yet sufficiently documented.',
            default => 'No active decision-readiness work is generated for a final lead.',
        };

        return [
            'requirements' => $requirements,
            'confirmed_count' => $confirmedCount,
            'unknown_count' => $unknownCount,
            'evidence_activity_ids' => array_values(array_unique(array_merge($evidenceIds, $comparisonEvidence))),
            'decision_readiness' => [
                'state' => $state,
                'label' => $stateLabel,
                'text' => $readinessText,
                'comparison_evidenced' => $hasComparison,
                'decision_timeline_known' => $decisionTimelineKnown,
            ],
        ];
    }

    private function competingOptionEvidence(Collection $activities): array
    {
        $terms = '/\b(token|tokened|token given|already booked|booking done|already finalize(?:d)?|other project|another project|competing project|cancel .*?(?:other|existing) booking)\b/i';
        return $activities->filter(function (Activity $activity) use ($terms) {
            $text = trim((string) $activity->outcome . ' ' . (string) $activity->notes);
            return $text !== '' && preg_match($terms, $text);
        })->take(5)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function unknowns(Lead $lead, array $facts, Collection $activities, Collection $siteVisits): array
    {
        $unknowns = [];
        $checks = [
            'configuration' => 'Preferred configuration',
            'budget' => 'Budget',
            'location' => 'Preferred location',
            'possession' => 'Possession requirement',
            'timeline' => 'Purchase / decision timeline',
        ];
        foreach ($checks as $key => $label) {
            if (! isset($facts[$key]['value']) || trim((string) $facts[$key]['value']) === '') {
                $unknowns[] = ['key' => $key, 'label' => $label, 'reason' => 'No explicit value is documented in the available lead timeline or structured lead fields.'];
            }
        }
        if ($siteVisits->isNotEmpty() && ! $activities->contains(fn (Activity $a) => str_contains(strtolower((string) $a->outcome . ' ' . $a->notes), 'feedback'))) {
            $unknowns[] = ['key' => 'post_visit_feedback', 'label' => 'Post-visit feedback / objection', 'reason' => 'A site visit exists, but the available timeline does not explicitly document customer feedback.'];
        }
        return $unknowns;
    }

    private function origin(Lead $lead, Collection $activities): array
    {
        $source = $lead->intake_source ?: $lead->source;
        $originType = $lead->origin_type;
        $note = $lead->origin_note;
        if (! $source && $activities->isNotEmpty()) {
            $capture = $activities->first(fn (Activity $a) => in_array($a->type, ['note', 'lead_created'], true) && str_contains(strtolower((string) $a->notes), 'lead'));
            if ($capture) $note = $capture->notes;
        }
        return [
            'source' => $source ?: null,
            'type' => $originType ?: null,
            'note' => $note ?: null,
        ];
    }

    private function signals(Lead $lead, Collection $activities, Collection $followups, Collection $siteVisits): array
    {
        $signals = [];
        $pending = $followups->where('status', 'pending');
        $overdue = $pending->filter(fn (Followup $f) => $f->isOverdue());
        $recentDays = $activities->filter(fn (Activity $a) => $a->logged_at && $a->logged_at->gte(now()->subDays(7)));

        if ($overdue->isNotEmpty()) {
            $signals[] = $this->signal('urgent', 'Overdue work',
                $overdue->count() . ' pending follow-up' . ($overdue->count() === 1 ? '' : 's') . ' is overdue.',
                $overdue->first()->source_activity_id ? [(int) $overdue->first()->source_activity_id] : []);
        }

        if (! $lead->isFinal() && $pending->isEmpty()) {
            $signals[] = $this->signal('warning', 'No next action',
                'This active lead has no pending follow-up. A next action should be explicitly scheduled.');
        }

        $notAnswered = $recentDays->where('outcome_key', 'not_answered');
        if ($notAnswered->count() >= 2) {
            $signals[] = $this->signal('warning', 'Repeated no-answer pattern',
                'There are ' . $notAnswered->count() . ' not-answered attempts in the last 7 days.',
                $notAnswered->take(5)->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        $interest = $recentDays->filter(fn (Activity $a) => in_array((string) $a->outcome_key, [
            'interested', 'site_visit_scheduled', 'visit_scheduled', 'visit_done', 'booking',
        ], true));
        if ($interest->isNotEmpty() && ! $lead->isWon()) {
            $signals[] = $this->signal('positive', 'Recent buying signal',
                'A recent activity records interest or a concrete progression event.',
                $interest->take(3)->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        if ($siteVisits->isNotEmpty()) {
            $latestVisit = $siteVisits->first();
            if ($latestVisit->client_attended) {
                $signals[] = $this->signal('positive', 'Site visit completed',
                    'The customer has a recorded attended site visit. Capture the post-visit decision and next step.',
                    [], \Carbon\Carbon::parse((string) $latestVisit->visit_at)->toIso8601String());
            }
        }

        if ($lead->visit_scheduled_at && $lead->visit_scheduled_at->isFuture()) {
            $signals[] = $this->signal('important', 'Upcoming site visit',
                'A site visit is scheduled for ' . $lead->visit_scheduled_at->format('d M Y, h:i A') . '.');
        }

        if ($lead->status === 'booking') {
            $signals[] = $this->signal('important', 'Booking stage',
                'The lead is in Booking. Commercial/approval work should remain visible and controlled.');
        }

        return $signals;
    }

    private function nextAction(
        Lead $lead,
        Collection $activities,
        Collection $followups,
        Collection $siteVisits,
        array $signals,
        array $journey,
        array $decisionDependencies,
        array $facts
    ): array {
        $pending = $followups->where('status', 'pending')->sortBy('scheduled_for')->values();
        $overdue = $pending->filter(fn (Followup $f) => $f->isOverdue())->values();

        if ($overdue->isNotEmpty()) {
            $task = $overdue->first();
            return $this->actionResult(
                'existing_task',
                $task->action_type,
                'Complete the overdue CRM task before creating another competing action.',
                1,
                'existing_crm_task',
                $task->source_activity_id ? [(int) $task->source_activity_id] : [],
                (int) $task->id,
                $task->scheduled_for?->toIso8601String()
            );
        }

        if ($pending->isNotEmpty()) {
            $task = $pending->first();
            return $this->actionResult(
                'existing_task',
                $task->action_type,
                'An explicit next action is already scheduled in the CRM.',
                1,
                'existing_crm_task',
                $task->source_activity_id ? [(int) $task->source_activity_id] : [],
                (int) $task->id,
                $task->scheduled_for?->toIso8601String()
            );
        }

        if ($lead->isFinal()) {
            return $this->actionResult(
                'none',
                'No operational action',
                'This lead is in a final lifecycle state.',
                1,
                'final_lifecycle_state'
            );
        }

        // Decision dependencies are deliberately prioritized over generic
        // discovery. The recommended action must address the most concrete
        // documented blocker first, without pretending that an inference is fact.
        $priority = [
            'post_visit' => 10,
            'visit_outcome' => 20,
            'competing_option' => 30,
            'funding' => 40,
            'information_gap' => 50,
        ];
        $dependencies = collect($decisionDependencies)
            ->sortBy(fn ($dependency) => $priority[$dependency['type']] ?? 99)
            ->values();

        foreach ($dependencies as $dependency) {
            $type = (string) $dependency['type'];
            $evidence = $dependency['evidence_activity_ids'] ?? [];
            $evidenceLevel = (string) ($dependency['evidence_level'] ?? 'inference');

            if ($type === 'post_visit') {
                return $this->actionResult(
                    'recommended',
                    'Record post-visit decision',
                    'A customer-attended visit is documented. Capture feedback, objection and the next commitment before advancing the lead.',
                    2,
                    'post_visit_dependency',
                    $evidence
                );
            }

            if ($type === 'visit_outcome') {
                return $this->actionResult(
                    'recommended',
                    'Confirm visit outcome',
                    'Visit progression is documented, but attendance or outcome is not yet established. Confirm what happened before treating the visit as completed.',
                    $evidenceLevel === 'explicit_text' ? 3 : 4,
                    'visit_outcome_dependency',
                    $evidence
                );
            }

            if ($type === 'competing_option') {
                return $this->actionResult(
                    'recommended',
                    'Clarify competing option and decision criteria',
                    'The timeline explicitly records another token, booking or alternative. Confirm the comparison criteria and what must be resolved before the customer can decide.',
                    3,
                    'competing_option_dependency',
                    $evidence
                );
            }

            if ($type === 'funding') {
                return $this->actionResult(
                    'recommended',
                    'Confirm funding position',
                    'Funding or financing is explicitly mentioned. Confirm the available funding structure and any amount still to be arranged before discussing financial commitment.',
                    3,
                    'funding_dependency',
                    $evidence
                );
            }

            if ($type === 'information_gap') {
                $gapKey = (string) ($dependency['gap_key'] ?? '');
                $labels = [
                    'configuration' => 'Confirm preferred configuration',
                    'budget' => 'Confirm usable budget',
                    'location' => 'Confirm preferred location',
                    'possession' => 'Confirm possession requirement',
                    'timeline' => 'Confirm purchase / decision timeline',
                ];
                if (isset($labels[$gapKey])) {
                    return $this->actionResult(
                        'recommended',
                        $labels[$gapKey],
                        'Resolve the highest-value documented information gap before narrowing options or moving the conversation forward.',
                        4,
                        'information_gap_dependency',
                        $evidence
                    );
                }
            }
        }

        $latest = $activities->first();
        if ($latest && in_array((string) $latest->outcome_key, ['not_answered', 'busy', 'call_later'], true)) {
            return $this->actionResult(
                'recommended',
                'Retry customer contact',
                'The latest recorded contact did not establish a completed conversation, and no next task is currently scheduled.',
                2,
                'latest_contact_outcome',
                [(int) $latest->id]
            );
        }

        if ($siteVisits->isNotEmpty() && $siteVisits->first()->client_attended) {
            return $this->actionResult(
                'recommended',
                'Record post-visit decision',
                'A customer-attended site visit exists, but there is no pending next action. Capture feedback, objection and the next commitment.',
                1,
                'attended_site_visit'
            );
        }

        return $this->actionResult(
            'recommended',
            'Review timeline and schedule next action',
            'The lead is active but has no explicit future task and no more specific deterministic action was identified. The CRM should never leave an active lead without a next step.',
            4,
            'generic_active_lead',
            $latest ? [(int) $latest->id] : []
        );
    }

    private function actionResult(
        string $type,
        string $label,
        string $reason,
        int $confidenceLevel,
        string $basis,
        array $evidenceActivityIds = [],
        ?int $followupId = null,
        ?string $scheduledFor = null
    ): array {
        $confidenceLabels = [
            1 => 'DIRECT CRM STATE',
            2 => 'HIGH CONFIDENCE',
            3 => 'EXPLICIT EVIDENCE',
            4 => 'INFERENCE · REQUIRES CONFIRMATION',
        ];

        return [
            'type' => $type,
            'label' => $label,
            'reason' => $reason,
            'followup_id' => $followupId,
            'scheduled_for' => $scheduledFor,
            'evidence_activity_ids' => array_values(array_unique(array_map('intval', $evidenceActivityIds))),
            'confidence_level' => $confidenceLevel,
            'confidence_label' => $confidenceLabels[$confidenceLevel] ?? 'EVIDENCE',
            'basis' => $basis,
        ];
    }

    /**
     * Derive a customer journey from documented CRM evidence. This is a
     * descriptive workflow position, not a prediction of customer intent.
     */
    private function journey(Lead $lead, Collection $activities, Collection $followups, Collection $siteVisits, array $facts): array
    {
        $stages = [
            'new' => 'New enquiry',
            'contact' => 'Contact established',
            'requirement' => 'Requirement discovery',
            'shortlist' => 'Project evaluation',
            'visit' => 'Site visit',
            'decision' => 'Decision / negotiation',
            'booking' => 'Booking',
        ];

        $current = 'new';
        $reason = 'The lead exists, but the available timeline does not yet document a later journey milestone.';
        $evidence = [];

        $keys = $activities->pluck('outcome_key')->map(fn ($v) => (string) $v)->all();
        $types = $activities->pluck('type')->map(fn ($v) => (string) $v)->all();
        $hasConversation = $activities->contains(fn (Activity $a) => in_array((string) $a->type, ['call', 'whatsapp', 'note'], true)
            && ! in_array((string) $a->outcome_key, ['not_answered', 'busy'], true));
        $hasRequirement = collect($facts['current'])->isNotEmpty();
        $hasInterest = $activities->contains(fn (Activity $a) => in_array((string) $a->outcome_key, ['interested', 'site_visit_scheduled', 'visit_scheduled'], true));
        $hasVisit = $siteVisits->isNotEmpty() || in_array((string) $lead->status, ['visit_scheduled', 'visit_done'], true);
        $hasDecision = in_array((string) $lead->status, ['negotiation', 'booking'], true)
            || in_array('negotiation', $keys, true);

        if ((string) $lead->status === 'booking') {
            $current = 'booking';
            $reason = 'The lead status is Booking.';
        } elseif ($hasDecision) {
            $current = 'decision';
            $reason = 'The lead status or timeline documents negotiation/decision activity.';
        } elseif ($hasVisit) {
            $current = 'visit';
            $reason = 'A site visit is scheduled or recorded in the CRM.';
            $evidence = $siteVisits->take(2)->pluck('id')->map(fn ($id) => (int) $id)->all();
        } elseif ($hasInterest) {
            $current = 'shortlist';
            $reason = 'The timeline documents interest or a site-visit progression event.';
        } elseif ($hasRequirement) {
            $current = 'requirement';
            $reason = 'Explicit customer requirement information has been captured in the CRM.';
        } elseif ($hasConversation) {
            $current = 'contact';
            $reason = 'The timeline contains a recorded interaction beyond an unanswered/busy contact attempt.';
        }

        $completedIndex = array_search($current, array_keys($stages), true);
        $completed = [];
        foreach (array_keys($stages) as $i => $key) {
            if ($i < $completedIndex) $state = 'documented';
            elseif ($i === $completedIndex) $state = 'in_progress';
            else $state = 'not_established';
            $completed[] = ['key' => $key, 'label' => $stages[$key], 'state' => $state];
        }

        return [
            'current_key' => $current,
            'current_label' => $stages[$current],
            'progress_label' => $current === 'new' ? 'Early stage' : 'In progress',
            'reason' => $reason,
            'evidence_activity_ids' => $evidence,
            'stages' => $completed,
        ];
    }

    /**
     * Enrich the operational next action with a concrete execution objective.
     * The CRM task remains authoritative; this layer answers what the agent
     * should accomplish while completing that task, using the strongest
     * documented dependency available.
     */
    private function actionObjective(
        Lead $lead,
        Collection $activities,
        Collection $siteVisits,
        array $facts,
        array $journey,
        array $decisionDependencies,
        array $nextAction
    ): array {
        if ($lead->isFinal()) {
            return [
                'title' => 'No active execution objective',
                'text' => 'The lead is in a final lifecycle state.',
                'questions' => [],
                'basis' => ['Final lifecycle state'],
                'evidence_activity_ids' => [],
                'evidence_label' => 'DIRECT CRM STATE',
            ];
        }

        $priority = [
            'post_visit' => 10,
            'competing_option' => 20,
            'visit_outcome' => 30,
            'funding' => 40,
            'information_gap' => 50,
        ];
        $dependency = collect($decisionDependencies)
            ->sortBy(fn ($item) => $priority[$item['type']] ?? 99)
            ->first();

        if ($dependency) {
            $type = (string) $dependency['type'];
            $evidence = $dependency['evidence_activity_ids'] ?? [];
            $label = (string) ($dependency['evidence_label'] ?? 'EVIDENCE');

            if ($type === 'competing_option') {
                return [
                    'title' => 'Resolve competing-project decision',
                    'text' => 'Use the scheduled interaction to confirm whether the documented alternative is still being considered, identify the deciding criteria, and record what must be resolved before the customer can decide.',
                    'questions' => [
                        'Are you currently comparing this project with the other project mentioned in our timeline?',
                        'What is the most important difference for you — price, location, possession, configuration, or something else?',
                        'What would need to be resolved for you to make a decision?',
                    ],
                    'basis' => ['Explicit competing-option evidence', 'Current CRM action: '.$nextAction['label']],
                    'evidence_activity_ids' => $evidence,
                    'evidence_label' => $label,
                ];
            }

            if ($type === 'post_visit') {
                return [
                    'title' => 'Capture post-visit decision',
                    'text' => 'Use the interaction to document what the customer liked, the main objection, and the next concrete commitment.',
                    'questions' => ['What did you like or dislike about the property?', 'What is the main concern or objection?', 'What should happen next, and when?'],
                    'basis' => ['Customer-attended site visit', 'Current CRM action: '.$nextAction['label']],
                    'evidence_activity_ids' => $evidence,
                    'evidence_label' => $label,
                ];
            }

            if ($type === 'visit_outcome') {
                return [
                    'title' => 'Confirm visit outcome',
                    'text' => 'Confirm whether the documented visit actually happened, capture the outcome, and record the next commitment.',
                    'questions' => ['Did the visit happen as planned?', 'What was the customer\'s reaction?', 'What is the next step and when should it happen?'],
                    'basis' => ['Visit progression is documented', 'Visit outcome is not yet established', 'Current CRM action: '.$nextAction['label']],
                    'evidence_activity_ids' => $evidence,
                    'evidence_label' => $label,
                ];
            }

            if ($type === 'funding') {
                return [
                    'title' => 'Confirm funding position',
                    'text' => 'Confirm how the purchase is expected to be funded and whether any financing or amount still needs to be arranged.',
                    'questions' => ['Is this purchase self-funded or will you use a home loan?', 'What amount is already available for the purchase?', 'Is there any financing step we need to plan for?'],
                    'basis' => ['Explicit funding evidence', 'Current CRM action: '.$nextAction['label']],
                    'evidence_activity_ids' => $evidence,
                    'evidence_label' => $label,
                ];
            }

            if ($type === 'information_gap') {
                $questions = [
                    'configuration' => 'Which configuration are you considering?',
                    'budget' => 'What budget range are you comfortable with?',
                    'location' => 'Which location or areas should we keep in consideration?',
                    'possession' => 'What possession timeline are you looking for?',
                    'timeline' => 'When are you planning to make the purchase or decision?',
                ];
                $gap = (string) ($dependency['gap_key'] ?? '');
                if (isset($questions[$gap])) {
                    return [
                        'title' => 'Complete requirement discovery',
                        'text' => 'Use the scheduled interaction to confirm the missing requirement before narrowing options or advancing the conversation.',
                        'questions' => [$questions[$gap]],
                        'basis' => ['Missing documented requirement: '.($dependency['title'] ?? $gap), 'Current CRM action: '.$nextAction['label']],
                        'evidence_activity_ids' => $evidence,
                        'evidence_label' => $label,
                    ];
                }
            }
        }

        return [
            'title' => 'Confirm requirement and next commitment',
            'text' => 'Complete the CRM action by verifying the current requirement and recording a concrete next commitment.',
            'questions' => ['Is the requirement still active?', 'What should we confirm or arrange next?', 'When should the next follow-up happen?'],
            'basis' => [$journey['reason'], 'Current CRM action: '.$nextAction['label']],
            'evidence_activity_ids' => $nextAction['evidence_activity_ids'] ?? [],
            'evidence_label' => $nextAction['confidence_label'] ?? 'EVIDENCE',
        ];
    }

    /**
     * Produce an interaction objective from the highest-value documented gap.
     * The objective is intentionally phrased as a CRM task, not a claim about
     * what the customer "really" wants.
     */
    private function conversationObjective(Lead $lead, Collection $activities, Collection $siteVisits, array $facts, array $journey, array $nextAction): array
    {
        $unknowns = $this->unknowns($lead, $facts['current'], $activities, $siteVisits);
        $labels = collect($unknowns)->pluck('label')->values();
        $maturity = $this->requirementMaturity($lead, $facts, $activities, $siteVisits);

        if ($lead->isFinal()) {
            return [
                'title' => 'No active conversation objective',
                'text' => 'The lead is in a final lifecycle state; no new discovery objective is generated.',
                'questions' => [],
                'basis' => [],
                'evidence_activity_ids' => [],
            ];
        }

        if ($journey['current_key'] === 'visit' && $siteVisits->isNotEmpty() && $siteVisits->first()->client_attended) {
            return [
                'title' => 'Capture post-visit decision',
                'text' => 'Document the customer\'s feedback, objection (if any), and the next commitment following the recorded visit.',
                'questions' => ['What did you like or dislike about the property?', 'What is the main concern or objection?', 'What should happen next, and when?'],
                'basis' => ['Recorded customer-attended site visit', 'Post-visit feedback is not explicitly documented'],
                'evidence_activity_ids' => [],
            ];
        }

        if ($maturity['decision_readiness']['state'] === 'comparison_ready') {
            return [
                'title' => 'Qualify project comparison and decision path',
                'text' => 'Most core requirements are documented. Use the next interaction to confirm the active comparison, decision criteria and expected decision timing.',
                'questions' => [
                    'Are you currently comparing this project with another project?',
                    'What is the most important factor in your decision — price, location, possession, configuration, or something else?',
                    'When are you planning to make the decision?',
                ],
                'basis' => ['Core requirements are substantially documented', 'Decision-readiness gaps remain'],
                'evidence_activity_ids' => $maturity['evidence_activity_ids'],
            ];
        }

        if ($labels->isNotEmpty()) {
            $first = (string) $labels->first();
            $questionMap = [
                'Preferred configuration' => 'Which configuration are you considering?',
                'Budget' => 'What budget range are you comfortable with?',
                'Preferred location' => 'Which location or areas should we keep in consideration?',
                'Possession requirement' => 'What possession timeline are you looking for?',
                'Purchase / decision timeline' => 'When are you planning to make the purchase or decision?',
            ];
            $question = $questionMap[$first] ?? 'What is the most important requirement we should confirm next?';
            return [
                'title' => 'Complete requirement discovery',
                'text' => 'Confirm the next missing customer requirement before moving the conversation forward.',
                'questions' => [$question],
                'basis' => ['Missing documented requirement: '.$first],
                'evidence_activity_ids' => $nextAction['evidence_activity_ids'] ?? [],
            ];
        }

        return [
            'title' => 'Confirm current requirement and next commitment',
            'text' => 'Use the next interaction to verify the current requirement and record a concrete follow-up commitment.',
            'questions' => ['Is the requirement still active?', 'What should we confirm or arrange next?'],
            'basis' => [$journey['reason']],
            'evidence_activity_ids' => $nextAction['evidence_activity_ids'] ?? [],
        ];
    }

    /**
     * Identify documented decision dependencies and clearly separate them from
     * facts. A dependency explains what must be resolved/confirmed before a
     * documented next milestone can safely move forward; it is never presented
     * as proof of customer intent.
     */
    private function decisionDependencies(Lead $lead, Collection $activities, Collection $siteVisits, array $facts, array $journey): array
    {
        $dependencies = [];
        $unknowns = $this->unknowns($lead, $facts['current'], $activities, $siteVisits);
        $unknownKeys = collect($unknowns)->pluck('key')->all();
        $texts = $activities->map(fn (Activity $a) => strtolower(trim((string) $a->outcome . ' ' . $a->notes)))->filter()->values();

        // Requirement gaps are direct information dependencies, not negative facts.
        $gapMap = [
            'configuration' => ['title' => 'Configuration must be confirmed', 'text' => 'A configuration is not explicitly documented yet. Confirm it before narrowing project/unit options.'],
            'budget' => ['title' => 'Budget must be confirmed', 'text' => 'A budget is not explicitly documented yet. Confirm the usable budget before presenting a final shortlist or discussing commitment.'],
            'location' => ['title' => 'Preferred location must be confirmed', 'text' => 'A preferred location is not explicitly documented yet. Confirm the acceptable area before narrowing alternatives.'],
            'possession' => ['title' => 'Possession requirement must be confirmed', 'text' => 'A possession requirement is not explicitly documented yet. Confirm the required timeline before filtering projects.'],
            'timeline' => ['title' => 'Purchase / decision timeline must be confirmed', 'text' => 'A purchase or decision timeline is not explicitly documented yet. Confirm when the customer expects to decide.'],
        ];
        foreach ($gapMap as $key => $meta) {
            if (in_array($key, $unknownKeys, true)) {
                $dependencies[] = $this->dependency('information_gap', $meta['title'], $meta['text'], 'inference', [], $key);
            }
        }

        // Competing option / existing token / booking creates an explicit decision dependency.
        $competitionIds = [];
        foreach ($activities as $activity) {
            $text = strtolower(trim((string) $activity->outcome . ' ' . $activity->notes));
            if ($text === '') continue;
            if (preg_match('/\b(token|tokened|token given|already booked|booking done|already finalize|already finali[sz]ed|other project|another project|competing project|cancel (?:the )?(?:other|existing) booking)\b/i', $text)) {
                $competitionIds[] = (int) $activity->id;
            }
        }
        if ($competitionIds) {
            $dependencies[] = $this->dependency(
                'competing_option',
                'Existing / competing option must be resolved',
                'The timeline explicitly mentions another booking, token, finalized option or competing project. The next decision depends on confirming how the current project compares with that option.',
                'explicit_text',
                array_values(array_unique($competitionIds)),
                'competing_option'
            );
        }

        // Explicit visit progression creates a dependency to confirm the visit outcome.
        $visitIds = [];
        foreach ($activities as $activity) {
            $text = strtolower(trim((string) $activity->outcome . ' ' . $activity->notes));
            if ($text !== '' && preg_match('/\b(site visit|visit|revisit|coming for (?:a )?visit|visit scheduled|vc scheduled|vc fixed)\b/i', $text)) {
                $visitIds[] = (int) $activity->id;
            }
        }
        if ($siteVisits->isNotEmpty() && $siteVisits->first()->client_attended) {
            $dependencies[] = $this->dependency(
                'post_visit',
                'Post-visit decision must be captured',
                'A customer-attended site visit is recorded. Capture likes/dislikes, objections and the next commitment before treating the visit as decision progress.',
                'structured',
                $visitIds,
                'post_visit_feedback'
            );
        } elseif ($visitIds && $journey['current_key'] === 'visit') {
            $dependencies[] = $this->dependency(
                'visit_outcome',
                'Visit outcome must be confirmed',
                'The timeline documents visit progression, but customer attendance/outcome is not yet established by a completed site-visit record.',
                'explicit_text',
                array_values(array_unique($visitIds)),
                'visit_outcome'
            );
        }

        // Explicit financing/self-funding language can identify a funding dependency
        // without assuming whether finance is actually required.
        $fundingIds = [];
        foreach ($activities as $activity) {
            $text = strtolower(trim((string) $activity->outcome . ' ' . $activity->notes));
            if ($text !== '' && preg_match('/\b(self funding|self-funded|home loan|loan|finance|financing|funding)\b/i', $text)) {
                $fundingIds[] = (int) $activity->id;
            }
        }
        if ($fundingIds) {
            $dependencies[] = $this->dependency(
                'funding',
                'Funding position should be confirmed',
                'The timeline explicitly mentions funding or financing. Confirm the usable funding structure and any amount still to be arranged before a financial commitment is discussed.',
                'explicit_text',
                array_values(array_unique($fundingIds)),
                'funding'
            );
        }

        // Avoid duplicate dependencies while keeping evidence explainable.
        $unique = [];
        foreach ($dependencies as $dependency) {
            $fingerprint = $dependency['type'].'|'.$dependency['title'];
            if (! isset($unique[$fingerprint])) {
                $unique[$fingerprint] = $dependency;
            } else {
                $unique[$fingerprint]['evidence_activity_ids'] = array_values(array_unique(array_merge(
                    $unique[$fingerprint]['evidence_activity_ids'],
                    $dependency['evidence_activity_ids']
                )));
            }
        }

        return array_values($unique);
    }

    private function dependency(string $type, string $title, string $text, string $evidenceLevel, array $activityIds = [], ?string $gapKey = null): array
    {
        $labels = [
            'structured' => 'EVIDENCE · STRUCTURED',
            'standardized_outcome' => 'EVIDENCE · STANDARDIZED OUTCOME',
            'explicit_text' => 'EVIDENCE · EXPLICIT TEXT',
            'inference' => 'INFERENCE · REQUIRES CONFIRMATION',
        ];

        return [
            'type' => $type,
            'title' => $title,
            'text' => $text,
            'evidence_level' => $evidenceLevel,
            'evidence_label' => $labels[$evidenceLevel] ?? 'EVIDENCE',
            'evidence_activity_ids' => array_values(array_unique(array_map('intval', $activityIds))),
            'gap_key' => $gapKey,
        ];
    }

    private function evidenceHierarchy(): array
    {
        return [
            ['level' => 1, 'key' => 'structured', 'label' => 'FACT · STRUCTURED', 'description' => 'Value stored directly in a structured CRM field.'],
            ['level' => 2, 'key' => 'standardized_outcome', 'label' => 'EVIDENCE · STANDARDIZED OUTCOME', 'description' => 'Explicit CRM outcome/status recorded through a controlled workflow.'],
            ['level' => 3, 'key' => 'explicit_text', 'label' => 'FACT · EXPLICIT TIMELINE', 'description' => 'Customer or staff statement explicitly recorded in timeline text.'],
            ['level' => 4, 'key' => 'inference', 'label' => 'INFERENCE · REQUIRES CONFIRMATION', 'description' => 'A deterministic interpretation derived from documented evidence; never treated as customer fact.'],
        ];
    }

    private function signal(string $severity, string $title, string $text, array $activityIds = [], ?string $at = null): array
    {
        return compact('severity', 'title', 'text', 'activityIds', 'at');
    }

    private function cleanFact(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value));
        return trim($value, " \t\n\r\0\x0B:-");
    }

    private function siteVisits(int $leadId): Collection
    {
        return collect(DB::table('site_visits')
            ->where('lead_id', $leadId)
            ->orderByDesc('visit_at')
            ->orderByDesc('id')
            ->get());
    }
}
