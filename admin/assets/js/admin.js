document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('admin-sidebar');
    const collapseTrigger = document.getElementById('collapse-trigger');

    if (!sidebar || !collapseTrigger) return;

    if (localStorage.getItem('clean_cms_sidebar_collapsed') === 'true') {
        sidebar.classList.add('collapsed');
    }

    collapseTrigger.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('clean_cms_sidebar_collapsed', sidebar.classList.contains('collapsed'));
    });
});

/**
 * Responsive mobile navigation drawer.
 * On viewports <= 900px the sidebar becomes an off-canvas drawer
 * toggled by the hamburger button, with a click-to-close overlay.
 */
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('admin-sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const menuBtn = document.getElementById('mobile-menu-btn');

    if (!sidebar || !menuBtn) return;

    const isMobile = () => window.matchMedia('(max-width: 900px)').matches;

    const openDrawer = () => {
        sidebar.classList.add('mobile-open');
        if (overlay) overlay.classList.add('active');
        document.documentElement.classList.add('ui-modal-open');
    };

    const closeDrawer = () => {
        sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('active');
        document.documentElement.classList.remove('ui-modal-open');
    };

    menuBtn.addEventListener('click', () => {
        if (sidebar.classList.contains('mobile-open')) {
            closeDrawer();
        } else {
            openDrawer();
        }
    });

    if (overlay) {
        overlay.addEventListener('click', closeDrawer);
    }

    // Close the drawer after tapping a navigation link on mobile.
    sidebar.addEventListener('click', (e) => {
        if (isMobile() && e.target.closest('a.nav-link')) {
            closeDrawer();
        }
    });

    // Reset the drawer state when leaving the mobile breakpoint.
    window.addEventListener('resize', () => {
        if (!isMobile()) {
            closeDrawer();
        }
    });
});

/**
 * Keep the hamburger button visibility in sync with the mobile breakpoint.
 * This is a safety net so the toggle never shows (and never lingers) on
 * desktop even if a stylesheet is stale or fails to load.
 */
document.addEventListener('DOMContentLoaded', () => {
    const menuBtn = document.getElementById('mobile-menu-btn');
    if (!menuBtn) return;

    const sync = () => {
        const isMobile = window.matchMedia('(max-width: 900px)').matches;
        menuBtn.style.display = isMobile ? 'inline-flex' : 'none';
    };

    sync();
    window.addEventListener('resize', sync);
});


/**
 * Shared client-side slugifier for title/name inputs. Mirrors Core\Slug:
 * Polish diacritics and other common Latin characters are transliterated
 * before the ASCII slug is built.
 */
window.ModoSlugify = function (value, fallback) {
    var map = {
        'ą': 'a', 'ć': 'c', 'ę': 'e', 'ł': 'l', 'ń': 'n', 'ó': 'o', 'ś': 's', 'ź': 'z', 'ż': 'z',
        'á': 'a', 'à': 'a', 'ä': 'a', 'â': 'a', 'ã': 'a', 'å': 'a', 'ç': 'c', 'č': 'c',
        'ď': 'd', 'đ': 'd', 'é': 'e', 'è': 'e', 'ê': 'e', 'ë': 'e', 'ě': 'e',
        'í': 'i', 'ì': 'i', 'î': 'i', 'ï': 'i', 'ı': 'i', 'ñ': 'n', 'ň': 'n',
        'ö': 'o', 'ô': 'o', 'õ': 'o', 'ø': 'o', 'ő': 'o', 'ř': 'r', 'š': 's', 'ť': 't',
        'ú': 'u', 'ù': 'u', 'û': 'u', 'ü': 'u', 'ů': 'u', 'ű': 'u', 'ý': 'y', 'ÿ': 'y', 'ž': 'z',
        'ß': 'ss', 'æ': 'ae', 'œ': 'oe', 'þ': 'th', 'ð': 'd', '&': ' and ', '@': ' at '
    };
    var text = String(value || '').toLowerCase();
    text = text.replace(/&/g, ' and ').replace(/@/g, ' at ');
    text = text.replace(/[^\u0000-\u007f]/g, function (char) {
        if (map[char]) return map[char];
        var normalized = char.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        return normalized;
    });
    text = text.replace(/[^a-z0-9]+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, '');
    return text || fallback || '';
};
