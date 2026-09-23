<?php if (!defined('IN_CMS')) die(); ?>
<?php include __DIR__ . '/header.php'; ?>

<main id="main-content" class="site-content flex-grow-1">

    <?php if (!empty($item['featured_image'])): ?>
        <div class="page-hero page-hero-post" style="background-image: url('<?= htmlspecialchars($item['featured_image'], ENT_QUOTES, 'UTF-8') ?>');">
            <div class="page-hero-overlay"></div>
            <div class="container position-relative">
                <span class="badge bg-primary mb-2"><?= _e('Wpis na blogu') ?></span>
                <h1 class="page-hero-title text-white fw-bold"><?= Security::sanitize($item['title']) ?></h1>
                <p class="text-white-50 mb-0">
                    <i class="bi bi-calendar3 me-1"></i>
                    <?= date('d.m.Y', strtotime($item['created_at'])) ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">

                <?php if (empty($item['featured_image'])): ?>
                    <span class="badge bg-primary mb-2"><?= _e('Wpis na blogu') ?></span>
                    <h1 class="page-title fw-bold mb-3"><?= Security::sanitize($item['title']) ?></h1>
                    <p class="text-muted small mb-4">
                        <i class="bi bi-calendar3 me-1"></i>
                        <?= date('d.m.Y', strtotime($item['created_at'])) ?>
                    </p>
                <?php endif; ?>

                <article class="page-article entry-content mb-5">
                    <?= $item['content'] ?>
                </article>

                <hr class="my-5">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <a href="<?= get_site_url() ?>/blog" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left me-1"></i> <?= _e('Wróć do bloga') ?>
                    </a>
                    <div class="share-buttons d-flex gap-2">
                        <span class="text-muted small align-self-center"><?= _e('Udostępnij:') ?></span>
                        <a class="btn btn-sm btn-outline-secondary rounded-circle" href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
                        <a class="btn btn-sm btn-outline-secondary rounded-circle" href="#" aria-label="X (Twitter)"><i class="bi bi-twitter-x"></i></a>
                        <a class="btn btn-sm btn-outline-secondary rounded-circle" href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
                    </div>
                </div>

            </div>

            <aside class="col-lg-3 mt-5 mt-lg-0">
                <div class="sidebar-widget bg-light rounded-4 p-4">
                    <h6 class="fw-bold mb-3"><?= _e('Ostatnie wpisy') ?></h6>
                    <?php $sidebar_recent = get_recent_posts(5); ?>
                    <?php if (!empty($sidebar_recent)): ?>
                        <ul class="list-unstyled">
                            <?php foreach ($sidebar_recent as $recent): ?>
                                <li class="mb-3">
                                    <a href="<?= get_site_url() ?>/<?= Security::sanitize($recent['slug']) ?>"
                                       class="text-decoration-none text-dark fw-medium sidebar-link">
                                        <?= Security::sanitize($recent['title']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted small mb-0"><?= _e('Brak innych wpisów.') ?></p>
                    <?php endif; ?>
                </div>
            </aside>

        </div>
    </div>

</main>

<?php include __DIR__ . '/footer.php'; ?>
