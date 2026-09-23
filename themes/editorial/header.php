<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Core\I18n::getLocale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php theme_head(); ?>
    <link rel="stylesheet" href="<?php site_url('themes/editorial/style.css'); ?>">
</head>
<body>
    <div class="editorial-header-wrapper">
        <header class="editorial-header">
            <div>
                <div class="brand-title">
                    <a href="<?php site_url(); ?>">
                        <span>&bull;</span> <?php site_title(); ?>
                    </a>
                </div>
                <div class="brand-desc"><?php site_desc(); ?></div>
            </div>
            <div class="header-right">
                <nav><?php menu('main-menu', 'editorial-nav'); ?></nav>
                <?php lang_switch('editorial-lang'); ?>
            </div>
        </header>
    </div>
    <main class="editorial-main">