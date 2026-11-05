/* =============================================================
   Modo CMS project site builder
   -------------------------------------------------------------
   Builds the published site into .build/site:
     - copies the hand-written pages from site/
     - renders docs/pl/*.md and docs/en/*.md into real subpages
     - renders CHANGELOG.md
     - generates the documentation hubs and sitemap.xml

   Run locally:
     npm --prefix .github/site-build install
     node .github/site-build/build.mjs
     python3 -m http.server -d .build/site 8080
   ============================================================= */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { marked } from 'marked';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../..');
const SRC = path.join(ROOT, 'site');
const OUT = path.join(ROOT, '.build', 'site');
const SITE_URL = 'https://modocms.top';
const REPO = 'https://github.com/multicolor-rgb/ModoCMS';
const BRANCH = 'main';

/* ---------------- documentation map ---------------- */

const DOCS = [
  {
    slug: 'installation',
    pl: 'instalacja.md',
    en: 'installation.md',
    title: { pl: 'Instalacja', en: 'Installation' },
    desc: {
      pl: 'Root, podkatalog, Apache/Nginx i rozwiązywanie problemów.',
      en: 'Root, subdirectory, Apache/Nginx and troubleshooting.'
    },
    icon: 'download'
  },
  {
    slug: 'usage',
    pl: 'obsluga.md',
    en: 'usage.md',
    title: { pl: 'Obsługa systemu', en: 'Using Modo CMS' },
    desc: {
      pl: 'Logowanie, treść, media, motywy, ustawienia, użytkownicy i backupy.',
      en: 'Sign-in, content, media, themes, settings, users and backups.'
    },
    icon: 'book'
  },
  {
    slug: 'automation',
    pl: 'automatyzacja.md',
    en: 'automation.md',
    title: { pl: 'Automatyzacja i konserwacja', en: 'Automation and maintenance' },
    desc: {
      pl: 'Site Health, kosz, podglądy, RSS i zadania cron.',
      en: 'Site Health, trash, previews, RSS and cron jobs.'
    },
    icon: 'bolt'
  },
  {
    slug: 'plugins',
    pl: 'pluginy.md',
    en: 'plugins.md',
    title: { pl: 'Tworzenie pluginów', en: 'Creating plugins' },
    desc: {
      pl: 'Natywne pluginy Modo krok po kroku, z akcjami i filtrami.',
      en: 'Native Modo plugins step by step, with actions and filters.'
    },
    icon: 'plug'
  },
  {
    slug: 'getsimple-plugins',
    pl: 'wtyczki.md',
    en: 'getsimple-plugins.md',
    title: { pl: 'Wtyczki GetSimple', en: 'GetSimple plugins' },
    desc: {
      pl: 'Warstwa zgodności: register_plugin, sidebar, i18n.',
      en: 'Compatibility layer: register_plugin, sidebar, i18n.'
    },
    icon: 'layers'
  },
  {
    slug: 'theme-functions',
    pl: 'funkcje-motywu.md',
    en: 'theme-functions.md',
    title: { pl: 'Funkcje motywu', en: 'Theme functions' },
    desc: {
      pl: 'Pełna referencja wszystkich funkcji — opis dla laika.',
      en: 'A full reference of every function — explained in plain language.'
    },
    icon: 'code'
  },
  {
    slug: 'themes',
    pl: 'motywy.md',
    en: 'themes.md',
    title: { pl: 'Tworzenie motywów', en: 'Creating themes' },
    desc: {
      pl: 'Struktura motywu, theme.json, szablony, pętle i pakowanie.',
      en: 'Theme structure, theme.json, templates, loops and packaging.'
    },
    icon: 'layers'
  },
  {
    slug: 'hooks',
    pl: 'hooki.md',
    en: 'hooks.md',
    title: { pl: 'Hooki', en: 'Hooks' },
    desc: {
      pl: 'Wszystkie akcje i filtry: gdzie działają i do czego służą.',
      en: 'Every action and filter: where it fires and what it is for.'
    },
    icon: 'bolt'
  },
  {
    slug: 'page-types',
    pl: 'typy-stron.md',
    en: 'page-types.md',
    title: { pl: 'Typy stron', en: 'Page types' },
    desc: {
      pl: 'Gotowe presety treści z domyślnymi polami niestandardowymi.',
      en: 'Reusable content presets with default custom fields.'
    },
    icon: 'grid'
  },
  {
    slug: 'theme-editor-styles',
    pl: 'styl-motywu-w-edytorze.md',
    en: 'theme-editor-styles.md',
    title: { pl: 'Styl motywu w edytorze', en: 'Theme editor styles' },
    desc: {
      pl: 'Opcjonalna typografia TinyMCE z pliku editor.css.',
      en: 'Optional TinyMCE typography from editor.css.'
    },
    icon: 'code'
  },
  {
    slug: 'customizer',
    pl: 'customizer.md',
    en: 'customizer.md',
    title: { pl: 'Ustawienia motywu (Customizer)', en: 'Theme Customizer settings' },
    desc: {
      pl: 'Predefiniowane pola motywu, typy kontrolek, TinyMCE i linki wewnętrzne.',
      en: 'Predefined theme fields, control types, TinyMCE and internal links.'
    },
    icon: 'sliders'
  },
  {
    slug: 'api',
    pl: 'api.md',
    en: 'api.md',
    title: { pl: 'API treści', en: 'Content API' },
    desc: {
      pl: 'Tokeny, endpointy CRUD, uprawnienia, przykłady curl i błędy.',
      en: 'Tokens, CRUD endpoints, permissions, curl examples and errors.'
    },
    icon: 'code'
  },
  {
    slug: 'examples',
    pl: 'przyklady.md',
    en: 'examples.md',
    title: { pl: 'Przykłady', en: 'Examples' },
    desc: {
      pl: 'Gotowe wtyczki i fragmenty motywu do skopiowania.',
      en: 'Ready-to-copy plugins and theme snippets.'
    },
    icon: 'spark'
  }
];

