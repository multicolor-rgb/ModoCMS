<?php require __DIR__ . '/header.php'; ?>

<div class="article-container">
    <?php if (has_image()): ?>
        <img class="article-hero-image" src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
    <?php endif; ?>
    
    <div class="post-meta">
        <?= _e('Published on') ?>: <?php page_date(); ?> &bull; <?= _e('by') ?> <?php page_author(); ?>
    </div>

    <h1 class="article-title"><?php page_title(); ?></h1>

    <div class="prose-content">
        <?php page_content(); ?>
    </div>

    <?php if (has_tags()): ?>
        <div style="margin-top: 32px; padding-top: 20px; border-top: 1px solid var(--border);">
            <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 8px;"><?= _e('Tags') ?>:</span>
            <?php post_tags(); ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>