<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadLabel;
use App\Models\LeadTag;
use Illuminate\Support\Facades\DB;

class LeadTagService
{
    /** All active tags, ordered. */
    public function tags()
    {
        return LeadTag::active()->ordered()->get();
    }

    /** All active labels, ordered, grouped by group_key. */
    public function labelsGrouped()
    {
        return LeadLabel::active()->ordered()->get()
            ->groupBy('group_key');
    }

    /** Apply a tag + full set of labels to a lead. */
    public function apply(Lead $lead, ?int $tagId, array $labelIds): void
    {
        DB::transaction(function () use ($lead, $tagId, $labelIds) {
            $lead->update(['tag_id' => $tagId]);

            $lead->labels()->sync(
                array_values(array_unique(array_map('intval', $labelIds)))
            );
        });
    }
}