<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Analytics
 *
 * Single source of truth for the internal, privacy-friendly visit statistics.
 * Every frontend request is stored as one row (no write-time de-duplication),
 * which makes it possible to report both "all views" and "unique visitors"
 * from the same data set. An IP hash is rotated every day, so unique visitors
 * are only comparable inside a single day - exactly how the dashboard reports
 * them.
 */
final class Analytics
{
    /** Setting key that stores the date of the last counter reset. */
    public const OPTION_RESET_AT = 'analytics_reset_at';

    /** Default chart colour palettes (one base colour - the second series uses opacity). */
    private const PALETTES = [
        'primary' => '#6366f1',
        'emerald' => '#10b981',
        'violet'  => '#8b5cf6',
        'amber'   => '#f59e0b',
        'rose'    => '#f43f5e',
    ];

    /** Extensions that never represent a page view. */
    private const IGNORED_EXTENSIONS = [
        'css', 'js', 'mjs', 'json', 'map', 'txt', 'xml',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'woff', 'woff2', 'ttf', 'otf', 'eot', 'pdf', 'zip', 'gz', 'mp3', 'mp4', 'webm',
    ];

    /** User agents that must not inflate the statistics. */
    private const BOT_PATTERN = '/bot|crawl|slurp|spider|mediapartners|facebookexternalhit|preview|headless|monitor|uptime|pingdom|curl|wget|python-requests|go-http-client/i';

    /**
     * Stores one page view for the current request.
     *
     * Kept intentionally silent: analytics must never break the page.
     */
    public static function track(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (defined('IN_ADMIN') && IN_ADMIN === true) {
            return;
        }

        // Do not count the maintenance page or admin previews.
        if (class_exists(Router::class) && Router::maintenanceActive()) {
            return;
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($uriPath === '') {
            $uriPath = '/';
        }

        if (str_starts_with($uriPath, '/admin') || str_starts_with($uriPath, '/api')) {
            return;
        }

        $extension = strtolower(pathinfo($uriPath, PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, self::IGNORED_EXTENSIONS, true)) {
            return;
        }

        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (preg_match(self::BOT_PATTERN, $userAgent) === 1) {
            return;
        }

        // Logged-in editors/admins would otherwise pollute their own reports.
        if (class_exists(Auth::class) && Auth::check()) {
            return;
        }

        try {
            $db = Database::getConnection();
            $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $day = date('Y-m-d');
            $ipHash = hash('sha256', $ipAddress . '|' . $day . '|' . self::salt());

            $stmt = $db->prepare(
                'INSERT INTO visits (path, ip_hash, user_agent, visited_at)
                 VALUES (:path, :hash, :agent, :visited_at)'
            );
            $stmt->execute([
                ':path'       => substr($uriPath, 0, 2048),
                ':hash'       => $ipHash,
                ':agent'      => substr($userAgent, 0, 255),
                ':visited_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Silent fallback - analytics is optional.
        }
    }

    /**
     * Returns the aggregated series for the dashboard chart.
     *
     * Shape:
     *   [
     *     'days'   => 7,
     *     'start'  => '2026-10-22',
     *     'end'    => '2026-10-28',
     *     'series' => [
     *        ['date' => '2026-10-22', 'label' => '22.10', 'all' => 12, 'unique' => 8],
     *        ...
     *     ],
     *     'totals' => [
     *        'today'  => ['all' => 12, 'unique' => 8],
     *        'period' => ['all' => 84, 'unique' => 51],
     *     ],
     *   ]
     */
    public static function summary(?int $days = null): array
    {
        $days = self::clampDays($days ?? self::period());
        $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $endDate = date('Y-m-d');
        [$from, $to] = self::range($days);

        $map = [];
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare(
                "SELECT substr(visited_at, 1, 10) AS day,
                        COUNT(*) AS all_views,
                        COUNT(DISTINCT ip_hash) AS unique_views
                 FROM visits
                 WHERE visited_at >= :from AND visited_at < :to
                 GROUP BY day"
            );
            $stmt->execute([':from' => $from, ':to' => $to]);

            foreach ($stmt->fetchAll() as $row) {
                $map[(string)$row['day']] = [
                    'all'    => (int)$row['all_views'],
                    'unique' => (int)$row['unique_views'],
                ];
            }
        } catch (\Throwable $e) {
            // Empty report when the statistics table is not available.
        }

        $series = [];
        $periodTotals = ['all' => 0, 'unique' => 0];

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime($startDate . ' +' . $i . ' days'));
            $values = $map[$date] ?? ['all' => 0, 'unique' => 0];

            $series[] = [
                'date'   => $date,
                'label'  => date('d.m', strtotime($date)),
                'all'    => $values['all'],
                'unique' => $values['unique'],
            ];

            $periodTotals['all'] += $values['all'];
            $periodTotals['unique'] += $values['unique'];
        }

        $today = $series[$days - 1] ?? ['all' => 0, 'unique' => 0];

        return [
            'days'   => $days,
            'start'  => $startDate,
            'end'    => $endDate,
            'series' => $series,
            'totals' => [
                'today'  => ['all' => (int)$today['all'], 'unique' => (int)$today['unique']],
                'period' => $periodTotals,
            ],
        ];
    }

