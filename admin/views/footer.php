<?php
use Core\Hooks;
?>
        </main>
    </div>

    <!-- Theme Toggle & Admin Global Scripts -->
    <script src="assets/js/admin.js"></script>

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
                localStorage.setItem('clean_cms_theme', nextTheme);
                updateThemeUI();
            });
        }
    });
    </script>

    <?php Hooks::doAction('admin_footer'); ?>
</body>
</html>