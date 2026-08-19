import '@fontsource-variable/vazirmatn';
import '@fontsource-variable/manrope';

const ready = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
        return;
    }

    callback();
};

/**
 * Wait for the Telegram WebApp SDK to be fully ready BEFORE reading initData.
 *
 * The previous implementation called telegram.ready() with no callback and
 * then synchronously read telegram.initData. In some Telegram clients (older
 * Android, Telegram Desktop, Web A/B variants) initData is populated only
 * after the ready event fires — reading it too early yielded an empty string
 * and the user saw "Telegram sign-in data is unavailable" forever, with no
 * recovery path.
 *
 * Now we wait for the ready callback (or a SHORT fallback timer) and only
 * treat initData as truly missing after that point. We also expose a manual
 * retry button so the user can re-attempt without restarting Telegram.
 *
 * Optimisation (post-feedback): we no longer wait the full 4 seconds for the
 * SDK to call back. Telegram populates initData within ~50ms in modern
 * clients; 1.5s is plenty. We also fast-path: if `telegram.initData` is
 * already a non-empty string the moment we touch the SDK, we fire immediately
 * rather than waiting for the callback at all.
 */
const onTelegramReady = (callback) => {
    const telegram = window.Telegram?.WebApp;

    if (!telegram) {
        callback();
        return;
    }

    let fired = false;
    const run = () => {
        if (fired) return;
        fired = true;
        callback();
    };

    // Fast path: initData is already populated (modern Telegram client).
    if (typeof telegram.initData === 'string' && telegram.initData.length > 0) {
        try { telegram.ready(); } catch (_) { /* ignore */ }
        run();
        return;
    }

    try {
        telegram.ready(run);
    } catch (_) {
        run();
        return;
    }

    // Short fallback — previously 4s, now 1.5s so users don't see the loader
    // for very long when the SDK is misbehaving.
    setTimeout(run, 1500);
};

