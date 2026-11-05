<?php
declare(strict_types=1);

namespace Core;

/**
 * RSS 2.0 feed for published blog posts.
 *
 * The feed honours the current frontend language and the site/installation
 * subdirectory. It is generated on demand and excluded from the page cache.
 */
final class Feed
{
    private const LIMIT = 30;

    public static function url(?string $locale = null): string
    {
        $locale = $locale ?? I18n::getLocale();
        $prefix = '';
        if (Router::getOption('multilingual_frontend', '0') === '1'
            && $locale !== ''
            && $locale !== I18n::getDefaultLocale()) {
            $prefix = '/' . rawurlencode($locale);
        }

        return rtrim(Router::getSiteUrl(), '/') . $prefix . '/feed.xml';
    }

    public static function render(?string $locale = null): string
    {
        $locale = $locale ?? I18n::getLocale();
        $siteUrl = rtrim(Router::getSiteUrl(), '/');
        $siteTitle = (string)Router::getOption('site_title', 'Modo CMS');
        $description = (string)Router::getOption('site_description', '');
        $postsSlug = Router::getPostsPageSlug();
        $langPrefix = '';

        if (Router::getOption('multilingual_frontend', '0') === '1'
            && $locale !== ''
            && $locale !== I18n::getDefaultLocale()) {
            $langPrefix = '/' . rawurlencode($locale);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT id, title, slug, content, created_at, updated_at, published_at, lang
            FROM pages
            WHERE type = 'post'
              AND status = 'published'
              AND deleted_at IS NULL
              AND (published_at IS NULL OR published_at <= datetime('now'))
              AND (lang = :lang OR lang = '')
            ORDER BY COALESCE(published_at, created_at) DESC, id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':lang', $locale, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', self::LIMIT, \PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll() ?: [];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= "  <channel>\n";
        $xml .= '    <title>' . self::xml($siteTitle) . "</title>\n";
        $xml .= '    <link>' . self::xml($siteUrl . $langPrefix . '/') . "</link>\n";
        $xml .= '    <description>' . self::xml($description !== '' ? $description : $siteTitle) . "</description>\n";
        $xml .= '    <language>' . self::xml($locale !== '' ? $locale : 'en') . "</language>\n";
        $xml .= '    <atom:link href="' . self::xml(self::url($locale)) . '" rel="self" type="application/rss+xml" />' . "\n";

        foreach ($posts as $post) {
            $url = $siteUrl . $langPrefix . '/' . rawurlencode($postsSlug) . '/' . rawurlencode((string)$post['slug']);
            $date = (string)($post['published_at'] ?: $post['created_at']);
            $timestamp = strtotime($date) ?: time();
            $content = (string)($post['content'] ?? '');

            $xml .= "    <item>\n";
            $xml .= '      <title>' . self::xml((string)$post['title']) . "</title>\n";
            $xml .= '      <link>' . self::xml($url) . "</link>\n";
            $xml .= '      <guid isPermaLink="true">' . self::xml($url) . "</guid>\n";
            $xml .= '      <pubDate>' . date(DATE_RSS, $timestamp) . "</pubDate>\n";
            $xml .= '      <description>' . self::xml(self::excerpt($content)) . "</description>\n";
            $xml .= '      <content:encoded><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $content) . "]]></content:encoded>\n";
            $xml .= "    </item>\n";
        }

        $xml .= "  </channel>\n</rss>\n";

        return $xml;
    }

    private static function excerpt(string $html, int $length = 240): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string)preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) > $length) {
            $text = rtrim(mb_substr($text, 0, $length - 1)) . '…';
        }

        return $text;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
