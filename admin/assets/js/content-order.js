/* ==========================================================================
   Manual content ordering for the Content list.
   Handles flat post reordering and sibling-only page reordering.
   ========================================================================== */
(function () {
    'use strict';

    var configElement = document.getElementById('content-order-config');
    var body = document.getElementById('pages-tree-body');
    if (!configElement || !body) return;

    var config;
    try {
        config = JSON.parse(configElement.textContent || '{}');
    } catch (error) {
        return;
    }

    var errorMessage = config.error || 'Could not save the new order.';

    function rows() {
        return Array.prototype.slice.call(body.querySelectorAll('tr.page-tree-row'));
    }

    function parentOf(row) {
        return config.type === 'page' ? String(row.dataset.parentId || '0') : '0';
    }

    function siblingsOf(parentId) {
        return rows().filter(function (row) {
            return config.type !== 'page' || parentOf(row) === String(parentId);
        });
    }

    function blockOf(row) {
        var block = [row];
        if (config.type !== 'page') return block;

        var depth = parseInt(row.dataset.depth || '0', 10);
        var next = row.nextElementSibling;
        while (next && next.classList.contains('page-tree-row')) {
            if (parseInt(next.dataset.depth || '0', 10) <= depth) break;
            block.push(next);
            next = next.nextElementSibling;
        }
        return block;
    }

    function insertBlock(block, reference) {
        block.forEach(function (node) {
            body.insertBefore(node, reference || null);
        });
    }

    function persist(parentId) {
        var formData = new FormData();
        formData.append('csrf_token', config.csrf || '');
        formData.append('type', config.type || '');
        formData.append('parent_id', config.type === 'page' ? String(parentId || 0) : '0');
        formData.append('lang', config.lang || 'all');
        siblingsOf(parentId).forEach(function (row) {
            formData.append('ids[]', row.dataset.pageId || '');
        });

        fetch(config.endpoint || 'reorder.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            if (!result.ok || !result.data || !result.data.ok) {
                throw new Error((result.data && result.data.error) || errorMessage);
            }
        }).catch(function () {
            if (window.UI && typeof window.UI.alert === 'function') {
                window.UI.alert({ title: config.errorTitle || 'Ordering', message: errorMessage })
                    .then(function () { window.location.reload(); });
            } else {
                window.alert(errorMessage);
                window.location.reload();
            }
        });
    }

    var dragRow = null;
    var dragBlock = [];

    body.addEventListener('dragstart', function (event) {
        var handle = event.target.closest('.content-order-handle');
        if (!handle) return;

        dragRow = handle.closest('tr.page-tree-row');
        if (!dragRow) return;

        dragBlock = blockOf(dragRow);
        dragBlock.forEach(function (row) { row.classList.add('is-order-dragging'); });

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            try { event.dataTransfer.setData('text/plain', dragRow.dataset.pageId || ''); } catch (error) {}
        }
    });

    body.addEventListener('dragover', function (event) {
        if (!dragRow) return;
        event.preventDefault();

        var margin = 70;
        if (event.clientY < margin) {
            window.scrollBy(0, -12);
        } else if (event.clientY > window.innerHeight - margin) {
            window.scrollBy(0, 12);
        }

        var target = event.target.closest('tr.page-tree-row');
        if (!target || dragBlock.indexOf(target) !== -1) return;

        if (config.type === 'page') {
            if (target.dataset.parentId !== dragRow.dataset.parentId) return;
            if (target.dataset.depth !== dragRow.dataset.depth) return;
        }

        var rect = target.getBoundingClientRect();
        var after = (event.clientY - rect.top) > rect.height / 2;
        var reference;

        if (after) {
            var targetBlock = blockOf(target);
            reference = targetBlock[targetBlock.length - 1].nextSibling;
        } else {
            reference = target;
        }

        insertBlock(dragBlock, reference);
    });

    body.addEventListener('drop', function (event) {
        if (dragRow) event.preventDefault();
    });

    body.addEventListener('dragend', function () {
        if (!dragRow) return;

        var parentId = parentOf(dragRow);
        dragBlock.forEach(function (row) { row.classList.remove('is-order-dragging'); });
        dragRow = null;
        dragBlock = [];
        persist(parentId);
    });

    body.addEventListener('click', function (event) {
        var button = event.target.closest('.content-order-up, .content-order-down');
        if (!button) return;

        var row = button.closest('tr.page-tree-row');
        if (!row) return;

        var parentId = parentOf(row);
        var siblings = siblingsOf(parentId);
        var index = siblings.indexOf(row);
        var targetIndex = button.classList.contains('content-order-up') ? index - 1 : index + 1;

        if (index < 0 || targetIndex < 0 || targetIndex >= siblings.length) return;

        var block = blockOf(row);
        var target = siblings[targetIndex];

        if (button.classList.contains('content-order-up')) {
            insertBlock(block, target);
        } else {
            var targetBlock = blockOf(target);
            insertBlock(block, targetBlock[targetBlock.length - 1].nextSibling);
        }

        persist(parentId);
    });
})();
