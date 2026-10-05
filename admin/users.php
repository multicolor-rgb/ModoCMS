<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';
use Core\Auth;
use Core\Database;
use Core\Security;

Auth::requireCapability('manage_users');

$db = Database::getConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) die('Invalid CSRF');

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'editor';

    if (!in_array($role, ['admin', 'editor', 'author'], true)) $role = 'editor';

    if ($username && $email && strlen($password) >= 6) {
        try {
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (:u, :e, :p, :r)");
            $stmt->execute([
                ':u' => $username,
                ':e' => $email,
                ':p' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                ':r' => $role
            ]);
            $message = __('User added successfully.');
        } catch (\PDOException $e) {
            $message = 'Username or email already exists.';
        }
    } else {
        $message = __('Password must be at least 6 characters.');
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (Security::verifyCsrfToken($_GET['csrf'] ?? '')) {
        $delId = (int)$_GET['id'];
        if ($delId !== Auth::id()) {
            $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute([':id' => $delId]);
            header('Location: users.php');
            exit;
        } else {
            $message = 'You cannot delete your own account.';
        }
    }
}

$users = $db->query("SELECT id, username, email, role, created_at FROM users ORDER BY id ASC")->fetchAll();
require_once __DIR__ . '/views/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('Users & Permissions') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage your editorial team and access rights') ?></p>
    </div>
</div>

<?php if ($message): ?>
    <div class="card" style="border-left: 4px solid var(--primary); padding: 12px; margin-bottom: 20px;">
        <?= Security::sanitize($message) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    <div class="table-container">
        <table class="pro-table">
            <thead>
                <tr><th><?= _e('Username') ?></th><th><?= _e('Email Address') ?></th><th><?= _e('Role') ?></th><th><?= _e('Updated') ?></th><th style="text-align: right;"><?= _e('Actions') ?></th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><strong><?= Security::sanitize($u['username']) ?></strong></td>
                        <td style="color: var(--text-muted);"><?= Security::sanitize($u['email']) ?></td>
                        <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-warning' : 'badge-success' ?>"><?= strtoupper(Security::sanitize($u['role'])) ?></span></td>
                        <td style="color: var(--text-muted); font-size: 12px;"><?= $u['created_at'] ?></td>
                        <td style="text-align: right;">
                            <?php if ($u['id'] !== Auth::id()): ?>
                                <a href="users.php?action=delete&id=<?= $u['id'] ?>&csrf=<?= Security::generateCsrfToken() ?>" 
                                   class="btn btn-danger-ghost" style="padding: 4px 10px; font-size: 12px;" onclick="return confirm('Delete user?');"><?= _e('Delete') ?></a>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 12px;">(You)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 style="font-size: 15px; font-weight: 600; margin-bottom: 16px;"><?= _e('Create New User') ?></h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_user">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
            <div class="form-group">
                <label class="form-label" for="username"><?= _e('Username') ?></label>
                <input class="form-control" type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="email"><?= _e('Email Address') ?></label>
                <input class="form-control" type="email" id="email" name="email" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="password"><?= _e('Password') ?></label>
                <input class="form-control" type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="role"><?= _e('Role in System') ?></label>
                <select class="form-control" name="role" id="role">
                    <option value="editor"><?= _e('Editor (Manage all content)') ?></option>
                    <option value="author"><?= _e('Author (Create & edit own content)') ?></option>
                    <option value="admin"><?= _e('Administrator (Full system control)') ?></option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;"><?= _e('Create Account') ?></button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/views/footer.php'; ?>
