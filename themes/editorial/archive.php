<?php
require __DIR__ . '/header.php';
$curPage = isset($currentPage) ? (int)$currentPage : 1;
$totPages = isset($totalPages) ? (int)$totalPages : 1;
$first = true;
?>

<section class="archive-headline text-center border-bottom py-5">
    <div class="container">
        <span class="text-uppercase small fw-semibold text-danger d-block mb-2"><?= _e('Dział') ?></span>
        <h1 class="editorial-title mb-0">
            <?= !empty($archiveTitle) ? htmlspecialchars($archiveTitle, ENT_QUOTES, 'UTF-8') : _e('Wszystkie wpisy') ?>
        </h1>
    </div>
</section>

<div class="container py-5">
    <?php if (have_posts()): ?>
        <div class="row g-4">
            <?php while (have_posts()): the_post(); ?>
                <?php if ($first): $first = false; ?>
                    <div class="col-12">
                        <article class="editorial-featured row g-0 align-items-center">
                            <?php if (has_image()): ?>
                                <div class="col-md-6">
                                    <a href="<?php post_url(); ?>">
                                        <img src="<?php post_image(); ?>" class="editorial-featured-img" alt="<?php post_title(); ?>">
                                    </a>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-<?= has_image() ? '6' : '12' ?>">
                                <div class="p-4 p-lg-5">
                                    <div class="post-meta text-uppercase small fw-semibold text-body-secondary mb-2">
                                        <?= _e('Wyróżnione') ?> &bull; <?php page_date(); ?>
                                    </div>
                                    <h2 class="editorial-title h1 mb-3">
                                        <a href="<?php post_url(); ?>" class="text-decoration-none text-body stretched-link"><?php post_title(); ?></a>
                                    </h2>
                                    <p class="text-body-secondary mb-0"><?php post_excerpt(200); ?></p>
                                </div>
                            </div>
                        </article>
                    </div>
                <?php else: ?>
                    <div class="col-md-6 col-lg-4">
                        <article class="editorial-card h-100">
                            <?php if (has_image()): ?>
                                <a href="<?php post_url(); ?>">
                                    <img src="<?php post_image(); ?>" class="editorial-card-img" alt="<?php post_title(); ?>" loading="lazy">
                                </a>
                            <?php endif; ?>
                            <div class="pt-3">
                                <div class="post-meta text-uppercase small fw-semibold text-body-secondary mb-2">
                                    <?php page_date('M d, Y'); ?>
                                </div>
                                <h3 class="editorial-title-mini h5 mb-2">
                                    <a href="<?php post_url(); ?>" class="text-decoration-none text-body stretched-link"><?php post_title(); ?></a>
                                </h3>
                                <p class="text-body-secondary small mb-0"><?php post_excerpt(110); ?></p>
                            </div>
                        </article>
                    </div>
                <?php endif; ?>
            <?php endwhile; ?>
        </div>

        <?php if ($totPages > 1): ?>
            <nav class="mt-5" aria-label="<?= _e('Paginacja') ?>">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= ($curPage <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= max(1, $curPage - 1) ?>">&laquo;</a>
                    </li>
                    <?php for ($i = 1; $i <= $totPages; $i++): ?>
                        <li class="page-item <?= ($i === $curPage) ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($curPage >= $totPages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= min($totPages, $curPage + 1) ?>">&raquo;</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>

    <?php else: ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox fs-1 text-body-tertiary d-block mb-3"></i>
            <p class="text-body-secondary"><?= _e('Nie znaleziono żadnych wpisów.') ?></p>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>