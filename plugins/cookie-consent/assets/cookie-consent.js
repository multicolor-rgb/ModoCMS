/**
 * Cookie Consent – Modo CMS
 * Frontend consent manager: banner, granular categories, script unblocking,
 * Google Consent Mode v2, GPC/DNT, consent logging.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'modo_cookie_consent';
    var cfgEl = document.getElementById('cc-config');
    if (!cfgEl) { return; }

    var CFG;
    try { CFG = JSON.parse(cfgEl.textContent || '{}'); } catch (e) { return; }

    var CATS = ['pref', 'analytics', 'marketing'];

    function now() { return Date.now(); }

    function readStored() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) { return null; }
            var data = JSON.parse(raw);
            if (!data || typeof data !== 'object') { return null; }
            if (String(data.policyVersion) !== String(CFG.policyVersion)) { return null; }
            if (data.expires && now() > data.expires) { return null; }
            return data;
        } catch (e) { return null; }
    }

    function saveStored(state) {
        state.policyVersion = CFG.policyVersion;
        state.expires = now() + (CFG.expiryDays * 86400000);
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
    }

    function normalizeCats(cats) {
        var out = {};
        CATS.forEach(function (c) { out[c] = !!(cats && cats[c]); });
        return out;
    }

    function logConsent(action, cats) {
        if (!CFG.endpoint) { return; }
        var payload = {
            action: action,
            consent_id: (readStored() && readStored().consentId) || '',
            categories: normalizeCats(cats)
        };
        try {
            fetch(CFG.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                credentials: 'same-origin',
                keepalive: true
            }).then(function (r) { return r.json(); })
              .then(function (res) {
                  if (res && res.consent_id) {
                      var st = readStored() || {};
                      st.consentId = res.consent_id;
                      saveStored(st);
                  }
              }).catch(function () {});
        } catch (e) {}
    }

    function updateConsentMode(cats) {
        if (!CFG.consentMode) { return; }
        window.dataLayer = window.dataLayer || [];
        function gtag() { window.dataLayer.push(arguments); }
        gtag('consent', 'update', {
            'ad_storage': cats.marketing ? 'granted' : 'denied',
            'ad_user_data': cats.marketing ? 'granted' : 'denied',
            'ad_personalization': cats.marketing ? 'granted' : 'denied',
            'analytics_storage': cats.analytics ? 'granted' : 'denied',
            'functionality_storage': cats.pref ? 'granted' : 'denied'
        });
        window.dispatchEvent(new CustomEvent('modo:consent-updated', { detail: cats }));
    }

    function unblockCategory(cat) {
        var nodes = document.querySelectorAll('[data-cookieconsent="' + cat + '"]');
        Array.prototype.forEach.call(nodes, function (node) {
            if (node.tagName === 'SCRIPT') {
                if (node.type !== 'text/plain') { return; }
                var s = document.createElement('script');
                Array.prototype.forEach.call(node.attributes, function (attr) {
                    if (attr.name === 'type' || attr.name === 'data-cookieconsent') { return; }
                    s.setAttribute(attr.name, attr.value);
                });
                s.type = 'text/javascript';
                s.text = node.textContent;
                node.parentNode.replaceChild(s, node);
            } else if (node.tagName === 'IFRAME') {
                var src = node.getAttribute('data-src');
                if (src && !node.src) { node.src = src; }
            }
        });
    }

    function applyConsent(cats) {
        if (cats.pref) { unblockCategory('pref'); }
        if (cats.analytics) { unblockCategory('analytics'); }
        if (cats.marketing) { unblockCategory('marketing'); }
        updateConsentMode(cats);
    }

    function getRoot() { return document.getElementById('cc-root'); }
    function getRevoke() { return document.getElementById('cc-revoke'); }

    function showBanner() {
        var root = getRoot();
        if (root) { root.hidden = false; }
        document.documentElement.classList.add('cc-lock');
        var revoke = getRevoke();
        if (revoke) { revoke.hidden = true; }
    }

    function hideBanner() {
        var root = getRoot();
        if (root) { root.hidden = true; }
        document.documentElement.classList.remove('cc-lock');
        var revoke = getRevoke();
        if (revoke && CFG.showRevoke) { revoke.hidden = false; }
    }

    function setCheckboxes(cats) {
        CATS.forEach(function (c) {
            var cb = document.getElementById('cc-cat-' + c);
            if (cb) { cb.checked = !!cats[c]; }
        });
    }

    function readCheckboxes() {
        var cats = {};
        CATS.forEach(function (c) {
            var cb = document.getElementById('cc-cat-' + c);
            cats[c] = cb ? !!cb.checked : false;
        });
        return cats;
    }

    function persist(action, cats) {
        cats = normalizeCats(cats);
        var prev = readStored() || {};
        saveStored({ categories: cats, consentId: prev.consentId || '' });
        applyConsent(cats);
        logConsent(action, cats);
        hideBanner();
    }

    function allCats(value) {
        var c = {};
        CATS.forEach(function (k) { c[k] = value; });
        return c;
    }

    function bind() {
        var root = getRoot();
        if (!root) { return; }

        root.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-cc]');
            if (!btn) { return; }
            var action = btn.getAttribute('data-cc');
            var catsPanel = root.querySelector('.cc-categories');

            if (action === 'accept') {
                persist('accept_all', allCats(true));
            } else if (action === 'reject') {
                persist('reject_all', allCats(false));
            } else if (action === 'settings') {
                if (catsPanel) { catsPanel.hidden = !catsPanel.hidden; }
                var saveBtn = root.querySelector('[data-cc="save"]');
                if (saveBtn) { saveBtn.hidden = !(catsPanel && !catsPanel.hidden); }
                btn.setAttribute('aria-expanded', (catsPanel && !catsPanel.hidden) ? 'true' : 'false');
            } else if (action === 'save') {
                persist('save', readCheckboxes());
            }
        });

        var revoke = getRevoke();
        if (revoke) {
            revoke.addEventListener('click', function () {
                var stored = readStored();
                setCheckboxes(stored ? stored.categories : allCats(false));
                showBanner();
            });
        }
    }

    function init() {
        bind();

        if ((CFG.server && CFG.server.gpc) || (navigator.globalPrivacyControl === true)) {
            persist('reject_all', allCats(false));
            return;
        }
        if (CFG.server && CFG.server.dnt) {
            persist('reject_all', allCats(false));
            return;
        }

        var stored = readStored();
        if (stored) {
            setCheckboxes(stored.categories);
            applyConsent(normalizeCats(stored.categories));
            hideBanner();
        } else {
            setCheckboxes(allCats(false));
            showBanner();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.ModoCookieConsent = {
        open: function () {
            var stored = readStored();
            setCheckboxes(stored ? stored.categories : allCats(false));
            showBanner();
        },
        acceptAll: function () { persist('accept_all', allCats(true)); },
        rejectAll: function () { persist('reject_all', allCats(false)); },
        getConsent: function () { var s = readStored(); return s ? s.categories : null; }
    };
})();