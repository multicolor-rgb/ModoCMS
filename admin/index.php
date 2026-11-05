<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/views/header.php';

use Core\Database;
use Core\Security;
use Core\Auth;
use Core\Hooks;
use Core\PageTypes;
use Core\SiteHealth;
use Core\Sitemap;

$db = Database::getConnection();
$pageTypes = PageTypes::all();
$msg = '';

// Handle Quick Draft submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_draft') {
    if (Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        Auth::requireCapability('create_pages');
        $draftTitle = trim($_POST['draft_title'] ?? '');
        $draftContent = trim($_POST['draft_content'] ?? '');

        if ($draftTitle !== '') {
            $draftLang = \Core\I18n::getDefaultLocale();
            $slug = \Core\PageSlug::slugify($draftTitle);
            $requestedSlug = $slug;
            $slug = \Core\PageSlug::unique($slug !== '' ? $slug : 'draft', $draftLang);
            $stmt = $db->prepare("INSERT INTO pages (title, slug, content, type, status, lang, translation_group, author_id) VALUES (:t, :s, :c, 'post', 'draft', :l, :g, :a)");
            $stmt->execute([
                ':t' => $draftTitle,
                ':s' => $slug,
                ':c' => nl2br(htmlspecialchars($draftContent, ENT_QUOTES, 'UTF-8')),
                ':l' => $draftLang,
                ':g' => bin2hex(random_bytes(8)),
                ':a' => Auth::id()
            ]);

            // Automatyczna aktualizacja sitemapy Google
            if (class_exists('Core\Sitemap')) {
                try {
                    Sitemap::generate();
                } catch (\Throwable $e) {}
            }

            \Core\Audit::log('page.quick_draft', 'page:' . (int)$db->lastInsertId());
            $msg = __('Draft saved successfully.');
            if ($slug !== $requestedSlug) {
                $msg .= ' ' . sprintf(__('The slug was already in use, so it was changed to: %s'), $slug);
            }
        }
    }
}

// 1. High-level metric counts
$totalPages = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'page' AND deleted_at IS NULL")->fetchColumn();
$totalPosts = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'post' AND deleted_at IS NULL")->fetchColumn();
$totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// 2. Traffic analytics (all views + unique visitors)
$analyticsSummary = \Core\Analytics::summary();
$analyticsMode    = \Core\Analytics::mode();
$analyticsPeriod  = (int)($analyticsSummary['days'] ?? 7);
$todayAll         = (int)($analyticsSummary['totals']['today']['all'] ?? 0);
$todayUnique      = (int)($analyticsSummary['totals']['today']['unique'] ?? 0);
$periodAll        = (int)($analyticsSummary['totals']['period']['all'] ?? 0);
$periodUnique     = (int)($analyticsSummary['totals']['period']['unique'] ?? 0);
$analyticsLastReset = \Core\Analytics::lastReset();
$showAllViews      = $analyticsMode !== 'unique';
$showUniqueViews   = $analyticsMode !== 'all';

// Top visited paths for the selected period
$topPages = \Core\Analytics::topPages();

