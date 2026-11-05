<?php
declare(strict_types=1);

namespace Core;

/**
 * Defensive SVG sanitiser.
 *
 * A regex cannot safely classify SVG: the format allows scripting through
 * <script>, <foreignObject>, animation event attributes, javascript: URLs and
 * external references. This walks the DOM instead and removes every construct
 * that can execute code in the site's origin.
 */
final class SvgSanitizer
{
    /** Elements that have no place in an uploaded image. */
    private const FORBIDDEN_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'object', 'embed', 'handler',
        'listener', 'set', 'animate', 'animatemotion', 'animatetransform',
        'discard', 'audio', 'video', 'canvas',
    ];

    /**
     * Returns a cleaned SVG document, or null when the document is invalid or
     * uses an unsupported/unsafe XML feature.
     */
    public static function sanitize(string $svg): ?string
    {
        if (!class_exists(\DOMDocument::class)) {
            return null;
        }

        if (trim($svg) === '' || strlen($svg) > 5 * 1024 * 1024) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$loaded) {
                return null;
            }

            // External entities and doctypes are never needed in an upload.
            if ($dom->doctype !== null) {
                return null;
            }

            $removed = 0;
            foreach (self::FORBIDDEN_ELEMENTS as $tag) {
                while (($nodes = $dom->getElementsByTagName($tag))->length > 0) {
                    $node = $nodes->item(0);
                    if ($node === null) {
                        break;
                    }
                    $node->parentNode?->removeChild($node);
                    $removed++;
                }
            }

            self::cleanElement($dom->documentElement);
            self::cleanElementTree($dom);

            $output = $dom->saveXML();
            if (!is_string($output) || trim($output) === '') {
                return null;
            }

            // A second pass on the serialised output catches constructs that
            // DOM extension might have preserved (e.g. namespaced handlers).
            if (preg_match('/<script|<foreignObject|javascript:|data:text\/html/i', $output) === 1) {
                return null;
            }

            return $output;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function cleanElementTree(\DOMDocument $dom): void
    {
        $stack = [$dom->documentElement];
        while ($stack !== []) {
            $element = array_pop($stack);
            if (!$element instanceof \DOMElement) {
                continue;
            }

            self::cleanElement($element);

            foreach ($element->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $stack[] = $child;
                }
            }
        }
    }

    private static function cleanElement(?\DOMElement $element): void
    {
        if (!$element || !$element->hasAttributes()) {
            return;
        }

        $remove = [];
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = strtolower(trim((string)$attribute->nodeValue));
            // Browsers ignore control characters/whitespace inside URL schemes,
            // so "java\tscript:" must be treated like "javascript:".
            $compact = (string)preg_replace('/[\x00-\x20]+/', '', $value);

            $isLink = $name === 'href' || $name === 'xlink:href' || $name === 'src';
            if (str_starts_with($name, 'on')
                || str_contains($compact, 'javascript:')
                || str_contains($compact, 'vbscript:')
                || str_contains($compact, 'data:text/html')
                || ($isLink && str_starts_with($compact, 'data:'))) {
                $remove[] = $attribute->nodeName;
            }
        }

        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }
    }
}
