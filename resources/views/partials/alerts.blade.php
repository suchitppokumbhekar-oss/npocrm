@if (session('success'))
    <div class="alert" data-auto-dismiss>
        {{ session('success') }}
    </div>
@endif

@if (session('error'))
    <div class="alert alert-error" data-auto-dismiss>
        {{ session('error') }}
    </div>
@endif

@if ($errors->any() && !request()->ajax())
    <div class="alert alert-error">
        <strong>Please fix:</strong>
        <ul style="margin:4px 0 0 18px;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif