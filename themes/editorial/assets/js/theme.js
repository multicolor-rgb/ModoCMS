// Modo Editorial — theme.js
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var backToTop = document.getElementById('backToTop');
    if (backToTop) {
        window.addEventListener('scroll', function () {
            backToTop.classList.toggle('show', window.scrollY > 400);
        });
        backToTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    var currentPath = window.location.pathname.replace(/\/$/, '') || '/';
    document.querySelectorAll('.navbar-nav .nav-link').forEach(function (link) {
        var linkPath = link.pathname.replace(/\/$/, '') || '/';
        if (linkPath === currentPath) {
            link.classList.add('active');
            link.setAttribute('aria-current', 'page');
        }
    });

    var navbarCollapse = document.getElementById('mainNav');
    if (navbarCollapse) {
        navbarCollapse.querySelectorAll('a.nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (navbarCollapse.classList.contains('show') && window.bootstrap) {
                    window.bootstrap.Collapse.getOrCreateInstance(navbarCollapse).hide();
                }
            });
        });
    }
});