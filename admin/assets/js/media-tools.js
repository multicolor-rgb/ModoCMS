/**
 * Modo CMS - Media tools
 * ---------------------------------------------------------------
 * Local, dependency-free lightbox and canvas image editor used by the
 * admin media library. All public entry points are exposed on window so the
 * existing inline onclick handlers in media.php keep working.
 *
 * Public API:
 *   openMediaLightbox(index), closeMediaLightbox(), lightboxPrev(),
 *   lightboxNext(), lightboxZoom(direction), lightboxResetZoom(),
 *   lightboxEdit(), lightboxCopy()
 *   openImageEditor(url, name, folder, path, mime), closeImageEditor(),
 *   editorRotate(deg), editorFlip(axis), editorReset(), editorSetRatio(value),
 *   saveEditedImage(mode)
 */
(function () {
    'use strict';

    var cfg = window.MEDIA_TOOLS_CFG || {};
    var items = Array.isArray(cfg.items) ? cfg.items : [];
    var i18n = cfg.i18n || {};
    var transformUrl = cfg.transformUrl || 'media-transform.php';
    var csrf = cfg.csrf || '';

    function t(key, fallback) {
        return i18n[key] || fallback || key;
    }

    function toast(message, type) {
        if (window.UI && typeof window.UI.toast === 'function') {
            window.UI.toast(message, type || 'success');
        }
    }

    function confirmDialog(options) {
        if (window.UI && typeof window.UI.confirm === 'function') {
            return window.UI.confirm(options);
        }
        return Promise.resolve(window.confirm(options.message || options.title || ''));
    }

    function alertDialog(options) {
        if (window.UI && typeof window.UI.alert === 'function') {
            return window.UI.alert(options);
        }
        window.alert(options.message || options.title || '');
        return Promise.resolve(true);
    }

    function formatBytes(bytes) {
        var value = Number(bytes) || 0;
        if (value < 1024) {
            return value + ' B';
        }
        if (value < 1048576) {
            return (value / 1024).toFixed(value < 10240 ? 1 : 0) + ' KB';
        }
        return (value / 1048576).toFixed(1) + ' MB';
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            try {
                var textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                var ok = document.execCommand('copy');
                document.body.removeChild(textarea);
                if (ok) {
                    resolve();
                } else {
                    reject(new Error('copy failed'));
                }
            } catch (error) {
                reject(error);
            }
        });
    }


    function syncModalLock() {
        var locked = lightboxState.open || editorState.open;
        if (locked) {
            document.documentElement.classList.add('media-modal-open');
        } else {
            document.documentElement.classList.remove('media-modal-open');
        }
    }

    // =======================================================================
    // Lightbox
    // =======================================================================
    var lightboxBuilt = false;
    var lightboxRoot = null;
    var lightboxImage = null;
    var lightboxCounter = null;
    var lightboxName = null;
    var lightboxSub = null;
    var lightboxError = null;
    var lightboxZoomLabel = null;
    var lightboxPrevBtn = null;
    var lightboxNextBtn = null;
    var lightboxState = {
        open: false,
        index: 0,
        zoom: 1,
        panX: 0,
        panY: 0,
        dragging: false,
        startX: 0,
        startY: 0,
        baseX: 0,
        baseY: 0,
        trigger: null
    };

    function buildLightbox() {
        if (lightboxBuilt) {
            return;
        }
        lightboxBuilt = true;

        lightboxRoot = document.createElement('div');
        lightboxRoot.className = 'media-lightbox';
        lightboxRoot.style.display = 'none';
        lightboxRoot.setAttribute('role', 'dialog');
        lightboxRoot.setAttribute('aria-modal', 'true');
        lightboxRoot.innerHTML =
            '<div class="media-lightbox-backdrop" data-lb-close></div>' +
            '<button type="button" class="media-lightbox-close" data-lb-close>&times;</button>' +
            '<button type="button" class="media-lightbox-nav media-lightbox-prev">&#8249;</button>' +
            '<div class="media-lightbox-stage">' +
                '<img class="media-lightbox-image" alt="" draggable="false">' +
                '<div class="media-lightbox-error" role="alert"></div>' +
            '</div>' +
            '<button type="button" class="media-lightbox-nav media-lightbox-next">&#8250;</button>' +
            '<div class="media-lightbox-counter"></div>' +
            '<div class="media-lightbox-bar">' +
                '<div class="media-lightbox-info">' +
                    '<strong class="media-lightbox-name"></strong>' +
                    '<span class="media-lightbox-sub"></span>' +
                '</div>' +
                '<div class="media-lightbox-actions">' +
                    '<button type="button" class="media-lightbox-btn" data-action="zoom-out">&minus;</button>' +
                    '<span class="media-lightbox-zoom-label">100%</span>' +
                    '<button type="button" class="media-lightbox-btn" data-action="zoom-in">+</button>' +
                    '<button type="button" class="media-lightbox-btn" data-action="reset-zoom"></button>' +
                    '<button type="button" class="media-lightbox-btn media-lightbox-primary" data-action="edit"></button>' +
                    '<button type="button" class="media-lightbox-btn" data-action="copy"></button>' +
                    '<button type="button" class="media-lightbox-btn" data-action="original"></button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(lightboxRoot);

        lightboxImage = lightboxRoot.querySelector('.media-lightbox-image');
        lightboxCounter = lightboxRoot.querySelector('.media-lightbox-counter');
        lightboxName = lightboxRoot.querySelector('.media-lightbox-name');
        lightboxSub = lightboxRoot.querySelector('.media-lightbox-sub');
        lightboxError = lightboxRoot.querySelector('.media-lightbox-error');
        lightboxZoomLabel = lightboxRoot.querySelector('.media-lightbox-zoom-label');
        lightboxPrevBtn = lightboxRoot.querySelector('.media-lightbox-prev');
        lightboxNextBtn = lightboxRoot.querySelector('.media-lightbox-next');

        var closeBtn = lightboxRoot.querySelector('.media-lightbox-close');
        closeBtn.setAttribute('aria-label', t('close', 'Close'));
        lightboxPrevBtn.setAttribute('aria-label', t('previousImage', 'Previous image'));
        lightboxNextBtn.setAttribute('aria-label', t('nextImage', 'Next image'));

        var zoomOutButton = lightboxRoot.querySelector('[data-action="zoom-out"]');
        var zoomInButton = lightboxRoot.querySelector('[data-action="zoom-in"]');
        zoomOutButton.title = t('zoomOut', 'Zoom out');
        zoomOutButton.setAttribute('aria-label', t('zoomOut', 'Zoom out'));
        zoomInButton.title = t('zoomIn', 'Zoom in');
        zoomInButton.setAttribute('aria-label', t('zoomIn', 'Zoom in'));

        lightboxRoot.querySelector('[data-action="reset-zoom"]').textContent = t('resetZoom', 'Reset zoom');
        lightboxRoot.querySelector('[data-action="edit"]').textContent = t('edit', 'Edit');
        lightboxRoot.querySelector('[data-action="copy"]').textContent = t('copyUrl', 'Copy URL');
        lightboxRoot.querySelector('[data-action="original"]').textContent = t('openOriginal', 'Open original');

        lightboxRoot.querySelectorAll('[data-lb-close]').forEach(function (element) {
            element.addEventListener('click', function () {
                closeLightbox(true);
            });
        });

        lightboxPrevBtn.addEventListener('click', lightboxPrev);
        lightboxNextBtn.addEventListener('click', lightboxNext);
        lightboxRoot.querySelector('[data-action="zoom-out"]').addEventListener('click', function () {
            lightboxZoom(-1);
        });
        lightboxRoot.querySelector('[data-action="zoom-in"]').addEventListener('click', function () {
            lightboxZoom(1);
        });
        lightboxRoot.querySelector('[data-action="reset-zoom"]').addEventListener('click', lightboxResetZoom);
        lightboxRoot.querySelector('[data-action="edit"]').addEventListener('click', lightboxEdit);
        lightboxRoot.querySelector('[data-action="copy"]').addEventListener('click', lightboxCopy);
        lightboxRoot.querySelector('[data-action="original"]').addEventListener('click', function () {
            var item = items[lightboxState.index];
            if (item && item.url) {
                window.open(item.url, '_blank', 'noopener');
            }
        });

        lightboxImage.addEventListener('load', function () {
            lightboxError.style.display = 'none';
            lightboxImage.style.visibility = 'visible';
        });
        lightboxImage.addEventListener('error', function () {
            lightboxError.textContent = t('couldNotLoad', 'Could not load the image.');
            lightboxError.style.display = 'block';
            lightboxImage.style.visibility = 'hidden';
        });

        lightboxRoot.querySelector('.media-lightbox-stage').addEventListener('wheel', function (event) {
            if (!lightboxState.open) {
                return;
            }
            event.preventDefault();
            lightboxZoom(event.deltaY < 0 ? 1 : -1);
        }, { passive: false });

        lightboxImage.addEventListener('dblclick', function () {
            setLightboxZoom(lightboxState.zoom > 1 ? 1 : 2.5);
        });

        lightboxImage.addEventListener('pointerdown', function (event) {
            if (!lightboxState.open || lightboxState.zoom <= 1) {
                return;
            }
            event.preventDefault();
            lightboxState.dragging = true;
            lightboxState.startX = event.clientX;
            lightboxState.startY = event.clientY;
            lightboxState.baseX = lightboxState.panX;
            lightboxState.baseY = lightboxState.panY;
            lightboxImage.classList.add('is-dragging');
            try {
                lightboxImage.setPointerCapture(event.pointerId);
            } catch (error) {
                // Pointer capture is a progressive enhancement.
            }
        });

        lightboxImage.addEventListener('pointermove', function (event) {
            if (!lightboxState.dragging) {
                return;
            }
            lightboxState.panX = lightboxState.baseX + (event.clientX - lightboxState.startX);
            lightboxState.panY = lightboxState.baseY + (event.clientY - lightboxState.startY);
            applyLightboxTransform();
        });

        function endDrag(event) {
            if (!lightboxState.dragging) {
                return;
            }
            lightboxState.dragging = false;
            lightboxImage.classList.remove('is-dragging');
            try {
                lightboxImage.releasePointerCapture(event.pointerId);
            } catch (error) {
                // Ignore stale pointer ids.
            }
        }

        lightboxImage.addEventListener('pointerup', endDrag);
        lightboxImage.addEventListener('pointercancel', endDrag);
    }

    function applyLightboxTransform() {
        if (!lightboxImage) {
            return;
        }
        lightboxImage.style.transform = 'translate(' + lightboxState.panX + 'px, '
            + lightboxState.panY + 'px) scale(' + lightboxState.zoom + ')';
        lightboxImage.style.cursor = lightboxState.zoom > 1
            ? (lightboxState.dragging ? 'grabbing' : 'grab')
            : 'zoom-in';
        if (lightboxZoomLabel) {
            lightboxZoomLabel.textContent = Math.round(lightboxState.zoom * 100) + '%';
        }
    }

    function setLightboxZoom(value) {
        lightboxState.zoom = Math.max(1, Math.min(6, value));
        if (lightboxState.zoom === 1) {
            lightboxState.panX = 0;
            lightboxState.panY = 0;
        }
        applyLightboxTransform();
    }

    function showLightboxItem(index) {
        if (!items.length) {
            return;
        }

        var count = items.length;
        index = ((Number(index) || 0) % count + count) % count;
        lightboxState.index = index;
        var item = items[index] || {};

        lightboxState.zoom = 1;
        lightboxState.panX = 0;
        lightboxState.panY = 0;
        applyLightboxTransform();

        lightboxError.style.display = 'none';
        lightboxError.textContent = '';
        lightboxImage.style.visibility = 'visible';
        lightboxImage.alt = item.name || t('imagePreview', 'Image preview');
        lightboxImage.src = item.url || '';

        lightboxCounter.textContent = (index + 1) + ' / ' + count;
        lightboxName.textContent = item.name || '';
        var folder = item.folder ? item.folder : t('root', 'Root');
        lightboxSub.textContent = folder + ' \u00b7 ' + formatBytes(item.size);

        var single = count < 2;
        lightboxPrevBtn.disabled = single;
        lightboxNextBtn.disabled = single;
    }

    function openMediaLightbox(index) {
        if (!items.length) {
            return;
        }
        buildLightbox();

        lightboxState.trigger = document.activeElement;
        lightboxState.open = true;
        lightboxRoot.style.display = 'flex';
        syncModalLock();
        showLightboxItem(index);

        var closeBtn = lightboxRoot.querySelector('.media-lightbox-close');
        if (closeBtn) {
            closeBtn.focus();
        }
    }

    function closeLightbox(restoreFocus) {
        if (!lightboxBuilt || !lightboxState.open) {
            return;
        }
        lightboxState.open = false;
        lightboxState.dragging = false;
        lightboxRoot.style.display = 'none';
        lightboxImage.removeAttribute('src');
        syncModalLock();

        if (restoreFocus !== false && lightboxState.trigger
            && typeof lightboxState.trigger.focus === 'function'
            && document.contains(lightboxState.trigger)) {
            try {
                lightboxState.trigger.focus();
            } catch (error) {
                // Ignore detached trigger elements.
            }
        }
    }

    function closeMediaLightbox() {
        closeLightbox(true);
    }

    function lightboxPrev() {
        showLightboxItem(lightboxState.index - 1);
    }

    function lightboxNext() {
        showLightboxItem(lightboxState.index + 1);
    }

    function lightboxZoom(direction) {
        if (!lightboxState.open) {
            return;
        }
        var step = Number(direction) >= 0 ? 1.25 : 0.8;
        setLightboxZoom(lightboxState.zoom * step);
    }

    function lightboxResetZoom() {
        setLightboxZoom(1);
    }

    function lightboxEdit() {
        var item = items[lightboxState.index];
        if (!item) {
            return;
        }
        var trigger = lightboxState.trigger;
        closeLightbox(false);
        openImageEditor(item.url, item.name, item.folder, item.path, item.mime, trigger);
    }

    function lightboxCopy() {
        var item = items[lightboxState.index];
        if (!item || !item.url) {
            return;
        }
        var url;
        try {
            url = new URL(item.url, window.location.href).href;
        } catch (error) {
            url = item.url;
        }

        copyToClipboard(url).then(function () {
            toast(t('linkCopied', 'Link copied to clipboard'));
        }).catch(function () {
            toast(t('linkCopied', 'Link copied to clipboard'));
        });
    }

    // =======================================================================
    // Image editor (Cropper.js)
    // =======================================================================
    var editorRoot = document.getElementById('image-editor-modal');
    var editorImage = document.getElementById('editor-image');
    var editorRatioSelect = document.getElementById('editor-ratio');
    var editorResolution = document.getElementById('canvas-resolution');
    var editorFormat = document.getElementById('editor-format');
    var editorSaveBtn = document.getElementById('editor-save');
    var editorSaveCopyBtn = document.getElementById('editor-save-copy');
    var editorCropper = null;
    var editorState = {
        open: false,
        item: null,
        trigger: null,
        saving: false,
        loadToken: 0,
        baseImage: null,
        angle: 0,
        flipX: 1,
        flipY: 1,
        previewUrl: ''
    };

    function formatLabel(mime) {
        switch (mime) {
            case 'image/jpeg': return 'JPEG';
            case 'image/png': return 'PNG';
            case 'image/webp': return 'WEBP';
            default: return 'IMAGE';
        }
    }

    function setEditorBusy(busy) {
        editorState.saving = busy;
        if (editorSaveBtn) {
            editorSaveBtn.disabled = busy;
            editorSaveBtn.textContent = busy
                ? t('saving', 'Saving...')
                : t('save', 'Save');
        }
        if (editorSaveCopyBtn) {
            editorSaveCopyBtn.disabled = busy;
        }
    }

    function updateEditorMeta() {
        if (!editorCropper || !editorResolution) return;

        var data = editorCropper.getData(true) || {};
        var width = Math.max(1, Math.round(Number(data.width) || 0));
        var height = Math.max(1, Math.round(Number(data.height) || 0));
        editorResolution.textContent = width + ' \u00d7 ' + height + ' px';

        if (editorFormat) {
            editorFormat.textContent = formatLabel(editorState.item ? editorState.item.mime : '');
        }
    }

    function destroyEditorCropper() {
        if (editorCropper) {
            try { editorCropper.destroy(); } catch (error) {}
            editorCropper = null;
        }
    }

    function initEditorCropper() {
        if (!editorImage || typeof window.Cropper === 'undefined') {
            alertDialog({
                title: t('editImage', 'Edit Image'),
                message: t('errorFailed', 'Could not save the image.'),
                danger: true
            });
            closeImageEditor();
            return;
        }

        destroyEditorCropper();
        editorCropper = new window.Cropper(editorImage, {
            viewMode: 1,
            dragMode: 'move',
            autoCropArea: 1,
            background: false,
            responsive: true,
            restore: false,
            checkOrientation: true,
            rotatable: false,
            scalable: false,
            zoomable: true,
            zoomOnWheel: true,
            toggleDragModeOnDblclick: false,
            aspectRatio: NaN,
            ready: function () {
                updateEditorMeta();
            },
            crop: function () {
                updateEditorMeta();
            }
        });

        var initialRatio = editorRatioSelect ? editorRatioSelect.value : 'free';
        if (initialRatio && initialRatio !== 'free') {
            editorSetRatio(initialRatio);
        }
        updateEditorMeta();
    }

    function releasePreviewUrl() {
        if (editorState.previewUrl) {
            try { URL.revokeObjectURL(editorState.previewUrl); } catch (error) {}
            editorState.previewUrl = '';
        }
    }

    function loadPreviewSource(url, objectUrl) {
        if (!editorImage) return;
        destroyEditorCropper();
        var token = ++editorState.loadToken;
        editorImage.onload = function () {
            if (token !== editorState.loadToken || !editorState.open) return;
            initEditorCropper();
        };
        editorImage.onerror = function () {
            if (token !== editorState.loadToken) return;
            alertDialog({
                title: t('editImage', 'Edit Image'),
                message: t('errorFailed', 'Could not save the image.'),
                danger: true
            });
            closeImageEditor();
        };
        editorImage.removeAttribute('src');
        if (!objectUrl) releasePreviewUrl();
        editorImage.src = url;
    }

    function renderTransformedPreview() {
        var base = editorState.baseImage;
        if (!base || !base.naturalWidth || !base.naturalHeight) return;

        var rotated = editorState.angle === 90 || editorState.angle === 270;
        var canvas = document.createElement('canvas');
        canvas.width = rotated ? base.naturalHeight : base.naturalWidth;
        canvas.height = rotated ? base.naturalWidth : base.naturalHeight;

        var context = canvas.getContext('2d');
        context.translate(canvas.width / 2, canvas.height / 2);
        context.rotate((editorState.angle * Math.PI) / 180);
        context.scale(editorState.flipX, editorState.flipY);
        context.drawImage(base, -base.naturalWidth / 2, -base.naturalHeight / 2);

        var mime = editorState.item && editorState.item.mime === 'image/png'
            ? 'image/png'
            : (editorState.item && editorState.item.mime === 'image/webp' ? 'image/webp' : 'image/jpeg');

        canvas.toBlob(function (blob) {
            if (!blob) return;
            releasePreviewUrl();
            var url = URL.createObjectURL(blob);
            editorState.previewUrl = url;
            loadPreviewSource(url, true);
        }, mime, 0.95);
    }

    function openImageEditor(imageUrl, filename, folder, path, mime, triggerOverride) {
        if (!editorRoot || !editorImage) {
            return;
        }

        var previousTrigger = triggerOverride || document.activeElement;
        if (lightboxState.open) {
            previousTrigger = lightboxState.trigger || previousTrigger;
            closeLightbox(false);
        }

        editorState.trigger = previousTrigger;
        editorState.item = {
            url: imageUrl || '',
            name: filename || '',
            folder: folder || '',
            path: path || '',
            mime: mime || ''
        };
        editorState.saving = false;
        editorState.open = true;
        if (editorRatioSelect) {
            editorRatioSelect.value = 'free';
        }
        setEditorBusy(false);

        editorRoot.style.display = 'flex';
        syncModalLock();

        editorState.angle = 0;
        editorState.flipX = 1;
        editorState.flipY = 1;
        releasePreviewUrl();

        var token = ++editorState.loadToken;
        var base = new Image();
        base.onload = function () {
            if (token !== editorState.loadToken || !editorState.open) return;
            editorState.baseImage = base;
            loadPreviewSource(imageUrl || '', false);
        };
        base.onerror = function () {
            if (token !== editorState.loadToken) return;
            alertDialog({
                title: t('editImage', 'Edit Image'),
                message: t('errorFailed', 'Could not save the image.'),
                danger: true
            });
            closeImageEditor();
        };
        base.src = imageUrl || '';
    }

    function closeImageEditor() {
        if (!editorRoot || !editorState.open) {
            return;
        }
        editorState.open = false;
        editorState.loadToken++;
        destroyEditorCropper();
        releasePreviewUrl();
        editorState.baseImage = null;
        if (editorImage) {
            editorImage.onload = null;
            editorImage.onerror = null;
            editorImage.removeAttribute('src');
        }
        editorRoot.style.display = 'none';
        syncModalLock();

        if (editorState.trigger
            && typeof editorState.trigger.focus === 'function'
            && document.contains(editorState.trigger)) {
            try {
                editorState.trigger.focus();
            } catch (error) {
                // Ignore detached trigger elements.
            }
        }
    }

    function editorRotate(degrees) {
        if (!editorState.baseImage) return;
        editorState.angle = (editorState.angle + (Number(degrees) || 0) + 360) % 360;
        renderTransformedPreview();
    }

    function editorFlip(axis) {
        if (!editorState.baseImage) return;
        if (axis === 'x') {
            editorState.flipX *= -1;
        } else if (axis === 'y') {
            editorState.flipY *= -1;
        }
        renderTransformedPreview();
    }

    function editorReset() {
        if (!editorState.baseImage) return;
        editorState.angle = 0;
        editorState.flipX = 1;
        editorState.flipY = 1;
        if (editorRatioSelect) {
            editorRatioSelect.value = 'free';
        }
        releasePreviewUrl();
        loadPreviewSource(editorState.item ? editorState.item.url : '', false);
    }

    function editorSetRatio(value) {
        if (!editorCropper) return;

        if (value === 'free') {
            editorCropper.setAspectRatio(NaN);
        } else if (value === 'original') {
            var imageData = editorCropper.getImageData();
            var width = Number(imageData.naturalWidth) || 1;
            var height = Number(imageData.naturalHeight) || 1;
            editorCropper.setAspectRatio(width / height);
        } else if (value && value.indexOf(':') !== -1) {
            var parts = value.split(':');
            var ratio = Number(parts[0]) / Number(parts[1]);
            if (isFinite(ratio) && ratio > 0) {
                editorCropper.setAspectRatio(ratio);
            }
        }
        updateEditorMeta();
    }

    function doSave(mode) {
        if (!editorCropper || editorState.saving || !editorState.item || !editorState.item.path) {
            return;
        }

        var data = editorCropper.getData(true) || {};
        if (!data.width || !data.height) {
            alertDialog({
                title: t('saveError', 'Save Error'),
                message: t('errorFailed', 'Could not save the image.'),
                danger: true
            });
            return;
        }

        setEditorBusy(true);

        var formData = new FormData();
        formData.append('csrf', csrf);
        formData.append('path', editorState.item.path);
        formData.append('mode', mode);
        formData.append('rotate', String(editorState.angle));
        formData.append('flip_x', editorState.flipX < 0 ? '1' : '0');
        formData.append('flip_y', editorState.flipY < 0 ? '1' : '0');
        formData.append('crop[x]', String(Math.round(Number(data.x) || 0)));
        formData.append('crop[y]', String(Math.round(Number(data.y) || 0)));
        formData.append('crop[width]', String(Math.round(Number(data.width) || 0)));
        formData.append('crop[height]', String(Math.round(Number(data.height) || 0)));
        formData.append('quality', '92');

        fetch(transformUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () {
                return { error: response.status === 413 ? 'too_large' : 'failed' };
            });
        }).then(function (data) {
            if (data && data.ok) {
                toast(t('imageSaved', 'Image saved successfully.'), 'success');
                window.location.reload();
                return;
            }

            setEditorBusy(false);
            var errorKey = data && data.error ? data.error : 'failed';
            var map = {
                missing: 'errorMissing',
                invalid: 'errorInvalid',
                unsupported: 'errorReadonly',
                readonly: 'errorReadonly',
                csrf: 'errorCsrf',
                too_large: 'errorTooLarge',
                failed: 'errorFailed'
            };
            alertDialog({
                title: t('saveError', 'Save Error'),
                message: t(map[errorKey] || 'errorFailed', 'Could not save the image.'),
                danger: true
            });
        }).catch(function () {
            setEditorBusy(false);
            alertDialog({
                title: t('saveError', 'Save Error'),
                message: t('errorFailed', 'Could not save the image.'),
                danger: true
            });
        });
    }

    function saveEditedImage(mode) {
        mode = mode === 'copy' ? 'copy' : 'overwrite';
        if (!editorState.open || !editorCropper || editorState.saving) {
            return;
        }

        if (mode === 'overwrite') {
            confirmDialog({
                title: t('replaceTitle', 'Replace original image?'),
                message: t('replaceMessage', 'The original file will be permanently replaced. This action cannot be undone.'),
                okText: t('save', 'Save'),
                cancelText: t('cancel', 'Cancel'),
                danger: true
            }).then(function (ok) {
                if (ok) {
                    doSave('overwrite');
                }
            });
            return;
        }

        doSave('copy');
    }

    // =======================================================================
    // Bulk image transformation (server-side, no full-size uploads)
    // =======================================================================
    var bulkCancel = null;

    async function bulkTransform(paths, options, onProgress) {
        options = options || {};
        var mode = options.mode === 'copy' ? 'copy' : 'overwrite';
        var rotate = [0, 90, 180, 270].indexOf(Number(options.rotate)) === -1 ? 0 : Number(options.rotate);
        var maxWidth = Math.max(0, Number(options.maxWidth) || 0);
        var maxHeight = Math.max(0, Number(options.maxHeight) || 0);
        var quality = Math.max(1, Math.min(100, Number(options.quality) || 82));

        var result = { total: paths.length, done: 0, failed: 0, errors: [] };
        var nextIndex = 0;
        var cancelled = false;
        bulkCancel = function () { cancelled = true; };

        async function processOne(path) {
            var error = '';
            try {
                var body = new FormData();
                body.append('csrf', csrf);
                body.append('path', path);
                body.append('mode', mode);
                body.append('rotate', String(rotate));
                body.append('flip_x', options.flipX ? '1' : '0');
                body.append('flip_y', options.flipY ? '1' : '0');
                body.append('max_width', String(maxWidth));
                body.append('max_height', String(maxHeight));
                body.append('quality', String(quality));

                var response = await fetch(transformUrl, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                var data = await response.json().catch(function () { return {}; });
                if (!response.ok || !data.ok) {
                    throw new Error(data.error || t('errorFailed', 'Could not save the image.'));
                }
                result.done++;
            } catch (err) {
                error = err && err.message ? err.message : 'failed';
                result.failed++;
                if (result.errors.length < 20) {
                    result.errors.push({ path: path, error: error });
                }
            }

            if (typeof onProgress === 'function') {
                onProgress(result.done + result.failed, paths.length, { path: path, error: error });
            }
        }

        async function worker() {
            while (!cancelled) {
                var index = nextIndex++;
                if (index >= paths.length) return;
                await processOne(paths[index]);
            }
        }

        // Two concurrent requests keep the server busy without exhausting
        // memory on shared hosting.
        await Promise.all([worker(), worker()]);
        bulkCancel = null;

        return result;
    }

    // =======================================================================
    // Global keyboard shortcuts
    // =======================================================================
    document.addEventListener('keydown', function (event) {
        if (editorState.open) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeImageEditor();
            }
            return;
        }

        if (!lightboxState.open) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeMediaLightbox();
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            lightboxPrev();
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            lightboxNext();
        } else if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            lightboxZoom(1);
        } else if (event.key === '-') {
            event.preventDefault();
            lightboxZoom(-1);
        } else if (event.key === '0') {
            event.preventDefault();
            lightboxResetZoom();
        }
    });

    window.addEventListener('resize', function () {
        if (editorState.open && editorCropper) {
            try { editorCropper.resize(); } catch (error) {}
        }
    });

    // Public API used by media.php inline handlers.
    window.openMediaLightbox = openMediaLightbox;
    window.closeMediaLightbox = closeMediaLightbox;
    window.lightboxPrev = lightboxPrev;
    window.lightboxNext = lightboxNext;
    window.lightboxZoom = lightboxZoom;
    window.lightboxResetZoom = lightboxResetZoom;
    window.lightboxEdit = lightboxEdit;
    window.lightboxCopy = lightboxCopy;

    window.openImageEditor = openImageEditor;
    window.closeImageEditor = closeImageEditor;
    window.editorRotate = editorRotate;
    window.editorFlip = editorFlip;
    window.editorReset = editorReset;
    window.editorSetRatio = editorSetRatio;
    window.saveEditedImage = saveEditedImage;
    window.ModoMediaBulk = {
        transform: bulkTransform,
        cancel: function () {
            if (typeof bulkCancel === 'function') bulkCancel();
        }
    };
})();
