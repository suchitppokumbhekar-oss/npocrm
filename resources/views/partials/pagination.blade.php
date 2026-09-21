{{-- ============================================================
     REUSABLE PAGINATION
     Usage: @include('partials.pagination', ['paginator' => $items])
     ============================================================ --}}
@if (isset($paginator) && $paginator instanceof \Illuminate\Contracts\Pagination\Paginator)
    @if ($paginator->hasPages() || $paginator->total() > $paginator->perPage())
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:var(--s-3);gap:var(--s-2);flex-wrap:wrap;">

            <div class="muted" style="font-size:12px;">
                @if ($paginator->total() > 0)
                    Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
                    of {{ number_format($paginator->total()) }}
                @else
                    No results
                @endif
                @if ($paginator->hasPages())
                    · Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
                @endif
            </div>

            @if ($paginator->hasPages())
                <div style="display:flex;gap:6px;flex-wrap:wrap;">

                    {{-- Prev --}}
                    @if ($paginator->onFirstPage())
                        <span class="btn-small" style="opacity:.4;cursor:not-allowed;">← Prev</span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" class="btn-small"
                           style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                            ← Prev
                        </a>
                    @endif

                    {{-- Windowed pages --}}
                    @php
                        $start = max(1, $paginator->currentPage() - 2);
                        $end   = min($paginator->lastPage(), $paginator->currentPage() + 2);
                    @endphp

                    @if ($start > 1)
                        <a href="{{ $paginator->url(1) }}" class="btn-small"
                           style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">1</a>
                        @if ($start > 2)
                            <span class="muted" style="align-self:center;">…</span>
                        @endif
                    @endif

                    @for ($p = $start; $p <= $end; $p++)
                        @if ($p == $paginator->currentPage())
                            <span class="btn-small" style="background:var(--c-primary);color:#fff;">{{ $p }}</span>
                        @else
                            <a href="{{ $paginator->url($p) }}" class="btn-small"
                               style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                                {{ $p }}
                            </a>
                        @endif
                    @endfor

                    @if ($end < $paginator->lastPage())
                        @if ($end < $paginator->lastPage() - 1)
                            <span class="muted" style="align-self:center;">…</span>
                        @endif
                        <a href="{{ $paginator->url($paginator->lastPage()) }}" class="btn-small"
                           style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                            {{ $paginator->lastPage() }}
                        </a>
                    @endif

                    {{-- Next --}}
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" class="btn-small"
                           style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                            Next →
                        </a>
                    @else
                        <span class="btn-small" style="opacity:.4;cursor:not-allowed;">Next →</span>
                    @endif
                </div>
            @endif

        </div>
    @endif
@endif