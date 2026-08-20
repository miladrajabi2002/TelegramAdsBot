{{--
    Flash + validation error summary.

    Renders:
      • session('success')  -> green notice
      • session('error')   -> red notice
      • session('warning')  -> warning notice
      • $errors->any()      -> red notice listing all field errors

    When the calling view sets `$suppressFlashErrors = true` (e.g. when
    the form itself renders its own inline errors and we don't want a
    DUPLICATE summary at the top of the page), the $errors->any() block
    is skipped — only session flash messages (success/error/warning)
    are rendered.

    This is the simplest way to avoid the "duplicated error notice" UX
    bug on the KYC form: the form renders its own per-field errors
    inline + a single compact summary notice INSIDE the form (so the
    user sees the error right next to the field they need to fix),
    while the layout-level flash no longer duplicates that summary.

    The `$errors` shared variable is normally injected by Laravel's
    ShareErrorsFromSession middleware during HTTP requests. When this
    component is rendered outside of an HTTP lifecycle (e.g. from a
    CLI script, a queue job, or a mailable), `$errors` is not set and
    calling ->any() on it would throw "Undefined variable $errors".
    We defensively default it to an empty ViewErrorBag so the
    component is safe to render from any context.
--}}
@php
    $errors = $errors ?? new \Illuminate\Support\ViewErrorBag();
@endphp
@if(session('success') || session('error') || session('warning') || ($errors->any() && empty($suppressFlashErrors)))
    <div class="stack-sm" aria-live="polite">
        @if(session('success'))
            <div class="notice notice-success"><x-icon name="check" /><p>{{ session('success') }}</p></div>
        @endif
        @if(session('error'))
            <div class="notice notice-danger"><x-icon name="warning" /><p>{{ session('error') }}</p></div>
        @endif
        @if(session('warning'))
            <div class="notice notice-warning"><x-icon name="clock" /><p>{{ session('warning') }}</p></div>
        @endif
        @if($errors->any() && empty($suppressFlashErrors))
            <div class="notice notice-danger" role="alert">
                <x-icon name="warning" />
                <div><strong>{{ app()->isLocale('fa') ? 'لطفاً موارد زیر را اصلاح کنید:' : 'Please fix the following:' }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            </div>
        @endif
    </div>
@endif
