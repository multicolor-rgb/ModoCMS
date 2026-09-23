<?php require __DIR__ . '/header.php'; ?>

<div class="archive-headline">
    <h2><?= htmlspecialchars($archiveTitle ?? __('Latest Stories'), ENT_QUOTES, 'UTF-8') ?></h2>
</div>

<?php if (have_posts()): ?>
    <div class="editorial-grid">
        <?php while (have_posts()): the_post(); ?>
            <article class="editorial-card">
                <?php if (has_image()): ?>
                    <a href="<?php post_url(); ?>" class="card-media-wrapper">
                        <img src="<?php post_image(); ?>" alt="<?php post_title(); ?>" loading="lazy">
                    </a>
                <?php endif; ?>
                
                <div class="card-content">
                    <div class="card-meta">
                        <?php page_date('M d, Y'); ?> &bull; <?php page_author(); ?>
                    </div>
                    
                    <h3 class="card-title">
                        <a href="<?php post_url(); ?>"><?php post_title(); ?></a>
                    </h3>
                    
                    <p class="card-summary"><?php post_excerpt(130); ?></p>

                    <div>
                        <a href="<?php post_url(); ?>" style="color: var(--primary); font-weight: 700; text-decoration: none; font-size: 0.9rem;">
                            <?= _e('Continue reading') ?> &rarr;
                        </a>
                    </div>

                    <?php if (has_tags()): ?>
                        <div class="post-tags-list">
                            <?php post_tags(null, 'post-tags-list'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endwhile; ?>
    </div>

    <!-- Enhanced Numerical & Directional Pagination -->
    <?php if (isset($totalPages) && $totalPages > 1): ?>
        <nav class="editorial-pagination" aria-label="Pagination Navigation">
            <!-- Previous Button -->
            <?php if ($currentPage > 1): ?>
                <a href="?page=<?= $currentPage - 1 ?>" class="page-btn">&larr;</a>
            <?php else: ?>
                <span class="page-btn disabled">&larr;</span>
            <?php endif; ?>

            <!-- Page Number Links -->
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?= $i ?>" class="page-btn <?= $i === $currentPage ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <!-- Next Button -->
            <?php if ($currentPage < $totalPages): ?>
                <a href="?page=<?= $currentPage + 1 ?>" class="page-btn">&rarr;</a>
            <?php else: ?>
                <span class="page-btn disabled">&rarr;</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

<?php else: ?>
    <div style="background: #ffffff; border: 1px solid var(--border-light); border-radius: var(--radius-md); padding: 48px; text-align: center; color: var(--text-muted);">
        <p style="font-size: 1.1rem;"><?= _e('No posts published yet.') ?></p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>