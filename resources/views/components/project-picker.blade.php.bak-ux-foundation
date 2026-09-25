@props([
    'name'         => 'project_id',
    'selectedId'   => null,
    'selectedName' => null,
    'required'     => false,
    'placeholder'  => 'Search project…',
    'id'           => null,
])

@php
    $fieldId = $id ?: 'pp_' . uniqid();
@endphp

<div class="project-picker" data-project-picker data-field-id="{{ $fieldId }}">
    <input type="hidden"
           name="{{ $name }}"
           id="{{ $fieldId }}_value"
           value="{{ $selectedId }}"
           @if($required) required @endif>

    <div class="pp-input-wrap">
        <input type="text"
               id="{{ $fieldId }}_search"
               class="input pp-search"
               placeholder="{{ $placeholder }}"
               autocomplete="off"
               spellcheck="false"
               value="{{ $selectedName }}"
               data-search-url="{{ route('projects.search') }}">
        <button type="button" class="pp-clear" aria-label="Clear">✕</button>
    </div>

    <div class="pp-results" hidden></div>

    <p class="pp-hint muted" style="font-size:11px;margin-top:4px;">
        Type 2+ letters to search — e.g. <em>riv</em> for Rivali
    </p>
</div>