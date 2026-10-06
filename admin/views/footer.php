<?php
use Core\Hooks;
?>
        </main>
    </div>

    <!-- Global UI translations (localized labels for ui.js modals) -->
    <script>
        window.MODO_UI = {
            confirm: <?= json_encode(__('Confirm')) ?>,
            cancel: <?= json_encode(__('Cancel')) ?>,
            ok: <?= json_encode(__('OK')) ?>,
            areYouSure: <?= json_encode(__('Are you sure?')) ?>
        };
    </script>

    <!-- Theme Toggle & Admin Global Scripts -->
    <script src="assets/js/ui.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
    <script src="assets/js/admin.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/admin.js') ?>"></script>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const toggleBtn = document.getElementById('theme-toggle');
        const sunIcon = document.getElementById('theme-icon-sun');
        const moonIcon = document.getElementById('theme-icon-moon');

        const updateThemeUI = () => {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            if (sunIcon && moonIcon) {
                if (currentTheme === 'dark') {
                    sunIcon.style.display = 'inline-block';
                    moonIcon.style.display = 'none';
                } else {
                    sunIcon.style.display = 'none';
                    moonIcon.style.display = 'inline-block';
                }
            }
        };

        if (toggleBtn) {
            updateThemeUI();

            toggleBtn.addEventListener('click', () => {
                const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
                const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';

                document.documentElement.setAttribute('data-theme', nextTheme);
                localStorage.setItem('modo_cms_theme', nextTheme);
                updateThemeUI();
            });
        }
    });
    </script>

    <?php Hooks::doAction('admin_footer'); ?>
</body>
</html>