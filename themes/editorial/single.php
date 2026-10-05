<?php require __DIR__ . '/header.php'; ?>

<?php if (has_image()): ?>
    <section class="post-hero position-relative">
        <img class="page-hero-img" src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
        <div class="page-hero-overlay"></div>
    </section>
<?php endif; ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="post-meta text-uppercase small fw-semibold text-body-secondary mb-3">
                <span class="text-danger"><?= _e('Wpis') ?></span>
                <span class="mx-2">&bull;</span>
                <?php page_date('F d, Y'); ?>
                <span class="mx-2">&bull;</span>
                <?php page_author(); ?>
            </div>

            <h1 class="editorial-title mb-4"><?php page_title(); ?></h1>

            <article class="entry-content editorial-prose">
                <?php page_content(); ?>
            </article>

            <?php if (has_tags()): ?>
                <div class="mt-5 pt-4 border-top">
                    <span class="text-uppercase small fw-semibold text-body-secondary d-block mb-2"><?= _e('Tagged in') ?>:</span>
                    <?php post_tags(null, 'post-tags d-flex flex-wrap gap-2'); ?>
                </div>
            <?php endif; ?>

            <div class="mt-5 pt-4 border-top">
                <h2 class="h5 editorial-title mb-4"><?= _e('Ostatnie wpisy') ?></h2>
                <div class="row g-4">
                    <?php foreach (get_recent_posts(3) as $rp): ?>
                        <div class="col-md-4">
                            <a class="editorial-mini text-decoration-none" href="<?= htmlspecialchars(page_url($rp, false), ENT_QUOTES, 'UTF-8') ?>">
                                <span class="d-block text-body-secondary small mb-1"><?= htmlspecialchars(date('d.m.Y', strtotime($rp['created_at'] ?? 'now')), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="editorial-title-mini"><?= htmlspecialchars($rp['title'], ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>