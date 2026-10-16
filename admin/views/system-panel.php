<?php
/**
 * System tab of the Settings screen.
 *
 * Renders the server requirements, the filesystem permission report and the
 * .htaccess hardening layer provided by Core\Setup, so an installation can be
 * audited and repaired from the browser.
 *
 * Expected variables:
 *   $setupAudit             array  Setup::audit()
 *   $setupSummary           array  Setup::auditSummary($setupAudit)
 *   $setupRequirements      array  Setup::requirements()
 *   $setupRequirementErrors int    Setup::requirementErrors($setupRequirements)
 *   $setupPermissions       array  Setup::permissions()
 *   $setupPermissionErrors  int    Setup::permissionErrors($setupPermissions)
 *   $setupOwner             string Setup::processOwner()
 *   $setupDeploy            ?array last deployHtaccess() result with a "force" flag
 */
declare(strict_types=1);

$severityColors = [
    'success' => '#10b981',
    'warning' => '#f59e0b',
    'danger'  => '#ef4444',
    'info'    => '#6366f1',
    'muted'   => '#94a3b8',
];
$statusBadge = static function (string $label, string $severity) use ($severityColors): string {
    $color = $severityColors[$severity] ?? '#94a3b8';

    return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;'
        . 'color:' . $color . ';background:' . $color . '1f;border:1px solid ' . $color . '55;">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
};
$codeCell = static function (string $value): string {
    return '<code style="font-size:11px;word-break:break-all;color:var(--text-muted);">'
        . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</code>';
};
?>

