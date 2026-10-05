<?php
require __DIR__ . '/header.php';
$curPage = isset($currentPage) ? (int)$currentPage : 1;
$totPages = isset($totalPages) ? (int)$totalPages : 1;
?>

<section class="archive-hero page-hero-gradient py-5">
    <div class="container text-center py-3">
        <span class="badge text-bg-light mb-2"><?= _e('Blog') ?></span>
        <h1 class="display-5 fw-bold text-white mb-2">
            <?= !empty($archiveTitle) ? htmlspecialchars($archiveTitle, ENT_QUOTES, 'UTF-8') : _e('Wszystkie wpisy') ?>
        </h1>
        <?php if (site_desc(false) !== ''): ?>
            <p class="text-white-50 mb-0"><?= site_desc(false) ?></p>
        <?php endif; ?>
    </div>
</section>

<div class="container py-5">
    <?php if (have_posts()): ?>
        <div class="row g-4">
            <?php while (have_posts()): the_post(); ?>
                <div class="col-md-6 col-lg-4">
                    <article class="card h-100 border-secondary-subtle post-card">
                        <?php if (has_image()): ?>
                            <a href="<?php post_url(); ?>" class="post-card-media">
                                <img src="<?php post_image(); ?>" class="card-img-top post-card-img" alt="<?php post_title(); ?>" loading="lazy">
                            </a>
                        <?php else: ?>
                            <a href="<?php post_url(); ?>" class="post-card-media post-card-img-placeholder d-flex align-items-center justify-content-center">
                                <i class="bi bi-image fs-1 text-secondary"></i>
                            </a>
                        <?php endif; ?>
                        <div class="card-body d-flex flex-column">
                            <div class="text-secondary small mb-2">
                                <i class="bi bi-calendar3 me-1"></i> <?php page_date(); ?>
                                <span class="mx-1">&bull;</span> <?php page_author(); ?>
                            </div>
                            <h2 class="h5 card-title fw-bold">
                                <a href="<?php post_url(); ?>" class="stretched-link text-decoration-none link-light"><?php post_title(); ?></a>
                            </h2>
                            <p class="card-text text-secondary flex-grow-1"><?php post_excerpt(120); ?></p>
                            <?php if (has_tags()): ?>
                                <div class="mt-2"><?php post_tags(null, 'post-tags d-flex flex-wrap gap-2'); ?></div>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
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
            <i class="bi bi-inbox fs-1 text-secondary d-block mb-3"></i>
            <p class="text-secondary"><?= _e('Nie znaleziono żadnych wpisów.') ?></p>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>