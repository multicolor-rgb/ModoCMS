<?php if (!defined('IN_CMS')) die(); ?>
<!DOCTYPE html>
<html lang="<?= I18n::getLocale() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0d6efd">

    <title>
        <?php if (!empty($item['meta_title'])): ?>
            <?= Security::sanitize($item['meta_title']) ?>
        <?php elseif (!empty($item['title'])): ?>
            <?= Security::sanitize($item['title']) ?> &bull; <?= get_site_title() ?>
        <?php else: ?>
            <?= get_site_title() ?>
        <?php endif; ?>
    </title>

    <?php if (!empty($item['meta_description'])): ?>
        <meta name="description" content="<?= Security::sanitize($item['meta_description']) ?>">
    <?php else: ?>
        <meta name="description" content="<?= Security::sanitize(get_site_description()) ?>">
    <?php endif; ?>

    <link rel="preconnect" href="https://cdn.jsdelivr.net">

    <!-- Bootstrap 5.3.8 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">

    <!-- Ikony Bootstrap -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Arkusz stylów motywu -->
    <link rel="stylesheet" href="<?= get_theme_url() ?>/assets/css/style.css">

    <link rel="icon" href="<?= get_theme_url() ?>/assets/img/favicon.ico" sizes="any">

    <!-- Hook nagłówka dla wtyczek (SEO, analytics, consent mode) -->
    <?php get_header(); ?>
</head>
<body>

<a class="skip-link visually-hidden-focusable" href="#main-content">Przejdź do treści</a>

<header class="site-header sticky-top">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?= get_site_url() ?>">
                <i class="bi bi-hexagon-fill text-primary"></i>
                <span><?= Security::sanitize(get_site_title()) ?></span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#mainNav" aria-controls="mainNav"
                    aria-expanded="false" aria-label="Przełącz nawigację">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainNav">
                <div class="ms-auto">
                    <?php get_theme_menu('navbar-nav'); ?>
                </div>
            </div>
        </div>
    </nav>
</header>
