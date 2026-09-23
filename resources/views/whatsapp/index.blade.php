@extends('layouts.app')
@section('title','My WhatsApp — NPO CRM')
@section('content')
<div class="page-head"><div><h1>💬 My WhatsApp</h1><p class="muted">Configure up to two WhatsApp identities for CRM actions. These are separate from your CRM profile phone number.</p></div></div>
<div class="card" style="max-width:680px;">
<form method="POST" action="{{ route('whatsapp.accounts.update') }}">@csrf
<div class="field"><label>Personal WhatsApp</label><input class="input" type="tel" name="personal" value="{{ old('personal', $personal?->phone) }}" placeholder="+91 98765 43210" inputmode="tel"><p class="muted" style="font-size:11px;margin-top:4px;">Leave blank if you do not use a personal WhatsApp for CRM work.</p>@error('personal')<div class="alert alert-error">{{ $message }}</div>@enderror</div>
<div class="field"><label>Business WhatsApp</label><input class="input" type="tel" name="business" value="{{ old('business', $business?->phone) }}" placeholder="+91 98765 43210" inputmode="tel"><p class="muted" style="font-size:11px;margin-top:4px;">Leave blank if you do not use a business WhatsApp for CRM work.</p>@error('business')<div class="alert alert-error">{{ $message }}</div>@enderror</div>
<div class="field"><label>Default sharing number</label><select class="input" name="default_account_type"><option value="">No default — ask me each time</option><option value="personal" {{ old('default_account_type', $defaultAccountType) === 'personal' ? 'selected' : '' }} {{ isset($personal) ? '' : 'disabled' }}>Personal WhatsApp</option><option value="business" {{ old('default_account_type', $defaultAccountType) === 'business' ? 'selected' : '' }} {{ isset($business) ? '' : 'disabled' }}>Business WhatsApp</option></select><p class="muted" style="font-size:11px;margin-top:4px;">Your default will be shown first for CRM sharing. You can still choose another configured number when sending.</p>@error('default_account_type')<div class="alert alert-error">{{ $message }}</div>@enderror</div>
<div style="padding:12px;border:1px solid var(--c-border);border-radius:10px;background:var(--c-surface-2);font-size:12px;line-height:1.5;margin-bottom:14px;">ℹ️ When a CRM WhatsApp action is started, NPO CRM will show your default identity when configured and still allow you to choose another configured identity. The actual sender account remains controlled by the WhatsApp app/browser session.</div>
<button class="btn btn-block" type="submit">💾 Save WhatsApp Accounts</button>
</form></div>
@endsection
