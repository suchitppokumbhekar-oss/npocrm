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

        return view("settings.workflow-vocabulary", compact(
            "user",
            "outcomes",
            "outcomeOverrides",
            "lostReasons",
            "lostReasonOverrides",
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
        ]);

        $canonicalOutcomes = $this->settings
            ->callOutcomes(false)
            ->keyBy("key");

        $canonicalLostReasons = collect(\App\Services\LeadStatusService::lostReasonOptions())
            ->flatten(1)
            ->keyBy("key");

        DB::transaction(function () use (
            $validated,
            $canonicalOutcomes,
            $canonicalLostReasons,
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
