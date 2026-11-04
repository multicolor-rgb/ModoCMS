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
    var saveUrl = cfg.saveUrl || 'media-save.php';
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

    var webpSupported = (function () {
        try {
            var canvas = document.createElement('canvas');
            canvas.width = 1;
            canvas.height = 1;
            return canvas.toDataURL('image/webp').indexOf('data:image/webp') === 0;
        } catch (error) {
            return false;
        }
    })();

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
    // Image editor
    // =======================================================================
    var editorRoot = document.getElementById('image-editor-modal');
    var editorCanvas = document.getElementById('editor-canvas');
    var editorCrop = document.getElementById('editor-crop');
    var editorRatioSelect = document.getElementById('editor-ratio');
    var editorResolution = document.getElementById('canvas-resolution');
    var editorFormat = document.getElementById('editor-format');
    var editorSaveBtn = document.getElementById('editor-save');
    var editorSaveCopyBtn = document.getElementById('editor-save-copy');
    var editorState = {
        open: false,
        img: null,
        item: null,
        angle: 0,
        flipX: 1,
        flipY: 1,
        scale: 1,
        fullW: 0,
        fullH: 0,
        crop: { x: 0, y: 0, w: 0, h: 0 },
        ratio: 'free',
        ratioValue: null,
        copyOnly: false,
        outputMime: 'image/jpeg',
        outputExt: 'jpg',
        saving: false,
        trigger: null,
        drag: null,
        loadToken: 0
    };

    function outputFormatFor(mime) {
        switch (mime) {
            case 'image/jpeg':
                return { mime: 'image/jpeg', ext: 'jpg', label: 'JPEG', copyOnly: false };
            case 'image/png':
                return { mime: 'image/png', ext: 'png', label: 'PNG', copyOnly: false };
            case 'image/webp':
                if (webpSupported) {
                    return { mime: 'image/webp', ext: 'webp', label: 'WEBP', copyOnly: false };
                }
                return { mime: 'image/png', ext: 'png', label: 'PNG', copyOnly: true };
            default:
                return { mime: 'image/png', ext: 'png', label: 'PNG', copyOnly: true };
        }
    }

    function currentRatioNumber() {
        if (editorState.ratio === 'original') {
            return editorCanvas && editorCanvas.height > 0
                ? editorCanvas.width / editorCanvas.height
                : null;
        }
        if (editorState.ratio && editorState.ratio.indexOf(':') !== -1) {
            var parts = editorState.ratio.split(':');
            var ratio = Number(parts[0]) / Number(parts[1]);
            return isFinite(ratio) && ratio > 0 ? ratio : null;
        }
        return null;
    }

    function clampValue(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function applyRatioToCrop() {
        var width = editorCanvas.width;
        var height = editorCanvas.height;
        var ratio = editorState.ratioValue;

        if (!ratio) {
            editorState.crop = { x: 0, y: 0, w: width, h: height };
            return;
        }

        var cropWidth = width;
        var cropHeight = cropWidth / ratio;
        if (cropHeight > height) {
            cropHeight = height;
            cropWidth = cropHeight * ratio;
        }

        editorState.crop = {
            x: (width - cropWidth) / 2,
            y: (height - cropHeight) / 2,
            w: cropWidth,
            h: cropHeight
        };
    }

    function updateCropOverlay() {
        if (!editorCrop || !editorCanvas || !editorCanvas.width) {
            return;
        }
        var rect = editorCanvas.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return;
        }

        var scaleX = rect.width / editorCanvas.width;
        var scaleY = rect.height / editorCanvas.height;
        editorCrop.style.left = (editorState.crop.x * scaleX) + 'px';
        editorCrop.style.top = (editorState.crop.y * scaleY) + 'px';
        editorCrop.style.width = (editorState.crop.w * scaleX) + 'px';
        editorCrop.style.height = (editorState.crop.h * scaleY) + 'px';
    }

    function updateEditorMeta() {
        if (!editorResolution || !editorCanvas) {
            return;
        }
        var outputWidth = Math.max(1, Math.round(editorState.crop.w / editorState.scale));
        var outputHeight = Math.max(1, Math.round(editorState.crop.h / editorState.scale));
        editorResolution.textContent = outputWidth + ' \u00d7 ' + outputHeight + ' px';

        if (editorFormat) {
            var format = outputFormatFor(editorState.item ? editorState.item.mime : '');
            editorFormat.textContent = format.label
                + (editorState.copyOnly ? ' \u00b7 ' + t('copyOnlyHint', 'This image format can only be saved as a copy.') : '');
        }
    }

    function renderEditor() {
        if (!editorState.img || !editorCanvas) {
            return;
        }

        var img = editorState.img;
        var rotated = editorState.angle === 90 || editorState.angle === 270;
        var fullWidth = rotated ? img.naturalHeight : img.naturalWidth;
        var fullHeight = rotated ? img.naturalWidth : img.naturalHeight;
        var scale = Math.min(1, 2400 / Math.max(fullWidth, fullHeight));
        var width = Math.max(1, Math.round(fullWidth * scale));
        var height = Math.max(1, Math.round(fullHeight * scale));

        editorState.scale = scale;
        editorState.fullW = fullWidth;
        editorState.fullH = fullHeight;

        editorCanvas.width = width;
        editorCanvas.height = height;

        var context = editorCanvas.getContext('2d');
        context.clearRect(0, 0, width, height);
        context.save();
        context.translate(width / 2, height / 2);
        context.rotate((editorState.angle * Math.PI) / 180);
        context.scale(editorState.flipX * scale, editorState.flipY * scale);
        context.drawImage(img, -img.naturalWidth / 2, -img.naturalHeight / 2);
        context.restore();

        editorState.ratioValue = currentRatioNumber();
        applyRatioToCrop();
        if (editorCrop) {
            editorCrop.style.display = '';
        }
        updateCropOverlay();
        updateEditorMeta();
    }

    function openImageEditor(imageUrl, filename, folder, path, mime, triggerOverride) {
        if (!editorRoot || !editorCanvas) {
            return;
        }

        var previousTrigger = triggerOverride || document.activeElement;
        if (lightboxState.open) {
            previousTrigger = lightboxState.trigger || previousTrigger;
            closeLightbox(false);
        }

        var format = outputFormatFor(mime || '');
        editorState.trigger = previousTrigger;
        editorState.item = {
            url: imageUrl || '',
            name: filename || '',
            folder: folder || '',
            path: path || '',
            mime: mime || ''
        };
        editorState.copyOnly = format.copyOnly;
        editorState.outputMime = format.mime;
        editorState.outputExt = format.ext;
        editorState.angle = 0;
        editorState.flipX = 1;
        editorState.flipY = 1;
        editorState.ratio = 'free';
        editorState.ratioValue = null;
        editorState.img = null;
        editorState.saving = false;
        if (editorRatioSelect) {
            editorRatioSelect.value = 'free';
        }
        if (editorSaveBtn) {
            editorSaveBtn.disabled = false;
            editorSaveBtn.title = format.copyOnly
                ? t('copyOnlyHint', 'This image format can only be saved as a copy.')
                : '';
        }
        if (editorSaveCopyBtn) {
            editorSaveCopyBtn.disabled = false;
        }

        editorCanvas.width = 1;
        editorCanvas.height = 1;
        if (editorCrop) {
            editorCrop.style.display = 'none';
        }
        if (editorResolution) {
            editorResolution.textContent = '';
        }

        editorRoot.style.display = 'flex';
        editorState.open = true;
        syncModalLock();

        var token = ++editorState.loadToken;
        var image = new Image();
        image.crossOrigin = 'anonymous';
        image.onload = function () {
            if (token !== editorState.loadToken || !editorState.open) {
                return;
            }
            editorState.img = image;
            renderEditor();
        };
        image.onerror = function () {
            if (token !== editorState.loadToken) {
                return;
            }
            closeImageEditor();
            alertDialog({
                title: t('saveError', 'Save Error'),
                message: t('couldNotLoad', 'Could not load the image.'),
                danger: true
            });
        };
        image.src = imageUrl || '';
    }

    function closeImageEditor() {
        if (!editorRoot || !editorState.open) {
            return;
        }
        editorState.open = false;
        editorState.loadToken++;
        editorState.img = null;
        editorState.drag = null;
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
        if (!editorState.img) {
            return;
        }
        editorState.angle = (editorState.angle + Number(degrees || 0) + 360) % 360;
        renderEditor();
    }

    function editorFlip(axis) {
        if (!editorState.img) {
            return;
        }
        if (axis === 'x') {
            editorState.flipX *= -1;
        } else if (axis === 'y') {
            editorState.flipY *= -1;
        }
        renderEditor();
    }

    function editorReset() {
        if (!editorState.img) {
            return;
        }
        editorState.angle = 0;
        editorState.flipX = 1;
        editorState.flipY = 1;
        editorState.ratio = 'free';
        editorState.ratioValue = null;
        if (editorRatioSelect) {
            editorRatioSelect.value = 'free';
        }
        renderEditor();
    }

    function editorSetRatio(value) {
        editorState.ratio = value || 'free';
        if (!editorState.img) {
            return;
        }
        editorState.ratioValue = currentRatioNumber();
        applyRatioToCrop();
        updateCropOverlay();
        updateEditorMeta();
    }

    function resizeCrop(mode, dx, dy, startCrop) {
        var width = editorCanvas.width;
        var height = editorCanvas.height;
        var min = 16;

        if (mode === 'move') {
            return {
                x: clampValue(startCrop.x + dx, 0, Math.max(0, width - startCrop.w)),
                y: clampValue(startCrop.y + dy, 0, Math.max(0, height - startCrop.h)),
                w: startCrop.w,
                h: startCrop.h
            };
        }

        var west = mode.indexOf('w') !== -1;
        var east = mode.indexOf('e') !== -1;
        var north = mode.indexOf('n') !== -1;
        var south = mode.indexOf('s') !== -1;
        var left = startCrop.x;
        var top = startCrop.y;
        var right = startCrop.x + startCrop.w;
        var bottom = startCrop.y + startCrop.h;

        if (west) {
            left = clampValue(startCrop.x + dx, 0, right - min);
        }
        if (east) {
            right = clampValue(startCrop.x + startCrop.w + dx, left + min, width);
        }
        if (north) {
            top = clampValue(startCrop.y + dy, 0, bottom - min);
        }
        if (south) {
            bottom = clampValue(startCrop.y + startCrop.h + dy, top + min, height);
        }

        var cropWidth = right - left;
        var cropHeight = bottom - top;
        var ratio = editorState.ratioValue;

        if (ratio) {
            if (east || west) {
                cropWidth = clampValue(cropWidth, min, width);
                cropHeight = cropWidth / ratio;
                if (cropHeight > height) {
                    cropHeight = height;
                    cropWidth = cropHeight * ratio;
                }
                if (cropHeight < min) {
                    cropHeight = min;
                    cropWidth = cropHeight * ratio;
                }
                if (cropWidth > width) {
                    cropWidth = width;
                    cropHeight = cropWidth / ratio;
                }

                left = west ? (startCrop.x + startCrop.w) - cropWidth : startCrop.x;
                left = clampValue(left, 0, Math.max(0, width - cropWidth));

                if (north || south) {
                    top = north ? (startCrop.y + startCrop.h) - cropHeight : startCrop.y;
                } else {
                    top = (startCrop.y + startCrop.h / 2) - cropHeight / 2;
                }
                top = clampValue(top, 0, Math.max(0, height - cropHeight));
            } else {
                cropHeight = clampValue(cropHeight, min, height);
                cropWidth = cropHeight * ratio;
                if (cropWidth > width) {
                    cropWidth = width;
                    cropHeight = cropWidth / ratio;
                }
                if (cropWidth < min) {
                    cropWidth = min;
                    cropHeight = cropWidth / ratio;
                }

                top = north ? (startCrop.y + startCrop.h) - cropHeight : startCrop.y;
                top = clampValue(top, 0, Math.max(0, height - cropHeight));

                left = (startCrop.x + startCrop.w / 2) - cropWidth / 2;
                left = clampValue(left, 0, Math.max(0, width - cropWidth));
            }
        }

        cropWidth = clampValue(cropWidth, min, width);
        cropHeight = clampValue(cropHeight, min, height);
        left = clampValue(left, 0, Math.max(0, width - cropWidth));
        top = clampValue(top, 0, Math.max(0, height - cropHeight));

        return { x: left, y: top, w: cropWidth, h: cropHeight };
    }

    function cropPointerDown(event) {
        if (!editorState.open || !editorState.img || !editorCrop) {
            return;
        }
        var handle = event.target && event.target.getAttribute
            ? event.target.getAttribute('data-handle')
            : null;
        if (!handle && event.target !== editorCrop) {
            return;
        }

        event.preventDefault();
        var rect = editorCanvas.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return;
        }

        editorState.drag = {
            mode: handle || 'move',
            startX: event.clientX,
            startY: event.clientY,
            crop: {
                x: editorState.crop.x,
                y: editorState.crop.y,
                w: editorState.crop.w,
                h: editorState.crop.h
            },
            scaleX: rect.width / editorCanvas.width,
            scaleY: rect.height / editorCanvas.height
        };

        editorCrop.classList.add('is-dragging');
        try {
            editorCrop.setPointerCapture(event.pointerId);
        } catch (error) {
            // Pointer capture is a progressive enhancement.
        }
    }

    function cropPointerMove(event) {
        if (!editorState.drag) {
            return;
        }
        event.preventDefault();
        var drag = editorState.drag;
        var dx = (event.clientX - drag.startX) / (drag.scaleX || 1);
        var dy = (event.clientY - drag.startY) / (drag.scaleY || 1);
        editorState.crop = resizeCrop(drag.mode, dx, dy, drag.crop);
        updateCropOverlay();
        updateEditorMeta();
    }

    function cropPointerUp(event) {
        if (!editorState.drag) {
            return;
        }
        editorState.drag = null;
        if (editorCrop) {
            editorCrop.classList.remove('is-dragging');
            try {
                editorCrop.releasePointerCapture(event.pointerId);
            } catch (error) {
                // Ignore stale pointer ids.
            }
        }
    }

    if (editorCrop) {
        editorCrop.addEventListener('pointerdown', cropPointerDown);
        editorCrop.addEventListener('pointermove', cropPointerMove);
        editorCrop.addEventListener('pointerup', cropPointerUp);
        editorCrop.addEventListener('pointercancel', cropPointerUp);
    }

    function buildOutputCanvas() {
        var img = editorState.img;
        var crop = editorState.crop;
        var scale = editorState.scale;
        var outputWidth = Math.max(1, Math.round(crop.w / scale));
        var outputHeight = Math.max(1, Math.round(crop.h / scale));
        var output = document.createElement('canvas');
        output.width = outputWidth;
        output.height = outputHeight;

        var context = output.getContext('2d');
        if (editorState.outputMime === 'image/jpeg') {
            // JPEG has no alpha channel: flatten transparency onto white.
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, outputWidth, outputHeight);
        }

        context.save();
        context.translate(
            editorState.fullW / 2 - crop.x / scale,
            editorState.fullH / 2 - crop.y / scale
        );
        context.rotate((editorState.angle * Math.PI) / 180);
        context.scale(editorState.flipX, editorState.flipY);
        context.drawImage(img, -img.naturalWidth / 2, -img.naturalHeight / 2);
        context.restore();

        return output;
    }

    function editedFilename(mode) {
        var name = (editorState.item && editorState.item.name) || ('image.' + editorState.outputExt);
        if (mode !== 'copy') {
            return name;
        }
        var base = name.replace(/\.[^.]+$/, '') || 'image';
        return base + '-edited.' + editorState.outputExt;
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

    function doSave(mode) {
        if (editorState.saving || !editorState.img) {
            return;
        }

        var output;
        try {
            output = buildOutputCanvas();
        } catch (error) {
            alertDialog({
                title: t('saveError', 'Save Error'),
                message: t('errorFailed', 'Could not save the image.'),
                danger: true
            });
            return;
        }

        setEditorBusy(true);

        output.toBlob(function (blob) {
            if (!blob) {
                setEditorBusy(false);
                alertDialog({
                    title: t('saveError', 'Save Error'),
                    message: t('errorFailed', 'Could not save the image.'),
                    danger: true
                });
                return;
            }

            var formData = new FormData();
            formData.append('csrf', csrf);
            formData.append('path', (editorState.item && editorState.item.path) || '');
            formData.append('mode', mode);
            formData.append('file', blob, editedFilename(mode));

            fetch(saveUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
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
        }, editorState.outputMime, 0.92);
    }

    function saveEditedImage(mode) {
        mode = mode === 'copy' ? 'copy' : 'overwrite';
        if (!editorState.open || !editorState.img || editorState.saving) {
            return;
        }

        if (mode === 'overwrite' && editorState.copyOnly) {
            alertDialog({
                title: t('editImage', 'Edit Image'),
                message: t('copyOnlyHint', 'This image format can only be saved as a copy.'),
                danger: false
            });
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
        if (editorState.open) {
            updateCropOverlay();
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
})();
