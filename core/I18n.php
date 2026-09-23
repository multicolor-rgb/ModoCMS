<?php
declare(strict_types=1);

namespace Core {
    use Core\Router;

    /**
     * Class I18n
     * Manages system localization, dictionary loaders, frontend routing detection, and admin language scopes.
     */
    final class I18n {
        private static string $frontendLang = 'en';
        private static string $adminLang = 'en';
        private static string $defaultLang = 'en';
        private static array $translations = [];
        private static array $availableLanguages = [];

        public static function init(): void {
            self::$defaultLang = class_exists('\Core\Router') ? Router::getOption('default_language', 'en') : 'en';
            $rawLangs = class_exists('\Core\Router') ? Router::getOption('available_languages', 'en:English,pl:Polski') : 'en:English,pl:Polski';

            self::$availableLanguages = [];
            foreach (explode(',', $rawLangs) as $pair) {
                $parts = explode(':', trim($pair));
                if (count($parts) === 2) {
                    self::$availableLanguages[trim($parts[0])] = trim($parts[1]);
                }
            }

            if (empty(self::$availableLanguages)) {
                self::$availableLanguages = ['en' => 'English', 'pl' => 'Polski'];
            }

            self::detectFrontendLanguage();

            // Priority: Active user session > Database settings > Frontend default fallback
            $configuredAdminLang = class_exists('\Core\Router') ? Router::getOption('admin_language', self::$defaultLang) : self::$defaultLang;

            if (isset($_SESSION['user_admin_lang']) && array_key_exists($_SESSION['user_admin_lang'], self::$availableLanguages)) {
                self::$adminLang = $_SESSION['user_admin_lang'];
            } elseif (array_key_exists($configuredAdminLang, self::$availableLanguages)) {
                self::$adminLang = $configuredAdminLang;
            } else {
                self::$adminLang = self::$defaultLang;
            }

            self::loadDictionary(self::$frontendLang);
            if (self::$adminLang !== self::$frontendLang) {
                self::loadDictionary(self::$adminLang);
            }
        }

        private static function detectFrontendLanguage(): void {
            $isMultilingual = class_exists('\Core\Router') && Router::getOption('multilingual_frontend', '0') === '1';
            if (!$isMultilingual) {
                self::$frontendLang = self::$defaultLang;
                return;
            }

            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

            // Strip subfolder prefix if present
            $basePrefix = class_exists('\Core\Router') ? Router::getBaseSubdirectory() : '';
            if ($basePrefix !== '' && str_starts_with((string)$uri, $basePrefix)) {
                $uri = substr((string)$uri, strlen($basePrefix));
            }

            $segments = array_values(array_filter(explode('/', trim((string)$uri, '/'))));
            $firstSegment = $segments[0] ?? '';

            if (!empty($firstSegment) && array_key_exists($firstSegment, self::$availableLanguages)) {
                self::$frontendLang = $firstSegment;
            } else {
                self::$frontendLang = self::$defaultLang;
            }
        }

        public static function loadDictionary(string $lang): void {
            $lang = preg_replace('/[^a-z0-9_-]/i', '', $lang);
            $filePath = __DIR__ . '/../languages/' . $lang . '.json';

            if (file_exists($filePath)) {
                $json = file_get_contents($filePath);
                $data = json_decode((string)$json, true);
                if (is_array($data)) {
                    self::$translations[$lang] = $data;
                    return;
                }
            }
            self::$translations[$lang] = [];
        }

        public static function getLocale(): string {
            return self::$frontendLang;
        }

        public static function setLocale(string $lang): void {
            if (array_key_exists($lang, self::$availableLanguages)) {
                self::$frontendLang = $lang;
                if (!isset(self::$translations[$lang])) {
                    self::loadDictionary($lang);
                }
            }
        }

        public static function getAdminLocale(): string {
            return self::$adminLang;
        }

        public static function setAdminLocale(string $lang): void {
            if (array_key_exists($lang, self::$availableLanguages)) {
                self::$adminLang = $lang;
                $_SESSION['user_admin_lang'] = $lang;
                if (!isset(self::$translations[$lang])) {
                    self::loadDictionary($lang);
                }
            }
        }

        public static function getDefaultLocale(): string {
            return self::$defaultLang;
        }

        public static function getAvailableLanguages(): array {
            return self::$availableLanguages;
        }

        public static function translate(string $key, ?string $lang = null): string {
            if ($lang === null) {
                $isAdmin = defined('IN_ADMIN') && IN_ADMIN === true;
                $lang = $isAdmin ? self::$adminLang : self::$frontendLang;
            }

            if (!isset(self::$translations[$lang])) {
                self::loadDictionary($lang);
            }

            $translated = self::$translations[$lang][$key] ?? null;
            if ($translated !== null && $translated !== '') {
                return (string)$translated;
            }

            if ($lang !== self::$defaultLang && isset(self::$translations[self::$defaultLang][$key])) {
                return (string)self::$translations[self::$defaultLang][$key];
            }

            return $key;
        }
    }
}

namespace {
    if (!function_exists('__')) {
        function __(string $key, ?string $lang = null): string {
            return \Core\I18n::translate($key, $lang);
        }
    }

    if (!function_exists('_e')) {
        function _e(string $key, ?string $lang = null): void {
            echo htmlspecialchars(\Core\I18n::translate($key, $lang), ENT_QUOTES, 'UTF-8');
        }
    }
}