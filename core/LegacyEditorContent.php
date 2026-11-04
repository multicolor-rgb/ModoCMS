<?php
declare(strict_types=1);

namespace Core;

/**
 * Class LegacyEditorContent
 *
 * Read-only renderer for content saved by the removed Editor.js block editor.
 * It is used by the one-time migration and kept as a safety net for old
 * revisions or restored backups. No editor UI, settings or assets live here.
 */
final class LegacyEditorContent
{
    private static bool $booted = false;

    /**
     * Registers the frontend content filter for legacy JSON documents.
     */
    public static function init(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        Hooks::addFilter('the_content', [self::class, 'render'], 1);
    }

    /**
     * Decodes an Editor.js document, or null when the value is regular HTML.
     *
     * @return array<string,mixed>|null
     */
    public static function decode(?string $content): ?array
    {
        if ($content === null) {
            return null;
        }

        $trimmed = trim($content);
        // An Editor.js document is a JSON object that always carries "blocks".
        if ($trimmed === '' || $trimmed[0] !== '{' || !str_contains($trimmed, '"blocks"')) {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded) || !isset($decoded['blocks']) || !is_array($decoded['blocks'])) {
            return null;
        }

        foreach ($decoded['blocks'] as $block) {
            if (!is_array($block) || !isset($block['type']) || !is_string($block['type'])) {
                return null;
            }
        }

