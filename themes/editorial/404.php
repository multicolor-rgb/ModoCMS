<?php require __DIR__ . '/header.php'; ?>

<div style="text-align: center; padding: 70px 20px;">
    <h1 style="font-size: 6rem; font-weight: 900; color: var(--primary); line-height: 1;">404</h1>
    <h2 style="font-size: 1.8rem; font-weight: 800; margin: 16px 0; color: var(--text-heading);"><?= _e('Page not found') ?></h2>
    <p style="color: var(--text-muted); max-width: 460px; margin: 0 auto 28px;"><?= _e('The requested article or page cannot be found or was moved to another location.') ?></p>
    <a href="<?php site_url(); ?>" class="page-btn" style="display: inline-flex; width: auto; padding: 0 24px;">&larr; <?= _e('Return to Home') ?></a>
</div>

<?php require __DIR__ . '/footer.php'; ?>