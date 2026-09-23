<?php require __DIR__ . '/header.php'; ?>

<div class="article-container">
    <?php if (has_image()): ?>
        <img class="article-hero-image" src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
    <?php endif; ?>
    <h1 class="article-title"><?php page_title(); ?></h1>
    <div class="prose-content">
        <?php page_content(); ?>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>