/* Modo CMS — Frontend Theme Settings Panel ("Edit template settings")
 * A slide-in sidebar rendered on the live frontend. The page itself is the
 * preview: controls mapped to a CSS custom property update instantly, while all
 * other changes reload the page with the session draft overlaid on top of the
 * published values (see Core\CustomizerPanel / Core\Customizer).
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

    const apiUrl = CFG.apiUrl || '/admin/customize-api.php';
    const uploadUrl = CFG.uploadUrl || '/admin/upload.php';
    const statusEl = document.getElementById('modo-czp-status');

    let reloadTimer = null;
    let statusTimer = null;
    let draftTimer = null;

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
    // Live preview (the current page is the preview)
    // ------------------------------------------------------------------
    function applyCssVars(values) {
        let style = document.getElementById('modo-panel-live-vars');
        if (!style) {
            style = document.createElement('style');
            style.id = 'modo-panel-live-vars';
            document.head.appendChild(style);
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

    function reloadPreview() {
        // The URL keeps ?modo_customize=1, so the panel and the draft persist.
        window.location.reload();
    }

    function saveDraft() {
        return api('save_draft', { values: JSON.stringify(collectValues()) });
    }

    function scheduleDraftSave() {
        if (draftTimer) clearTimeout(draftTimer);
        draftTimer = setTimeout(() => {
            saveDraft().then(() => setStatus(L.saved, 'ok')).catch(() => {});
        }, 700);
    }

    function onControlChange(el) {
        const values = collectValues();

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

        // Full refresh only when the change cannot be previewed via CSS vars.
        const id = el.getAttribute('data-control-id');
        if (!CFG.cssMap || !CFG.cssMap[id]) {
            if (reloadTimer) clearTimeout(reloadTimer);
            reloadTimer = setTimeout(reloadPreview, 1000);
        }
    }

    // Controls wiring (delegated).
    root.addEventListener('input', (e) => {
        if (e.target.classList && e.target.classList.contains('modo-control')) {
            onControlChange(e.target);
        }
    });
    root.addEventListener('change', (e) => {
        if (e.target.classList && e.target.classList.contains('modo-control')) {
            onControlChange(e.target);
        }
    });

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
        onControlChange(field);
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
                    reloadPreview();
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
                    reloadPreview();
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

    // Reflect the current draft instantly on load.
    applyCssVars(collectValues());
})();
