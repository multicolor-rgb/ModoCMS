/* Modo CMS — Live Customizer UI
 * Handles the live iframe preview, draft auto-save, publish/reset, device
 * switching and the schema builder (sections/controls stored in the database).
 */
(function () {
    'use strict';

    const CFG = window.MODO_CUSTOMIZER || {};
    const L = CFG.labels || {};
    const root = document.getElementById('modo-customizer');
    if (!root) return;

    const apiUrl = 'customize-api.php';
    const frame = document.getElementById('modo-cz-frame');
    const statusEl = document.getElementById('modo-cz-status');
    const frameWrap = root.querySelector('.modo-cz-frame-wrap');

    let reloadTimer = null;
    let statusTimer = null;
    let draftTimer = null;

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
            } else {
                values[id] = el.value;
            }
        });
        return values;
    }

    // ------------------------------------------------------------------
    // Live preview
    // ------------------------------------------------------------------
    function applyCssVars(values) {
        let doc;
        try { doc = frame.contentDocument; } catch (e) { return; }
        if (!doc || !doc.head) return;

        let style = doc.getElementById('modo-cz-live-vars');
        if (!style) {
            style = doc.createElement('style');
            style.id = 'modo-cz-live-vars';
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

    function reloadPreview() {
        if (!frame) return;
        const base = frame.src.split('modo_preview=')[0];
        frame.src = base + 'modo_preview=' + encodeURIComponent(CFG.token) + '&_=' + Date.now();
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

        scheduleDraftSave();

        // Full refresh only when the change cannot be previewed via CSS vars.
        const id = el.getAttribute('data-control-id');
        if (!CFG.cssMap || !CFG.cssMap[id]) {
            if (reloadTimer) clearTimeout(reloadTimer);
            reloadTimer = setTimeout(reloadPreview, 900);
        }
    }

    // ------------------------------------------------------------------
    // Controls wiring (delegated)
    // ------------------------------------------------------------------
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

    // Media picker (reuses the shared upload endpoint).
    root.addEventListener('click', (e) => {
        const btn = e.target.closest('.modo-media-btn');
        if (!btn) return;
        const wrap = btn.closest('.modo-media-wrap');
        const picker = wrap.parentNode.querySelector('.modo-media-picker');
        const field = wrap.querySelector('.modo-control');
        if (!picker || !field) return;
        picker.onchange = () => {
            if (!picker.files.length) return;
            const fd = new FormData();
            fd.append('file', picker.files[0]);
            fetch('upload.php', { method: 'POST', body: fd })
                .then((r) => r.json())
                .then((data) => {
                    if (data.location) {
                        field.value = data.location;
                        onControlChange(field);
                    } else {
                        setStatus(data.error || L.uploadFailed, 'err');
                    }
                })
                .catch(() => setStatus(L.uploadFailed, 'err'));
        };
        picker.value = '';
        picker.click();
    });

    if (frame) {
        frame.addEventListener('load', () => applyCssVars(collectValues()));
    }

    // ------------------------------------------------------------------
    // Top bar actions
    // ------------------------------------------------------------------
    const publishBtn = document.getElementById('modo-cz-publish');
    const resetBtn = document.getElementById('modo-cz-reset');

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
            if (!window.confirm(L.confirmReset)) return;
            api('reset', {}).then((res) => {
                if (res.token) CFG.token = res.token;
                setStatus(L.reset, 'ok');
                reloadPreview();
            }).catch(() => setStatus(L.error, 'err'));
        });
    }

    // Device switcher
    root.querySelectorAll('.modo-cz-device').forEach((btn) => {
        btn.addEventListener('click', () => {
            root.querySelectorAll('.modo-cz-device').forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            if (frameWrap) frameWrap.setAttribute('data-device', btn.getAttribute('data-device'));
        });
    });

    // Tabs
    root.querySelectorAll('.modo-cz-tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            const name = tab.getAttribute('data-tab');
            root.querySelectorAll('.modo-cz-tab').forEach((t) => t.classList.toggle('active', t === tab));
            root.querySelectorAll('.modo-cz-panel').forEach((p) => {
                p.hidden = p.getAttribute('data-panel') !== name;
            });
        });
    });


    // ------------------------------------------------------------------
    // Schema builder (sections & controls stored in the DB)
    // ------------------------------------------------------------------
    const builderEl = document.getElementById('modo-cz-builder');

    const TYPES = CFG.controlTypes || ['text'];
    const OPTION_TYPES = ['select', 'radio'];

    function el(tag, cls, text) {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function field(labelText, input) {
        const wrap = el('div', 'modo-bf');
        wrap.appendChild(el('label', 'modo-bf-label', labelText));
        wrap.appendChild(input);
        return wrap;
    }

    function input(value, cls) {
        const i = el('input', 'form-control ' + (cls || ''));
        i.type = 'text';
        i.value = value === undefined || value === null ? '' : value;
        return i;
    }

    function optionsToText(options) {
        return (options || []).map((o) => o.value + '|' + o.label).join('\n');
    }

    function controlEl(control) {
        const box = el('div', 'modo-builder-control');

        const head = el('div', 'modo-builder-control-head');
        head.appendChild(el('span', 'modo-builder-control-title', L.field || 'Field'));
        const rm = el('button', 'modo-builder-remove', '×');
        rm.type = 'button';
        rm.addEventListener('click', () => box.remove());
        head.appendChild(rm);
        box.appendChild(head);

        const idIn = input(control.id, 'bf-id');
        const typeIn = el('select', 'form-control bf-type');
        TYPES.forEach((t) => {
            const o = el('option', null, t);
            o.value = t;
            if (t === control.type) o.selected = true;
            typeIn.appendChild(o);
        });
        const labelIn = input(control.label, 'bf-label');
        const cssVarIn = input(control.css_var, 'bf-css-var');
        const cssUnitIn = input(control.css_unit, 'bf-css-unit');
        const defIn = input(control.default, 'bf-default');
        const descIn = input(control.description, 'bf-desc');
        const minIn = input(control.min, 'bf-min');
        const maxIn = input(control.max, 'bf-max');
        const stepIn = input(control.step, 'bf-step');

        const optWrap = el('div', 'modo-bf modo-bf-options');
        optWrap.appendChild(el('label', 'modo-bf-label', (L.options || 'Options') + ' (value|label)'));
        const optIn = el('textarea', 'form-control bf-options');
        optIn.rows = 3;
        optIn.value = optionsToText(control.options);
        optWrap.appendChild(optIn);

        function syncOptionVisibility() {
            optWrap.style.display = OPTION_TYPES.indexOf(typeIn.value) !== -1 ? '' : 'none';
        }
        typeIn.addEventListener('change', syncOptionVisibility);

        box.appendChild(field(L.id || 'ID', idIn));
        box.appendChild(field(L.type || 'Type', typeIn));
        box.appendChild(field(L.label || 'Label', labelIn));
        box.appendChild(field(L.default || 'Default', defIn));
        box.appendChild(field(L.description || 'Description', descIn));
        box.appendChild(field(L.cssVar || 'CSS variable', cssVarIn));
        box.appendChild(field(L.cssUnit || 'CSS unit', cssUnitIn));
        box.appendChild(field('min', minIn));
        box.appendChild(field('max', maxIn));
        box.appendChild(field('step', stepIn));
        box.appendChild(optWrap);

        syncOptionVisibility();
        return box;
    }


    function sectionEl(section) {
        const box = el('div', 'modo-builder-section');

        const head = el('div', 'modo-builder-section-head');
        const idIn = input(section.id, 'bs-id');
        const titleIn = input(section.title, 'bs-title');
        const prioIn = input(section.priority, 'bs-priority');
        prioIn.type = 'number';
        const rm = el('button', 'modo-builder-remove', '×');
        rm.type = 'button';
        rm.addEventListener('click', () => box.remove());
        head.appendChild(el('span', 'modo-builder-section-title', L.section || 'Section'));
        head.appendChild(rm);
        box.appendChild(head);
        box.appendChild(field(L.id || 'ID', idIn));
        box.appendChild(field(L.title || 'Title', titleIn));
        box.appendChild(field(L.priority || 'Priority', prioIn));

        const controlsWrap = el('div', 'modo-builder-controls');
        box.appendChild(controlsWrap);
        (section.controls || []).forEach((c) => controlsWrap.appendChild(controlEl(c)));

        const addBtn = el('button', 'btn btn-secondary modo-builder-add-control', '+ ' + (L.addField || 'Add field'));
        addBtn.type = 'button';
        addBtn.addEventListener('click', () => controlsWrap.appendChild(controlEl({ type: 'text' })));
        box.appendChild(addBtn);
        return box;
    }

    function renderBuilder() {
        if (!builderEl) return;
        builderEl.innerHTML = '';
        (CFG.storedSchema || []).forEach((s) => builderEl.appendChild(sectionEl(s)));
    }

    function readNumber(v) {
        return v === '' || v === null || isNaN(parseFloat(v)) ? null : Number(v);
    }

    function readBuilder() {
        const sections = [];
        builderEl.querySelectorAll('.modo-builder-section').forEach((secBox) => {
            const id = secBox.querySelector('.bs-id').value.trim();
            if (!id) return;
            const controls = [];
            secBox.querySelectorAll('.modo-builder-control').forEach((cBox) => {
                const cid = cBox.querySelector('.bf-id').value.trim();
                if (!cid) return;
                const optionsText = cBox.querySelector('.bf-options').value || '';
                const options = {};
                optionsText.split('\n').forEach((line) => {
                    line = line.trim();
                    if (!line) return;
                    const parts = line.split('|');
                    const value = parts[0].trim();
                    const label = (parts[1] !== undefined ? parts[1] : parts[0]).trim();
                    if (value) options[value] = label;
                });
                controls.push({
                    id: cid,
                    type: cBox.querySelector('.bf-type').value,
                    label: cBox.querySelector('.bf-label').value,
                    default: cBox.querySelector('.bf-default').value,
                    description: cBox.querySelector('.bf-desc').value,
                    css_var: cBox.querySelector('.bf-css-var').value,
                    css_unit: cBox.querySelector('.bf-css-unit').value,
                    min: readNumber(cBox.querySelector('.bf-min').value),
                    max: readNumber(cBox.querySelector('.bf-max').value),
                    step: readNumber(cBox.querySelector('.bf-step').value),
                    options
                });
            });
            sections.push({
                id,
                title: secBox.querySelector('.bs-title').value,
                priority: readNumber(secBox.querySelector('.bs-priority').value) || 0,
                controls
            });
        });
        return sections;
    }

    renderBuilder();

    const addSectionBtn = document.getElementById('modo-cz-add-section');
    if (addSectionBtn) {
        addSectionBtn.addEventListener('click', () => {
            if (builderEl) builderEl.appendChild(sectionEl({ id: '', title: '', priority: 100, controls: [] }));
        });
    }

    function refreshCustomizePanel() {
        return api('panel', {}).then((res) => {
            if (res && res.ok && typeof res.html === 'string') {
                const panel = root.querySelector('.modo-cz-panel[data-panel="customize"]');
                if (panel) panel.innerHTML = res.html;
                if (res.cssMap) CFG.cssMap = res.cssMap;
                applyCssVars(collectValues());
            }
        });
    }

    const saveSchemaBtn = document.getElementById('modo-cz-save-schema');
    if (saveSchemaBtn) {
        saveSchemaBtn.addEventListener('click', () => {
            const sections = readBuilder();
            api('schema_save', { sections: JSON.stringify(sections) })
                .then((res) => {
                    if (res.ok) {
                        CFG.storedSchema = res.stored || sections;
                        setStatus(L.schemaSaved, 'ok');
                        // Refresh the Customize tab so new fields appear immediately.
                        return refreshCustomizePanel().then(reloadPreview);
                    }
                    setStatus(res.error || L.error, 'err');
                })
                .catch(() => setStatus(L.error, 'err'));
        });
    }
})();

