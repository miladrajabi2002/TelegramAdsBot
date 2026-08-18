import '@fontsource-variable/vazirmatn';
import '@fontsource-variable/manrope';

const ready = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
        return;
    }

    callback();
};

ready(() => {
    document.documentElement.style.colorScheme = 'light';

    const telegram = window.Telegram?.WebApp;
    if (telegram) {
        try {
            telegram.ready();
            telegram.expand();
            telegram.setHeaderColor?.('#ffffff');
            telegram.setBackgroundColor?.('#f5f8fc');
            telegram.setBottomBarColor?.('#ffffff');

            document.querySelectorAll('form[data-telegram-auth]').forEach((form) => {
                let field = form.querySelector('input[name="telegram_init_data"]');
                if (!field) {
                    field = document.createElement('input');
                    field.type = 'hidden';
                    field.name = 'telegram_init_data';
                    form.append(field);
                }
                field.value = telegram.initData || '';
            });
        } catch (_) {
            document.documentElement.dataset.telegramState = 'fallback';
        }
    }

    const sessionForm = document.querySelector('[data-miniapp-session]');
    if (sessionForm) {
        const sessionError = document.querySelector('[data-session-error]');
        const submitButton = sessionForm.querySelector('[type="submit"]');
        const initDataField = sessionForm.querySelector('input[name="init_data"]');
        const initData = telegram?.initData || '';
        let authenticating = false;

        const showSessionError = () => {
            if (sessionError) sessionError.hidden = false;
            if (submitButton) submitButton.disabled = true;
        };

        const authenticate = async (event) => {
            event?.preventDefault();
            if (authenticating || !initData || sessionForm.action.endsWith('#')) {
                if (!initData) showSessionError();
                return;
            }

            authenticating = true;
            if (initDataField) initDataField.value = initData;
            submitButton?.classList.add('is-loading');
            submitButton?.setAttribute('aria-busy', 'true');
            if (submitButton) submitButton.disabled = true;

            try {
                const response = await fetch(sessionForm.action, {
                    method: 'POST',
                    body: new FormData(sessionForm),
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                });
                if (!response.ok) throw new Error(`Session request failed (${response.status})`);
                const result = await response.json();
                if (!result.redirect) throw new Error('Session redirect is missing');
                window.location.replace(result.redirect);
            } catch (_) {
                authenticating = false;
                submitButton?.classList.remove('is-loading');
                submitButton?.removeAttribute('aria-busy');
                showSessionError();
            }
        };

        sessionForm.addEventListener('submit', authenticate);
        if (initData) {
            if (submitButton) submitButton.disabled = false;
            authenticate();
        } else {
            showSessionError();
        }
    }

    document.querySelectorAll('[data-request-contact]').forEach((button) => {
        const status = document.querySelector(button.dataset.contactStatus || '[data-contact-status]');
        button.addEventListener('click', () => {
            if (!telegram?.requestContact) {
                if (status) {
                    status.hidden = false;
                    status.textContent = button.dataset.unsupportedMessage || 'Open the bot chat and use its official share-contact button.';
                }
                return;
            }

            button.disabled = true;
            button.classList.add('is-loading');
            telegram.requestContact((shared) => {
                button.classList.remove('is-loading');
                if (!shared) {
                    button.disabled = false;
                    return;
                }
                if (status) {
                    status.hidden = false;
                    status.textContent = button.dataset.successMessage || 'Contact shared. Refreshing verification status…';
                }
                window.setTimeout(() => window.location.reload(), 1800);
            });
        });
    });

    document.querySelectorAll('[data-count-target]').forEach((input) => {
        const target = document.querySelector(input.dataset.countTarget);
        const max = Number(input.getAttribute('maxlength') || input.dataset.maxlength || 160);

        const update = () => {
            if (!target) return;
            const count = Array.from(input.value).length;
            target.textContent = `${count} / ${max}`;
            target.classList.toggle('is-near', count >= max * 0.85 && count <= max);
            target.classList.toggle('is-over', count > max);

            const preview = input.dataset.previewTarget
                ? document.querySelector(input.dataset.previewTarget)
                : null;
            if (preview) preview.textContent = input.value || preview.dataset.placeholder || '—';
        };

        input.addEventListener('input', update);
        update();
    });

    document.querySelectorAll('[data-preview-input]').forEach((input) => {
        const image = document.querySelector(input.dataset.previewInput);
        const box = input.closest('.upload-box');
        input.addEventListener('change', () => {
            const [file] = input.files || [];
            if (!file || !image) {
                box?.classList.remove('has-preview');
                return;
            }

            const objectUrl = URL.createObjectURL(file);
            image.onload = () => URL.revokeObjectURL(objectUrl);
            image.src = objectUrl;
            image.alt = file.name;
            box?.classList.add('has-preview');
        });
    });

    document.querySelectorAll('[data-category-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            const value = button.dataset.categoryFilter;
            const scope = button.closest('[data-channel-picker]') || document;

            scope.querySelectorAll('[data-category-filter]').forEach((item) => {
                item.classList.toggle('is-active', item === button);
                item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
            });

            scope.querySelectorAll('[data-channel-category]').forEach((row) => {
                const categories = (row.dataset.channelCategory || '').split(',');
                row.hidden = value !== 'all' && !categories.includes(value);
            });
        });
    });

    document.querySelectorAll('[data-wizard]').forEach((wizard) => {
        const panes = Array.from(wizard.querySelectorAll('[data-wizard-step]'));
        const progress = wizard.querySelector('[data-wizard-progress]');
        const currentLabel = wizard.querySelector('[data-wizard-current]');
        let current = Math.max(0, Number(wizard.dataset.initialStep || 1) - 1);

        const render = () => {
            panes.forEach((pane, index) => {
                pane.hidden = index !== current;
                pane.setAttribute('aria-hidden', index === current ? 'false' : 'true');
            });
            if (progress) progress.style.setProperty('--progress', `${((current + 1) / panes.length) * 100}%`);
            if (currentLabel) currentLabel.textContent = String(current + 1);
            wizard.querySelectorAll('[data-wizard-prev]').forEach((button) => {
                button.disabled = current === 0;
            });
            wizard.querySelectorAll('[data-wizard-next]').forEach((button) => {
                button.hidden = current === panes.length - 1;
            });
            wizard.querySelectorAll('[data-wizard-submit]').forEach((button) => {
                button.hidden = current !== panes.length - 1;
            });
        };

        wizard.addEventListener('click', (event) => {
            const next = event.target.closest('[data-wizard-next]');
            const previous = event.target.closest('[data-wizard-prev]');
            if (!next && !previous) return;

            event.preventDefault();
            if (next) {
                const pane = panes[current];
                const invalid = pane?.querySelector(':invalid');
                if (invalid) {
                    invalid.reportValidity();
                    invalid.focus();
                    return;
                }
                current = Math.min(panes.length - 1, current + 1);
            } else {
                current = Math.max(0, current - 1);
            }
            render();
            wizard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        render();
    });

    document.querySelectorAll('[data-drawer-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const drawer = document.querySelector(button.dataset.drawerToggle);
            const scrim = document.querySelector('[data-drawer-scrim]');
            const willOpen = !drawer?.classList.contains('is-open');
            drawer?.classList.toggle('is-open', willOpen);
            scrim?.classList.toggle('is-open', willOpen);
            button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            drawer?.setAttribute('aria-hidden', willOpen ? 'false' : 'true');
        });
    });

    const closeDrawers = () => {
        document.querySelectorAll('.drawer.is-open').forEach((drawer) => {
            drawer.classList.remove('is-open');
            drawer.setAttribute('aria-hidden', 'true');
        });
        document.querySelector('[data-drawer-scrim]')?.classList.remove('is-open');
        document.querySelectorAll('[data-drawer-toggle]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
    };
    document.querySelectorAll('[data-drawer-close], [data-drawer-scrim]').forEach((item) => item.addEventListener('click', closeDrawers));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeDrawers();
    });

    document.querySelectorAll('[data-reveal-sensitive]').forEach((button) => {
        button.addEventListener('click', () => {
            const media = button.closest('.sensitive-media');
            media?.classList.add('is-revealed');
            button.setAttribute('aria-expanded', 'true');
        });
    });

    document.querySelectorAll('[data-require-field]').forEach((button) => {
        const form = button.closest('form');
        const field = form?.querySelector(button.dataset.requireField);
        if (!form || !field) return;

        const clearCustomError = () => field.setCustomValidity('');
        field.addEventListener('input', clearCustomError);

        form.querySelectorAll('[type="submit"]').forEach((submitter) => {
            if (submitter === button) return;
            submitter.addEventListener('click', () => {
                field.required = false;
                clearCustomError();
            });
        });

        button.addEventListener('click', () => {
            field.required = true;
            if (!field.value.trim() && button.dataset.requiredMessage) {
                field.setCustomValidity(button.dataset.requiredMessage);
            }
        });
    });

    document.querySelectorAll('[data-confirm]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            const message = trigger.dataset.confirm;
            if (message && !window.confirm(message)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
    });

    document.querySelectorAll('form[data-loading-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const submit = event.submitter || form.querySelector('[type="submit"]');
            if (!submit) return;
            submit.disabled = true;
            submit.classList.add('is-loading');
            submit.setAttribute('aria-busy', 'true');
        });
    });

    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const value = button.dataset.copy || '';
            try {
                await navigator.clipboard.writeText(value);
                const original = button.getAttribute('aria-label');
                button.setAttribute('aria-label', button.dataset.copiedLabel || 'Copied');
                setTimeout(() => button.setAttribute('aria-label', original || 'Copy'), 1600);
            } catch (_) {
                // Clipboard can be unavailable inside older Telegram WebViews.
            }
        });
    });

    if ('serviceWorker' in navigator && (window.isSecureContext || location.hostname === 'localhost')) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
                // PWA support is progressive; the web app remains fully usable without it.
            });
        }, { once: true });
    }
});
