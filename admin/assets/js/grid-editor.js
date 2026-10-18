/* ==========================================================================
   Modo CMS — Grid / Columns inserter for TinyMCE
   Adds a "modo_grid" toolbar button that inserts framework-correct grid
   markup (Bootstrap, Bulma, Pico, UIkit, Foundation, Tailwind, Modo Grid or a
   generic fallback). The framework is configured server-side in Settings and
   exposed through the global window.MODO_GRID object.
   ========================================================================== */
(function () {
    'use strict';

    function getConfig() {
        // Safely retrieve MODO_GRID, defaulting to null if not present
        if (window.MODO_GRID && typeof window.MODO_GRID === 'object') {
            return window.MODO_GRID;
        }
        return null;
    }

    function label(key, fallback) {
        var c = getConfig();
        return (c && c.labels && c.labels[key]) ? c.labels[key] : fallback;
    }

    /** Escapes a string for safe use inside HTML text nodes. */
    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /** Evenly distributes 12 columns across the requested column count. */
    function width12(count) {
        return Math.max(1, Math.round(12 / count));
    }

    /**
     * Builds a single column element's markup for the active framework.
     */
    function buildColumn(fw, count, bp, content) {
        var w = width12(count);
        var inner = content === '' ? '&nbsp;' : esc(content);
        var cls = '';

        switch (fw.mode) {
            case 'bootstrap':
                cls = bp ? ('col-' + bp + '-' + w) : ('col-' + w);
                return '<div class="' + cls + '">' + inner + '</div>';
            case 'bulma':
                cls = count > 1 ? ('column is-' + w + (bp ? '-' + bp : '')) : 'column';
                return '<div class="' + cls + '">' + inner + '</div>';
            case 'foundation':
                cls = (bp && bp !== 'small') ? (bp + '-' + w) : ('small-' + w);
                return '<div class="cell ' + cls + '">' + inner + '</div>';
            case 'modo':
                cls = bp ? ('modo-col-' + bp + '-' + w) : ('modo-col-' + w);
                return '<div class="' + cls + '">' + inner + '</div>';
            case 'pico':
            case 'uikit':
            case 'tailwind':
                return '<div>' + inner + '</div>';
            default:
                return '<div class="grid-col">' + inner + '</div>';
        }
    }

    /**
     * Builds the full grid markup for the active framework.
     */
    function buildGrid(fw, count, bp, contents) {
        var parts = [];
        for (var i = 0; i < count; i++) {
            parts.push(buildColumn(fw, count, bp, contents[i] || ''));
        }
        var colsHtml = parts.join('\n');
        var rowOpen, rowClose = '</div>';

        switch (fw.mode) {
            case 'bootstrap':
                rowOpen = '<div class="row">';
                break;
            case 'bulma':
                rowOpen = '<div class="columns' + (bp ? ' is-multiline' : '') + '">';
                break;
            case 'pico':
                rowOpen = '<div class="grid" data-cols="' + count + '">';
                break;
            case 'uikit':
                rowOpen = '<div class="uk-grid uk-child-width-1-' + count + (bp ? '@' + bp : '') + '">';
                break;
            case 'foundation':
                rowOpen = '<div class="grid-x grid-padding-x">';
                break;
            case 'tailwind':
                rowOpen = bp
                    ? '<div class="grid grid-cols-1 gap-4 ' + bp + ':grid-cols-' + count + '">'
                    : '<div class="grid grid-cols-' + count + ' gap-4">';
                break;
            case 'modo':
                rowOpen = '<div class="modo-grid">';
                break;
            default:
                rowOpen = '<div class="grid-row">';
        }

        var containerOpen = '';
        var containerClose = '';
        if (fw.container) {
            containerOpen = '<div class="' + fw.container + '">\n';
            containerClose = '\n</div>';
        }

        return containerOpen + rowOpen + '\n' + colsHtml + '\n' + rowClose + containerClose;
    }

    /**
     * Registers the modo_grid button and opens the builder dialog.
     */
    function openBuilder(editor) {
        var cfg = getConfig();
        if (!cfg) {
            // Fallback: try to use a default 'bootstrap' mode if MODO_GRID is missing
            editor.notificationManager.open({ text: label('none', 'No grid framework selected.'), type: 'warning' });
            return;
        }
        // Work on a copy so the shared window.MODO_GRID object is never mutated.
        var fw = Object.assign({}, cfg);
        // Ensure mode has a valid value; default to 'none' if missing or invalid
        if (!fw.mode || typeof fw.mode !== 'string') {
            fw.mode = 'none';
        }
        if (fw.mode === 'none') {
            editor.notificationManager.open({ text: label('none', 'No grid framework selected.'), type: 'warning' });
            return;
        }

        var colItems = [1, 2, 3, 4, 6, 12].map(function (n) {
            return { text: n + ' \u00d7', value: String(n) };
        });

        var bpItems = [{ text: '\u2014 (all sizes)', value: '' }].concat((fw.breakpoints || []).map(function (b) {
            return { text: b, value: b };
        }));

        var items = [
            // TinyMCE 6 names the dropdown component "selectbox" – there is no
            // "select" type, and an unknown type makes the whole dialog body fail
            // ("Unknown factory type \"select\", defaulting to container").
            { type: 'selectbox', name: 'count', label: label('columns', 'Columns'), items: colItems },
            { type: 'selectbox', name: 'bp', label: label('breakpoint', 'Responsive breakpoint'), items: bpItems }
        ];

        if (fw.container) {
            items.push({ type: 'checkbox', name: 'container', label: label('container', 'Wrap in container') });
        }
        items.push({ type: 'textarea', name: 'contents', label: label('content', 'Column content (one line per column)') });

        var dialog = {
            title: label('title', 'Insert Grid / Columns'),
            size: 'medium',
            body: { type: 'panel', items: items },
            // Without initialData a listbox falls back to its first item, which
            // would silently insert a single column grid. 2 columns matches the
            // `|| 2` fallback used in onSubmit().
            initialData: { count: '2' },
            buttons: [
                { type: 'cancel', text: label('cancel', 'Cancel') },
                { type: 'submit', text: label('insert', 'Insert'), primary: true }
            ],
            onSubmit: function (api) {
                var data = api.getData();
                var count = parseInt(data.count, 10) || 2;
                var bp = data.bp || '';
                var lines = String(data.contents || '').split('\n');
                var localFw = Object.assign({}, fw);

                if ('container' in data) {
                    localFw.container = data.container ? fw.container : '';
                } else {
                    localFw.container = '';
                }

                editor.insertContent(buildGrid(localFw, count, bp, lines));
                api.close();
            }
        };

        // Guard against an unsupported dialog spec: TinyMCE would otherwise fail
        // silently and the button would look "dead".
        try {
            editor.windowManager.open(dialog);
        } catch (err) {
            if (window.console && console.error) {
                console.error('[modo_grid] Could not open the grid dialog:', err);
            }
            editor.notificationManager.open({
                text: label('error', 'Could not open the grid dialog. Check the browser console for details.'),
                type: 'error'
            });
        }
    }

    function registerButton(editor) {
        editor.ui.registry.addButton('modo_grid', {
            icon: 'table',
            tooltip: label('title', 'Insert Grid / Columns'),
            onAction: function () {
                openBuilder(editor);
            }
        });
    }

    /**
     * Exposes the framework aware builder so other editors (Editor.js grid
     * tool, admin/assets/js/editorjs-grid-tool.js) produce identical markup.
     */
    window.ModoGrid = {
        config: getConfig,
        label: label,
        esc: esc,
        width12: width12,
        buildColumn: buildColumn,
        buildGrid: buildGrid,
        openBuilder: openBuilder
    };

    /**
     * Patches tinymce.init so every editor on the page gets the button,
     * regardless of how/when the editor is created.
     */
    function patch() {
        if (!window.tinymce || typeof window.tinymce.init !== 'function' || window.tinymce.__modoGridPatched) {
            return !!window.tinymce;
        }
        window.tinymce.__modoGridPatched = true;

        var orig = window.tinymce.init;
        window.tinymce.init = function (conf) {
            var c = conf || {};

            if (typeof c.toolbar === 'string' && c.toolbar.indexOf('modo_grid') === -1) {
                c.toolbar = c.toolbar + ' | modo_grid';
            } else if (Array.isArray(c.toolbar)) {
                // TinyMCE 6 also accepts a grouped toolbar definition:
                // [{ name: 'group', items: ['bold', ...] }, ...]
                var hasGrid = c.toolbar.some(function (group) {
                    if (typeof group === 'string') {
                        return group.indexOf('modo_grid') !== -1;
                    }
                    return !!group && Array.isArray(group.items) && group.items.indexOf('modo_grid') !== -1;
                });
                if (!hasGrid) {
                    c.toolbar = c.toolbar.concat([{ name: 'modo_grid', items: ['modo_grid'] }]);
                }
            }

            var origSetup = c.setup;
            c.setup = function (editor) {
                if (typeof origSetup === 'function') {
                    origSetup(editor);
                }
                registerButton(editor);
            };

            return orig.apply(this, arguments);
        };
        return true;
    }

    if (!patch()) {
        var tries = 0;
        var iv = setInterval(function () {
            tries++;
            if (patch() || tries > 60) {
                clearInterval(iv);
            }
        }, 100);
    }
})();
