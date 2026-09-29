<?php
if (!defined('IN_ADMIN')) {
    define('IN_ADMIN', true);
}
require_once __DIR__ . '/../../core/bootstrap.php';

use Core\Auth;
use Core\Hooks;
use Core\I18n;
use Core\Router;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);

// Dynamically compute the admin directory base URL for asset resolution
$scriptDir = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$adminBase = ($scriptDir !== '' ? '/' . $scriptDir : '') . '/';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::getAdminLocale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?= htmlspecialchars($adminBase, ENT_QUOTES, 'UTF-8') ?>">
    <title>Modo CMS &bull; <?= _e('Dashboard') ?></title>
    
    <!-- Instant Dark Mode Init Script (Prevents FOUC) -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('clean_cms_theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>

    <link rel="stylesheet" href="assets/css/admin.css">
    <?php Hooks::doAction('admin_head'); ?>
</head>
<body>
    <aside id="admin-sidebar">
        <div class="sidebar-header">
            <div class="brand-badge">C</div>
            <span class="brand-name">Modo CMS</span>
        </div>
        <nav class="sidebar-nav">
            <span class="nav-section-title"><?= _e('System Overview') ?></span>
            
            <a href="index.php" class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span class="nav-text"><?= _e('Dashboard') ?></span>
            </a>

            <?php if (Auth::can('manage_pages')): ?>
                <a href="pages.php" class="nav-link <?= in_array($currentPage, ['pages.php', 'page-edit.php']) ? 'active' : '' ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span class="nav-text"><?= _e('Content') ?></span>
                </a>
            <?php endif; ?>

            <a href="media.php" class="nav-link <?= $currentPage === 'media.php' ? 'active' : '' ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <span class="nav-text"><?= _e('Media') ?></span>
            </a>

            <?php if (Auth::can('manage_settings')): ?>
                <a href="menus.php" class="nav-link <?= $currentPage === 'menus.php' ? 'active' : '' ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
                    <span class="nav-text"><?= _e('Navigation') ?></span>
                </a>
                <a href="theme-edit.php" class="nav-link <?= $currentPage === 'theme-edit.php' ? 'active' : '' ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                    <span class="nav-text"><?= _e('Theme Editor') ?></span>
                </a>
                <a href="plugins.php" class="nav-link <?= ($currentPage === 'plugins.php' && empty($_GET['id'])) ? 'active' : '' ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
                    <span class="nav-text"><?= _e('Plugins') ?></span>
                </a>
                <a href="users.php" class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <span class="nav-text"><?= _e('Users') ?></span>
                </a>
                <a href="languages.php" class="nav-link <?= $currentPage === 'languages.php' ? 'active' : '' ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
                    <span class="nav-text"><?= _e('Languages') ?></span>
                </a>


      <a href="backup.php" class="nav-link <?= (isset($currentPage) ? $currentPage : basename($_SERVER['PHP_SELF'])) === 'backup.php' ? 'active' : '' ?>">
    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
    </svg>
    <span class="nav-text"><?= _e('Backups') ?></span>
</a>

                <a href="settings.php" class="nav-link <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span class="nav-text"><?= _e('Settings') ?></span>
                </a>
            <?php endif; ?>

            <?php 
            // Dynamic GetSimple & Plugin Navigation Items
            $hasPluginItems = !empty(\Core\GSRegistry::$adminSidebarItems) || Hooks::hasAction('plugins-sidebar');
            if ($hasPluginItems && Auth::can('manage_settings')): 
            ?>
                <span class="nav-section-title" style="margin-top: 16px;"><?= _e('Extensions') ?></span>
                <?php
                if (function_exists('render_admin_plugin_navigation')) {
                    render_admin_plugin_navigation('nav-link');
                } else {
                    $activeId = $_GET['id'] ?? '';
                    foreach (\Core\GSRegistry::$adminSidebarItems as $item) {
                        $isActive = ($currentPage === 'plugins.php' && $activeId === $item['id']);
                        $url = 'plugins.php?id=' . urlencode($item['id']);
                        if (!empty($item['action']) && $item['action'] !== $item['id']) {
                            $url .= '&action=' . urlencode($item['action']);
                        }
                        ?>
                        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" class="nav-link <?= $isActive ? 'active' : '' ?>">
                            <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            <span class="nav-text"><?= htmlspecialchars(function_exists('i18n_r') ? i18n_r($item['title']) : _e($item['title']), ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                        <?php
                    }
                    Hooks::doAction('plugins-sidebar');
                }
                ?>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <button id="collapse-trigger" class="collapse-trigger" type="button" title="Toggle Sidebar">
                <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
            </button>
        </div>
    </aside>

    <div id="main-wrapper">
        <header id="topbar">
            <div>
                <a href="<?= site_url('', false) ?>" target="_blank" class="btn btn-secondary" style="font-size: 12px; padding: 5px 10px;"><?= _e('View Website') ?> &nearr;</a>
            </div>
            <div style="display: flex; align-items: center; gap: 14px;">
                <!-- Dark Mode Toggle Button -->
                <button type="button" id="theme-toggle" class="theme-toggle-btn" title="Toggle Light/Dark Theme">
                    <svg id="theme-icon-moon" style="width: 16px; height: 16px; display: none;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    <svg id="theme-icon-sun" style="width: 16px; height: 16px; display: none;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </button>

                <a href="profile.php" style="text-decoration:none; color:inherit;" class="user-profile">
                    <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?></div>
                    <span><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="badge" style="background:#e0e7ff; color:#3730a3; font-size:11px;"><?= strtoupper(Auth::role()) ?></span>
                </a>
                <a href="logout.php" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;"><?= _e('Sign Out') ?></a>
            </div>
        </header>
        <main class="content-area">