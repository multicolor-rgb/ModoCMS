<?php
/**
 * Modo Bootstrap Dark — header.php
 * Responsive Bootstrap 5 (dark) theme for Modo CMS (full theme API).
 */
if (!function_exists('site_title')) { die('Modo CMS theme'); }
$themeUrl = htmlspecialchars(get_theme_url(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Core\I18n::getLocale(), ENT_QUOTES, 'UTF-8') ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#7c3aed">

    <?php theme_head(); ?>

    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= $themeUrl ?>/assets/css/style.css">
</head>
<body class="modo-theme modo-bootstrap-dark d-flex flex-column min-vh-100">

    <a class="skip-link visually-hidden-focusable" href="#main-content"><?= _e('Przejdź do treści') ?></a>

    <header class="site-header sticky-top">
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary-subtle">
            <div class="container">
                <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?= htmlspecialchars(site_url('', false), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="brand-dot"></span>
                    <?= site_logo(false) ?>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                        aria-controls="mainNav" aria-expanded="false" aria-label="<?= _e('Przełącz nawigację') ?>">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="mainNav">
                    <?php get_theme_menu('navbar-nav ms-auto mb-2 mb-lg-0'); ?>
                    <div class="ms-lg-3 mt-2 mt-lg-0 d-flex">
                        <?php lang_switch('lang-switcher list-unstyled d-flex gap-2 mb-0'); ?>
                    </div>
                </div>
            </div>
        </nav>
    </header>

    <main id="main-content" class="flex-grow-1">