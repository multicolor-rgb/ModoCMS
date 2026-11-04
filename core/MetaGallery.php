<?php
declare(strict_types=1);

namespace Core;

/**
 * Normalizes and renders custom-field galleries ("gallery" type in page_meta).
 *
 * A gallery value is stored as JSON:
 *   {"items":[{"image":"/uploads/a.jpg","alt":"","caption":"","link":""}],
 *    "options":{"columns":"3","gap":"14","ratio":"1","lightbox":"1","captions":"1"}}
 *
 * When the bundled Modo Gallery plugin is active, rendering is delegated to it
 * so the admin preview and the published page share the exact same markup,
 * lightbox and assets. Otherwise a dependency-free responsive grid is emitted.
 */
final class MetaGallery
{
    /** Value stored in page_meta.meta_type. */
    public const TYPE = 'gallery';

    /**
     * Default appearance of a gallery created in a custom field.
     *
     * @return array<string,string>
     */
    public static function defaultOptions(): array
    {
        return [
            'columns'  => '3',
            'gap'      => '14',
            'ratio'    => '1',
            'lightbox' => '1',
            'captions' => '1',
        ];
    }

    /**
     * Normalizes any stored representation (JSON string, array, legacy list)
     * into a predictable structure.
     *
     * @return array{
     *     items: array<int,array{image:string,alt:string,caption:string,link:string}>,
     *     options: array<string,string>
     * }
     */
    public static function normalize(mixed $raw): array
    {
        $data = $raw;
        if (is_string($raw)) {
            $trimmed = trim($raw);
            $data = $trimmed === '' ? [] : json_decode($trimmed, true);
        }
        if (!is_array($data)) {
            $data = [];
        }

        // Accept a bare list of items as well as the canonical {items, options}.
        $isList = $data !== [] && array_keys($data) === range(0, count($data) - 1);
        $itemsRaw = array_key_exists('items', $data) && is_array($data['items'])
            ? $data['items']
            : ($isList ? $data : []);

        $items = [];
        foreach ($itemsRaw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $image = trim((string) ($item['image'] ?? ''));
            if ($image === '') {
                continue;
            }
            $items[] = [
                'image'   => $image,
                'alt'     => trim((string) ($item['alt'] ?? '')),
                'caption' => trim((string) ($item['caption'] ?? '')),
                'link'    => trim((string) ($item['link'] ?? '')),
            ];
        }

        $optionsRaw = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
        $options = self::defaultOptions();

        if (array_key_exists('columns', $optionsRaw)) {
            $options['columns'] = (string) max(1, min(6, (int) $optionsRaw['columns']));
        }
        if (array_key_exists('gap', $optionsRaw)) {
            $options['gap'] = (string) max(0, min(60, (int) $optionsRaw['gap']));
        }
        if (array_key_exists('ratio', $optionsRaw)) {
            $ratio = (string) $optionsRaw['ratio'];
            $options['ratio'] = in_array($ratio, ['1', '1.3333', '1.5', '1.7778', 'auto'], true)
                ? $ratio
                : '1';
        }
        foreach (['lightbox', 'captions'] as $flag) {
            if (array_key_exists($flag, $optionsRaw)) {
                $value = $optionsRaw[$flag];
                $options[$flag] = ($value === false || $value === null || $value === '' || $value === '0' || $value === 0)
                    ? '0'
                    : '1';
            }
        }

        return ['items' => $items, 'options' => $options];
    }

    /**
     * Serializes a gallery value in the canonical JSON shape.
     */
    public static function toJson(mixed $raw): string
    {
        $data = self::normalize($raw);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false
            ? $json
            : '{"items":[],"options":{}}';
    }

    /**
     * Returns the normalized gallery or null when it has no images.
     *
     * @return array{
     *     items: array<int,array{image:string,alt:string,caption:string,link:string}>,
     *     options: array<string,string>
     * }|null
     */
    public static function resolve(mixed $raw): ?array
    {
        $data = self::normalize($raw);
        return $data['items'] === [] ? null : $data;
    }

    /**
     * Renders a gallery value as HTML. $args may override the stored options.
     *
     * @param array<string,mixed> $args
     */
    public static function render(mixed $raw, array $args = [], string $uid = ''): string
    {
        $data = self::resolve($raw);
        if ($data === null) {
            return '';
        }

        $options = $data['options'];
        foreach ($args as $key => $value) {
            if (array_key_exists((string) $key, $options) && is_scalar($value)) {
                $options[(string) $key] = (string) $value;
            }
        }
        $options = self::normalize(['items' => $data['items'], 'options' => $options])['options'];

        $id = self::safeId($uid);
        if ($id === '') {
            $id = 'cf-' . substr(md5(self::toJson($data)), 0, 10);
        }

        if (class_exists('\\ModoGallery') && is_callable(['\\ModoGallery', 'renderInlineGallery'])) {
            $html = \ModoGallery::renderInlineGallery($data['items'], $options, $id);
            if ($html !== '') {
                return $html;
            }
        }

        return self::renderFallback($data['items'], $options, $id);
    }

