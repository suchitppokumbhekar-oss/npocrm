@props([
    'name'         => 'project_id',
    'selectedId'   => null,
    'selectedName' => null,
    'required'     => false,
    'placeholder'  => 'Search project…',
    'id'           => null,

    // Filter-mode options. Existing form usages remain unchanged.
    'allowAll'     => false,
    'allLabel'     => 'All projects',
    'autoSubmit'   => false,
    'searchContext' => null,
    'searchSource'  => null,
    'searchScope'   => null,
])

@php
    $fieldId = $id ?: 'pp_' . uniqid();
@endphp

<div class="project-picker"
     data-project-picker
     data-field-id="{{ $fieldId }}"
     @if($autoSubmit) data-auto-submit="1" @endif
     @if($searchContext) data-search-context="{{ $searchContext }}" @endif
     @if($searchSource) data-search-source="{{ $searchSource }}" @endif
     @if($searchScope) data-search-scope="{{ $searchScope }}" @endif>

    <input type="hidden"
           name="{{ $name }}"
           id="{{ $fieldId }}_value"
           value="{{ $selectedId }}"
           @if($required) required @endif>

    <div class="pp-input-wrap">
        <input type="text"
               id="{{ $fieldId }}_search"
               class="input pp-search"
               placeholder="{{ $allowAll && !$selectedId ? $allLabel : $placeholder }}"
               autocomplete="off"
               spellcheck="false"
               value="{{ $selectedName }}"
               data-search-url="{{ route('projects.search') }}"
               aria-label="{{ $placeholder }}">

        <button type="button"
                class="pp-clear"
                aria-label="{{ $allowAll ? $allLabel : 'Clear project' }}">
            ✕
        </button>
    </div>

    <div class="pp-results" hidden></div>

    <p class="pp-hint muted" style="font-size:11px;margin-top:4px;">
        @if($allowAll)
            Type 2+ letters to find a project. Clear to show {{ strtolower($allLabel) }}.
        @else
            Type 2+ letters to search — e.g. <em>riv</em> for Rivali
        @endif
    </p>
</div>
