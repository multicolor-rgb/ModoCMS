/* Modo CMS — Frontend Theme Settings Panel ("Edit template settings")
 * The panel and the live preview live in separate documents: the sidebar is in
 * the customizer shell, while the site is rendered in an iframe. Controls mapped
 * to a CSS custom property update the frame instantly, while the remaining ones
 * reload only the frame with the session draft overlaid on top of the published
 * values (see Core\CustomizerPanel / Core\Customizer). The sidebar itself is
 * never re-rendered during editing, so its scroll position, open sections and
 * focus survive every preview refresh. Reloads only happen once editing is
 * committed (field blur, switch/select change), so typing never interrupts it.
 */
(function () {
    'use strict';

    const CFG = window.MODO_CUSTOMIZER || {};
    const L = CFG.labels || {};

    // Confirmation helper: use the global styled modal when available,
    // otherwise fall back to the native confirm dialog.
    const uiConfirm = (message, title) => {
        if (window.UI && typeof window.UI.confirm === 'function') {
            return window.UI.confirm({ title: title || 'Confirm', message: message, okText: 'Confirm', danger: true });
        }
        return Promise.resolve(window.confirm(message));
    };
    const root = document.getElementById('modo-cz-panel');
    if (!root) return;

    // The live preview iframe. When it is absent (older embedded mode) the
    // script falls back to refreshing the whole page.
    const frame = document.getElementById('modo-czp-frame');

    const apiUrl = CFG.apiUrl || '/admin/customize-api.php';
    const uploadUrl = CFG.uploadUrl || '/admin/upload.php';
    const statusEl = document.getElementById('modo-czp-status');

    let reloadTimer = null;
    let statusTimer = null;
    let draftTimer = null;

    // Values as rendered by the server, used to detect real changes so the
    // preview is not reloaded when nothing actually changed.
    let initialValues = {};

    const STORAGE_KEY = 'modo_cz_panel_open';

    // ------------------------------------------------------------------
    // Utilities
    // ------------------------------------------------------------------
    function setStatus(text, type) {
        if (!statusEl) return;
        statusEl.textContent = text || '';
        statusEl.className = 'modo-cz-status' + (type ? ' is-' + type : '');
        if (statusTimer) clearTimeout(statusTimer);
        if (text) {
            statusTimer = setTimeout(() => { statusEl.textContent = ''; }, 3000);
        }
    }

    function api(action, extra) {
        const body = new FormData();
        body.append('action', action);
        body.append('csrf_token', CFG.csrf);
        Object.keys(extra || {}).forEach((k) => body.append(k, extra[k]));
        return fetch(apiUrl, { method: 'POST', body, credentials: 'same-origin' })
            .then((r) => r.json());
    }

    function collectValues() {
        const values = {};
        root.querySelectorAll('.modo-control[data-control-id]').forEach((el) => {
            const id = el.getAttribute('data-control-id');
            if (el.type === 'checkbox') {
                values[id] = el.checked ? '1' : '0';
            } else if (el.type === 'radio') {
                if (el.checked) values[id] = el.value;
            } else {
                values[id] = el.value;
            }
        });
        return values;
    }

    // ------------------------------------------------------------------
    // Live preview (rendered in the iframe next to the panel)
    // ------------------------------------------------------------------
    function previewDocument() {
        if (!frame) return document;
        try {
            return frame.contentDocument || (frame.contentWindow && frame.contentWindow.document) || null;
        } catch (e) {
            return null;
        }
    }

    function reloadPreviewFrame() {
        if (frame && frame.contentWindow) {
            frame.contentWindow.location.reload();
            return;
        }
        // Fallback for the old embedded mode without a preview frame.
        window.location.reload();
    }

    function applyCssVars(values) {
        const doc = previewDocument();
        if (!doc || !doc.head) return;

        let style = doc.getElementById('modo-panel-live-vars');
        if (!style) {
            style = doc.createElement('style');
            style.id = 'modo-panel-live-vars';
            doc.head.appendChild(style);
        }

        let css = ':root{';
        Object.keys(CFG.cssMap || {}).forEach((id) => {
            const raw = values[id];
            if (raw === undefined || raw === '' || raw === '0') return;
            const map = CFG.cssMap[id];
            let value = raw;
            if (map.unit && !isNaN(parseFloat(value))) value = parseFloat(value) + map.unit;
            css += '--' + map.var + ':' + value + ';';
        });
        css += '}';
        style.textContent = css;
    }

    function reloadPage() {
        if (reloadTimer) { clearTimeout(reloadTimer); reloadTimer = null; }
        // Publish/reset rebuild the whole shell so the panel picks up the
        // committed values from the server.
        window.location.reload();
    }

    function reloadPreview() {
        if (reloadTimer) { clearTimeout(reloadTimer); reloadTimer = null; }

        // Values that the refreshed frame will display. Updating the baseline
        // here keeps later change detection accurate (e.g. toggling a switch
        // back to its original value still triggers a reload).
        const baseline = collectValues();

        // Store the pending draft first, so the reloaded frame already shows the
        // value the user just edited.
        saveDraftNow().catch(() => {}).finally(() => {
            initialValues = baseline;
            reloadPreviewFrame();
        });
    }

    if (frame) {
        // The frame document is replaced on every preview reload: re-apply the
        // live CSS variables to the new document.
        frame.addEventListener('load', () => applyCssVars(collectValues()));
    }

    function saveDraft() {
        return api('save_draft', { values: JSON.stringify(collectValues()) });
    }

    function cancelDraftSave() {
        if (draftTimer) { clearTimeout(draftTimer); draftTimer = null; }
    }

    function saveDraftNow() {
        cancelDraftSave();
        return saveDraft();
    }

    function scheduleDraftSave() {
        if (draftTimer) clearTimeout(draftTimer);
        draftTimer = setTimeout(() => {
            draftTimer = null;
            saveDraft().then(() => setStatus(L.saved, 'ok')).catch(() => {});
        }, 700);
    }

    // True when at least one control that cannot be previewed through a CSS
    // custom property differs from the value rendered on page load.
    function hasPendingPreviewChanges() {
        const values = collectValues();
        let changed = false;
        root.querySelectorAll('.modo-control[data-control-id]').forEach((el) => {
            const id = el.getAttribute('data-control-id');
            if (!id || (CFG.cssMap && CFG.cssMap[id])) return;
            if (el.type === 'radio' && !el.checked) return;
            if (values[id] !== initialValues[id]) changed = true;
        });
        return changed;
    }

    // Reloads the live preview only when it is actually needed, i.e. when the
    // user has finished editing a control that is not driven by a CSS variable.
    function schedulePreviewReload() {
        if (reloadTimer) clearTimeout(reloadTimer);
        if (!hasPendingPreviewChanges()) {
            reloadTimer = null;
            return;
        }
        reloadTimer = setTimeout(() => {
            reloadTimer = null;
            reloadPreview();
        }, 300);
    }

    // Controls where typing is an ongoing action; a scheduled preview reload
    // must not interrupt the user in the middle of it.
    function isTypingControl(el) {
        if (!el || el.tagName === 'TEXTAREA') return true;
        return ['text', 'url', 'email', 'number', 'password'].indexOf(el.type) !== -1;
    }

    // Instant feedback while typing or dragging: never reloads the page.
    function onControlInput(el) {
        const values = collectValues();

        // Do not reload while the user is still typing in some field.
        if (reloadTimer && isTypingControl(el)) {
            clearTimeout(reloadTimer);
            reloadTimer = null;
        }

        // Instant feedback for CSS-variable driven controls.
        applyCssVars(values);

        // Colour text mirror.
        if (el.classList.contains('modo-color-input')) {
            const wrap = el.closest('.modo-color-wrap');
            const text = wrap && wrap.querySelector('.modo-color-text');
            if (text) text.value = el.value;
        }

        // Image preview mirror.
        if (el.classList.contains('modo-media-url')) {
            updateMediaPreview(el);
        }

        scheduleDraftSave();
    }

    // Called once editing is committed (blur for text fields, click for
    // checkboxes/selects/radios, release for ranges). Only here — and only for
    // controls without a CSS variable — a full page refresh may happen.
    function onControlCommit(el) {
        onControlInput(el);

        const id = el.getAttribute('data-control-id');
        if (!id) return;
        if (CFG.cssMap && CFG.cssMap[id]) return;

        schedulePreviewReload();
    }

    // Controls wiring (delegated).
    root.addEventListener('input', (e) => {
        if (e.target.classList && e.target.classList.contains('modo-control')) {
            onControlInput(e.target);
        }
    });
    root.addEventListener('change', (e) => {
        if (e.target.classList && e.target.classList.contains('modo-control')) {
            onControlCommit(e.target);
        }
    });

    // ------------------------------------------------------------------
    // Collapsible sections: collapsed by default, open state remembered
    // across live-preview reloads (per theme).
    // ------------------------------------------------------------------
    const SECTIONS_KEY = 'modo_cz_sections_' + (CFG.theme || 'default');
    const SCROLL_KEY = 'modo_cz_scroll_' + (CFG.theme || 'default');

    function readOpenSections() {
        try {
            const raw = localStorage.getItem(SECTIONS_KEY);
            if (!raw) return null;
            const list = JSON.parse(raw);
            return Array.isArray(list) ? list : null;
        } catch (e) {
            return null;
        }
    }

    function storeOpenSections() {
        const open = [];
        root.querySelectorAll('details.modo-cz-section[data-section-id]').forEach((section) => {
            if (section.open) open.push(section.getAttribute('data-section-id'));
        });
        try { localStorage.setItem(SECTIONS_KEY, JSON.stringify(open)); } catch (e) {}
    }

    function initSectionState() {
        const sections = root.querySelectorAll('details.modo-cz-section[data-section-id]');
        if (!sections.length) return;

        const open = readOpenSections();
        if (open) {
            sections.forEach((section) => {
                section.open = open.indexOf(section.getAttribute('data-section-id')) !== -1;
            });
        }

        // The "toggle" event does not bubble, so listen in the capture phase.
        root.addEventListener('toggle', (e) => {
            const section = e.target;
            if (section && section.classList && section.classList.contains('modo-cz-section')) {
                storeOpenSections();
            }
        }, true);
    }

    function initScrollState() {
        const body = root.querySelector('.modo-czp-body');
        if (!body) return;

        let saved = 0;
        try { saved = parseInt(sessionStorage.getItem(SCROLL_KEY) || '0', 10) || 0; } catch (e) {}

        if (saved > 0) {
            window.requestAnimationFrame(() => { body.scrollTop = saved; });
        }

        body.addEventListener('scroll', () => {
            try { sessionStorage.setItem(SCROLL_KEY, String(body.scrollTop)); } catch (e) {}
        }, { passive: true });
    }

    // ------------------------------------------------------------------
    // Media fields: choose from the library or upload from the computer
    // ------------------------------------------------------------------
    const mediaModal = document.getElementById('modo-cz-media-modal');
    const mediaGrid = document.getElementById('modo-cz-media-grid');
    const mediaEmpty = document.getElementById('modo-cz-media-empty');
    const mediaFolderFilter = document.getElementById('modo-cz-media-folder-filter');
    let mediaTarget = null;
    let uploadFromModal = false;

    function applyMediaFolderFilter() {
        if (!mediaGrid) return;
        const value = mediaFolderFilter ? mediaFolderFilter.value : '__all__';
        mediaGrid.querySelectorAll('.modo-cz-media-item').forEach((tile) => {
            const matches = value === '__all__' || (tile.getAttribute('data-folder') || '') === value;
            tile.style.display = matches ? '' : 'none';
        });
    }

    if (mediaFolderFilter) {
        mediaFolderFilter.addEventListener('change', applyMediaFolderFilter);
    }

    function updateMediaPreview(field) {
        const wrap = field && field.closest ? field.closest('.modo-media-field') : null;
        if (!wrap) return;

        const preview = wrap.querySelector('[data-media-preview]');
        const img = wrap.querySelector('[data-media-preview-img]');
        const placeholder = wrap.querySelector('[data-media-placeholder]');
        const remove = wrap.querySelector('.modo-media-remove');
        const value = (field.value || '').trim();
        const hasImage = value !== '';

        if (preview) preview.classList.toggle('has-image', hasImage);
        if (img) {
            if (hasImage) {
                img.src = value;
                img.hidden = false;
            } else {
                img.removeAttribute('src');
                img.hidden = true;
            }
        }
        if (placeholder) placeholder.hidden = hasImage;
        if (remove) remove.hidden = !hasImage;
    }

    function setMediaValue(field, url) {
        if (!field) return;
        field.value = url || '';
        updateMediaPreview(field);
        onControlCommit(field);
    }

    function triggerMediaPicker(field, fromModal) {
        const wrap = field && field.closest ? field.closest('.modo-media-field') : null;
        const picker = wrap ? wrap.querySelector('.modo-media-picker') : null;
        if (!picker) return;

        uploadFromModal = !!fromModal;
        picker.onchange = () => {
            if (!picker.files || !picker.files.length) return;
            const file = picker.files[0];
            const fd = new FormData();
            fd.append('file', file);
            fd.append('csrf', (window.MODO_CSRF || (typeof CFG !== 'undefined' ? CFG.csrf : '') || ''));

            setStatus(L.uploading, 'ok');
            fetch(uploadUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then((r) => r.json())
                .then((data) => {
                    if (!data || !data.location) {
                        setStatus((data && data.error) || L.uploadFailed, 'err');
                        return;
                    }
                    addMediaTile(data.location, file.name);
                    setMediaValue(field, data.location);
                    setStatus(L.saved, 'ok');
                    if (uploadFromModal) closeMediaModal();
                })
                .catch(() => setStatus(L.uploadFailed, 'err'));
        };
        picker.value = '';
        picker.click();
    }

    function addMediaTile(url, name) {
        if (!mediaGrid || !url) return;
        const tile = document.createElement('button');
        tile.type = 'button';
        tile.className = 'modo-cz-media-item';
        tile.setAttribute('data-media-url', url);
        tile.setAttribute('data-folder', '');

        const img = document.createElement('img');
        img.src = url;
        img.alt = name || '';
        img.loading = 'lazy';

        const label = document.createElement('span');
        label.className = 'modo-cz-media-name';
        label.textContent = name || url.split('/').pop();

        tile.appendChild(img);
        tile.appendChild(label);
        mediaGrid.insertBefore(tile, mediaGrid.firstChild);
        if (mediaEmpty) mediaEmpty.hidden = true;
        applyMediaFolderFilter();
    }

    function openMediaModal(field) {
        if (!mediaModal || !field) return;
        mediaTarget = field;
        if (mediaFolderFilter) {
            mediaFolderFilter.value = '__all__';
            applyMediaFolderFilter();
        }
        mediaModal.hidden = false;
        const first = mediaGrid ? mediaGrid.querySelector('.modo-cz-media-item') : null;
        if (first) setTimeout(() => first.focus(), 30);
    }

    function closeMediaModal() {
        if (!mediaModal) return;
        mediaModal.hidden = true;
        mediaTarget = null;
        uploadFromModal = false;
    }

    root.addEventListener('click', (e) => {
        const action = e.target.closest('.modo-media-library, .modo-media-upload, .modo-media-remove');
        if (!action) return;
        const wrap = action.closest('.modo-media-field');
        const field = wrap ? wrap.querySelector('.modo-media-url') : null;
        if (!field) return;

        if (action.classList.contains('modo-media-library')) {
            openMediaModal(field);
        } else if (action.classList.contains('modo-media-upload')) {
            triggerMediaPicker(field, false);
        } else {
            setMediaValue(field, '');
        }
    });

    if (mediaModal) {
        mediaModal.addEventListener('click', (e) => {
            if (e.target === mediaModal || e.target.closest('[data-media-close]')) {
                closeMediaModal();
                return;
            }
            if (e.target.closest('[data-media-upload]')) {
                if (mediaTarget) triggerMediaPicker(mediaTarget, true);
                return;
            }
            const tile = e.target.closest('.modo-cz-media-item');
            if (tile && mediaTarget) {
                const field = mediaTarget;
                const url = tile.getAttribute('data-media-url') || '';
                closeMediaModal();
                setMediaValue(field, url);
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !mediaModal.hidden) closeMediaModal();
        });
    }

    // Reflect any server-rendered values in the preview boxes on load.
    root.querySelectorAll('.modo-media-url').forEach(updateMediaPreview);

    // ------------------------------------------------------------------
    // Top bar actions
    // ------------------------------------------------------------------
    const publishBtn = document.getElementById('modo-czp-publish');
    const resetBtn = document.getElementById('modo-czp-reset');

    if (publishBtn) {
        publishBtn.addEventListener('click', () => {
            publishBtn.disabled = true;
            api('publish', { values: JSON.stringify(collectValues()) })
                .then((res) => {
                    if (res.token) CFG.token = res.token;
                    setStatus(L.published, 'ok');
                    // Nothing left to save: the submitted values are published.
                    cancelDraftSave();
                    reloadPage();
                })
                .catch(() => setStatus(L.error, 'err'))
                .finally(() => { publishBtn.disabled = false; });
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            uiConfirm(L.confirmReset, L.reset).then((ok) => {
                if (!ok) return;
                api('reset', {}).then((res) => {
                    if (res.token) CFG.token = res.token;
                    setStatus(L.reset, 'ok');
                    // Do not save a draft after a reset, or it would be recreated.
                    cancelDraftSave();
                    reloadPage();
                }).catch(() => setStatus(L.error, 'err'));
            });
        });
    }

    // ------------------------------------------------------------------
    // Open / close (persisted per browser)
    // ------------------------------------------------------------------
    const reopenBtn = document.getElementById('modo-czp-reopen');
    const closeBtn = document.getElementById('modo-czp-close');

    function setOpen(open) {
        document.documentElement.classList.toggle('modo-cz-panel-collapsed', !open);
        try { localStorage.setItem(STORAGE_KEY, open ? '1' : '0'); } catch (e) {}
    }

    if (closeBtn) closeBtn.addEventListener('click', () => setOpen(false));
    if (reopenBtn) reopenBtn.addEventListener('click', () => setOpen(true));

    // The panel is rendered by the server only when the "Edit template settings"
    // link was used, so always expand it automatically on load.
    setOpen(true);

    // Snapshot the server-rendered values before the user edits anything.
    initialValues = collectValues();

    // Restore the sections the user had expanded and the panel scroll position.
    initSectionState();
    initScrollState();

    // Reflect the current draft instantly on load.
    applyCssVars(collectValues());
})();
