<?php if (!defined('IN_CMS')) die(); ?>
<?php include __DIR__ . '/header.php'; ?>

<main id="main-content" class="site-content flex-grow-1">

    <div class="archive-hero bg-light py-5 mb-5">
        <div class="container text-center">
            <span class="badge bg-primary mb-2"><?= _e('Blog') ?></span>
            <h1 class="fw-bold mb-2">
                <?= !empty($archive_title) ? Security::sanitize($archive_title) : _e('Wszystkie wpisy') ?>
            </h1>
            <p class="text-muted mb-0"><?= get_site_description() ?></p>
        </div>
    </div>

    <div class="container pb-5">
        <?php $items = $items ?? get_recent_posts(9); ?>

        <?php if (!empty($items)): ?>
            <div class="row g-4">
                <?php foreach ($items as $post): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-sm post-card">
                            <?php if (!empty($post['featured_image'])): ?>
                                <a href="<?= get_site_url() ?>/<?= Security::sanitize($post['slug']) ?>">
                                    <img src="<?= htmlspecialchars($post['featured_image'], ENT_QUOTES, 'UTF-8') ?>"
                                         class="card-img-top post-card-img" alt="<?= Security::sanitize($post['title']) ?>">
                                </a>
                            <?php else: ?>
                                <a href="<?= get_site_url() ?>/<?= Security::sanitize($post['slug']) ?>"
                                   class="post-card-img post-card-img-placeholder d-flex align-items-center justify-content-center">
                                    <i class="bi bi-image fs-1 text-secondary"></i>
                                </a>
                            <?php endif; ?>

                            <div class="card-body d-flex flex-column">
                                <p class="text-muted small mb-2">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <?= date('d.m.Y', strtotime($post['created_at'])) ?>
                                </p>
                                <h5 class="card-title fw-bold">
                                    <a href="<?= get_site_url() ?>/<?= Security::sanitize($post['slug']) ?>"
                                       class="text-dark text-decoration-none post-card-title">
                                        <?= Security::sanitize($post['title']) ?>
                                    </a>
                                </h5>
                                <p class="card-text text-muted small flex-grow-1">
                                    <?= mb_strimwidth(strip_tags($post['content'] ?? ''), 0, 120, '...') ?>
                                </p>
                                <a href="<?= get_site_url() ?>/<?= Security::sanitize($post['slug']) ?>"
                                   class="btn btn-sm btn-outline-primary mt-2 align-self-start">
                                    <?= _e('Czytaj dalej') ?> <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($pagination_html)): ?>
                <nav class="mt-5" aria-label="<?= _e('Paginacja') ?>">
                    <?= $pagination_html ?>
                </nav>
            <?php endif; ?>

        <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-secondary d-block mb-3"></i>
                <p class="text-muted"><?= _e('Nie znaleziono żadnych wpisów.') ?></p>
            </div>
        <?php endif; ?>
    </div>

</main>

<?php include __DIR__ . '/footer.php'; ?>
