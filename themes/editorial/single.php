<?php require __DIR__ . '/header.php'; ?>

<article class="single-editorial-article">
    <?php if (has_image()): ?>
        <div class="single-hero-cover">
            <img src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
            <div class="single-hero-overlay"></div>
        </div>
    <?php endif; ?>

    <div class="single-editorial-body">
        <div class="single-meta-strip">
            <?= _e('Published on') ?> <?php page_date('F d, Y'); ?> &bull; <?= _e('by') ?> <?php page_author(); ?>
        </div>

        <h1 class="single-title"><?php page_title(); ?></h1>

        <div class="single-prose">
            <?php page_content(); ?>
        </div>

        <?php if (has_tags()): ?>
            <div style="margin-top: 40px; padding-top: 24px; border-top: 1px solid var(--border-light);">
                <span style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); display: block; margin-bottom: 10px;">
                    <?= _e('Tagged In') ?>:
                </span>
                <?php post_tags(null, 'post-tags-list'); ?>
            </div>
        <?php endif; ?>
    </div>
</article>

<?php require __DIR__ . '/footer.php'; ?>