/* ---------------- UI strings ---------------- */

const UI = {
  pl: {
    lang: 'pl',
    nav: {
      home: 'Start',
      features: 'Funkcje',
      docs: 'Dokumentacja',
      plugins: 'Pluginy',
      changelog: 'Changelog',
      download: 'Pobierz'
    },
    brandSub: 'PHP 8 · SQLite3',
    menu: 'Otwórz menu',
    docsTitle: 'Dokumentacja',
    docsLead: 'Przewodniki po instalacji, wtyczkach, motywach i API. Wszystko po polsku i angielsku, z przykładami gotowymi do skopiowania.',
    docsHubTitle: 'Wybierz temat',
    onThisPage: 'Na tej stronie',
    editOnGithub: 'Edytuj na GitHub',
    previous: 'Poprzednia',
    next: 'Następna',
    start: 'Start',
    docs: 'Dokumentacja',
    footerAbout: 'Lekki, szybki i modułowy system zarządzania treścią na PHP 8 i SQLite3. Zbudowany dla ludzi, którzy chcą pełnej kontroli nad kodem.',
    footerProject: 'Projekt',
    footerDocs: 'Dokumentacja',
    footerHome: 'Strona główna',
    footerFeatures: 'Funkcje',
    footerPlugins: 'Pluginy i motywy',
    footerChangelog: 'Changelog',
    footerDownload: 'Pobierz',
    footerContributing: 'Współpraca',
    footerIssues: 'Zgłoś błąd',
    footerLicense: 'Licencja MIT',
    footerMade: 'Zbudowano na PHP 8 + SQLite3 · bez CDN',
    changelogNote: 'Changelog projektu jest prowadzony po polsku i angielsku. Historia wersji znajduje się także w repozytorium.',
    changelogTitle: 'Changelog',
    changelogLead: 'Wszystkie istotne zmiany w projekcie Modo CMS — od pierwszego publicznego wydania po najnowsze funkcje.'
  },
  en: {
    lang: 'en',
    nav: {
      home: 'Home',
      features: 'Features',
      docs: 'Documentation',
      plugins: 'Plugins',
      changelog: 'Changelog',
      download: 'Download'
    },
    brandSub: 'PHP 8 · SQLite3',
    menu: 'Open menu',
    docsTitle: 'Documentation',
    docsLead: 'Guides for installation, plugins, themes and the API. Everything in Polish and English, with ready-to-copy examples.',
    docsHubTitle: 'Choose a topic',
    onThisPage: 'On this page',
    editOnGithub: 'Edit on GitHub',
    previous: 'Previous',
    next: 'Next',
    start: 'Home',
    docs: 'Documentation',
    footerAbout: 'A lightweight, fast and modular content management system built on PHP 8 and SQLite3. Made for people who want full control over their code.',
    footerProject: 'Project',
    footerDocs: 'Documentation',
    footerHome: 'Home',
    footerFeatures: 'Features',
    footerPlugins: 'Plugins & themes',
    footerChangelog: 'Changelog',
    footerDownload: 'Download',
    footerContributing: 'Contributing',
    footerIssues: 'Report a bug',
    footerLicense: 'MIT License',
    footerMade: 'Built on PHP 8 + SQLite3 · no CDN',
    changelogNote: 'The project changelog is maintained in Polish and English. Version history is also available in the repository.',
    changelogTitle: 'Changelog',
    changelogLead: 'Every important change in Modo CMS — from the first public release to the latest features.'
  }
};

