<?php require __DIR__ . '/header.php'; ?>

<div class="container py-5 text-center">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="error-code fw-bold text-primary mb-3">404</div>
            <h1 class="fw-bold mb-3"><?= _e('Strona nie została znaleziona') ?></h1>
            <p class="text-secondary mb-4">
                <?= _e('Szukana strona mogła zostać przeniesiona, usunięta lub nigdy nie istniała.') ?>
            </p>
            <a href="<?= htmlspecialchars(site_url('', false), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-lg">
                <i class="bi bi-house-door me-1"></i> <?= _e('Wróć na stronę główną') ?>
            </a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>