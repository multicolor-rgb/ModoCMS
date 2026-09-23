<?php if (!defined('IN_CMS')) die(); ?>
    <footer class="site-footer bg-dark text-light-emphasis mt-auto">
        <div class="container py-5">
            <div class="row gy-4">
                <div class="col-lg-4">
                    <h5 class="text-white fw-bold mb-3">
                        <i class="bi bi-hexagon-fill text-primary me-1"></i>
                        <?= Security::sanitize(get_site_title()) ?>
                    </h5>
                    <p class="text-secondary small mb-0">
                        <?= Security::sanitize(get_site_description()) ?>
                    </p>
                </div>

                <div class="col-lg-4">
                    <h6 class="text-white fw-semibold mb-3"><?= _e('Nawigacja') ?></h6>
                    <ul class="list-unstyled footer-nav">
                        <?php get_theme_menu('footer-nav'); ?>
                    </ul>
                </div>

                <div class="col-lg-4">
                    <h6 class="text-white fw-semibold mb-3"><?= _e('Ostatnie wpisy') ?></h6>
                    <?php $footer_recent = get_recent_posts(3); ?>
                    <?php if (!empty($footer_recent)): ?>
                        <ul class="list-unstyled footer-recent-posts">
                            <?php foreach ($footer_recent as $post): ?>
                                <li class="mb-2">
                                    <a href="<?= get_site_url() ?>/<?= Security::sanitize($post['slug']) ?>"
                                       class="text-secondary text-decoration-none footer-link">
                                        <i class="bi bi-arrow-right-short me-1"></i>
                                        <?= Security::sanitize($post['title']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-secondary small mb-0"><?= _e('Brak wpisów.') ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <hr class="border-secondary my-4">

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
                <p class="mb-0 small text-secondary">
                    &copy; <?= date('Y') ?> <?= Security::sanitize(get_site_title()) ?>. <?= _e('All rights reserved.') ?>
                </p>
                <p class="mb-0 small text-secondary">
                    <?= _e('Zbudowano na') ?> Clean CMS
                </p>
            </div>
        </div>
    </footer>

    <!-- Przycisk powrotu do góry -->
    <button id="backToTop" type="button" class="btn btn-primary btn-back-to-top" aria-label="<?= _e('Wróć do góry') ?>">
        <i class="bi bi-arrow-up"></i>
    </button>

    <!-- Bootstrap 5.3.8 Bundle JS (Popper wliczony) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Skrypt motywu -->
    <script src="<?= get_theme_url() ?>/assets/js/theme.js"></script>

    <!-- Hook stopki dla wtyczek (skrypty, cookie banery, tracking) -->
    <?php get_footer(); ?>
</body>
</html>