        return $decoded;
    }

    /**
     * Whether the given value is an Editor.js document.
     */
    public static function isEditorJs(?string $content): bool
    {
        return self::decode($content) !== null;
    }

    /**
     * Renders an Editor.js document to HTML. Regular HTML is returned as-is,
     * which makes this safe to use from any content filter or template tag.
     */
    public static function render(?string $content): string
    {
        $document = self::decode($content);
        if ($document === null) {
            return (string) $content;
        }

        return self::renderDocument($document);
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    /**
     * Renders every block of a decoded document.
     *
     * @param array<string,mixed> $document
     */
    public static function renderDocument(array $document): string
    {
        $html = [];
        foreach ((array) ($document['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $rendered = self::renderBlock($block);
            if (trim($rendered) !== '') {
                $html[] = $rendered;
            }
        }

        return trim(implode("\n", $html));
    }

    /**
     * Renders a single block to HTML.
     *
     * @param array<string,mixed> $block
     */
    private static function renderBlock(array $block): string
    {
        $type = (string) ($block['type'] ?? '');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        return match ($type) {
            'paragraph'  => self::renderParagraph($data),
            'header'     => self::renderHeader($data),
            'list'       => self::renderList($data),
            'checklist'  => self::renderChecklist($data),
            'quote'      => self::renderQuote($data),
            'warning'    => self::renderWarning($data),
            'delimiter'  => '<hr class="modo-delimiter">',
            'code'       => self::renderCode($data),
            'table'      => self::renderTable($data),
            'image'      => self::renderImage($data),
            'embed'      => self::renderEmbed($data),
            'grid'       => self::renderGrid($data),
            'raw'        => (string) ($data['html'] ?? ''),
            'shortcode'  => trim((string) ($data['code'] ?? '')),
            default      => self::renderFallback($data),
        };
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderParagraph(array $data): string
    {
        $text = self::inline((string) ($data['text'] ?? ''));
        return $text === '' ? '' : '<p>' . $text . '</p>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderHeader(array $data): string
    {
        $text = self::inline((string) ($data['text'] ?? ''));
        if ($text === '') {
            return '';
        }
        $level = (int) ($data['level'] ?? 2);
        if ($level < 1 || $level > 6) {
            $level = 2;
        }

        return '<h' . $level . '>' . $text . '</h' . $level . '>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderList(array $data): string
    {
        $tag = (string) ($data['style'] ?? 'unordered') === 'ordered' ? 'ol' : 'ul';
        $items = [];

        foreach ((array) ($data['items'] ?? []) as $item) {
            if (is_array($item)) {
                // @editorjs/list v2 keeps nested entries inside the same item.
                $content = self::inline((string) ($item['content'] ?? ''));
                $nested = [];
                foreach ((array) ($item['items'] ?? []) as $child) {
                    $childText = is_array($child)
                        ? self::inline((string) ($child['content'] ?? ''))
                        : self::inline((string) $child);
                    if ($childText !== '') {
                        $nested[] = '<li>' . $childText . '</li>';
                    }
                }
                $html = $content;
                if ($nested !== []) {
                    $html .= '<' . $tag . '>' . implode('', $nested) . '</' . $tag . '>';
                }
                if ($html !== '') {
                    $items[] = '<li>' . $html . '</li>';
                }
                continue;
            }

            $text = self::inline((string) $item);
            if ($text !== '') {
                $items[] = '<li>' . $text . '</li>';
            }
        }

        return $items === [] ? '' : '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderChecklist(array $data): string
    {
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = self::inline((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $checked = !empty($item['checked']);
            $items[] = '<li class="' . ($checked ? 'is-checked' : 'is-unchecked') . '">'
                . '<span class="modo-checklist__mark" aria-hidden="true">' . ($checked ? '&#10004;' : '&#9633;') . '</span> '
                . $text . '</li>';
        }

        return $items === [] ? '' : '<ul class="modo-checklist">' . implode('', $items) . '</ul>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderQuote(array $data): string
    {
        $text = self::inline((string) ($data['text'] ?? ''));
        if ($text === '') {
            return '';
        }
        $caption = self::inline((string) ($data['caption'] ?? ''));

        return '<blockquote><p>' . $text . '</p>'
            . ($caption !== '' ? '<cite>' . $caption . '</cite>' : '')
            . '</blockquote>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderWarning(array $data): string
    {
        $title = self::inline((string) ($data['title'] ?? ''));
        $message = self::inline((string) ($data['message'] ?? ''));
        if ($title === '' && $message === '') {
            return '';
        }

        return '<div class="modo-warning">'
            . ($title !== '' ? '<strong class="modo-warning__title">' . $title . '</strong>' : '')
            . ($message !== '' ? '<p>' . $message . '</p>' : '')
            . '</div>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderCode(array $data): string
    {
        $code = rtrim((string) ($data['code'] ?? ''), "\n");
        return $code === '' ? '' : '<pre><code>' . self::escape($code) . '</code></pre>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderTable(array $data): string
    {
        $content = (array) ($data['content'] ?? []);
        if ($content === []) {
            return '';
        }
        $withHeadings = !empty($data['withHeadings']);

        $rows = [];
        foreach ($content as $index => $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            $cells = [];
            foreach ($row as $cell) {
                $tag = ($withHeadings && $index === 0) ? 'th' : 'td';
                $cells[] = '<' . $tag . '>' . self::inline((string) $cell) . '</' . $tag . '>';
            }
            $rows[] = '<tr>' . implode('', $cells) . '</tr>';
        }

        if ($rows === []) {
            return '';
        }

        $head = '';
        if ($withHeadings) {
            $head = '<thead>' . array_shift($rows) . '</thead>';
        }
        $body = $rows !== [] ? '<tbody>' . implode('', $rows) . '</tbody>' : '';

        return '<table class="modo-table">' . $head . $body . '</table>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderImage(array $data): string
    {
        $url = '';
        if (isset($data['file']) && is_array($data['file'])) {
            $url = (string) ($data['file']['url'] ?? '');
        }
        if ($url === '') {
            $url = (string) ($data['url'] ?? '');
        }
        $url = self::safeUrl($url);
        if ($url === '') {
            return '';
        }

        $classes = ['modo-image'];
        foreach (['withBorder' => 'is-bordered', 'stretched' => 'is-stretched', 'withBackground' => 'has-background'] as $key => $class) {
            if (!empty($data[$key])) {
                $classes[] = $class;
            }
        }

        $captionRaw = (string) ($data['caption'] ?? '');
        $caption = self::inline($captionRaw);
        $alt = trim(preg_replace('/\s+/', ' ', strip_tags($captionRaw)) ?? '');

        $image = '<img src="' . self::escape($url) . '" alt="' . self::escape($alt) . '" loading="lazy">';

        return '<figure class="' . implode(' ', $classes) . '">' . $image
            . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '')
            . '</figure>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderEmbed(array $data): string
    {
        $source = trim((string) ($data['embed'] ?? ''));
        if ($source === '' || !preg_match('#^https?://#i', $source)) {
            return '';
        }

        $caption = self::inline((string) ($data['caption'] ?? ''));

        return '<div class="modo-embed"><div class="modo-embed__frame">'
            . '<iframe src="' . self::escape($source) . '" loading="lazy" allowfullscreen '
            . 'referrerpolicy="strict-origin-when-cross-origin" frameborder="0"></iframe>'
            . '</div>'
            . ($caption !== '' ? '<p class="modo-embed__caption">' . $caption . '</p>' : '')
            . '</div>';
    }

    /**
     * Renders the grid/collage block produced by the "Insert Grid" tool. The
     * markup is generated by CssFramework so it always matches the framework
     * selected in the settings.
     *
     * @param array<string,mixed> $data
     */
    private static function renderGrid(array $data): string
    {
        $framework = (string) ($data['framework'] ?? '');
        if ($framework === '' || CssFramework::get($framework) === null) {
            $framework = CssFramework::active();
        }
        if ($framework === CssFramework::NONE || $framework === 'custom') {
            return '';
        }

        $contents = [];
        foreach ((array) ($data['contents'] ?? []) as $line) {
            $contents[] = self::inline((string) $line);
        }

        return CssFramework::gridMarkup([
            'framework'  => $framework,
            'columns'    => (int) ($data['columns'] ?? 2),
            'breakpoint' => (string) ($data['breakpoint'] ?? ''),
            'container'  => !empty($data['container']),
            'contents'   => $contents,
        ]);
    }

    /**
     * Best effort rendering of an unknown block: only plain text is emitted so
     * no raw JSON ever leaks into the frontend.
     *
     * @param array<string,mixed> $data
     */
    private static function renderFallback(array $data): string
    {
        foreach (['text', 'message', 'caption'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                return self::renderParagraph(['text' => $data[$key]]);
            }
        }

        return '';
    }

    // ---------------------------------------------------------------------
    // Text / inline markup
    // ---------------------------------------------------------------------

    /**
     * Renders inline markup into safe HTML.
     *
     * Editor.js stores block text as HTML innerHTML, so the tags its inline
     * toolbar can produce (b, i, u, s, mark, code, a, br) are brought back
     * after escaping - every other tag is dropped. Markdown-ish markers
     * (**bold**, *italic*, ~~strike~~) are supported as well.
     */
    private static function inline(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Script/style content must never survive, not even as text.
        $clean = (string) preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $text);
        $escaped = self::escape($clean);

        // Restores the whitelisted tags with rebuilt (and therefore safe)
        // attributes; anything else stays escaped and is shown as plain text.
        $escaped = (string) preg_replace_callback(
            '#&lt;(/?)(strong|em|br|mark|code|b|i|u|s|a)(?=[\s/]|&gt;)((?:(?!&lt;|&gt;).)*)&gt;#is',
            static function (array $match): string {
                $tag = strtolower($match[2]);
                if ($tag === 'br') {
                    return '<br>';
                }
                if ($match[1] === '/') {
                    return '</' . $tag . '>';
                }

                $attrs = '';
                // Decoding the attribute block is safe: the tag is rebuilt from
                // scratch below, so no event handler or style can pass through.
                $raw = html_entity_decode((string) $match[3], ENT_QUOTES, 'UTF-8');

                if ($tag === 'a') {
                    if (preg_match('/\shref\s*=\s*"([^"]*)"/i', $raw, $href)
                        || preg_match("/\shref\s*=\s*'([^']*)'/i", $raw, $href)) {
                        $url = self::safeUrl(trim($href[1]));
                        if ($url !== '') {
                            $attrs = ' href="' . self::escape($url) . '" rel="noopener noreferrer"';
                        }
                    }
                } elseif ($tag === 'mark' || $tag === 'code') {
                    $needle = $tag === 'mark' ? 'cdx-marker' : 'inline-code';
                    if (preg_match('/\sclass\s*=\s*"([^"]*)"/i', $raw, $class)
                        && str_contains(strtolower($class[1]), $needle)) {
                        $attrs = ' class="' . $needle . '"';
                    }
                }

                return '<' . $tag . $attrs . '>';
            },
            $escaped
        );

        $escaped = (string) preg_replace('#\*\*(.+?)\*\*#s', '<strong>$1</strong>', $escaped);
        $escaped = (string) preg_replace('#(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])#s', '<em>$1</em>', $escaped);
        $escaped = (string) preg_replace('#~~(.+?)~~#s', '<s>$1</s>', $escaped);

        return trim($escaped);
    }

    /**
     * Escapes a value for HTML output.
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Filters a URL coming from the editor: relative paths and http(s) (plus
     * inline images) are allowed, everything else is dropped.
     */
    private static function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $match)) {
            $scheme = strtolower($match[1]);
            if ($scheme === 'data') {
                return str_starts_with(strtolower($url), 'data:image/') ? $url : '';
            }
            if ($scheme !== 'http' && $scheme !== 'https') {
                return '';
            }
        }

        return $url;
    }

    // ---------------------------------------------------------------------
    // Template tag helpers
    // ---------------------------------------------------------------------

    /**
     * Renders a content value and strips it down to plain text (used by
     * post_excerpt() and similar template tags).
     */
    public static function toPlainText(?string $content, int $limit = 0): string
    {
        $html = self::render($content);

        // Keep block boundaries readable when the tags are stripped below.
        $html = (string) preg_replace('#<br\s*/?>#i', ' ', $html);
        $html = (string) preg_replace(
            '#</(p|div|h[1-6]|li|blockquote|pre|tr|figure|figcaption|table|ul|ol|section|article)>#i',
            '$0 ',
            $html
        );

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));

        if ($limit > 0 && mb_strlen($text) > $limit) {
            $text = rtrim(mb_substr($text, 0, $limit)) . '…';
        }

        return $text;
    }
}
