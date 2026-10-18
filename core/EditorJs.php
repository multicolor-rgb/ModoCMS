<?php
declare(strict_types=1);

namespace Core;

/**
 * Class EditorJs
 *
 * Support for the optional Editor.js block editor (Settings → Content).
 *
 * Editor.js stores a document as JSON (`{"time":…,"blocks":[…],"version":…}`)
 * and NOT as HTML. Every consumer of `pages.content` / `snippets.content` must
 * therefore pass the value through EditorJs::render() first. Values that are not
 * valid Editor.js JSON (i.e. everything authored with TinyMCE) are returned
 * unchanged, so both editors can be mixed on one installation.
 *
 * All Editor.js bundles ship locally (admin/assets/vendor/editorjs, MIT) - no
 * CDN and no build step. The tool registry below is the single source of truth
 * for both the admin UI (Settings + editor bootstrap) and the renderer.
 *
 * Bundled versions: editorjs 2.31.7, paragraph 2.11.7, header 2.8.9, list 2.0.9,
 * checklist 1.6.0, quote 2.7.6, warning 1.4.1, code 2.9.4, delimiter 1.4.2,
 * table 2.4.6, marker 1.4.0, inline-code 1.5.2, raw 2.5.1, image 2.10.3,
 * embed 2.8.0. The matching license texts live in
 * admin/assets/vendor/editorjs/licenses/.
 */
final class EditorJs
{
    /** Setting holding the active content editor ("tinymce" or "editorjs"). */
    public const OPTION = 'content_editor';

    /** Value of OPTION that enables the block editor. */
    public const VALUE = 'editorjs';

    /** Setting holding the enabled tool ids (comma separated). */
    public const OPTION_TOOLS = 'editorjs_tools';

    /** Setting holding the "insert grid" tool toggle ("1"/"0"). */
    public const OPTION_GRID = 'editorjs_grid';

    /** Relative (project root) directory with the vendored bundles. */
    public const VENDOR_DIR = 'admin/assets/vendor/editorjs/';

    private static bool $booted = false;

    /**
     * Registry of the bundled block tools.
     *
     * Each entry:
     *  - label   : Human readable name (translated).
     *  - file    : Bundle inside VENDOR_DIR.
     *  - global  : UMD global exported by the bundle.
     *  - inline  : true for inline tools (highlight / inline code).
     *  - default : enabled on a fresh installation.
     *
     * @var array<string,array<string,mixed>>
     */
    private const TOOLS = [
        'paragraph'   => ['label' => 'Paragraph',   'file' => 'paragraph.umd.js',   'global' => 'Paragraph',    'inline' => false, 'default' => true],
        'header'      => ['label' => 'Heading',     'file' => 'header.umd.js',      'global' => 'Header',       'inline' => false, 'default' => true],
        'list'        => ['label' => 'List',        'file' => 'list.umd.js',        'global' => 'EditorjsList', 'inline' => false, 'default' => true],
        'checklist'   => ['label' => 'Checklist',   'file' => 'checklist.umd.js',   'global' => 'Checklist',    'inline' => false, 'default' => true],
        'quote'       => ['label' => 'Quote',       'file' => 'quote.umd.js',       'global' => 'Quote',        'inline' => false, 'default' => true],
        'delimiter'   => ['label' => 'Delimiter',   'file' => 'delimiter.umd.js',   'global' => 'Delimiter',    'inline' => false, 'default' => true],
        'table'       => ['label' => 'Table',       'file' => 'table.umd.js',       'global' => 'Table',        'inline' => false, 'default' => true],
        'image'       => ['label' => 'Image',       'file' => 'image.umd.js',       'global' => 'ImageTool',    'inline' => false, 'default' => true],
        'raw'         => ['label' => 'Raw HTML',    'file' => 'raw.umd.js',         'global' => 'RawTool',      'inline' => false, 'default' => true],
        'warning'     => ['label' => 'Warning',     'file' => 'warning.umd.js',     'global' => 'Warning',      'inline' => false, 'default' => false],
        'code'        => ['label' => 'Code',        'file' => 'code.umd.js',        'global' => 'CodeTool',     'inline' => false, 'default' => false],
        'embed'       => ['label' => 'Embed',       'file' => 'embed.umd.js',       'global' => 'Embed',        'inline' => false, 'default' => false],
        'marker'      => ['label' => 'Highlight',   'file' => 'marker.umd.js',      'global' => 'Marker',       'inline' => true,  'default' => false],
        'inline-code' => ['label' => 'Inline code', 'file' => 'inline-code.umd.js', 'global' => 'InlineCode',   'inline' => true,  'default' => false],
        // Not a vendored bundle: the tool is part of editorjs-init.js and keeps
        // plugin shortcodes (gallery, form, carousel, …) editable in the canvas.
        'shortcode'   => ['label' => 'Shortcode',   'file' => '',                   'global' => 'ModoShortcode', 'inline' => false, 'default' => true],
        // embed.umd.js ships without a `static get toolbox()` getter, so it needs
        // the wrapper built in editorjs-init.js to be insertable at all.
        'embed'       => ['label' => 'Embed',       'file' => 'embed.umd.js',       'global' => 'Embed',        'inline' => false, 'default' => false, 'toolbox' => false],
    ];

