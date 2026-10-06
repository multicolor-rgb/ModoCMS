/* Modo CMS — Theme settings manager (backend)
 * Handles the schema builder (sections/controls stored in the database).
 * Editing the actual values with live preview is available on the frontend
 * via the admin "Edit template settings" sidebar (Core\CustomizerPanel).
 */
(function () {
    'use strict';

    const CFG = window.MODO_CUSTOMIZER || {};
    const L = CFG.labels || {};

    const root = document.getElementById('modo-customizer');
    if (!root) return;

    const apiUrl = 'customize-api.php';
    const statusEl = document.getElementById('modo-cz-status');

    let statusTimer = null;

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

    const saveSchemaBtn = document.getElementById('modo-cz-save-schema');
    if (saveSchemaBtn) {
        saveSchemaBtn.addEventListener('click', () => {
            const sections = readBuilder();
            api('schema_save', { sections: JSON.stringify(sections) })
                .then((res) => {
                    if (res.ok) {
                        CFG.storedSchema = res.stored || sections;
                        setStatus(L.schemaSaved, 'ok');
                    } else {
                        setStatus(res.error || L.error, 'err');
                    }
                })
                .catch(() => setStatus(L.error, 'err'));
        });
    }
})();

