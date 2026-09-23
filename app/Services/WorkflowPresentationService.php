<?php

namespace App\Services;

use App\Models\UserWorkflowPresentation;
use Illuminate\Support\Collection;

class WorkflowPresentationService
{
    public const TYPE_OUTCOME = "outcome";
    public const TYPE_LOST_REASON = "lost_reason";
    public const TYPE_SITE_VISIT_OUTCOME = "site_visit_outcome";

    public function presentationsForUser(int $userId, string $optionType): Collection
    {
        return UserWorkflowPresentation::query()
            ->where("user_id", $userId)
            ->where("option_type", $optionType)
            ->get()
            ->keyBy("canonical_key");
    }

    public function presentOutcomes(Collection $outcomes, ?int $userId = null): Collection
    {
        $userId ??= (int) session("user_id");
        $overrides = $userId > 0
            ? $this->presentationsForUser($userId, self::TYPE_OUTCOME)
            : collect();

        return $outcomes
            ->map(function ($outcome) use ($overrides) {
                $override = $overrides->get($outcome->key);

                if ($override && ! $override->is_visible) {
                    return null;
                }

                return [
                    "key" => $outcome->key,
                    "canonical_label" => $outcome->label,
                    "display_label" => trim((string) ($override?->display_label ?? "")) ?: $outcome->label,
                    "category" => $outcome->category,
                    "context_action_key" => $outcome->context_action_key,
                    "activity_type_filter" => $outcome->activity_type_filter,
                    "next_action_label" => $outcome->nextActionType?->label,
                    "next_action_delay_hours" => (int) $outcome->next_action_delay_hours,
                    "suggested_status_id" => $outcome->suggested_status_id,
                    "prompts_whatsapp_send" => (bool) $outcome->prompts_whatsapp_send,
                    "is_connected" => (bool) $outcome->is_connected,
                    "requires_site_visit_datetime" => (bool) ($outcome->requires_site_visit_datetime ?? false),
                    "next_action_anchor" => (string) ($outcome->next_action_anchor ?? "now"),
                    "presentation_sort_order" => $override?->sort_order,
                    "canonical_sort_order" => (int) $outcome->sort_order,
                ];
            })
            ->filter()
            ->sortBy(function (array $row) {
                return [
                    $row["presentation_sort_order"] ?? $row["canonical_sort_order"],
                    $row["canonical_sort_order"],
                    $row["key"],
                ];
            })
            ->values();
    }

    public function presentLostReasons(?int $userId = null): array
    {
        $userId ??= (int) session("user_id");
        $overrides = $userId > 0
            ? $this->presentationsForUser($userId, self::TYPE_LOST_REASON)
            : collect();

        $presented = [];

        foreach (LeadStatusService::lostReasonOptions() as $groupKey => $options) {
            $rows = collect($options)
                ->map(function (array $option, int $index) use ($overrides, $groupKey) {
                    $override = $overrides->get($option["key"]);

                    if ($override && ! $override->is_visible) {
                        return null;
                    }

                    return [
                        "key" => $option["key"],
                        "canonical_label" => $option["label"],
                        "display_label" => trim((string) ($override?->display_label ?? "")) ?: $option["label"],
                        "group" => $groupKey,
                        "nurture_eligible" => $groupKey === "nurture",
                        "presentation_sort_order" => $override?->sort_order,
                        "canonical_sort_order" => $index,
                    ];
                })
                ->filter()
                ->sortBy(function (array $row) {
                    return [
                        $row["presentation_sort_order"] ?? $row["canonical_sort_order"],
                        $row["canonical_sort_order"],
                        $row["key"],
                    ];
                })
                ->values()
                ->all();

            $presented[$groupKey] = $rows;
        }

        return $presented;
    }

    public function displayLabelForLostReason(?string $canonicalKey, ?int $userId = null): ?string
    {
        $canonicalKey = trim((string) $canonicalKey);
        if ($canonicalKey === "") {
            return null;
        }

        $defaultLabel = LeadStatusService::lostReasonLabel($canonicalKey);
        if ($defaultLabel === null) {
            return null;
        }

        $userId ??= (int) session("user_id");
        if ($userId <= 0) {
            return $defaultLabel;
        }

        return $this->labelFor(
            $userId,
            self::TYPE_LOST_REASON,
            $canonicalKey,
            $defaultLabel,
        );
    }

