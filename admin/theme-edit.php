<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Security;
use Core\Router;

Auth::requireCapability('manage_settings');

$themesBaseDir = realpath(__DIR__ . '/../themes');
if (!$themesBaseDir || !is_dir($themesBaseDir)) {
    die('Themes directory not found.');
}

// Fetch all available themes
$availableThemes = array_filter(glob($themesBaseDir . '/*'), 'is_dir');
$themeNames = array_map('basename', $availableThemes);

// Selected theme
$activeTheme = Router::getOption('active_theme', 'default');
$selectedTheme = $_GET['theme'] ?? $activeTheme;
if (!in_array($selectedTheme, $themeNames, true)) {
    $selectedTheme = reset($themeNames) ?: 'default';
}

$themeDir = realpath($themesBaseDir . '/' . $selectedTheme);
$allowedExtensions = ['php', 'css', 'js', 'json', 'html', 'txt', 'svg'];

// Recursive scan helper
function scanThemeFiles(string $dir, string $baseDir, array $allowedExtensions): array {
    $result = [];
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $children = scanThemeFiles($path, $baseDir, $allowedExtensions);
            if (!empty($children)) {
                $result[$item] = $children;
            }
        } elseif (is_file($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExtensions, true)) {
                $relPath = ltrim(str_replace($baseDir, '', $path), '/\\');
                $result[$item] = $relPath;
            }
        }
    }
    return $result;
}

$themeFilesTree = $themeDir ? scanThemeFiles($themeDir, $themeDir, $allowedExtensions) : [];

// Resolve active file
$requestedFile = $_GET['file'] ?? '';
$targetFilePath = '';

if ($requestedFile !== '' && $themeDir) {
    $candidate = realpath($themeDir . '/' . $requestedFile);
    if ($candidate && str_starts_with($candidate, $themeDir) && is_file($candidate)) {
        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExtensions, true)) {
            $targetFilePath = $candidate;
        }
    }
}

// Fallback to primary files if no target set
if (!$targetFilePath && $themeDir) {
    foreach (['index.php', 'template.php', 'style.css'] as $c) {
        $check = realpath($themeDir . '/' . $c);
        if ($check && is_file($check)) {
            $targetFilePath = $check;
            $requestedFile = $c;
            break;
        }
    }
}

$saved = false;
$error = '';

// Handle save request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }

    $fileToSave = $_POST['file_path'] ?? '';
    $contentToSave = $_POST['file_content'] ?? '';

    if ($themeDir && $fileToSave !== '') {
        $savePath = realpath($themeDir . '/' . $fileToSave);
        if ($savePath && str_starts_with($savePath, $themeDir) && is_file($savePath)) {
            $ext = strtolower(pathinfo($savePath, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExtensions, true)) {
                if (is_writable($savePath)) {
                    file_put_contents($savePath, $contentToSave);
                    $saved = true;
                    $targetFilePath = $savePath;
                    $requestedFile = $fileToSave;
                } else {
                    $error = _e('File is not writable. Check file permissions.');
                }
            } else {
                $error = _e('Invalid file extension.');
            }
        } else {
            $error = _e('Invalid file path.');
        }
    }
}

$currentContent = ($targetFilePath && is_file($targetFilePath)) ? file_get_contents($targetFilePath) : '';
$isWritable = $targetFilePath ? is_writable($targetFilePath) : false;
$currentExt = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));

// Map file extension to CodeMirror mode
$editorMode = match ($currentExt) {
    'css' => 'text/css',
    'js', 'json' => 'application/javascript',
    'html', 'svg' => 'text/html',
    default => 'application/x-httpd-php', // php default
};

require_once __DIR__ . '/views/header.php';
?>

<!-- CodeMirror CSS -->
<link rel="stylesheet" href="assets/vendor/codemirror/codemirror.min.css">
<link rel="stylesheet" href="assets/vendor/codemirror/theme/dracula.min.css">

