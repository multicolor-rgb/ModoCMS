/* ==========================================================================
   Reusable drag-and-drop / arrow ordering for repeatable field rows.
   Works with dynamically added rows through event delegation.
   ========================================================================== */
(function () {
    'use strict';

    function attach(list, options) {
        if (!list) return;
        options = options || {};

        var rowSelector = options.rowSelector || '[data-field-row]';
        var handleSelector = options.handleSelector || '.field-order-handle';
        var upSelector = options.upSelector || '.field-order-up';
        var downSelector = options.downSelector || '.field-order-down';
        var dragging = null;

        list.addEventListener('click', function (event) {
            var up = event.target.closest(upSelector);
            var down = event.target.closest(downSelector);
            if (!up && !down) return;

            var row = (up || down).closest(rowSelector);
            if (!row) return;

            if (up) {
                var previous = row.previousElementSibling;
                if (previous && previous.matches(rowSelector)) {
                    list.insertBefore(row, previous);
                }
            } else {
                var next = row.nextElementSibling;
                if (next && next.matches(rowSelector)) {
                    list.insertBefore(next, row);
                }
            }
        });

        list.addEventListener('dragstart', function (event) {
            var handle = event.target.closest(handleSelector);
            if (!handle) return;

            dragging = handle.closest(rowSelector);
            if (!dragging) return;

            dragging.classList.add('is-field-dragging');
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                try { event.dataTransfer.setData('text/plain', 'field'); } catch (error) {}
            }
        });

        list.addEventListener('dragover', function (event) {
            if (!dragging) return;
            event.preventDefault();

            var target = event.target.closest(rowSelector);
            if (!target || target === dragging) return;

            var rect = target.getBoundingClientRect();
            var after = (event.clientY - rect.top) > rect.height / 2;
            list.insertBefore(dragging, after ? target.nextSibling : target);
        });

        list.addEventListener('drop', function (event) {
            if (dragging) event.preventDefault();
        });

        list.addEventListener('dragend', function () {
            if (!dragging) return;
            dragging.classList.remove('is-field-dragging');
            dragging = null;
        });
    }

    window.ModoFieldOrder = { attach: attach };
})();
