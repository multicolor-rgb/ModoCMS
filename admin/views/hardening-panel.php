<?php
/**
 * "Production hardening" card of the Settings -> System tab.
 *
 * Renders every option declared in Core\Config::definitions() (debug flags,
 * timezone, URL overrides, maintenance mode, HTTPS redirect, upload limits,
 * session cookie flag, plugin hardening ...) as a form and stores the answers in
 * modoconfig.php through Core\Config::save(). The file is portable: copy it to
 * another installation and the same hardening applies.
 *
 * Expected variables:
 *   $advancedGroups  array<string,array<string,array<string,mixed>>> definitions grouped by "group"
 *   $advancedValues  array<string,mixed> effective value of every option
 *   $advancedFile    array{path:string,exists:bool,writable:bool,count:int,sample:bool,source:string}
 *   $configReport    ?array{ok:bool,message:string} result of the last action
 *   $severityColors  array<string,string> badge palette   (from system-panel.php)
 *   $codeCell        callable(string):string code fragment (from system-panel.php)
 */
declare(strict_types=1);

// system-panel.php defines the palette and the code cell helper; keep local
// fallbacks so this partial also renders when it is included on its own.
if (!isset($severityColors)) {
    $severityColors = [
        'success' => '#10b981',
        'warning' => '#f59e0b',
        'danger'  => '#ef4444',
        'info'    => '#6366f1',
        'muted'   => '#94a3b8',
    ];
}
if (!isset($codeCell)) {
    $codeCell = static function (string $value): string {
        return '<code style="font-size:11px;word-break:break-all;color:var(--text-muted);">'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</code>';
    };
}

$hardeningInputStyle = 'width:100%;padding:8px 10px;font-size:12px;background:var(--bg-surface, #1e293b);'
    . 'border:1px solid var(--border-subtle);border-radius:var(--radius-sm);color:var(--text-main);';
$hardeningBadge = static function (string $label, string $severity) use ($severityColors): string {
    $color = $severityColors[$severity] ?? '#94a3b8';

    return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;'
        . 'text-transform:uppercase;letter-spacing:.03em;color:' . $color . ';background:' . $color . '1f;'
        . 'border:1px solid ' . $color . '55;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
};
?>

<!-- ===================== Production hardening (modoconfig.php) ===================== -->
<div class="card settings-panel" data-tab="system">
    <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
        <?= _e('Production hardening') ?>
    </h3>
    <p style="font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-bottom: 16px;">
        <?= _e('Every option below is written to modoconfig.php in the project root. The file is read before the database is opened, so its values win over the Settings screen - delete a line to fall back to the default. Because it is a plain PHP file you can copy it to staging or to the live server to apply exactly the same hardening.') ?>
    </p>

    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px;">
        <?= $hardeningBadge($advancedFile['exists'] ? __('modoconfig.php present') : __('modoconfig.php missing'), $advancedFile['exists'] ? 'success' : 'muted') ?>
        <?= $hardeningBadge($advancedFile['writable'] ? __('Writable') : __('Not writable'), $advancedFile['writable'] ? 'success' : 'warning') ?>
        <?= $hardeningBadge(sprintf(__('%d option(s) overridden'), (int)$advancedFile['count']), 'info') ?>
    </div>

    <?php if ($configReport !== null): ?>
        <div style="border-left: 4px solid <?= $configReport['ok'] ? 'var(--success, #10b981)' : 'var(--danger, #ef4444)' ?>; background: <?= $configReport['ok'] ? 'rgba(16, 185, 129, 0.08)' : 'rgba(239, 68, 68, 0.08)' ?>; padding: 12px 16px; margin-bottom: 16px; border-radius: var(--radius-sm); color: <?= $configReport['ok'] ? '#34d399' : '#f87171' ?>; font-weight: 600; font-size: 13px;">
            <?= \Core\Security::sanitize((string)$configReport['message']) ?>
        </div>
    <?php endif; ?>

    <?php if (!$advancedFile['writable']): ?>
        <div style="border-left: 4px solid var(--warning, #f59e0b); background: rgba(245, 158, 11, 0.08); padding: 12px 16px; margin-bottom: 16px; border-radius: var(--radius-sm); color: #fbbf24; font-size: 12px; line-height: 1.6;">
            <strong><?= _e('The configuration file cannot be written.') ?></strong>
            <?= _e('Create it manually with the snippet at the bottom of this card, or make the project root writable:') ?>
            <div style="margin-top: 6px;"><?= $codeCell('chmod 755 ' . dirname($advancedFile['path']) . ' && touch ' . $advancedFile['path'] . ' && chmod 640 ' . $advancedFile['path']) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!$advancedFile['exists']): ?>
        <div style="border-left: 4px solid var(--primary, #3b82f6); background: rgba(59, 130, 246, 0.08); padding: 12px 16px; border-radius: var(--radius-sm); color: #93c5fd; font-size: 12px; line-height: 1.6;">
            <?= _e('No modoconfig.php yet - every option follows the built-in default (or the matching Settings value). Save this form to create the file.') ?>
        </div>
    <?php endif; ?>
