<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Core\I18n::getLocale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php theme_head(); ?>
    <link rel="stylesheet" href="<?php site_url('themes/default/style.css'); ?>">
</head>
<body>
    <div class="site-header-wrapper">
        <header class="site-header">
            <div class="brand">
                <h1 class="logo"><a href="<?php site_url(); ?>"><?php site_title(); ?></a></h1>
                <p class="tagline"><?php site_desc(); ?></p>
            </div>
            <div class="header-actions">
                <nav><?php menu('main-menu', 'site-nav'); ?></nav>
                <?php lang_switch('lang-switcher'); ?>
            </div>
        </header>
    </div>
    <main class="site-main">