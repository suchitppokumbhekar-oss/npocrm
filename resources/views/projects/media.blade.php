@extends('layouts.app')

@section('title', $project->name . ' Media — NPO CRM')

@section('content')
@php
    $returnTo = trim((string) request('return_to', ''));
    $safeReturnTo = $returnTo !== '' && str_starts_with($returnTo, url('/')) ? $returnTo : null;
@endphp
<div class="page-head">
    <div>
        @if($safeReturnTo)
            <a href="{{ $safeReturnTo }}" class="back-link">← Back to Lead / Project Sharing</a>
        @else
            <a href="{{ route('projects.index') }}" class="back-link">← Projects</a>
        @endif
        <h1 class="page-title">{{ $project->name }} — Media Library</h1>
        <p class="page-subtitle">{{ $project->location }} · RERA: {{ $project->rera_number ?: '—' }}</p>
    </div>
</div>

@if($canUploadMedia)
<details class="card" id="upload-project-media">
    <summary style="cursor:pointer;font-weight:800;font-size:15px;">➕ Add New Project Material</summary>
    <div style="margin-top:12px;padding:10px 12px;background:#f8fafc;border-radius:8px;font-size:12px;line-height:1.5;"><strong>What to do:</strong> Choose the material type, add a clear title, optionally add the customer message, select the file, then upload it.</div>

    <form method="POST" action="{{ route('documents.upload') }}" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="entity_type" value="project">
        <input type="hidden" name="entity_id" value="{{ $project->id }}">
        <input type="hidden" name="context_type" value="project_media">
        <input type="hidden" name="return_to" value="{{ url()->current() }}?return_to={{ urlencode($safeReturnTo ?? '') }}#project-media">

        <div class="field">
            <label>Material type</label>
            <select name="document_category" class="input" required>
                <option value="brochure">Brochure</option>
                <option value="floor_plan">Floor Plan</option>
                <option value="price_sheet">Price Sheet</option>
                <option value="cost_sheet">Cost Sheet</option>
                <option value="inventory_sheet">Inventory Sheet</option>
                <option value="payment_plan">Payment Plan</option>
                <option value="location_map">Location Map</option>
                <option value="amenity_gallery">Amenity / Gallery</option>
                <option value="rera_approval">RERA / Approval</option>
                <option value="project_video">Project Video</option>
                <option value="other_sales_material">Other Sales Material</option>
            </select>
        </div>

        <div class="field">
            <label>Title</label>
            <input name="title" class="input" maxlength="255">
        </div>

        <div class="field">
            <label>Customer message <span class="muted">(optional)</span></label>
            <textarea name="description" class="input" rows="6" placeholder="Message employees can use when sharing this material with a customer. Emojis and line breaks are supported."></textarea>
        </div>

        <div class="field">
            <label>File</label>
            <input type="file" name="file" class="input" required accept="image/jpeg,image/png,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,video/mp4,video/quicktime">
            <div class="muted" style="font-size:11px;margin-top:4px;">Maximum 25 MB. Large videos should use the later approved external-video option instead of server upload.</div>
        </div>

        <label style="display:flex;gap:8px;align-items:center;margin:10px 0;">
            <input type="checkbox" name="customer_shareable" value="1">
            <span><strong>Use for customer sharing</strong><span class="muted" style="display:block;font-size:11px;">Turn this on for brochures, price sheets, floor plans and other material intended for customers.</span></span>
        </label>

        <button class="btn btn-primary" type="submit" style="width:100%;">Upload Material</button>
    </form>
</details>
@endif

