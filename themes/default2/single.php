<?php require __DIR__ . '/header.php'; ?>

<?php if (has_image()): ?>
    <section class="post-hero position-relative">
        <img class="page-hero-img" src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
        <div class="page-hero-overlay"></div>
    </section>
<?php endif; ?>

<div class="container py-5">
    <div class="row g-5">
        <div class="col-lg-8">
            <h1 class="display-6 fw-bold mb-3"><?php page_title(); ?></h1>

            <div class="post-meta text-secondary small mb-4">
                <span class="badge text-bg-primary me-2"><?= _e('Blog') ?></span>
                <i class="bi bi-calendar3 me-1"></i> <?php page_date(); ?>
                <span class="mx-2">&bull;</span>
                <i class="bi bi-person me-1"></i> <?php page_author(); ?>
            </div>

            <article class="entry-content glass-card">
                <?php page_content(); ?>
            </article>

            <?php if (has_tags()): ?>
                <div class="mt-5 pt-4 border-top border-secondary-subtle">
                    <span class="text-secondary small fw-semibold d-block mb-2"><?= _e('Tags') ?>:</span>
                    <?php post_tags(null, 'post-tags d-flex flex-wrap gap-2'); ?>
                </div>
            <?php endif; ?>
        </div>

        <aside class="col-lg-4">
            <div class="card border-secondary-subtle bg-body-tertiary mb-4">
                <div class="card-body">
                    <h2 class="h6 fw-bold mb-3"><i class="bi bi-clock-history me-1"></i> <?= _e('Ostatnie wpisy') ?></h2>
                    <?php $recent = get_recent_posts(5); ?>
                    <?php if (!empty($recent)): ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($recent as $rp): ?>
                                <li class="mb-2 pb-2 border-bottom border-secondary-subtle">
                                    <a class="link-light text-decoration-none" href="<?= htmlspecialchars(page_url($rp, false), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($rp['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-secondary small mb-0"><?= _e('Brak wpisów.') ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-secondary-subtle bg-body-tertiary">
                <div class="card-body">
                    <h2 class="h6 fw-bold mb-3"><i class="bi bi-grid me-1"></i> <?= _e('Nawigacja') ?></h2>
                    <?php menu('main-menu', 'sidebar-nav list-unstyled mb-0'); ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>