// 3. Recently updated pages and blog posts
$recentContent = $db->query("
    SELECT p.id, p.title, p.slug, p.type, p.status, p.updated_at, u.username as author_name 
    FROM pages p 
    LEFT JOIN users u ON p.author_id = u.id 
    WHERE p.deleted_at IS NULL
    ORDER BY p.updated_at DESC 
    LIMIT 6
")->fetchAll();
?>

<?php
$healthRows = SiteHealth::checks();
$healthScore = SiteHealth::score($healthRows);
$healthIssues = SiteHealth::issues($healthRows);
$healthOk = count($healthRows) - count($healthIssues);
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('System Overview') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Summary of your website activity and resources') ?></p>
    </div>
    <div style="display:flex; gap: 8px; align-items:center; flex-wrap:wrap;">
        <?php if (!empty($pageTypes)): ?>
            <select class="form-control" style="width:auto; padding:8px 12px; font-size:12px;" onchange="if (this.value) { const opt = this.options[this.selectedIndex]; location.href = 'page-edit.php?type=' + encodeURIComponent(opt.dataset.base || 'page') + '&page_type=' + encodeURIComponent(this.value); }">
                <option value="">+ <?= _e('New from type...') ?></option>
                <?php foreach ($pageTypes as $preset): ?>
                    <option value="<?= htmlspecialchars((string) $preset['slug'], ENT_QUOTES, 'UTF-8') ?>" data-base="<?= htmlspecialchars((string) $preset['base_type'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= Security::sanitize((string) $preset['name']) ?> (<?= $preset['base_type'] === 'post' ? _e('Post') : _e('Page') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <a href="page-edit.php?type=post" class="btn btn-primary">+ <?= _e('New Post') ?></a>
        <a href="page-edit.php?type=page" class="btn btn-secondary">+ <?= _e('New Page') ?></a>
    </div>
</div>

<?php if ($msg): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php
$healthRows = SiteHealth::checks();
$healthScore = SiteHealth::score($healthRows);
$healthIssues = SiteHealth::issues($healthRows);
$healthOk = count($healthRows) - count($healthIssues);
?>
<div class="card" style="padding: 18px 20px; margin-bottom: 22px; border-left: 4px solid <?= $healthScore >= 80 ? 'var(--success, #10b981)' : ($healthScore >= 50 ? 'var(--warning, #f59e0b)' : 'var(--danger, #ef4444)') ?>;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 12px;">
        <div>
            <h2 style="font-size: 15px; font-weight: 700; margin: 0 0 4px; color: var(--text-main);"><?= _e('Site Health') ?></h2>
            <p style="font-size: 12px; color: var(--text-muted); margin: 0;">
                <?= sprintf(__('%d of %d checks are healthy.'), $healthOk, count($healthRows)) ?>
            </p>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <strong style="font-size: 24px; color: <?= $healthScore >= 80 ? 'var(--success, #10b981)' : ($healthScore >= 50 ? 'var(--warning, #f59e0b)' : 'var(--danger, #ef4444)') ?>;"><?= (int)$healthScore ?>%</strong>
            <a href="settings.php?tab=system" class="btn btn-secondary" style="font-size: 12px;"><?= _e('System status') ?></a>
        </div>
    </div>

    <?php if (empty($healthIssues)): ?>
        <p style="font-size: 13px; color: var(--success, #10b981); margin: 0;"><?= _e('All checks passed. Your site is in good shape.') ?></p>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px;">
            <?php foreach (array_slice($healthIssues, 0, 4) as $issue): ?>
                <div style="display: flex; gap: 10px; align-items: flex-start; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); border-radius: 8px; padding: 10px 12px;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; flex: 0 0 auto; background: <?= $issue['status'] === 'error' ? 'var(--danger, #ef4444)' : 'var(--warning, #f59e0b)' ?>;"></span>
                    <div style="min-width: 0;">
                        <div style="font-size: 12px; font-weight: 700; color: var(--text-main);"><?= Security::sanitize($issue['label']) ?></div>
                        <div style="font-size: 11px; color: var(--text-muted); line-height: 1.45; margin-top: 2px;"><?= Security::sanitize($issue['detail']) ?></div>
                        <?php if ($issue['action'] !== ''): ?>
                            <a href="<?= htmlspecialchars($issue['action'], ENT_QUOTES, 'UTF-8') ?>" style="font-size: 11px; color: var(--accent, #60a5fa); display: inline-block; margin-top: 4px;"><?= _e('Fix') ?> &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($healthIssues) > 4): ?>
            <p style="font-size: 11px; color: var(--text-muted); margin: 10px 0 0;"><?= sprintf(__('%d more check(s) need attention.'), count($healthIssues) - 4) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Primary Metrics Row -->
