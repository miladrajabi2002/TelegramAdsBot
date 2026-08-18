@if(session('success') || session('error') || $errors->any())
    <div class="stack-sm" aria-live="polite">
        @if(session('success'))
            <div class="notice notice-success"><x-icon name="check" /><p>{{ session('success') }}</p></div>
        @endif
        @if(session('error'))
            <div class="notice notice-danger"><x-icon name="warning" /><p>{{ session('error') }}</p></div>
        @endif
        @if($errors->any())
            <div class="notice notice-danger" role="alert">
                <x-icon name="warning" />
                <div><strong>{{ app()->isLocale('fa') ? 'لطفاً موارد زیر را اصلاح کنید:' : 'Please fix the following:' }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            </div>
        @endif
    </div>
@endif
