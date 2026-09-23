<?php require __DIR__ . '/header.php'; ?>

<div class="page-title-section">
    <h2 class="page-main-title"><?= htmlspecialchars($archiveTitle ?? __('Latest posts'), ENT_QUOTES, 'UTF-8') ?></h2>
</div>

<?php if (have_posts()): ?>
    <div class="posts-grid">
        <?php while (have_posts()): the_post(); ?>
            <article class="post-card">
                <?php if (has_image()): ?>
                    <a href="<?php post_url(); ?>">
                        <img class="post-card-thumb" src="<?php post_image(); ?>" alt="<?php post_title(); ?>">
                    </a>
                <?php endif; ?>
                <div class="post-card-body">
                    <div class="post-meta">
                        <?php page_date(); ?> &bull; <?php page_author(); ?>
                    </div>
                    <h3 class="post-card-title">
                        <a href="<?php post_url(); ?>"><?php post_title(); ?></a>
                    </h3>
                    <p class="post-card-excerpt"><?php post_excerpt(120); ?></p>
                    
                    <a href="<?php post_url(); ?>" class="read-more-link">
                        <?= _e('Read more') ?> &rarr;
                    </a>

                    <?php post_tags(null, 'post-tags'); ?>
                </div>
            </article>
        <?php endwhile; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?= $i ?>" class="<?= $i === $currentPage ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div style="background: var(--bg-surface); padding: 32px; border-radius: var(--radius-md); border: 1px solid var(--border); text-align: center; color: var(--text-muted);">
        <p><?= _e('No posts found.') ?></p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>