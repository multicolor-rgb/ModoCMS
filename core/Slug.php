<?php
declare(strict_types=1);

namespace Core;

/**
 * Shared URL slug normalisation with explicit transliteration for Polish and
 * other common Latin diacritics. Works even when iconv or intl is unavailable.
 */
final class Slug
{
    private const MAP = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ı' => 'i',
        'ñ' => 'n', 'ň' => 'n',
        'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ő' => 'o',
        'ř' => 'r', 'š' => 's', 'ť' => 't',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u', 'ű' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z',
        'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'þ' => 'th', 'ð' => 'd',
        '&' => ' and ', '@' => ' at ',
    ];

    /**
     * Converts arbitrary text into a lowercase, hyphen-separated ASCII slug.
     */
    public static function slugify(string $value, string $fallback = ''): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        $value = strtr($value, self::MAP);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false && $converted !== '') {
                $value = $converted;
            }
        }

        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = (string) preg_replace('/-+/', '-', $value);
        $value = trim($value, '-');

        return $value !== '' ? $value : $fallback;
    }
}
