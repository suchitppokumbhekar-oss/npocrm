@props(['lead', 'size' => 'md', 'highlight' => null])

@php
    $tel = phone_tel($lead->phone);
    $wa  = phone_wa($lead->phone);

    $hasPhone = $tel !== '' && $wa !== '';

    if ($hasPhone) {
        $settings    = app(\App\Services\SettingsService::class);
        $projectName = $lead->project?->name;

        // Template editable in Settings → General.
        // Placeholders: {name}, {agent}, {company}, {project}
        // Multiline supported — use \n\n for a blank line between paragraphs.
        $template = $settings->get(
            'whatsapp_initial_message',
            "Hi {name},\n\nWe have received an enquiry about {project} project from you.\n\nWhen is a good time to talk to you to discuss more about this?"
        );

        $msg = str_replace(
            ['{name}', '{agent}', '{company}', '{project}'],
            [
                $lead->customer_name,
                session('user_name', ''),
                $settings->get('company_name', 'NPO CRM'),
                $projectName ?: 'our',
            ],
            $template
        );

        // Keep newlines intact; only collapse runs of spaces/tabs
        $msg = preg_replace('/[ \t]+/', ' ', $msg);
        $msg = preg_replace('/\n{3,}/', "\n\n", $msg);
        $msg = trim($msg);

    }

    $sizeClass = match ($size) {
        'sm' => 'contact-btn-sm',
        'lg' => 'contact-btn-lg',
        default => '',
    };

    $callPrimary = $highlight === 'call' ? 'contact-btn-primary' : '';
    $waPrimary   = $highlight === 'whatsapp' ? 'contact-btn-primary' : '';
@endphp

@if ($hasPhone)
    <div {{ $attributes->merge(['class' => 'contact-buttons']) }}>
        {{-- Call --}}
        <a href="tel:{{ $tel }}"
           class="contact-btn contact-btn-call {{ $sizeClass }} {{ $callPrimary }}"
           title="Call {{ $lead->customer_name }}"
           aria-label="Call {{ $lead->customer_name }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true" class="contact-icon">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
            </svg>
            @if ($size === 'lg')<span>Call</span>@endif
        </a>

        {{-- WhatsApp — official brand mark --}}
        <button type="button"
           class="contact-btn contact-btn-wa {{ $sizeClass }} {{ $waPrimary }}"
           data-npo-whatsapp
           data-whatsapp-phone="{{ $wa }}"
           data-whatsapp-text="{{ base64_encode($msg) }}"
           title="WhatsApp {{ $lead->customer_name }}"
           aria-label="WhatsApp {{ $lead->customer_name }}">
            <svg viewBox="0 0 24 24" fill="currentColor"
                 aria-hidden="true" class="contact-icon">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
            </svg>
            @if ($size === 'lg')<span>WhatsApp</span>@endif
        </button>
    </div>
@endif