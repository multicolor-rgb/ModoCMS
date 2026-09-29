<?php
/**
 * Plugin Name: Hello World Filter
 * Description: Demonstrates hooks and content filtration in Modo CMS.
 */
use Core\Hooks;

Hooks::addFilter('the_content', function(string $content) {
    if (basename($_SERVER['PHP_SELF']) !== 'admin') {
        return $content . '<p style="margin-top:2rem; font-size:0.85rem; color:#64748b; font-style:italic;">[Powered by Modo CMS]</p>';
    }
    return $content;
});
