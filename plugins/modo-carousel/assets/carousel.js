/* Modo Carousel - front-end script (initialises the bundled Swiper) */
(function () {
    "use strict";

    function initCarousels() {
        if (typeof window.Swiper !== "function") {
            return;
        }
        var boxes = document.querySelectorAll(".modo-carousel[data-swiper]");
        boxes.forEach(function (el) {
            if (el.getAttribute("data-mc-init") === "1") {
                return;
            }
            el.setAttribute("data-mc-init", "1");

            var opts = {};
            try {
                opts = JSON.parse(el.getAttribute("data-swiper") || "{}") || {};
            } catch (e) {
                opts = {};
            }

            try {
                new window.Swiper(el, opts);
            } catch (e) {
                /* ignore init errors */
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initCarousels);
    } else {
        initCarousels();
    }
})();