<!-- ===================== Server requirements ===================== -->
<div class="card settings-panel" data-tab="system">
    <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
        <?= _e('Server Requirements') ?>
    </h3>
    <p style="font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-bottom: 16px;">
        <?= _e('Environment reported by PHP for this installation. Rows marked as blocking have to be fixed, otherwise parts of the CMS stop working.') ?>
    </p>

    <?php if ($setupRequirementErrors > 0): ?>
        <div style="border-left: 4px solid var(--danger, #ef4444); background: rgba(239, 68, 68, 0.08); padding: 12px 16px; margin-bottom: 16px; border-radius: var(--radius-sm); color: #f87171; font-weight: 600; font-size: 13px;">
            <?= sprintf(__('%d blocking requirement problem(s) detected - see the rows highlighted below.'), $setupRequirementErrors) ?>
        </div>
    <?php else: ?>
        <div style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 16px; border-radius: var(--radius-sm); color: #34d399; font-weight: 600; font-size: 13px;">
            <?= _e('All blocking requirements are met.') ?>
        </div>
    <?php endif; ?>

    <div class="table-container">
        <table class="pro-table">
            <thead>
                <tr>
                    <th style="width: 28%;"><?= _e('Check') ?></th>
                    <th style="width: 22%;"><?= _e('Value') ?></th>
                    <th style="width: 12%;"><?= _e('Status') ?></th>
                    <th style="width: 38%;"><?= _e('Notes') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($setupRequirements as $row): ?>
                    <?php
                        $ok = !empty($row['ok']);
                        $severity = (string)($row['severity'] ?? 'info');
                        if ($ok) {
                            $stateLabel = __('OK');
                            $stateSeverity = 'success';
                        } elseif ($severity === 'error') {
                            $stateLabel = __('Blocking');
                            $stateSeverity = 'danger';
                        } else {
                            $stateLabel = __('Warning');
                            $stateSeverity = 'warning';
                        }
                    ?>
                    <tr>
                        <td><strong><?= \Core\Security::sanitize((string)$row['label']) ?></strong></td>
                        <td><?= $codeCell((string)$row['value']) ?></td>
                        <td><?= $statusBadge($stateLabel, $stateSeverity) ?></td>
                        <td style="font-size: 12px; color: var(--text-muted); line-height: 1.5;">
                            <?= ($ok && $severity !== 'info') ? '' : \Core\Security::sanitize((string)($row['hint'] ?? '')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================== File permissions ===================== -->
<div class="card settings-panel" data-tab="system">
    <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
        <?= _e('File Permissions') ?>
    </h3>
    <p style="font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-bottom: 16px;">
        <?= sprintf(__('Directories and files the CMS has to write to. PHP currently runs as "%s" - the ownership commands below use that user.'), htmlspecialchars($setupOwner, ENT_QUOTES, 'UTF-8')) ?>
    </p>

    <?php if ($setupPermissionErrors > 0): ?>
        <div style="border-left: 4px solid var(--danger, #ef4444); background: rgba(239, 68, 68, 0.08); padding: 12px 16px; margin-bottom: 16px; border-radius: var(--radius-sm); color: #f87171; font-weight: 600; font-size: 13px;">
            <?= sprintf(__('%d path(s) are not writable. The CMS cannot save content, uploads or settings until this is fixed.'), $setupPermissionErrors) ?>
        </div>
    <?php else: ?>
        <div style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 16px; border-radius: var(--radius-sm); color: #34d399; font-weight: 600; font-size: 13px;">
            <?= _e('Every required path is writable.') ?>
        </div>
    <?php endif; ?>

    <div class="table-container">
        <table class="pro-table">
            <thead>
                <tr>
                    <th style="width: 26%;"><?= _e('Path') ?></th>
                    <th style="width: 34%;"><?= _e('Purpose') ?></th>
                    <th style="width: 14%;"><?= _e('Status') ?></th>
                    <th style="width: 26%;"><?= _e('Fix command') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($setupPermissions as $row): ?>
                    <?php
                        $writable = !empty($row['writable']);
                        $exists = !empty($row['exists']);
                        $severity = (string)($row['severity'] ?? 'warning');

                        if ($writable) {
                            $stateLabel = $exists ? __('Writable') : __('Created on demand');
                            $stateSeverity = 'success';
                        } else {
                            $stateLabel = $exists ? __('Not writable') : __('Missing');
                            $stateSeverity = $severity === 'error' ? 'danger' : 'warning';
                        }
                    ?>
                    <tr>
                        <td>
                            <strong><?= \Core\Security::sanitize((string)$row['label']) ?></strong>
                            <div style="margin-top: 2px;"><?= $codeCell((string)$row['path']) ?></div>
                        </td>
                        <td style="font-size: 12px; color: var(--text-muted); line-height: 1.5;">
                            <?= \Core\Security::sanitize((string)($row['purpose'] ?? '')) ?>
                        </td>
                        <td><?= $statusBadge($stateLabel, $stateSeverity) ?></td>
                        <td style="font-size: 11px;">
                            <?= $writable ? '' : $codeCell((string)($row['hint'] ?? '')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <details style="margin-top: 16px;">
        <summary style="cursor: pointer; font-size: 12px; font-weight: 600; color: var(--primary, #3b82f6);">
            <?= _e('Show the recommended permission scheme') ?>
        </summary>
        <div style="margin-top: 10px; font-size: 12px; color: var(--text-muted); line-height: 1.7;">
            <p style="margin: 0 0 6px;"><?= _e('Directories: 755 for the code and 775 for every directory the CMS writes to. Files: 644, except the configuration file which may stay 640.') ?></p>
            <pre style="margin: 0; padding: 12px; overflow: auto; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); font-size: 11px; line-height: 1.6;"><?= htmlspecialchars(
                'chmod 755 . && chmod 644 index.php' . "\n"
                . 'chmod -R 775 data uploads && chmod 640 data/cms.sqlite modoconfig.php' . "\n"
                . 'chown -R ' . $setupOwner . ':' . $setupOwner . ' data uploads',
                ENT_QUOTES,
                'UTF-8'
            ) ?></pre>
        </div>
    </details>
</div>


<!-- ===================== Security rules (.htaccess) ===================== -->
<div class="card settings-panel" data-tab="system">
    <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
        <?= _e('Security Rules (.htaccess)') ?>
    </h3>
    <p style="font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-bottom: 16px;">
        <?= sprintf(
            __('ModoCMS keeps hardened .htaccess files in the project root, data/, uploads/ and plugins/. They hide the SQLite database, the configuration file, backups and the thumbnail cache, and they enable the pretty permalinks. This report compares every file with the shipped rule set (version %s).'),
            htmlspecialchars(\Core\Setup::VERSION, ENT_QUOTES, 'UTF-8')
        ) ?>
    </p>

    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px;">
        <?= $statusBadge(sprintf(__('%d up to date'), (int)$setupSummary['ok']), 'success') ?>
        <?= $statusBadge(sprintf(__('%d to check'), (int)$setupSummary['problem']), ((int)$setupSummary['problem'] > 0 ? 'warning' : 'muted')) ?>
        <?= $statusBadge(sprintf(__('%d disabled'), (int)$setupSummary['disabled']), 'info') ?>
        <?= $statusBadge(sprintf(__('%d file(s) in total'), (int)$setupSummary['total']), 'muted') ?>
    </div>

    <div class="table-container">
        <table class="pro-table">
            <thead>
                <tr>
                    <th style="width: 22%;"><?= _e('File') ?></th>
                    <th style="width: 40%;"><?= _e('Purpose') ?></th>
                    <th style="width: 16%;"><?= _e('Status') ?></th>
                    <th style="width: 10%;"><?= _e('Rules') ?></th>
                    <th style="width: 12%;"><?= _e('Permissions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($setupAudit as $row): ?>
                    <?php
                        $state = (string)$row['state'];
                        $meta = \Core\Setup::stateMeta($state);
                    ?>
                    <tr>
                        <td>
                            <strong><?= \Core\Security::sanitize((string)$row['label']) ?></strong>
                            <div style="margin-top: 2px;"><?= $codeCell((string)$row['path']) ?></div>
                        </td>
                        <td style="font-size: 12px; color: var(--text-muted); line-height: 1.5;">
                            <?= \Core\Security::sanitize((string)($row['purpose'] ?? '')) ?>
                        </td>
                        <td><?= $statusBadge(__((string)$meta['label']), (string)$meta['severity']) ?></td>
                        <td style="font-size: 11px; color: var(--text-muted);">
                            <?= $row['version'] !== null ? 'v' . \Core\Security::sanitize((string)$row['version']) : '&mdash;' ?>
                            <?php if (!empty($row['managed']) && empty($row['matches_template'])): ?>
                                <div><?= _e('modified') ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 11px;">
                            <?= !empty($row['writable']) ? _e('writable') : '<span style="color: #f87171;">' . _e('read only') . '</span>' ?>
                            <?php if (empty($row['directory_exists'])): ?>
                                <div style="color: var(--text-muted);"><?= _e('directory missing') ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <form method="POST" action="settings.php" style="margin-top: 18px;">
        <input type="hidden" name="csrf_token" value="<?= \Core\Security::generateCsrfToken() ?>">
        <input type="hidden" name="action" value="setup_htaccess_deploy">

        <div style="padding: 12px 14px; background: var(--bg-surface, #1e293b); border-radius: var(--radius-sm); border: 1px solid var(--border-subtle); margin-bottom: 14px;">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--text-main);">
                <input type="checkbox" name="force_custom" value="1">
                <?= _e('Replace custom files') ?>
            </label>
            <p style="font-size: 11px; color: var(--text-muted); margin-top: 6px; margin-left: 24px; line-height: 1.4;">
                <?= _e('Files that were not created by ModoCMS (no version marker) are normally left untouched. Enable this to overwrite them as well - a copy is always saved into data/backups/ first.') ?>
            </p>
        </div>

        <button type="submit" class="btn btn-primary" style="font-size: 12px;">
            <?= _e('Check and repair rules') ?>
        </button>
    </form>


    <?php if ($setupDeploy !== null): ?>
        <?php
            $deploySeverity = [
                'up-to-date' => 'muted',
                'written'    => 'success',
                'updated'    => 'success',
                'removed'    => 'warning',
                'skipped'    => 'info',
                'error'      => 'danger',
            ];
            $deployLabels = [
                'up-to-date' => __('Up to date'),
                'written'    => __('Created'),
                'updated'    => __('Updated'),
                'removed'    => __('Removed'),
                'skipped'    => __('Skipped'),
                'error'      => __('Failed'),
            ];
        ?>
        <div style="margin-top: 18px; border-top: 1px solid var(--border-subtle); padding-top: 14px;">
            <h4 style="font-size: 13px; font-weight: 700; margin-bottom: 10px; color: var(--text-main);">
                <?= $setupDeploy['force'] ? _e('Repair report (custom files were replaced)') : _e('Repair report') ?>
            </h4>
            <div class="table-container">
                <table class="pro-table">
                    <thead>
                        <tr>
                            <th style="width: 24%;"><?= _e('File') ?></th>
                            <th style="width: 14%;"><?= _e('Status') ?></th>
                            <th style="width: 62%;"><?= _e('Result') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($setupDeploy['results'] as $entry): ?>
                            <?php $status = (string)($entry['status'] ?? 'error'); ?>
                            <tr>
                                <td><strong><?= \Core\Security::sanitize((string)$entry['label']) ?></strong></td>
                                <td><?= $statusBadge($deployLabels[$status] ?? $status, $deploySeverity[$status] ?? 'muted') ?></td>
                                <td style="font-size: 12px; color: var(--text-muted); line-height: 1.5;">
                                    <?= \Core\Security::sanitize((string)($entry['message'] ?? '')) ?>
                                    <?php if (!empty($entry['backup'])): ?>
                                        <div style="margin-top: 4px;"><?= sprintf(__('Backup: %s'), $codeCell((string)$entry['backup'])) ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <details style="margin-top: 18px;">
        <summary style="cursor: pointer; font-size: 12px; font-weight: 600; color: var(--primary, #3b82f6);">
            <?= _e('nginx configuration (server block)') ?>
        </summary>
        <div style="margin-top: 10px; font-size: 12px; color: var(--text-muted); line-height: 1.7;">
            <p style="margin: 0 0 8px;">
                <?= _e('nginx ignores .htaccess files completely. Paste the block below into the server { ... } section of your site configuration and reload nginx, otherwise the database and the cache stay downloadable.') ?>
            </p>
            <pre style="margin: 0; padding: 12px; overflow: auto; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); font-size: 11px; line-height: 1.6;"><?= htmlspecialchars(\Core\Setup::webServerRules(), ENT_QUOTES, 'UTF-8') ?></pre>
        </div>
    </details>
</div>

