<?php
/**
 * System tab: one-click updater for the latest public ModoCMS release.
 *
 * Expected variables:
 *   $updateState  array  Updater::state() or Updater::check() result
 *   $updateReport ?array last check/update report from the session
 */
declare(strict_types=1);

$currentVersion = (string)($updateState['current'] ?? \Core\Version::current());
$latestVersion = (string)($updateState['latest'] ?? '');
$hasUpdate = !empty($updateState['has_update']);
$isAhead = $latestVersion !== ''
    && version_compare(\Core\Version::comparable($latestVersion), \Core\Version::comparable($currentVersion), '<');
$release = is_array($updateState['release'] ?? null) ? $updateState['release'] : null;
$checkedAt = (int)($updateState['checked_at'] ?? 0);
$updateError = (string)($updateState['error'] ?? '');
$requirements = \Core\Updater::requirements();
$canUpdate = \Core\Updater::canUpdate();

$releaseUrl = $release !== null ? (string)($release['html_url'] ?? '') : '';
$releaseName = $release !== null ? (string)($release['name'] ?? '') : '';
$publishedAt = $release !== null ? (string)($release['published_at'] ?? '') : '';
$releaseNotes = $release !== null ? (string)($release['body'] ?? '') : '';
$releaseSource = $release !== null ? (string)($release['source'] ?? 'api') : '';

$missing = [];
if (!$requirements['zip']) {
    $missing[] = __('The update requires the PHP ZipArchive extension.');
}
if (!$requirements['network']) {
    $missing[] = __('Outgoing HTTP requests are unavailable - enable cURL or allow_url_fopen.');
}
if (!$requirements['root']
    || !$requirements['core']
    || !$requirements['admin']
    || !$requirements['languages']
    || !$requirements['themes']
    || !$requirements['plugins']
    || !$requirements['data']
    || !$requirements['backups']) {
    $missing[] = __('The installation is not writable. Check the file permissions in Settings -> System.');
}

$badge = static function (string $label, string $color): string {
    return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;'
        . 'color:' . $color . ';background:' . $color . '1f;border:1px solid ' . $color . '55;">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
};

