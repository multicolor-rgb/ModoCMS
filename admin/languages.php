<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';
use Core\Auth;
use Core\Database;
use Core\Security;
use Core\I18n;
use Core\Router;

Auth::requireCapability('manage_settings');

$db = Database::getConnection();
$message = '';
$activeLang = $_GET['lang'] ?? I18n::getAdminLocale();
$dictFile = __DIR__ . '/../languages/' . basename($activeLang) . '.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_translations') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) die('Invalid CSRF');

    $keys = $_POST['keys'] ?? [];
    $values = $_POST['values'] ?? [];
    $dictionary = [];

    for ($i = 0; $i < count($keys); $i++) {
        $k = trim($keys[$i]);
        if ($k !== '') {
            $dictionary[$k] = trim($values[$i] ?? '');
        }
    }

    if (!empty($_POST['new_key'])) {
        $dictionary[trim($_POST['new_key'])] = trim($_POST['new_val'] ?? '');
    }

    file_put_contents($dictFile, json_encode($dictionary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $message = __('Settings saved successfully.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_languages_config') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) die('Invalid CSRF');

    $defaultLanguage = trim($_POST['default_language'] ?? 'en');
    $availableLanguages = trim($_POST['available_languages'] ?? 'en:English,pl:Polski');

    $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = :v");
    $stmt->execute([':k' => 'default_language', ':v' => $defaultLanguage]);
    $stmt->execute([':k' => 'available_languages', ':v' => $availableLanguages]);
    $message = __('Settings saved successfully.');
}

$currentTranslations = file_exists($dictFile) ? json_decode((string)file_get_contents($dictFile), true) : [];
$currentTranslations = is_array($currentTranslations) ? $currentTranslations : [];

require_once __DIR__ . '/views/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('Languages & Translations') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage language packs and user interface dictionaries') ?></p>
    </div>
</div>

<?php if ($message): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= Security::sanitize($message) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
    <div class="card">
        <h2 style="font-size: 15px; font-weight: 600; margin-bottom: 16px;"><?= _e('Languages') ?></h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_languages_config">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

            <div class="form-group">
                <label class="form-label" for="default_language"><?= _e('Default Language') ?></label>
                <input class="form-control" type="text" id="default_language" name="default_language" value="<?= Security::sanitize(Router::getOption('default_language', 'en')) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="available_languages"><?= _e('Available Languages (format: code:Label)') ?></label>
                <input class="form-control" type="text" id="available_languages" name="available_languages" value="<?= Security::sanitize(Router::getOption('available_languages', 'en:English,pl:Polski')) ?>" required>
                <small style="color: var(--text-muted);"><?= _e('Separate with commas, e.g.:') ?> <code>en:English,pl:Polski,de:Deutsch</code></small>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;"><?= _e('Save Language Settings') ?></button>
        </form>
    </div>

    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h2 style="font-size: 15px; font-weight: 600;"><?= _e('UI Translation Dictionary') ?></h2>
            <div style="display: flex; gap: 6px;">
                <?php foreach (I18n::getAvailableLanguages() as $code => $name): ?>
                    <a href="languages.php?lang=<?= urlencode($code) ?>" class="btn <?= $code === $activeLang ? 'btn-primary' : 'btn-secondary' ?>" style="padding: 4px 8px; font-size: 12px;">
                        <?= Security::sanitize($name) ?> (<?= strtoupper($code) ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="save_translations">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

            <table class="pro-table" style="margin-bottom: 16px;">
                <thead>
                    <tr><th><?= _e('Original Source Key') ?></th><th><?= _e('Translation') ?> (<?= strtoupper(Security::sanitize($activeLang)) ?>)</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($currentTranslations as $k => $v): ?>
                        <tr>
                            <td>
                                <input type="text" name="keys[]" value="<?= Security::sanitize($k) ?>" class="form-control" style="font-family: monospace; font-size: 12px;">
                            </td>
                            <td>
                                <input type="text" name="values[]" value="<?= Security::sanitize((string)$v) ?>" class="form-control">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="background: #f8fafc;">
                        <td>
                            <input type="text" name="new_key" placeholder="<?= _e('Add new key...') ?>" class="form-control" style="font-family: monospace; font-size: 12px;">
                        </td>
                        <td>
                            <input type="text" name="new_val" placeholder="<?= _e('Translation') ?>" class="form-control">
                        </td>
                    </tr>
                </tbody>
            </table>

            <button type="submit" class="btn btn-primary"><?= _e('Save Dictionary') ?> (<?= strtoupper(Security::sanitize($activeLang)) ?>)</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/views/footer.php'; ?>
