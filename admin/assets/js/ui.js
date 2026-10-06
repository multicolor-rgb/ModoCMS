/**
 * Modo CMS - Global UI helpers
 * ---------------------------------------------------------------
 * Replaces native window.alert() / window.confirm() with styled
 * modals that match the Media Library look & feel.
 *
 * Public API:
 *   UI.confirm({ title, message, okText, cancelText, danger }) -> Promise<boolean>
 *   UI.alert({ title, message, okText, danger })               -> Promise<true>
 *   UI.toast(message, type)                                    -> void
 *
 * Declarative usage (no extra JS required):
 *   <a href="..." data-confirm data-confirm-title="..." data-confirm-message="...">...</a>
 *   <form method="post" data-confirm data-confirm-title="..."> ... </form>
 *   <button type="submit" data-confirm data-confirm-message="...">...</button>
 *
 * Supported data attributes:
 *   data-confirm                 Presence enables the confirm modal.
 *   data-confirm-title           Modal heading.
 *   data-confirm-message         Modal body text.
 *   data-confirm-ok              Confirm button label.
 *   data-confirm-cancel          Cancel button label.
 *   data-confirm-danger          Apply the red/danger styling.
 */
(function () {
    'use strict';

    var DEFAULTS = {
        title: (window.MODO_UI && window.MODO_UI.areYouSure) || 'Are you sure?',
        message: '',
        okText: (window.MODO_UI && window.MODO_UI.confirm) || 'Confirm',
        cancelText: (window.MODO_UI && window.MODO_UI.cancel) || 'Cancel',
        danger: false
    };

    var ICONS = {
        danger: '<path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
        info: '<path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    };

    var activeOverlay = null;

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Core modal builder. Returns a Promise resolving to a boolean.
     */
    function openModal(options) {
        var opts = Object.assign({}, DEFAULTS, options || {});

        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'ui-overlay';

            var iconPath = opts.danger ? ICONS.danger : ICONS.info;
            var modalClass = 'ui-modal' + (opts.danger ? ' ui-modal-danger' : '');

            var showCancel = opts.cancel !== false;
            var cancelHtml = showCancel
                ? '<button type="button" class="btn btn-secondary ui-modal-cancel" data-ui-cancel>' + escapeHtml(opts.cancelText) + '</button>'
                : '';

            overlay.innerHTML =
                '<div class="' + modalClass + '" role="dialog" aria-modal="true" aria-labelledby="ui-modal-title">' +
                    '<div class="ui-modal-icon ' + (opts.danger ? 'ui-icon-danger' : 'ui-icon-info') + '">' +
                        '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:24px;height:24px;">' + iconPath + '</svg>' +
                    '</div>' +
                    '<h3 id="ui-modal-title" class="ui-modal-title">' + escapeHtml(opts.title) + '</h3>' +
                    (opts.message ? '<p class="ui-modal-message">' + escapeHtml(opts.message) + '</p>' : '') +
                    '<div class="ui-modal-actions">' +
                        cancelHtml +
                        '<button type="button" class="btn ' + (opts.danger ? 'btn-primary ui-btn-danger' : 'btn-primary') + ' ui-modal-ok" data-ui-ok>' + escapeHtml(opts.okText) + '</button>' +
                    '</div>' +
                '</div>';

            var previousFocus = document.activeElement;

            function close(result) {
                document.removeEventListener('keydown', onKeydown, true);
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
                if (activeOverlay === overlay) {
                    activeOverlay = null;
                }
                document.documentElement.classList.remove('ui-modal-open');
                if (previousFocus && typeof previousFocus.focus === 'function') {
                    try { previousFocus.focus(); } catch (e) { /* ignore */ }
                }
                resolve(result);
            }

            function onKeydown(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    close(false);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    close(true);
                }
            }

            overlay.querySelector('[data-ui-ok]').addEventListener('click', function () { close(true); });
            var cancelBtn = overlay.querySelector('[data-ui-cancel]');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () { close(false); });
            }
            overlay.addEventListener('mousedown', function (e) {
                if (e.target === overlay) {
                    close(false);
                }
            });

            document.body.appendChild(overlay);
            activeOverlay = overlay;
            document.documentElement.classList.add('ui-modal-open');
            document.addEventListener('keydown', onKeydown, true);

            var okBtn = overlay.querySelector('[data-ui-ok]');
            if (okBtn) okBtn.focus();
        });
    }
function showToast(message, type) {
        var toast = document.createElement('div');
        toast.className = 'ui-toast' + (type ? ' ui-toast-' + type : '');
        toast.textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('ui-toast-visible'); });
        setTimeout(function () {
            toast.classList.remove('ui-toast-visible');
            setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 250);
        }, 2600);
    }

    var UI = {
        confirm: function (options) {
            return openModal(Object.assign({}, options || {}, { cancel: true }));
        },
        alert: function (options) {
            return openModal(Object.assign({ danger: false }, options || {}, { cancel: false, okText: (options && options.okText) || (window.MODO_UI && window.MODO_UI.ok) || 'OK' }));
        },
        toast: showToast,
        escapeHtml: escapeHtml
    };

    window.UI = UI;

    // ------------------------------------------------------------------
    // Declarative handling: data-confirm on links, buttons and forms.
    // ------------------------------------------------------------------
    function dataConfirmOptions(el) {
        return {
            title: el.getAttribute('data-confirm-title') || DEFAULTS.title,
            message: el.getAttribute('data-confirm-message') || '',
            okText: el.getAttribute('data-confirm-ok') || DEFAULTS.okText,
            cancelText: el.getAttribute('data-confirm-cancel') || DEFAULTS.cancelText,
            danger: el.hasAttribute('data-confirm-danger')
        };
    }

    document.addEventListener('click', function (e) {
        // Confirm on links
        var link = e.target.closest ? e.target.closest('a[data-confirm]') : null;
        if (link) {
            e.preventDefault();
            UI.confirm(dataConfirmOptions(link)).then(function (ok) {
                if (ok) {
                    window.location.href = link.getAttribute('href');
                }
            });
            return;
        }

        // Confirm on submit buttons (preserve name/value via requestSubmit)
        var btn = e.target.closest ? e.target.closest('button[data-confirm]') : null;
        if (btn) {
            var form = btn.form;
            if (!form) return;
            e.preventDefault();
            UI.confirm(dataConfirmOptions(btn)).then(function (ok) {
                if (!ok) return;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(btn);
                } else {
                    form.submit();
                }
            });
        }
    }, true);

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.matches && form.matches('form[data-confirm]') && !form.dataset.uiConfirmed) {
            e.preventDefault();
            UI.confirm(dataConfirmOptions(form)).then(function (ok) {
                if (ok) {
                    form.dataset.uiConfirmed = '1';
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
            });
        }
    }, true);
})();