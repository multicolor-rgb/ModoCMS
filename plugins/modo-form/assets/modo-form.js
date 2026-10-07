/**
 * Modo Form - skrypt frontu (bez CDN).
 * - obsługa reCAPTCHA v3 (wypełnienie ukrytego tokenu przed wysłaniem),
 * - lekka walidacja po stronie klienta (uzupełnienie walidacji serwera).
 */
(function () {
    "use strict";

    function ready(fn) {
        if (document.readyState !== "loading") {
            fn();
        } else {
            document.addEventListener("DOMContentLoaded", fn);
        }
    }

    ready(function () {
        var forms = document.querySelectorAll("form.modo-form");
        if (!forms.length) {
            return;
        }

        // reCAPTCHA v3 - wypełnij ukryte pole tokenem przed wysłaniem.
        var v3 = window.mfRecaptchaV3;
        var pending = false;
        if (v3 && typeof v3 === "object" && v3.siteKey && window.grecaptcha) {
            Array.prototype.forEach.call(forms, function (form) {
                var hidden = form.querySelector('input[name="mf_grecaptcha"]');
                if (!hidden) {
                    return;
                }
                form.addEventListener("submit", function (e) {
                    if (hidden.value || pending) {
                        return;
                    }
                    e.preventDefault();
                    pending = true;
                    grecaptcha.ready(function () {
                        grecaptcha.execute(v3.siteKey, { action: "modo_form" }).then(function (token) {
                            hidden.value = token;
                            pending = false;
                            if (form.requestSubmit) {
                                form.requestSubmit();
                            } else {
                                form.submit();
                            }
                        });
                    });
                });
            });
        }

        // Walidacja klienta.
        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener("submit", function (e) {
                var ok = true;
                var firstBad = null;

                Array.prototype.forEach.call(form.querySelectorAll(".modo-form__row.is-error"), function (r) {
                    r.classList.remove("is-error");
                });

                Array.prototype.forEach.call(form.querySelectorAll("[required]"), function (field) {
                    var row = field.closest(".modo-form__row") || field.closest(".modo-form__captcha");

                    if (field.type === "checkbox" || field.type === "radio") {
                        var group = form.querySelectorAll('[name="' + field.name + '"]');
                        var checked = Array.prototype.some.call(group, function (g) { return g.checked; });
                        var need = Array.prototype.some.call(group, function (g) { return g.required; });
                        if (need && !checked) {
                            ok = false;
                            if (row) { row.classList.add("is-error"); }
                            if (!firstBad) { firstBad = field; }
                        }
                    } else if (!field.value.trim()) {
                        ok = false;
                        if (row) { row.classList.add("is-error"); }
                        if (!firstBad) { firstBad = field; }
                    }
                });

                if (!ok) {
                    e.preventDefault();
                    if (firstBad && firstBad.focus) { firstBad.focus(); }
                }
            });
        });
    });
})();
