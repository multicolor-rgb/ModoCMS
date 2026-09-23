<?php require __DIR__ . '/header.php'; ?>

<article class="single-editorial-article">
    <?php if (has_image()): ?>
        <div class="single-hero-cover">
            <img src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
            <div class="single-hero-overlay"></div>
        </div>
    <?php endif; ?>

    <div class="single-editorial-body">
        <h1 class="single-title"><?php page_title(); ?></h1>
        <div class="single-prose">
            <?php page_content(); ?>
        </div>
    </div>
</article>

<?php require __DIR__ . '/footer.php'; ?>