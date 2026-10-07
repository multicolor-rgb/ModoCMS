/* Modo Gallery - front-end script (initialises the bundled GLightbox) */
(function () {
    "use strict";

    function initGalleries() {
        if (typeof window.GLightbox !== "function") {
            return;
        }
        var boxes = document.querySelectorAll(".modo-gallery[data-lightbox='1']");
        boxes.forEach(function (el) {
            if (!el.id || el.getAttribute("data-mg-init") === "1") {
                return;
            }
            el.setAttribute("data-mg-init", "1");

            var opts = {
                selector: "#" + el.id + " .glightbox",
                touchNavigation: true,
                draggable: true,
                openEffect: "zoom",
                closeEffect: "zoom",
                loop: true,
                zoomable: true
            };

            try {
                var extra = JSON.parse(el.getAttribute("data-lb") || "{}");
                for (var k in extra) {
                    if (Object.prototype.hasOwnProperty.call(extra, k)) {
                        opts[k] = extra[k];
                    }
                }
            } catch (e) {
                /* ignore malformed config */
            }

            try {
                window.GLightbox(opts);
            } catch (e) {
                /* ignore init errors */
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initGalleries);
    } else {
        initGalleries();
    }
})();
