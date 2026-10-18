/* =============================================================
   Modo CMS — landing interactions
   - PL/EN i18n with localStorage
   - scroll reveal, animated counters, mobile nav,
     copy-to-clipboard, card pointer glow
   No dependencies.
   ============================================================= */
(function () {
  'use strict';

  /* ---------------- i18n dictionary ---------------- */
  var I18N = {
    pl: {
      'a11y.skip': 'Przejdź do treści',
      'brand.sub': 'PHP 8 · SQLite3',
      'nav.features': 'Funkcje',
      'nav.how': 'Jak działa',
      'nav.start': 'Szybki start',
      'nav.docs': 'Dokumentacja',
      'nav.github': 'GitHub',
      'nav.menu': 'Otwórz menu',

      'hero.badge': '100% open source · licencja MIT',
      'hero.title': 'Modularny CMS.<br>W <span class="grad-text">całości</span> na Twoich zasadach.',
      'hero.lead': 'Lekki, szybki i modułowy CMS na PHP 8 i SQLite3. Zero konfiguracji bazy, zero CDN i pełna kontrola nad motywem oraz wtyczkami.',
      'hero.cta1': 'Pobierz i zainstaluj',
      'hero.cta2': 'Zobacz na GitHub',
      'hero.tag3': 'Bez CDN',
      'hero.tag4': 'Licencja MIT',
      'hero.codecomment': '// Wtyczka w 8 liniach — bez zmiany rdzenia.',

      'panel.eyebrow': 'Panel administratora',
      'panel.title': 'Zaprojektowany, by pracowało się szybko',
      'panel.sub': 'Czysty, ciemny interfejs z pełną obsługą treści, mediów, motywów i wtyczek — działający tak samo dobrze na telefonie.',
      'panel.menu.dashboard': 'Pulpit',
      'panel.menu.pages': 'Strony',
      'panel.menu.posts': 'Wpisy',
      'panel.menu.media': 'Media',
      'panel.menu.plugins': 'Wtyczki',
      'panel.menu.settings': 'Ustawienia',
      'panel.h': 'Pulpit',
      'panel.sub2': 'Witaj ponownie — oto jak radzi sobie Twoja witryna.',
      'panel.stat1': 'Opublikowane strony',
      'panel.stat2': 'Wpisy na blogu',
      'panel.stat3': 'Aktywne wtyczki',

      'features.eyebrow': 'Możliwości',
      'features.title': 'Wszystko, czego potrzebujesz — bez balastu',
      'features.sub': 'Nowoczesne funkcje CMS w lekkim, czytelnym kodzie. Każdą część rozbudujesz wtyczką, nie forkiem.',
      'f1.title': 'Theme Customizer',
      'f1.desc': 'Ustawienia motywu z podglądem na żywo — pola deklarujesz w theme.json lub z wtyczki, a kolory aktualizują się natychmiast przez zmienne CSS.',
      'f2.title': 'Dwa edytory treści',
      'f2.desc': 'Klasyczny WYSIWYG (TinyMCE) albo edytor blokowy (Editor.js). Zmiana edytora nie niszczy istniejących treści — oba formaty współistnieją.',
      'f3.title': 'Siatki CSS bez CDN',
      'f3.desc': 'Bootstrap, Bulma, Pico, UIkit, Foundation, Tailwind lub własna siatka — dołączone lokalnie i spójne między frontendem a edytorem.',
      'f4.title': 'Hooki i filtry',
      'f4.desc': 'System akcji i filtrów w stylu WordPressa (Core\\Hooks) pozwala rozbudować panel i frontend bez dotykania plików rdzenia.',
      'f5.title': 'Pola niestandardowe',
      'f5.desc': 'Tekst, liczba, checkbox, kolor, obrazek i JSON — przypisz dowolne metadane do strony i wypisz je w motywie jedną funkcją.',
      'f6.title': 'Kategorie i tagi',
      'f6.desc': 'Płaskie kategorie i tagi dla wpisów, z archiwami, paginacją, opisami SEO i wpisami do sitemap.xml — gotowe do użycia z motywu.',
      'f7.title': 'E-mail przez SMTP',
      'f7.desc': 'Wysyłka przez PHP mail() lub SMTP z PHPMailerem (TLS/SSL). Wszystkie wtyczki korzystają z jednej, globalnej konfiguracji.',
      'f8.title': 'Pasek administratora',
      'f8.desc': 'Zalogowani widzą na froncie pasek z szybkimi akcjami: edycja bieżącej strony, dodanie nowej treści i edycja ustawień motywu.',
      'f9.title': 'Bezpieczeństwo',
      'f9.desc': 'PDO Prepared Statements, tokeny CSRF, weryfikacja uprawnień i sanityzacja wyjścia — bezpieczne wzorce od pierwszej linii kodu.',

      'how.eyebrow': 'Jak to działa',
      'how.title': 'Rdzeń zostaje czysty — resztę dopinasz hookami',
      'how.sub': 'Trzy kroki wystarczą, by rozbudować CMS. Twoja wtyczka wpina się w nazwane punkty i nie modyfikuje ani jednego pliku rdzenia.',
      'how.s1.title': 'Wrzuć plik do plugins/',
      'how.s1.desc': 'Pojedynczy plik PHP albo folder z własnymi zasobami. Identyfikatorem jest nazwa pliku lub katalogu.',
      'how.s2.title': 'Włącz w panelu',
      'how.s2.desc': 'Jedno kliknięcie w ekranie Wtyczki ustawia flagę aktywności. Kod ładuje się przy każdym żądaniu.',
      'how.s3.title': 'Wpnij się w hooki',
      'how.s3.desc': 'Akcje robią coś w danym momencie, filtry zmieniają wartość. Priorytet decyduje o kolejności.',

      'stat1': 'Konfiguracji bazy danych',
      'stat2': 'Edytory treści',
      'stat3': 'Funkcje motywu',
      'stat4': 'Hooki',
      'stat5': 'Dołączone wtyczki',
      'stat6': 'Licencja',

      'start.eyebrow': 'Szybki start',
      'start.title': 'Od zera do działającej strony w minutę',
      'start.l1': '<b>Sklonuj repozytorium</b> do katalogu głównego lub podkatalogu.',
      'start.l2': '<b>Nadaj prawa zapisu</b> katalogom <code>data/</code> i <code>uploads/</code>.',
      'start.l3': '<b>Uruchom kreatora</b> pod <code>/install.php</code> i gotowe.',
      'start.copy': 'Kopiuj',
      'start.copied': 'Skopiowano',

      'docs.eyebrow': 'Dokumentacja',
      'docs.title': 'Przewodniki dla laika i dla programisty',
      'docs.sub': 'Wszystko po polsku i angielsku: od instalacji, przez tworzenie pluginów, po pełną referencję funkcji i hooków — z przykładami.',
      'doc1.title': 'Instalacja',
      'doc1.desc': 'Root, podkatalog, Apache/Nginx i rozwiązywanie problemów.',
      'doc2.title': 'Tworzenie pluginów',
      'doc2.desc': 'Natywne pluginy Modo krok po kroku, z akcjami i filtrami.',
      'doc3.title': 'Wtyczki GetSimple',
      'doc3.desc': 'Warstwa zgodności: register_plugin, sidebar, i18n.',
      'doc4.title': 'Funkcje motywu',
      'doc4.desc': 'Pełna referencja wszystkich funkcji — opis dla laika.',
      'doc5.title': 'Hooki',
      'doc5.desc': 'Wszystkie akcje i filtry: gdzie działają i do czego służą.',
      'doc6.title': 'Przykłady',
      'doc6.desc': 'Gotowe wtyczki i fragmenty motywu do skopiowania.',

      'cta.title': 'Gotowy zbudować coś lekkiego?',
      'cta.sub': 'Pobierz Modo CMS, przeczytaj dokumentację i postaw własną witrynę na PHP 8 i SQLite3 — bez bazy do konfiguracji i bez zewnętrznych zależności.',
      'cta.btn1': 'Pobierz Modo CMS',
      'cta.btn2': 'Przeczytaj dokumentację',

      'footer.about': 'Lekki, szybki i modułowy system zarządzania treścią na PHP 8 i SQLite3. Zbudowany dla ludzi, którzy chcą pełnej kontroli nad kodem.',
      'footer.col1': 'Projekt',
      'footer.col2': 'Dokumentacja',
      'footer.contributing': 'Współpraca',
      'footer.issues': 'Zgłoś błąd',
      'footer.license': 'Licencja MIT',
      'footer.made': 'Zbudowano na PHP 8 + SQLite3 · bez CDN'
    },

    en: {
      'a11y.skip': 'Skip to content',
      'brand.sub': 'PHP 8 · SQLite3',
      'nav.features': 'Features',
      'nav.how': 'How it works',
      'nav.start': 'Quick start',
      'nav.docs': 'Documentation',
      'nav.github': 'GitHub',
      'nav.menu': 'Open menu',

      'hero.badge': '100% open source · MIT licensed',
      'hero.title': 'A modular CMS.<br>Entirely <span class="grad-text">on your terms</span>.',
      'hero.lead': 'A lightweight, fast and modular CMS built on PHP 8 and SQLite3. No database setup, no CDN, and full control over your theme and plugins.',
      'hero.cta1': 'Download & install',
      'hero.cta2': 'View on GitHub',
      'hero.tag3': 'No CDN',
      'hero.tag4': 'MIT licensed',
      'hero.codecomment': '// A plugin in 8 lines — no core changes.',

      'panel.eyebrow': 'Admin panel',
      'panel.title': 'Designed to be fast to work with',
      'panel.sub': 'A clean, dark interface with full control over content, media, themes and plugins — just as good on a phone.',
      'panel.menu.dashboard': 'Dashboard',
      'panel.menu.pages': 'Pages',
      'panel.menu.posts': 'Posts',
      'panel.menu.media': 'Media',
      'panel.menu.plugins': 'Plugins',
      'panel.menu.settings': 'Settings',
      'panel.h': 'Dashboard',
      'panel.sub2': 'Welcome back — here is how your site is doing.',
      'panel.stat1': 'Published pages',
      'panel.stat2': 'Blog posts',
      'panel.stat3': 'Active plugins',

      'features.eyebrow': 'Features',
      'features.title': 'Everything you need — nothing you do not',
      'features.sub': 'Modern CMS features in a light, readable codebase. Extend every part with a plugin, not a fork.',
      'f1.title': 'Theme Customizer',
      'f1.desc': 'Theme settings with live preview — declare fields in theme.json or from a plugin, and colours update instantly via CSS variables.',
      'f2.title': 'Two content editors',
      'f2.desc': 'Classic WYSIWYG (TinyMCE) or a block editor (Editor.js). Switching editors never destroys existing content — both formats coexist.',
      'f3.title': 'CSS grids without a CDN',
      'f3.desc': 'Bootstrap, Bulma, Pico, UIkit, Foundation, Tailwind or your own grid — bundled locally and consistent between frontend and editor.',
      'f4.title': 'Hooks & filters',
      'f4.desc': 'A WordPress-style action and filter system (Core\\Hooks) lets you extend the panel and frontend without touching core files.',
      'f5.title': 'Custom fields',
      'f5.desc': 'Text, number, checkbox, colour, image and JSON — attach any metadata to a page and print it in a theme with a single function.',
      'f6.title': 'Categories & tags',
      'f6.desc': 'Flat categories and tags for posts, with archives, pagination, SEO descriptions and entries in sitemap.xml — ready to use from a theme.',
      'f7.title': 'E-mail over SMTP',
      'f7.desc': 'Send via PHP mail() or SMTP with PHPMailer (TLS/SSL). Every plugin uses one global configuration.',
      'f8.title': 'Admin bar',
      'f8.desc': 'Logged-in users get a frontend bar with quick actions: edit the current page, add new content and edit theme settings.',
      'f9.title': 'Security',
      'f9.desc': 'PDO prepared statements, CSRF tokens, permission checks and output sanitisation — safe patterns from the first line of code.',

      'how.eyebrow': 'How it works',
      'how.title': 'The core stays clean — the rest plugs in through hooks',
      'how.sub': 'Three steps are enough to extend the CMS. Your plugin plugs into named points without modifying a single core file.',
      'how.s1.title': 'Drop a file into plugins/',
      'how.s1.desc': 'A single PHP file or a folder with its own assets. The identifier is the file or folder name.',
      'how.s2.title': 'Enable it in the panel',
      'how.s2.desc': 'One click on the Plugins screen flips the active flag. The code loads on every request.',
      'how.s3.title': 'Hook into the events',
      'how.s3.desc': 'Actions do something at a given moment, filters change a value. Priority decides the order.',

      'stat1': 'Database setup',
      'stat2': 'Content editors',
      'stat3': 'Theme functions',
      'stat4': 'Hooks',
      'stat5': 'Bundled plugins',
      'stat6': 'License',

      'start.eyebrow': 'Quick start',
      'start.title': 'From zero to a running site in a minute',
      'start.l1': '<b>Clone the repository</b> into the root or a subdirectory.',
      'start.l2': '<b>Grant write access</b> to the <code>data/</code> and <code>uploads/</code> directories.',
      'start.l3': '<b>Run the wizard</b> at <code>/install.php</code> and you are done.',
      'start.copy': 'Copy',
      'start.copied': 'Copied',

      'docs.eyebrow': 'Documentation',
      'docs.title': 'Guides for beginners and for developers',
      'docs.sub': 'Everything in Polish and English: from installation, through building plugins, to a full reference of functions and hooks — with examples.',
      'doc1.title': 'Installation',
      'doc1.desc': 'Root, subdirectory, Apache/Nginx and troubleshooting.',
      'doc2.title': 'Creating plugins',
      'doc2.desc': 'Native Modo plugins step by step, with actions and filters.',
      'doc3.title': 'GetSimple plugins',
      'doc3.desc': 'Compatibility layer: register_plugin, sidebar, i18n.',
      'doc4.title': 'Theme functions',
      'doc4.desc': 'A full reference of every function — explained in plain language.',
      'doc5.title': 'Hooks',
      'doc5.desc': 'Every action and filter: where it fires and what it is for.',
      'doc6.title': 'Examples',
      'doc6.desc': 'Ready-to-copy plugins and theme snippets.',

      'cta.title': 'Ready to build something lightweight?',
      'cta.sub': 'Download Modo CMS, read the docs and launch your own site on PHP 8 and SQLite3 — with no database to configure and no external dependencies.',
      'cta.btn1': 'Download Modo CMS',
      'cta.btn2': 'Read the documentation',

      'footer.about': 'A lightweight, fast and modular content management system built on PHP 8 and SQLite3. Made for people who want full control over their code.',
      'footer.col1': 'Project',
      'footer.col2': 'Documentation',
      'footer.contributing': 'Contributing',
      'footer.issues': 'Report a bug',
      'footer.license': 'MIT License',
      'footer.made': 'Built on PHP 8 + SQLite3 · no CDN'
    }
  };

  var META = {
    pl: {
      title: 'Modo CMS — lekki, modułowy CMS na PHP 8 i SQLite3',
      desc: 'Modo CMS to lekki, szybki i modułowy system zarządzania treścią na PHP 8 i SQLite3. Zero konfiguracji bazy, zero CDN, pełna kontrola nad motywem i wtyczkami. Licencja MIT.'
    },
    en: {
      title: 'Modo CMS — a lightweight modular CMS on PHP 8 & SQLite3',
      desc: 'Modo CMS is a lightweight, fast and modular content management system built on PHP 8 and SQLite3. No database setup, no CDN, full control over your theme and plugins. MIT licensed.'
    }
  };

  /* Doc links switch with the language (repo tracks the docs in docs/pl and docs/en) */
  var DOCs = {
    installation: { pl: 'docs/pl/instalacja.md', en: 'docs/en/installation.md' },
    plugins: { pl: 'docs/pl/pluginy.md', en: 'docs/en/plugins.md' },
    getsimple: { pl: 'docs/pl/wtyczki.md', en: 'docs/en/getsimple-plugins.md' },
    functions: { pl: 'docs/pl/funkcje-motywu.md', en: 'docs/en/theme-functions.md' },
    hooks: { pl: 'docs/pl/hooki.md', en: 'docs/en/hooks.md' },
    examples: { pl: 'docs/pl/przyklady.md', en: 'docs/en/examples.md' }
  };
  var REPO_BLOB = 'https://github.com/multicolor-rgb/ModoCMS/blob/3.0/';

  var STORAGE_KEY = 'modo-lang';
  var currentLang = 'pl';

  function getInitialLang() {
    try {
      var q = new URLSearchParams(window.location.search).get('lang');
      if (q === 'pl' || q === 'en') return q;
    } catch (e) {}
    try {
      var v = localStorage.getItem(STORAGE_KEY);
      if (v === 'pl' || v === 'en') return v;
    } catch (e) {}
    return 'pl';
  }

  function applyLang(lang) {
    var dict = I18N[lang] || I18N.pl;

    document.documentElement.lang = lang;

    var nodes = document.querySelectorAll('[data-i18n]');
    for (var i = 0; i < nodes.length; i++) {
      var key = nodes[i].getAttribute('data-i18n');
      if (dict[key] != null) nodes[i].textContent = dict[key];
    }

    var htmlNodes = document.querySelectorAll('[data-i18n-html]');
    for (var j = 0; j < htmlNodes.length; j++) {
      var hkey = htmlNodes[j].getAttribute('data-i18n-html');
      if (dict[hkey] != null) htmlNodes[j].innerHTML = dict[hkey];
    }

    var attrNodes = document.querySelectorAll('[data-i18n-attr]');
    for (var k = 0; k < attrNodes.length; k++) {
      var pairs = attrNodes[k].getAttribute('data-i18n-attr').split(';');
      for (var p = 0; p < pairs.length; p++) {
        var parts = pairs[p].split(':');
        if (parts.length === 2 && dict[parts[1].trim()] != null) {
          attrNodes[k].setAttribute(parts[0].trim(), dict[parts[1].trim()]);
        }
      }
    }

    // Document title + description
    if (META[lang]) {
      document.title = META[lang].title;
      var desc = document.querySelector('meta[name="description"]');
      if (desc) desc.setAttribute('content', META[lang].desc);
    }

    // Documentation links
    var docLinks = document.querySelectorAll('[data-doc]');
    for (var d = 0; d < docLinks.length; d++) {
      var id = docLinks[d].getAttribute('data-doc');
      if (DOCs[id] && DOCs[id][lang]) {
        docLinks[d].setAttribute('href', REPO_BLOB + DOCs[id][lang]);
      }
    }

    // Language buttons
    var buttons = document.querySelectorAll('.lang-switch button');
    for (var b = 0; b < buttons.length; b++) {
      buttons[b].setAttribute('aria-pressed', buttons[b].getAttribute('data-lang') === lang ? 'true' : 'false');
    }

    try { localStorage.setItem(STORAGE_KEY, lang); } catch (e) {}
    currentLang = lang;
    return dict;
  }

  /* ---------------- Language switch ---------------- */
  var langButtons = document.querySelectorAll('.lang-switch button');
  for (var i = 0; i < langButtons.length; i++) {
    langButtons[i].addEventListener('click', function () {
      applyLang(this.getAttribute('data-lang'));
    });
  }
  applyLang(getInitialLang());

  /* ---------------- Mobile nav ---------------- */
  var nav = document.getElementById('nav');
  var burger = document.getElementById('burger');
  if (burger && nav) {
    burger.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    var navLinks = document.querySelectorAll('.nav__link');
    for (var n = 0; n < navLinks.length; n++) {
      navLinks[n].addEventListener('click', function () {
        nav.classList.remove('is-open');
        burger.setAttribute('aria-expanded', 'false');
      });
    }
  }

  /* ---------------- Sticky nav state ---------------- */
  var onScroll = function () {
    if (!nav) return;
    nav.classList.toggle('is-scrolled', window.scrollY > 12);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------------- Copy button ---------------- */
  var copyBtn = document.getElementById('copyStart');
  var term = document.getElementById('termCode');
  if (copyBtn && term) {
    copyBtn.addEventListener('click', function () {
      var text = term.innerText.replace(/^\s*#.*$/gm, '').replace(/^\s*\$\s?/gm, '').trim();
      var done = function () {
        var dict = I18N[currentLang] || I18N.pl;
        copyBtn.classList.add('is-done');
        copyBtn.textContent = dict['start.copied'];
        setTimeout(function () {
          copyBtn.classList.remove('is-done');
          copyBtn.textContent = (I18N[currentLang] || I18N.pl)['start.copy'];
        }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () {});
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(ta);
      }
    });
  }

  /* ---------------- Scroll reveal ---------------- */
  var revealEls = document.querySelectorAll('[data-reveal]');
  if ('IntersectionObserver' in window) {
    var revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          revealObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    for (var r = 0; r < revealEls.length; r++) revealObserver.observe(revealEls[r]);
  } else {
    for (var q = 0; q < revealEls.length; q++) revealEls[q].classList.add('is-visible');
  }

  /* ---------------- Animated counters ---------------- */
  var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function animateCounter(el) {
    var target = parseInt(el.getAttribute('data-count'), 10) || 0;
    var prefix = el.getAttribute('data-prefix') || '';
    var suffix = el.getAttribute('data-suffix') || '';
    if (prefersReduced || target === 0) { el.textContent = prefix + target + suffix; return; }

    var duration = 1100;
    var start = null;
    function step(ts) {
      if (start === null) start = ts;
      var progress = Math.min((ts - start) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      el.textContent = prefix + Math.round(eased * target) + suffix;
      if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  var counters = document.querySelectorAll('[data-count]');
  if ('IntersectionObserver' in window) {
    var counterObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          animateCounter(entry.target);
          counterObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.5 });
    for (var c = 0; c < counters.length; c++) counterObserver.observe(counters[c]);
  } else {
    for (var cc = 0; cc < counters.length; cc++) animateCounter(counters[cc]);
  }

  /* ---------------- Card pointer glow ---------------- */
  if (!prefersReduced && window.matchMedia('(hover: hover)').matches) {
    var cards = document.querySelectorAll('.card');
    for (var ci = 0; ci < cards.length; ci++) {
      (function (card) {
        card.addEventListener('mousemove', function (e) {
          var rect = card.getBoundingClientRect();
          card.style.setProperty('--mx', (e.clientX - rect.left) + 'px');
          card.style.setProperty('--my', (e.clientY - rect.top) + 'px');
        });
      })(cards[ci]);
    }
  }
})();
