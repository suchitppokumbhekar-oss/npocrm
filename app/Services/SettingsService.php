<?php

namespace App\Services;

use App\Models\Config\ActivityType;
use App\Models\Config\CallOutcome;
use App\Models\Config\FollowupActionType;
use App\Models\Config\LeadSource;
use App\Models\Config\LeadStatus;
use App\Models\Config\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_TTL = 3600; // 1 hour

    /* ============================================================
       LEAD STATUSES
       ============================================================ */

    public function statuses(bool $activeOnly = true): Collection
    {
        $rows = Cache::remember(
            'cfg.statuses.data.' . (int) $activeOnly,
            self::CACHE_TTL,
            function () use ($activeOnly) {
                $q = LeadStatus::query()->ordered();
                if ($activeOnly) {
                    $q->active();
                }
                return $q->get()->toArray();
            }
        );

        return LeadStatus::hydrate($rows);
    }

    public function statusByKey(string $key): ?LeadStatus
    {
        return $this->statuses(false)->firstWhere('key', $key);
    }

    public function statusLabel(string $key): string
    {
        return $this->statusByKey($key)?->label ?? $key;
    }

    public function statusColor(string $key): string
    {
        return $this->statusByKey($key)?->color ?? 'blue';
    }

    public function statusIsFinal(string $key): bool
    {
        return (bool) ($this->statusByKey($key)?->is_final ?? false);
    }
    
        public function statusTerminalType(string $key): ?string
    {
        return $this->statusByKey($key)?->terminal_type;
    }

    public function statusIsWon(string $key): bool
    {
        return $this->statusTerminalType($key) === 'won';
    }

    public function statusIsLost(string $key): bool
    {
        return $this->statusTerminalType($key) === 'lost';
    }
    

    public function allowedTransitionsFrom(string $fromKey): array
    {
        // Transitions are intentionally read fresh. Admin changes made in
        // phpMyAdmin must be effective on the next request without a cache flush.
        $from = LeadStatus::query()->where('key', $fromKey)->first();
        if (! $from) return [];
        return $from->allowedTransitions()->pluck('key')->all();
    }

    /* ============================================================
       ACTIVITY TYPES
       ============================================================ */

    public function activityTypes(bool $activeOnly = true, bool $manualOnly = false): Collection
    {
        $cacheKey = 'cfg.activity_types.data.' . (int) $activeOnly . '.' . (int) $manualOnly;

        $rows = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($activeOnly, $manualOnly) {
            $q = ActivityType::query()->ordered();
            if ($activeOnly) {
                $q->active();
            }
            if ($manualOnly) {
                $q->manual();
            }
            return $q->get()->toArray();
        });

        return ActivityType::hydrate($rows);
    }

    public function activityTypeByKey(string $key): ?ActivityType
    {
        return $this->activityTypes(false)->firstWhere('key', $key);
    }

    public function activityTypeRequiresOutcome(string $key): bool
    {
        return (bool) ($this->activityTypeByKey($key)?->requires_outcome ?? false);
    }

    /* ============================================================
       FOLLOWUP ACTION TYPES
       ============================================================ */

    public function actionTypes(bool $activeOnly = true): Collection
    {
        $rows = Cache::remember(
            'cfg.action_types.data.' . (int) $activeOnly,
            self::CACHE_TTL,
            function () use ($activeOnly) {
                $q = FollowupActionType::query()->ordered();
                if ($activeOnly) {
                    $q->active();
                }
                return $q->get()->toArray();
            }
        );

        return FollowupActionType::hydrate($rows);
    }

    public function actionTypeByKey(string $key): ?FollowupActionType
    {
        return $this->actionTypes(false)->firstWhere('key', $key);
    }

    /* ============================================================
       CALL OUTCOMES
       ============================================================ */

    public function callOutcomes(bool $activeOnly = true): Collection
    {
        $rows = Cache::remember(
            'cfg.outcomes.data.' . (int) $activeOnly,
            self::CACHE_TTL,
            function () use ($activeOnly) {
                $q = CallOutcome::query()->with(['nextActionType', 'suggestedStatus'])->ordered();
                if ($activeOnly) {
                    $q->active();
                }
                return $q->get()->toArray();
            }
        );

        return CallOutcome::hydrate($rows);
    }
    
        public function callOutcomesForContext(?string $contextActionKey): \Illuminate\Support\Collection
    {
        return $this->callOutcomes(true)
            ->filter(function ($o) use ($contextActionKey) {
                // Include if global (no context) OR matches the given context
                if (empty($o->context_action_key)) return true;
                return $o->context_action_key === $contextActionKey;
            })
            ->values();
    }

    /**
     * Contact work uses a strict hierarchy so the result list changes with the
     * work being done instead of presenting one universal outcome catalogue.
     *
     * Specificity (highest first): action + Contact status, action, channel +
     * status, channel, global. Once a more-specific layer exists, broader
     * unrelated outcomes are not shown. This keeps the visible choices small
     * while preserving a safe fallback for legacy configuration.
     */
    public function callOutcomesForContactWork(\App\Models\Contact $contact, string $actionKey): \Illuminate\Support\Collection
    {
        $all = $this->callOutcomes(true);
        $status = (string) ($contact->status ?? '');
        $channel = $this->contactActionChannel($actionKey);

        $eligible = $all->filter(function ($o) use ($channel) {
            $filter = trim((string) ($o->activity_type_filter ?? ''));
            if ($filter === '') return true;
            return in_array($channel, array_map('trim', explode(',', $filter)), true);
        })->values();

        $score = function ($o) use ($actionKey, $status, $channel) {
            $context = trim((string) ($o->context_action_key ?? ''));
            $statusContext = trim((string) ($o->contact_status_key ?? ''));
            $actionMatch = $context !== '' && $context === $actionKey;
            $channelMatch = $context !== '' && $context === $channel;
            $statusMatch = $statusContext !== '' && $statusContext === $status;
            if ($actionMatch && $statusMatch) return 400;
            if ($actionMatch && $statusContext === '') return 300;
            if ($channelMatch && $statusMatch) return 200;
            if ($channelMatch && $statusContext === '') return 150;
            if ($context === '' && $statusMatch) return 100;
            if ($context === '' && $statusContext === '') return 50;
            return 0;
        };

        $scored = $eligible->map(function ($o) use ($score) {
            $o->contact_work_specificity = $score($o);
            return $o;
        })->filter(fn ($o) => $o->contact_work_specificity > 0)->values();

        $maxLayer = $scored->max('contact_work_specificity') ?? 0;
        if ($maxLayer >= 400) {
            return $scored->filter(fn ($o) => $o->contact_work_specificity >= 300)->values();
        }
        if ($maxLayer >= 300) {
            return $scored->filter(fn ($o) => $o->contact_work_specificity >= 300)->values();
        }
        if ($maxLayer >= 200) {
            return $scored->filter(fn ($o) => $o->contact_work_specificity >= 200)->values();
        }
        if ($maxLayer >= 150) {
            return $scored->filter(fn ($o) => $o->contact_work_specificity >= 150)->values();
        }
        if ($maxLayer >= 100) {
            return $scored->filter(fn ($o) => $o->contact_work_specificity >= 100)->values();
        }
        return $scored->filter(fn ($o) => $o->contact_work_specificity === 50)->values();
    }

    public function contactActionChannel(string $actionKey): string
    {
        if (str_contains($actionKey, 'whatsapp')) return 'whatsapp';
        if (str_contains($actionKey, 'email')) return 'email';
        if (str_contains($actionKey, 'site_visit')) return 'site_visit';
        if (in_array($actionKey, ['send_details','send_brochure','send_budget_options','send_location_opts'], true)) return 'whatsapp';
        if (in_array($actionKey, ['check_shared_agent','check_site_team'], true)) return 'report';
        if (in_array($actionKey, ['call','followup_call','retry_call','post_visit_call','negotiation_followup','confirm_site_visit','visit_reminder','visit_feedback_call','visit_outcome_call','booking_confirmation','thank_you_call','reactivation_call','verify_contact'], true)) return 'call';
        return 'other';
    }
        /**
     * Return outcomes filtered by:
     *   - task context (context_action_key matches OR is null)
     *   - activity type (activity_type_filter matches OR is null)
     */
    public function callOutcomesForTaskAndType(?string $taskContext, ?string $activityType): \Illuminate\Support\Collection
    {
        return $this->callOutcomes(true)
            ->filter(function ($o) use ($taskContext, $activityType) {
                // Task filter
                if (! empty($o->context_action_key) && $o->context_action_key !== $taskContext) {
                    return false;
                }
                // Activity type filter may contain multiple comma-separated canonical types.
                if (! empty($o->activity_type_filter)) {
                    $allowedActivityTypes = collect(explode(',', (string) $o->activity_type_filter))
                        ->map(fn ($type) => trim($type))
                        ->filter()
                        ->values()
                        ->all();

                    if (! in_array((string) $activityType, $allowedActivityTypes, true)) {
                        return false;
                    }
                }
                return true;
            })
            ->values();
    }
    public function callOutcomeByKey(string $key): ?CallOutcome
    {
        return $this->callOutcomes(false)->firstWhere('key', $key);
    }

    /* ============================================================
       LEAD SOURCES
       ============================================================ */

    public function sources(bool $activeOnly = true): Collection
    {
        $rows = Cache::remember(
            'cfg.sources.data.' . (int) $activeOnly,
            self::CACHE_TTL,
            function () use ($activeOnly) {
                $q = LeadSource::query()->ordered();
                if ($activeOnly) {
                    $q->active();
                }
                return $q->get()->toArray();
            }
        );

        return LeadSource::hydrate($rows);
    }

    /* ============================================================
       GENERIC SETTINGS (key-value)
       ============================================================ */

    public function get(string $key, $default = null)
    {
        return Cache::remember(
            'cfg.setting.' . $key,
            self::CACHE_TTL,
            function () use ($key, $default) {
                return Setting::get($key, $default);
            }
        );
    }

    public function set(string $key, $value): void
    {
        Setting::put($key, $value);
        Cache::forget('cfg.setting.' . $key);
    }

    /* ============================================================
       CACHE MANAGEMENT
       ============================================================ */

    public function flush(): void
    {
        // Flush list caches
        foreach (['statuses', 'activity_types', 'action_types', 'outcomes', 'sources'] as $prefix) {
            Cache::forget('cfg.' . $prefix . '.data.0');
            Cache::forget('cfg.' . $prefix . '.data.1');
        }

        // Flush per-status transition caches
        $statusKeys = LeadStatus::pluck('key');
        foreach ($statusKeys as $k) {
            Cache::forget('cfg.transitions.' . $k);
        }

        // Flush generic settings
        $settingKeys = Setting::pluck('key');
        foreach ($settingKeys as $k) {
            Cache::forget('cfg.setting.' . $k);
        }
    }
}