</main>

    <footer class="site-footer border-top mt-auto pt-5 pb-4">
        <div class="container">
            <div class="row gy-4">
                <div class="col-lg-5">
                    <h5 class="editorial-brand fw-bold mb-3"><?= site_title(false) ?></h5>
                    <p class="text-body-secondary small mb-0"><?= site_desc(false) ?></p>
                </div>

                <div class="col-lg-3">
                    <h6 class="footer-heading mb-3"><?= _e('Nawigacja') ?></h6>
                    <nav class="footer-nav" aria-label="<?= _e('Nawigacja') ?>">
                        <?php menu('main-menu', 'footer-nav-list list-unstyled mb-0'); ?>
                    </nav>
                </div>

                <div class="col-lg-4">
                    <h6 class="footer-heading mb-3"><?= _e('Ostatnie wpisy') ?></h6>
                    <?php $recent = get_recent_posts(4); ?>
                    <?php if (!empty($recent)): ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($recent as $rp): ?>
                                <li class="mb-2">
                                    <a class="link-secondary text-decoration-none" href="<?= htmlspecialchars(page_url($rp, false), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($rp['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-body-secondary small mb-0"><?= _e('Brak wpisów.') ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <hr class="my-4">

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 small text-body-secondary">
                <span>&copy; <?= date('Y') ?> <?= site_title(false) ?>. <?= _e('All rights reserved.') ?></span>
                <span><?= htmlspecialchars((string) get_theme_mod('modo_footer_text', __('Built with Modo CMS')), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </footer>

    <?php if ((string) get_theme_mod('modo_show_back_to_top', '1') === '1'): ?>
    <button id="backToTop" type="button" class="btn btn-dark btn-back-to-top" aria-label="<?= _e('Wróć do góry') ?>">
        <i class="bi bi-arrow-up"></i>
    </button>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(get_theme_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/theme.js"></script>
    <?php theme_footer(); ?>
</body>
</html>