    public function assertLostReasonAllowedForUser(?string $canonicalKey, ?int $userId = null): void
    {
        $canonicalKey = trim((string) $canonicalKey);
        if ($canonicalKey === "") {
            return;
        }

        if (LeadStatusService::lostReasonLabel($canonicalKey) === null) {
            throw new \DomainException("That Lost reason is not a valid canonical option.");
        }

        $userId ??= (int) session("user_id");
        if ($userId <= 0) {
            return;
        }

        $row = UserWorkflowPresentation::query()
            ->where("user_id", $userId)
            ->where("option_type", self::TYPE_LOST_REASON)
            ->where("canonical_key", $canonicalKey)
            ->first();

        if ($row && ! $row->is_visible) {
            throw new \DomainException("That Lost reason is not available for your account.");
        }
    }
    public static function siteVisitOutcomeOptions(): array
    {
        return [
            "very_interested" => "⭐ Very interested",
            "interested" => "✅ Interested",
            "wants_negotiation" => "🤝 Wants negotiation",
            "family_discussion" => "👨‍👩‍👧 Family discussion needed",
            "wants_revisit" => "🔄 Wants another visit",
            "price_issue" => "💰 Price / budget issue",
            "location_issue" => "📍 Location issue",
            "wants_other_project" => "🏗️ Prefers another project",
            "not_interested" => "❌ Not interested",
            "booked" => "🎉 Booked",
            "other" => "📝 Other",
        ];
    }

    public function presentSiteVisitOutcomes(?int $userId = null): array
    {
        $userId ??= (int) session("user_id");
        $overrides = $userId > 0
            ? $this->presentationsForUser($userId, self::TYPE_SITE_VISIT_OUTCOME)
            : collect();

        return collect(self::siteVisitOutcomeOptions())
            ->map(function (string $label, string $key) use ($overrides) {
                $override = $overrides->get($key);
                if ($override && ! $override->is_visible) {
                    return null;
                }

                return [
                    "key" => $key,
                    "canonical_label" => $label,
                    "display_label" => trim((string) ($override?->display_label ?? "")) ?: $label,
                    "presentation_sort_order" => $override?->sort_order,
                    "canonical_sort_order" => array_search($key, array_keys(self::siteVisitOutcomeOptions()), true),
                ];
            })
            ->filter()
            ->sortBy(fn (array $row) => [
                $row["presentation_sort_order"] ?? $row["canonical_sort_order"],
                $row["canonical_sort_order"],
                $row["key"],
            ])
            ->values()
            ->all();
    }

    public function assertSiteVisitOutcomeAllowedForUser(string $canonicalKey, ?int $userId = null): void
    {
        if (! array_key_exists($canonicalKey, self::siteVisitOutcomeOptions())) {
            throw new \DomainException("That site visit outcome is not a valid canonical option.");
        }

        $userId ??= (int) session("user_id");
        if ($userId <= 0) {
            return;
        }

        $row = UserWorkflowPresentation::query()
            ->where("user_id", $userId)
            ->where("option_type", self::TYPE_SITE_VISIT_OUTCOME)
            ->where("canonical_key", $canonicalKey)
            ->first();

        if ($row && ! $row->is_visible) {
            throw new \DomainException("That site visit outcome is not available for your account.");
        }
    }
    public function displayLabelForOutcome($outcome, ?int $userId = null): string
    {
        $userId ??= (int) session("user_id");
        if ($userId <= 0) {
            return (string) $outcome->label;
        }

        return $this->labelFor(
            $userId,
            self::TYPE_OUTCOME,
            (string) $outcome->key,
            (string) $outcome->label,
        );
    }

    public function assertOutcomeAllowedForUser(?string $canonicalKey, ?int $userId = null): void
    {
        $canonicalKey = trim((string) $canonicalKey);
        if ($canonicalKey === "") {
            return;
        }

        $userId ??= (int) session("user_id");
        if ($userId <= 0) {
            return;
        }

        $row = UserWorkflowPresentation::query()
            ->where("user_id", $userId)
            ->where("option_type", self::TYPE_OUTCOME)
            ->where("canonical_key", $canonicalKey)
            ->first();

        if ($row && ! $row->is_visible) {
            throw new \DomainException("That workflow outcome is not available for your account.");
        }
    }

    public function labelFor(int $userId, string $optionType, string $canonicalKey, string $defaultLabel): string
    {
        $row = UserWorkflowPresentation::query()
            ->where("user_id", $userId)
            ->where("option_type", $optionType)
            ->where("canonical_key", $canonicalKey)
            ->first();

        $label = trim((string) ($row?->display_label ?? ""));
        return $label !== "" ? $label : $defaultLabel;
    }
}
