<?php require __DIR__ . '/header.php'; ?>

<section class="home-hero page-hero-gradient py-5">
    <div class="container py-5 text-center">
        <h1 class="display-4 fw-bold text-white mb-3"><?php page_title(); ?></h1>
        <?php if (site_desc(false) !== ''): ?>
            <p class="lead text-white-50 mb-0"><?= site_desc(false) ?></p>
        <?php endif; ?>
    </div>
</section>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-9 entry-content glass-card">
            <?php page_content(); ?>
        </div>
    </div>

    <?php $recent = get_recent_posts(6); ?>
    <?php if (!empty($recent)): ?>
        <h2 class="h3 fw-bold mt-5 mb-4 text-center"><?= _e('Ostatnie wpisy') ?></h2>
        <div class="row g-4">
            <?php foreach ($recent as $rp): ?>
                <div class="col-md-6 col-lg-4">
                    <article class="card h-100 border-secondary-subtle">
                        <?php if (!empty($rp['featured_image'])): ?>
                            <a href="<?= htmlspecialchars(page_url($rp, false), ENT_QUOTES, 'UTF-8') ?>">
                                <img src="<?= htmlspecialchars(resolve_media_url($rp['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                                     class="card-img-top post-card-img" alt="<?= htmlspecialchars($rp['title'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                            </a>
                        <?php endif; ?>
                        <div class="card-body">
                            <h3 class="h5 card-title">
                                <a class="stretched-link text-decoration-none link-light" href="<?= htmlspecialchars(page_url($rp, false), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($rp['title'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </h3>
                            <p class="card-text text-secondary small mb-0">
                                <?= htmlspecialchars(mb_strimwidth(strip_tags($rp['content'] ?? ''), 0, 120, '...'), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>