    /**
     * Generates cached thumbnails for every image in the gallery.
     */
    public static function warmThumbnails(mixed $raw): void
    {
        if (!class_exists('\\Core\\MediaLibrary') || !\Core\MediaLibrary::galleryThumbnailsEnabled()) {
            return;
        }

        $data = self::normalize($raw);
        $widths = \Core\MediaLibrary::galleryThumbnailWidths();
        if ($data['items'] === [] || $widths === []) {
            return;
        }

        foreach ($data['items'] as $item) {
            \Core\MediaLibrary::ensureThumbnails($item['image'], $widths);
        }
    }

    /**
     * Dependency-free renderer used when the Modo Gallery plugin is inactive.
     *
     * @param array<int,array{image:string,alt:string,caption:string,link:string}> $items
     * @param array<string,string> $options
     */
    private static function renderFallback(array $items, array $options, string $id): string
    {
        $columns  = max(1, min(6, (int) ($options['columns'] ?? 3)));
        $gap      = max(0, min(60, (int) ($options['gap'] ?? 14)));
        $ratio    = (string) ($options['ratio'] ?? '1');
        $lightbox = ($options['lightbox'] ?? '1') === '1';
        $captions = ($options['captions'] ?? '1') === '1';

        $ratioCss = '1 / 1';
        if ($ratio !== 'auto' && (float) $ratio > 0) {
            $ratioCss = (string) (float) $ratio;
        }

        $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $html = '<div class="modo-meta-gallery modo-meta-gallery--' . $esc($id) . '" style="'
            . 'display:grid;grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));'
            . 'gap:' . $gap . 'px;margin:1.5rem 0;">';

        $sizes = max(1, (int) round(100 / $columns)) . 'vw';

        foreach ($items as $item) {
            $src = self::mediaUrl($item['image']);
            if ($src === '') {
                continue;
            }
            $alt     = $item['alt'] !== '' ? $item['alt'] : '';
            $caption = $item['caption'];
            $link    = $item['link'];

            $sources = ['src' => $src, 'srcset' => ''];
            if (class_exists('\\Core\\MediaLibrary') && is_callable(['\\Core\\MediaLibrary', 'frontendImageSources'])) {
                $sources = \Core\MediaLibrary::frontendImageSources($item['image'], null, $src);
            }

            $figureStyle = 'position:relative;display:block;overflow:hidden;border-radius:10px;'
                . 'background:rgba(0,0,0,.04);text-decoration:none;color:inherit;';
            if ($ratio !== 'auto') {
                $figureStyle .= 'aspect-ratio:' . $ratioCss . ';';
            }

            $srcset = (string) ($sources['srcset'] ?? '');
            $img = '<img src="' . $esc((string) ($sources['src'] ?? $src)) . '"'
                . ($srcset !== '' ? ' srcset="' . $esc($srcset) . '" sizes="' . $esc($sizes) . '"' : '')
                . ' alt="' . $esc($alt) . '" loading="lazy" decoding="async"'
                . ' style="width:100%;height:100%;object-fit:cover;display:block;">';

            $inner = $img;
            if ($captions && $caption !== '') {
                $inner .= '<span style="position:absolute;left:0;right:0;bottom:0;padding:30px 12px 10px;'
                    . 'color:#fff;font-size:.85rem;line-height:1.3;'
                    . 'background:linear-gradient(to top,rgba(0,0,0,.75),rgba(0,0,0,0));">'
                    . $esc($caption) . '</span>';
            }

            if ($lightbox) {
                $html .= '<a class="modo-meta-gallery__item" href="' . $esc($src) . '"'
                    . ' target="_blank" rel="noopener" style="' . $figureStyle . '">' . $inner . '</a>';
            } elseif ($link !== '') {
                $html .= '<a class="modo-meta-gallery__item" href="' . $esc($link) . '"'
                    . ' style="' . $figureStyle . '">' . $inner . '</a>';
            } else {
                $html .= '<span class="modo-meta-gallery__item" style="' . $figureStyle . '">' . $inner . '</span>';
            }
        }

        return $html . '</div>';
    }

    private static function mediaUrl(string $path): string
    {
        if ($path === '') {
            return '';
        }
        if (function_exists('resolve_media_url')) {
            return \resolve_media_url($path);
        }

        $base = class_exists('\\Core\\Router') ? rtrim(Router::getBaseSubdirectory(), '/') : '';
        return $base . '/' . ltrim($path, '/');
    }

    private static function safeId(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $value), '-');
    }
}
