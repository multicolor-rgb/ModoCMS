<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/views/header.php';

use Core\Database;
use Core\Security;
use Core\Auth;
use Core\Hooks;
use Core\Sitemap;

$db = Database::getConnection();
$msg = '';

// Handle Quick Draft submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_draft') {
    if (Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $draftTitle = trim($_POST['draft_title'] ?? '');
        $draftContent = trim($_POST['draft_content'] ?? '');

        if ($draftTitle !== '') {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $draftTitle), '-'));
            $stmt = $db->prepare("INSERT INTO pages (title, slug, content, type, status, lang, translation_group, author_id) VALUES (:t, :s, :c, 'post', 'draft', :l, :g, :a)");
            $stmt->execute([
                ':t' => $draftTitle,
                ':s' => $slug ?: 'draft-' . bin2hex(random_bytes(3)),
                ':c' => nl2br(htmlspecialchars($draftContent, ENT_QUOTES, 'UTF-8')),
                ':l' => \Core\I18n::getDefaultLocale(),
                ':g' => bin2hex(random_bytes(8)),
                ':a' => Auth::id()
            ]);

            // Automatyczna aktualizacja sitemapy Google
            if (class_exists('Core\Sitemap')) {
                try {
                    Sitemap::generate();
                } catch (\Throwable $e) {}
            }

            $msg = __('Draft saved successfully.');
        }
    }
}

// 1. High-level metric counts
$totalPages = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'page'")->fetchColumn();
$totalPosts = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'post'")->fetchColumn();
$totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// 2. Traffic analytics (today and last 7 days)
$todayVisits = (int)$db->query("SELECT COUNT(*) FROM visits WHERE visited_at = DATE('now')")->fetchColumn();
$weekVisits  = (int)$db->query("SELECT COUNT(*) FROM visits WHERE visited_at >= DATE('now', '-6 days')")->fetchColumn();

