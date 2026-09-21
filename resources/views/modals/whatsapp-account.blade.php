@php
    $lead = $lead ?? null;
    $settings = app(\App\Services\SettingsService::class);
    $template = $settings->get('whatsapp_initial_message', "Hi {name},\n\nWe have received an enquiry about {project} project from you.\n\nWhen is a good time to talk to you to discuss more about this?");
    $msg = $lead ? str_replace(
        ['{name}', '{agent}', '{company}', '{project}'],
        [$lead->customer_name, session('user_name',''), $settings->get('company_name','NPO CRM'), $lead->project?->name ?: 'our'],
        $template
    ) : '';
@endphp
@if (!$lead)
<div class="alert alert-error">Lead not found.</div>
@else
<div class="task-share-intro"><strong>{{ $lead->customer_name }}</strong><div class="muted" style="font-size:12px;margin-top:4px;">Choose Personal or Business WhatsApp.</div></div>
<button type="button" class="btn btn-block" data-npo-whatsapp data-whatsapp-phone="{{ phone_wa($lead->phone) }}" data-whatsapp-text="{{ base64_encode(trim($msg)) }}">💬 Choose WhatsApp</button>
@endif
