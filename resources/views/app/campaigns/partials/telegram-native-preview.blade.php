@php
$previewDefaultText = old('ad_text', data_get($draftRevision ?? [], 'ad_text')) ?: ($isFa ? 'متن تبلیغ شما اینجا نمایش داده می‌شود.' : 'Your ad copy will appear here.');
$previewDefaultTitle = old('internal_title', data_get($draftRevision ?? [], 'internal_title')) ?: ($isFa ? 'تبلیغ شما' : 'Your promotion');
@endphp

<style>
    /* ========================================================================
   Telegram native ad preview — scoped to .tgn-preview only.
   This intentionally lives in the Blade partial so installing this patch
   does not require rebuilding Vite assets and does not touch global UI.
   ======================================================================== */
    .tgn-preview,
    .tgn-preview * {
        box-sizing: border-box;
    }

    .tgn-preview [hidden] {
        display: none !important;
    }

    .tgn-preview-wrap {
        min-width: 0;
        display: grid;
        align-content: start;
        gap: 8px;
    }

    .tgn-preview-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin: 0;
    }

    .tgn-preview-context {
        display: inline-flex;
        align-items: center;
        min-height: 26px;
        padding: 3px 9px;
        border: 1px solid #d7e3ec;
        border-radius: 999px;
        background: #f5f9fc;
        color: #527087;
        font-size: 11px;
        font-weight: 700;
    }

    .tgn-preview {
        --tg-blue: #168acd;
        --tg-blue-strong: #0786d1;
        --tg-text: #111820;
        --tg-secondary: #75808a;
        --tg-line: rgba(60, 60, 67, .13);
        --tg-card: #ffffff;
        width: min(100%, 390px);
        height: 580px;
        margin-inline: auto;
        position: relative;
        overflow: hidden;
        border: 5px solid #101010;
        border-radius: 34px;
        background: #fff;
        color: var(--tg-text);
        direction: ltr;
        font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        box-shadow: 0 14px 36px rgba(18, 34, 47, .16), 0 2px 6px rgba(18, 34, 47, .08);
        isolation: isolate;
    }

    .tgn-status {
        height: 40px;
        padding: 11px 16px 4px;
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: start;
        background: rgba(255, 255, 255, .98);
        color: #07090b;
        font-size: 13px;
        font-weight: 700;
        line-height: 1;
        z-index: 5;
        position: relative;
    }

    .tgn-dynamic-island {
        width: 92px;
        height: 25px;
        margin-top: -4px;
        border-radius: 999px;
        background: #000;
    }

    .tgn-status-right {
        justify-self: end;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 10px;
    }

    .tgn-signal {
        display: inline-flex;
        align-items: end;
        gap: 1.5px;
        height: 10px;
    }

    .tgn-signal i {
        display: block;
        width: 2px;
        border-radius: 1px;
        background: #090b0d;
    }

    .tgn-signal i:nth-child(1) {
        height: 3px
    }

    .tgn-signal i:nth-child(2) {
        height: 5px
    }

    .tgn-signal i:nth-child(3) {
        height: 7px
    }

    .tgn-signal i:nth-child(4) {
        height: 9px
    }

    .tgn-wifi {
        width: 12px;
        height: 9px;
        position: relative;
        overflow: hidden;
    }

    .tgn-wifi::before {
        content: "";
        position: absolute;
        inset: 0 0 auto;
        height: 10px;
        border: 2px solid #090b0d;
        border-color: #090b0d transparent transparent;
        border-radius: 50%;
        transform: translateY(2px);
    }

    .tgn-wifi::after {
        content: "";
        position: absolute;
        width: 3px;
        height: 3px;
        border-radius: 50%;
        background: #090b0d;
        left: 4.5px;
        bottom: 0;
    }

    .tgn-battery {
        width: 20px;
        height: 9px;
        border: 1.5px solid #090b0d;
        border-radius: 2.5px;
        position: relative;
        padding: 1px;
    }

    .tgn-battery::before {
        content: "";
        display: block;
        width: 78%;
        height: 100%;
        border-radius: 1px;
        background: #090b0d;
    }

    .tgn-battery::after {
        content: "";
        position: absolute;
        width: 1.5px;
        height: 4px;
        right: -3px;
        top: 1.5px;
        border-radius: 0 1px 1px 0;
        background: #090b0d;
    }

    .tgn-header {
        height: 64px;
        display: grid;
        grid-template-columns: 32px 44px minmax(0, 1fr) 28px;
        align-items: center;
        gap: 7px;
        padding: 6px 12px;
        border-bottom: 1px solid var(--tg-line);
        background: rgba(250, 250, 252, .97);
        position: relative;
        z-index: 4;
    }

    .tgn-back {
        color: #0a84ff;
        font-size: 32px;
        line-height: 1;
        font-weight: 300;
        transform: translateY(-1px);
    }

    .tgn-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        flex: 0 0 auto;
        color: #fff;
        overflow: hidden;
    }

    .tgn-avatar.channel {
        background: linear-gradient(145deg, #c44ff0 0%, #2f8bff 100%);
    }

    .tgn-avatar.bot {
        background: linear-gradient(145deg, #2fd39a 0%, #169ce0 100%);
    }

    .tgn-avatar.search {
        background: linear-gradient(145deg, #5aa8ff 0%, #2787da 100%);
    }

    .tgn-avatar svg {
        width: 21px;
        height: 21px;
        fill: currentColor;
    }

    .tgn-header-copy {
        min-width: 0;
        text-align: left;
        line-height: 1.2;
    }

    .tgn-header-copy strong,
    .tgn-header-copy small {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .tgn-header-copy strong {
        font-size: 14px;
        font-weight: 730;
    }

    .tgn-header-copy small {
        margin-top: 2px;
        color: #8a8f96;
        font-size: 10.5px;
        font-weight: 500;
    }

    .tgn-menu {
        justify-self: end;
        color: #75808a;
        font-size: 23px;
        line-height: 1;
        letter-spacing: 1px;
    }

    .tgn-search-header {
        grid-template-columns: 36px minmax(0, 1fr);
        gap: 8px;
        height: 58px;
        padding: 7px 12px;
    }

    .tgn-search-cancel {
        color: #0a84ff;
        font-size: 12px;
        font-weight: 600;
    }

    .tgn-searchbox {
        min-width: 0;
        height: 36px;
        border-radius: 10px;
        background: #e9eaee;
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 0 10px;
        color: #6e7379;
        font-size: 12px;
    }

    .tgn-searchbox svg {
        width: 14px;
        height: 14px;
        stroke: currentColor;
        fill: none;
        stroke-width: 2;
    }

    .tgn-searchbox span {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .tgn-view {
        position: absolute;
        inset: 104px 0 0;
        overflow: hidden;
        background: #eef2f5;
    }

    .tgn-view-search {
        top: 98px;
        background: #fff;
    }

    /* ---------- channel preview ---------- */
    .tgn-channel-wall {
        height: 100%;
        padding: 0 9px 52px;
        overflow: hidden;
        background-color: #cfe2bd;
        background-image:
            radial-gradient(circle at 18px 16px, rgba(255, 255, 255, .22) 1.5px, transparent 1.7px),
            radial-gradient(circle at 68px 43px, rgba(77, 123, 69, .09) 2px, transparent 2.2px),
            linear-gradient(125deg, rgba(248, 219, 109, .28), transparent 44%),
            linear-gradient(315deg, rgba(112, 201, 169, .22), transparent 52%);
        background-size: 72px 72px, 88px 88px, auto, auto;
    }

    .tgn-pinned {
        height: 38px;
        margin-inline: -9px;
        padding: 5px 13px;
        display: grid;
        grid-template-columns: 3px minmax(0, 1fr) 18px;
        gap: 7px;
        align-items: center;
        background: rgba(247, 249, 250, .96);
        border-bottom: 1px solid rgba(60, 60, 67, .11);
        color: #64717d;
    }

    .tgn-pinned-line {
        align-self: stretch;
        border-radius: 5px;
        background: #229ed9;
    }

    .tgn-pinned-copy {
        min-width: 0;
        line-height: 1.15;
    }

    .tgn-pinned-copy b {
        display: block;
        color: #3181ad;
        font-size: 10px;
    }

    .tgn-pinned-copy span {
        display: block;
        margin-top: 2px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 9.5px;
    }

    .tgn-pin {
        color: #71808d;
        font-size: 15px;
        transform: rotate(-25deg);
    }

    .tgn-host-post {
        width: calc(100% - 18px);
        margin: 9px auto 8px;
        padding: 12px 12px 9px;
        border-radius: 3px 3px 12px 12px;
        background: rgba(255, 255, 255, .97);
        box-shadow: 0 1px 2px rgba(0, 0, 0, .13);
        color: #17202a;
        font-size: 10px;
        line-height: 1.42;
    }

    .tgn-host-post p {
        margin: 0;
    }

    .tgn-host-post strong {
        font-weight: 760;
    }

    .tgn-host-links {
        color: #387da3;
    }

    .tgn-reactions {
        display: flex;
        gap: 5px;
        margin-top: 9px;
    }

    .tgn-reaction {
        min-width: 37px;
        height: 21px;
        padding: 0 6px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 3px;
        background: #d9edf8;
        color: #4f7891;
        font-size: 9px;
    }

    .tgn-post-meta {
        display: flex;
        justify-content: flex-end;
        gap: 6px;
        margin-top: 5px;
        color: #9b9fa5;
        font-size: 8.5px;
    }

    .tgn-comment-row {
        margin: 8px -12px -9px;
        padding: 7px 10px;
        border-top: 1px solid #eef0f2;
        display: flex;
        justify-content: space-between;
        color: #4383a6;
        font-size: 9px;
        font-weight: 650;
    }

    .tgn-channel-ad-wrap {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 30px;
        gap: 6px;
        align-items: end;
        margin: 5px 1px 0;
    }

    .tgn-sponsored-card {
        position: relative;
        min-width: 0;
        padding: 10px 10px 8px 14px;
        border-radius: 13px;
        background: #fff;
        box-shadow: 0 2px 4px rgba(0, 0, 0, .14);
        overflow: hidden;
    }

    .tgn-sponsored-card::before {
        content: "";
        position: absolute;
        inset: 8px auto 8px 6px;
        width: 3px;
        border-radius: 99px;
        background: #a763d4;
    }

    .tgn-ad-kicker {
        display: flex;
        align-items: center;
        gap: 5px;
        min-width: 0;
        margin-bottom: 3px;
    }

    .tgn-ad-label {
        color: #9c58c8;
        font-size: 9.5px;
        font-weight: 750;
    }

    .tgn-what {
        display: inline-flex;
        align-items: center;
        min-height: 17px;
        padding: 1px 6px;
        border-radius: 999px;
        background: #efe4f7;
        color: #9a6bb8;
        font-size: 8px;
        white-space: nowrap;
    }

    .tgn-sponsored-title {
        display: block;
        margin: 0 0 3px;
        color: #101419;
        font-size: 12.2px;
        font-weight: 760;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .tgn-sponsored-text {
        margin: 0;
        color: #20262c;
        font-size: 11.1px;
        line-height: 1.42;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .tgn-sponsored-media {
        margin: 7px 0 1px;
        border-radius: 8px;
        overflow: hidden;
        aspect-ratio: 16/9;
        background: #e8edf1;
    }

    .tgn-sponsored-media img,
    .tgn-sponsored-media video {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        background: #111;
    }

    .tgn-sponsored-media video {
        cursor: pointer;
    }

    .tgn-sponsored-cta {
        margin-top: 7px;
        padding-top: 7px;
        border-top: 1px solid #eee8f2;
        text-align: center;
        color: #9553bd;
        font-size: 9.5px;
        font-weight: 800;
        letter-spacing: .02em;
    }

    .tgn-ad-side {
        align-self: center;
        display: grid;
        overflow: hidden;
        border-radius: 13px;
        background: rgba(98, 150, 80, .52);
        color: #fff;
        backdrop-filter: blur(5px);
    }

    .tgn-ad-side span {
        height: 28px;
        display: grid;
        place-items: center;
        font-size: 16px;
        line-height: 1;
    }

    .tgn-ad-side span+span {
        border-top: 1px solid rgba(255, 255, 255, .22);
        font-size: 18px;
    }

    /* ---------- bot preview ---------- */
    .tgn-bot-sponsored {
        min-height: 72px;
        padding: 7px 38px 7px 13px;
        position: relative;
        background: #fff;
        border-bottom: 1px solid var(--tg-line);
        direction: ltr;
    }

    .tgn-bot-ad-top {
        display: flex;
        align-items: center;
        gap: 5px;
        min-width: 0;
    }

    .tgn-bot-ad-top .tgn-ad-label {
        color: #0a84ff;
        font-size: 10px;
    }

    .tgn-bot-ad-top .tgn-what {
        color: #2c91c8;
        background: #e4f3fb;
    }

    .tgn-bot-ad-title {
        min-width: 0;
        max-width: 175px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 11px;
        font-weight: 760;
    }

    .tgn-bot-ad-text {
        margin: 4px 0 0;
        color: #171b20;
        font-size: 11px;
        line-height: 1.38;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .tgn-bot-close {
        position: absolute;
        right: 13px;
        top: 22px;
        color: #0a84ff;
        font-size: 22px;
        font-weight: 300;
        line-height: 1;
    }

    .tgn-bot-wall {
        height: calc(100% - 72px);
        padding: 12px 10px 46px;
        position: relative;
        background:
            radial-gradient(circle at 18% 18%, rgba(255, 225, 94, .6), transparent 31%),
            radial-gradient(circle at 82% 28%, rgba(60, 209, 220, .58), transparent 36%),
            radial-gradient(circle at 27% 85%, rgba(240, 156, 107, .45), transparent 38%),
            #dcebdc;
    }

    .tgn-day {
        width: max-content;
        margin: 0 auto 10px;
        padding: 3px 8px;
        border-radius: 999px;
        background: rgba(82, 123, 107, .55);
        color: #fff;
        font-size: 9px;
        font-weight: 700;
    }

    .tgn-bubble-wrap {
        display: flex;
        align-items: flex-end;
        gap: 6px;
    }

    .tgn-mini-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        background: linear-gradient(145deg, #2fd39a, #169ce0);
        color: #fff;
        flex: 0 0 28px;
    }

    .tgn-mini-avatar svg {
        width: 14px;
        height: 14px;
        fill: currentColor;
    }

    .tgn-bubble {
        width: min(82%, 250px);
        padding: 8px 10px 7px;
        border-radius: 13px 13px 13px 4px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(0, 0, 0, .12);
    }

    .tgn-bubble b {
        display: block;
        margin-bottom: 5px;
        font-size: 11px;
    }

    .tgn-bubble p {
        margin: 0;
        font-size: 10.3px;
        line-height: 1.38;
    }

    .tgn-bubble time {
        display: block;
        margin-top: 2px;
        text-align: right;
        color: #92979c;
        font-size: 8px;
    }

    .tgn-inline-btn {
        width: min(82%, 250px);
        height: 34px;
        margin: 3px 0 0 34px;
        border: 0;
        border-radius: 9px;
        background: rgba(72, 153, 197, .78);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font: inherit;
        font-size: 10.5px;
        font-weight: 680;
    }

    .tgn-compose {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 42px;
        display: grid;
        grid-template-columns: 28px minmax(0, 1fr) 25px 25px;
        gap: 4px;
        align-items: center;
        padding: 5px 8px;
        background: rgba(248, 248, 249, .96);
        border-top: 1px solid rgba(60, 60, 67, .14);
        color: #8c9197;
    }

    .tgn-compose-field {
        height: 29px;
        border: 1px solid #d8dadd;
        border-radius: 15px;
        display: flex;
        align-items: center;
        padding: 0 10px;
        color: #a0a4a9;
        font-size: 9.5px;
        background: #fff;
    }

    .tgn-compose-ico {
        text-align: center;
        font-size: 16px;
        color: #6c7279;
    }

    /* ---------- search preview ---------- */
    .tgn-search-body {
        height: 100%;
        background: #fff;
        overflow: hidden;
    }

    .tgn-search-tabs {
        height: 37px;
        display: flex;
        align-items: end;
        gap: 18px;
        padding: 0 14px;
        border-bottom: 1px solid var(--tg-line);
        color: #7f858b;
        font-size: 9.5px;
        font-weight: 650;
    }

    .tgn-search-tab {
        height: 37px;
        display: flex;
        align-items: center;
        position: relative;
        white-space: nowrap;
    }

    .tgn-search-tab.active {
        color: #0a84ff;
    }

    .tgn-search-tab.active::after {
        content: "";
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 2px;
        border-radius: 2px 2px 0 0;
        background: #0a84ff;
    }

    .tgn-search-title {
        padding: 10px 14px 5px;
        color: #8a8f95;
        font-size: 9px;
        font-weight: 650;
        text-transform: uppercase;
        letter-spacing: .03em;
    }

    .tgn-search-result {
        min-height: 64px;
        padding: 7px 12px;
        display: grid;
        grid-template-columns: 43px minmax(0, 1fr) 18px;
        gap: 9px;
        align-items: center;
        border-bottom: 1px solid #eff0f2;
        background: #fff;
    }

    .tgn-search-result.sponsored {
        background: linear-gradient(90deg, #f7fbff 0%, #fff 45%);
    }

    .tgn-search-result .tgn-avatar {
        width: 42px;
        height: 42px;
    }

    .tgn-search-copy {
        min-width: 0;
    }

    .tgn-search-row-top {
        display: flex;
        align-items: center;
        gap: 5px;
        min-width: 0;
    }

    .tgn-search-row-top strong {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 11.7px;
    }

    .tgn-search-ad {
        padding: 1px 5px;
        border-radius: 999px;
        background: #e6f3fb;
        color: #168acd;
        font-size: 7.5px;
        font-weight: 800;
        flex: 0 0 auto;
    }

    .tgn-search-sub {
        display: block;
        margin-top: 1px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: #8a8f95;
        font-size: 9.4px;
    }

    .tgn-search-desc {
        margin: 2px 0 0;
        color: #61686f;
        font-size: 9.5px;
        line-height: 1.28;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .tgn-chevron {
        color: #b2b6ba;
        font-size: 20px;
        font-weight: 300;
    }

    .tgn-search-cta {
        margin: 7px 12px 8px;
        height: 29px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #e8f4fb;
        color: #168acd;
        font-size: 9.5px;
        font-weight: 740;
    }

    .tgn-search-muted .tgn-avatar {
        color: #fff;
        font-size: 14px;
        font-weight: 800;
    }

    .tgn-letter-a {
        background: linear-gradient(145deg, #ffb05e, #ee6b73);
    }

    .tgn-letter-d {
        background: linear-gradient(145deg, #8d7af4, #5595eb);
    }

    .tgn-letter-n {
        background: linear-gradient(145deg, #58c7a4, #3f91c9);
    }

    .tgn-homebar {
        position: absolute;
        left: 50%;
        bottom: 5px;
        transform: translateX(-50%);
        width: 116px;
        height: 4px;
        border-radius: 999px;
        background: #111;
        z-index: 10;
    }


    /* ---------- wizard bottom actions / mobile keyboard ----------
   Keep Back and Continue on one compact row, visually attached to the
   bottom navigation. On mobile keyboard open, both fixed chrome layers
   temporarily leave the viewport so inputs never sit behind them. */
    :root {
        --ap-action-bar-h: 70px;
    }

    .wizard-actions {
        display: grid !important;
        grid-template-columns: minmax(96px, 0.34fr) minmax(0, 0.66fr) !important;
        grid-template-rows: 52px !important;
        align-items: stretch !important;
        gap: 8px !important;
        inset-block-end: var(--ap-shell-bottom) !important;
        padding: 8px 12px 10px !important;
        border-block-start: 1px solid rgba(200, 213, 224, .92) !important;
        border-block-end: 0 !important;
        background: rgba(255, 255, 255, .965) !important;
        -webkit-backdrop-filter: blur(14px) saturate(1.12);
        backdrop-filter: blur(14px) saturate(1.12);
        box-shadow: 0 -10px 28px rgba(18, 34, 47, .06) !important;
        transition: transform 180ms ease, opacity 160ms ease, visibility 160ms ease !important;
    }

    .wizard-actions>[hidden] {
        display: none !important;
    }

    .wizard-actions [data-wizard-prev] {
        grid-column: 1 !important;
        grid-row: 1 !important;
        width: 100% !important;
        min-width: 0 !important;
        min-height: 48px !important;
        align-self: center !important;
        border: 1px solid #d5e0e8 !important;
        border-radius: 12px !important;
        background: #f5f8fb !important;
        color: #486276 !important;
        font-size: 13px !important;
        font-weight: 720 !important;
        box-shadow: none !important;
    }

    .wizard-actions [data-wizard-next-btn],
    .wizard-actions [data-wizard-submit-btn] {
        grid-column: 2 !important;
        grid-row: 1 !important;
        width: 100% !important;
        min-width: 0 !important;
        min-height: 52px !important;
        border-radius: 12px !important;
        font-size: 15px !important;
        font-weight: 760 !important;
    }

    .mini-bottom-nav {
        transition: transform 180ms ease, opacity 160ms ease, visibility 160ms ease !important;
    }

    html.ap-keyboard-open .wizard-actions,
    html.ap-keyboard-open .mini-bottom-nav {
        transform: translateY(125%) !important;
        opacity: 0 !important;
        visibility: hidden !important;
        pointer-events: none !important;
    }

    html.ap-keyboard-open .mini-content.has-wizard-action {
        padding-block-end: 28px !important;
    }

    html.ap-keyboard-open {
        scroll-padding-block-end: 24px;
    }

    .tgn-preview.has-live-media .tgn-host-post {
        max-height: 122px;
        overflow: hidden;
    }

    .tgn-preview.has-live-media .tgn-sponsored-card {
        padding-bottom: 7px;
    }

    @media (max-width: 620px) {
        .ad-content-layout {
            grid-template-columns: minmax(0, 1fr) !important;
        }

        .tgn-preview {
            height: 548px;
            border-width: 4px;
            border-radius: 30px;
        }
    }

    @media (max-width: 380px) {
        .tgn-preview {
            height: 520px;
            border-radius: 27px;
        }

        .tgn-status {
            height: 36px;
            padding-top: 9px;
        }

        .tgn-header {
            height: 60px;
        }

        .tgn-view {
            inset-block-start: 96px;
        }

        .tgn-host-post {
            font-size: 9.3px;
        }

        .tgn-sponsored-card {
            padding-block: 8px 7px;
        }

        .tgn-channel-ad-wrap {
            margin-top: 3px;
        }
    }
</style>

<div class="tgn-preview-wrap">
    <p class="field-label tgn-preview-heading">
        <span>{{ $isFa ? 'پیش‌نمایش زنده' : 'Live preview' }}</span>
        <span class="tgn-preview-context" data-preview-context-label>{{ $isFa ? 'کانال' : 'Channel' }}</span>
    </p>

    <div class="tgn-preview" data-preview-stage data-preview-placement="channel_posts" aria-label="{{ $isFa ? 'پیش‌نمایش تبلیغ در تلگرام' : 'Telegram ad preview' }}">
        <div class="tgn-status" aria-hidden="true">
            <span>9:41</span>
            <span class="tgn-dynamic-island"></span>
            <span class="tgn-status-right">
                <span class="tgn-signal"><i></i><i></i><i></i><i></i></span>
                <span class="tgn-wifi"></span>
                <span class="tgn-battery"></span>
            </span>
        </div>

        {{-- CHANNEL HOST HEADER: intentionally generic. The advertiser identity
             is rendered inside the sponsored card, matching Telegram's feed UI. --}}
        <header class="tgn-header" data-tg-header="channel_posts">
            <span class="tgn-back" aria-hidden="true">‹</span>
            <span class="tgn-avatar channel" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                    <path d="M4.4 11.2 18.8 5.6c.7-.3 1.3.2 1.1 1l-2.4 11.2c-.2.8-.7 1-1.4.6l-3.7-2.7-1.8 1.7c-.2.2-.4.4-.8.4l.3-3.8 6.9-6.2c.3-.3-.1-.4-.5-.2L8 13l-3.6-1.1c-.8-.3-.8-.8 0-1.1Z" />
                </svg>
            </span>
            <span class="tgn-header-copy">
                <strong>{{ $isFa ? 'تحلیل و رشد' : 'Analytics & Growth' }}</strong>
                <small>{{ $isFa ? '۸٫۲K مشترک' : '8.2K subscribers' }}</small>
            </span>
            <span class="tgn-menu" aria-hidden="true">⋮</span>
        </header>

        {{-- BOT HOST HEADER --}}
        <header class="tgn-header" data-tg-header="bot_messages" hidden>
            <span class="tgn-back" aria-hidden="true">‹</span>
            <span class="tgn-avatar bot" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                    <path d="M4.4 11.2 18.8 5.6c.7-.3 1.3.2 1.1 1l-2.4 11.2c-.2.8-.7 1-1.4.6l-3.7-2.7-1.8 1.7c-.2.2-.4.4-.8.4l.3-3.8 6.9-6.2c.3-.3-.1-.4-.5-.2L8 13l-3.6-1.1c-.8-.3-.8-.8 0-1.1Z" />
                </svg>
            </span>
            <span class="tgn-header-copy">
                <strong>{{ $isFa ? 'ربات خدماتی' : 'Service Bot' }}</strong>
                <small>{{ $isFa ? '۴۵٬۳۲۴ کاربر ماهانه' : '45,324 monthly users' }}</small>
            </span>
            <span class="tgn-menu" aria-hidden="true">⋮</span>
        </header>

        {{-- SEARCH HEADER --}}
        <header class="tgn-header tgn-search-header" data-tg-header="search_results" hidden>
            <span class="tgn-search-cancel">{{ $isFa ? 'لغو' : 'Cancel' }}</span>
            <span class="tgn-searchbox">
                <svg viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="7"></circle>
                    <path d="m20 20-3.5-3.5"></path>
                </svg>
                <span data-preview-search-query dir="auto">{{ $isFa ? 'جستجو در تلگرام' : 'Search Telegram' }}</span>
            </span>
        </header>

        {{-- ====================== CHANNEL ====================== --}}
        <section class="tgn-view tgn-view-channel" data-preview-view="channel_posts">
            <div class="tgn-channel-wall">
                <div class="tgn-pinned" aria-hidden="true">
                    <span class="tgn-pinned-line"></span>
                    <span class="tgn-pinned-copy"><b>{{ $isFa ? 'پیام سنجاق‌شده' : 'Pinned Message' }}</b><span>{{ $isFa ? 'راهنمای امروز و آخرین به‌روزرسانی کانال…' : 'Today’s guide and latest channel update…' }}</span></span>
                    <span class="tgn-pin">⌖</span>
                </div>

                <article class="tgn-host-post" aria-hidden="true">
                    <p>
                        <strong>1.</strong> {{ $isFa ? 'راهنمای کوتاه رشد کانال با داده' : 'Data-driven channel growth guide' }}<br>
                        <strong>2.</strong> {{ $isFa ? 'چطور نرخ تعامل را بهتر کنیم' : 'How to improve engagement' }}<br>
                        <strong>3.</strong> <span class="tgn-host-links">{{ $isFa ? 'تحلیل رفتار مخاطب و زمان انتشار' : 'Audience behavior & publishing time' }}</span><br>
                        <strong>4.</strong> {{ $isFa ? 'چک‌لیست محتوای این هفته' : 'This week’s content checklist' }}
                    </p>
                    <div class="tgn-reactions"><span class="tgn-reaction">👍 10</span><span class="tgn-reaction">🔥 7</span><span class="tgn-reaction">❤️ 3</span></div>
                    <div class="tgn-post-meta"><span>◉ 1.3K</span><span>14:09</span></div>
                    <div class="tgn-comment-row"><span>◯ {{ $isFa ? 'ارسال نظر' : 'Leave a comment' }}</span><span>›</span></div>
                </article>

                <div class="tgn-channel-ad-wrap">
                    <article class="tgn-sponsored-card">
                        <div class="tgn-ad-kicker"><span class="tgn-ad-label">Ad</span><span class="tgn-what">{{ $isFa ? 'این چیست؟' : "what's this?" }}</span></div>
                        <strong class="tgn-sponsored-title" data-preview-title dir="auto">{{ $previewDefaultTitle }}</strong>
                        <p class="tgn-sponsored-text ios-tg-msg-text" id="ad-preview-text" data-placeholder="{{ $isFa ? 'متن تبلیغ شما اینجا نمایش داده می‌شود.' : 'Your ad copy will appear here.' }}" dir="auto">{{ $previewDefaultText }}</p>
                        <div class="tgn-sponsored-media" data-preview-media-slot hidden>
                            <img id="ad-preview-media" alt="" hidden>
                            <video id="ad-preview-video" muted playsinline controls preload="metadata" hidden></video>
                        </div>
                        <div class="tgn-sponsored-cta">{{ $isFa ? 'مشاهده کانال' : 'VIEW CHANNEL' }}</div>
                    </article>
                    <span class="tgn-ad-side" aria-hidden="true"><span>×</span><span>⋮</span></span>
                </div>
            </div>
        </section>

        {{-- ======================== BOT ======================== --}}
        <section class="tgn-view tgn-view-bot" data-preview-view="bot_messages" hidden>
            <div class="tgn-bot-sponsored">
                <div class="tgn-bot-ad-top">
                    <span class="tgn-ad-label">Ad</span>
                    <strong class="tgn-bot-ad-title" data-preview-title dir="auto">{{ $previewDefaultTitle }}</strong>
                    <span class="tgn-what">{{ $isFa ? 'این چیست؟' : "what's this?" }}</span>
                </div>
                <p class="tgn-bot-ad-text ios-tg-msg-text" data-placeholder="{{ $isFa ? 'متن تبلیغ شما اینجا نمایش داده می‌شود.' : 'Your ad copy will appear here.' }}" dir="auto">{{ $previewDefaultText }}</p>
                <span class="tgn-bot-close" aria-hidden="true">×</span>
            </div>
            <div class="tgn-bot-wall">
                <div class="tgn-day">{{ $isFa ? 'امروز' : 'Today' }}</div>
                <div class="tgn-bubble-wrap">
                    <span class="tgn-mini-avatar" aria-hidden="true"><svg viewBox="0 0 24 24">
                            <path d="M4.4 11.2 18.8 5.6c.7-.3 1.3.2 1.1 1l-2.4 11.2c-.2.8-.7 1-1.4.6l-3.7-2.7-1.8 1.7c-.2.2-.4.4-.8.4l.3-3.8 6.9-6.2c.3-.3-.1-.4-.5-.2L8 13l-3.6-1.1c-.8-.3-.8-.8 0-1.1Z" />
                        </svg></span>
                    <article class="tgn-bubble" aria-hidden="true">
                        <b>{{ $isFa ? 'شروع کنیم 👋' : "Let's get started 👋" }}</b>
                        <p>{{ $isFa ? 'برای ادامه، دکمه زیر را لمس کنید.' : 'Tap the button below to continue.' }}</p>
                        <time>9:41</time>
                    </article>
                </div>
                <button class="tgn-inline-btn" type="button" tabindex="-1">{{ $isFa ? 'شروع ربات' : 'Start Bot' }}</button>
                <div class="tgn-compose" aria-hidden="true"><span class="tgn-compose-ico">☺</span><span class="tgn-compose-field">{{ $isFa ? 'پیام…' : 'Message…' }}</span><span class="tgn-compose-ico">⌕</span><span class="tgn-compose-ico">◉</span></div>
            </div>
        </section>

        {{-- ====================== SEARCH ======================= --}}
        <section class="tgn-view tgn-view-search" data-preview-view="search_results" hidden>
            <div class="tgn-search-body">
                <div class="tgn-search-tabs" aria-hidden="true">
                    <span class="tgn-search-tab active">{{ $isFa ? 'همه' : 'All' }}</span>
                    <span class="tgn-search-tab">{{ $isFa ? 'گفتگوها' : 'Chats' }}</span>
                    <span class="tgn-search-tab">{{ $isFa ? 'رسانه' : 'Media' }}</span>
                    <span class="tgn-search-tab">{{ $isFa ? 'لینک‌ها' : 'Links' }}</span>
                </div>
                <div class="tgn-search-title">{{ $isFa ? 'نتیجه پیشنهادی' : 'Suggested' }}</div>

                <div class="tgn-search-result sponsored">
                    <span class="tgn-avatar search" aria-hidden="true"><svg viewBox="0 0 24 24">
                            <path d="M4.4 11.2 18.8 5.6c.7-.3 1.3.2 1.1 1l-2.4 11.2c-.2.8-.7 1-1.4.6l-3.7-2.7-1.8 1.7c-.2.2-.4.4-.8.4l.3-3.8 6.9-6.2c.3-.3-.1-.4-.5-.2L8 13l-3.6-1.1c-.8-.3-.8-.8 0-1.1Z" />
                        </svg></span>
                    <span class="tgn-search-copy">
                        <span class="tgn-search-row-top"><strong data-preview-title dir="auto">{{ $previewDefaultTitle }}</strong><span class="tgn-search-ad">Ad</span></span>
                        <span class="tgn-search-sub" data-preview-username>@your_channel</span>
                        <p class="tgn-search-desc ios-tg-msg-text" data-placeholder="{{ $isFa ? 'متن تبلیغ شما اینجا نمایش داده می‌شود.' : 'Your ad copy will appear here.' }}" dir="auto">{{ $previewDefaultText }}</p>
                    </span>
                    <span class="tgn-chevron" aria-hidden="true">›</span>
                </div>
                <div class="tgn-search-cta">{{ $isFa ? 'باز کردن در تلگرام' : 'Open in Telegram' }}</div>

                <div class="tgn-search-title">{{ $isFa ? 'نتایج' : 'Results' }}</div>
                <div class="tgn-search-result tgn-search-muted" aria-hidden="true">
                    <span class="tgn-avatar tgn-letter-a">A</span><span class="tgn-search-copy"><span class="tgn-search-row-top"><strong>AI Daily</strong></span><span class="tgn-search-sub">@ai_daily</span>
                        <p class="tgn-search-desc">Technology, AI and product updates</p>
                    </span><span class="tgn-chevron">›</span>
                </div>
                <div class="tgn-search-result tgn-search-muted" aria-hidden="true">
                    <span class="tgn-avatar tgn-letter-d">D</span><span class="tgn-search-copy"><span class="tgn-search-row-top"><strong>Design Hub</strong></span><span class="tgn-search-sub">@design_hub</span>
                        <p class="tgn-search-desc">UI, product design and inspiration</p>
                    </span><span class="tgn-chevron">›</span>
                </div>
                <div class="tgn-search-result tgn-search-muted" aria-hidden="true">
                    <span class="tgn-avatar tgn-letter-n">N</span><span class="tgn-search-copy"><span class="tgn-search-row-top"><strong>News Room</strong></span><span class="tgn-search-sub">@news_room</span>
                        <p class="tgn-search-desc">Daily highlights and breaking news</p>
                    </span><span class="tgn-chevron">›</span>
                </div>
            </div>
        </section>

        <span class="tgn-homebar" aria-hidden="true"></span>
    </div>

    <p class="field-help" style="text-align:center;margin-top:2px">
        {{ $isFa ? 'پیش‌نمایش شبیه‌سازی‌شده بر اساس رابط موبایل تلگرام است و هم‌زمان با اطلاعات فرم به‌روزرسانی می‌شود.' : 'A Telegram-like mobile simulation that updates live from the form.' }}
    </p>
</div>


<script>
    (() => {
        const boot = () => {
            const stage = document.querySelector('.tgn-preview[data-preview-stage]');
            if (!stage) return;

            const isFa = document.documentElement.lang === 'fa';
            const adTextInput = document.querySelector('#ad-text');
            const titleInput = document.querySelector('#internal-title');
            const urlInput = document.querySelector('#destination-url');
            const keywordInput = document.querySelector('#search-keyword-input');
            const keywordPicker = document.querySelector('[data-keyword-search]');
            const mediaInput = document.querySelector('#ad-media');
            const mediaSlot = stage.querySelector('[data-preview-media-slot]');
            const mediaImg = stage.querySelector('#ad-preview-media');
            const mediaVideo = stage.querySelector('#ad-preview-video');
            const searchQuery = stage.querySelector('[data-preview-search-query]');
            const contextLabel = document.querySelector('[data-preview-context-label]');
            const placementInputs = document.querySelectorAll('[data-placement-option]');
            let mediaObjectUrl = null;

            const parseTelegramUsername = (raw) => {
                const value = String(raw || '').trim();
                if (!value) return '';
                const match = value.match(/(?:https?:\/\/)?(?:www\.)?t\.me\/(?:s\/)?([^/?#]+)/i);
                if (match) {
                    const segment = match[1];
                    if (segment.startsWith('+')) return isFa ? 'کانال خصوصی' : 'Private channel';
                    return /^[A-Za-z0-9_]{3,}$/.test(segment) ? '@' + segment : segment;
                }
                return value.startsWith('@') ? value : '';
            };

            const syncText = () => {
                const fallback = isFa ? 'متن تبلیغ شما اینجا نمایش داده می‌شود.' : 'Your ad copy will appear here.';
                const value = (adTextInput?.value || '').trim() || fallback;
                stage.querySelectorAll('.ios-tg-msg-text').forEach((node) => {
                    node.textContent = value;
                });
            };

            const syncIdentity = () => {
                const title = (titleInput?.value || '').trim() || (isFa ? 'تبلیغ شما' : 'Your promotion');
                stage.querySelectorAll('[data-preview-title]').forEach((node) => {
                    node.textContent = title;
                });
                const placement = stage.dataset.previewPlacement || 'channel_posts';
                const parsed = parseTelegramUsername(urlInput?.value || '');
                const fallback = placement === 'bot_messages' ? '@your_bot' : '@your_channel';
                stage.querySelectorAll('[data-preview-username]').forEach((node) => {
                    node.textContent = parsed || fallback;
                });
            };

            const firstSavedKeyword = () => {
                return keywordPicker?.querySelector('[data-keyword-search-hidden] input[name="search_keywords[]"]')?.value?.trim() || '';
            };

            const syncSearch = () => {
                if (!searchQuery) return;
                const typing = (keywordInput?.value || '').trim();
                const saved = firstSavedKeyword();
                searchQuery.textContent = typing || saved || (isFa ? 'جستجو در تلگرام' : 'Search Telegram');
            };

            const syncPlacement = (placement) => {
                if (!placement) return;
                stage.dataset.previewPlacement = placement;
                stage.querySelectorAll('[data-preview-view]').forEach((view) => {
                    view.hidden = view.dataset.previewView !== placement;
                });
                stage.querySelectorAll('[data-tg-header]').forEach((header) => {
                    header.hidden = header.dataset.tgHeader !== placement;
                });
                if (contextLabel) {
                    contextLabel.textContent = ({
                        channel_posts: isFa ? 'کانال' : 'Channel',
                        bot_messages: isFa ? 'ربات' : 'Bot',
                        search_results: isFa ? 'جستجو' : 'Search',
                    })[placement] || (isFa ? 'کانال' : 'Channel');
                }
                syncIdentity();
                syncSearch();
            };

            const clearMedia = () => {
                if (mediaObjectUrl) {
                    URL.revokeObjectURL(mediaObjectUrl);
                    mediaObjectUrl = null;
                }
                if (mediaImg) {
                    mediaImg.removeAttribute('src');
                    mediaImg.hidden = true;
                }
                if (mediaVideo) {
                    try {
                        mediaVideo.pause();
                    } catch (_) {}
                    mediaVideo.removeAttribute('src');
                    mediaVideo.load?.();
                    mediaVideo.hidden = true;
                }
                if (mediaSlot) mediaSlot.hidden = true;
                stage.classList.remove('has-live-media');
            };

            const syncMedia = () => {
                const file = mediaInput?.files?.[0];
                clearMedia();
                if (!file || !mediaSlot) return;

                mediaObjectUrl = URL.createObjectURL(file);
                const isVideo = file.type.startsWith('video/');
                const isImage = file.type.startsWith('image/');

                if (isVideo && mediaVideo) {
                    mediaVideo.src = mediaObjectUrl;
                    mediaVideo.hidden = false;
                    mediaSlot.hidden = false;
                    stage.classList.add('has-live-media');
                    mediaVideo.load();
                    return;
                }

                if (isImage && mediaImg) {
                    mediaImg.src = mediaObjectUrl;
                    mediaImg.alt = file.name || '';
                    mediaImg.hidden = false;
                    mediaSlot.hidden = false;
                    stage.classList.add('has-live-media');
                }
            };

            adTextInput?.addEventListener('input', syncText);
            titleInput?.addEventListener('input', syncIdentity);
            urlInput?.addEventListener('input', syncIdentity);
            keywordInput?.addEventListener('input', syncSearch);
            keywordInput?.addEventListener('focus', syncSearch);
            keywordPicker?.addEventListener('keyword-search:change', () => requestAnimationFrame(syncSearch));
            mediaInput?.addEventListener('change', syncMedia);
            placementInputs.forEach((input) => input.addEventListener('change', () => syncPlacement(input.value)));

            syncText();
            syncIdentity();
            syncSearch();
            syncPlacement(document.querySelector('[data-placement-option]:checked')?.value || 'channel_posts');
            syncMedia();

            window.addEventListener('pagehide', () => {
                if (mediaObjectUrl) URL.revokeObjectURL(mediaObjectUrl);
            }, {
                once: true
            });

            /* Keyboard-aware fixed chrome. visualViewport is the reliable signal
               in Telegram/iOS WebViews. We keep the largest non-keyboard viewport
               as a baseline and only enter keyboard mode while an editable control
               is actually focused. */
            const vv = window.visualViewport;
            let baselineHeight = vv?.height || window.innerHeight;
            const editableSelector = 'input:not([type="checkbox"]):not([type="radio"]):not([type="file"]), textarea, select, [contenteditable="true"]';

            const isEditableFocused = () => {
                const active = document.activeElement;
                return !!(active && active.matches?.(editableSelector));
            };

            const updateKeyboardState = () => {
                const currentHeight = vv?.height || window.innerHeight;
                if (!isEditableFocused()) baselineHeight = Math.max(baselineHeight, currentHeight);
                const keyboardDelta = Math.max(0, baselineHeight - currentHeight);
                const open = isEditableFocused() && keyboardDelta > 110;
                document.documentElement.classList.toggle('ap-keyboard-open', open);
                document.documentElement.style.setProperty('--ap-keyboard-height', `${Math.round(keyboardDelta)}px`);
            };

            const keepFocusedFieldVisible = (target) => {
                if (!target?.matches?.(editableSelector)) return;
                window.setTimeout(() => {
                    try {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center',
                            inline: 'nearest'
                        });
                    } catch (_) {}
                }, 180);
            };

            document.addEventListener('focusin', (event) => {
                if (!event.target?.matches?.(editableSelector)) return;
                keepFocusedFieldVisible(event.target);
                window.setTimeout(updateKeyboardState, 80);
                window.setTimeout(updateKeyboardState, 260);
            });
            document.addEventListener('focusout', () => {
                window.setTimeout(updateKeyboardState, 180);
            });
            vv?.addEventListener('resize', updateKeyboardState, {
                passive: true
            });
            vv?.addEventListener('scroll', updateKeyboardState, {
                passive: true
            });
            window.addEventListener('orientationchange', () => {
                baselineHeight = vv?.height || window.innerHeight;
                window.setTimeout(updateKeyboardState, 220);
            }, {
                passive: true
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot, {
                once: true
            });
        } else {
            boot();
        }
    })();
</script>