</div>

<!-- ===================== Hardening options ===================== -->
<form method="POST" action="settings.php" class="card settings-panel" data-tab="system">
    <input type="hidden" name="csrf_token" value="<?= \Core\Security::generateCsrfToken() ?>">
    <input type="hidden" name="action" value="config_save">

    <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
        <?= _e('Hardening options') ?>
    </h3>

    <?php foreach ($advancedGroups as $group => $options): ?>
        <fieldset style="border: none; padding: 0; margin: 0 0 22px;">
            <legend style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin-bottom: 10px;">
                <?= \Core\Security::sanitize(__((string)\Core\Config::groupLabel((string)$group))) ?>
            </legend>

            <?php foreach ($options as $key => $definition): ?>
                <?php
                    $type = (string)($definition['type'] ?? 'text');
                    $label = (string)($definition['label'] ?? $key);
                    $help = (string)($definition['help'] ?? '');
                    $placeholder = (string)($definition['placeholder'] ?? '');
                    $value = $advancedValues[$key] ?? $definition['default'] ?? '';
                    $fromFile = array_key_exists($key, $advancedFileValues);
                    $fromSetting = !$fromFile && !empty($advancedSettings[$key]);
                ?>
                <div style="padding: 12px 14px; margin-bottom: 10px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm);">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                        <label for="config-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" style="font-size: 13px; font-weight: 600; color: var(--text-main);">
                            <?= \Core\Security::sanitize(__($label)) ?>
                        </label>
                        <?php if ($fromFile): ?>
                            <?= $hardeningBadge(__('modoconfig.php'), 'info') ?>
                        <?php elseif ($fromSetting): ?>
                            <?= $hardeningBadge(__('Settings screen'), 'muted') ?>
                        <?php else: ?>
                            <?= $hardeningBadge(__('Built-in default'), 'muted') ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($help !== ''): ?>
                        <p style="font-size: 11px; color: var(--text-muted); line-height: 1.5; margin: 6px 0 0;">
                            <?= \Core\Security::sanitize(__($help)) ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($type === 'bool'): ?>
                        <label style="display: flex; align-items: center; gap: 10px; margin-top: 10px; cursor: pointer; font-size: 12px; color: var(--text-main);">
                            <input type="checkbox" id="config-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" name="config[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]" value="1" <?= !empty($value) ? 'checked' : '' ?>>
                            <?= _e('Enabled') ?>
                        </label>
                    <?php elseif ($type === 'select'): ?>
                        <select id="config-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" name="config[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]" style="<?= $hardeningInputStyle ?> margin-top: 10px;">
                            <?php foreach ((array)($definition['options'] ?? []) as $optionValue => $optionLabel): ?>
                                <option value="<?= htmlspecialchars((string)$optionValue, ENT_QUOTES, 'UTF-8') ?>" <?= (string)$value === (string)$optionValue ? 'selected' : '' ?>>
                                    <?= \Core\Security::sanitize(__((string)$optionLabel)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'textarea'): ?>
                        <textarea id="config-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" name="config[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]" rows="5"
                            placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?>"
                            style="<?= $hardeningInputStyle ?> margin-top: 10px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; line-height: 1.5;"
                        ><?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <?php elseif ($type === 'int'): ?>
                        <input type="number" id="config-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" name="config[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]"
                            value="<?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?>"
                            <?= isset($definition['min']) ? 'min="' . (int)$definition['min'] . '"' : '' ?>
                            <?= isset($definition['max']) ? 'max="' . (int)$definition['max'] . '"' : '' ?>
                            step="1" style="<?= $hardeningInputStyle ?> margin-top: 10px; max-width: 220px;">
                    <?php else: ?>
                        <input type="text" id="config-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" name="config[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]"
                            value="<?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?>"
                            style="<?= $hardeningInputStyle ?> margin-top: 10px;">
                    <?php endif; ?>

                    <div style="margin-top: 6px; font-size: 10px; color: var(--text-muted);"><?= $codeCell((string)$key) ?></div>
                </div>
            <?php endforeach; ?>
        </fieldset>
    <?php endforeach; ?>

    <p style="font-size: 11px; color: var(--text-muted); line-height: 1.5; margin-bottom: 14px;">
        <?= _e('Empty fields remove the line from modoconfig.php, so the option falls back to the Settings screen or to the built-in default again. Switches are always stored with their current position, so for them the file always overrides the Settings screen.') ?>
    </p>

    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <button type="submit" class="btn btn-primary" style="font-size: 12px;"><?= _e('Save hardening options') ?></button>
    </div>
