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

    // ── Measure the actual rendered topbar height and store it as a
    //    CSS custom property on :root. The wizard progress bar uses
    //    --ap-topbar-h as its sticky inset-block-start, so it must
    //    match the actual topbar height (which varies with content,
    //    font loading, and locale). Re-measure on resize/orientation
    //    change so the sticky progress bar always sticks flush under
    //    the topbar instead of overlapping it or leaving a gap. The
    //    default in app.css (76px) is a safe fallback if the topbar
    //    element is not present on a given page.
    const measureTopbar = () => {
        const topbar = document.querySelector('.mini-topbar');
        if (!topbar) return;
        const h = Math.round(topbar.getBoundingClientRect().height);
        if (h > 0 && h !== Number.parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--ap-topbar-h') || '0')) {
            document.documentElement.style.setProperty('--ap-topbar-h', h + 'px');
        }
    };
    measureTopbar();
    // Re-measure after fonts load (brand text changes height once Vazirmatn/Manrope is ready).
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(measureTopbar).catch(() => {});
    }
    window.addEventListener('resize', measureTopbar, { passive: true });
    window.addEventListener('orientationchange', measureTopbar, { passive: true });

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

        // ── "Disable next/submit until step is valid" logic ────────────────
        // IMPORTANT: isStepValid + updateNextButtonState MUST be declared
        // before applyPlacement, because applyPlacement calls
        // updateNextButtonState() on init. `const` declarations are subject
        // to the Temporal Dead Zone in JavaScript — referencing them
        // before their declaration line throws ReferenceError, which was
        // silently breaking the entire wizard (buttons stayed disabled
        // forever because the init call crashed).
        const isStepValid = (pane) => {
            if (!pane) return true;
            const fields = pane.querySelectorAll('input, textarea, select');
            for (const field of fields) {
                if (field.disabled) continue;
                // Skip fields inside a [hidden] ancestor (e.g. placement-
                // specific fields that don't apply to the current placement).
                if (field.closest('[hidden]')) continue;
                // Optional non-radio/checkbox fields: still check
                // pattern / maxLength violations.
                if (!field.required && field.type !== 'radio' && field.type !== 'checkbox') {
                    if (!field.checkValidity()) return false;
                    continue;
                }
                // Radio group: valid if ANY member is checked.
                if (field.type === 'radio') {
                    const checked = pane.querySelector(`input[name="${field.name}"]:checked`);
                    if (!checked && field.hasAttribute('required')) {
                        return false;
                    }
                    continue;
                }
                // Checkbox: must be ticked when required.
                if (field.type === 'checkbox' && field.required && !field.checked) {
                    return false;
                }
                // Any other required field: must pass native validity.
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
            // Toggle the matching per-placement header (channel / bot / search
            // have visually different headers, each rendered as a separate
            // <header data-tg-header="..."> element).
            wizard.querySelectorAll('[data-tg-header]').forEach((header) => {
                header.hidden = header.dataset.tgHeader !== placement;
            });
            // Update the small context pill above the preview ("Channel" / "Bot" / "Search")
            // so the user always knows which placement they're previewing.
            const contextLabel = wizard.querySelector('[data-preview-context-label]');
            if (contextLabel) {
                const contextCopy = {
                    channel_posts: isFa ? 'کانال' : 'Channel',
                    bot_messages: isFa ? 'ربات' : 'Bot',
                    search_results: isFa ? 'جستجو' : 'Search',
                };
                contextLabel.textContent = contextCopy[placement] || contextCopy.channel_posts;
            }
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
    //
    // Aug 2026 — also binds #internal-title → preview title and
    // #destination-url → preview subtitle (parses @username from the URL).
    // Falls back gracefully to defaults when those fields are empty so the
    // preview never shows broken/empty strings.
    document.querySelectorAll('[data-preview-stage]').forEach((stage) => {
        const adTextInput = document.querySelector('#ad-text');
        const previewTextTargets = stage.querySelectorAll('[data-placeholder], .ios-tg-msg-text');
        const mediaInput = document.querySelector('[data-media-preview-target]');
        const mediaSlot = stage.querySelector('[data-preview-media-slot]');
        const mediaImg = stage.querySelector('[data-preview-media]');
        const searchQueryEl = stage.querySelector('[data-preview-search-query]');
        const titleInput = document.querySelector('#internal-title');
        const urlInput = document.querySelector('#destination-url');
        const titleTargets = stage.querySelectorAll('[data-preview-title]');
        const usernameTargets = stage.querySelectorAll('[data-preview-username]');
        const isFa = document.documentElement.lang === 'fa';

        // Parse a Telegram URL into a displayable @username.
        // Handles:
        //   https://t.me/your_channel
        //   https://t.me/your_channel/123
        //   https://t.me/+abcDEF123    → "Private channel"
        //   https://t.me/s/your_channel
        //   https://t.me/your_bot?start=ref123
        //   Anything else             → first path segment or the hostname
        const parseTelegramUsername = (rawUrl) => {
            if (!rawUrl) return '';
            const url = String(rawUrl).trim();
            // Try the t.me short form first
            const tgMatch = url.match(/(?:https?:\/\/)?(?:www\.)?t\.me\/(?:s\/)?([^/?#]+)/i);
            if (tgMatch) {
                const seg = tgMatch[1];
                if (seg.startsWith('+')) {
                    return isFa ? 'کانال خصوصی' : 'Private channel';
                }
                if (/^[A-Za-z0-9_]{3,}$/.test(seg)) {
                    return '@' + seg;
                }
                return seg;
            }
            // Try a generic URL → take hostname without www
            try {
                const u = new URL(url);
                return u.hostname.replace(/^www\./, '');
            } catch {
                // Not a URL — if it already starts with @, keep as-is
                return url.startsWith('@') ? url : '';
            }
        };

        // Sync the preview title from #internal-title to all [data-preview-title].
        // Falls back to localized "Your channel / Your bot" when the field is empty.
        const syncTitle = () => {
            const value = (titleInput && titleInput.value.trim())
                || (isFa ? 'کانال شما' : 'Your channel');
            titleTargets.forEach((el) => {
                if (el.tagName === 'STRONG' || el.tagName === 'SPAN') {
                    el.textContent = value;
                }
            });
        };

        // Sync the preview @username from #destination-url to all [data-preview-username].
        // Falls back to localized placeholders so the subtitle never looks broken.
        const syncUsername = () => {
            const raw = urlInput ? urlInput.value.trim() : '';
            const parsed = parseTelegramUsername(raw);
            // The current placement decides which fallback string to show when
            // the URL is empty or unparseable.
            const currentPlacement = stage.dataset.previewPlacement || 'channel_posts';
            const fallbacks = {
                channel_posts: isFa ? '۱۲۳٫۴K مشترک' : '123.4K subscribers',
                bot_messages: isFa ? 'ربات' : 'bot',
                search_results: '@your_channel',
            };
            const value = parsed || fallbacks[currentPlacement] || fallbacks.channel_posts;
            // For bot placement, prefix with "bot · " if the value is a @username.
            const display = (currentPlacement === 'bot_messages' && parsed.startsWith('@'))
                ? (isFa ? 'ربات · ' : 'bot · ') + parsed
                : value;
            usernameTargets.forEach((el) => {
                el.textContent = display;
            });
        };

        if (titleInput) {
            titleInput.addEventListener('input', syncTitle);
            syncTitle();
        }
        if (urlInput) {
            urlInput.addEventListener('input', () => {
                syncUsername();
                // A URL change usually means the channel identity changed too,
                // so also re-sync the title in case the user wants the title to
                // mirror the username (best-effort — titleInput.value still wins).
            });
            syncUsername();
        }
        // Re-sync the username whenever the placement type changes, because the
        // fallback text differs per placement ("123.4K subscribers" vs "bot").
        const placementObserver = new MutationObserver(() => {
            syncUsername();
        });
        placementObserver.observe(stage, {
            attributes: true,
            attributeFilter: ['data-preview-placement'],
        });

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

    // ─── Step 4 budget & bid auto-calculation ──────────────────────────
    // On step 4 we now ask the user to enter CPM and budget in GRAM (not Toman).
    // The form still submits `media_budget_toman` (the backend's canonical unit),
    // so we compute it from gram × gram_to_usd × usd_to_irr / 10 on every input
    // change. The `impression_goal` field is read-only and auto-derived as:
    //
    //   impressions = budget_gram / effective_cpm × 1000
    //
    // where effective_cpm = max(cpm, 1) when competitive AND cpm<1,
    //                         = cpm × 1.5 when competitive AND cpm>1,
    //                         = cpm otherwise (standard plan).
    //
    // We also show:
    //   • Rial equivalent of the gram amount (live, below the input)
    //   • Effective CPM note (only when competitive is selected and cpm≠1)
    //
    // Rates come from data attributes on the [data-budget-pane] section
    // that the controller populates from PricingService::quote().
    document.querySelectorAll('[data-budget-pane]').forEach((pane) => {
        const usdToIrr = Number(pane.dataset.usdToIrr || 0);
        const gramToUsd = Number(pane.dataset.gramToUsd || 0);
        const cpmInput = pane.querySelector('[data-cpm-input]');
        const budgetGramInput = pane.querySelector('[data-budget-gram-input]');
        const budgetTomanHidden = pane.querySelector('[data-budget-toman-hidden]');
        const rialLine = pane.querySelector('[data-budget-rial-line]');
        const impressionDisplay = pane.querySelector('[data-impression-display]');
        const planInputs = pane.querySelectorAll('[data-plan-option]');
        const effectiveNote = pane.querySelector('[data-effective-cpm-note]');
        const editing = budgetGramInput && budgetGramInput.hasAttribute('readonly');
        const isFa = document.documentElement.lang === 'fa';

        // Format a number with thousands separators + 0-2 decimal places.
        const fmt = (n) => {
            if (!isFinite(n) || isNaN(n)) return '—';
            const rounded = Math.round(n * 100) / 100;
            return rounded.toLocaleString(isFa ? 'fa-IR' : 'en-US');
        };
        // Format a rial/toman amount with currency suffix.
        const fmtToman = (n) => {
            if (!isFinite(n) || isNaN(n)) return '—';
            const whole = Math.round(n);
            return whole.toLocaleString(isFa ? 'fa-IR' : 'en-US')
                + (isFa ? ' تومان' : ' Toman');
        };

        // Compute the effective CPM based on the selected plan.
        const effectiveCpm = (rawCpm, isCompetitive) => {
            const cpm = Math.max(0, Number(rawCpm) || 0);
            if (!isCompetitive) return cpm;
            if (cpm < 1) return 1;
            return cpm * 1.5;
        };

        const recompute = () => {
            const rawCpm = cpmInput ? Number(cpmInput.value || 0) : 0;
            const gram = budgetGramInput ? Number(budgetGramInput.value || 0) : 0;
            const isCompetitive = !!(pane.querySelector('[data-plan-competitive]:checked'));
            const effCpm = effectiveCpm(rawCpm, isCompetitive);

            // 1) Toman equivalent from gram (10 IRR = 1 Toman)
            // gram × gram_to_usd × usd_to_irr / 10 = toman
            const toman = (usdToIrr > 0 && gramToUsd > 0)
                ? gram * gramToUsd * usdToIrr / 10
                : 0;
            if (budgetTomanHidden) {
                budgetTomanHidden.value = Math.max(0, Math.round(toman));
                // Backend rule: media_budget_toman must be >= 10000.
                // If the computed toman is below the minimum (very low gram
                // input), set a custom validity on the impression_goal
                // field too, so the wizard's Continue button stays
                // disabled until the user raises the budget. We piggyback
                // on impression_goal's custom validity because the toman
                // field is hidden and we don't want to expose it to the
                // user — they should think in terms of impressions, not
                // toman.
                if (toman > 0 && toman < 10000) {
                    const msg = isFa
                        ? 'بودجه واردشده کم است؛ مبلغ گرام را بیشتر کنید.'
                        : 'Budget too low; increase the GRAM amount.';
                    budgetTomanHidden.setCustomValidity(msg);
                } else {
                    budgetTomanHidden.setCustomValidity('');
                }
            }

            // 2) Rial equivalent line (we show the Toman amount — matches
            // what the user pays — plus the GRAM amount for context).
            if (rialLine) {
                if (toman > 0) {
                    rialLine.textContent = isFa
                        ? 'معادل ریالی: ' + fmtToman(toman)
                        : 'Rial equivalent: ' + fmtToman(toman);
                    rialLine.hidden = false;
                } else {
                    rialLine.textContent = isFa
                        ? 'معادل ریالی: در حال محاسبه…'
                        : 'Rial equivalent: calculating…';
                    rialLine.hidden = false;
                }
            }

            // 3) Auto-calculated impressions = gram / effective_cpm × 1000
            //    The impression_goal input is `readonly` (auto-computed
            //    from budget/CPM), so its `willValidate` is false per the
            //    HTML spec — read-only fields don't participate in native
            //    constraint validation. The wizard's isStepValid() can
            //    therefore NOT detect when impression_goal < 1000 (the
            //    backend's min:1000 rule). We work around this by:
            //      1. Setting a custom validity message on the
            //         impression_goal field (helps any caller that
            //         explicitly checks it).
            //      2. Showing an inline warning with a clear hint about
            //         HOW to fix it (raise budget or lower CPM) since
            //         the user can't edit the read-only field directly.
            //      3. Dispatching an 'input' event from the budget_gram
            //         input (which IS editable + validated) so the
            //         wizard's updateNextButtonState() re-runs and can
            //         re-check our custom validity.
            //         — Actually we can't dispatch from budget_gram_input
            //         because that would cause infinite recursion (its
            //         'input' handler calls recompute again). So we just
            //         dispatch 'input' on impression_display — the wizard
            //         listens to 'input' on itself with bubbling, which
            //         will trigger updateNextButtonState().
            //      4. Patching the wizard's isStepValid to also check
            //         this field's rangeUnderflow. This is done by
            //         storing the validity state on the field's dataset
            //         and reading it from isStepValid. We can't modify
            //         isStepValid directly from here (it's a closure), so
            //         we use a more robust approach: we set a custom
            //         validity on the budget_gram input too when
            //         impression_goal is below 1000, since the budget_gram
            //         input is editable and IS validated.
            if (impressionDisplay) {
                const imp = (effCpm > 0 && gram > 0)
                    ? Math.round((gram / effCpm) * 1000)
                    : 0;
                impressionDisplay.value = imp;
                // Show inline warning when below backend minimum (1000).
                // The warning explains HOW to fix it (raise budget or
                // lower CPM) since the field itself is read-only.
                const warning = pane.querySelector('[data-impression-warning]');
                const help = pane.querySelector('[data-impression-help]');
                const belowMin = imp > 0 && imp < 1000;
                if (warning) warning.hidden = !belowMin;
                if (help) help.hidden = belowMin;
                // Set a custom validity message so any caller that
                // explicitly checks impression_goal will see a helpful
                // error tooltip.
                if (belowMin) {
                    const msg = isFa
                        ? 'تعداد نمایش باید حداقل ۱٬۰۰۰ باشد. بودجه را بیشتر یا CPM را کم کنید.'
                        : 'Estimated impressions must be at least 1,000. Increase your budget or lower CPM.';
                    impressionDisplay.setCustomValidity(msg);
                } else {
                    impressionDisplay.setCustomValidity('');
                }
                // Bridge the validity to the budget_gram input (which IS
                // editable and participates in native validation). When
                // impression_goal < 1000, set a custom error on
                // budget_gram so isStepValid sees the step as invalid and
                // disables the Continue button. When impression_goal is
                // fine (>= 1000), clear the bridged error.
                if (budgetGramInput && !editing) {
                    if (belowMin) {
                        const msg = isFa
                            ? 'بودجه برای رسیدن به حداقل ۱٬۰۰۰ نمایش کافی نیست؛ مبلغ گرام را بیشتر یا CPM را کم کنید.'
                            : 'Budget too low for at least 1,000 impressions; raise the GRAM amount or lower CPM.';
                        budgetGramInput.setCustomValidity(msg);
                    } else {
                        // Only clear if WE set it; don't trample on
                        // native rangeUnderflow errors (e.g., min=0.001).
                        // setCustomValidity('') clears our message but
                        // native constraints (min, max, required) still
                        // apply via checkValidity automatically.
                        budgetGramInput.setCustomValidity('');
                    }
                }
                // Trigger the wizard's per-step validity recheck so the
                // Continue button's disabled state updates immediately.
                impressionDisplay.dispatchEvent(new Event('input', { bubbles: true }));
            }

            // 4) Effective CPM note (only when competitive actually changed it)
            if (effectiveNote) {
                if (isCompetitive && rawCpm > 0 && effCpm !== rawCpm) {
                    const label = isFa
                        ? 'CPM مؤثر پس از اعمال پلن رقابتی: ' + fmt(effCpm) + ' GRAM/1K'
                        : 'Effective CPM after competitive plan: ' + fmt(effCpm) + ' GRAM/1K';
                    effectiveNote.textContent = label;
                    effectiveNote.hidden = false;
                } else {
                    effectiveNote.hidden = true;
                    effectiveNote.textContent = '';
                }
            }
        };

        // Wire up listeners.
        if (cpmInput) cpmInput.addEventListener('input', recompute);
        if (budgetGramInput) budgetGramInput.addEventListener('input', recompute);
        planInputs.forEach((input) => input.addEventListener('change', recompute));

        // If we're in edit mode, the gram input is readonly — sync once.
        if (editing && budgetGramInput) {
            // Force a single recompute so the Rial line shows for the
            // pre-existing gram value loaded from the order.
            setTimeout(recompute, 0);
        }

        // Initial calculation.
        recompute();
    });

    // ─── 16:9 media upload validation ────────────────────────────────────
    // When the user picks a media file, we check the actual width/height
    // of the decoded image (or video metadata) and reject anything whose
    // aspect ratio isn't within ±2% of 16:9 (= 1.778). We surface the
    // error via the field-help text and clear the file input so the form
    // can't be submitted with bad media.
    document.querySelectorAll('input[type="file"][data-media-ratio="16:9"]').forEach((input) => {
        const field = input.closest('.field');
        const help = field ? field.querySelector('.field-help') : null;
        const originalHelp = help ? help.textContent : '';
        const isFa = document.documentElement.lang === 'fa';

        const setError = (msg) => {
            if (help) {
                help.textContent = msg;
                help.style.color = 'var(--ap-danger)';
                help.style.fontWeight = '600';
            }
            // Clear the file so the user can't submit it as-is.
            input.value = '';
            // Reset the preview box too.
            const box = input.closest('.upload-box');
            if (box) {
                box.classList.remove('has-preview');
                const img = box.querySelector('.upload-preview');
                const vid = box.querySelector('.upload-preview-video');
                if (img) img.src = '';
                if (vid) { vid.src = ''; vid.hidden = true; }
            }
            // Also clear the iOS preview's media slot.
            const previewTarget = input.getAttribute('data-media-preview-target');
            if (previewTarget) {
                const slot = document.querySelector(previewTarget)?.closest('[data-preview-media-slot]');
                if (slot) {
                    slot.hidden = true;
                    const img = slot.querySelector('[data-preview-media]');
                    if (img) img.src = '';
                }
            }
        };
        const clearError = () => {
            if (help) {
                help.textContent = originalHelp;
                help.style.color = '';
                help.style.fontWeight = '';
            }
        };

        input.addEventListener('change', () => {
            const [file] = input.files || [];
            if (!file) { clearError(); return; }
            clearError();

            // For images, decode dimensions via createImageBitmap.
            if (file.type.startsWith('image/')) {
                if (typeof createImageBitmap === 'function') {
                    createImageBitmap(file).then((bmp) => {
                        const ratio = bmp.width / Math.max(1, bmp.height);
                        // 16/9 = 1.7778, allow ±2% → 1.7423..1.8133
                        if (Math.abs(ratio - 16 / 9) > 16 / 9 * 0.02) {
                            setError(isFa
                                ? 'نسبت تصویر باید ۱۶:۹ باشد. تصویر انتخابی ' + ratio.toFixed(2) + ' است.'
                                : 'Image aspect ratio must be 16:9. Selected ratio is ' + ratio.toFixed(2) + '.');
                        }
                        bmp.close && bmp.close();
                    }).catch(() => {
                        // If we can't decode, let the server-side check reject.
                    });
                }
            } else if (file.type.startsWith('video/')) {
                // For videos, load metadata and check videoWidth/videoHeight.
                const url = URL.createObjectURL(file);
                const v = document.createElement('video');
                v.preload = 'metadata';
                v.onloadedmetadata = () => {
                    URL.revokeObjectURL(url);
                    const ratio = (v.videoWidth || 0) / Math.max(1, v.videoHeight || 1);
                    if (v.videoWidth > 0 && Math.abs(ratio - 16 / 9) > 16 / 9 * 0.02) {
                        setError(isFa
                            ? 'نسبت ویدیو باید ۱۶:۹ باشد. ویدیوی انتخابی ' + ratio.toFixed(2) + ' است.'
                            : 'Video aspect ratio must be 16:9. Selected ratio is ' + ratio.toFixed(2) + '.');
                    }
                };
                v.onerror = () => URL.revokeObjectURL(url);
                v.src = url;
            }
        });
    });

    // ─── Top loading progress bar ───────────────────────────────────────
    // Replaces the old full-screen splash. A thin blue bar sits under the
    // mini-topbar header and animates whenever the user navigates between
    // pages or submits a form. The lifecycle is:
    //
    //   1. Link click / form submit → bar grows from 0% → 80% (is-loading)
    //   2. New page loads → bar jumps to 100% (is-complete) for 200ms
    //   3. Container fades out, bar resets to 0% for next round.
    //
    // We also bump the bar to "is-complete" on pagehide / beforeunload so
    // a long-running navigation doesn't leave the bar stuck at 80%.
    const navProgress = document.querySelector('[data-nav-progress]');
    if (navProgress) {
        const navBar = navProgress.querySelector('.nav-progress-bar');
        let completeTimer = null;
        let fadeTimer = null;

        const startProgress = () => {
            if (completeTimer) { clearTimeout(completeTimer); completeTimer = null; }
            if (fadeTimer) { clearTimeout(fadeTimer); fadeTimer = null; }
            // Reset to 0 first so the animation always starts cleanly.
            navProgress.classList.remove('is-complete');
            if (navBar) navBar.style.width = '0%';
            // Force a reflow before adding is-loading, otherwise the
            // transition from 0% → 80% won't be visible (CSS will just
            // jump straight to 80%).
            // eslint-disable-next-line no-unused-expressions
            navProgress.offsetWidth;
            navProgress.classList.add('is-loading');
        };

        const completeProgress = () => {
            if (!navProgress.classList.contains('is-loading')) return;
            navProgress.classList.remove('is-loading');
            navProgress.classList.add('is-complete');
            completeTimer = setTimeout(() => {
                navProgress.classList.remove('is-complete');
                fadeTimer = setTimeout(() => {
                    if (navBar) navBar.style.width = '0%';
                }, 200);
            }, 220);
        };

        // Initial load: bump to 80% immediately, then complete once the
        // window's `load` event fires.
        startProgress();
        if (document.readyState === 'complete') {
            // Page already fully loaded — complete immediately on next frame.
            requestAnimationFrame(completeProgress);
        } else {
            window.addEventListener('load', () => requestAnimationFrame(completeProgress), { once: true });
        }

        // Intercept clicks on same-origin <a> tags (excluding target=_blank,
        // downloads, and modified clicks) to start the progress bar.
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a');
            if (!link) return;
            if (link.target === '_blank') return;
            if (link.hasAttribute('download')) return;
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const url = new URL(link.href, window.location.href);
            if (url.origin !== window.location.origin) return;
            if (url.pathname === window.location.pathname && url.hash) {
                // Same-page anchor link — don't trigger the loader.
                return;
            }
            startProgress();
        });

        // Form submits should also start the loader (matches what
        // data-loading-form does visually, but this catches every form).
        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!form || form.tagName !== 'FORM') return;
            // Skip forms that have data-loading-form because they manage
            // their own button-disabled state and we don't want to
            // double-track.
            if (form.hasAttribute('data-loading-form')) {
                startProgress();
            } else {
                startProgress();
            }
        });

        // If the page is unloaded (navigation completes server-side),
        // complete the bar so the next page's init can start fresh.
        window.addEventListener('pagehide', completeProgress);
        window.addEventListener('beforeunload', completeProgress);

        // Telegram MiniApp back-button + native navigation also benefits.
        try {
            window.Telegram?.WebApp?.BackButton?.onClick?.(startProgress);
        } catch (_) { /* ignore — Telegram SDK may not expose BackButton */ }
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
