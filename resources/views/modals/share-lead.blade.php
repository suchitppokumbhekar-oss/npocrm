@php
    $lead = \App\Models\Lead::with('project')->findOrFail(request('lead'));

    $lines = [];
    $lines[] = "🔔 *New Lead*";
    $lines[] = "";
    $lines[] = "👤 *{$lead->customer_name}*";
    $lines[] = "📱 {$lead->phone}";
    if ($lead->email)          $lines[] = "✉️ {$lead->email}";
    if ($lead->project?->name) $lines[] = "🏗️ {$lead->project->name}";

    $message = implode("\n", $lines);
@endphp

<form id="share-lead-form" onsubmit="return false;">
    <p class="muted" style="margin-bottom:var(--s-3);font-size:13px;">
        Send <strong>{{ $lead->customer_name }}</strong>'s details to any WhatsApp number,
        or leave the number blank to open WhatsApp's contact/group picker.
    </p>

    <div class="field">
        <label>Send to (phone number — optional)</label>
        <input type="tel" name="phone" class="input"
               placeholder="e.g. 9876543210 or +91 98765 43210"
               inputmode="tel" autocomplete="off">
        <p class="muted" style="font-size:11px;margin-top:4px;">
            Leave empty to pick a contact or group from WhatsApp directly.
        </p>
    </div>

    <div class="field">
        <label>Message preview (editable)</label>
        <textarea name="message" id="share-msg" rows="8" class="input"
                  style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;">{{ $message }}</textarea>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" id="share-wa-btn" class="btn btn-block"
                style="background:#25D366;color:#fff;">
            💬 Open WhatsApp
        </button>
    </div>

    <div style="margin-top:var(--s-2);display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" id="share-copy-msg" class="btn-small btn-ghost"
                style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
            📋 Copy full message
        </button>
    </div>
</form>

<script>
(function () {
    var phoneIn = document.querySelector('#share-lead-form [name="phone"]');
    var msgIn   = document.getElementById('share-msg');
    var waBtn   = document.getElementById('share-wa-btn');
    var copyMsg = document.getElementById('share-copy-msg');

    if (!waBtn) return;

    waBtn.addEventListener('click', function () {
        var phone = (phoneIn.value || '').replace(/\D/g, '');
        var text  = msgIn.value || '';

                if (!window.NpoWhatsApp || typeof window.NpoWhatsApp.choose !== 'function') {
            alert('WhatsApp identity chooser is not available. Please refresh the CRM page and try again.');
            return;
        }
        window.NpoWhatsApp.choose(phone, text);
    });

    copyMsg.addEventListener('click', function () {
        var original = this.textContent;
        var btn = this;
        var done = function () {
            btn.textContent = '✅ Copied!';
            setTimeout(function () { btn.textContent = original; }, 1500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(msgIn.value).then(done).catch(function () { alert('Copy failed'); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = msgIn.value;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch (e) { alert('Copy failed'); }
            document.body.removeChild(ta);
        }
    });
})();
</script>