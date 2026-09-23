<?php require __DIR__ . '/header.php'; ?>

<div class="not-found-wrapper">
    <div class="not-found-code">404</div>
    <h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 8px;"><?= _e('Page not found.') ?></h2>
    <p style="color: var(--text-muted); margin-bottom: 24px; font-size: 0.95rem;">
        <?= _e('The page you are looking for might have been moved, deleted, or does not exist.') ?>
    </p>
    <a href="<?php site_url(); ?>" class="read-more-link" style="font-size: 1rem;">
        &larr; <?= _e('Back to homepage') ?>
    </a>
</div>

<?php require __DIR__ . '/footer.php'; ?>