const form = document.querySelector('[data-miniapp-session]');
const errorBox = document.querySelector('[data-session-error]');
const errorHint = document.querySelector('[data-session-error-hint]');
const retryButton = document.querySelector('[data-session-retry]');
const token = new URLSearchParams(window.location.search).get('t') || '';
let authenticating = false;
let sdkPromise = null;

const showError = (message = '') => {
    if (errorBox) errorBox.hidden = false;
    if (errorHint) errorHint.textContent = message || errorBox?.dataset.unavailableHint || '';
};

const hideError = () => {
    if (errorBox) errorBox.hidden = true;
    if (errorHint) errorHint.textContent = '';
};

const applyTelegramChrome = () => {
    const telegram = window.Telegram?.WebApp;
    if (!telegram) return;

    try {
        telegram.ready();
        telegram.expand();
        telegram.setHeaderColor?.('#ffffff');
        telegram.setBackgroundColor?.('#f5f8fc');
        telegram.setBottomBarColor?.('#ffffff');
    } catch (_) {
        // Telegram chrome is cosmetic and must never block authentication.
    }
};

const loadTelegramSdk = () => {
    if (window.Telegram?.WebApp) return Promise.resolve(window.Telegram.WebApp);
    if (sdkPromise) return sdkPromise;

    sdkPromise = new Promise((resolve) => {
        const script = document.createElement('script');
        let settled = false;
        const finish = () => {
            if (settled) return;
            settled = true;
            applyTelegramChrome();
            resolve(window.Telegram?.WebApp || null);
        };

        script.src = 'https://telegram.org/js/telegram-web-app.js';
        script.async = true;
        script.onload = finish;
        script.onerror = finish;
        document.head.append(script);
        window.setTimeout(finish, 1800);
    });

    return sdkPromise;
};

const waitForSignedInitData = async () => {
    const telegram = await loadTelegramSdk();
    if (!telegram) return '';

    const deadline = performance.now() + 900;
    do {
        if (typeof telegram.initData === 'string' && telegram.initData.length > 0) {
            return telegram.initData;
        }
        await new Promise((resolve) => window.setTimeout(resolve, 50));
    } while (performance.now() < deadline);

    return '';
};

const postSession = async (initData) => {
    if (!form || authenticating) return false;
    authenticating = true;
    hideError();
    if (retryButton) retryButton.disabled = true;

    const payload = new FormData(form);
    payload.set('init_data', initData || '');
    payload.set('token', token);
    payload.delete('init_data_unsafe');

    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 12000);

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.redirect) {
            throw new Error(result.message || `Session request failed (${response.status})`);
        }

        window.location.replace(result.redirect);
        return true;
    } catch (error) {
        showError(error?.name === 'AbortError' ? '' : error?.message || '');
        return false;
    } finally {
        window.clearTimeout(timeout);
        authenticating = false;
        if (retryButton) retryButton.disabled = false;
    }
};

const authenticate = async () => {
    // Bot buttons include a high-entropy user token. Use it immediately and
    // load the Telegram SDK in parallel instead of adding an artificial wait.
    if (token) {
        const existingInitData = window.Telegram?.WebApp?.initData || '';
        loadTelegramSdk();
        if (await postSession(existingInitData)) return;
    }

    const initData = await waitForSignedInitData();
    if (!initData) {
        showError();
        return;
    }

    await postSession(initData);
};

retryButton?.addEventListener('click', authenticate);
authenticate();
