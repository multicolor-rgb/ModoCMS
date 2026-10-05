<?php require __DIR__ . '/header.php'; ?>

<section class="home-hero text-center py-5 border-bottom">
    <div class="container py-4">
        <h1 class="editorial-title display-4 mb-3"><?php page_title(); ?></h1>
        <?php if (site_desc(false) !== ''): ?>
            <p class="lead text-body-secondary mb-0"><?= site_desc(false) ?></p>
        <?php endif; ?>
    </div>
</section>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-9 entry-content editorial-prose">
            <?php page_content(); ?>
        </div>
    </div>

    <?php $recent = get_recent_posts(6); ?>
    <?php if (!empty($recent)): ?>
        <h2 class="editorial-title h3 text-center mt-5 mb-4"><?= _e('Ostatnie wpisy') ?></h2>
        <div class="row g-4">
            <?php foreach ($recent as $rp): ?>
                <div class="col-md-6 col-lg-4">
                    <article class="editorial-card h-100">
                        <?php if (!empty($rp['featured_image'])): ?>
                            <a href="<?= htmlspecialchars(page_url($rp, false), ENT_QUOTES, 'UTF-8') ?>">
                                <img src="<?= htmlspecialchars(resolve_media_url($rp['featured_image']), ENT_QUOTES, 'UTF-8') ?>"
                                     class="editorial-card-img" alt="<?= htmlspecialchars($rp['title'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                            </a>
                        <?php endif; ?>
                        <div class="pt-3">
                            <span class="d-block text-body-secondary small mb-1"><?= htmlspecialchars(date('d.m.Y', strtotime($rp['created_at'] ?? 'now')), ENT_QUOTES, 'UTF-8') ?></span>
                            <h3 class="editorial-title-mini h5 mb-2">
                                <a class="text-decoration-none text-body stretched-link" href="<?= htmlspecialchars(page_url($rp, false), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($rp['title'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </h3>
                            <p class="text-body-secondary small mb-0">
                                <?= htmlspecialchars(mb_strimwidth(strip_tags($rp['content'] ?? ''), 0, 110, '...'), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>