const DOC_BY_LANG_FILE = {};
for (const doc of DOCS) {
  DOC_BY_LANG_FILE[`pl/${doc.pl}`] = doc.slug;
  DOC_BY_LANG_FILE[`en/${doc.en}`] = doc.slug;
}

/* ---------------- helpers ---------------- */

function rmrf(target) {
  fs.rmSync(target, { recursive: true, force: true });
}

function copyDir(from, to) {
  fs.mkdirSync(to, { recursive: true });
  for (const entry of fs.readdirSync(from, { withFileTypes: true })) {
    const src = path.join(from, entry.name);
    const dst = path.join(to, entry.name);
    if (entry.isDirectory()) copyDir(src, dst);
    else if (entry.isFile()) fs.copyFileSync(src, dst);
  }
}

function write(file, content) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, content, 'utf8');
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function stripTags(value) {
  return String(value)
    .replace(/<[^>]+>/g, '')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .trim();
}

/** GitHub-like slug used by the existing docs: ASCII, no collapsed hyphens. */
function makeSlug(text) {
  return stripTags(text)
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^\w\s-]/g, '')
    .trim()
    .replace(/\s/g, '-');
}

function createSlugger() {
  const used = new Set();
  return function slug(text) {
    const base = makeSlug(text) || 'section';
    let value = base;
    let n = 1;
    while (used.has(value)) value = `${base}-${n++}`;
    used.add(value);
    return value;
  };
}

/** Remove the language switcher line that lives near the top of every doc. */
function stripLanguageLine(markdown) {
  const lines = markdown.split('\n');
  const index = lines.findIndex((line, i) =>
    i < 12 && line.includes('|') && /Polski|English/.test(line) && line.includes('](')
  );
  if (index !== -1) lines.splice(index, 1);
  return lines.join('\n').replace(/^\s*\n/, '');
}