    /**
     * Most visited paths with both counters.
     *
     * @return array<int, array{path: string, all: int, unique: int}>
     */
    public static function topPages(?int $days = null, int $limit = 5): array
    {
        $days = self::clampDays($days ?? self::period());
        $limit = max(1, min(50, $limit));
        [$from, $to] = self::range($days);

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare(
                'SELECT path,
                        COUNT(*) AS all_views,
                        COUNT(DISTINCT ip_hash) AS unique_views
                 FROM visits
                 WHERE visited_at >= :from AND visited_at < :to
                 GROUP BY path
                 ORDER BY all_views DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':from', $from);
            $stmt->bindValue(':to', $to);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            $rows = [];
            foreach ($stmt->fetchAll() as $row) {
                $rows[] = [
                    'path'   => (string)$row['path'],
                    'all'    => (int)$row['all_views'],
                    'unique' => (int)$row['unique_views'],
                ];
            }

            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Deletes every collected visit and remembers when the reset happened.
     *
     * @return int Number of removed rows.
     */
    public static function reset(): int
    {
        try {
            $db = Database::getConnection();
            $removed = (int)$db->query('SELECT COUNT(*) FROM visits')->fetchColumn();

            $db->beginTransaction();
            $db->exec('DELETE FROM visits');
            self::setOption(self::OPTION_RESET_AT, date('Y-m-d H:i:s'));
            $db->commit();

            return $removed;
        } catch (\Throwable $e) {
            if (isset($db) && $db instanceof \PDO && $db->inTransaction()) {
                $db->rollBack();
            }

            return 0;
        }
    }

    public static function lastReset(): string
    {
        return Router::getOption(self::OPTION_RESET_AT, '');
    }

    public static function isEnabled(): bool
    {
        return Config::getBool('analytics_enabled', true);
    }

    /** @return array<string, string> */
    public static function palettes(): array
    {
        return [
            'primary' => 'Indigo',
            'emerald' => 'Emerald',
            'violet'  => 'Violet',
            'amber'   => 'Amber',
            'rose'    => 'Rose',
        ];
    }

    public static function mode(): string
    {
        $mode = Router::getOption('analytics_mode', 'both');
        return in_array($mode, ['both', 'unique', 'all'], true) ? $mode : 'both';
    }

    public static function chartType(): string
    {
        $type = Router::getOption('analytics_chart_type', 'bar');
        return in_array($type, ['bar', 'line', 'area'], true) ? $type : 'bar';
    }

    public static function palette(): string
    {
        $palette = Router::getOption('analytics_chart_palette', 'primary');
        return array_key_exists($palette, self::PALETTES) || $palette === 'custom' ? $palette : 'primary';
    }

    public static function customColor(): string
    {
        $color = trim(Router::getOption('analytics_chart_custom_color', ''));
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : '';
    }

    public static function chartColor(): string
    {
        $palette = self::palette();
        if ($palette === 'custom') {
            $custom = self::customColor();
            if ($custom !== '') {
                return $custom;
            }
            $palette = 'primary';
        }

        return self::PALETTES[$palette] ?? self::PALETTES['primary'];
    }

    public static function chartHeight(): int
    {
        return max(80, min(260, (int)Router::getOption('analytics_chart_height', '110')));
    }

    public static function period(): int
    {
        $period = (int)Router::getOption('analytics_chart_period', '7');
        return in_array($period, [7, 14, 30], true) ? $period : 7;
    }

    public static function showValues(): bool
    {
        return Router::getOption('analytics_chart_show_values', '1') === '1';
    }

    public static function showLegend(): bool
    {
        return Router::getOption('analytics_chart_show_legend', '1') === '1';
    }

    public static function showGrid(): bool
    {
        return Router::getOption('analytics_chart_show_grid', '0') === '1';
    }

    /**
     * Renders the dashboard chart as dependency-free HTML/CSS/SVG.
     * Returns an empty-state paragraph when there is no data at all.
     */
    public static function renderChart(?array $summary = null, ?string $mode = null): string
    {
        $summary = $summary ?? self::summary();
        $series = $summary['series'] ?? [];
        $days = count($series);

        if ($days === 0) {
            return '<p class="analytics-empty">' . self::e(\__('No visit records available for this period.')) . '</p>';
        }

        $mode = $mode ?? self::mode();
        if (!in_array($mode, ['both', 'unique', 'all'], true)) {
            $mode = 'both';
        }

        $type = self::chartType();
        $height = self::chartHeight();
        $color = self::chartColor();
        $showValues = self::showValues();
        $showLegend = self::showLegend();
        $showGrid = self::showGrid();

        // Visible series in drawing order (all views first, unique on top).
        $visible = [];
        if ($mode !== 'unique') {
            $visible['all'] = ['label' => \__('All views'), 'opacity' => $mode === 'both' ? 0.42 : 1.0];
        }
        if ($mode !== 'all') {
            $visible['unique'] = ['label' => \__('Unique views'), 'opacity' => 1.0];
        }

        $max = 1;
        foreach ($series as $row) {
            foreach (array_keys($visible) as $key) {
                $max = max($max, (int)($row[$key] ?? 0));
            }
        }

        $legend = '';
        if ($showLegend) {
            $legend = '<div class="analytics-legend">';
            foreach ($visible as $key => $definition) {
                $legend .= '<span class="analytics-legend-item">'
                    . '<span class="analytics-legend-swatch" style="background:' . self::e($color) . ';opacity:' . $definition['opacity'] . '"></span>'
                    . self::e((string)$definition['label'])
                    . '</span>';
            }
            $legend .= '</div>';
        }

        $axis = '<div class="analytics-axis">';
        $step = max(1, (int)ceil($days / 10));
        foreach ($series as $index => $row) {
            $label = ($index % $step === 0 || $index === $days - 1) ? (string)$row['label'] : '';
            $axis .= '<span class="analytics-axis-label">' . self::e($label) . '</span>';
        }
        $axis .= '</div>';

        if ($type === 'bar') {
            $barSpace = max(20, $height - ($showValues ? 18 : 4));
            $plot = '<div class="analytics-plot analytics-plot--bars' . ($showGrid ? ' analytics-grid' : '') . '" style="height:' . $height . 'px">';

            foreach ($series as $row) {
                $plot .= '<div class="analytics-day" title="' . self::e((string)$row['date']) . '">';
                $plot .= '<div class="analytics-day-bars">';

                foreach ($visible as $key => $definition) {
                    $value = (int)($row[$key] ?? 0);
                    $barHeight = max(2, (int)round($barSpace * ($value / $max)));

                    $plot .= '<div class="analytics-bar-wrap">';
                    if ($showValues) {
                        $plot .= '<span class="analytics-bar-value">' . $value . '</span>';
                    }
                    $plot .= '<div class="analytics-bar analytics-bar--' . $key . '"'
                        . ' style="height:' . $barHeight . 'px;background:' . self::e($color) . ';opacity:' . $definition['opacity'] . '"'
                        . ' title="' . self::e((string)$row['label'] . ': ' . $value . ' ' . $definition['label']) . '"></div>';
                    $plot .= '</div>';
                }

                $plot .= '</div></div>';
            }

            $plot .= '</div>';
        } else {
            $plot = self::renderSvgPlot($series, $visible, $max, $color, $height, $type === 'area', $showValues, $showGrid);
        }

        return '<div class="analytics-chart analytics-chart--' . self::e($type) . '">'
            . $legend
            . $plot
            . $axis
            . '</div>';
    }

    /**
     * Builds the inline SVG for the line/area chart, plus HTML value labels so
     * the text is never stretched by the responsive viewBox.
     */
    private static function renderSvgPlot(
        array $series,
        array $visible,
        int $max,
        string $color,
        int $height,
        bool $area,
        bool $showValues,
        bool $showGrid
    ): string {
        $days = count($series);
        $width = 720;
        $height = max(80, $height);
        $padX = 16;
        $padTop = 16;
        $padBottom = 10;
        $plotWidth = $width - ($padX * 2);
        $plotHeight = $height - $padTop - $padBottom;
        $baseY = $padTop + $plotHeight;

        $xAt = static function (int $index) use ($days, $padX, $plotWidth): float {
            return $days > 1
                ? $padX + ($plotWidth * $index / ($days - 1))
                : $padX + ($plotWidth / 2);
        };
        $yAt = static function (int $value) use ($padTop, $plotHeight, $max): float {
            return $padTop + $plotHeight - (($value / $max) * $plotHeight);
        };

        $svg = '<svg class="analytics-svg" viewBox="0 0 ' . $width . ' ' . $height . '"'
            . ' preserveAspectRatio="none" role="img" aria-hidden="true">';

        if ($showGrid) {
            for ($i = 0; $i <= 4; $i++) {
                $y = $padTop + ($plotHeight * $i / 4);
                $svg .= '<line x1="' . $padX . '" y1="' . round($y, 2) . '"'
                    . ' x2="' . ($width - $padX) . '" y2="' . round($y, 2) . '"'
                    . ' stroke="#94a3b8" stroke-opacity="0.22" stroke-width="1"'
                    . ' stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>';
            }
        }

        // Draw the "all views" series first so unique visitors stay on top.
        foreach ($visible as $key => $definition) {
            $points = [];
            foreach ($series as $index => $row) {
                $points[] = [
                    'x' => $xAt($index),
                    'y' => $yAt((int)($row[$key] ?? 0)),
                    'value' => (int)($row[$key] ?? 0),
                    'label' => (string)$row['label'],
                ];
            }

            if ($points === []) {
                continue;
            }

            if ($area) {
                $areaPath = 'M' . $points[0]['x'] . ',' . $baseY;
                foreach ($points as $point) {
                    $areaPath .= ' L' . $point['x'] . ',' . $point['y'];
                }
                $areaPath .= ' L' . $points[$days - 1]['x'] . ',' . $baseY . ' Z';

                $svg .= '<path d="' . $areaPath . '" fill="' . self::e($color) . '"'
                    . ' fill-opacity="' . ($definition['opacity'] * 0.25) . '" stroke="none"/>';
            }

            $linePath = 'M' . $points[0]['x'] . ',' . $points[0]['y'];
            foreach (array_slice($points, 1) as $point) {
                $linePath .= ' L' . $point['x'] . ',' . $point['y'];
            }

            $svg .= '<path d="' . $linePath . '" fill="none" stroke="' . self::e($color) . '"'
                . ' stroke-opacity="' . $definition['opacity'] . '" stroke-width="2.2"'
                . ' stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';

            foreach ($points as $point) {
                $svg .= '<circle cx="' . round($point['x'], 2) . '" cy="' . round($point['y'], 2) . '" r="3"'
                    . ' fill="' . self::e($color) . '" fill-opacity="' . $definition['opacity'] . '">'
                    . '<title>' . self::e($point['label'] . ': ' . $point['value'] . ' ' . $definition['label']) . '</title>'
                    . '</circle>';
            }
        }

        $svg .= '</svg>';

        $labels = '';
        if ($showValues) {
            $labels .= '<div class="analytics-svg-labels">';
            foreach ($visible as $key => $definition) {
                foreach ($series as $index => $row) {
                    $value = (int)($row[$key] ?? 0);
                    $left = ($xAt($index) / $width) * 100;
                    $top = ($yAt($value) / $height) * 100;
                    $labels .= '<span class="analytics-point-value analytics-point-value--' . $key . '"'
                        . ' style="left:' . round($left, 3) . '%;top:' . round($top, 3) . '%;opacity:' . $definition['opacity'] . '">'
                        . $value . '</span>';
                }
            }
            $labels .= '</div>';
        }

        return '<div class="analytics-plot analytics-plot--svg" style="height:' . $height . 'px">'
            . $svg
            . $labels
            . '</div>';
    }

    private static function setOption(string $key, string $value): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO settings (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = :value'
        );
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    /** @return array{0: string, 1: string} */
    private static function range(int $days): array
    {
        $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $end = date('Y-m-d', strtotime('+1 day'));

        return [$start . ' 00:00:00', $end . ' 00:00:00'];
    }

    private static function clampDays(int $days): int
    {
        return max(1, min(90, $days));
    }

    private static function salt(): string
    {
        return hash('sha256', __DIR__ . '|' . Config::getString('site_url', ''));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
