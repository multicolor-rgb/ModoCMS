<?php
declare(strict_types=1);

namespace Core;

/**
 * Event and filter dispatcher for Modo CMS.
 */
class Hooks {
    protected static array $actions = [];
    protected static array $filters = [];

    public static function addAction(string $hook, callable $callback, int $priority = 10): void {
        self::$actions[$hook][$priority][] = $callback;
    }

    public static function hasAction(string $hook): bool {
        return !empty(self::$actions[$hook]);
    }

    public static function doAction(string $hook, ...$args): void {
        if (!isset(self::$actions[$hook])) {
            return;
        }

        ksort(self::$actions[$hook]);
        foreach (self::$actions[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                $callback(...$args);
            }
        }
    }

    public static function addFilter(string $filter, callable $callback, int $priority = 10): void {
        self::$filters[$filter][$priority][] = $callback;
    }

    public static function hasFilter(string $filter): bool {
        return !empty(self::$filters[$filter]);
    }

    public static function applyFilters(string $filter, mixed $value, ...$args): mixed {
        if (!isset(self::$filters[$filter])) {
            return $value;
        }

        ksort(self::$filters[$filter]);
        foreach (self::$filters[$filter] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }

        return $value;
    }
}