ready(() => {
    document.documentElement.style.colorScheme = 'light';

    const sessionForm = document.querySelector('[data-miniapp-session]');
    const sessionError = document.querySelector('[data-session-error]');
    const sessionErrorHint = document.querySelector('[data-session-error-hint]');
    const submitButton = sessionForm?.querySelector('[type="submit"]');
    const retryButton = document.querySelector('[data-session-retry]');
    const buttonLabel = document.querySelector('[data-session-button-label]');
    const initDataField = sessionForm?.querySelector('input[name="init_data"]');

    const setLabel = (text) => {
        if (buttonLabel) buttonLabel.textContent = text;
    };

    /**
     * Apply Telegram chrome (expand, header color, etc.) and prefill any
     * `data-telegram-auth` forms with the verified initData. Must be called
     * AFTER `telegram.ready()` to ensure `initData` is populated — that's
     * why this lives inside `onTelegramReady` below.
     */
    const initTelegramChrome = (telegram) => {
        if (!telegram) return;
        try {
            telegram.expand();
            telegram.setHeaderColor?.('#ffffff');
            telegram.setBackgroundColor?.('#f5f8fc');
            telegram.setBottomBarColor?.('#ffffff');
        } catch (_) {
            // Non-fatal: chrome customisation is best-effort.
        }

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
    };

    const showSessionError = (hint) => {
        if (sessionError) sessionError.hidden = false;
        if (sessionErrorHint && hint) sessionErrorHint.textContent = hint;
        if (submitButton) submitButton.disabled = true;
        setLabel(sessionForm?.dataset.labelRetry || 'Retry sign-in');
    };

    const hideSessionError = () => {
        if (sessionError) sessionError.hidden = true;
        if (submitButton) submitButton.disabled = false;
        setLabel(sessionForm?.dataset.labelConnect || 'Connecting…');
    };

    const authenticate = async (telegram) => {
        if (!sessionForm) return;

        // ─── Multi-layer auth data collection ────────────────────────────
        // We collect ALL available authentication signals and send them to
        // the backend in one POST. The backend tries them in order:
        //   1. init_data        (cryptographically signed by Telegram)
        //   2. token            (magic_token from the URL `?t=…`)
        //   3. init_data_unsafe (Telegram's unsigned payload)
        //
        // This makes the Mini App work regardless of HOW the user opened it:
        //   - Clicked the inline button → init_data is populated → layer 1 wins
        //   - Opened the URL from a chat message → init_data is empty but the
        //     URL still has ?t=<token> → layer 2 wins
        //   - Older Telegram client that populates initDataUnsafe but not
        //     initData → layer 3 wins
        //   - None of the above → show the retry UI
        const initData = telegram?.initData || '';

        // Extract ?t=<token> from the URL (preserved when Telegram opens
        // a web_app button URL with query params).
        const urlToken = new URLSearchParams(window.location.search).get('t') || '';

        // Construct a JSON-encoded init_data_unsafe payload from Telegram's
        // unsigned initDataUnsafe object. We only send the user + auth_date
        // fields — no hash, since the backend won't try to verify it.
        let initDataUnsafe = '';
        if (telegram?.initDataUnsafe?.user) {
            try {
                initDataUnsafe = JSON.stringify({
                    user: telegram.initDataUnsafe.user,
                    auth_date: telegram.initDataUnsafe.auth_date || null,
                    start_param: telegram.initDataUnsafe.start_param || null,
                });
            } catch (_) {
                initDataUnsafe = '';
            }
        }

        // Bail out only if NO signal is available.
        if (!initData && !urlToken && !initDataUnsafe) {
            showSessionError(sessionError?.dataset.unavailableHint || '');
            return;
        }

        // Populate the visible init_data field (kept for backward compat
        // with any CSRF validation expectations) plus the two new fields.
        if (initDataField) initDataField.value = initData;

        // Append or update the hidden token + init_data_unsafe fields.
        const ensureField = (name) => {
            let field = sessionForm.querySelector(`input[name="${name}"]`);
            if (!field) {
                field = document.createElement('input');
                field.type = 'hidden';
                field.name = name;
                sessionForm.append(field);
            }
            return field;
        };
        const tokenField = ensureField('token');
        const unsafeField = ensureField('init_data_unsafe');
        tokenField.value = urlToken;
        unsafeField.value = initDataUnsafe;

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.classList.add('is-loading');
            submitButton.setAttribute('aria-busy', 'true');
        }

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
            if (submitButton) {
                submitButton.classList.remove('is-loading');
                submitButton.removeAttribute('aria-busy');
            }
            showSessionError(sessionError?.dataset.unavailableHint || '');
        }
    };

    // The retry button lets the user re-attempt after they've ensured the Mini
    // App is opened via the bot button (the most common fix). We re-run ready
    // + auth without a full page reload.
    if (retryButton) {
        retryButton.addEventListener('click', () => {
            hideSessionError();
            const telegram = window.Telegram?.WebApp;
            onTelegramReady(() => {
                initTelegramChrome(telegram);
                authenticate(telegram);
            });
        });
    }

    // Boot the Telegram SDK, then run auth once it's truly ready.
    const telegram = window.Telegram?.WebApp;
    onTelegramReady(() => {
        initTelegramChrome(telegram);

        // Try to authenticate with ANY of the three signals we now accept.
        // Previously, only `telegram.initData` was checked, which failed
        // whenever the user opened the URL via a non-button path.
        const urlToken = new URLSearchParams(window.location.search).get('t') || '';
        const hasUnsafeUser = !!telegram?.initDataUnsafe?.user;
        const hasAnySignal = !!(telegram?.initData || urlToken || hasUnsafeUser);

        if (hasAnySignal) {
            if (submitButton) submitButton.disabled = false;
            authenticate(telegram);
        } else {
            showSessionError(sessionError?.dataset.unavailableHint || '');
        }
    });

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
        const target = document.querySelector(input.dataset.previewInput);
        const box = input.closest('.upload-box');
        input.addEventListener('change', () => {
            const [file] = input.files || [];
            if (!file || !target) {
                box?.classList.remove('has-preview');
                return;
            }

            const objectUrl = URL.createObjectURL(file);
            // Both <img> and <video> previews are supported. We pick the
            // right one based on the target element's tag.
            if (target.tagName === 'IMG') {
                target.onload = () => URL.revokeObjectURL(objectUrl);
                target.src = objectUrl;
                target.alt = file.name;
            } else if (target.tagName === 'VIDEO') {
                target.onloadeddata = () => URL.revokeObjectURL(objectUrl);
                target.src = objectUrl;
                target.hidden = false;
            }
            box?.classList.add('has-preview');
        });
    });

    // ─── Generic "disable submit until form is valid" ────────────────────
    // Any form marked with [data-disable-until-valid] gets its primary
    // submit button disabled until the form passes :invalid. Used by the
    // KYC document upload form and any other form where we want the user
    // to see a clear "fill everything first" state.
    document.querySelectorAll('form[data-disable-until-valid]').forEach((form) => {
        const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        const update = () => {
            const valid = form.checkValidity();
            submitButtons.forEach((btn) => {
                btn.disabled = !valid;
                btn.classList.toggle('is-disabled-pending', !valid);
            });
        };
        form.addEventListener('input', update);
        form.addEventListener('change', update);
        update();
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
        const nextButtons = wizard.querySelectorAll('[data-wizard-next-btn]');
        const submitButtons = wizard.querySelectorAll('[data-wizard-submit-btn]');
        const prevButtons = wizard.querySelectorAll('[data-wizard-prev]');
        let current = Math.max(0, Number(wizard.dataset.initialStep || 1) - 1);

        // ── Branching placement logic ────────────────────────────────────
        // When the user picks a placement_type on step 1, the matching
        // placement-specific fields on step 2 (image/video upload for
        // channels, search-keyword tag input for search) become visible,
        // the iOS-style Telegram preview switches to the correct variant,
        // and the step-3 title/label changes to کانال های هدف / ربات های
        // هدف / جستجوی هدف.
        const placementInputs = wizard.querySelectorAll('[data-placement-option]');
        const placementFields = wizard.querySelectorAll('[data-placement-field]');
        const previewViews = wizard.querySelectorAll('[data-preview-view]');
        const previewStage = wizard.querySelector('[data-preview-stage]');
        const targetStepTitle = wizard.querySelector('[data-target-step-title]');
        const targetStepSubtitle = wizard.querySelector('[data-target-step-subtitle]');
        const searchInputLabel = wizard.querySelector('[data-search-input-label]');
        const isFa = document.documentElement.lang === 'fa';

        const placementCopy = {
            channel_posts: {
                title: isFa ? 'کانال‌های هدف' : 'Target channels',
                subtitle: isFa ? 'آیدی کانال را با Enter اضافه کنید. حذف با دکمه ×. حداقل یک کانال الزامی است.' : 'Add a channel ID with Enter. Remove with ×. At least one is required.',
                searchLabel: isFa ? 'سرچ کانال با آیدی یا لینک' : 'Search channel by ID or link',
            },
            bot_messages: {
                title: isFa ? 'ربات‌های هدف' : 'Target bots',
                subtitle: isFa ? 'آیدی ربات را با Enter اضافه کنید. حذف با دکمه ×. حداقل یک ربات الزامی است.' : 'Add a bot ID with Enter. Remove with ×. At least one is required.',
                searchLabel: isFa ? 'سرچ ربات با آیدی یا لینک' : 'Search bot by ID or link',
            },
            search_results: {
                title: isFa ? 'جستجوی هدف' : 'Target search',
                subtitle: isFa ? 'کلیدواژه‌های هدف را در مرحله ۲ وارد کرده‌اید. در این مرحله می‌توانید کانال‌های پیشنهادی را هم انتخاب کنید.' : 'You added target keywords in step 2. You may also pick suggested channels here.',
                searchLabel: isFa ? 'سرچ کانال با آیدی یا لینک' : 'Search channel by ID or link',
            },
        };

        const applyPlacement = (placement) => {
            if (!placement) return;
            // Show only the fields that belong to this placement.
            placementFields.forEach((field) => {
                const matches = field.dataset.placementField === placement;
                field.hidden = !matches;
                // Disable hidden fields so the browser doesn't validate them
                // when computing :invalid on the parent step.
                field.querySelectorAll('input, textarea, select').forEach((el) => {
                    if (!matches) {
                        el.dataset.apWasRequired = el.required ? '1' : '';
                        el.required = false;
                    } else if (el.dataset.apWasRequired === '1') {
                        el.required = true;
                    }
                });
            });
            // Switch the iOS Telegram preview to the matching variant.
            previewViews.forEach((view) => {
                view.hidden = view.dataset.previewView !== placement;
            });
            if (previewStage) previewStage.dataset.previewPlacement = placement;
            // Update the step-3 title/subtitle.
            const copy = placementCopy[placement] || placementCopy.channel_posts;
            if (targetStepTitle) targetStepTitle.textContent = copy.title;
            if (targetStepSubtitle) targetStepSubtitle.textContent = copy.subtitle;
            if (searchInputLabel) searchInputLabel.textContent = copy.searchLabel;
            // Re-run step-validity check.
            updateNextButtonState();
        };

        const selectedPlacementInput = wizard.querySelector('[data-placement-option]:checked');
        if (selectedPlacementInput) applyPlacement(selectedPlacementInput.value);
        placementInputs.forEach((input) => {
            input.addEventListener('change', () => applyPlacement(input.value));
        });

        // ── "Disable next/submit until step is valid" logic ────────────────
        // The wizard's Next and final Submit buttons stay disabled (and dim)
        // until every required field on the CURRENT step passes :invalid.
        // The native browser constraint-check is reused — no custom
        // validation rules required.
        const isStepValid = (pane) => {
            if (!pane) return true;
            // Check visible+required fields. Hidden/disabled fields are
            // excluded automatically by the browser's :invalid selector only
            // for the field itself — but `:invalid` on a hidden input still
            // fires if the field has a value that violates its constraint.
            // So we manually skip hidden inputs.
            const fields = pane.querySelectorAll('input, textarea, select');
            for (const field of fields) {
                if (field.disabled) continue;
                // Skip fields inside a [hidden] ancestor (e.g. placement-
                // specific fields that don't apply to the current placement).
                if (field.closest('[hidden]')) continue;
                if (!field.required && field.type !== 'radio' && field.type !== 'checkbox') {
                    // Still check pattern/maxLength violations even on
                    // optional fields.
                    if (!field.checkValidity()) return false;
                    continue;
                }
                if (field.type === 'radio') {
                    const group = pane.querySelector(`input[name="${field.name}"]:checked`);
                    if (!group && field.hasAttribute('required')) {
                        // Has this radio group any required marker?
                        const anyRequired = pane.querySelector(`input[name="${field.name}"][required]`);
                        if (anyRequired) return false;
                    }
                    continue;
                }
                if (field.type === 'checkbox' && field.required && !field.checked) {
                    return false;
                }
                if (!field.checkValidity()) return false;
            }
            return true;
        };

        const updateNextButtonState = () => {
            const pane = panes[current];
            const valid = isStepValid(pane);
            nextButtons.forEach((btn) => { btn.disabled = !valid; });
            submitButtons.forEach((btn) => { btn.disabled = !valid; });
        };

        // Recompute on every input change inside any pane.
        wizard.addEventListener('input', updateNextButtonState);
        wizard.addEventListener('change', updateNextButtonState);
        wizard.addEventListener('channel-search:change', updateNextButtonState);
        wizard.addEventListener('keyword-search:change', updateNextButtonState);

        const render = () => {
            panes.forEach((pane, index) => {
                pane.hidden = index !== current;
                pane.setAttribute('aria-hidden', index === current ? 'false' : 'true');
            });
            if (progress) progress.style.setProperty('--progress', `${((current + 1) / panes.length) * 100}%`);
            if (currentLabel) currentLabel.textContent = String(current + 1);
            prevButtons.forEach((button) => { button.disabled = current === 0; });
            nextButtons.forEach((button) => { button.hidden = current === panes.length - 1; });
            submitButtons.forEach((button) => { button.hidden = current !== panes.length - 1; });
            updateNextButtonState();
        };

        wizard.addEventListener('click', (event) => {
            const next = event.target.closest('[data-wizard-next]');
            const previous = event.target.closest('[data-wizard-prev]');
            if (!next && !previous) return;

            event.preventDefault();
            if (next) {
                if (next.disabled) return;
                const pane = panes[current];
                const invalid = pane?.querySelector('input:not(:disabled):not([hidden]) [required], input[required]:not(:disabled)');
                // The simple `:invalid` selector works only on visible fields
                // because hidden placement-specific fields have been set
                // required=false by applyPlacement().
                const firstInvalid = pane?.querySelector(':invalid');
                if (firstInvalid && !firstInvalid.closest('[hidden]') && !firstInvalid.disabled) {
                    firstInvalid.reportValidity();
                    firstInvalid.focus();
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

    // P0-26 — auto-scroll ticket threads to the latest message. Previously the
    // thread was capped at max-height:420px and the user had to manually
    // scroll to find the latest reply, which was especially painful on mobile.
    document.querySelectorAll('[data-ticket-thread]').forEach((thread) => {
        // Defer one microtask so any images/emoji fonts have settled.
        requestAnimationFrame(() => { thread.scrollTop = thread.scrollHeight; });
    });

    // P1-13 — scrollspy for the admin user-detail tabs (Overview / Identity /
    // Orders / …). Without this, "Overview" stays visually active even when
    // the user has scrolled to "Identity" or below.
    const tabLinks = Array.from(document.querySelectorAll('.tab-link'));
    if (tabLinks.length > 1) {
        const targets = tabLinks
            .map((link) => {
                const id = link.getAttribute('href')?.replace(/^#/, '');
                return id ? document.getElementById(id) : null;
            })
            .filter(Boolean);
        if (targets.length > 0) {
            const setActive = (id) => {
                tabLinks.forEach((link) => {
                    link.classList.toggle('is-active', link.getAttribute('href') === `#${id}`);
                });
            };
            const observer = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) setActive(entry.target.id);
                    });
                },
                { rootMargin: '-25% 0px -65% 0px' }
            );
            targets.forEach((target) => observer.observe(target));
        }
    }

    // P1-18 — replace inline onchange="this.form.submit()" with addEventListener
    // so CSP-strict deployments don't break the dashboard period dropdown.
    document.querySelectorAll('[data-auto-submit]').forEach((select) => {
        select.addEventListener('change', () => select.form?.submit());
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

    // ─── Luxury bottom-nav animations ─────────────────────────────────
    // A modern, animated pill indicator slides between tabs and the
    // active tab icon gets a gentle scale + color transition. We also
    // fire a haptic ping on touch (when Telegram's HapticFeedback is
    // available) for a premium feel.
    const bottomNav = document.querySelector('.mini-bottom-nav');
    if (bottomNav) {
        const items = Array.from(bottomNav.querySelectorAll('.mini-nav-item'));
        const indicator = bottomNav.querySelector('.mini-nav-indicator');

        const moveIndicator = (item) => {
            if (!indicator || !item) return;
            const navRect = bottomNav.getBoundingClientRect();
            const itemRect = item.getBoundingClientRect();
            const left = itemRect.left - navRect.left + itemRect.width / 2;
            indicator.style.transform = `translateX(${left}px) translateX(-50%)`;
            indicator.style.opacity = '1';
        };

        // Move indicator to the active tab on load + on resize.
        const activeItem = bottomNav.querySelector('.mini-nav-item.is-active') || items[0];
        requestAnimationFrame(() => moveIndicator(activeItem));
        window.addEventListener('resize', () => {
            const current = bottomNav.querySelector('.mini-nav-item.is-active') || items[0];
            moveIndicator(current);
        });

        // Subtle scale-on-press and haptic ping on tap.
        items.forEach((item) => {
            item.addEventListener('pointerdown', () => {
                item.classList.add('is-pressed');
                try {
                    window.Telegram?.WebApp?.HapticFeedback?.impactOccurred('light');
                } catch (_) { /* ignore */ }
            });
            ['pointerup', 'pointerleave', 'pointercancel'].forEach((ev) => {
                item.addEventListener(ev, () => item.classList.remove('is-pressed'));
            });
        });
    }

    // ─── Channel/Bot search by ID or username ──────────────────────────
    // User enters a Telegram chat identifier (e.g. @channel, t.me/link,
    // or numeric -100... chat_id), each Enter or comma sends a lookup
    // to the backend, the resolved channel preview (avatar + title +
    // @username) is appended to a chip list, and an ✕ button lets them
    // remove it. Hidden inputs for `target_channel_ids[]` are created
    // automatically.
    document.querySelectorAll('[data-channel-search]').forEach((picker) => {
        const input = picker.querySelector('[data-channel-search-input]');
        const results = picker.querySelector('[data-channel-search-results]');
        const emptyHint = picker.querySelector('[data-channel-search-empty]');
        const endpoint = picker.dataset.channelSearch;
        const hiddenContainer = picker.querySelector('[data-channel-search-hidden]');
        if (!input || !results || !endpoint) return;

        const selected = new Map(); // username -> {id, title, avatar, username}

        const renderChips = () => {
            results.innerHTML = '';
            if (selected.size === 0) {
                if (emptyHint) emptyHint.hidden = false;
                return;
            }
            if (emptyHint) emptyHint.hidden = true;

            for (const [username, info] of selected) {
                const chip = document.createElement('div');
                chip.className = 'channel-chip';
                chip.dataset.channelChip = username;
                const avatar = document.createElement('span');
                avatar.className = 'channel-chip-avatar';
                if (info.avatar) {
                    const img = document.createElement('img');
                    img.src = info.avatar;
                    img.alt = '';
                    img.loading = 'lazy';
                    avatar.appendChild(img);
                } else {
                    avatar.textContent = (info.title || username).trim().charAt(0).toUpperCase();
                }
                const copy = document.createElement('span');
                copy.className = 'channel-chip-copy';
                const strong = document.createElement('strong');
                strong.textContent = info.title || username;
                const small = document.createElement('small');
                small.className = 'ltr';
                small.textContent = `@${info.username || username}` + (info.id ? ` · ${info.id}` : '');
                copy.appendChild(strong);
                copy.appendChild(small);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'channel-chip-remove';
                remove.setAttribute('aria-label', 'Remove');
                remove.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
                remove.addEventListener('click', () => {
                    selected.delete(username);
                    renderChips();
                    syncHidden();
                });

                chip.appendChild(avatar);
                chip.appendChild(copy);
                chip.appendChild(remove);
                results.appendChild(chip);
            }
        };

        const syncHidden = () => {
            if (!hiddenContainer) return;
            hiddenContainer.innerHTML = '';
            selected.forEach((info, username) => {
                const field = document.createElement('input');
                field.type = 'hidden';
                field.name = 'target_channel_ids[]';
                field.value = info.id || username;
                hiddenContainer.appendChild(field);
            });
            // Notify any listeners (e.g. submit-button state).
            picker.dispatchEvent(new CustomEvent('channel-search:change', {
                detail: { count: selected.size },
                bubbles: true,
            }));
        };

        let controller = null;
        const lookup = async (rawQuery) => {
            const q = (rawQuery || '').trim();
            if (!q) return false;
            // Skip duplicates already in the list.
            const normalized = q.replace(/^@/, '').replace(/^https?:\/\/t\.me\//i, '').replace(/\/.*$/, '').toLowerCase();
            if (selected.has(normalized)) return false;

            if (controller) controller.abort();
            controller = new AbortController();
            input.disabled = true;
            try {
                const params = new URLSearchParams({ q });
                const res = await fetch(`${endpoint}?${params}`, {
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    signal: controller.signal,
                    credentials: 'same-origin',
                });
                if (!res.ok) {
                    input.value = '';
                    input.disabled = false;
                    input.focus();
                    return false;
                }
                const data = await res.json();
                if (!data || !data.username) {
                    input.value = '';
                    input.disabled = false;
                    input.focus();
                    return false;
                }
                const key = String(data.username).toLowerCase();
                selected.set(key, {
                    id: data.id ?? null,
                    username: data.username,
                    title: data.title ?? data.username,
                    avatar: data.avatar ?? null,
                });
                renderChips();
                syncHidden();
                input.value = '';
                input.disabled = false;
                input.focus();
                return true;
            } catch (err) {
                if (err && err.name === 'AbortError') return false;
                input.disabled = false;
                return false;
            }
        };

        // Enter or comma submits; paste of newline-separated list submits each.
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ',' || event.key === '\n') {
                event.preventDefault();
                lookup(input.value);
                return;
            }
            if (event.key === 'Backspace' && input.value === '' && selected.size > 0) {
                // Backspace on empty removes the last chip.
                const lastKey = Array.from(selected.keys()).pop();
                if (lastKey) {
                    selected.delete(lastKey);
                    renderChips();
                    syncHidden();
                }
            }
        });
        input.addEventListener('paste', (event) => {
            event.preventDefault();
            const text = (event.clipboardData || window.clipboardData).getData('text') || '';
            const lines = text.split(/[\n,]+/).map((s) => s.trim()).filter(Boolean);
            (async () => {
                for (const line of lines) {
                    await lookup(line);
                }
            })();
        });

        // Seed from any pre-selected hidden inputs (e.g. on edit page).
        if (hiddenContainer) {
            hiddenContainer.querySelectorAll('input[name="target_channel_ids[]"]').forEach((field) => {
                const v = field.value || '';
                if (!v) return;
                const username = v.replace(/^@/, '').toLowerCase();
                selected.set(username, {
                    id: null,
                    username,
                    title: username,
                    avatar: null,
                });
            });
            renderChips();
            // Drop the seed inputs — they'll be regenerated by syncHidden on next change.
            hiddenContainer.innerHTML = '';
            // Re-sync once to rewrite the same set (so the seed is preserved on save).
            syncHidden();
        } else {
            renderChips();
        }
    });

    // ─── Search-keyword chip input (placement = search_results) ─────────
    // Mirrors the channel-search UX but for free-text keywords. Each chip
    // is a hidden input named `search_keywords[]`. Enforced constraints:
    //   - min length 4 (configurable via data-min-length on the picker)
    //   - max chips 30 (configurable via data-max)
    //   - duplicates rejected
    //   - backspace on empty removes the last chip (same UX as channels)
    document.querySelectorAll('[data-keyword-search]').forEach((picker) => {
        const input = picker.querySelector('[data-keyword-search-input]');
        const results = picker.querySelector('[data-keyword-search-results]');
        const emptyHint = picker.querySelector('[data-keyword-search-empty]');
        const hiddenContainer = picker.querySelector('[data-keyword-search-hidden]');
        const minLength = Math.max(1, Number(picker.dataset.minLength || 4));
        const maxKeywords = Math.max(1, Number(picker.dataset.max || 30));
        if (!input || !results) return;

        const keywords = new Map(); // lower-cased keyword → original text

        const renderChips = () => {
            results.innerHTML = '';
            if (keywords.size === 0) {
                if (emptyHint) emptyHint.hidden = false;
                return;
            }
            if (emptyHint) emptyHint.hidden = true;

            for (const [key, original] of keywords) {
                const chip = document.createElement('div');
                chip.className = 'keyword-chip';
                chip.dataset.keywordChip = key;
                const copy = document.createElement('span');
                copy.className = 'keyword-chip-copy';
                copy.textContent = original;
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'keyword-chip-remove';
                remove.setAttribute('aria-label', 'Remove');
                remove.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
                remove.addEventListener('click', () => {
                    keywords.delete(key);
                    renderChips();
                    syncHidden();
                });
                chip.appendChild(copy);
                chip.appendChild(remove);
                results.appendChild(chip);
            }
        };

        const syncHidden = () => {
            if (!hiddenContainer) return;
            hiddenContainer.innerHTML = '';
            keywords.forEach((original) => {
                const field = document.createElement('input');
                field.type = 'hidden';
                field.name = 'search_keywords[]';
                field.value = original;
                hiddenContainer.appendChild(field);
            });
            picker.dispatchEvent(new CustomEvent('keyword-search:change', {
                detail: { count: keywords.size },
                bubbles: true,
            }));
        };

        const addKeyword = (raw) => {
            const trimmed = (raw || '').trim();
            if (!trimmed) return false;
            if (trimmed.length < minLength) {
                input.classList.add('is-invalid');
                // Auto-clear the error class after a brief shake.
                setTimeout(() => input.classList.remove('is-invalid'), 600);
                return false;
            }
            if (keywords.size >= maxKeywords) return false;
            const key = trimmed.toLowerCase();
            if (keywords.has(key)) {
                input.value = '';
                return false;
            }
            keywords.set(key, trimmed);
            renderChips();
            syncHidden();
            input.value = '';
            return true;
        };

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ',' || event.key === '\n') {
                event.preventDefault();
                addKeyword(input.value);
                return;
            }
            if (event.key === 'Backspace' && input.value === '' && keywords.size > 0) {
                const lastKey = Array.from(keywords.keys()).pop();
                if (lastKey) {
                    keywords.delete(lastKey);
                    renderChips();
                    syncHidden();
                }
            }
        });
        input.addEventListener('blur', () => {
            if (input.value.trim()) addKeyword(input.value);
        });
        input.addEventListener('paste', (event) => {
            event.preventDefault();
            const text = (event.clipboardData || window.clipboardData).getData('text') || '';
            const lines = text.split(/[\n,]+/).map((s) => s.trim()).filter(Boolean);
            for (const line of lines) addKeyword(line);
        });

        // Seed from pre-existing hidden inputs (edit page).
        if (hiddenContainer) {
            hiddenContainer.querySelectorAll('input[name="search_keywords[]"]').forEach((field) => {
                const v = (field.value || '').trim();
                if (!v) return;
                keywords.set(v.toLowerCase(), v);
            });
            renderChips();
            hiddenContainer.innerHTML = '';
            syncHidden();
        } else {
            renderChips();
        }
    });

    // ─── iOS-style preview: live-bind ad_text + media + search query ───
    // The preview text mirrors what the user types in #ad-text in real time.
    // For channel_posts, an attached image/video is also shown in the preview.
    // For search_results, the first keyword becomes the search bar text.
    document.querySelectorAll('[data-preview-stage]').forEach((stage) => {
        const adTextInput = document.querySelector('#ad-text');
        const previewTextTargets = stage.querySelectorAll('[data-placeholder], .ios-tg-msg-text');
        const mediaInput = document.querySelector('[data-media-preview-target]');
        const mediaSlot = stage.querySelector('[data-preview-media-slot]');
        const mediaImg = stage.querySelector('[data-preview-media]');
        const searchQueryEl = stage.querySelector('[data-preview-search-query]');

        if (adTextInput) {
            const sync = () => {
                const value = adTextInput.value || adTextInput.getAttribute('placeholder') || '';
                previewTextTargets.forEach((el) => {
                    if (el.tagName === 'P' || el.tagName === 'SPAN') {
                        el.textContent = value;
                    }
                });
            };
            adTextInput.addEventListener('input', sync);
            sync();
        }

        if (mediaInput && mediaSlot && mediaImg) {
            mediaInput.addEventListener('change', () => {
                const [file] = mediaInput.files || [];
                if (!file) {
                    mediaSlot.hidden = true;
                    mediaImg.src = '';
                    return;
                }
                const url = URL.createObjectURL(file);
                mediaImg.onload = () => URL.revokeObjectURL(url);
                mediaImg.src = url;
                mediaSlot.hidden = false;
            });
        }

        // Search keywords → preview search bar shows the first keyword.
        const keywordPicker = document.querySelector('[data-keyword-search]');
        if (keywordPicker && searchQueryEl) {
            keywordPicker.addEventListener('keyword-search:change', () => {
                const first = keywordPicker.querySelector('[data-keyword-search-hidden] input');
                if (first && first.value) {
                    searchQueryEl.textContent = first.value;
                } else {
                    const isFa = document.documentElement.lang === 'fa';
                    searchQueryEl.textContent = isFa ? 'جستجو در تلگرام' : 'Search Telegram';
                }
            });
        }
    });

    // ─── App splash loader ─────────────────────────────────────────────
    // The splash is rendered in HTML for instant paint. The CSS animation
    // fades it out after 1.6s. Here we additionally dismiss it early as
    // soon as the page has finished loading (DOMContentLoaded + a minimum
    // visible window so the splash doesn't feel like a flicker). We also
    // tag sessionStorage so a back/forward navigation within the same
    // browsing session doesn't re-trigger the splash.
    const splash = document.querySelector('[data-app-splash]');
    if (splash) {
        const minMs = Number(document.body.dataset.splashMinMs || 600);
        const shownAt = Number(sessionStorage.getItem('ap-splash-shown-at') || 0);
        const now = Date.now();
        if (shownAt && now - shownAt < 60_000) {
            // Splash was shown less than 60s ago — don't show again, just
            // hide immediately.
            splash.hidden = true;
        } else {
            sessionStorage.setItem('ap-splash-shown-at', String(now));
            const dismiss = () => {
                const elapsed = Date.now() - now;
                const wait = Math.max(0, minMs - elapsed);
                setTimeout(() => {
                    splash.style.opacity = '0';
                    setTimeout(() => { splash.hidden = true; }, 480);
                }, wait);
            };
            if (document.readyState === 'complete') {
                dismiss();
            } else {
                window.addEventListener('load', () => requestAnimationFrame(dismiss), { once: true });
            }
        }
    }

    // ─── Locale toggle micro-interaction ────────────────────────────────
    // Even though the toggle works as an <a href> (no-JS fallback), we
    // intercept the click to do a smooth fade-out before navigating,
    // giving the user a sense of "the page is switching language".
    document.querySelectorAll('[data-locale-toggle]').forEach((toggle) => {
        toggle.addEventListener('click', (event) => {
            // Only intercept same-origin navigations.
            const url = new URL(toggle.href, window.location.href);
            if (url.origin !== window.location.origin) return;
            event.preventDefault();
            try {
                window.Telegram?.WebApp?.HapticFeedback?.selectionChanged();
            } catch (_) { /* ignore */ }
            document.body.style.transition = 'opacity 200ms ease';
            document.body.style.opacity = '0.35';
            setTimeout(() => { window.location.href = toggle.href; }, 180);
        });
    });

    // ─── Auto-convert Persian/Arabic digits to English on numeric fields ──
    // When a user types Persian digits (۰۱۲۳۴۵۶۷۸۹ or ٠١٢٣٤٥٦٧٨٩) inside a
    // numeric input, the value is not accepted by server-side validation
    // (which uses regex [0-9]). We convert in real-time on every input
    // event AND on paste, so the user sees their digits change to English
    // instantly — no surprises at submit time.
    //
    // We target any input with:
    //   - type="number"
    //   - inputmode="numeric"
    //   - inputmode="decimal"
    //   - pattern starting with [0-9۰-۹]
    //   - class "number" or "ltr" + name like national_id / card_number /
    //     amount_*, cpm_gram, members_count, etc.
    const PERSIAN_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
    const ARABIC_DIGITS = '٠١٢٣٤٥٦٧٨٩';
    const convertDigits = (str) => {
        if (! str) return str;
        let out = '';
        for (const ch of str) {
            const pIdx = PERSIAN_DIGITS.indexOf(ch);
            if (pIdx >= 0) { out += String(pIdx); continue; }
            const aIdx = ARABIC_DIGITS.indexOf(ch);
            if (aIdx >= 0) { out += String(aIdx); continue; }
            out += ch;
        }
        return out;
    };

    const isNumericField = (input) => {
        if (input.tagName !== 'INPUT' && input.tagName !== 'TEXTAREA') return false;
        if (input.tagName === 'INPUT') {
            if (input.type === 'number') return true;
            const im = input.getAttribute('inputmode');
            if (im === 'numeric' || im === 'decimal' || im === 'tel') return true;
            const pattern = input.getAttribute('pattern') || '';
            if (pattern.includes('[0-9') || pattern.includes('[۰-۹')) return true;
            if (input.classList.contains('number')) return true;
        }
        // Identify known-numeric name attributes that don't carry type=number.
        const numericNames = [
            'national_id', 'card_number', 'card_holder_name',
            'amount_toman', 'amount_usd', 'media_budget_toman',
            'impression_goal', 'frequency_cap', 'members_count',
            'cpm_gram', 'minimum_channel_members', 'quote_ttl_minutes',
            'service_markup_percent', 'gateway_fee_percent',
            'usd_to_toman_rate', 'gram_to_usd', 'conversion_margin_percent',
            'sort_order', 'minimum_target_members',
        ];
        return numericNames.includes(input.name || '');
    };

    const applyDigitConversion = (input) => {
        if (! isNumericField(input)) return;
        input.dataset.apDigitConvert = '1';
        input.addEventListener('input', () => {
            const start = input.selectionStart;
            const end = input.selectionEnd;
            const before = input.value;
            const after = convertDigits(before);
            if (before === after) return;
            input.value = after;
            // Restore caret position best-effort (no shift on Persian→English
            // since each char is exactly 1 unit wide either way).
            try { input.setSelectionRange(start, end); } catch (_) { /* ignore */ }
        });
        input.addEventListener('paste', (event) => {
            // Let the browser do the paste first, then run our converter
            // synchronously after.
            setTimeout(() => {
                const before = input.value;
                const after = convertDigits(before);
                if (before === after) return;
                input.value = after;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }, 0);
        });
    };

    document.querySelectorAll('input, textarea').forEach(applyDigitConversion);

    // Also handle future-inserted inputs (e.g. via AJAX). The MutationObserver
    // is cheap because we only scan for INPUT/TEXTAREA on added nodes.
    if ('MutationObserver' in window) {
        new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                for (const node of mutation.addedNodes) {
                    if (node.nodeType !== 1) continue;
                    if (node.tagName === 'INPUT' || node.tagName === 'TEXTAREA') {
                        applyDigitConversion(node);
                    } else {
                        node.querySelectorAll?.('input, textarea').forEach(applyDigitConversion);
                    }
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    if ('serviceWorker' in navigator && (window.isSecureContext || location.hostname === 'localhost')) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
                // PWA support is progressive; the web app remains fully usable without it.
            });
        }, { once: true });
    }
});