    /**
     * Writing systems that render right to left. Editor.js cannot derive this
     * from the language code, it wants the hint in `i18n.direction`.
     *
     * @var list<string>
     */
    private const RTL_LANGUAGES = ['ar', 'ckb', 'dv', 'fa', 'he', 'iw', 'ji', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi'];

    // ---------------------------------------------------------------------
    // Bootstrap
    // ---------------------------------------------------------------------

    /**
     * Registers the frontend content filter.
     */
    public static function init(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // Priority 1: the stored value is converted from Editor.js JSON to HTML
        // *before* anything else touches it. Running later would let the
        // snippet expander (priority 5) rewrite shortcodes inside the raw JSON
        // string, which would invalidate the document and leak JSON to the
        // frontend. Plugin shortcodes (priority 20) work on the rendered HTML.
        Hooks::addFilter('the_content', [self::class, 'render'], 1);
    }

    /**
     * Whether Editor.js is the configured content editor.
     */
    public static function isActive(): bool
    {
        return class_exists(Router::class) && Router::getOption(self::OPTION, 'tinymce') === self::VALUE;
    }

    /**
     * Whether the "Insert Grid / Columns" tool is available.
     */
    public static function gridEnabled(): bool
    {
        return Router::getOption(self::OPTION_GRID, '1') === '1';
    }

    // ---------------------------------------------------------------------
    // Tool registry / settings
    // ---------------------------------------------------------------------

    /**
     * Returns the full tool registry with translated labels.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function availableTools(): array
    {
        $tools = [];
        foreach (self::TOOLS as $id => $tool) {
            $tool['label'] = __((string) $tool['label']);
            $tools[$id] = $tool;
        }
        return $tools;
    }

    /**
     * Ids of the tools enabled in the settings, falling back to the defaults
     * when nothing was configured yet.
     *
     * @return array<int,string>
     */
    public static function enabledTools(): array
    {
        $stored = self::sanitizeTools((string) Router::getOption(self::OPTION_TOOLS, ''));

        if ($stored === '') {
            $stored = implode(',', array_keys(array_filter(
                self::TOOLS,
                static fn (array $tool): bool => (bool) $tool['default']
            )));
        }

        return array_values(array_filter(explode(',', $stored), static fn (string $id): bool => $id !== ''));
    }

    /**
     * Validates a tool selection coming from the settings form (array) or from
     * the option table (comma separated string).
     *
     * @param mixed $input Raw value.
     */
    public static function sanitizeTools(mixed $input): string
    {
        if (is_string($input)) {
            $input = explode(',', $input);
        }
        if (!is_array($input)) {
            return '';
        }

        $ids = [];
        foreach ($input as $id) {
            if (!is_string($id)) {
                continue;
            }
            $id = strtolower(trim($id));
            if (isset(self::TOOLS[$id]) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return implode(',', $ids);
    }

    /**
     * Resolves a project-relative asset path to a public URL.
     */
    public static function assetUrl(string $relative): string
    {
        $base = class_exists(Router::class) ? rtrim(Router::getBaseSubdirectory(), '/') : '';
        return $base . '/' . ltrim($relative, '/');
    }

    /**
     * Absolute filesystem path of a vendored bundle.
     */
    public static function assetPath(string $file): string
    {
        return dirname(__DIR__) . '/' . self::VENDOR_DIR . $file;
    }

    /**
     * URLs of the enabled block tools (excluding the core bundle).
     *
     * @return array<int,string>
     */
    public static function toolAssets(): array
    {
        $tools = self::availableTools();
        $urls = [];
        foreach (self::enabledTools() as $id) {
            if (!isset($tools[$id])) {
                continue;
            }
            $file = (string) $tools[$id]['file'];
            if (is_file(self::assetPath($file))) {
                $urls[] = self::assetUrl(self::VENDOR_DIR . $file);
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * URL of the Editor.js core bundle.
     */
    public static function coreAsset(): string
    {
        return self::assetUrl(self::VENDOR_DIR . 'editorjs.umd.js');
    }

    // ---------------------------------------------------------------------
    // Editor.js UI localization
    // ---------------------------------------------------------------------

    /**
     * English source strings rendered by Editor.js and its tools themselves.
     *
     * The structure mirrors the dictionary compiled into editorjs.umd.js, with
     * the English source string as both key and value (`self::dict()`).
     * Editor.js replaces its default dictionary with whatever we hand over, so
     * only strings a bundle reads through `api.i18n.t()` may live here:
     * placeholders passed via `config` are covered by toolConfig(), and strings
     * compiled into a bundle (the Confirm/Cancel popover buttons) cannot be
     * translated this way at all.
     *
     * @return array<string,mixed>
     */
    private static function messages(): array
    {
        return [
            'ui' => [
                'blockTunes' => [
                    'toggler' => self::dict(['Click to tune', 'or drag to move']),
                ],
                'inlineToolbar' => [
                    'converter' => self::dict(['Convert to']),
                ],
                'toolbar' => [
                    'toolbox' => self::dict(['Add']),
                ],
                'popover' => self::dict(['Filter', 'Nothing found', 'Convert to']),
            ],
            // Keys are the English toolbox titles of the bundled tools; the same
            // list also names the inline tools shown by the inline toolbar.
            'toolNames' => self::dict([
                'Text', 'Heading', 'Unordered List', 'Ordered List', 'Checklist',
                'Quote', 'Delimiter', 'Table', 'Image', 'Raw HTML', 'Warning',
                'Code', 'Embed', 'Bold', 'Italic', 'Link',
            ]),
            'tools' => [
                'stub'   => self::dict(['Error', 'The block can not be displayed correctly.']),
                'link'   => self::dict(['Add a link']),
                'header' => self::dict([
                    'Heading 1', 'Heading 2', 'Heading 3', 'Heading 4', 'Heading 5', 'Heading 6',
                ]),
                'image'  => self::dict([
                    'Caption', 'Select an Image', 'Couldn’t upload image. Please try another.',
                    'Stretch image', 'With background', 'With border', 'With caption',
                ]),
                'quote'  => self::dict([
                    'Enter a quote', 'Enter a caption', 'Align Left', 'Align Center', 'Align Right',
                ]),
                'embed'  => self::dict(['Enter a caption']),
                // list 2.0.9 asks for the checklist style name in its own namespace.
                'list'   => self::dict(['Start with', 'Counter type', 'Ordered', 'Unordered', 'Checklist']),
                'table'  => self::dict([
                    'Add column to left', 'Add column to right', 'Delete column',
                    'Add row above', 'Add row below', 'Delete row',
                    'Heading', 'Stretch', 'With headings', 'Without headings', 'Collapse',
                ]),
            ],
            'blockTunes' => [
                'delete'   => self::dict(['Delete', 'Click to delete']),
                'moveUp'   => self::dict(['Move up']),
                'moveDown' => self::dict(['Move down']),
            ],
        ];
    }

    /**
     * Turns a list of English source strings into an identity dictionary - the
     * shape Editor.js expects (source string as key *and* value).
     *
     * @param  list<string> $strings
     * @return array<string,string>
     */
    private static function dict(array $strings): array
    {
        return array_combine($strings, $strings) ?: [];
    }

    /**
     * `i18n` payload for the Editor.js constructor.
     *
     * @return array{messages:array<string,mixed>,direction:string}
     */
    public static function i18n(): array
    {
        return [
            'messages'  => self::translateTree(self::messages()),
            'direction' => self::direction(),
        ];
    }

    /**
     * Replaces every leaf (the English source string) with its translation. An
     * empty translation would render as a blank label inside Editor.js, so it
     * falls back to the English source string.
     *
     * @param  array<string,mixed> $node
     * @return array<string,mixed>
     */
    private static function translateTree(array $node): array
    {
        $translated = [];
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $translated[$key] = self::translateTree($value);
                continue;
            }
            $source = (string) $value;
            $label  = __($source);
            $translated[$key] = $label === '' ? $source : $label;
        }
        return $translated;
    }

    /**
     * Text direction of the current admin language.
     */
    private static function direction(): string
    {
        $parts  = preg_split('/[-_]/', strtolower(I18n::getAdminLocale()));
        $locale = is_array($parts) ? (string) ($parts[0] ?? '') : '';

        return in_array($locale, self::RTL_LANGUAGES, true) ? 'rtl' : 'ltr';
    }

    /**
     * Values the tool bundles only read from `config` (never through the i18n
     * dictionary): input placeholders and the image caption placeholder.
     *
     * @return array<string,array<string,string>>
     */
    public static function toolConfig(): array
    {
        return [
            'header'  => ['placeholder' => __('Heading')],
            'code'    => ['placeholder' => __('Enter a code')],
            'raw'     => ['placeholder' => __('Enter HTML code')],
            'warning' => [
                'titlePlaceholder'   => __('Title'),
                'messagePlaceholder' => __('Message'),
            ],
            'image'   => ['captionPlaceholder' => __('Caption (optional)')],
        ];
    }

    /**
     * Payload for window.MODO_EDITORJS consumed by
     * admin/assets/js/editorjs-init.js.
     *
     * @return array<string,mixed>
     */
    public static function clientConfig(): array
    {
        $tools = self::availableTools();
        $blockTools = [];
        $inlineTools = [];

        foreach (self::enabledTools() as $id) {
            if (!isset($tools[$id])) {
                continue;
            }
            $entry = [
                'id'      => $id,
                'global'  => (string) $tools[$id]['global'],
                'title'   => (string) $tools[$id]['label'],
                // false => the bundle has no static toolbox getter, so
                // editorjs-init.js wraps it to keep the block insertable.
                'toolbox' => !array_key_exists('toolbox', $tools[$id]) || (bool) $tools[$id]['toolbox'],
            ];
            if (!empty($tools[$id]['inline'])) {
                $inlineTools[] = $entry;
            } else {
                $blockTools[] = $entry;
            }
        }

        return [
            'tools'       => $blockTools,
            'inlineTools' => $inlineTools,
            'gridTool'    => self::gridEnabled() && CssFramework::isEnabled(),
            'uploadUrl'   => self::assetUrl('admin/upload.php'),
            'csrf'        => class_exists(Security::class) ? Security::generateCsrfToken() : '',
            // UI strings of Editor.js itself (tool names, block tunes, tool
            // dialogs) and the text direction of the current admin language.
            'i18n'        => self::i18n(),
            // Values the tools only read from their own `config` object.
            'toolConfig'  => self::toolConfig(),
            'labels'      => [
                'placeholder'  => __('Start writing, or press Tab to pick a block...'),
                'imageCaption' => __('Caption (optional)'),
                'imageUpload'  => __('Upload an image'),
                'imageFailed'  => __('Could not upload the file.'),
                'gridTitle'    => __('Insert Grid / Columns'),
                'blocksTitle'  => __('Blocks'),
                'plainText'    => __('Plain text'),
                'shortcodeTitle' => __('Shortcode'),
                'editorFailed' => __('The block editor bundle could not be loaded. The content field stays available in raw mode.'),
                'editorStartFailed' => __('The block editor could not be started:'),
                'noBlocks'     => __('No Editor.js block is available - check Settings → Content.'),
                'noMediaLibrary' => __('The media library is not available on this screen.'),
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Decoding
    // ---------------------------------------------------------------------

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






