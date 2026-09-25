<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $package->project?->name ?? 'Project Details' }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f5f7fb;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
        }
        .wrap {
            width: min(920px, calc(100% - 28px));
            margin: 0 auto;
            padding: 28px 0 48px;
        }
        .hero {
            background: #fff;
            border: 1px solid #e3e7ef;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 18px;
            box-shadow: 0 4px 18px rgba(20, 30, 50, .05);
        }
        .eyebrow {
            margin: 0 0 7px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        h1 {
            margin: 0;
            font-size: clamp(24px, 6vw, 36px);
            line-height: 1.15;
        }
        .message {
            margin: 16px 0 0;
            color: #475569;
            white-space: pre-line;
            line-height: 1.6;
        }
        .meta {
            margin-top: 16px;
            color: #64748b;
            font-size: 12px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
        }
        .card {
            background: #fff;
            border: 1px solid #e3e7ef;
            border-radius: 14px;
            overflow: hidden;
        }
        .preview {
            display: block;
            width: 100%;
            aspect-ratio: 16 / 10;
            background: #eef2f7;
            object-fit: cover;
        }
        .file-body {
            padding: 14px;
        }
        .file-name {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            word-break: break-word;
        }
        .file-meta {
            margin: 6px 0 12px;
            color: #64748b;
            font-size: 11px;
        }
        .open {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            width: 100%;
            border-radius: 9px;
            background: #172033;
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
        }
        .notice {
            margin-top: 18px;
            color: #64748b;
            font-size: 11px;
            line-height: 1.5;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="wrap">

    <section class="hero">
        <p class="eyebrow">Project details</p>
        <h1>{{ $package->project?->name ?? 'Project Details' }}</h1>

        @if($package->message)
            <div class="message">{{ $package->message }}</div>
        @endif

        <div class="meta">
            Shared with you by {{ $package->creator?->name ?? 'your property consultant' }}
        </div>
    </section>

    <section class="grid">
        @foreach($files as $packageFile)
            @php
                $file = $packageFile->file;
                $isImage = $file && str_starts_with((string) $file->mime_type, 'image/');
            @endphp

            @if($file)
                <article class="card">
                    @if($isImage)
                        <img
                            class="preview"
                            src="{{ route('project-share.file', ['token' => $token, 'fileId' => $file->id]) }}"
                            alt="{{ $file->title ?: $file->original_name }}"
                        >
                    @else
                        <div class="preview" style="display:flex;align-items:center;justify-content:center;color:#64748b;font-weight:700;font-size:13px;">
                            {{ strtoupper(pathinfo($file->original_name, PATHINFO_EXTENSION) ?: 'FILE') }}
                        </div>
                    @endif

                    <div class="file-body">
                        <p class="file-name">{{ $file->title ?: $file->original_name }}</p>

                        @if($file->document_category)
                            <p class="file-meta">{{ $file->document_category }}</p>
                        @endif

                        <a
                            class="open"
                            target="_blank"
                            rel="noopener"
                            href="{{ route('project-share.file', ['token' => $token, 'fileId' => $file->id]) }}"
                        >
                            Open / View
                        </a>
                    </div>
                </article>
            @endif
        @endforeach
    </section>

    <p class="notice">
        This private sharing link is temporary and will stop working after its expiry date.
    </p>

</div>
</body>
</html>