<div id="project-media" style="margin-top:16px;">
    <div class="card" style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
            <div>
                <h3 style="margin:0;">Project Material</h3>
                <div class="muted" style="margin-top:4px;font-size:12px;">
                    {{ $media->count() }} uploaded ·
                    {{ $media->filter(fn ($file) => $file->isApprovedForSharing())->count() }} ready to share
                </div>
            </div>

            @if($canUploadMedia)
                <button type="button" class="btn btn-primary" onclick="document.getElementById('upload-project-media')?.setAttribute('open','open');document.getElementById('upload-project-media')?.scrollIntoView({behavior:'smooth',block:'start'});">
                    + Add Material
                </button>
            @endif
        </div>

        <div style="margin-top:12px;padding:11px 12px;border-radius:9px;background:#f8fafc;font-size:12px;line-height:1.5;">
            <strong>How this works:</strong>
            Upload the material and enable customer sharing → it becomes immediately available in
            <strong>Lead → Share Project Details</strong>. Current versions and changes remain auditable.
        </div>
    </div>

    @if($media->isEmpty())
        <div class="card" style="text-align:center;padding:28px 18px;">
            <div style="font-size:28px;margin-bottom:8px;">📎</div>
            <strong>No project material yet</strong>
            <div class="muted" style="font-size:12px;margin-top:5px;">
                Add a brochure, price sheet, floor plan, image or other sales material.
            </div>
        </div>
    @else
        <div style="display:grid;gap:12px;">
            @foreach($media as $file)
                <article id="media-{{ $file->id }}" class="card" style="margin:0;padding:14px;">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
                        <div style="min-width:0;">
                            <div style="font-weight:800;font-size:15px;word-break:break-word;">
                                {{ $file->title ?: $file->original_name }}
                            </div>

                            <div class="muted" style="font-size:11px;margin-top:4px;">
                                {{ ucwords(str_replace('_',' ', $file->document_category)) }}
                                · v{{ $file->version_number }}
                                @if($file->mime_type)
                                    · {{ strtoupper(pathinfo($file->original_name, PATHINFO_EXTENSION)) }}
                                @endif
                            </div>
                        </div>

                        <div style="flex:0 0 auto;">
                            @if($file->removed_at)
                                <span class="badge red">Removed</span>
                            @elseif($file->isApprovedForSharing())
                                <span class="badge green">✓ Ready to share</span>
                            @else
                                <span class="badge">Internal only</span>
                            @endif
                        </div>
                    </div>

                    @if($file->description)
                        <details style="margin-top:12px;">
                            <summary style="cursor:pointer;font-weight:700;font-size:12px;">
                                💬 View customer share message
                            </summary>
                            <div style="margin-top:8px;padding:10px;background:#f8fafc;border-radius:8px;white-space:pre-wrap;font-size:12px;line-height:1.5;">{{ $file->description }}</div>
                        </details>
                    @endif

                    @if(!$file->removed_at)
                        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:13px;">
                            <a href="{{ route('documents.view', $file->id) }}" target="_blank" rel="noopener" class="btn-small btn-info" style="text-align:center;text-decoration:none;padding:9px;">
                                👁 View
                            </a>

                            @if($canManageMedia)
                                <button type="button" class="btn-small btn-ghost" style="padding:9px;" onclick="document.getElementById('edit-media-{{ $file->id }}')?.toggleAttribute('open');">
                                    ✏️ Edit
                                </button>
                            @endif
                        </div>

                        @if($canManageMedia)
                            <details id="edit-media-{{ $file->id }}" style="margin-top:10px;border-top:1px solid #e5e7eb;padding-top:10px;">
                                <summary style="cursor:pointer;font-weight:700;font-size:12px;">Edit material details</summary>

                                <form method="POST" action="{{ route('documents.updateMetadata', $file->id) }}" style="display:grid;gap:11px;margin-top:12px;">
                                    @csrf
                                    <input type="hidden" name="return_to" value="{{ url()->current() }}?return_to={{ urlencode($safeReturnTo ?? '') }}#media-{{ $file->id }}">

                                    <div class="field">
                                        <label>Title</label>
                                        <input class="input" type="text" name="title" value="{{ $file->title }}" maxlength="255">
                                    </div>

                                    <div class="field">
                                        <label>Material type</label>
                                        <select class="input" name="document_category" required>
                                            @foreach([
                                                'brochure' => 'Brochure',
                                                'floor_plan' => 'Floor Plan',
                                                'price_sheet' => 'Price Sheet',
                                                'cost_sheet' => 'Cost Sheet',
                                                'inventory_sheet' => 'Inventory Sheet',
                                                'payment_plan' => 'Payment Plan',
                                                'location_map' => 'Location Map',
                                                'amenity_gallery' => 'Amenity / Gallery',
                                                'rera_approval' => 'RERA / Approval',
                                                'project_video' => 'Project Video',
                                                'other_sales_material' => 'Other Sales Material',
                                            ] as $value => $label)
                                                <option value="{{ $value }}" @selected($file->document_category === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div class="field">
                                        <label>Customer message</label>
                                        <textarea class="input" name="description" rows="8" placeholder="This message can be used when sharing this project with a customer.">{{ $file->description }}</textarea>
                                        <div class="muted" style="font-size:11px;margin-top:4px;">
                                            Emojis, paragraphs and line breaks are preserved.
                                        </div>
                                    </div>

                                    <label style="display:flex;gap:9px;align-items:flex-start;">
                                        <input type="checkbox" name="customer_shareable" value="1" @checked($file->customer_shareable) style="margin-top:3px;">
                                        <span>
                                            <strong>Allow customer sharing</strong>
                                            <span class="muted" style="display:block;font-size:11px;">
                                                When enabled, the current active version is immediately available for customer sharing.
                                            </span>
                                        </span>
                                    </label>

                                    <button type="submit" class="btn btn-primary">Save Changes</button>
                                </form>

                                @if($canManageMedia)
                                    <details style="margin-top:12px;">
                                        <summary style="cursor:pointer;font-size:12px;font-weight:700;">Replace file</summary>
                                        <form method="POST" action="{{ route('documents.replace', $file->id) }}" enctype="multipart/form-data" style="display:grid;gap:8px;margin-top:9px;">
                                            @csrf
                                            <input type="hidden" name="return_to" value="{{ url()->current() }}?return_to={{ urlencode($safeReturnTo ?? '') }}#media-{{ $file->id }}">
                                            <input class="input" type="file" name="file" required accept="image/jpeg,image/png,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,video/mp4,video/quicktime">
                                            <button class="btn-small btn-ghost" type="submit">Upload New Version</button>
                                        </form>
                                    </details>
                                @endif

                                @if($canManageMedia)
                                    <form method="POST" action="{{ route('documents.remove', $file->id) }}" style="margin-top:12px;" onsubmit="return confirm('Remove this material from active use? Audit history will be preserved.');">
                                        @csrf
                                        <input type="hidden" name="return_to" value="{{ url()->current() }}?return_to={{ urlencode($safeReturnTo ?? '') }}#media-{{ $file->id }}">
                                        <input type="hidden" name="reason" value="Removed from project media library">
                                        <button class="btn-small" type="submit">Remove Material</button>
                                    </form>
                                @endif
                            </details>
                        @endif
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    @if($safeReturnTo)
        <a href="{{ $safeReturnTo }}" class="btn btn-primary" style="display:block;text-align:center;text-decoration:none;margin-top:16px;">
            ← Back to Share Project Details
        </a>
    @endif
</div>
@endsection