$reportMode = is_array($updateReport) ? (string)($updateReport['mode'] ?? '') : '';
$reportResult = is_array($updateReport['result'] ?? null) ? $updateReport['result'] : null;
?>
<div class="card settings-panel" data-tab="system">
    <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
        <?= _e('System Updates') ?>
    </h3>
    <p style="font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-bottom: 16px;">
        <?= _e('Check the latest public ModoCMS release and install it with one click. A database snapshot and a backup of every replaced file are created automatically.') ?>
    </p>

    <?php if ($reportResult !== null): ?>
        <?php
        $reportOk = !empty($reportResult['ok']);
        $reportError = (string)($reportResult['error'] ?? '');
        $reportBackup = (string)($reportResult['backup_dir'] ?? '');
        $reportDbBackup = (string)($reportResult['db_backup'] ?? '');
        $borderColor = $reportOk ? '#10b981' : '#ef4444';
        $background = $reportOk ? 'rgba(16, 185, 129, 0.08)' : 'rgba(239, 68, 68, 0.08)';
        $textColor = $reportOk ? '#34d399' : '#f87171';
        ?>
        <div style="border-left: 4px solid <?= $borderColor ?>; background: <?= $background ?>; padding: 12px 16px; margin-bottom: 16px; border-radius: var(--radius-sm); color: <?= $textColor ?>; font-size: 13px; line-height: 1.6;">
            <?php if ($reportMode === 'check'): ?>
                <?php if ($reportOk): ?>
                    <strong><?= !empty($reportResult['has_update']) ? _e('Update available') : _e('You are running the latest version.') ?></strong>
                    <?php if (!empty($reportResult['has_update'])): ?>
                        <div><?= sprintf(__('A newer release is available: %s'), htmlspecialchars((string)($reportResult['latest'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <strong><?= _e('The update could not be checked.') ?></strong>
                    <div><?= \Core\Security::sanitize($reportError) ?></div>
                <?php endif; ?>
            <?php elseif ($reportMode === 'update'): ?>
                <?php if ($reportOk): ?>
                    <strong><?= _e('The update completed successfully.') ?></strong>
                    <div>
                        <?= sprintf(
                            __('Updated from %s to %s.'),
                            htmlspecialchars((string)($reportResult['from'] ?? ''), ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars((string)($reportResult['to'] ?? ''), ENT_QUOTES, 'UTF-8')
                        ) ?>
                    </div>
                    <div>
                        <?= sprintf(
                            __('Files replaced: %d, new files: %d, skipped: %d.'),
                            (int)($reportResult['replaced'] ?? 0),
                            (int)($reportResult['added'] ?? 0),
                            count((array)($reportResult['skipped'] ?? []))
                        ) ?>
                    </div>
                <?php else: ?>
                    <strong><?= _e('The update could not be installed.') ?></strong>
                    <div><?= \Core\Security::sanitize($reportError) ?></div>
                    <?php if (!empty($reportResult['rolled_back'])): ?>
                        <div><?= _e('The files were restored from the automatic backup.') ?></div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($reportBackup !== ''): ?>
                    <div style="margin-top: 6px;"><?= sprintf(__('Backup: %s'), '<code style="font-size:11px;">' . \Core\Security::sanitize($reportBackup) . '</code>') ?></div>
                <?php endif; ?>
                <?php if ($reportDbBackup !== ''): ?>
                    <div><?= sprintf(__('Database snapshot: %s'), '<code style="font-size:11px;">' . \Core\Security::sanitize($reportDbBackup) . '</code>') ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px;">
        <div style="padding: 12px 14px; border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); background: var(--bg-surface, #1e293b);">
            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted);"><?= _e('Current version') ?></div>
            <div style="font-size: 16px; font-weight: 700; margin-top: 4px; color: var(--text-main);"><?= htmlspecialchars($currentVersion, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div style="padding: 12px 14px; border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); background: var(--bg-surface, #1e293b);">
            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted);"><?= _e('Latest version') ?></div>
            <div style="font-size: 16px; font-weight: 700; margin-top: 4px; color: var(--text-main);"><?= $latestVersion !== '' ? htmlspecialchars($latestVersion, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></div>
        </div>
        <div style="padding: 12px 14px; border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); background: var(--bg-surface, #1e293b); display: flex; align-items: center;">
            <?php if ($hasUpdate): ?>
                <?= $badge(__('Update available'), '#f59e0b') ?>
            <?php elseif ($isAhead): ?>
                <?= $badge(__('Development version'), '#6366f1') ?>
            <?php elseif ($latestVersion !== ''): ?>
                <?= $badge(__('Up to date'), '#10b981') ?>
            <?php else: ?>
                <?= $badge(__('Not checked yet'), '#94a3b8') ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isAhead): ?>
        <p style="font-size: 12px; color: var(--text-muted); margin: -4px 0 14px;">
            <?= _e('Your version is newer than the latest public release.') ?>
        </p>
    <?php endif; ?>

    <?php if ($checkedAt > 0): ?>
        <p style="font-size: 12px; color: var(--text-muted); margin: -4px 0 14px;">
            <?= sprintf(__('Last checked: %s'), htmlspecialchars(date('Y-m-d H:i', $checkedAt), ENT_QUOTES, 'UTF-8')) ?>
        </p>
    <?php endif; ?>

    <?php if ($updateError !== ''): ?>
        <div style="border-left: 4px solid #f59e0b; background: rgba(245, 158, 11, 0.08); padding: 10px 14px; margin-bottom: 14px; border-radius: var(--radius-sm); color: #fbbf24; font-size: 12px; line-height: 1.5;">
            <?= \Core\Security::sanitize($updateError) ?>
            <?php if (stripos($updateError, 'rate limit') !== false): ?>
                <div style="margin-top: 6px;"><?= _e('Add a GitHub token in Settings -> System -> Production hardening to raise the limit.') ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($release !== null): ?>
        <div style="padding: 12px 14px; border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); margin-bottom: 14px; background: var(--bg-surface, #1e293b);">
            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 12px; color: var(--text-muted);">
                <strong style="color: var(--text-main);"><?= htmlspecialchars($releaseName, ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if ($releaseSource === 'atom'): ?>
                    <?= $badge(__('Public releases feed'), '#6366f1') ?>
                <?php endif; ?>
                <?php if ($publishedAt !== ''): ?>
                    <span><?= sprintf(__('Published: %s'), htmlspecialchars(substr($publishedAt, 0, 10), ENT_QUOTES, 'UTF-8')) ?></span>
                <?php endif; ?>
                <?php if ($releaseUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($releaseUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" style="color: var(--primary, #3b82f6);">
                        <?= _e('Release notes') ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($releaseSource === 'atom'): ?>
                <p style="margin: 8px 0 0; font-size: 11px; color: var(--text-muted); line-height: 1.5;">
                    <?= _e('The GitHub API limit was reached, so the latest release was read from the public releases feed.') ?>
                </p>
            <?php endif; ?>

            <?php if ($releaseNotes !== ''): ?>
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer; font-size: 12px; font-weight: 600; color: var(--primary, #3b82f6);">
                        <?= _e('Show release description') ?>
                    </summary>
                    <div style="margin-top: 10px; max-height: 320px; overflow: auto; font-size: 12px; line-height: 1.6; color: var(--text-muted); white-space: pre-wrap;"><?= nl2br(htmlspecialchars($releaseNotes, ENT_QUOTES, 'UTF-8')) ?></div>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($missing !== []): ?>
        <div style="border-left: 4px solid #ef4444; background: rgba(239, 68, 68, 0.08); padding: 12px 16px; margin-bottom: 14px; border-radius: var(--radius-sm); color: #f87171; font-size: 12px; line-height: 1.6;">
            <strong><?= _e('The automatic update is not available on this server.') ?></strong>
            <ul style="margin: 6px 0 0 18px; padding: 0;">
                <?php foreach ($missing as $reason): ?>
                    <li><?= \Core\Security::sanitize($reason) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <form method="POST" action="settings.php" style="margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= \Core\Security::generateCsrfToken() ?>">
            <input type="hidden" name="action" value="update_check">
            <button type="submit" class="btn btn-secondary" style="font-size: 12px;">
                <?= _e('Check for updates') ?>
            </button>
        </form>

        <?php if ($hasUpdate): ?>
            <form method="POST" action="settings.php" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= \Core\Security::generateCsrfToken() ?>">
                <input type="hidden" name="action" value="update_run">
                <button type="submit" class="btn btn-primary" style="font-size: 12px;"
                        <?= $canUpdate ? '' : 'disabled title="' . htmlspecialchars(__('The automatic update is not available on this server.'), ENT_QUOTES, 'UTF-8') . '"' ?>
                        <?php if ($canUpdate): ?>
                            data-confirm
                            data-confirm-title="<?= _e('Update ModoCMS') ?>"
                            data-confirm-message="<?= _e('The update will replace the system, admin, bundled theme and bundled plugin files. A backup is created automatically. Continue?') ?>"
                            data-confirm-ok="<?= _e('Update now') ?>"
                            data-confirm-danger
                        <?php endif; ?>>
                    <?= sprintf(__('Update to %s'), htmlspecialchars($latestVersion, ENT_QUOTES, 'UTF-8')) ?>
                </button>
            </form>
        <?php endif; ?>

        <?php if ($releaseUrl !== ''): ?>
            <a href="<?= htmlspecialchars($releaseUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="font-size: 12px;">
                <?= _e('Open release page') ?>
            </a>
        <?php endif; ?>
    </div>

    <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border-subtle); font-size: 12px; color: var(--text-muted); line-height: 1.6;">
        <p style="margin: 0 0 6px;"><strong style="color: var(--text-main);"><?= _e('Automatic backup') ?></strong></p>
        <p style="margin: 0 0 6px;"><?= _e('Before updating, ModoCMS creates a database snapshot and a backup of every replaced file in data/backups/.') ?></p>
        <p style="margin: 0;"><?= _e('The update replaces core, admin, bundled themes and bundled plugins. Your content, uploads, modoconfig.php and custom themes or plugins with different names are left untouched.') ?></p>
    </div>
</div>