</form>

<!-- ===================== Portable configuration file ===================== -->
<div class="card settings-panel" data-tab="system">
    <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
        <?= _e('Portable configuration file') ?>
    </h3>
    <p style="font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-bottom: 12px;">
        <?= _e('The snippet below is the exact content of the file the CMS reads before the database is opened. Copy it to staging or to the live server to apply exactly the same hardening there. The file is always blocked from HTTP access by the shipped rules - keep it that way, it may contain environment specific values.') ?>
    </p>

    <div style="margin-bottom: 10px;">
        <?= $codeCell($advancedFile['path']) ?>
    </div>

    <pre style="margin: 0 0 14px; padding: 14px; max-height: 340px; overflow: auto; font-size: 11px; line-height: 1.6; background: var(--bg-main, #0f172a); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); color: var(--text-main); font-family: ui-monospace, SFMono-Regular, Menlo, monospace;"><?= htmlspecialchars((string)$advancedFile['source'], ENT_QUOTES, 'UTF-8') ?></pre>

    <p style="font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-bottom: 10px;">
        <?= _e('The package also ships a commented sample that lists every supported option:') ?>
        <?= $codeCell(\Core\Config::samplePath()) ?>
    </p>

    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <form method="POST" action="settings.php" style="margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= \Core\Security::generateCsrfToken() ?>">
            <input type="hidden" name="action" value="config_sample">
            <button type="submit" class="btn btn-secondary" style="font-size: 12px;"><?= _e('Write sample file') ?></button>
        </form>

        <?php if ($advancedFile['exists']): ?>
            <form method="POST" action="settings.php" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= \Core\Security::generateCsrfToken() ?>">
                <input type="hidden" name="action" value="config_delete">
                <button type="submit" class="btn btn-danger-ghost" style="font-size: 12px;"
                        data-confirm
                        data-confirm-title="<?= _e('Delete configuration file') ?>"
                        data-confirm-message="<?= _e('Delete the configuration file? Every option falls back to the Settings screen and to the built-in defaults.') ?>"
                        data-confirm-ok="<?= _e('Delete') ?>"
                        data-confirm-danger>
                    <?= _e('Delete configuration file') ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

