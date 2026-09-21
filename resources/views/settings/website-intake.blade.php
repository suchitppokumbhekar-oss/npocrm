@extends('layouts.app')

@section('title', 'Website Intake — NPO CRM')

@section('content')

    <a href="{{ url('/settings') }}" class="back-link">← Back to Settings</a>

    <div class="card">
        <h2>🌐 Website Lead Intake</h2>
        <p class="muted" style="font-size:13px;">
            Send leads from your website directly into the CRM. Auto-assigned to the least-loaded agent with a first-contact follow-up.
        </p>
    </div>

    {{-- ============================================================ --}}
    {{-- SETTINGS --}}
    {{-- ============================================================ --}}
    <div class="card">
        <h3>⚙️ Settings</h3>

        <form method="POST" action="{{ url('/settings/website-intake') }}">
            @csrf

            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="enabled" value="1" @checked($enabled)
                           style="width:auto;min-height:auto;">
                    <span><strong>Enable website intake</strong></span>
                </label>
                <p class="muted" style="font-size:11px;margin-left:26px;">
                    When off, the endpoint returns 503 and no leads are created.
                </p>
            </div>

            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="require_api_key" value="1" @checked($requireKey)
                           style="width:auto;min-height:auto;">
                    <span><strong>Require API key</strong></span>
                </label>
                <p class="muted" style="font-size:11px;margin-left:26px;">
                    Recommended for production. Website must send <code>X-API-Key</code> header (or <code>api_key</code> POST field).
                    When off, anyone can POST to the endpoint (still rate-limited).
                </p>
            </div>

            <div class="flex">
                <div class="flex-item">
                    <label>Default Source</label>
                    <select name="default_source" class="input">
                        @foreach ($sources as $src)
                            <option value="{{ $src->key }}" @selected($defaultSource === $src->key)>{{ $src->label }}</option>
                        @endforeach
                    </select>
                    <p class="muted" style="font-size:11px;">Applied when the form doesn't send its own <code>source</code>.</p>
                </div>
                <div class="flex-item">
                    <label>Rate Limit (submissions per minute, per IP)</label>
                    <input type="number" name="rate_per_minute" class="input" min="1" max="60" value="{{ $rateLimit }}">
                </div>
            </div>

            <button type="submit" class="btn">💾 Save Settings</button>
        </form>
    </div>

    {{-- ============================================================ --}}
    {{-- API KEY --}}
    {{-- ============================================================ --}}
    <div class="card">
        <h3>🔑 API Key</h3>
        <p class="muted" style="font-size:12px;">
            Only required if "Require API key" is on. Keep this secret — don't expose it in client-side JavaScript if you can avoid it.
        </p>

        <div style="background:var(--c-surface-2);padding:var(--s-3);border-radius:8px;font-family:monospace;font-size:13px;word-break:break-all;margin:var(--s-3) 0;">
            {{ $apiKey ?: '— not set —' }}
        </div>

        <form method="POST" action="{{ url('/settings/website-intake/regenerate') }}"
              onsubmit="return confirm('Regenerate the API key? Your website will stop working until you update it.');">
            @csrf
            <button type="submit" class="btn btn-ghost"
                    style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                🔄 Regenerate Key
            </button>
        </form>
    </div>

    {{-- ============================================================ --}}
    {{-- ENDPOINT --}}
    {{-- ============================================================ --}}
    <div class="card">
        <h3>🔗 Endpoint</h3>
        <p class="muted" style="font-size:12px;">POST to this URL from your website form.</p>

        <div style="background:var(--c-surface-2);padding:var(--s-3);border-radius:8px;font-family:monospace;font-size:13px;word-break:break-all;margin:var(--s-2) 0;">
            {{ $endpoint }}
        </div>

        <p class="muted" style="font-size:12px;margin-top:var(--s-2);">
            <strong>Accepted fields:</strong>
            <code>name*</code>, <code>phone*</code>, <code>email</code>, <code>budget</code>,
            <code>project</code> (name), <code>project_id</code>, <code>source</code>, <code>message</code>.
        </p>
    </div>

    {{-- ============================================================ --}}
    {{-- SNIPPET 1 — PLAIN HTML FORM --}}
    {{-- ============================================================ --}}
    <div class="card">
        <h3>📝 Copy-Paste #1 — Plain HTML Form</h3>
        <p class="muted" style="font-size:12px;">
            Works without JavaScript. Visitors land back on your site after submission.
        </p>

        <textarea readonly rows="15" class="input" style="font-family:monospace;font-size:12px;"
                  onclick="this.select();">@verbatim
