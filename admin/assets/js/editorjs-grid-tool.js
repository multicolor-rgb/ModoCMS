/* ==========================================================================
   Modo CMS — "Grid / Columns" block tool for Editor.js
   --------------------------------------------------------------------------
   Renders exactly the markup produced by the TinyMCE button: the builder itself
   lives in admin/assets/js/grid-editor.js and is exposed as window.ModoGrid, so
   both editors share one implementation. The active framework, its breakpoints
   and all labels come from the server through window.MODO_GRID.
   ========================================================================== */
(function () {
    'use strict';

    var COLUMN_CHOICES = [1, 2, 3, 4, 6, 12];

    function gridConfig() {
        return (window.ModoGrid && typeof window.ModoGrid.config === 'function')
            ? window.ModoGrid.config()
            : null;
    }

    function label(key, fallback) {
        var cfg = gridConfig();
        return (cfg && cfg.labels && cfg.labels[key]) ? cfg.labels[key] : fallback;
    }

    /** Escapes a string for safe HTML interpolation. */
    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /** Builds the preview markup through the shared (TinyMCE) builder. */
    function buildMarkup(data) {
        var cfg = gridConfig();
        if (!cfg || cfg.mode === 'none' || cfg.mode === 'custom') {
            return '';
        }

        var columns = parseFloat(data.columns) > 0 ? parseFloat(data.columns) : 2;
        var lines = String(data.contentsText == null ? '' : data.contentsText).split('\n');
        var framework = {
            mode: cfg.mode,
            container: data.container ? cfg.container : ''
        };

        return window.ModoGrid.buildGrid(framework, columns, data.breakpoint || '', lines);
    }

    /** Small modal mirroring the TinyMCE grid dialog. */
    function openBuilder(data, onSave) {
        var cfg = gridConfig();
        if (!cfg) {
            return;
        }

        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(11,15,25,.8);z-index:100000;'
            + 'display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px);padding:20px;';

        var box = document.createElement('div');
        box.style.cssText = 'width:100%;max-width:520px;background:var(--bg-card,#111827);'
            + 'border:1px solid var(--border-subtle);border-radius:var(--radius-md,10px);padding:20px;'
            + 'display:flex;flex-direction:column;gap:14px;';

        function optionList(values, selected) {
            return values.map(function (value) {
                var text = value === '' ? '\u2014 (all sizes)' : String(value);
                return '<option value="' + esc(value) + '"'
                    + (String(selected) === String(value) ? ' selected' : '') + '>' + esc(text) + '</option>';
            }).join('');
        }

        var breakpoints = [''].concat(cfg.breakpoints || []);
        var containerField = cfg.container
            ? '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-main);">'
                + '<input type="checkbox" data-field="container"' + (data.container ? ' checked' : '') + '> '
                + esc(label('container', 'Wrap in container')) + '</label>'
            : '';

        box.innerHTML = '<h3 style="margin:0;font-size:15px;font-weight:700;color:var(--text-main);">'
                + esc(label('title', 'Insert Grid / Columns')) + '</h3>'
            + '<div style="display:flex;flex-direction:column;gap:6px;">'
            +   '<label style="font-size:12px;color:var(--text-muted);">' + esc(label('columns', 'Columns')) + '</label>'
            +   '<select data-field="columns" class="form-control">' + optionList(COLUMN_CHOICES, data.columns || 2) + '</select>'
            + '</div>'
            + '<div style="display:flex;flex-direction:column;gap:6px;">'
            +   '<label style="font-size:12px;color:var(--text-muted);">' + esc(label('breakpoint', 'Responsive breakpoint')) + '</label>'
            +   '<select data-field="breakpoint" class="form-control">' + optionList(breakpoints, data.breakpoint || '') + '</select>'
            + '</div>'
            + containerField
            + '<div style="display:flex;flex-direction:column;gap:6px;">'
            +   '<label style="font-size:12px;color:var(--text-muted);">' + esc(label('content', 'Column content (one line per column)')) + '</label>'
            +   '<textarea data-field="contentsText" class="form-control" rows="4">' + esc(data.contentsText || '') + '</textarea>'
            + '</div>'
            + '<div style="display:flex;justify-content:flex-end;gap:8px;">'
            +   '<button type="button" class="btn btn-secondary" data-cancel>' + esc(label('cancel', 'Cancel')) + '</button>'
            +   '<button type="button" class="btn btn-primary" data-submit>' + esc(label('insert', 'Insert')) + '</button>'
            + '</div>';

        function field(name) {
            return box.querySelector('[data-field="' + name + '"]');
        }
        function close() {
            document.removeEventListener('keydown', onKey);
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
        }
        function onKey(event) {
            if (event.key === 'Escape') { close(); }
        }

        box.querySelector('[data-cancel]').addEventListener('click', close);
        box.querySelector('[data-submit]').addEventListener('click', function () {
            var containerInput = field('container');
            var next = {
                framework: cfg.id,
                columns: parseInt(field('columns').value, 10) || 2,
                breakpoint: field('breakpoint').value || '',
                container: containerInput ? containerInput.checked : false,
                contentsText: field('contentsText').value
            };
            close();
            onSave(next);
        });
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) { close(); }
        });
        document.addEventListener('keydown', onKey);

        overlay.appendChild(box);
        document.body.appendChild(overlay);
    }

    /**
     * Editor.js block tool. The stored block data is
     * { framework, columns, breakpoint, container, contents[] } — the exact
     * shape consumed by Core\EditorJs::renderGrid().
     */
    class ModoGridTool {
        static get isReadOnlySupported() {
            return true;
        }

        static get toolbox() {
            return {
                title: label('title', 'Grid / Columns'),
                icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
                    + '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16"/></svg>'
            };
        }

        constructor(options) {
            var raw = options.data || {};
            this.api = options.api;
            this.readOnly = !!options.readOnly;
            this.data = {
                columns: parseInt(raw.columns, 10) || 2,
                breakpoint: raw.breakpoint || '',
                container: !!raw.container,
                contentsText: Array.isArray(raw.contents)
                    ? raw.contents.join('\n')
                    : String(raw.contentsText || '')
            };
        }

        /** Column lines as an array (one entry per column). */
        lines() {
            var text = String(this.data.contentsText || '');
            return text === '' ? [] : text.split('\n');
        }

        render() {
            this.wrapper = document.createElement('div');
            this.wrapper.style.cssText = 'padding:4px 0;';
            this.preview = document.createElement('div');
            this.preview.contentEditable = 'false';
            this.wrapper.appendChild(this.preview);
            this.refresh();
            return this.wrapper;
        }

        refresh() {
            var cfg = gridConfig();
            var markup = buildMarkup({
                columns: this.data.columns,
                breakpoint: this.data.breakpoint,
                container: this.data.container,
                contentsText: this.data.contentsText
            });

            if (!markup) {
                this.preview.innerHTML = '<div style="padding:10px;border:1px dashed var(--border-subtle);'
                    + 'border-radius:8px;font-size:12px;color:var(--text-muted);">'
                    + esc(label('none', 'No grid framework is selected in Settings.')) + '</div>';
            } else {
                this.preview.innerHTML = markup;
            }

            this.preview.setAttribute('data-framework', cfg ? cfg.id : '');
            this.wrapper.style.cursor = this.readOnly ? 'default' : 'pointer';
            this.wrapper.title = label('title', 'Grid / Columns');
        }

        save() {
            return {
                framework: (gridConfig() || {}).id || '',
                columns: this.data.columns,
                breakpoint: this.data.breakpoint,
                container: !!this.data.container,
                contents: this.lines()
            };
        }

        renderSettings() {
            var button = document.createElement('div');
            button.classList.add('ce-settings__button');
            button.style.cssText = 'display:flex;align-items:center;gap:6px;cursor:pointer;';
            button.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
                + '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16"/></svg>'
                + '<span>' + esc(label('title', 'Grid / Columns')) + '</span>';
            button.addEventListener('click', () => {
                this.openBuilder();
            });
            return button;
        }

        /** Opens the builder modal (used by the block's tunes menu). */
        openBuilder() {
            if (this.readOnly) {
                return;
            }
            var self = this;
            openBuilder({
                columns: this.data.columns,
                breakpoint: this.data.breakpoint,
                container: this.data.container,
                contentsText: this.data.contentsText
            }, function (next) {
                self.data = next;
                self.refresh();
            });
        }

        validate(data) {
            return parseInt(data.columns, 10) > 0;
        }
    }

    window.ModoGridTool = ModoGridTool;
})();