// 7-day sparkline bar chart data aggregation
$chartQuery = $db->query("
    SELECT d.dt as date, COUNT(v.id) as count 
    FROM (
        SELECT DATE('now', '-6 days') as dt UNION ALL
        SELECT DATE('now', '-5 days') UNION ALL
        SELECT DATE('now', '-4 days') UNION ALL
        SELECT DATE('now', '-3 days') UNION ALL
        SELECT DATE('now', '-2 days') UNION ALL
        SELECT DATE('now', '-1 days') UNION ALL
        SELECT DATE('now')
    ) d
    LEFT JOIN visits v ON v.visited_at = d.dt
    GROUP BY d.dt ORDER BY d.dt ASC
");
$chartData = $chartQuery->fetchAll();

// Top visited paths (last 7 days)
$topPages = $db->query("
    SELECT path, COUNT(*) as hits 
    FROM visits 
    WHERE visited_at >= DATE('now', '-6 days')
    GROUP BY path 
    ORDER BY hits DESC 
    LIMIT 5
")->fetchAll();

// 3. Recently updated pages and blog posts
$recentContent = $db->query("
    SELECT p.id, p.title, p.slug, p.type, p.status, p.updated_at, u.username as author_name 
    FROM pages p 
    LEFT JOIN users u ON p.author_id = u.id 
    ORDER BY p.updated_at DESC 
    LIMIT 6
")->fetchAll();
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('System Overview') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Summary of your website activity and resources') ?></p>
    </div>
    <div style="display:flex; gap: 8px;">
        <a href="page-edit.php?type=post" class="btn btn-primary">+ <?= _e('New Post') ?></a>
        <a href="page-edit.php?type=page" class="btn btn-secondary">+ <?= _e('New Page') ?></a>
    </div>
</div>

<?php if ($msg): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<!-- Primary Metrics Row -->
<div class="metrics-grid">
    <div class="metric-card">
        <span class="metric-label"><?= _e('Pageviews (Today)') ?></span>
        <span class="metric-value" style="color: var(--primary);"><?= $todayVisits ?></span>
    </div>
    <div class="metric-card">
        <span class="metric-label"><?= _e('Pageviews (7 Days)') ?></span>
        <span class="metric-value"><?= $weekVisits ?></span>
    </div>
    <div class="metric-card">
        <span class="metric-label"><?= _e('Blog Posts') ?> / <?= _e('Pages') ?></span>
        <span class="metric-value"><?= $totalPosts ?> <span style="font-size:16px; font-weight:normal; color:var(--text-muted);">/ <?= $totalPages ?></span></span>
    </div>
    <div class="metric-card">
        <span class="metric-label"><?= _e('Registered Users') ?></span>
        <span class="metric-value"><?= $totalUsers ?></span>
    </div>
</div>

<!-- Dashboard Main Grid: Analytics & Recent Content vs Sidebar Modules -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">

    <!-- LEFT COLUMN: Traffic Analytics & Recent Content -->
    <div>
        <!-- Analytics & Popular Pages Card -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px;">
                <h2 style="font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0;"><?= _e('Traffic (Last 7 Days)') ?></h2>
                <span class="badge" style="background: var(--primary-subtle); color: var(--primary);"><?= _e('Daily Unique') ?></span>
            </div>

            <!-- CSS Bar Chart (Zero dependencies) -->
            <?php
            $maxVisits = 1;
            foreach ($chartData as $cd) {
                if ($cd['count'] > $maxVisits) $maxVisits = $cd['count'];
            }
            ?>
            <div style="display: flex; align-items: flex-end; gap: 12px; height: 110px; padding-top: 15px; border-bottom: 1px solid var(--border-subtle);">
                <?php foreach ($chartData as $bar): 
                    $h = max(6, (int)(($bar['count'] / $maxVisits) * 85));
                    $dayLabel = date('d.m', strtotime($bar['date']));
                ?>
                    <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end;">
                        <span style="font-size: 11px; font-weight: 600; color: var(--text-muted);"><?= $bar['count'] ?></span>
                        <div style="width: 100%; max-width: 32px; height: <?= $h ?>px; background: linear-gradient(180deg, var(--primary), #818cf8); border-radius: 4px 4px 0 0;" title="<?= $bar['date'] ?>: <?= $bar['count'] ?> views"></div>
                        <span style="font-size: 10px; color: var(--text-muted);"><?= $dayLabel ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Top Visited Routes -->
            <div style="margin-top: 16px;">
                <span style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;"><?= _e('Top Visited Pages') ?></span>
                <table class="pro-table" style="margin-top: 8px;">
                    <tbody>
                        <?php if (empty($topPages)): ?>
                            <tr><td style="color: var(--text-muted); font-size: 12px; padding: 12px 0; border: none;"><?= _e('No visit records available for this week.') ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($topPages as $tp): ?>
                                <tr>
                                    <td style="font-family: monospace; font-size: 12px; padding: 6px 0; border: none; color: var(--text-main);"><?= Security::sanitize($tp['path']) ?></td>
                                    <td style="text-align: right; font-weight: 600; padding: 6px 0; border: none; color: var(--text-main);"><?= $tp['hits'] ?> <?= _e('views') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Content Card -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 14px;">
                <h2 style="font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0;"><?= _e('Recently Updated') ?></h2>
                <a href="pages.php" style="font-size: 12px; color: var(--primary); text-decoration: none; font-weight: 600;"><?= _e('View All') ?> &rarr;</a>
            </div>

            <div class="table-container" style="border: none; box-shadow: none;">
                <table class="pro-table">
                    <thead>
                        <tr>
                            <th><?= _e('Title') ?></th>
                            <th><?= _e('Type') ?></th>
                            <th><?= _e('Status') ?></th>
                            <th><?= _e('Updated') ?></th>
                            <th style="text-align: right;"><?= _e('Action') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentContent as $rc): ?>
                            <tr>
                                <td>
                                    <strong><a href="page-edit.php?id=<?= $rc['id'] ?>" style="color: var(--text-main); text-decoration: none;"><?= Security::sanitize($rc['title']) ?></a></strong>
                                    <div style="font-size: 11px; color: var(--text-muted); font-family: monospace;">/<?= Security::sanitize($rc['slug']) ?></div>
                                </td>
                                <td>
                                    <span class="badge" style="background: var(--bg-surface, #1e293b); color: var(--text-muted); font-size: 11px; border: 1px solid var(--border-subtle);">
                                        <?= $rc['type'] === 'post' ? _e('Post') : _e('Page') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $rc['status'] === 'published' ? 'badge-success' : 'badge-warning' ?>" style="font-size: 11px;">
                                        <?= $rc['status'] === 'published' ? _e('Published') : _e('Draft') ?>
                                    </span>
                                </td>
                                <td style="font-size: 12px; color: var(--text-muted);"><?= date('d.m H:i', strtotime($rc['updated_at'])) ?></td>
                                <td style="text-align: right;">
                                    <a href="page-edit.php?id=<?= $rc['id'] ?>" class="btn btn-secondary" style="padding: 3px 8px; font-size: 11px;"><?= _e('Edit') ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: Quick Draft & Environment Specs -->
    <div>
        <!-- Quick Draft Widget -->
        <div class="card">
            <h2 style="font-size: 15px; font-weight: 700; margin-bottom: 6px; color: var(--text-main);"><?= _e('Quick Draft') ?></h2>
            <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 14px;"><?= _e('Capture an idea quickly. It will be saved as an unpublished blog post draft.') ?></p>

            <form method="POST" action="">
                <input type="hidden" name="action" value="quick_draft">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

                <div class="form-group" style="margin-bottom: 12px;">
                    <label class="form-label" style="font-size: 12px;"><?= _e('Draft Title') ?></label>
                    <input type="text" name="draft_title" class="form-control" placeholder="<?= _e('Enter draft title...') ?>" required>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-size: 12px;"><?= _e('Content / Notes') ?></label>
                    <textarea name="draft_content" class="form-control" rows="4" placeholder="<?= _e('What is on your mind?') ?>"></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; font-size: 13px;"><?= _e('Save as Draft') ?></button>
            </form>
        </div>

        <!-- System & Environment Information Widget -->
        <div class="card">
            <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 12px; color: var(--text-main);"><?= _e('Environment') ?></h3>
            <ul style="list-style: none; font-size: 13px; display: flex; flex-direction: column; gap: 8px; color: var(--text-muted); padding: 0; margin: 0;">
                <li style="display: flex; justify-content: space-between;">
                    <span><?= _e('PHP Version') ?>:</span> <strong style="color: var(--text-main);"><?= PHP_VERSION ?></strong>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span><?= _e('Database Engine') ?>:</span> <strong style="color: var(--text-main);">SQLite 3 (WAL)</strong>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span><?= _e('Multilingual Mode') ?>:</span> <strong style="color: var(--text-main);"><?= \Core\Router::getOption('multilingual_frontend') === '1' ? _e('Enabled') : _e('Single') ?></strong>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span><?= _e('Active Theme') ?>:</span> <strong style="color: var(--text-main);"><?= htmlspecialchars(\Core\Router::getOption('active_theme', 'default'), ENT_QUOTES, 'UTF-8') ?></strong>
                </li>
            </ul>
        </div>

        <?php Hooks::doAction('admin_dashboard_sidebar'); ?>
    </div>
</div>

<?php Hooks::doAction('admin_dashboard_widgets'); ?>
<?php require_once __DIR__ . '/views/footer.php'; ?>