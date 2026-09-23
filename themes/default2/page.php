<?php if (!defined('IN_CMS')) die(); ?>
<?php include __DIR__ . '/header.php'; ?>

<main id="main-content" class="site-content flex-grow-1">

    <?php if (!empty($item['featured_image'])): ?>
        <div class="page-hero" style="background-image: url('<?= htmlspecialchars($item['featured_image'], ENT_QUOTES, 'UTF-8') ?>');">
            <div class="page-hero-overlay"></div>
            <div class="container position-relative">
                <h1 class="page-hero-title text-white fw-bold"><?= Security::sanitize($item['title']) ?></h1>
            </div>
        </div>
    <?php endif; ?>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-9">

                <?php if (empty($item['featured_image'])): ?>
                    <h1 class="page-title fw-bold mb-4"><?= Security::sanitize($item['title']) ?></h1>
                <?php endif; ?>

                <?php if (!empty($item['created_at'])): ?>
                    <p class="text-muted small mb-4">
                        <i class="bi bi-calendar3 me-1"></i>
                        <?= date('d.m.Y', strtotime($item['created_at'])) ?>
                    </p>
                <?php endif; ?>

                <article class="page-article entry-content">
                    <?= $item['content'] ?>
                </article>

            </div>
        </div>
    </div>

</main>

<?php include __DIR__ . '/footer.php'; ?>