<form method="POST" action="@endverbatim{{ $endpoint }}@verbatim">
  <input type="text" name="name" placeholder="Your name" required>
  <input type="tel" name="phone" placeholder="10-digit mobile" required>
  <input type="email" name="email" placeholder="Email (optional)">

  <!-- Honeypot: leave empty. Real users won't see it. -->
  <input type="text" name="website_url" style="position:absolute;left:-9999px;" tabindex="-1" autocomplete="off">

  <textarea name="message" placeholder="Which project / area are you interested in?"></textarea>

  <button type="submit">Send enquiry</button>
</form>@endverbatim</textarea>

        <p class="muted" style="font-size:11px;margin-top:6px;">
            Click the box → Ctrl+A → Ctrl+C to copy.
        </p>
    </div>

    {{-- ============================================================ --}}
    {{-- SNIPPET 2 — AJAX FORM --}}
    {{-- ============================================================ --}}
    <div class="card">
        <h3>⚡ Copy-Paste #2 — AJAX Form (recommended)</h3>
        <p class="muted" style="font-size:12px;">
            No page reload. Shows a success message inline.
        </p>

        <textarea readonly rows="24" class="input" style="font-family:monospace;font-size:12px;"
                  onclick="this.select();">@verbatim
<form id="crm-lead-form">
  <input type="text" name="name" placeholder="Your name" required>
  <input type="tel" name="phone" placeholder="10-digit mobile" required>
  <input type="email" name="email" placeholder="Email (optional)">

  <!-- Honeypot -->
  <input type="text" name="website_url" style="position:absolute;left:-9999px;" tabindex="-1" autocomplete="off">

  <textarea name="message" placeholder="Which project / area are you interested in?"></textarea>

  <button type="submit">Send enquiry</button>
  <p id="crm-lead-status" style="margin-top:8px;"></p>
</form>

<script>
document.getElementById('crm-lead-form').addEventListener('submit', async function (e) {
  e.preventDefault();
  const form = e.target;
  const status = document.getElementById('crm-lead-status');
  status.textContent = 'Sending…';
  status.style.color = '#555';

  const formData = new FormData(form);

  try {
    const res = await fetch('@endverbatim{{ $endpoint }}@verbatim', {
      method: 'POST',
      headers: { 'Accept': 'application/json' @endverbatim{{ $requireKey ? ", 'X-API-Key': '{$apiKey}'" : '' }}@verbatim },
      body: formData
    });
    const data = await res.json();
    if (data.success) {
      status.textContent = data.message || 'Thanks! We\'ll be in touch.';
      status.style.color = '#27ae60';
      form.reset();
    } else {
      status.textContent = data.message || 'Something went wrong.';
      status.style.color = '#e74c3c';
    }
  } catch (err) {
    status.textContent = 'Network error. Please try again.';
    status.style.color = '#e74c3c';
  }
});
</script>@endverbatim</textarea>
    </div>

    {{-- ============================================================ --}}
    {{-- SNIPPET 3 — CURL TEST --}}
    {{-- ============================================================ --}}
    <div class="card">
        <h3>🧪 Copy-Paste #3 — Server-Side (curl)</h3>
        <p class="muted" style="font-size:12px;">
            Use this to test from your terminal or from a server-to-server integration.
        </p>

        <textarea readonly rows="10" class="input" style="font-family:monospace;font-size:12px;"
                  onclick="this.select();">@verbatim
curl -X POST @endverbatim{{ $endpoint }}@verbatim \
  -H "Accept: application/json"@endverbatim{{ $requireKey ? ' \\' . PHP_EOL . '  -H "X-API-Key: ' . $apiKey . '"' : '' }}@verbatim \
  -d "name=Test Lead" \
  -d "phone=9876543210" \
  -d "email=test@example.com" \
  -d "message=Interested in 3BHK at Raheja"@endverbatim</textarea>
    </div>

@endsection