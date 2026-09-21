@props(['tag' => null, 'clickable' => true])

@if ($tag)
    @if ($clickable)
        <a href="{{ url('/leads?tag_id=' . $tag->id) }}"
           class="lead-tag-chip tag-{{ $tag->color }}"
           title="See all leads tagged {{ $tag->label }}">
            {{ $tag->icon }} {{ $tag->label }}
        </a>
    @else
        <span class="lead-tag-chip tag-{{ $tag->color }}"
              title="Tagged {{ $tag->label }}">
            {{ $tag->icon }} {{ $tag->label }}
        </span>
    @endif
@endif