<style>
.CodeMirror {
    height: 600px;
    font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
    font-size: 13px;
    line-height: 1.5;
}
</style>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h1 class="page-title"><?= _e('Theme Code Editor') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Edit template files, stylesheets, and scripts with syntax highlighting') ?></p>
    </div>

    <!-- Theme selector -->
    <form method="GET" action="theme-edit.php" style="display: flex; align-items: center; gap: 8px;">
        <label for="theme-select" style="font-size: 12px; font-weight: 600; color: var(--text-muted);"><?= _e('Theme:') ?></label>
        <select id="theme-select" name="theme" class="form-control" onchange="this.form.submit()" style="padding: 6px 12px; font-size: 13px;">
            <?php foreach ($themeNames as $tName): ?>
                <option value="<?= htmlspecialchars($tName, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedTheme === $tName ? 'selected' : '' ?>>
                    <?= htmlspecialchars($tName, ENT_QUOTES, 'UTF-8') ?><?= $tName === $activeTheme ? ' (' . _e('Active') . ')' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($saved): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); padding: 12px; margin-bottom: 20px;">
        <?= _e('File saved successfully.') ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="card" style="border-left: 4px solid var(--danger, #ef4444); padding: 12px; margin-bottom: 20px; color: var(--danger, #ef4444);">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 24px; align-items: start;">
    
    <!-- Code Editor Section -->
    <div>
        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="background: #1e293b; color: #94a3b8; padding: 10px 16px; font-family: monospace; font-size: 12px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #334155;">
                <div>
                    <span style="color: #38bdf8; font-weight: 600;"><?= htmlspecialchars($selectedTheme, ENT_QUOTES, 'UTF-8') ?>/</span><?= htmlspecialchars($requestedFile, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div>
                    <?php if (!$isWritable): ?>
                        <span style="color: #f87171; font-weight: 600;">[<?= _e('Read Only') ?>]</span>
                    <?php else: ?>
                        <span style="color: #4ade80;">[<?= _e('Writable') ?>]</span>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" action="theme-edit.php?theme=<?= urlencode($selectedTheme) ?>&file=<?= urlencode($requestedFile) ?>" id="theme-editor-form">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                <input type="hidden" name="file_path" value="<?= htmlspecialchars($requestedFile, ENT_QUOTES, 'UTF-8') ?>">

                <textarea id="code-editor" name="file_content"><?= htmlspecialchars($currentContent, ENT_QUOTES, 'UTF-8') ?></textarea>

<div style="padding: 12px 16px; background: var(--bg-surface); border-top: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center;">                    <small style="color: var(--text-muted); font-size: 11px;">
                        <?= _e('Syntax:') ?> <span style="font-weight: 600; text-transform: uppercase;"><?= htmlspecialchars($currentExt ?: 'none', ENT_QUOTES, 'UTF-8') ?></span> &bull; <?= _e('Ctrl+S to save') ?>
                    </small>
                    <button type="submit" class="btn btn-primary" <?= !$isWritable ? 'disabled' : '' ?>>
                        <?= _e('Save Changes') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Theme Files Sidebar -->
    <div>
        <div class="card">
            <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 1px solid var(--border-subtle);">
                <?= _e('Theme Files') ?>
            </h3>

            <?php
            function renderFileTree(array $tree, string $selectedTheme, string $activeFile): void {
                echo '<ul style="list-style: none; padding-left: 12px; margin: 0; font-size: 12px;">';
                foreach ($tree as $name => $item) {
                    if (is_array($item)) {
                        echo '<li style="margin: 6px 0;">';
                        echo '<span style="font-weight: 600; color: var(--text-muted); display: flex; align-items: center; gap: 4px;">';
                        echo '<svg style="width:14px;height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>';
                        echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '/';
                        echo '</span>';
                        renderFileTree($item, $selectedTheme, $activeFile);
                        echo '</li>';
                    } else {
                        $isActive = ($activeFile === $item);
                        $linkStyle = $isActive 
                            ? 'background: var(--primary-light, #eff6ff); color: var(--primary, #2563eb); font-weight: 700;' 
                            : 'color: inherit;';
                        $url = 'theme-edit.php?theme=' . urlencode($selectedTheme) . '&file=' . urlencode($item);

                        echo '<li style="margin: 3px 0;">';
                        echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display: block; padding: 4px 8px; border-radius: var(--radius-sm, 4px); text-decoration: none; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; ' . $linkStyle . '">';
                        echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                        echo '</a>';
                        echo '</li>';
                    }
                }
                echo '</ul>';
            }

            if (!empty($themeFilesTree)) {
                renderFileTree($themeFilesTree, $selectedTheme, $requestedFile);
            } else {
                echo '<p style="font-size: 12px; color: var(--text-muted);">' . _e('No editable template files found.') . '</p>';
            }
            ?>
        </div>
    </div>
</div>

<!-- CodeMirror Core Scripts -->
<script src="assets/vendor/codemirror/codemirror.min.js"></script>
<script src="assets/vendor/codemirror/mode/xml/xml.min.js"></script>
<script src="assets/vendor/codemirror/mode/javascript/javascript.min.js"></script>
<script src="assets/vendor/codemirror/mode/css/css.min.js"></script>
<script src="assets/vendor/codemirror/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="assets/vendor/codemirror/mode/clike/clike.min.js"></script>
<script src="assets/vendor/codemirror/mode/php/php.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const textarea = document.getElementById('code-editor');
    const form = document.getElementById('theme-editor-form');

    if (!textarea) return;

    // Resolve mode configuration
    const currentExt = <?= json_encode($currentExt) ?>;
    let modeConfig = 'text/html';

    if (currentExt === 'php') {
        modeConfig = {
            name: "application/x-httpd-php",
            startOpen: true
        };
    } else if (currentExt === 'css') {
        modeConfig = 'text/css';
    } else if (currentExt === 'js' || currentExt === 'json') {
        modeConfig = 'text/javascript';
    }

    // Initialize CodeMirror instance
    const editor = CodeMirror.fromTextArea(textarea, {
        lineNumbers: true,
        matchBrackets: true,
        mode: modeConfig,
        theme: 'dracula',
        indentUnit: 4,
        tabSize: 4,
        indentWithTabs: false,
        lineWrapping: true,
        readOnly: <?= $isWritable ? 'false' : "'nocursor'" ?>
    });

    // Keyboard shortcut Ctrl+S / Cmd+S to submit
    editor.setOption('extraKeys', {
        'Ctrl-S': function(cm) {
            form.submit();
        },
        'Cmd-S': function(cm) {
            form.submit();
        }
    });

    // Sync content back to textarea before form submission
    form.addEventListener('submit', () => {
        editor.save();
    });
});
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>