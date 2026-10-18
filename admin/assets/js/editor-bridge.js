/* ==========================================================================
   Modo CMS — editor bridge
   --------------------------------------------------------------------------
   A tiny, editor agnostic button registry shared by the admin content editors.

   Plugins (modo-gallery, modo-form, modo-carousel, …) register their toolbar
   actions here instead of talking to TinyMCE directly:

       window.ModoEditor.registerButton({
           id:     'modo_gallery',
           label:  'Gallery',
           icon:   '<svg …>',                  // optional inline SVG
           insert: function (api) { … }         // called when clicked
       });

   The bridge mounts the collected buttons above the Editor.js canvas and
   passes the callback an `api` object that knows how to insert content into
   the block editor. TinyMCE is untouched: its own toolbar items keep working
   exactly as before, so the registration is purely additive.
   ========================================================================== */
(function () {
    'use strict';

    var registry = window.ModoEditor = window.ModoEditor || {};
    var buttons = registry.buttons = registry.buttons || [];
    var mountPoint = null;
    var editorApi = null;

    /** Escapes a string for safe HTML interpolation. */
    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /** Shows a message through the admin UI kit (with a plain fallback). */
    function message(text, danger) {
        if (window.UI && typeof UI.alert === 'function') {
            UI.alert({ title: registry.labels && registry.labels.notice ? registry.labels.notice : 'Notice', message: text, danger: !!danger });
            return;
        }
        window.alert(text);
    }

    /**
     * Lightweight modal list picker mirroring the TinyMCE selectbox dialogs
     * used by the plugins (gallery / form / carousel inserters).
     */
    function pickFromList(options, onPick) {
        options = options || {};
        var items = options.items || [];

        if (!items.length) {
            message(options.emptyText || 'Nothing to choose from yet.', true);
            return;
        }

        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(11,15,25,.8);z-index:100000;'
            + 'display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px);padding:20px;';

        var box = document.createElement('div');
        box.className = 'card';
        box.style.cssText = 'width:100%;max-width:420px;background:var(--bg-card,#111827);'
            + 'border:1px solid var(--border-subtle);border-radius:var(--radius-md,10px);padding:20px;'
            + 'display:flex;flex-direction:column;gap:12px;';
        box.innerHTML = '<h3 style="margin:0;font-size:15px;font-weight:700;color:var(--text-main);">' + esc(options.title || '') + '</h3>'
            + '<div style="display:flex;flex-direction:column;gap:6px;">'
            +   '<label style="font-size:12px;color:var(--text-muted);">' + esc(options.label || '') + '</label>'
            +   '<select class="form-control" style="width:100%;"></select>'
            + '</div>'
            + '<div style="display:flex;justify-content:flex-end;gap:8px;">'
            +   '<button type="button" class="btn btn-secondary" data-cancel>' + esc(options.cancelText || 'Cancel') + '</button>'
            +   '<button type="button" class="btn btn-primary" data-submit>' + esc(options.submitText || 'Insert') + '</button>'
            + '</div>';

        var select = box.querySelector('select');
        items.forEach(function (item) {
            var option = document.createElement('option');
            option.value = String(item.value);
            option.textContent = String(item.text);
            select.appendChild(option);
        });

        function close() {
            document.removeEventListener('keydown', onKey);
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
        }
        function onKey(event) {
            if (event.key === 'Escape') { close(); }
        }

        box.querySelector('[data-cancel]').addEventListener('click', close);
        box.querySelector('[data-submit]').addEventListener('click', function () {
            var value = select.value;
            close();
            if (typeof onPick === 'function') { onPick(value); }
        });
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) { close(); }
        });
        document.addEventListener('keydown', onKey);

        overlay.appendChild(box);
        document.body.appendChild(overlay);
        select.focus();
    }

    registry.esc = esc;
    registry.message = message;
    registry.pickFromList = pickFromList;

    /**
     * Registers (or replaces) a toolbar button.
     *
     * @param {Object} definition { id, label, icon?, disabled?, insert }
     */
    registry.registerButton = function (definition) {
        if (!definition || !definition.id || typeof definition.insert !== 'function') {
            return null;
        }
        for (var i = 0; i < buttons.length; i++) {
            if (buttons[i].id === definition.id) {
                buttons[i] = definition;
                if (mountPoint && editorApi) { render(mountPoint, editorApi); }
                return definition;
            }
        }
        buttons.push(definition);
        if (mountPoint && editorApi) { render(mountPoint, editorApi); }
        return definition;
    };

    /**
     * Draws every registered button inside the given container.
     */
    function render(container, api) {
        var visible = buttons.filter(function (button) { return !button.disabled; });

        container.innerHTML = '';
        container.style.cssText = 'display:flex;flex-wrap:wrap;align-items:center;gap:8px;'
            + 'padding:8px 10px;margin-bottom:10px;border:1px solid var(--border-subtle);'
            + 'border-radius:var(--radius-sm,8px);background:var(--bg-surface,#1e293b);';

        if (!visible.length) {
            container.style.display = 'none';
            return;
        }
        container.style.display = 'flex';

        visible.forEach(function (definition) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-secondary';
            button.style.cssText = 'display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:5px 10px;';
            button.innerHTML = (definition.icon
                ? '<span style="display:inline-flex;width:14px;height:14px;">' + definition.icon + '</span>'
                : '')
                + '<span>' + esc(definition.label || definition.id) + '</span>';

            button.addEventListener('click', function () {
                try {
                    definition.insert(api);
                } catch (err) {
                    if (window.console && console.error) {
                        console.error('[ModoEditor] button "' + definition.id + '" failed:', err);
                    }
                    message((definition.label || definition.id) + ': ' + (err && err.message ? err.message : 'insert failed'), true);
                }
            });

            container.appendChild(button);
        });
    }

    /**
     * Mounts the collected buttons into the Editor.js toolbar.
     *
     * @param {HTMLElement} container Toolbar container.
     * @param {Object}      api       Editor API handed to every insert callback.
     */
    registry.mountToolbar = function (container, api) {
        if (!container) {
            return;
        }
        mountPoint = container;
        editorApi = api;
        render(container, api);
    };
})();