/** Remove a duplicated manual "Contents" / "Spis treści" section. */
function stripContentsSection(markdown) {
  const re = /^##\s+(Contents|Spis treści)\s*$/im;
  const match = re.exec(markdown);
  if (!match) return markdown;
  const start = match.index;
  const rest = markdown.slice(start + match[0].length);
  const next = rest.search(/^##\s+/m);
  if (next === -1) return markdown;
  return markdown.slice(0, start) + rest.slice(next);
}

function extractTitle(markdown) {
  const match = /^#\s+(.+)$/m.exec(markdown);
  if (!match) return { title: '', markdown };
  const title = match[1].trim();
  const withoutTitle = markdown.replace(/^#\s+.+\n?/m, '');
  return { title, markdown: withoutTitle };
}

function rewriteLinks(html, lang) {
  return html.replace(/(<a\b[^>]*\bhref=")([^"]+)(")/g, (full, before, href, after) => {
    if (/^(?:https?:|mailto:|tel:|#|\/)/i.test(href)) {
      return full;
    }

    const hashIndex = href.indexOf('#');
    const hash = hashIndex === -1 ? '' : href.slice(hashIndex);
    const filePart = hashIndex === -1 ? href : href.slice(0, hashIndex);

    let targetLang = lang;
    let file = filePart;

    const cross = /^\.\.\/(pl|en)\/([^/]+\.md)$/i.exec(filePart);
    if (cross) {
      targetLang = cross[1].toLowerCase();
      file = cross[2];
    }

    const key = `${targetLang}/${decodeURIComponent(file)}`;
    const slug = DOC_BY_LANG_FILE[key];
    if (slug) {
      const prefix = targetLang === lang ? '' : `/docs/${targetLang}/`;
      return `${before}${prefix}${slug}.html${hash}${after}`;
    }

    if (/\.md$/i.test(file)) {
      return `${before}${REPO}/blob/${BRANCH}/docs/${targetLang}/${file}${hash}${after}`;
    }

    return full;
  });
}

function addHeadingIds(html) {
  const slug = createSlugger();
  const headings = [];
  const rewritten = html.replace(/<h([2-4])>([\s\S]*?)<\/h\1>/g, (full, level, inner) => {
    const text = stripTags(inner);
    const id = slug(text);
    headings.push({ level: Number(level), text, id });
    return `<h${level} id="${escapeHtml(id)}">${inner}</h${level}>`;
  });
  return { html: rewritten, headings };
}

function renderMarkdown(markdown, lang) {
  let md = stripLanguageLine(markdown);
  md = stripContentsSection(md);

  const extracted = extractTitle(md);
  md = extracted.markdown;

  let html = marked.parse(md, { gfm: true, breaks: false });
  html = rewriteLinks(html, lang);
  html = html.replace(/<table>/g, '<div class="table-wrap"><table>').replace(/<\/table>/g, '</table></div>');
  html = html.replace(/(<a\b[^>]*\bhref="https?:\/\/[^"]+"[^>]*)(>)/g, (full, start, end) => {
    if (/\btarget=/.test(start)) return full;
    return `${start} target="_blank" rel="noopener"${end}`;
  });

  const withIds = addHeadingIds(html);
  return { title: extracted.title, html: withIds.html, headings: withIds.headings };
}

function icon(name) {
  const paths = {
    download: '<path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
    plug: '<path d="M9 7V3m6 4V3M7 7h10v5a5 5 0 0 1-10 0zM12 17v4"/>',
    layers: '<path d="M12 3l9 5-9 5-9-5 9-5zM3 13l9 5 9-5M3 18l9 5 9-5"/>',
    code: '<path d="M8 7l-5 5 5 5M16 7l5 5-5 5M14 4l-4 16"/>',
    bolt: '<path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z"/>',
    grid: '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
    spark: '<path d="M12 3l2.2 5.8L20 11l-5.8 2.2L12 19l-2.2-5.8L4 11l5.8-2.2L12 3z"/>',
    sliders: '<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2" fill="currentColor"/><circle cx="15" cy="12" r="2" fill="currentColor"/><circle cx="8" cy="18" r="2" fill="currentColor"/>',
    book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>'
  };
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.spark}</svg>`;
}

function navLinks(lang, active) {
  const ui = UI[lang];
  const links = [
    ['home', '/index.html', ui.nav.home],
    ['features', '/features.html', ui.nav.features],
    ['docs', '/docs/', ui.nav.docs],
    ['plugins', '/plugins.html', ui.nav.plugins],
    ['changelog', '/changelog/', ui.nav.changelog],
    ['download', '/download.html', ui.nav.download]
  ];
  return links.map(([key, href, label]) =>
    `<a class="nav__link${key === active ? ' is-active' : ''}" href="${href}"${key === active ? ' aria-current="page"' : ''}>${escapeHtml(label)}</a>`
  ).join('\n        ');
}

function footer(lang) {
  const ui = UI[lang];
  return `<footer class="footer">
    <div class="container">
      <div class="footer__grid">
        <div>
          <a class="brand" href="/index.html" style="margin-bottom:16px">
            <img src="/assets/img/logo.svg" alt="Modo CMS" width="36" height="36">
            <span>Modo CMS<small>${escapeHtml(ui.brandSub)}</small></span>
          </a>
          <p>${escapeHtml(ui.footerAbout)}</p>
        </div>
        <div>
          <h4>${escapeHtml(ui.footerProject)}</h4>
          <ul>
            <li><a href="${REPO}" target="_blank" rel="noopener">GitHub</a></li>
            <li><a href="${REPO}/blob/${BRANCH}/CONTRIBUTING.md" target="_blank" rel="noopener">${escapeHtml(ui.footerContributing)}</a></li>
            <li><a href="${REPO}/issues" target="_blank" rel="noopener">${escapeHtml(ui.footerIssues)}</a></li>
            <li><a href="${REPO}/blob/${BRANCH}/LICENSE" target="_blank" rel="noopener">${escapeHtml(ui.footerLicense)}</a></li>
          </ul>
        </div>
        <div>
          <h4>${escapeHtml(ui.footerDocs)}</h4>
          <ul>
            <li><a href="/index.html">${escapeHtml(ui.footerHome)}</a></li>
            <li><a href="/features.html">${escapeHtml(ui.footerFeatures)}</a></li>
            <li><a href="/docs/">${escapeHtml(ui.footerDocs)}</a></li>
            <li><a href="/plugins.html">${escapeHtml(ui.footerPlugins)}</a></li>
            <li><a href="/changelog/">${escapeHtml(ui.footerChangelog)}</a></li>
            <li><a href="/download.html">${escapeHtml(ui.footerDownload)}</a></li>
          </ul>
        </div>
      </div>
      <div class="footer__bottom">
        <span>© 2026 Modo CMS · ${escapeHtml(ui.footerLicense)}</span>
        <span>${escapeHtml(ui.footerMade)}</span>
      </div>
    </div>
  </footer>`;
}

function shell({ lang, title, description, active, canonical, body, langSwitch = '', staticLang = false, scripts = '' }) {
  const ui = UI[lang];
  const pageUrl = `${SITE_URL}${canonical}`;
  return `<!DOCTYPE html>
<html lang="${lang}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(title)}</title>
  <meta name="description" content="${escapeHtml(description)}">
  <meta name="theme-color" content="#0b1120">
  <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
  <link rel="canonical" href="${pageUrl}">

  <meta property="og:type" content="website">
  <meta property="og:title" content="${escapeHtml(title)}">
  <meta property="og:description" content="${escapeHtml(description)}">
  <meta property="og:url" content="${pageUrl}">
  <meta property="og:image" content="${SITE_URL}/assets/img/og-image.jpg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="${escapeHtml(title)}">
  <meta name="twitter:description" content="${escapeHtml(description)}">
  <meta name="twitter:image" content="${SITE_URL}/assets/img/og-image.jpg">

  <link rel="stylesheet" href="/assets/css/style.css">
  ${scripts}
</head>
<body data-page="${active}"${staticLang ? ` data-static-lang="${lang}"` : ''}>
  <a class="skip-link" href="#main">${lang === 'pl' ? 'Przejdź do treści' : 'Skip to content'}</a>

  <header class="nav" id="nav">
    <div class="container nav__inner">
      <a class="brand" href="/index.html">
        <img src="/assets/img/logo.svg" alt="Modo CMS" width="36" height="36">
        <span>Modo CMS<small>${escapeHtml(ui.brandSub)}</small></span>
      </a>

      <nav class="nav__links" id="navLinks" aria-label="${lang === 'pl' ? 'Nawigacja' : 'Navigation'}">
        ${navLinks(lang, active)}
      </nav>

      <div class="nav__actions">
        ${langSwitch}
        <a class="btn btn--ghost" href="${REPO}" target="_blank" rel="noopener">
          <svg class="btn__icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5A11.5 11.5 0 0 0 .5 12a11.5 11.5 0 0 0 7.86 10.92c.58.1.79-.25.79-.56v-2c-3.2.7-3.88-1.37-3.88-1.37-.53-1.34-1.3-1.7-1.3-1.7-1.05-.72.08-.71.08-.71 1.16.08 1.77 1.2 1.77 1.2 1.03 1.77 2.7 1.26 3.36.96.1-.75.4-1.26.72-1.55-2.55-.29-5.23-1.28-5.23-5.7 0-1.26.45-2.29 1.19-3.1-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.18 1.18a11 11 0 0 1 5.8 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.84 1.19 3.1 0 4.43-2.69 5.4-5.25 5.69.41.35.78 1.05.78 2.12v3.14c0 .31.2.67.8.56A11.5 11.5 0 0 0 23.5 12 11.5 11.5 0 0 0 12 .5Z"/></svg>
          <span>GitHub</span>
        </a>
        <button class="nav__burger" id="burger" type="button" aria-expanded="false" aria-controls="navLinks" aria-label="${escapeHtml(ui.menu)}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
      </div>
    </div>
  </header>

  <main id="main">
${body}
  </main>

  ${footer(lang)}

  <script src="/assets/js/main.js" defer></script>
</body>
</html>
`;
}

function docsSwitch(lang, slug, isHub = false) {
  const suffix = isHub ? '' : `${slug}.html`;
  return `<div class="lang-switch" role="group" aria-label="Język / Language">
          <a href="/docs/pl/${suffix}" data-lang="pl"${lang === 'pl' ? ' aria-current="true"' : ''}>PL</a>
          <a href="/docs/en/${suffix}" data-lang="en"${lang === 'en' ? ' aria-current="true"' : ''}>EN</a>
        </div>`;
}

function changelogSwitch(lang) {
  return `<div class="lang-switch" role="group" aria-label="Język / Language">
          <a href="/changelog/pl.html" data-lang="pl"${lang === 'pl' ? ' aria-current="true"' : ''}>PL</a>
          <a href="/changelog/en.html" data-lang="en"${lang === 'en' ? ' aria-current="true"' : ''}>EN</a>
        </div>`;
}

function sidebar(lang, activeSlug) {
  const ui = UI[lang];
  return `<aside class="docs-sidebar">
      <div class="docs-sidebar__title">${escapeHtml(ui.docsTitle)}</div>
      <nav class="docs-nav" aria-label="${escapeHtml(ui.docsTitle)}">
        ${DOCS.map(doc => `<a href="/docs/${lang}/${doc.slug}.html"${doc.slug === activeSlug ? ' class="is-active" aria-current="page"' : ''}>${escapeHtml(doc.title[lang])}</a>`).join('\n        ')}
      </nav>
      <a class="docs-sidebar__edit" href="${REPO}/blob/${BRANCH}/docs/${lang}/${DOCS.find(d => d.slug === activeSlug)[lang === 'pl' ? 'pl' : 'en']}" target="_blank" rel="noopener">${escapeHtml(ui.editOnGithub)}</a>
    </aside>`;
}

function toc(headings, lang) {
  if (!headings.length) return '';
  return `<aside class="docs-toc" aria-label="${escapeHtml(UI[lang].onThisPage)}">
      <div class="docs-toc__title">${escapeHtml(UI[lang].onThisPage)}</div>
      ${headings.map(h => `<a class="toc-level-${h.level}" href="#${escapeHtml(h.id)}">${escapeHtml(h.text)}</a>`).join('\n      ')}
    </aside>`;
}

function docsBody(lang, doc, html, headings) {
  const ui = UI[lang];
  const index = DOCS.findIndex(d => d.slug === doc.slug);
  const prev = index > 0 ? DOCS[index - 1] : null;
  const next = index < DOCS.length - 1 ? DOCS[index + 1] : null;

  return `<div class="container docs-layout">
    ${sidebar(lang, doc.slug)}

    <article class="docs-content">
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="/index.html">${escapeHtml(ui.start)}</a> <span>/</span>
        <a href="/docs/${lang}/">${escapeHtml(ui.docs)}</a> <span>/</span>
        <span>${escapeHtml(doc.title[lang])}</span>
      </nav>
      <h1 class="docs-title">${escapeHtml(doc.title[lang])}</h1>
      <p class="docs-lead">${escapeHtml(doc.desc[lang])}</p>
      <div class="prose">
${html}
      </div>

      <nav class="docs-pager" aria-label="${lang === 'pl' ? 'Nawigacja dokumentacji' : 'Docs navigation'}">
        ${prev ? `<a class="docs-pager__prev" href="/docs/${lang}/${prev.slug}.html"><small>← ${escapeHtml(ui.previous)}</small><b>${escapeHtml(prev.title[lang])}</b></a>` : '<span></span>'}
        ${next ? `<a class="docs-pager__next" href="/docs/${lang}/${next.slug}.html"><small>${escapeHtml(ui.next)} →</small><b>${escapeHtml(next.title[lang])}</b></a>` : '<span></span>'}
      </nav>
    </article>

    ${toc(headings, lang)}
  </div>`;
}

/* ---------------- build ---------------- */

rmrf(path.join(ROOT, '.build'));
copyDir(SRC, OUT);

for (const lang of ['pl', 'en']) {
  const ui = UI[lang];

  // docs hub
  const hubCards = DOCS.map(doc => `<a class="doc-tile docs-hub__card" href="/docs/${lang}/${doc.slug}.html">
        <span class="doc-tile__icon">${icon(doc.icon)}</span>
        <b>${escapeHtml(doc.title[lang])}</b>
        <span>${escapeHtml(doc.desc[lang])}</span>
      </a>`).join('\n      ');

  const hub = `<div class="container page-hero">
      <span class="eyebrow">${escapeHtml(ui.docsTitle)}</span>
      <h1>${escapeHtml(ui.docsTitle)}</h1>
      <p>${escapeHtml(ui.docsLead)}</p>
    </div>
    <div class="container" style="margin-bottom:clamp(48px,8vw,96px)">
      <h2 class="docs-hub__title">${escapeHtml(ui.docsHubTitle)}</h2>
      <div class="docs-hub">
      ${hubCards}
      </div>
    </div>`;

  write(path.join(OUT, 'docs', lang, 'index.html'), shell({
    lang,
    title: `${ui.docsTitle} — Modo CMS`,
    description: ui.docsLead,
    active: 'docs',
    canonical: `/docs/${lang}/`,
    body: hub,
    langSwitch: docsSwitch(lang, 'installation', true),
    staticLang: true
  }));

  // docs pages
  for (const doc of DOCS) {
    const source = path.join(ROOT, 'docs', lang, doc[lang]);
    const markdown = fs.readFileSync(source, 'utf8');
    const rendered = renderMarkdown(markdown, lang);
    const title = doc.title[lang] || rendered.title;
    const description = doc.desc[lang];

    const body = docsBody(lang, { ...doc, title: { ...doc.title, [lang]: title } }, rendered.html, rendered.headings);

    write(path.join(OUT, 'docs', lang, `${doc.slug}.html`), shell({
      lang,
      title: `${title} — Modo CMS`,
      description,
      active: 'docs',
      canonical: `/docs/${lang}/${doc.slug}.html`,
      body,
      langSwitch: docsSwitch(lang, doc.slug),
      staticLang: true
    }));
  }
}

// docs chooser at /docs/
write(path.join(OUT, 'docs', 'index.html'), `<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dokumentacja — Modo CMS</title>
  <meta name="description" content="Dokumentacja Modo CMS — przewodniki po instalacji, wtyczkach, motywach i API.">
  <link rel="canonical" href="${SITE_URL}/docs/">
  <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
  <link rel="stylesheet" href="/assets/css/style.css">
  <script>
    try {
      var l = localStorage.getItem('modo-lang');
      location.replace(l === 'en' ? '/docs/en/' : '/docs/pl/');
    } catch (e) {
      location.replace('/docs/pl/');
    }
  </script>
</head>
<body>
  <main class="container" style="padding:80px 22px">
    <h1>Dokumentacja / Documentation</h1>
    <p><a href="/docs/pl/">Polski</a> · <a href="/docs/en/">English</a></p>
  </main>
</body>
</html>
`);

// changelog
{
  const sources = [
    { lang: 'pl', file: 'CHANGELOG.md' },
    { lang: 'en', file: 'CHANGELOG.en.md' }
  ];

  for (const source of sources) {
    const ui = UI[source.lang];
    const markdown = fs.readFileSync(path.join(ROOT, source.file), 'utf8');
    const rendered = renderMarkdown(markdown, source.lang);
    const body = `<div class="container page-hero">
      <span class="eyebrow">${escapeHtml(ui.changelogTitle)}</span>
      <h1>${escapeHtml(ui.changelogTitle)}</h1>
      <p>${escapeHtml(ui.changelogLead)}</p>
    </div>
    <div class="container" style="margin-bottom:clamp(48px,8vw,96px)">
      <div class="prose changelog-prose">
        <p class="callout">${escapeHtml(ui.changelogNote)}</p>
${rendered.html}
      </div>
    </div>`;

    write(path.join(OUT, 'changelog', `${source.lang}.html`), shell({
      lang: source.lang,
      title: 'Changelog — Modo CMS',
      description: ui.changelogLead,
      active: 'changelog',
      canonical: `/changelog/${source.lang}.html`,
      body,
      langSwitch: changelogSwitch(source.lang),
      staticLang: true
    }));
  }

  // Language chooser for /changelog/
  write(path.join(OUT, 'changelog', 'index.html'), `<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Changelog — Modo CMS</title>
  <meta name="description" content="Modo CMS changelog — all notable changes, in Polish and English.">
  <link rel="canonical" href="${SITE_URL}/changelog/">
  <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
  <link rel="stylesheet" href="/assets/css/style.css">
  <script>
    try {
      var l = localStorage.getItem('modo-lang');
      location.replace(l === 'en' ? '/changelog/en.html' : '/changelog/pl.html');
    } catch (e) {
      location.replace('/changelog/pl.html');
    }
  </script>
</head>
<body>
  <main class="container" style="padding:80px 22px">
    <h1>Changelog</h1>
    <p><a href="/changelog/pl.html">Polski</a> · <a href="/changelog/en.html">English</a></p>
  </main>
</body>
</html>
`);

  // Keep the old URL working.
  write(path.join(OUT, 'changelog.html'), `<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="refresh" content="0; url=/changelog/">
  <link rel="canonical" href="${SITE_URL}/changelog/">
  <title>Changelog — Modo CMS</title>
  <script>location.replace('/changelog/');</script>
</head>
<body><a href="/changelog/">Changelog</a></body>
</html>
`);
}

// sitemap
{
  const urls = [
    '/', '/features.html', '/plugins.html', '/changelog/', '/changelog/pl.html',
    '/changelog/en.html', '/download.html',
    '/docs/', '/docs/pl/', '/docs/en/',
    ...DOCS.flatMap(doc => [`/docs/pl/${doc.slug}.html`, `/docs/en/${doc.slug}.html`])
  ];
  const xml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls.map(url => `  <url>
    <loc>${SITE_URL}${url}</loc>
    <changefreq>${url === '/' ? 'weekly' : 'monthly'}</changefreq>
    <priority>${url === '/' ? '1.0' : url.startsWith('/docs/') ? '0.7' : '0.8'}</priority>
  </url>`).join('\n')}
</urlset>
`;
  write(path.join(OUT, 'sitemap.xml'), xml);
}

console.log(`Built site into ${path.relative(ROOT, OUT)}`);
console.log(`Docs pages: ${DOCS.length * 2}, languages: pl/en`);
