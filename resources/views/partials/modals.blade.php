{{-- Single modal host for the whole app. JS fills it. --}}
<div id="modal-root" class="modal-backdrop" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <header class="modal-header">
            <h3 id="modal-title">Loading…</h3>
            <button type="button" class="modal-close" aria-label="Close">✕</button>
        </header>
        <div id="modal-body" class="modal-body"></div>
    </div>
</div>