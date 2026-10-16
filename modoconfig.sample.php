<?php
declare(strict_types=1);

/**
 * ModoCMS advanced configuration - SAMPLE FILE.
 *
 * Copy this file to modoconfig.php (or let Settings -> System write it for you)
 * and uncomment the lines you need. Values set here override the matching option
 * from the Settings screen.
 */

return [

    // ----------------------------------------------------------------------
    // Debugging and error handling
    // ----------------------------------------------------------------------
    // Shows PHP notices, warnings and errors on screen and disables asset
    // minification. Never enable this on a live site: error output can expose
    // filesystem paths and configuration details.
    // 'debug' => false,
    // Writes PHP errors to data/logs/error.log instead of displaying them. Keep it
    // enabled on production sites so problems stay traceable. Requires a writable
    // data/logs directory.
    // 'debug_log' => false,

    // ----------------------------------------------------------------------
    // Localization
    // ----------------------------------------------------------------------
    // Timezone used by PHP for dates and times (date_default_timezone_set). Leave
    // empty to keep the value configured in php.ini.
    // 'timezone' => '',
    // Default frontend language code, used when the visitor has not chosen a
    // language yet.
    // Falls back to the "default_language" setting when omitted.
    // 'language' => '',

    // ----------------------------------------------------------------------
    // URLs
    // ----------------------------------------------------------------------
    // Absolute base URL of the installation including the subfolder, e.g.
    // https://example.com or https://example.com/modocms. Feeds canonical links,
    // OpenGraph tags, the sitemap and robots.txt.
    // Falls back to the "site_url" setting when omitted.
    // 'siteurl' => '',
    // Replaces the rules generated for /robots.txt. Leave it empty to let the CMS
    // build the file (it then blocks /admin/, /core/ and /uploads/cache/ and links
    // the sitemap). Useful to keep a staging copy out of the search index.
    // 'robots_txt' => '',

    // ----------------------------------------------------------------------
    // Availability (maintenance / HTTPS / analytics)
    // ----------------------------------------------------------------------
    // Locks the public site: visitors get a 503 Service Unavailable page with a
    // Retry-After header instead of the content, while the admin area and the IP
    // addresses listed below keep working. Search engines mark the outage as
    // temporary instead of dropping the pages.
    // 'maintenance_mode' => false,
    // Comma separated IP addresses, address prefixes or CIDR ranges (203.0.113.7,
    // 198.51.100. or 203.0.113.0/24) that may browse the site while maintenance
    // mode is active. The admin area stays reachable for everybody who can log in.
    // 'maintenance_allowed_ips' => '',
    // Message shown to visitors while maintenance mode is active. Leave it empty
    // for the built-in wording. A theme can replace the whole page by shipping
    // maintenance.php.
    // 'maintenance_message' => '',
    // Value of the Retry-After header sent with the 503 response. It tells
    // browsers and search engines when to come back (3600 = one hour).
    // 'maintenance_retry_after' => 3600,
    // Redirects every frontend request made over plain HTTP to the same URL over
    // HTTPS (301). Enable it only once a valid TLS certificate is installed,
    // otherwise the site becomes unreachable.
    // 'require_https' => false,
    // Stores anonymous, daily rotating IP hashes for visited pages. Disabling it
    // stops all writes to the statistics table, which removes the Dashboard
    // traffic report.
    // 'analytics_enabled' => true,

    // ----------------------------------------------------------------------
    // Uploads
    // ----------------------------------------------------------------------
    // Rejects larger files in the media library and the editor. The effective
    // limit is never higher than PHP upload_max_filesize / post_max_size. Use 0 to
    // follow the PHP configuration only.
    // 'upload_max_size' => 0,

    // ----------------------------------------------------------------------
    // Security
    // ----------------------------------------------------------------------
    // automatic: mark the session cookie as Secure whenever the request arrives
    // over HTTPS (recommended). always: mark it Secure even over HTTP, suitable
    // for HTTPS-only sites. never: required when TLS is terminated by a reverse
    // proxy that does not forward the HTTPS flag.
    // 'session_cookie_secure' => 'auto',
    // Keeps a plugins/.htaccess that stops browsers from executing plugin entry
    // points (they are included by the core, never requested directly). Disable it
    // only if a third-party plugin ships its own web endpoint.
    // 'plugins_php_deny' => true,
];
