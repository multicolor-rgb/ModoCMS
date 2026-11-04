<?php
declare(strict_types=1);

namespace Core;

/**
 * Single source of truth for the installed ModoCMS version.
 *
 * Bump CURRENT in the release commit (for example "3.0.3-dev" while developing
 * and "3.0.3" for the tagged release). The updater compares only the numeric
 * part, so a "-dev" build of the upcoming release is never offered as an update
 * to itself once that release has been published.
 */
final class Version
{
    /** Currently installed application version. */
    public const CURRENT = '3.0.4';

    public static function current(): string
    {
        return self::CURRENT;
    }

    /**
     * Version reduced to its comparable numeric part, e.g. "3.0.3-dev" -> "3.0.3".
     */
    public static function comparable(?string $version = null): string
    {
        $version = ltrim(trim($version ?? self::CURRENT), 'vV');
        $numeric = preg_replace('/[-+].*$/', '', $version);

        return $numeric !== null && $numeric !== '' ? $numeric : $version;
    }
}
