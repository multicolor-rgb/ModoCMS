<?php
/**
 * Modo Editorial — header.php
 * Magazine-style responsive Bootstrap 5 theme for Modo CMS (full theme API).
 */
if (!function_exists('site_title')) { die('Modo CMS theme'); }
$themeUrl = htmlspecialchars(get_theme_url(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Core\I18n::getLocale(), ENT_QUOTES, 'UTF-8') ?>" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111827">

    <?php theme_head(); ?>

    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= $themeUrl ?>/assets/css/style.css">
</head>
<body class="modo-theme modo-editorial d-flex flex-column min-vh-100">

    <a class="skip-link visually-hidden-focusable" href="#main-content"><?= _e('Przejdź do treści') ?></a>

    <header class="site-header">
        <div class="masthead text-center py-4 border-bottom">
            <div class="container">
                <a class="masthead-title d-inline-block text-decoration-none" href="<?= htmlspecialchars(site_url('', false), ENT_QUOTES, 'UTF-8') ?>">
                    <?= site_logo(false) ?>
                </a>
                <?php if (site_desc(false) !== ''): ?>
                    <p class="masthead-tagline mb-0"><?= site_desc(false) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <nav class="navbar navbar-expand-lg editorial-navbar border-bottom sticky-top">
            <div class="container">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                        aria-controls="mainNav" aria-expanded="false" aria-label="<?= _e('Przełącz nawigację') ?>">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="mainNav">
                    <?php get_theme_menu('navbar-nav mx-auto mb-2 mb-lg-0'); ?>
                    <div class="d-flex justify-content-center">
                        <?php lang_switch('lang-switcher list-unstyled d-flex gap-2 mb-0'); ?>
                    </div>
                </div>
            </div>
        </nav>
    </header>

    <main id="main-content" class="flex-grow-1">