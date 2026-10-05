<?php require __DIR__ . '/header.php'; ?>

<?php if (has_image()): ?>
    <section class="page-hero position-relative">
        <img class="page-hero-img" src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
        <div class="page-hero-overlay"></div>
        <div class="container page-hero-inner">
            <h1 class="editorial-title text-white mb-0"><?php page_title(); ?></h1>
        </div>
    </section>
<?php endif; ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <?php if (!has_image()): ?>
                <h1 class="editorial-title mb-4"><?php page_title(); ?></h1>
            <?php endif; ?>
            <article class="entry-content">
                <?php page_content(); ?>
            </article>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>