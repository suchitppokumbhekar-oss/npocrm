@php
    $tagSvc     = app(\App\Services\LeadTagService::class);
    $allTags    = $tagSvc->tags();
    $allGroups  = $tagSvc->labelsGrouped();

    $currentTagId    = $lead->tag_id ?? null;
    $currentLabelIds = $lead->labels->pluck('id')->all();
@endphp

<form method="POST" action="/leads/tags/save" data-ajax id="tags-form">
    @csrf
    <input type="hidden" name="lead_id" value="{{ $lead->id }}">

    <p class="muted" style="margin-bottom:12px;">
        Tags &amp; labels for <strong>{{ $lead->customer_name }}</strong>
    </p>

    {{-- TAG (single-select) --}}
    <div class="field">
        <label style="font-weight:700;">🏷️ Tag <span class="muted" style="font-weight:400;">— pick one</span></label>

        <div class="tag-picker">
            <label class="tag-option tag-none {{ $currentTagId ? '' : 'is-selected' }}">
                <input type="radio" name="tag_id" value="" @checked(! $currentTagId)>
                <span>— Clear</span>
            </label>

            @foreach ($allTags as $t)
                <label class="tag-option tag-{{ $t->color }} {{ (int) $currentTagId === $t->id ? 'is-selected' : '' }}">
                    <input type="radio" name="tag_id" value="{{ $t->id }}"
                           @checked((int) $currentTagId === $t->id)>
                    <span>{{ $t->icon }} {{ $t->label }}</span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- LABELS (multi-select, grouped) --}}
    <div class="field">
        <label style="font-weight:700;">
            📌 Labels
            <span class="muted" style="font-weight:400;">— pick as many as apply</span>
        </label>

        @foreach ($allGroups as $groupKey => $labels)
            <details class="label-group">
                <summary class="label-group-head">
                    <span class="lg-arrow">▸</span>
                    <span class="lg-title">{{ $labels->first()->group_label }}</span>
                    <span class="lg-count">
                        {{ $labels->whereIn('id', $currentLabelIds)->count() ?: '' }}
                    </span>
                </summary>
                <div class="label-group-body">
                    @foreach ($labels as $lbl)
                        <label class="label-option">
                            <input type="checkbox" name="label_ids[]" value="{{ $lbl->id }}"
                                   @checked(in_array($lbl->id, $currentLabelIds, true))>
                            <span>{{ $lbl->label }}</span>
                        </label>
                    @endforeach
                </div>
            </details>
        @endforeach
    </div>

    <button type="submit" class="btn btn-block">💾 Save Tags &amp; Labels</button>
</form>