@php
    $editIsFa = app()->isLocale('fa');
    $editDraft = $campaign ?? $order ?? $draft ?? null;
    $editDraft?->loadMissing('currentRevision.targets');

    // The create/edit wizard seeds its search chips from suggested_channel_id.
    // Manual targets do not have that id, so the old fallback used the
    // campaign_targets row id (1, 2, 3, ...). Seed every existing target by
    // its real Telegram username instead. The correction controller resolves
    // catalog usernames back to their catalog row and keeps manual targets as
    // manual, so this works identically for channel and bot placements.
    foreach ($editDraft?->currentRevision?->targets ?? [] as $target) {
        $username = trim((string) data_get($target, 'channel_username', ''));
        if ($username !== '') {
            $target->setAttribute('suggested_channel_id', '@'.ltrim($username, '@'));
        }
    }

    $editRevision = $editDraft?->currentRevision;
    $existingMediaPath = trim((string) data_get($editRevision, 'ad_media_path', ''));
    $existingMediaType = (string) data_get($editRevision, 'ad_media_type', 'image');
    $existingMediaUrl = $existingMediaPath !== '' && $editDraft
        ? route('app.campaigns.ad-media', ['campaign' => data_get($editDraft, 'public_id', data_get($editDraft, 'id'))])
        : null;
@endphp

@if($existingMediaUrl)
@push('scripts')
<script>
(() => {
    const existingUrl = @json($existingMediaUrl);
    const existingType = @json($existingMediaType);

    const showExistingMedia = () => {
        const fileInput = document.querySelector('#ad-media');
        if (fileInput?.files?.length) return; // a newly selected file wins

        // Upload-card preview.
        const uploadBox = fileInput?.closest('.upload-box');
        const uploadImage = document.querySelector('#ad-media-preview');
        const uploadVideo = document.querySelector('#ad-media-preview-video');
        if (existingType === 'video') {
            if (uploadImage) uploadImage.hidden = true;
            if (uploadVideo) {
                uploadVideo.src = existingUrl;
                uploadVideo.hidden = false;
            }
        } else if (uploadImage) {
            uploadImage.src = existingUrl;
            uploadImage.hidden = false;
            uploadImage.alt = @json($editIsFa ? 'رسانه فعلی تبلیغ' : 'Current ad media');
            if (uploadVideo) uploadVideo.hidden = true;
        }
        uploadBox?.classList.add('has-preview');

        // Telegram-native live preview. The partial normally fills this only
        // after a file input change; populate it from the saved revision too.
        const stage = document.querySelector('[data-preview-stage]');
        const slot = stage?.querySelector('[data-preview-media-slot]');
        const image = stage?.querySelector('#ad-preview-media');
        const video = stage?.querySelector('#ad-preview-video');
        if (slot) slot.hidden = false;
        if (existingType === 'video') {
            if (image) image.hidden = true;
            if (video) {
                video.src = existingUrl;
                video.hidden = false;
            }
        } else {
            if (video) video.hidden = true;
            if (image) {
                image.src = existingUrl;
                image.hidden = false;
            }
        }
        stage?.classList.add('has-live-media');
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => setTimeout(showExistingMedia, 0), { once: true });
    } else {
        setTimeout(showExistingMedia, 0);
    }
})();
</script>
@endpush
@endif

@include('app.campaigns.create', [
    'editing' => true,
    'draft' => $editDraft,
])
