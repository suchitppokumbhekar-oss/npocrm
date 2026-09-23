<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserWorkflowPresentation;
use App\Services\SettingsService;
use App\Services\SuperAdminService;
use App\Services\WorkflowPresentationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkflowVocabularyController extends Controller
{
    public function __construct(
        private SuperAdminService $superAdmins,
        private SettingsService $settings,
        private WorkflowPresentationService $presentations,
    ) {}

    public function edit(int $userId)
    {
        $this->superAdmins->requireSuperAdmin();

        $user = User::query()
            ->with("agent")
            ->findOrFail($userId);

        abort_unless($user->agent, 404);

        $outcomes = $this->settings->callOutcomes(false);
        $outcomeOverrides = $this->presentations
            ->presentationsForUser($user->id, WorkflowPresentationService::TYPE_OUTCOME);

        $lostReasons = \App\Services\LeadStatusService::lostReasonOptions();
        $lostReasonOverrides = $this->presentations
            ->presentationsForUser($user->id, WorkflowPresentationService::TYPE_LOST_REASON);

        $siteVisitOutcomes = WorkflowPresentationService::siteVisitOutcomeOptions();
        $siteVisitOutcomeOverrides = $this->presentations
            ->presentationsForUser($user->id, WorkflowPresentationService::TYPE_SITE_VISIT_OUTCOME);

        $activityTypes = $this->settings->activityTypes(false, true);
        $activityTypeOverrides = $this->presentations
            ->presentationsForUser($user->id, WorkflowPresentationService::TYPE_ACTIVITY_TYPE);

        $followupActionTypes = $this->settings->actionTypes(false);
        $followupActionTypeOverrides = $this->presentations
            ->presentationsForUser($user->id, WorkflowPresentationService::TYPE_FOLLOWUP_ACTION_TYPE);

        return view("settings.workflow-vocabulary", compact(
            "user",
            "outcomes",
            "outcomeOverrides",
            "lostReasons",
            "lostReasonOverrides",
            "siteVisitOutcomes",
            "siteVisitOutcomeOverrides",
            "activityTypes",
            "activityTypeOverrides",
            "followupActionTypes",
            "followupActionTypeOverrides",
        ));
    }

    public function save(Request $request, int $userId)
    {
        $this->superAdmins->requireSuperAdmin();

        $user = User::query()
            ->with("agent")
            ->findOrFail($userId);

        abort_unless($user->agent, 404);

        $validated = $request->validate([
            "outcomes" => ["nullable", "array"],
            "outcomes.*.display_label" => ["nullable", "string", "max:150"],
            "outcomes.*.is_visible" => ["nullable", "boolean"],
            "outcomes.*.sort_order" => ["nullable", "integer", "min:0", "max:100000"],
            "lost_reasons" => ["nullable", "array"],
            "lost_reasons.*.display_label" => ["nullable", "string", "max:150"],
            "lost_reasons.*.is_visible" => ["nullable", "boolean"],
            "lost_reasons.*.sort_order" => ["nullable", "integer", "min:0", "max:100000"],
            "site_visit_outcomes" => ["nullable", "array"],
            "site_visit_outcomes.*.display_label" => ["nullable", "string", "max:150"],
            "site_visit_outcomes.*.is_visible" => ["nullable", "boolean"],
            "site_visit_outcomes.*.sort_order" => ["nullable", "integer", "min:0", "max:100000"],
            "activity_types" => ["nullable", "array"],
            "activity_types.*.display_label" => ["nullable", "string", "max:150"],
            "activity_types.*.is_visible" => ["nullable", "boolean"],
            "activity_types.*.sort_order" => ["nullable", "integer", "min:0", "max:100000"],
            "followup_action_types" => ["nullable", "array"],
            "followup_action_types.*.display_label" => ["nullable", "string", "max:150"],
            "followup_action_types.*.is_visible" => ["nullable", "boolean"],
            "followup_action_types.*.sort_order" => ["nullable", "integer", "min:0", "max:100000"],
        ]);

        $canonicalOutcomes = $this->settings
            ->callOutcomes(false)
            ->keyBy("key");

        $canonicalLostReasons = collect(\App\Services\LeadStatusService::lostReasonOptions())
            ->flatten(1)
            ->keyBy("key");

        $canonicalSiteVisitOutcomes = collect(WorkflowPresentationService::siteVisitOutcomeOptions());

        $canonicalActivityTypes = $this->settings->activityTypes(false, true)->keyBy("key");
        $canonicalFollowupActionTypes = $this->settings->actionTypes(false)->keyBy("key");

        DB::transaction(function () use (
            $validated,
            $canonicalOutcomes,
            $canonicalLostReasons,
            $canonicalSiteVisitOutcomes,
            $canonicalActivityTypes,
            $canonicalFollowupActionTypes,
            $user
        ) {
            $this->savePresentationRows(
                $user->id,
                WorkflowPresentationService::TYPE_OUTCOME,
                $validated["outcomes"] ?? [],
                $canonicalOutcomes,
                "Unknown canonical workflow outcome.",
            );

            $this->savePresentationRows(
                $user->id,
                WorkflowPresentationService::TYPE_LOST_REASON,
                $validated["lost_reasons"] ?? [],
                $canonicalLostReasons,
                "Unknown canonical Lost reason.",
            );

            $this->savePresentationRows(
                $user->id,
                WorkflowPresentationService::TYPE_SITE_VISIT_OUTCOME,
                $validated["site_visit_outcomes"] ?? [],
                $canonicalSiteVisitOutcomes,
                "Unknown canonical site visit outcome.",
            );

            $this->savePresentationRows(
                $user->id,
                WorkflowPresentationService::TYPE_ACTIVITY_TYPE,
                $validated["activity_types"] ?? [],
                $canonicalActivityTypes,
                "Unknown canonical activity type.",
            );

            $this->savePresentationRows(
                $user->id,
                WorkflowPresentationService::TYPE_FOLLOWUP_ACTION_TYPE,
                $validated["followup_action_types"] ?? [],
                $canonicalFollowupActionTypes,
                "Unknown canonical follow-up action type.",
            );
        });

        return back()->with("success", "Workflow vocabulary updated for ".$user->name.".");
    }

    private function savePresentationRows(
        int $userId,
        string $optionType,
        array $submitted,
        \Illuminate\Support\Collection $canonical,
        string $unknownMessage,
    ): void {
        foreach ($submitted as $key => $values) {
            if (! $canonical->has($key)) {
                abort(422, $unknownMessage);
            }

            $label = trim((string) ($values["display_label"] ?? ""));
            $visible = array_key_exists("is_visible", $values)
                ? (bool) $values["is_visible"]
                : false;
            $sortOrder = $values["sort_order"] ?? null;

            $hasOverride = $label !== ""
                || ! $visible
                || $sortOrder !== null;

            if (! $hasOverride) {
                UserWorkflowPresentation::query()
                    ->where("user_id", $userId)
                    ->where("option_type", $optionType)
                    ->where("canonical_key", $key)
                    ->delete();

                continue;
            }

            UserWorkflowPresentation::query()->updateOrCreate(
                [
                    "user_id" => $userId,
                    "option_type" => $optionType,
                    "canonical_key" => $key,
                ],
                [
                    "display_label" => $label !== "" ? $label : null,
                    "is_visible" => $visible,
                    "sort_order" => $sortOrder,
                ],
            );
        }
    }
}
