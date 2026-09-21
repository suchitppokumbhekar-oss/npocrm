@props(['lead','currentFollowup'=>null])


{{-- Lead Directory is a navigation surface, not the work surface.
     All lead work happens on the Lead page's canonical work area. --}}
<div class="lead-work-actions lead-directory-action">
    <a href="{{ url('/leads/' . $lead->id) }}#pending-tasks"
       class="lead-work-primary"
       aria-label="Open and work {{ $lead->customer_name }}">
        <span aria-hidden="true">{{ $lead->isLost() || $lead->isWon() ? '👁' : '▶' }}</span>
        <span>{{ $lead->isLost() || $lead->isWon() ? 'Open' : 'Work Lead' }}</span>
    </a>
</div>