<div class="metrics-grid">
    <div class="metric-card">
        <span class="metric-label"><?= _e('Pageviews (Today)') ?></span>
        <span class="metric-value" style="color: var(--primary);"><?= $showAllViews ? $todayAll : $todayUnique ?> <span style="font-size:14px; font-weight:500; color:var(--text-muted);"><?= $showAllViews ? _e('all') : _e('unique') ?></span></span>
        <?php if ($showAllViews && $showUniqueViews): ?>
            <span style="font-size: 12px; color: var(--text-muted);"><?= $todayUnique ?> <?= _e('unique') ?></span>
        <?php endif; ?>
    </div>
    <div class="metric-card">
        <span class="metric-label"><?= sprintf(__('Pageviews (%d Days)'), $analyticsPeriod) ?></span>
        <span class="metric-value"><?= $showAllViews ? $periodAll : $periodUnique ?> <span style="font-size:14px; font-weight:500; color:var(--text-muted);"><?= $showAllViews ? _e('all') : _e('unique') ?></span></span>
        <?php if ($showAllViews && $showUniqueViews): ?>
            <span style="font-size: 12px; color: var(--text-muted);" title="<?= _e('Sum of daily unique visitors') ?>"><?= $periodUnique ?> <?= _e('unique') ?></span>
        <?php endif; ?>
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
<div class="dashboard-layout">

    <!-- LEFT COLUMN: Traffic Analytics & Recent Content -->
    <div>
        <!-- Analytics & Popular Pages Card -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px; gap: 12px; flex-wrap: wrap;">
                <h2 style="font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0;"><?= sprintf(__('Traffic (Last %d Days)'), $analyticsPeriod) ?></h2>
                <span class="badge" style="background: var(--primary-subtle); color: var(--primary);">
                    <?= $analyticsMode === 'unique' ? _e('Unique views') : ($analyticsMode === 'all' ? _e('All views') : _e('Unique + all')) ?>
                </span>
            </div>

            <?= \Core\Analytics::renderChart($analyticsSummary, $analyticsMode) ?>

            <?php if ($analyticsLastReset !== ''): ?>
                <p style="font-size: 11px; color: var(--text-muted); margin: 8px 0 0 0;">
                    <?= sprintf(__('Statistics counted since the last reset: %s'), date('d.m.Y H:i', strtotime($analyticsLastReset))) ?>
                </p>
            <?php endif; ?>

            <!-- Top Visited Routes -->
            <div style="margin-top: 16px;">
                <span style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;"><?= _e('Top Visited Pages') ?></span>
                <table class="pro-table" style="margin-top: 8px;">
                    <thead>
                        <tr>
                            <th><?= _e('Page') ?></th>
                            <?php if ($analyticsMode !== 'unique'): ?>
                                <th style="text-align: right;"><?= _e('All views') ?></th>
                            <?php endif; ?>
                            <?php if ($analyticsMode !== 'all'): ?>
                                <th style="text-align: right;"><?= _e('Unique views') ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topPages)): ?>
                            <tr><td colspan="3" style="color: var(--text-muted); font-size: 12px; padding: 12px 0; border: none;"><?= _e('No visit records available for this period.') ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($topPages as $tp): ?>
                                <tr>
                                    <td style="font-family: monospace; font-size: 12px; padding: 6px 0; border: none; color: var(--text-main);"><?= Security::sanitize($tp['path']) ?></td>
                                    <?php if ($analyticsMode !== 'unique'): ?>
                                        <td style="text-align: right; font-weight: 600; padding: 6px 0; border: none; color: var(--text-main);"><?= (int)$tp['all'] ?></td>
                                    <?php endif; ?>
                                    <?php if ($analyticsMode !== 'all'): ?>
                                        <td style="text-align: right; font-weight: 600; padding: 6px 0; border: none; color: var(--text-muted);"><?= (int)$tp['unique'] ?></td>
                                    <?php endif; ?>
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