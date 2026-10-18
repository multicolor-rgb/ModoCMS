/* ==========================================================================
   Modo CMS — Editor.js bootstrap
   --------------------------------------------------------------------------
   Turns the hidden <textarea name="content"> into an Editor.js canvas.

   * Every bundle is loaded locally from admin/assets/vendor/editorjs.
   * On submit (and on autosave) the document is serialized back to JSON into
     the textarea, so the existing server side save pipeline is untouched.
   * Legacy HTML documents are converted into blocks on load, which is what
     makes switching the editor per installation non destructive.
   * Plugin buttons are collected from the editor bridge
     (admin/assets/js/editor-bridge.js) and rendered above the canvas.
   ========================================================================== */
(function () {
    'use strict';

    var CFG = window.MODO_EDITORJS || {};
    var LABELS = CFG.labels || {};
    var bridge = window.ModoEditor || {};

    var editor = null;
    var textarea = null;
    var saveInFlight = null;

    function label(key, fallback) {
        return LABELS[key] || fallback;
    }

    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function notice(text, danger) {
        if (typeof bridge.message === 'function') {
            bridge.message(text, danger);
        } else {
            window.alert(text);
        }
    }

    // ---------------------------------------------------------------------
    // Block tools
    // ---------------------------------------------------------------------

    /**
     * "Shortcode" block: keeps plugin shortcodes ([modo_gallery id="1"], …)
     * editable inside the canvas. Core\EditorJs::renderBlock() emits the stored
     * code verbatim, so the regular shortcode filters expand it on output.
     */
    class ModoShortcode {
        static get isReadOnlySupported() {
            return true;
        }

        static get toolbox() {
            return {
                title: label('shortcodeTitle', 'Shortcode'),
                icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
                    + '<path d="M8 6 3 12l5 6M16 6l5 6-5 6"/></svg>'
            };
        }

        constructor(options) {
            this.data = { code: String((options.data || {}).code || '') };
            this.readOnly = !!(options.readOnly);
        }

        render() {
            var wrapper = document.createElement('div');
            wrapper.style.cssText = 'padding:6px 0;';

            var field = document.createElement('textarea');
            field.className = 'form-control';
            field.rows = 2;
            field.style.cssText = 'width:100%;font-family:monospace;font-size:12px;';
            field.placeholder = '[snippet:name]';
            field.value = this.data.code;
            field.readOnly = this.readOnly;
            field.addEventListener('input', () => {
                this.data.code = field.value;
            });

            wrapper.appendChild(field);
            return wrapper;
        }

        save() {
            return { code: String(this.data.code || '').trim() };
        }
    }

    window.ModoShortcode = window.ModoShortcode || ModoShortcode;

    /** Resolves a tool entry from the server payload to a class. */
    function resolveTool(entry) {
        if (!entry || !entry.global) {
            return null;
        }
        var tool = window[entry.global];
        if (typeof tool === 'function') {
            return tool;
        }
        if (window.console && console.warn) {
            console.warn('[ModoEditor] block "' + entry.id + '" skipped: global "' + entry.global + '" is missing.');
        }
        return null;
    }

    /**
     * Some bundles (embed.umd.js) ship without a toolbox getter, which would
     * keep their block out of the "+" picker even when the tool is enabled.
     * The wrapper supplies the translated title together with an icon.
     */
    function withToolbox(entry, Base) {
        var title = entry.title || entry.id;
        return class extends Base {
            static get toolbox() {
                return {
                    title: title,
                    icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
                        + '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/>'
                        + '<path d="m10 9 5 3-5 3z"/></svg>'
                };
            }
        };
    }

    /** Resolves the inline tools (highlight / inline code). */
    function resolveInlineTool(entry) {
        var Base = resolveTool(entry);
        if (!Base) {
            return null;
        }
        var title = entry.title || entry.id;
        // The inline toolbar shows the tool's own title, so give it the
        // translated one from the settings.
        return [entry.id, {
            class: class extends Base {
                static get title() {
                    return title;
                }
            }
        }];
    }

    // ---------------------------------------------------------------------
    // Legacy HTML -> blocks
    // ---------------------------------------------------------------------

    /**
     * Converts stored HTML (TinyMCE) into a block list. Unknown markup ends up
     * in a Raw HTML block, so nothing is lost when the editor is switched.
     */
    function htmlToBlocks(html) {
        var holder = document.createElement('div');
        holder.innerHTML = String(html || '');

        var blocks = [];
        Array.prototype.forEach.call(holder.childNodes, function (node) {
            if (node.nodeType === 3) {
                var text = String(node.textContent || '').trim();
                if (text !== '') {
                    blocks.push({ type: 'paragraph', data: { text: esc(text) } });
                }
                return;
            }
            if (node.nodeType !== 1) {
                return;
            }
            blockFromElement(node, blocks);
        });

        return blocks;
    }

    function blockFromElement(el, blocks) {
        var tag = el.tagName ? el.tagName.toLowerCase() : '';
        var text = String(el.innerHTML || '').trim();

        if (/^h[1-6]$/.test(tag)) {
            if (text !== '') {
                blocks.push({ type: 'header', data: { text: text, level: parseInt(tag.charAt(1), 10) } });
            }
            return;
        }
        if (tag === 'p') {
            if (String(el.textContent || '').trim() !== '') {
                blocks.push({ type: 'paragraph', data: { text: text } });
            }
            return;
        }
        if (tag === 'ul' || tag === 'ol') {
            var items = [];
            Array.prototype.forEach.call(el.children, function (li) {
                var item = String(li.innerHTML || '').trim();
                if (item !== '') {
                    items.push(item);
                }
            });
            if (items.length) {
                blocks.push({ type: 'list', data: { style: tag === 'ol' ? 'ordered' : 'unordered', items: items } });
            }
            return;
        }
        if (tag === 'blockquote') {
            var cite = el.querySelector('cite');
            blocks.push({
                type: 'quote',
                data: {
                    text: String((cite ? el.textContent.replace(cite.textContent, '') : el.textContent) || '').trim() || text,
                    caption: cite ? String(cite.textContent || '').trim() : ''
                }
            });
            return;
        }
        if (tag === 'hr') {
            blocks.push({ type: 'delimiter', data: {} });
            return;
        }
        if (tag === 'pre') {
            blocks.push({ type: 'code', data: { code: String(el.textContent || '') } });
            return;
        }
        if (tag === 'img') {
            blocks.push({ type: 'image', data: { file: { url: el.getAttribute('src') || '' }, caption: el.getAttribute('alt') || '' } });
            return;
        }
        if (tag === 'figure') {
            var img = el.querySelector('img');
            if (img) {
                var caption = el.querySelector('figcaption');
                blocks.push({
                    type: 'image',
                    data: {
                        file: { url: img.getAttribute('src') || '' },
                        caption: caption ? String(caption.textContent || '').trim() : ''
                    }
                });
                return;
            }
        }
        if (tag === 'table') {
            var rows = [];
            var hasHeadings = false;
            Array.prototype.forEach.call(el.querySelectorAll('tr'), function (tr, index) {
                var cells = [];
                Array.prototype.forEach.call(tr.children, function (cell) {
                    cells.push(String(cell.innerHTML || '').trim());
                    if (cell.tagName && cell.tagName.toLowerCase() === 'th') {
                        hasHeadings = true;
                    }
                });
                if (cells.length) {
                    rows.push(cells);
                }
                if (index === 0 && tr.querySelector('th')) {
                    hasHeadings = true;
                }
            });
            if (rows.length) {
                blocks.push({ type: 'table', data: { withHeadings: hasHeadings, content: rows } });
            }
            return;
        }
        if (tag === 'div' || tag === 'section' || tag === 'article' || tag === 'aside') {
            // Shortcode-only wrappers become shortcode blocks, everything else
            // is preserved verbatim as raw HTML.
            var only = String(el.innerHTML || '').trim();
            if (/^\[[a-z0-9_\-]+[^\]]*\]$/i.test(only)) {
                blocks.push({ type: 'shortcode', data: { code: only } });
                return;
            }
        }

        if (text !== '') {
            blocks.push({ type: 'raw', data: { html: el.outerHTML } });
        }
    }

    // ---------------------------------------------------------------------
    // Editor bootstrap
    // ---------------------------------------------------------------------

    /** Assembles the tools config from the server payload. */
    function buildTools() {
        var tools = {};

        (CFG.tools || []).forEach(function (entry) {
            var Klass = resolveTool(entry);
            if (!Klass) {
                return;
            }
            if (entry.toolbox === false) {
                Klass = withToolbox(entry, Klass);
            }
            tools[entry.id] = { class: Klass, inlineToolbar: true };
        });

        (CFG.inlineTools || []).forEach(function (entry) {
            var resolved = resolveInlineTool(entry);
            if (resolved) {
                tools[resolved[0]] = resolved[1];
            }
        });

        if (CFG.gridTool && window.ModoGridTool) {
            tools.grid = { class: window.ModoGridTool, inlineToolbar: false };
        }

        // Placeholders and hints the tool bundles only accept through `config`.
        Object.keys(CFG.toolConfig || {}).forEach(function (id) {
            if (!tools[id]) {
                return;
            }
            tools[id].config = Object.assign({}, tools[id].config, CFG.toolConfig[id]);
        });

        // The image tool uploads through the regular admin upload endpoint and
        // translates its {location} answer into the shape Editor.js expects.
        if (tools.image && CFG.uploadUrl) {
            tools.image.config = Object.assign({}, tools.image.config, {
                uploader: {
                    uploadByFile: function (file) {
                        var body = new FormData();
                        body.append('file', file);
                        body.append('editorjs', '1');

                        return fetch(CFG.uploadUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                            .then(function (response) { return response.json(); })
                            .then(function (data) {
                                if (data && data.location) {
                                    return { success: 1, file: { url: data.location } };
                                }
                                return { success: 0, message: (data && data.error) || label('imageFailed', 'Could not upload the file.') };
                            })
                            .catch(function () {
                                return { success: 0, message: label('imageFailed', 'Could not upload the file.') };
                            });
                    }
                }
            });
        }

        return tools;
    }

    /** API handed to every bridged toolbar button. */
    function createApi() {
        return {
            editor: editor,
            insertBlock: function (type, data) {
                return editor.blocks.insert(type, data || {}, {}, undefined, true);
            },
            insertHtml: function (html) {
                if (editor.blocks.getBlockTools().some(function (tool) { return tool.name === 'raw'; })) {
                    return editor.blocks.insert('raw', { html: html }, {}, undefined, true);
                }
                // Without the Raw HTML block the markup degrades to plain text.
                return editor.blocks.insert('paragraph', { text: esc(html) }, {}, undefined, true);
            },
            insertShortcode: function (code) {
                var hasShortcode = editor.blocks.getBlockTools().some(function (tool) { return tool.name === 'shortcode'; });
                return hasShortcode
                    ? editor.blocks.insert('shortcode', { code: code }, {}, undefined, true)
                    : this.insertHtml(code);
            },
            pickFromList: function (options, onPick) {
                if (typeof bridge.pickFromList === 'function') {
                    bridge.pickFromList(options, onPick);
                }
            },
            openMediaPicker: function (callback) {
                if (typeof window.ModoOpenMediaPicker === 'function') {
                    window.ModoOpenMediaPicker(callback);
                } else {
                    notice(label('noMediaLibrary', 'The media library is not available on this screen.'), true);
                }
            },
            message: function (text, danger) {
                notice(text, danger);
            }
        };
    }

    /** Mounts the plugin buttons collected by the editor bridge. */
    function mountToolbar() {
        if (typeof bridge.mountToolbar !== 'function') {
            return;
        }
        var bar = document.getElementById(CFG.toolbarId || 'editorjs-toolbar');
        if (bar) {
            bridge.mountToolbar(bar, createApi());
        }
    }

    /** Serializes the document back into the hidden textarea. */
    function serialize() {
        if (!editor) {
            return Promise.resolve('');
        }
        if (saveInFlight) {
            return saveInFlight;
        }

        saveInFlight = editor.save()
            .then(function (data) {
                var hasBlocks = !!(data && data.blocks && data.blocks.length);
                var json = hasBlocks ? JSON.stringify(data) : '';
                if (textarea && textarea.value !== json) {
                    textarea.value = json;
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                }
                return json;
            })
            .catch(function (err) {
                if (window.console && console.error) {
                    console.error('[ModoEditor] save failed:', err);
                }
                return '';
            })
            .then(function (json) {
                saveInFlight = null;
                return json;
            });

        return saveInFlight;
    }

    /** Loads the stored value: Editor.js JSON or legacy HTML. */
    function restore() {
        var raw = textarea ? String(textarea.value || '').trim() : '';
        if (raw === '') {
            return Promise.resolve();
        }

        if (raw.charAt(0) === '{') {
            try {
                var stored = JSON.parse(raw);
                if (stored && Array.isArray(stored.blocks) && stored.blocks.length) {
                    return editor.blocks.render(stored);
                }
                return Promise.resolve();
            } catch (err) {
                if (window.console && console.warn) {
                    console.warn('[ModoEditor] stored JSON is invalid, falling back to HTML parsing.');
                }
            }
        }

        var blocks = htmlToBlocks(raw);
        if (!blocks.length) {
            return Promise.resolve();
        }

        return editor.blocks.render({
            time: Date.now(),
            version: '2.31.7',
            blocks: blocks
        });
    }

    /** Registers the "insert from media library" button for the block editor. */
    function registerMediaButton() {
        if (typeof bridge.registerButton !== 'function') {
            return;
        }
        // Screens without a media library (e.g. snippets) simply skip it.
        if (typeof window.ModoOpenMediaPicker !== 'function') {
            return;
        }
        bridge.registerButton({
            id: 'modo-media',
            label: label('imageLibrary', 'Media library'),
            icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
                + '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>'
                + '<path d="m21 15-5-5L5 21"/></svg>',
            insert: function (api) {
                api.openMediaPicker(function (url, alt) {
                    if (!url) {
                        return;
                    }
                    var hasImageTool = api.editor.blocks.getBlockTools().some(function (tool) { return tool.name === 'image'; });
                    if (hasImageTool) {
                        api.insertBlock('image', { file: { url: url }, caption: alt || '' });
                        return;
                    }
                    api.insertHtml('<img src="' + esc(url) + '" alt="' + esc(alt || '') + '">');
                });
            }
        });
    }

    /** Registers a shortcut that inserts an empty grid block. */
    function registerGridButton() {
        if (!CFG.gridTool || !window.ModoGridTool || typeof bridge.registerButton !== 'function') {
            return;
        }
        var gridCfg = window.MODO_GRID || {};
        bridge.registerButton({
            id: 'modo-grid',
            label: label('gridTitle', 'Grid / Columns'),
            icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
                + '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16"/></svg>',
            insert: function (api) {
                api.insertBlock('grid', {
                    framework: gridCfg.id || '',
                    columns: 2,
                    breakpoint: '',
                    container: false,
                    contents: []
                });
            }
        });
    }

    /** Keeps the textarea in sync before the form is submitted. */
    function hookForm() {
        if (!textarea) {
            return;
        }
        var form = textarea.closest('form');
        if (!form) {
            return;
        }

        var submitting = false;
        form.addEventListener('submit', function (event) {
            if (submitting || !ready) {
                return;
            }
            event.preventDefault();
            serialize().then(function () {
                submitting = true;
                // requestSubmit() re-runs the other submit listeners (e.g. the
                // custom field commit handler), form.submit() would not.
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });
    }

    // ---------------------------------------------------------------------
    // Start
    // ---------------------------------------------------------------------

    var ready = false;

    function start() {
        textarea = document.getElementById(CFG.fieldId || 'editor');
        var holder = document.getElementById(CFG.holderId || 'editorjs');
        if (!holder) {
            return;
        }
        if (typeof window.EditorJS !== 'function') {
            if (window.console && console.error) {
                console.error('[ModoEditor] editorjs.umd.js did not export window.EditorJS.');
            }
            // Last resort: keep the field editable instead of showing a dead form.
            notice(label('editorFailed', 'The block editor bundle could not be loaded. The content field stays available in raw mode.'), true);
            if (textarea) {
                textarea.style.display = 'block';
                textarea.rows = 18;
            }
            return;
        }

        var tools = buildTools();
        if (!Object.keys(tools).length) {
            notice(label('noBlocks', 'No Editor.js block is available - check Settings → Content.'), true);
            return;
        }

        // All Editor.js UI strings (tool names, block tunes, popovers, tool
        // dialogs) come translated from Core\EditorJs::i18n(). Keys that are
        // missing anywhere fall back to English inside Editor.js.
        var config = {
            holder: holder,
            tools: tools,
            placeholder: label('placeholder', 'Start writing, or press Tab to pick a block...'),
            minHeight: 420,
            autofocus: true,
            onChange: function () {
                if (ready) {
                    serialize();
                }
            }
        };
        if (CFG.i18n && CFG.i18n.messages) {
            config.i18n = CFG.i18n;
        }

        editor = new window.EditorJS(config);

        editor.isReady
            .then(function () { return restore(); })
            .then(function () {
                ready = true;
                registerMediaButton();
                registerGridButton();
                mountToolbar();
                return serialize();
            })
            .then(function () {
                window.ModoEditorJs = {
                    editor: editor,
                    serialize: serialize,
                    insertBlock: function (type, data) {
                        return editor.blocks.insert(type, data || {}, {}, undefined, true);
                    }
                };
                document.dispatchEvent(new CustomEvent('modo-editor-ready', { detail: window.ModoEditorJs }));
            })
            .catch(function (err) {
                if (window.console && console.error) {
                    console.error('[ModoEditor] initialisation failed:', err);
                }
                notice(label('editorStartFailed', 'The block editor could not be started:') + ' '
                    + (err && err.message ? err.message : 'unknown error'), true);
            });

        hookForm();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
