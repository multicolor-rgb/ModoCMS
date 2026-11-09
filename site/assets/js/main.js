/* =============================================================
   Modo CMS — landing interactions
   - PL/EN i18n with localStorage
   - scroll reveal, animated counters, mobile nav,
     copy-to-clipboard, card pointer glow
   No dependencies.
   ============================================================= */
(function () {
  'use strict';

  var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------- i18n dictionary ---------------- */
  var I18N = {
    pl: {
      'a11y.skip': 'Przejdź do treści',
      'brand.sub': 'PHP 8 · SQLite3',
      'nav.home': 'Start',
      'nav.features': 'Funkcje',
      'nav.how': 'Jak działa',
      'nav.start': 'Szybki start',
      'nav.docs': 'Dokumentacja',
      'nav.plugins': 'Pluginy',
      'nav.changelog': 'Changelog',
      'nav.download': 'Pobierz',
      'nav.github': 'GitHub',
      'nav.menu': 'Otwórz menu',

      'meta.features.title': 'Funkcje — Modo CMS',
      'meta.features.desc': 'Poznaj funkcje Modo CMS: Theme Customizer, dwa edytory treści, siatki CSS bez CDN, hooki, pola niestandardowe, kategorie, SMTP i bezpieczeństwo.',
      'meta.plugins.title': 'Pluginy i motywy — Modo CMS',
      'meta.plugins.desc': 'Dołączone do Modo CMS wtyczki i motywy: Cookie Consent, Modo Carousel, Modo Form, Modo Gallery oraz trzy motywy Bootstrap.',
      'meta.download.title': 'Pobierz — Modo CMS',
      'meta.download.desc': 'Pobierz Modo CMS: wymagania PHP 8, instalacja w katalogu głównym lub podkatalogu, kreator instalacji i czysty ZIP bez bazy oraz uploadów.',

      'highlights.eyebrow': 'Dlaczego Modo',
      'highlights.title': 'Wszystko, czego potrzebujesz — bez balastu',
      'highlights.sub': 'Nowoczesne funkcje CMS w lekkim, czytelnym kodzie. Każdą część rozbudujesz wtyczką, nie forkiem.',
      'highlights.more': 'Poznaj wszystkie funkcje',

      'plugins.eyebrow': 'Wtyczki i motywy',
      'plugins.title': 'Gotowe rozszerzenia w paczce',
      'plugins.sub': 'Modo CMS działa od razu po instalacji. Dołączone wtyczki i motywy korzystają wyłącznie z lokalnych zasobów — bez CDN i bez zewnętrznych zależności.',
      'plugins.core': 'Wtyczki',
      'plugins.coreTitle': 'Cztery wtyczki, które po prostu działają',
      'plugins.themes': 'Motywy',
      'plugins.themesTitle': 'Trzy motywy startowe',
      'plugins.themesSub': 'Każdy motyw korzysta z pełnego API Modo CMS: menu, języki, tagi, pętla wpisów, hooki i SEO.',
      'plugins.read': 'Dokumentacja wtyczek',
      'p1.desc': 'Baner zgody zgodny z RODO, ePrivacy i Google Consent Mode v2. Blokuje skrypty analityczne i marketingowe do momentu wyrażenia zgody.',
      'p2.desc': 'Responsywny slider z lokalnym Swiperem. Slajdy z obrazem, nagłówkiem, tekstem i CTA, autoplay, efekty, strzałki, paginacja i breakpointy.',
      'p3.desc': 'Kreator formularzy i ankiet z archiwizacją zgłoszeń. Domyślne powiadomienia e-mail HTML, Reply-To, hCaptcha, reCAPTCHA i własna ochrona antyspamowa.',
      'p4.desc': 'Galerie grid, masonry i justified z lokalnym GLightboxem, sortowaniem drag & drop i wyborem zdjęć z biblioteki mediów.',
      't1.desc': 'Jasny, responsywny motyw oparty na Bootstrap 5.3.8. Dobry punkt startowy dla typowej strony firmowej lub bloga.',
      't2.desc': 'Ciemny motyw z gradientami i szklanymi kartami. Pełne wykorzystanie API motywów i nowoczesnego wyglądu.',
      't3.desc': 'Magazynowy motyw z serifowymi nagłówkami i wyróżnionym wpisem. Idealny dla bloga i publikacji treści.',

      'download.eyebrow': 'Pobierz',
      'download.title': 'Pobierz Modo CMS',
      'download.sub': 'Czyste archiwum bez bazy danych, uploadów i plików deweloperskich. Rozpakuj, uruchom kreatora i masz gotową stronę na PHP 8 i SQLite3.',
      'download.btn': 'Pobierz najnowszy release',
      'download.source': 'Kod źródłowy',
      'download.req': 'Wymagania',
      'download.reqTitle': 'Co jest potrzebne',
      'download.req1': 'PHP 8.0 lub nowszy',
      'download.req2': 'Rozszerzenia pdo_sqlite, sqlite3 i mbstring',
      'download.req3': 'Apache, Nginx lub wbudowany serwer PHP',
      'download.req4': 'Prawo zapisu do katalogów data/ i uploads/',
      'download.req5': 'Żadnego zewnętrznego serwera bazy danych',
      'download.step1.title': 'Rozpakuj archiwum',
      'download.step1.desc': 'Wrzuć pliki do katalogu głównego lub podkatalogu na serwerze.',
      'download.step2.title': 'Nadaj prawa zapisu',
      'download.step2.desc': 'Katalogi data/ i uploads/ muszą być zapisywalne przez użytkownika serwera WWW.',
      'download.step3.title': 'Uruchom kreatora',
      'download.step3.desc': 'Otwórz /install.php, przejdź przez kreator i usuń plik instalatora.',
      'download.docs': 'Instrukcja instalacji',
      'docs.all': 'Cała dokumentacja',
      'docs.allDesc': 'Instalacja, wtyczki, motywy, hooki, typy stron, style edytora i przykłady.',

      'hero.badge': '100% open source · licencja MIT',
      'hero.title': 'Postaw stronę',
      'hero.rot0': 'bez bazy.',
      'hero.lead': 'Lekki, szybki i modułowy CMS na PHP 8 i SQLite3. Zero konfiguracji bazy, zero CDN i pełna kontrola nad motywem oraz wtyczkami.',
      'hero.cta1': 'Pobierz i zainstaluj',
      'hero.cta2': 'Zobacz na GitHub',
      'hero.tag3': 'Bez CDN',
      'hero.tag4': 'Licencja MIT',
      'hero.autosave': 'Zapisano',
      'hero.crumb': 'Strony / Strona główna',
      'hero.titletxt': 'Witamy w Modo CMS',
      'hero.plugin': 'Wtyczka aktywna',

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
      'f2.desc': 'Klasyczny edytor WYSIWYG (TinyMCE) z lokalnym bundlem — bez CDN i bez builda.',
      'f3.title': 'Siatki CSS bez CDN',
      'f3.desc': 'Bootstrap, Bulma, Pico, UIkit, Foundation, Tailwind lub własna siatka — dołączone lokalnie i spójne między frontendem a edytorem.',
      'f4.title': 'Hooki i filtry',
      'f4.desc': 'System akcji i filtrów w stylu WordPressa (Core\\Hooks) pozwala rozbudować panel i frontend bez dotykania plików rdzenia.',
      'f5.title': 'Pola niestandardowe',
      'f5.desc': 'Tekst, liczba, checkbox, kolor, obrazek i JSON — przypisz dowolne metadane do strony i wypisz je w motywie jedną funkcją.',
      'f6.title': 'Hierarchiczne kategorie i tagi',
      'f6.desc': 'Kategorie z wieloma poziomami zagnieżdżenia, wcięta lista w panelu, archiwa, paginacja, opisy SEO i wpisy w sitemap.xml — gotowe do użycia z motywu.',
      'f11.title': 'Drzewo stron',
      'f11.desc': 'Lista treści pokazuje istniejącą hierarchię stron jako drzewo z rozwijaniem, wcięciami i zapamiętywaniem stanu — bez tracenia wyników filtrów.',
      'f12.title': 'Ręczne porządkowanie treści',
      'f12.desc': 'Opcjonalna kolejność wpisów i stron ustawiana przeciąganiem lub strzałkami. Pętle, archiwa i get_pages() korzystają z tej kolejności, a responsywna tabela treści pozostaje czytelna także na mniejszych laptopach i telefonach.',
      'f13.title': 'Zaawansowany edytor mediów',
      'f13.desc': 'Crop, obrót ±90°, odbicie, zoom i proporcje dzięki lokalnemu Cropper.js. Transformacje wykonuje serwer z auto-orientacją EXIF, a masowa obróbka nie wysyła pełnych zdjęć z przeglądarki.',
      'f14.title': 'Kolejność pól i polskie slugi',
      'f14.desc': 'Pola w typach stron i polach niestandardowych przesuniesz uchwytem lub strzałkami. Slug z tytułu zamienia ą, ć, ę, ł, ń, ó, ś, ź i ż na czytelne znaki ASCII.',
      'f7.title': 'E-mail przez SMTP',
      'f7.desc': 'Wysyłka przez PHP mail() lub SMTP z PHPMailerem (TLS/SSL). Wszystkie wtyczki korzystają z jednej, globalnej konfiguracji.',
      'f8.title': 'Pasek administratora',
      'f8.desc': 'Zalogowani widzą na froncie pasek z szybkimi akcjami: edycja bieżącej strony, dodanie nowej treści i edycja ustawień motywu.',
      'f9.title': 'Bezpieczeństwo',
      'f9.desc': 'PDO Prepared Statements, tokeny CSRF, weryfikacja uprawnień i sanityzacja wyjścia — bezpieczne wzorce od pierwszej linii kodu.',
      'f10.title': 'Style motywu w edytorze',
      'f10.desc': 'Motyw może dostarczyć editor.css, który stylizuje wyłącznie kanwę TinyMCE — bez zmian na frontendzie i bez nowego ustawienia.',

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
      'doc7.title': 'Typy stron',
      'doc7.desc': 'Gotowe presety treści z domyślnymi polami niestandardowymi.',
      'doc8.title': 'Styl motywu w edytorze',
      'doc8.desc': 'Opcjonalna typografia TinyMCE z pliku editor.css.',

      'cta.title': 'Gotowy zbudować coś lekkiego?',
      'cta.sub': 'Pobierz Modo CMS, przeczytaj dokumentację i postaw własną witrynę na PHP 8 i SQLite3 — bez bazy do konfiguracji i bez zewnętrznych zależności.',
      'cta.btn1': 'Pobierz Modo CMS',
      'cta.btn2': 'Przeczytaj dokumentację',

      'footer.about': 'Lekki, szybki i modułowy system zarządzania treścią na PHP 8 i SQLite3. Zbudowany dla ludzi, którzy chcą pełnej kontroli nad kodem.',
      'footer.col1': 'Projekt',
      'footer.col2': 'Nawigacja',
      'footer.contributing': 'Współpraca',
      'footer.issues': 'Zgłoś błąd',
      'footer.license': 'Licencja MIT',
      'footer.made': 'Zbudowano na PHP 8 + SQLite3 · bez CDN'
    },

    en: {
      'a11y.skip': 'Skip to content',
      'brand.sub': 'PHP 8 · SQLite3',
      'nav.home': 'Home',
      'nav.features': 'Features',
      'nav.how': 'How it works',
      'nav.start': 'Quick start',
      'nav.docs': 'Documentation',
      'nav.plugins': 'Plugins',
      'nav.changelog': 'Changelog',
      'nav.download': 'Download',
      'nav.github': 'GitHub',
      'nav.menu': 'Open menu',

      'meta.features.title': 'Features — Modo CMS',
      'meta.features.desc': 'Explore Modo CMS features: Theme Customizer, two content editors, CSS grids without a CDN, hooks, custom fields, categories, SMTP and security.',
      'meta.plugins.title': 'Plugins & themes — Modo CMS',
      'meta.plugins.desc': 'Plugins and themes bundled with Modo CMS: Cookie Consent, Modo Carousel, Modo Form, Modo Gallery and three Bootstrap themes.',
      'meta.download.title': 'Download — Modo CMS',
      'meta.download.desc': 'Download Modo CMS: PHP 8 requirements, root or subdirectory installation, the setup wizard and a clean ZIP without database or uploads.',

      'highlights.eyebrow': 'Why Modo',
      'highlights.title': 'Everything you need — nothing you do not',
      'highlights.sub': 'Modern CMS features in a light, readable codebase. Extend every part with a plugin, not a fork.',
      'highlights.more': 'See all features',

      'plugins.eyebrow': 'Plugins & themes',
      'plugins.title': 'Bundled extensions',
      'plugins.sub': 'Modo CMS works right after installation. Every bundled plugin and theme uses local assets only — no CDN and no external dependencies.',
      'plugins.core': 'Plugins',
      'plugins.coreTitle': 'Four plugins that simply work',
      'plugins.themes': 'Themes',
      'plugins.themesTitle': 'Three starter themes',
      'plugins.themesSub': 'Every theme uses the full Modo CMS API: menus, languages, tags, the posts loop, hooks and SEO.',
      'plugins.read': 'Plugin documentation',
      'p1.desc': 'A consent banner compliant with GDPR, ePrivacy and Google Consent Mode v2. Blocks analytics and marketing scripts until consent is given.',
      'p2.desc': 'A responsive slider powered by a local Swiper build. Slides with image, heading, text and CTA, plus autoplay, effects, arrows, pagination and breakpoints.',
      'p3.desc': 'A form and survey builder with submission archiving. HTML e-mail notifications by default, Reply-To, hCaptcha, reCAPTCHA and built-in spam protection.',
      'p4.desc': 'Grid, masonry and justified galleries with a local GLightbox, drag & drop ordering and a media-library picker.',
      't1.desc': 'A light, responsive theme based on Bootstrap 5.3.8. A great starting point for a typical company site or blog.',
      't2.desc': 'A dark theme with gradients and glass cards. Full use of the theme API and a modern look.',
      't3.desc': 'A magazine-style theme with serif headings and a featured post. Ideal for blogs and publishing.',

      'download.eyebrow': 'Download',
      'download.title': 'Download Modo CMS',
      'download.sub': 'A clean archive without a database, uploads or development files. Extract it, run the wizard and you have a working site on PHP 8 and SQLite3.',
      'download.btn': 'Download the latest release',
      'download.source': 'Source code',
      'download.req': 'Requirements',
      'download.reqTitle': 'What you need',
      'download.req1': 'PHP 8.0 or newer',
      'download.req2': 'The pdo_sqlite, sqlite3 and mbstring extensions',
      'download.req3': 'Apache, Nginx or the built-in PHP server',
      'download.req4': 'Write access to the data/ and uploads/ directories',
      'download.req5': 'No external database server',
      'download.step1.title': 'Extract the archive',
      'download.step1.desc': 'Upload the files to your web root or a subdirectory.',
      'download.step2.title': 'Set write permissions',
      'download.step2.desc': 'The data/ and uploads/ directories must be writable by the web server user.',
      'download.step3.title': 'Run the wizard',
      'download.step3.desc': 'Open /install.php, follow the wizard and delete the installer.',
      'download.docs': 'Installation guide',
      'docs.all': 'All documentation',
      'docs.allDesc': 'Installation, plugins, themes, hooks, page types, editor styles and examples.',

      'hero.badge': '100% open source · MIT licensed',
      'hero.title': 'Ship your site',
      'hero.rot0': 'without a database.',
      'hero.lead': 'A lightweight, fast and modular CMS built on PHP 8 and SQLite3. No database setup, no CDN, and full control over your theme and plugins.',
      'hero.cta1': 'Download & install',
      'hero.cta2': 'View on GitHub',
      'hero.tag3': 'No CDN',
      'hero.tag4': 'MIT licensed',
      'hero.autosave': 'Saved',
      'hero.crumb': 'Pages / Home',
      'hero.titletxt': 'Welcome to Modo CMS',
      'hero.plugin': 'Plugin active',

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
      'f2.desc': 'Classic WYSIWYG editor (TinyMCE) bundled locally — no CDN and no build step.',
      'f3.title': 'CSS grids without a CDN',
      'f3.desc': 'Bootstrap, Bulma, Pico, UIkit, Foundation, Tailwind or your own grid — bundled locally and consistent between frontend and editor.',
      'f4.title': 'Hooks & filters',
      'f4.desc': 'A WordPress-style action and filter system (Core\\Hooks) lets you extend the panel and frontend without touching core files.',
      'f5.title': 'Custom fields',
      'f5.desc': 'Text, number, checkbox, colour, image and JSON — attach any metadata to a page and print it in a theme with a single function.',
      'f6.title': 'Hierarchical categories & tags',
      'f6.desc': 'Multi-level nested categories, an indented admin list, archives, pagination, SEO descriptions and sitemap entries — ready to use from a theme.',
      'f11.title': 'Page tree',
      'f11.desc': 'The content list renders the existing page hierarchy as an expandable tree with indentation and persisted state — without hiding filtered results.',
      'f12.title': 'Manual content ordering',
      'f12.desc': 'Optional post and page ordering set by drag and drop or arrow buttons. Theme loops, archives and get_pages() follow this order, while the responsive content table stays readable on smaller laptops and phones.',
      'f13.title': 'Advanced media editor',
      'f13.desc': 'Crop, ±90° rotation, flips, zoom and aspect ratios with a local Cropper.js build. Transforms run on the server with EXIF auto-orient, and bulk edits no longer upload full photos from the browser.',
      'f14.title': 'Field ordering and Polish slugs',
      'f14.desc': 'Fields in page types and custom fields can be moved by drag or arrows. Title slugs transliterate ą, ć, ę, ł, ń, ó, ś, ź and ż into readable ASCII.',
      'f7.title': 'E-mail over SMTP',
      'f7.desc': 'Send via PHP mail() or SMTP with PHPMailer (TLS/SSL). Every plugin uses one global configuration.',
      'f8.title': 'Admin bar',
      'f8.desc': 'Logged-in users get a frontend bar with quick actions: edit the current page, add new content and edit theme settings.',
      'f9.title': 'Security',
      'f9.desc': 'PDO prepared statements, CSRF tokens, permission checks and output sanitisation — safe patterns from the first line of code.',
      'f10.title': 'Theme styles in the editor',
      'f10.desc': 'A theme can ship editor.css that styles only the TinyMCE canvas — no frontend changes and no new setting.',

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
      'doc7.title': 'Page types',
      'doc7.desc': 'Reusable content presets with default custom fields.',
      'doc8.title': 'Theme styles in the editor',
      'doc8.desc': 'Optional TinyMCE typography from editor.css.',

      'cta.title': 'Ready to build something lightweight?',
      'cta.sub': 'Download Modo CMS, read the docs and launch your own site on PHP 8 and SQLite3 — with no database to configure and no external dependencies.',
      'cta.btn1': 'Download Modo CMS',
      'cta.btn2': 'Read the documentation',

      'footer.about': 'A lightweight, fast and modular content management system built on PHP 8 and SQLite3. Made for people who want full control over their code.',
      'footer.col1': 'Project',
      'footer.col2': 'Navigation',
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

    // Landing meta tags only; subpages carry their own data-i18n title/description.
    if (document.body.getAttribute('data-page') === 'home' && META[lang]) {
      document.title = META[lang].title;
      var desc = document.querySelector('meta[name="description"]');
      if (desc) desc.setAttribute('content', META[lang].desc);
    }

    // Language buttons
    var buttons = document.querySelectorAll('.lang-switch button');
    for (var b = 0; b < buttons.length; b++) {
      buttons[b].setAttribute('aria-pressed', buttons[b].getAttribute('data-lang') === lang ? 'true' : 'false');
    }

    try { localStorage.setItem(STORAGE_KEY, lang); } catch (e) {}
    currentLang = lang;
    startRotator(lang);
    return dict;
  }

  /* ---------------- Hero rotating word ---------------- */
  var ROTATOR = {
    pl: ['bez bazy.', 'bez CDN.', 'po swojemu.', 'w minutę.'],
    en: ['without a database.', 'without a CDN.', 'your way.', 'in a minute.']
  };
  var rotatorTimer = null;

  function startRotator(lang) {
    var wrap = document.getElementById('rotator');
    if (!wrap) return;
    var wordEl = wrap.querySelector('.rotator__word');
    if (!wordEl) return;

    var words = ROTATOR[lang] || ROTATOR.pl;
    var idx = 0;
    wordEl.textContent = words[0];

    if (rotatorTimer) { clearInterval(rotatorTimer); rotatorTimer = null; }
    if (prefersReduced || words.length < 2) return;

    rotatorTimer = setInterval(function () {
      idx = (idx + 1) % words.length;
      wordEl.classList.add('is-out');
      setTimeout(function () {
        wordEl.textContent = words[idx];
        wordEl.classList.remove('is-out');
      }, 320);
    }, 2600);
  }

  /* ---------------- Language switch ---------------- */
  var staticLang = document.body.getAttribute('data-static-lang');
  if (staticLang) {
    currentLang = staticLang === 'en' ? 'en' : 'pl';
    document.documentElement.lang = currentLang;
  } else {
    var langButtons = document.querySelectorAll('.lang-switch button');
    for (var i = 0; i < langButtons.length; i++) {
      langButtons[i].addEventListener('click', function () {
        applyLang(this.getAttribute('data-lang'));
      });
    }
    applyLang(getInitialLang());
  }

  // Language links on generated docs pages: remember the choice for the rest of the site.
  var langLinks = document.querySelectorAll('.lang-switch a[data-lang]');
  for (var ll = 0; ll < langLinks.length; ll++) {
    langLinks[ll].addEventListener('click', function () {
      try { localStorage.setItem(STORAGE_KEY, this.getAttribute('data-lang')); } catch (e) {}
    });
  }

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

  /* ---------------- Copy buttons on documentation code blocks ---------------- */
  var docBlocks = document.querySelectorAll('.prose pre');
  for (var pb = 0; pb < docBlocks.length; pb++) {
    (function (pre) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'copy-btn code-copy';
      btn.textContent = (I18N[currentLang] || I18N.pl)['start.copy'];
      pre.appendChild(btn);

      btn.addEventListener('click', function () {
        var code = pre.querySelector('code');
        var text = (code || pre).innerText.trim();
        var done = function () {
          btn.classList.add('is-done');
          btn.textContent = (I18N[currentLang] || I18N.pl)['start.copied'];
          setTimeout(function () {
            btn.classList.remove('is-done');
            btn.textContent = (I18N[currentLang] || I18N.pl)['start.copy'];
          }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done).catch(function () {});
        } else {
          var ta = document.createElement('textarea');
          ta.value = text;
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); done(); } catch (e) {}
          document.body.removeChild(ta);
        }
      });
    })(docBlocks[pb]);
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
